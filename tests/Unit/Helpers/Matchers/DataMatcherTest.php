<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Matchers;

use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Helpers\Fixtures\DataMatcherFixtureData;
use Tests\Helpers\Matchers\DataMatcher;
use Tests\TestCase;

#[CoversClass(DataMatcher::class)]
final class DataMatcherTest extends TestCase
{
    public function testMatches(): void
    {
        $matcher = new DataMatcher(new DataMatcherFixtureData('AAPL', 100));

        $this->assertTrue($matcher->matches(new DataMatcherFixtureData('AAPL', 100)));
    }

    public function testMatchesDifferentValue(): void
    {
        $matcher = new DataMatcher(new DataMatcherFixtureData('AAPL', 100));

        $this->assertFalse($matcher->matches(new DataMatcherFixtureData('AAPL', 101)));
    }

    public function testMatchesNonData(): void
    {
        $matcher = new DataMatcher(new DataMatcherFixtureData('AAPL', 100));

        $this->assertFalse($matcher->matches(['symbol' => 'AAPL', 'volume' => 100]));
    }
}
