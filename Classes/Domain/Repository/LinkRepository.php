<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Domain\Repository;

use Derspe\AmazonLinkMonitor\Service\AmazonUrl;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Pages, links, crawl runs and status history.
 */
final readonly class LinkRepository
{
    public const PAGE_TABLE = 'tx_derspeamazonlinkmonitor_page';
    public const LINK_TABLE = 'tx_derspeamazonlinkmonitor_link';
    public const RUN_TABLE = 'tx_derspeamazonlinkmonitor_run';
    public const HISTORY_TABLE = 'tx_derspeamazonlinkmonitor_history';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function upsertPage(string $site, string $url, string $title): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::PAGE_TABLE);
        $hash = sha1($url);
        $uid = $connection->select(['uid'], self::PAGE_TABLE, ['site' => $site, 'url_hash' => $hash])->fetchOne();
        if ($uid !== false) {
            $connection->update(self::PAGE_TABLE, ['title' => $title, 'last_crawled_at' => time()], ['uid' => (int)$uid]);
            return (int)$uid;
        }
        $connection->insert(self::PAGE_TABLE, [
            'site' => $site,
            'url_hash' => $hash,
            'url' => $url,
            'title' => $title,
            'last_crawled_at' => time(),
        ]);
        return (int)$connection->lastInsertId();
    }

    /**
     * Removes pages (and their links) that are no longer in the sitemap.
     *
     * @param list<string> $sitemapUrls
     */
    public function removeOrphanPages(string $site, array $sitemapUrls, int $run): int
    {
        if ($sitemapUrls === []) {
            // A failed sitemap request must not wipe the inventory
            return 0;
        }
        $known = array_flip(array_map(static fn(string $u): string => rtrim($u, '/'), $sitemapUrls));
        $pages = $this->connectionPool->getConnectionForTable(self::PAGE_TABLE)
            ->select(['uid', 'url'], self::PAGE_TABLE, ['site' => $site])
            ->fetchAllAssociative();
        $removed = 0;
        foreach ($pages as $page) {
            if (isset($known[rtrim((string)$page['url'], '/')])) {
                continue;
            }
            foreach ($this->linksOfPage((int)$page['uid']) as $link) {
                $this->addHistory($site, $run, (string)$link['link_url'], (string)$page['url'], 'removed', (string)$link['status'], $link['status_code']);
            }
            $this->connectionPool->getConnectionForTable(self::LINK_TABLE)->delete(self::LINK_TABLE, ['page' => (int)$page['uid']]);
            $this->connectionPool->getConnectionForTable(self::PAGE_TABLE)->delete(self::PAGE_TABLE, ['uid' => (int)$page['uid']]);
            $removed++;
        }
        return $removed;
    }

    /**
     * @return array<string, array<string, mixed>> keyed by link URL
     */
    public function linksOfPage(int $page): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::LINK_TABLE)
            ->select(['*'], self::LINK_TABLE, ['page' => $page])
            ->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['link_url']] = $row;
        }
        return $result;
    }

    /**
     * Synchronizes the links of a page with the freshly extracted ones. New links get an
     * empty status until they are checked; vanished links are removed with a history entry.
     *
     * @param list<array{url: string, text: string, context: string, asin: string, affiliateId: string, occurrences: int}> $links
     */
    public function syncPageLinks(string $site, int $page, string $pageUrl, array $links, int $run): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::LINK_TABLE);
        $existing = $this->linksOfPage($page);
        $found = [];
        foreach ($links as $link) {
            $found[$link['url']] = true;
            $values = [
                'link_text' => mb_substr($link['text'], 0, 1000),
                'context' => mb_substr($link['context'], 0, 1000),
                'occurrence_count' => $link['occurrences'],
            ];
            if (isset($existing[$link['url']])) {
                $connection->update(self::LINK_TABLE, $values, ['uid' => (int)$existing[$link['url']]['uid']]);
                continue;
            }
            $connection->insert(self::LINK_TABLE, $values + [
                'site' => $site,
                'page' => $page,
                'link_hash' => sha1($link['url']),
                'link_url' => $link['url'],
                'asin' => $link['asin'],
                'affiliate_id' => mb_substr($link['affiliateId'], 0, 100),
                'status' => '',
            ]);
        }
        foreach ($existing as $url => $row) {
            if (!isset($found[$url])) {
                $this->addHistory($site, $run, $url, $pageUrl, 'removed', (string)$row['status'], $row['status_code']);
                $connection->delete(self::LINK_TABLE, ['uid' => (int)$row['uid']]);
            }
        }
    }

    /**
     * Stores a check result for every occurrence of the link and records status changes.
     *
     * @param array{status: string, statusCode: ?int, reason: string, method: string, productTitle: string, availability: string, asin: string, affiliateId: string} $result
     */
    public function storeCheckResult(string $site, string $linkUrl, array $result, int $run): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::LINK_TABLE);
        $rows = $qb->select('l.uid', 'l.status', 'l.asin', 'l.affiliate_id', 'p.url AS page_url')
            ->from(self::LINK_TABLE, 'l')
            ->join('l', self::PAGE_TABLE, 'p', $qb->expr()->eq('p.uid', $qb->quoteIdentifier('l.page')))
            ->where(
                $qb->expr()->eq('l.site', $qb->createNamedParameter($site)),
                $qb->expr()->eq('l.link_hash', $qb->createNamedParameter(sha1($linkUrl)))
            )
            ->executeQuery()
            ->fetchAllAssociative();
        $connection = $this->connectionPool->getConnectionForTable(self::LINK_TABLE);
        foreach ($rows as $row) {
            $event = AmazonUrl::statusEvent((string)$row['status'], $result['status']);
            if ($event !== null) {
                $this->addHistory($site, $run, $linkUrl, (string)$row['page_url'], $event, $result['status'], $result['statusCode']);
            }
            $connection->update(self::LINK_TABLE, [
                'status' => $result['status'],
                'status_code' => $result['statusCode'],
                'status_reason' => mb_substr($result['reason'], 0, 255),
                'check_method' => $result['method'],
                'product_title' => $result['productTitle'],
                'availability' => mb_substr($result['availability'], 0, 50),
                // Values from the resolved target (short links) take precedence
                'asin' => $result['asin'] !== '' ? $result['asin'] : (string)$row['asin'],
                'affiliate_id' => $result['affiliateId'] !== '' ? mb_substr($result['affiliateId'], 0, 100) : (string)$row['affiliate_id'],
                'last_checked_at' => time(),
            ], ['uid' => (int)$row['uid']]);
        }
    }

    public function addHistory(string $site, int $run, string $linkUrl, string $pageUrl, string $event, string $status, mixed $statusCode): void
    {
        $this->connectionPool->getConnectionForTable(self::HISTORY_TABLE)->insert(self::HISTORY_TABLE, [
            'site' => $site,
            'run' => $run,
            'link_url' => $linkUrl,
            'page_url' => $pageUrl,
            'event' => $event,
            'status' => $status,
            'status_code' => $statusCode !== null && $statusCode !== '' ? (int)$statusCode : null,
            'created_at' => time(),
        ]);
    }

    /**
     * @return array{total: int, ok: int, broken: int, warning: int, unknown: int, unchecked: int, lastCrawledAt: int|null, pages: int}
     */
    public function stats(string $site): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::LINK_TABLE);
        $rows = $qb->select('status')
            ->addSelectLiteral('COUNT(*) AS n')
            ->from(self::LINK_TABLE)
            ->where($qb->expr()->eq('site', $qb->createNamedParameter($site)))
            ->groupBy('status')
            ->executeQuery()
            ->fetchAllAssociative();
        $stats = ['total' => 0, 'ok' => 0, 'broken' => 0, 'warning' => 0, 'unknown' => 0, 'unchecked' => 0];
        foreach ($rows as $row) {
            $key = $row['status'] === '' ? 'unchecked' : (string)$row['status'];
            $stats[$key] = ($stats[$key] ?? 0) + (int)$row['n'];
            $stats['total'] += (int)$row['n'];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::PAGE_TABLE);
        $last = $qb->selectLiteral('MAX(last_crawled_at)')
            ->from(self::PAGE_TABLE)
            ->where($qb->expr()->eq('site', $qb->createNamedParameter($site)))
            ->executeQuery()
            ->fetchOne();
        $qb = $this->connectionPool->getQueryBuilderForTable(self::LINK_TABLE);
        $pages = $qb->selectLiteral('COUNT(DISTINCT page)')
            ->from(self::LINK_TABLE)
            ->where($qb->expr()->eq('site', $qb->createNamedParameter($site)))
            ->executeQuery()
            ->fetchOne();
        return $stats + ['lastCrawledAt' => ((int)$last) ?: null, 'pages' => (int)$pages];
    }

    /**
     * Pages with their links; pages with broken links first, broken links first within a page.
     *
     * @return list<array<string, mixed>>
     */
    public function linksByPage(string $site): array
    {
        $pages = $this->connectionPool->getConnectionForTable(self::PAGE_TABLE)
            ->select(['uid', 'url', 'title', 'last_crawled_at'], self::PAGE_TABLE, ['site' => $site])
            ->fetchAllAssociative();
        $links = $this->connectionPool->getConnectionForTable(self::LINK_TABLE)
            ->select(['*'], self::LINK_TABLE, ['site' => $site])
            ->fetchAllAssociative();
        $byPage = [];
        foreach ($links as $link) {
            $byPage[(int)$link['page']][] = [
                'id' => (int)$link['uid'],
                'url' => (string)$link['link_url'],
                'text' => (string)$link['link_text'],
                'context' => (string)$link['context'],
                'asin' => (string)$link['asin'],
                'affiliateId' => (string)$link['affiliate_id'],
                'occurrences' => (int)$link['occurrence_count'],
                'status' => (string)$link['status'],
                'statusCode' => $link['status_code'] !== null ? (int)$link['status_code'] : null,
                'reason' => (string)$link['status_reason'],
                'method' => (string)$link['check_method'],
                'productTitle' => (string)$link['product_title'],
                'availability' => (string)$link['availability'],
                'lastCheckedAt' => (int)$link['last_checked_at'] ?: null,
            ];
        }
        $rank = ['broken' => 0, 'warning' => 1, 'unknown' => 2, '' => 3, 'ok' => 4];
        $result = [];
        foreach ($pages as $page) {
            $pageLinks = $byPage[(int)$page['uid']] ?? [];
            if ($pageLinks === []) {
                continue;
            }
            usort($pageLinks, static fn(array $a, array $b): int => ($rank[$a['status']] ?? 3) <=> ($rank[$b['status']] ?? 3));
            $counts = array_count_values(array_column($pageLinks, 'status'));
            $result[] = [
                'id' => (int)$page['uid'],
                'url' => (string)$page['url'],
                'title' => (string)$page['title'],
                'lastCrawledAt' => (int)$page['last_crawled_at'] ?: null,
                'total' => count($pageLinks),
                'broken' => $counts['broken'] ?? 0,
                'warning' => $counts['warning'] ?? 0,
                'links' => $pageLinks,
            ];
        }
        usort($result, static fn(array $a, array $b): int => [$b['broken'], $b['warning'], $a['title']] <=> [$a['broken'], $a['warning'], $b['title']]);
        return $result;
    }

    public function startRun(string $site): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::RUN_TABLE);
        $connection->insert(self::RUN_TABLE, ['site' => $site, 'started_at' => time()]);
        return (int)$connection->lastInsertId();
    }

    public function finishRun(string $site, int $run): void
    {
        $stats = $this->stats($site);
        $this->connectionPool->getConnectionForTable(self::RUN_TABLE)->update(self::RUN_TABLE, [
            'finished_at' => time(),
            'total' => $stats['total'],
            'ok' => $stats['ok'],
            'broken' => $stats['broken'],
            'warning' => $stats['warning'],
            'unknown' => $stats['unknown'] + $stats['unchecked'],
        ], ['uid' => $run]);
    }

    /**
     * @return list<array<string, int>>
     */
    public function runs(string $site, int $from, int $to): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::RUN_TABLE);
        $rows = $qb->select('started_at', 'finished_at', 'total', 'ok', 'broken', 'warning', 'unknown')
            ->from(self::RUN_TABLE)
            ->where(
                $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                $qb->expr()->gt('finished_at', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->gte('finished_at', $qb->createNamedParameter($from, Connection::PARAM_INT)),
                $qb->expr()->lte('finished_at', $qb->createNamedParameter($to, Connection::PARAM_INT))
            )
            ->orderBy('started_at', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(static fn(array $r): array => array_map('intval', $r), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentEvents(string $site, int $limit = 50): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::HISTORY_TABLE);
        return array_map(static fn(array $r): array => [
            'linkUrl' => (string)$r['link_url'],
            'pageUrl' => (string)$r['page_url'],
            'event' => (string)$r['event'],
            'status' => (string)$r['status'],
            'statusCode' => $r['status_code'] !== null ? (int)$r['status_code'] : null,
            'createdAt' => (int)$r['created_at'],
        ], $qb->select('*')
            ->from(self::HISTORY_TABLE)
            ->where(
                $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                $qb->expr()->neq('event', $qb->createNamedParameter('added'))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative());
    }
}
