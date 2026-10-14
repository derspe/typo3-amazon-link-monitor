<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Service;

use Derspe\AmazonLinkMonitor\Http\ApiException;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * Checks Amazon links without ever creating an affiliate click:
 *
 * 1. Short links (amzn.to, a.co, amzn.eu) are resolved at the short link service
 *    without following the redirect to Amazon.
 * 2. Links with an ASIN are verified in batches via the Creators API (if configured).
 * 3. Everything else is requested from Amazon without affiliate parameters. Bot protection
 *    answers (503, captcha) are reported as "unknown", not as broken.
 */
final readonly class LinkChecker
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    /** Creators API: 1 request per second */
    private const API_INTERVAL_US = 1_100_000;

    /** API error codes that mean "the product does not exist" */
    private const BROKEN_CODES = ['InvalidParameterValue', 'ItemNotFound', 'ItemsNotFound', 'NoResults'];
    /** The product exists on Amazon but is not available through the API */
    private const WARNING_CODES = ['ItemNotAccessible'];

    public function __construct(
        private CreatorsApiClient $api,
        private GuzzleClientFactory $clientFactory,
    ) {}

    /**
     * @param list<string> $urls
     * @return array<string, array{status: string, statusCode: ?int, reason: string, method: string, productTitle: string, availability: string, asin: string, affiliateId: string}>
     */
    public function check(string $site, array $urls): array
    {
        $results = [];
        $byAsin = [];
        $useApi = $this->api->isConfigured($site);

        foreach ($urls as $url) {
            $target = $url;
            if (AmazonUrl::isShortlink($url)) {
                [$code, $location] = $this->resolveShortlink($url);
                if ($location === null) {
                    $results[$url] = self::result($code === 0 ? 'unknown' : 'broken', $code ?: null, $code === 0 ? 'shortlink-unreachable' : 'shortlink-dead', 'shortlink');
                    continue;
                }
                $target = $location;
            }
            $tag = AmazonUrl::asinAndTag($target);
            if ($useApi && $tag['asin'] !== '') {
                $byAsin[$tag['asin']][] = [$url, $tag];
                continue;
            }
            $results[$url] = $this->checkHttp($target) + ['asin' => $tag['asin'], 'affiliateId' => $tag['affiliateId']];
        }

        $first = true;
        foreach (array_chunk(array_keys($byAsin), CreatorsApiClient::BATCH_SIZE) as $batch) {
            if (!$first) {
                usleep(self::API_INTERVAL_US);
            }
            $first = false;
            try {
                $response = $this->api->getItems($site, $batch);
            } catch (ApiException $e) {
                if ($e->status === 429) {
                    usleep(2 * self::API_INTERVAL_US);
                }
                foreach ($batch as $asin) {
                    foreach ($byAsin[$asin] as [$url, $tag]) {
                        $results[$url] = self::result('unknown', null, 'api-error: ' . $e->getMessage(), 'api') + ['asin' => $asin, 'affiliateId' => $tag['affiliateId']];
                    }
                }
                continue;
            }
            foreach ($batch as $asin) {
                if (isset($response['items'][$asin])) {
                    $summary = CreatorsApiClient::itemSummary($response['items'][$asin]);
                    $result = self::result('ok', 200, '', 'api', $summary['title'], $summary['availability']);
                } elseif (isset($response['errors'][$asin])) {
                    $code = $response['errors'][$asin]['code'];
                    $status = in_array($code, self::BROKEN_CODES, true) ? 'broken' : (in_array($code, self::WARNING_CODES, true) ? 'warning' : 'unknown');
                    $result = self::result($status, null, $code, 'api');
                } else {
                    $result = self::result('unknown', null, 'no-api-answer', 'api');
                }
                foreach ($byAsin[$asin] as [$url, $tag]) {
                    $results[$url] = $result + ['asin' => $asin, 'affiliateId' => $tag['affiliateId']];
                }
            }
        }
        return $results;
    }

    /**
     * @return array{0: int, 1: ?string} [status code, redirect location]
     */
    private function resolveShortlink(string $url): array
    {
        try {
            $response = $this->clientFactory->getClient()->request('GET', $url, [
                'allow_redirects' => false,
                'http_errors' => false,
                'timeout' => 15,
                'headers' => ['User-Agent' => self::USER_AGENT],
            ]);
        } catch (\Throwable) {
            return [0, null];
        }
        $location = $response->getHeaderLine('Location');
        return [$response->getStatusCode(), $location !== '' ? $location : null];
    }

    /**
     * @return array{status: string, statusCode: ?int, reason: string, method: string, productTitle: string, availability: string}
     */
    private function checkHttp(string $url): array
    {
        $checkUrl = AmazonUrl::checkUrl($url);
        try {
            $response = $this->clientFactory->getClient()->request('GET', $checkUrl, [
                'allow_redirects' => ['max' => 5],
                'http_errors' => false,
                'timeout' => 20,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
                ],
            ]);
        } catch (\Throwable $e) {
            return self::result('unknown', null, 'unreachable', 'http');
        }
        $code = $response->getStatusCode();
        $body = $code === 200 ? (string)$response->getBody() : '';
        // Captcha / robot check pages come with status 200
        if ($body !== '' && (str_contains($body, 'validateCaptcha') || str_contains($body, 'api-services-support@amazon.com'))) {
            return self::result('unknown', $code, 'bot-protection', 'http');
        }
        $title = '';
        if ($body !== '' && preg_match('#<span[^>]+id="productTitle"[^>]*>(.*?)</span>#s', $body, $m) === 1) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
        }
        $status = AmazonUrl::statusFromHttp($code);
        return self::result($status, $code, $status === 'unknown' ? 'http-' . $code : '', 'http', $title);
    }

    /**
     * @return array{status: string, statusCode: ?int, reason: string, method: string, productTitle: string, availability: string}
     */
    private static function result(string $status, ?int $code, string $reason, string $method, string $title = '', string $availability = ''): array
    {
        return [
            'status' => $status,
            'statusCode' => $code,
            'reason' => $reason,
            'method' => $method,
            'productTitle' => $title,
            'availability' => $availability,
        ];
    }
}
