<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Service;

/**
 * Extracts Amazon links from HTML with link text, surrounding text and occurrence count.
 */
final class LinkExtractor
{
    private const CONTEXT_CHARS = 50;
    private const STOP_TAGS = ['body', 'main', 'article', 'section'];

    /**
     * @param list<string> $domains
     * @return array{title: string, links: list<array{url: string, text: string, context: string, asin: string, affiliateId: string, occurrences: int}>}
     */
    public static function extract(string $html, string $pageUrl, array $domains): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $titleNode = $dom->getElementsByTagName('title')->item(0);
        $title = $titleNode !== null ? trim($titleNode->textContent) : '';

        $links = [];
        $counts = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = trim(html_entity_decode($anchor->getAttribute('href'), ENT_QUOTES | ENT_HTML5));
            if ($href === '') {
                continue;
            }
            $href = self::absolute($href, $pageUrl);
            if (!AmazonUrl::isAmazonLink($href, $domains)) {
                continue;
            }
            $counts[$href] = ($counts[$href] ?? 0) + 1;
            if (isset($links[$href])) {
                continue;
            }
            $text = self::normalizeSpace($anchor->textContent);
            $tag = AmazonUrl::asinAndTag($href);
            $links[$href] = [
                'url' => $href,
                'text' => $text,
                'context' => self::context(self::surroundingText($anchor, $text), $text),
                'asin' => $tag['asin'],
                'affiliateId' => $tag['affiliateId'],
                'occurrences' => 0,
            ];
        }
        foreach ($links as $href => $link) {
            $links[$href]['occurrences'] = $counts[$href];
        }
        return ['title' => $title, 'links' => array_values($links)];
    }

    /**
     * Walks up the DOM until the parent text is clearly longer than the link text.
     */
    private static function surroundingText(\DOMElement $anchor, string $linkText): string
    {
        $node = $anchor->parentNode;
        for ($i = 0; $i < 5 && $node instanceof \DOMElement; $i++) {
            $text = self::normalizeSpace($node->textContent);
            if (mb_strlen($text) > mb_strlen($linkText) + 30) {
                return $text;
            }
            $parent = $node->parentNode;
            if (!$parent instanceof \DOMElement || in_array(strtolower($parent->tagName), self::STOP_TAGS, true)) {
                break;
            }
            $node = $parent;
        }
        return $node instanceof \DOMElement ? self::normalizeSpace($node->textContent) : $linkText;
    }

    public static function context(string $fullText, string $linkText): string
    {
        if ($linkText === '') {
            return mb_substr($fullText, 0, 2 * self::CONTEXT_CHARS);
        }
        $position = mb_strpos($fullText, $linkText);
        if ($position === false) {
            return $linkText;
        }
        $start = max(0, $position - self::CONTEXT_CHARS);
        $end = min(mb_strlen($fullText), $position + mb_strlen($linkText) + self::CONTEXT_CHARS);
        return trim(mb_substr($fullText, $start, $end - $start));
    }

    private static function normalizeSpace(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private static function absolute(string $href, string $pageUrl): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        $base = parse_url($pageUrl);
        $scheme = $base['scheme'] ?? 'https';
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $scheme . '://' . ($base['host'] ?? '') . $href;
        }
        return $href;
    }
}
