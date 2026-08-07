<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Data;

use App\MarketData\Data\BarData;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(BarData::class)]
final class BarDataTest extends TestCase
{
    public function testFake(): void
    {
        $bar = BarData::fake([
            'symbol' => 'AAPL',
            'date' => '2019-03-15',
            'open' => 180.5,
            'high' => 182.0,
            'low' => 179.25,
            'close' => 181.75,
            'volume' => 1_500_000,
        ]);

        $this->assertSame('AAPL', $bar->symbol);
        $this->assertSame('2019-03-15', $bar->date->toDateString());
        $this->assertSame(1_500_000, $bar->volume);
        $this->assertNull($bar->ts);
    }

    public function testFakeIntraday(): void
    {
        $bar = BarData::fake(['ts' => '2019-03-15 14:30:00']);

        $this->assertSame('2019-03-15 14:30:00', $bar->ts?->format('Y-m-d H:i:s'));
    }
}
