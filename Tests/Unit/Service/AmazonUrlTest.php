<?php

declare(strict_types=1);

namespace Derspe\AmazonLinkMonitor\Tests\Unit\Service;

use Derspe\AmazonLinkMonitor\Service\AmazonUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AmazonUrlTest extends TestCase
{
    #[Test]
    public function asinAndTagAreExtracted(): void
    {
        self::assertSame(
            ['asin' => 'B08DFPV5Y2', 'affiliateId' => 'example-21'],
            AmazonUrl::asinAndTag('https://www.amazon.de/Some-Product/dp/b08dfpv5y2/ref=sr_1_1?tag=example-21&keywords=x')
        );
        self::assertSame('B000000001', AmazonUrl::asinAndTag('https://www.amazon.de/gp/product/B000000001')['asin']);
        self::assertSame(['asin' => '', 'affiliateId' => ''], AmazonUrl::asinAndTag('https://www.amazon.de/s?k=thermostat'));
    }

    #[Test]
    public function amazonLinksAreDetectedByHostAndSubdomain(): void
    {
        $domains = ['amazon.de', 'amzn.to'];
        self::assertTrue(AmazonUrl::isAmazonLink('https://www.amazon.de/dp/B000000001', $domains));
        self::assertTrue(AmazonUrl::isAmazonLink('https://amzn.to/abc', $domains));
        self::assertFalse(AmazonUrl::isAmazonLink('https://notamazon.de/dp/B000000001', $domains));
        self::assertFalse(AmazonUrl::isAmazonLink('/p/B000000001', $domains));
    }

    #[Test]
    public function checkUrlNeverContainsAffiliateParameters(): void
    {
        self::assertSame('https://www.amazon.de/dp/B08DFPV5Y2', AmazonUrl::checkUrl('https://www.amazon.de/x/dp/B08DFPV5Y2?tag=example-21&linkCode=ll1'));
        self::assertSame('https://www.amazon.de/s?k=thermostat', AmazonUrl::checkUrl('https://www.amazon.de/s?k=thermostat&tag=example-21&ref_=abc'));
    }

    #[Test]
    public function shortlinksAreRecognized(): void
    {
        self::assertTrue(AmazonUrl::isShortlink('https://amzn.to/3abc'));
        self::assertTrue(AmazonUrl::isShortlink('https://a.co/d/xyz'));
        self::assertFalse(AmazonUrl::isShortlink('https://www.amazon.de/dp/B000000001'));
    }

    #[Test]
    public function botProtectionIsNotReportedAsBroken(): void
    {
        self::assertSame('ok', AmazonUrl::statusFromHttp(200));
        self::assertSame('broken', AmazonUrl::statusFromHttp(404));
        self::assertSame('unknown', AmazonUrl::statusFromHttp(503));
    }

    #[Test]
    public function statusEventsOnlyOnChange(): void
    {
        self::assertSame('added', AmazonUrl::statusEvent('', 'ok'));
        self::assertNull(AmazonUrl::statusEvent('ok', 'ok'));
        self::assertSame('broken', AmazonUrl::statusEvent('ok', 'broken'));
        self::assertSame('ok', AmazonUrl::statusEvent('broken', 'ok'));
    }
}
