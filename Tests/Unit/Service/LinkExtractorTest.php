<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Tests\Unit\Service;

use Derspe\AmazonLinkMonitor\Service\LinkExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LinkExtractorTest extends TestCase
{
    private const HTML = <<<'HTML'
        <html><head><title>Best thermostats</title></head><body><article>
        <p>For the living room I use the <a href="https://www.amazon.de/dp/B000000001?tag=example-21">Thermostat X</a> with a great app and long battery life.</p>
        <p>Short: <a href="https://amzn.to/abc">deal</a></p>
        <p>Again <a href="https://www.amazon.de/dp/B000000001?tag=example-21">Thermostat X</a></p>
        <p><a href="https://example.com/other">other</a> <a href="/p/B000000002">internal</a></p>
        </article></body></html>
        HTML;

    #[Test]
    public function amazonLinksAreExtractedDeduplicatedAndCounted(): void
    {
        $result = LinkExtractor::extract(self::HTML, 'https://example.com/post', ['amazon.de', 'amzn.to']);
        self::assertSame('Best thermostats', $result['title']);
        self::assertCount(2, $result['links']);
        $first = $result['links'][0];
        self::assertSame('https://www.amazon.de/dp/B000000001?tag=example-21', $first['url']);
        self::assertSame('Thermostat X', $first['text']);
        self::assertSame('B000000001', $first['asin']);
        self::assertSame('example-21', $first['affiliateId']);
        self::assertSame(2, $first['occurrences']);
        self::assertStringContainsString('living room I use the Thermostat X with a great app', $first['context']);
        self::assertSame(1, $result['links'][1]['occurrences']);
    }

    #[Test]
    public function contextIsCutAroundTheLinkText(): void
    {
        $text = str_repeat('a', 100) . 'LINK' . str_repeat('b', 100);
        self::assertSame(str_repeat('a', 50) . 'LINK' . str_repeat('b', 50), LinkExtractor::context($text, 'LINK'));
        self::assertSame('missing', LinkExtractor::context('no match here', 'missing'));
    }
}
