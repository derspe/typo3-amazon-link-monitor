<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Controller\Backend;

use Derspe\AmazonLinkMonitor\Domain\Repository\LinkRepository;
use Derspe\AmazonLinkMonitor\Http\ApiException;
use Derspe\AmazonLinkMonitor\Service\CrawlService;
use Derspe\AmazonLinkMonitor\Service\CreatorsApiClient;
use Derspe\AmazonLinkMonitor\Service\SitemapReader;
use Derspe\AmazonLinkMonitor\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Single AJAX entry point of the backend module and the dashboard widgets.
 */
final readonly class ApiController
{
    public const MODULE = 'amazon_link_monitor';
    private const JOB_STEP_SECONDS = 8.0;

    private const SETTING_KEYS = [
        'domains' => 'list',
        'sitemap_url' => 'string',
        'api_credential_id' => 'string',
        'api_credential_secret' => 'secret',
        'api_credential_version' => 'string',
        'api_partner_tag' => 'string',
        'api_marketplace' => 'string',
        'api_token_url' => 'string',
        'api_base_url' => 'string',
    ];

    public function __construct(
        private SiteFinder $siteFinder,
        private SiteSettings $settings,
        private LinkRepository $links,
        private CrawlService $crawl,
        private CreatorsApiClient $api,
        private SitemapReader $sitemap,
        private LoggerInterface $logger,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $body = $this->body($request);
        $endpoint = (string)($query['endpoint'] ?? $body['endpoint'] ?? '');
        $isPost = $request->getMethod() === 'POST';
        try {
            $user = $this->backendUser();
            if (!$user->check('modules', self::MODULE)) {
                throw new ApiException('Access denied', 403);
            }
            if ($endpoint === 'meta') {
                return new JsonResponse($this->meta());
            }
            $site = $this->resolveSite((string)($query['site'] ?? $body['site'] ?? ''))->getIdentifier();
            $data = match (true) {
                $endpoint === 'settings' && !$isPost => $this->readSettings($site),
                $endpoint === 'settings' && $isPost => $this->writeSettings($site, (array)($body['values'] ?? [])),
                $endpoint === 'stats' => $this->links->stats($site) + ['apiConfigured' => $this->api->isConfigured($site)],
                $endpoint === 'links' => $this->links->linksByPage($site),
                $endpoint === 'runs' => $this->links->runs($site, $this->since((int)($query['days'] ?? 30)), time()),
                $endpoint === 'events' => $this->links->recentEvents($site, max(1, min(200, (int)($query['limit'] ?? 50)))),
                $endpoint === 'job/start' && $isPost => ['job' => CrawlService::toDto($this->crawl->find($site, $this->crawl->start($site)))],
                $endpoint === 'job/active' => ['job' => CrawlService::toDto($this->crawl->findActive($site))],
                $endpoint === 'job/step' && $isPost => $this->stepJob($site, (int)($body['id'] ?? 0)),
                default => throw new ApiException('Unknown endpoint', 404),
            };
            return new JsonResponse($data);
        } catch (ApiException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->status);
        } catch (\Throwable $e) {
            $this->logger->error('Amazon Link Monitor API error on {endpoint}: {message}', ['endpoint' => $endpoint, 'message' => $e->getMessage(), 'exception' => $e]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function since(int $days): int
    {
        return $days <= 0 ? 0 : time() - $days * 86400;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        $sites = array_values(array_map(static fn(Site $site): array => [
            'identifier' => $site->getIdentifier(),
            'title' => (string)($site->getConfiguration()['websiteTitle'] ?? '') ?: $site->getIdentifier(),
            'base' => (string)$site->getBase(),
        ], $this->siteFinder->getAllSites()));
        return [
            'sites' => $sites,
            'isAdmin' => $this->backendUser()->isAdmin(),
            'locale' => (string)($this->languageService()->getLocale() ?? 'en'),
            'labels' => $this->languageService()->getLabelsFromResource('EXT:derspe_amazon_link_monitor/Resources/Private/Language/locallang_js.xlf'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readSettings(string $site): array
    {
        $values = [];
        foreach (self::SETTING_KEYS as $key => $type) {
            if ($type === 'secret') {
                $values[$key . '_set'] = $this->settings->isSet($site, $key);
                continue;
            }
            $values[$key] = $this->settings->get($site, $key);
        }
        $values['sitemap_url_default'] = $this->sitemap->sitemapUrl($site);
        $values['api_configured'] = $this->api->isConfigured($site);
        return $values;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function writeSettings(string $site, array $values): array
    {
        if (!$this->backendUser()->isAdmin()) {
            throw new ApiException('Only administrators can change the settings', 403);
        }
        foreach ($values as $key => $value) {
            switch (self::SETTING_KEYS[$key] ?? null) {
                case 'secret':
                    if (is_string($value) && trim($value) !== '') {
                        $this->settings->set($site, $key, trim($value));
                    }
                    break;
                case 'string':
                    $value = trim((string)$value);
                    $value === '' ? $this->settings->remove($site, $key) : $this->settings->set($site, $key, $value);
                    break;
                case 'list':
                    $list = is_array($value) ? $value : explode(',', (string)$value);
                    $list = array_values(array_filter(array_map(static fn($v): string => strtolower(trim((string)$v)), $list), static fn(string $v): bool => $v !== ''));
                    $list === [] ? $this->settings->remove($site, $key) : $this->settings->set($site, $key, $list);
                    break;
            }
        }
        return $this->readSettings($site);
    }

    /**
     * @return array<string, mixed>
     */
    private function stepJob(string $site, int $id): array
    {
        $job = $this->crawl->step($site, $id, self::JOB_STEP_SECONDS) ?? $this->crawl->find($site, $id);
        if ($job === null) {
            throw new ApiException('Job not found', 404);
        }
        return ['job' => CrawlService::toDto($job)];
    }

    private function resolveSite(string $identifier): Site
    {
        $sites = $this->siteFinder->getAllSites();
        if ($sites === []) {
            throw new ApiException('No site configuration found', 503);
        }
        return $sites[$identifier] ?? reset($sites);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }
        $decoded = json_decode((string)$request->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function backendUser(): BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new ApiException('Not logged in', 401);
        }
        return $user;
    }

    private function languageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
