<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Ingest\Incremental;

use App\MarketData\Ingest\Incremental\AlpacaBarSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(AlpacaBarSource::class)]
final class AlpacaBarSourceTest extends TestCase
{
    public function testDailyBars(): void
    {
        Http::fake([
            '*/v2/stocks/bars*' => Http::response([
                'bars' => [
                    'AAPL' => [
                        ['t' => '2019-03-13T04:00:00Z', 'o' => 182.25, 'h' => 183.3,
                            'l' => 181.46, 'c' => 181.71, 'v' => 31032530],
                    ],
                ],
                'next_page_token' => null,
            ]),
        ]);

        $bars = iterator_to_array($this->source()->dailyBars());

        $this->assertCount(1, $bars);
        $this->assertSame('AAPL', $bars[0]->symbol);
        $this->assertSame('2019-03-13', $bars[0]->date->toDateString());
    }

    /**
     * Test na stránkování je povinný — bez něj by se při 1500 symbolech tiše
     * naimportovala jen první stránka a nikdo by to nepoznal.
     */
    public function testDailyBarsPagination(): void
    {
        Http::fakeSequence()
            ->push(['bars' => ['AAPL' => [['t' => '2019-03-13T04:00:00Z', 'o' => 1, 'h' => 1,
                'l' => 1, 'c' => 1, 'v' => 1]]], 'next_page_token' => 'tok'])
            ->push(['bars' => ['AAPL' => [['t' => '2019-03-14T04:00:00Z', 'o' => 1, 'h' => 1,
                'l' => 1, 'c' => 1, 'v' => 1]]], 'next_page_token' => null]);

        $bars = iterator_to_array($this->source()->dailyBars());

        $this->assertCount(2, $bars);
    }

    public function testDailyBarsEmptyResponse(): void
    {
        Http::fake(['*/v2/stocks/bars*' => Http::response(['bars' => [], 'next_page_token' => null])]);

        $this->assertSame([], iterator_to_array($this->source()->dailyBars()));
    }

    public function testName(): void
    {
        $this->assertSame('alpaca:bars', $this->source()->name());
    }

    private function source(): AlpacaBarSource
    {
        return new AlpacaBarSource(
            symbols: ['AAPL'],
            from: CarbonImmutable::parse('2019-03-13'),
            to: CarbonImmutable::parse('2019-03-14'),
            baseUrl: 'https://data.alpaca.markets',
            keyId: 'k',
            secretKey: 's',
            feed: 'iex',
        );
    }
}
