<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Service;

use Derspe\AmazonLinkMonitor\Domain\Repository\LinkRepository;
use Derspe\AmazonLinkMonitor\Http\ApiException;
use Derspe\AmazonLinkMonitor\Settings\SiteSettings;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * The link check as a database job, processed in time-boxed steps:
 * sitemap → crawl pages and sync links → check unique links → finish run.
 *
 * Steps are driven by the polling backend UI and by the scheduler command, so no long
 * running PHP process is needed.
 */
final readonly class CrawlService
{
    public const TABLE = 'tx_derspeamazonlinkmonitor_job';
    private const CHECK_CHUNK = 10;

    public function __construct(
        private ConnectionPool $connectionPool,
        private LockFactory $lockFactory,
        private SitemapReader $sitemap,
        private LinkRepository $links,
        private LinkChecker $checker,
        private SiteSettings $settings,
        private LoggerInterface $logger,
    ) {}

    /**
     * Creates a crawl job or returns the active one.
     */
    public function start(string $site): int
    {
        $this->expireAbandoned();
        $active = $this->findActive($site);
        if ($active !== null) {
            return (int)$active['uid'];
        }
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'site' => $site,
            'type' => 'crawl',
            'status' => 'pending',
            'state' => json_encode(['phase' => 'sitemap']),
            'progress' => json_encode(['key' => 'job.pending']),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return (int)$connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActive(string $site): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                $qb->expr()->in('status', $qb->createNamedParameter(['pending', 'running'], Connection::PARAM_STR_ARRAY))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $site, int $uid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['uid' => $uid, 'site' => $site])
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllActive(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'running'], Connection::PARAM_STR_ARRAY)))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>|null null if another process works on the job
     */
    public function step(string $site, int $uid, float $budgetSeconds): ?array
    {
        $mode = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
        $locker = $this->lockFactory->createLocker('derspe_amazon_link_monitor_job_' . $uid, $mode);
        try {
            if (!$locker->acquire($mode)) {
                return null;
            }
        } catch (LockAcquireWouldBlockException) {
            return null;
        }
        try {
            $job = $this->find($site, $uid);
            if ($job === null || !in_array($job['status'], ['pending', 'running'], true)) {
                return $job;
            }
            $deadline = microtime(true) + $budgetSeconds;
            $state = json_decode((string)$job['state'], true) ?: ['phase' => 'sitemap'];
            $job['status'] = 'running';
            try {
                do {
                    [$job, $state] = match ($state['phase']) {
                        'sitemap' => $this->stepSitemap($job, $state),
                        'pages' => $this->stepPages($job, $state, $deadline),
                        'check' => $this->stepCheck($job, $state, $deadline),
                        default => [['status' => 'done'] + $job, $state],
                    };
                } while ($job['status'] === 'running' && microtime(true) < $deadline);
            } catch (\Throwable $e) {
                $this->logger->error('Amazon link check failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
                $job['status'] = 'error';
                $job['error'] = $e->getMessage();
                if (isset($state['run'])) {
                    $this->links->finishRun($site, (int)$state['run']);
                }
            }
            $job['state'] = $state;
            $this->save($job);
            return $job;
        } finally {
            $locker->release();
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $state
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function stepSitemap(array $job, array $state): array
    {
        $site = (string)$job['site'];
        $sitemapUrl = $this->sitemap->sitemapUrl($site);
        if ($sitemapUrl === '') {
            throw new ApiException('No sitemap URL configured', 503);
        }
        $urls = $this->sitemap->urls($sitemapUrl);
        $state['run'] = $this->links->startRun($site);
        $state['urls'] = $urls;
        $state['cursor'] = 0;
        $state['toCheck'] = [];
        $state['removed'] = $this->links->removeOrphanPages($site, $urls, (int)$state['run']);
        $state['phase'] = 'pages';
        $job['total'] = count($urls);
        $job['done'] = 0;
        $job['progress'] = ['key' => 'job.crawl.pages', 'args' => ['n' => 0, 'total' => count($urls), 'url' => '']];
        return [$job, $state];
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $state
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function stepPages(array $job, array $state, float $deadline): array
    {
        $site = (string)$job['site'];
        $domains = $this->settings->getList($site, 'domains');
        $urls = $state['urls'];
        $cursor = (int)$state['cursor'];
        $toCheck = array_flip($state['toCheck']);
        while ($cursor < count($urls) && microtime(true) < $deadline) {
            $url = $urls[$cursor];
            $job['progress'] = ['key' => 'job.crawl.pages', 'args' => ['n' => $cursor + 1, 'total' => count($urls), 'url' => $url]];
            try {
                $extracted = LinkExtractor::extract($this->sitemap->fetch($url), $url, $domains);
                $page = $this->links->upsertPage($site, $url, $extracted['title']);
                $this->links->syncPageLinks($site, $page, $url, $extracted['links'], (int)$state['run']);
                foreach ($extracted['links'] as $link) {
                    $toCheck[$link['url']] = true;
                }
            } catch (ApiException $e) {
                // A single unreachable page must not stop the run
                $this->logger->warning('Page could not be crawled: {url} ({message})', ['url' => $url, 'message' => $e->getMessage()]);
            }
            $cursor++;
        }
        $state['cursor'] = $cursor;
        $state['toCheck'] = array_keys($toCheck);
        $job['done'] = $cursor;
        if ($cursor >= count($urls)) {
            unset($state['urls']);
            $state['phase'] = 'check';
            $state['cursor'] = 0;
            $job['total'] = count($state['toCheck']);
            $job['done'] = 0;
            $job['progress'] = ['key' => 'job.crawl.checking', 'args' => ['n' => 0, 'total' => $job['total']]];
        }
        return [$job, $state];
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $state
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function stepCheck(array $job, array $state, float $deadline): array
    {
        $site = (string)$job['site'];
        $toCheck = $state['toCheck'];
        $cursor = (int)$state['cursor'];
        while ($cursor < count($toCheck) && microtime(true) < $deadline) {
            $chunk = array_slice($toCheck, $cursor, self::CHECK_CHUNK);
            foreach ($this->checker->check($site, $chunk) as $url => $result) {
                $this->links->storeCheckResult($site, $url, $result, (int)$state['run']);
            }
            $cursor += count($chunk);
            $job['progress'] = ['key' => 'job.crawl.checking', 'args' => ['n' => $cursor, 'total' => count($toCheck)]];
        }
        $state['cursor'] = $cursor;
        $job['done'] = $cursor;
        if ($cursor >= count($toCheck)) {
            $this->links->finishRun($site, (int)$state['run']);
            $stats = $this->links->stats($site);
            unset($state['toCheck']);
            $state['phase'] = 'done';
            $job['status'] = 'done';
            $job['progress'] = ['key' => 'job.crawl.done', 'args' => [
                'total' => $stats['total'],
                'broken' => $stats['broken'],
                'warning' => $stats['warning'],
                'unknown' => $stats['unknown'],
            ]];
        }
        return [$job, $state];
    }

    /**
     * @param array<string, mixed> $job
     */
    private function save(array $job): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, [
            'status' => $job['status'],
            'state' => json_encode($job['state'], JSON_UNESCAPED_SLASHES),
            'progress' => is_array($job['progress']) ? json_encode($job['progress'], JSON_UNESCAPED_SLASHES) : (string)$job['progress'],
            'total' => (int)$job['total'],
            'done' => (int)$job['done'],
            'error' => (string)($job['error'] ?? ''),
            'updated_at' => time(),
        ], ['uid' => (int)$job['uid']]);
    }

    private function expireAbandoned(): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->update(self::TABLE)
            ->set('status', 'error')
            ->set('error', 'abandoned')
            ->where(
                $qb->expr()->in('status', $qb->createNamedParameter(['pending', 'running'], Connection::PARAM_STR_ARRAY)),
                $qb->expr()->lt('updated_at', $qb->createNamedParameter(time() - 86400, Connection::PARAM_INT))
            )
            ->executeStatement();
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->delete(self::TABLE)
            ->where($qb->expr()->lt('updated_at', $qb->createNamedParameter(time() - 30 * 86400, Connection::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * @param array<string, mixed>|null $job
     * @return array<string, mixed>|null
     */
    public static function toDto(?array $job): ?array
    {
        if ($job === null) {
            return null;
        }
        $progress = is_array($job['progress'] ?? null) ? $job['progress'] : (json_decode((string)($job['progress'] ?? ''), true) ?: null);
        $state = is_array($job['state'] ?? null) ? $job['state'] : (json_decode((string)($job['state'] ?? ''), true) ?: []);
        return [
            'id' => (int)$job['uid'],
            'type' => 'crawl',
            'phase' => (string)($state['phase'] ?? ''),
            'status' => (string)$job['status'],
            'total' => (int)$job['total'],
            'done' => (int)$job['done'],
            'progress' => $progress,
            'error' => (string)($job['error'] ?? '') ?: null,
        ];
    }
}
