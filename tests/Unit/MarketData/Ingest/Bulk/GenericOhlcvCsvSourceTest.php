<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Ingest\Bulk;

use App\MarketData\Ingest\Bulk\GenericOhlcvCsvSource;
use App\MarketData\Ingest\Bulk\InvalidCsvHeaderException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(GenericOhlcvCsvSource::class)]
final class GenericOhlcvCsvSourceTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../../fixtures/market-data/daily-sample.csv';

    public function testDailyBars(): void
    {
        $bars = iterator_to_array(new GenericOhlcvCsvSource(self::FIXTURE)->dailyBars());

        $this->assertCount(4, $bars);
        $this->assertSame('AAPL', $bars[0]->symbol);
        $this->assertSame('2019-03-13', $bars[0]->date->toDateString());
        $this->assertEqualsWithDelta(181.71, $bars[0]->close, 0.0001);
        $this->assertSame(31032530, $bars[0]->volume);
    }

    public function testDailyBarsEmptyFile(): void
    {
        $path = $this->tempFile("symbol,date,open,high,low,close,volume\n");

        $bars = iterator_to_array(new GenericOhlcvCsvSource($path)->dailyBars());

        unlink($path);
        $this->assertSame([], $bars);
    }

    public function testDailyBarsInvalidCsvHeaderExceptionThrow(): void
    {
        $path = $this->tempFile("ticker,day,o,h,l,c,v\nAAPL,2019-03-13,1,1,1,1,1\n");

        $this->expectException(InvalidCsvHeaderException::class);

        try {
            iterator_to_array(new GenericOhlcvCsvSource($path)->dailyBars());
        } finally {
            unlink($path);
        }
    }

    public function testName(): void
    {
        $this->assertSame('bulk:daily-sample.csv', new GenericOhlcvCsvSource(self::FIXTURE)->name());
    }

    /** tempnam() vrací string|false, assert typ zúží pro level max. */
    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bars');

        $this->assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
