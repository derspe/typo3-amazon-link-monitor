<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Service;

/**
 * Pure helpers around Amazon URLs.
 */
final class AmazonUrl
{
    /** Query parameters that attribute a visit to an Associates account */
    private const AFFILIATE_PARAMS = ['tag', 'linkCode', 'linkId', 'ascsubtag', 'creative', 'creativeASIN', 'camp', 'ref_', 'ref'];

    /** Short link services used by Amazon (SiteStripe) */
    public const SHORTLINK_HOSTS = ['amzn.to', 'a.co', 'amzn.eu'];

    /**
     * @return array{asin: string, affiliateId: string}
     */
    public static function asinAndTag(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return ['asin' => '', 'affiliateId' => ''];
        }
        parse_str($parts['query'] ?? '', $query);
        $asin = '';
        if (preg_match('#/(?:dp|gp/product|gp/aw/d|exec/obidos/ASIN)/([A-Z0-9]{10})(?:[/?]|$)#i', $parts['path'] ?? '', $m) === 1) {
            $asin = strtoupper($m[1]);
        }
        return ['asin' => $asin, 'affiliateId' => is_string($query['tag'] ?? null) ? $query['tag'] : ''];
    }

    /**
     * @param list<string> $domains
     */
    public static function isAmazonLink(string $url, array $domains): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }
        return false;
    }

    public static function isShortlink(string $url): bool
    {
        return in_array(strtolower((string)parse_url($url, PHP_URL_HOST)), self::SHORTLINK_HOSTS, true);
    }

    /**
     * Tracking-free URL for checking: rebuilt from the ASIN if possible (robust against
     * SiteStripe search context), otherwise the affiliate parameters are removed.
     * The check therefore never counts as a click in the Associates account.
     */
    public static function checkUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }
        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        $asin = self::asinAndTag($url)['asin'];
        if ($asin !== '') {
            return $origin . '/dp/' . $asin;
        }
        parse_str($parts['query'] ?? '', $query);
        foreach (self::AFFILIATE_PARAMS as $param) {
            unset($query[$param]);
        }
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $origin . ($parts['path'] ?? '/') . ($queryString !== '' ? '?' . $queryString : '');
    }

    /**
     * Status derived from an HTTP response of Amazon (bot protection is not a broken link).
     *
     * @return 'ok'|'broken'|'unknown'
     */
    public static function statusFromHttp(int $code): string
    {
        if ($code >= 200 && $code < 400) {
            return 'ok';
        }
        if (in_array($code, [404, 410], true)) {
            return 'broken';
        }
        return 'unknown';
    }

    /**
     * Event for the history when a link changes its status; null = no change.
     */
    public static function statusEvent(?string $oldStatus, string $newStatus): ?string
    {
        if ($oldStatus === null || $oldStatus === '') {
            return 'added';
        }
        return $oldStatus === $newStatus ? null : $newStatus;
    }
}
