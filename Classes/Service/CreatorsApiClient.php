<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Service;

use Derspe\AmazonLinkMonitor\Http\ApiException;
use Derspe\AmazonLinkMonitor\Settings\SiteSettings;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * Minimal client for the Amazon Creators API (getItems), used to verify that the
 * products behind links still exist.
 */
final readonly class CreatorsApiClient
{
    public const BATCH_SIZE = 10;

    public function __construct(
        private SiteSettings $settings,
        private GuzzleClientFactory $clientFactory,
        private FrontendInterface $cache,
    ) {}

    public function isConfigured(string $site): bool
    {
        return $this->settings->isSet($site, 'api_credential_id')
            && $this->settings->isSet($site, 'api_credential_secret')
            && $this->settings->isSet($site, 'api_partner_tag');
    }

    /**
     * @param list<string> $asins max. 10
     * @return array{items: array<string, array<string, mixed>>, errors: array<string, array{code: string, message: string}>}
     */
    public function getItems(string $site, array $asins): array
    {
        $asins = array_values(array_unique($asins));
        if ($asins === []) {
            return ['items' => [], 'errors' => []];
        }
        $data = $this->post($site, '/getItems', [
            'itemIds' => $asins,
            'partnerTag' => $this->settings->getString($site, 'api_partner_tag'),
            'marketplace' => $this->settings->getString($site, 'api_marketplace'),
            'resources' => ['itemInfo.title', 'offersV2.listings.availability'],
        ]);
        $items = [];
        foreach ($data['itemsResult']['items'] ?? [] as $item) {
            $items[strtoupper((string)($item['asin'] ?? ''))] = $item;
        }
        $errors = [];
        foreach ($data['errors'] ?? [] as $error) {
            $message = (string)($error['message'] ?? '');
            $code = (string)($error['code'] ?? 'Error');
            // The API reports per-item errors with the ASIN inside the message
            foreach ($asins as $asin) {
                if (!isset($items[$asin]) && str_contains($message, $asin)) {
                    $errors[$asin] = ['code' => $code, 'message' => $message];
                }
            }
        }
        return ['items' => $items, 'errors' => $errors];
    }

    /**
     * @return array{title: string, availability: string}
     */
    public static function itemSummary(array $item): array
    {
        $listings = $item['offersV2']['listings'] ?? [];
        $availability = '';
        foreach ($listings as $listing) {
            if (is_array($listing['availability'] ?? null)) {
                $availability = (string)($listing['availability']['type'] ?? '');
                break;
            }
        }
        return [
            'title' => (string)($item['itemInfo']['title']['displayValue'] ?? ''),
            'availability' => $availability,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $site, string $path, array $body): array
    {
        $marketplace = $this->settings->getString($site, 'api_marketplace');
        try {
            $response = $this->clientFactory->getClient()->request('POST', rtrim($this->settings->getString($site, 'api_base_url'), '/') . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken($site) . ', Version ' . $this->settings->getString($site, 'api_credential_version'),
                    'x-marketplace' => $marketplace,
                ],
                'json' => $body,
                'timeout' => 20,
                'http_errors' => false,
            ]);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApiException('Creators API not reachable: ' . $e->getMessage(), 502, $e);
        }
        $status = $response->getStatusCode();
        $content = (string)$response->getBody();
        if ($status === 429) {
            throw new ApiException('Creators API rate limit reached', 429);
        }
        if ($status >= 400) {
            throw new ApiException('Creators API error (HTTP ' . $status . '): ' . substr($content, 0, 300), 502);
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new ApiException('Creators API returned invalid JSON', 502);
        }
        return $data;
    }

    private function accessToken(string $site): string
    {
        if (!$this->isConfigured($site)) {
            throw new ApiException('Creators API is not configured', 503);
        }
        $identifier = 'token_' . sha1($site . '|' . $this->settings->getString($site, 'api_credential_id'));
        $cached = $this->cache->get($identifier);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        try {
            $response = $this->clientFactory->getClient()->request('POST', $this->settings->getString($site, 'api_token_url'), [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->settings->getString($site, 'api_credential_id'),
                    'client_secret' => $this->settings->getString($site, 'api_credential_secret'),
                    'scope' => 'creatorsapi/default',
                ],
                'timeout' => 15,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new ApiException('Creators API token endpoint not reachable: ' . $e->getMessage(), 502, $e);
        }
        $data = json_decode((string)$response->getBody(), true) ?: [];
        $token = (string)($data['access_token'] ?? '');
        if ($response->getStatusCode() >= 400 || $token === '') {
            throw new ApiException('Creators API authentication failed (HTTP ' . $response->getStatusCode() . ')', 503);
        }
        $this->cache->set($identifier, $token, [], max(60, (int)($data['expires_in'] ?? 3600) - 60));
        return $token;
    }
}
