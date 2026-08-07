<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Calendar;

use App\MarketData\Calendar\CalendarImporter;
use App\MarketData\Data\MarketDayData;
use App\MarketData\Models\MarketDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(CalendarImporter::class)]
final class CalendarImporterTest extends TestCase
{
    use RefreshDatabase;

    public function testImport(): void
    {
        $imported = App::make(CalendarImporter::class)->import([
            MarketDayData::fake(['date' => '2019-11-28', 'closeAt' => '16:00']),
            MarketDayData::fake(['date' => '2019-11-29', 'closeAt' => '13:00', 'isEarlyClose' => true]),
        ]);

        $this->assertSame(2, $imported);
        $this->assertTrue(MarketDay::isTradingDay('XNYS', CarbonImmutable::parse('2019-11-28')));
    }

    public function testImportIdempotence(): void
    {
        $days = [MarketDayData::fake(['date' => '2019-11-28'])];

        App::make(CalendarImporter::class)->import($days);
        App::make(CalendarImporter::class)->import($days);

        $this->assertSame(1, MarketDay::query()->count());
    }

    public function testIsTradingDayUnknownDate(): void
    {
        $this->assertFalse(MarketDay::isTradingDay('XNYS', CarbonImmutable::parse('2019-12-25')));
    }
}
