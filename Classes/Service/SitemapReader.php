<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Service;

use Derspe\AmazonLinkMonitor\Http\ApiException;
use Derspe\AmazonLinkMonitor\Settings\SiteSettings;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Reads page URLs from the XML sitemap (incl. sitemap index) and fetches pages.
 */
final readonly class SitemapReader
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; TYPO3-AmazonLinkMonitor/1.0)';

    public function __construct(
        private SiteSettings $settings,
        private SiteFinder $siteFinder,
        private GuzzleClientFactory $clientFactory,
    ) {}

    public function sitemapUrl(string $site): string
    {
        $configured = $this->settings->getString($site, 'sitemap_url');
        if ($configured !== '') {
            return $configured;
        }
        try {
            $base = rtrim((string)$this->siteFinder->getSiteByIdentifier($site)->getBase(), '/');
        } catch (\Throwable) {
            return '';
        }
        return str_starts_with($base, 'http') ? $base . '/sitemap.xml' : '';
    }

    /**
     * @return list<string>
     */
    public function urls(string $sitemapUrl, int $depth = 0): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($this->fetch($sitemapUrl), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($document === false) {
            return [];
        }
        $document->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $pages = self::texts($document->xpath('//sm:url/sm:loc') ?: $document->xpath('//url/loc') ?: []);
        if ($pages !== [] || $depth > 2) {
            return array_values(array_unique($pages));
        }
        $urls = [];
        foreach (self::texts($document->xpath('//sm:sitemap/sm:loc') ?: $document->xpath('//sitemap/loc') ?: []) as $child) {
            array_push($urls, ...$this->urls($child, $depth + 1));
        }
        return array_values(array_unique($urls));
    }

    public function fetch(string $url): string
    {
        try {
            $response = $this->clientFactory->getClient()->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 20,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new ApiException('Could not fetch ' . $url . ': ' . $e->getMessage(), 502, $e);
        }
        if ($response->getStatusCode() >= 400) {
            throw new ApiException('Could not fetch ' . $url . ' (HTTP ' . $response->getStatusCode() . ')', 502);
        }
        return (string)$response->getBody();
    }

    /**
     * @param array<\SimpleXMLElement> $nodes
     * @return list<string>
     */
    private static function texts(array $nodes): array
    {
        return array_values(array_filter(array_map(static fn(\SimpleXMLElement $n): string => trim((string)$n), $nodes)));
    }
}
