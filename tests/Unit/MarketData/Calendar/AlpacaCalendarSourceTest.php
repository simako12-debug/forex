<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Calendar;

use App\MarketData\Calendar\AlpacaCalendarSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(AlpacaCalendarSource::class)]
final class AlpacaCalendarSourceTest extends TestCase
{
    public function testFetch(): void
    {
        Http::fake([
            '*/v2/calendar*' => Http::response([
                ['date' => '2019-11-28', 'open' => '09:30', 'close' => '16:00'],
                ['date' => '2019-11-29', 'open' => '09:30', 'close' => '13:00'],
            ]),
        ]);

        $days = iterator_to_array(
            App::make(AlpacaCalendarSource::class)->fetch(
                CarbonImmutable::parse('2019-11-28'),
                CarbonImmutable::parse('2019-11-29'),
            ),
        );

        $this->assertCount(2, $days);
        $this->assertSame('2019-11-28', $days[0]->date->toDateString());
        $this->assertTrue($days[0]->isOpen);
        $this->assertFalse($days[0]->isEarlyClose);
    }

    public function testFetchEarlyClose(): void
    {
        Http::fake([
            '*/v2/calendar*' => Http::response([
                ['date' => '2019-11-29', 'open' => '09:30', 'close' => '13:00'],
            ]),
        ]);

        $days = iterator_to_array(
            App::make(AlpacaCalendarSource::class)->fetch(
                CarbonImmutable::parse('2019-11-29'),
                CarbonImmutable::parse('2019-11-29'),
            ),
        );

        $this->assertTrue($days[0]->isEarlyClose);
    }

    public function testFetchEmptyResponse(): void
    {
        Http::fake(['*/v2/calendar*' => Http::response([])]);

        $days = iterator_to_array(
            App::make(AlpacaCalendarSource::class)->fetch(
                CarbonImmutable::parse('2019-12-25'),
                CarbonImmutable::parse('2019-12-25'),
            ),
        );

        $this->assertSame([], $days);
    }
}
