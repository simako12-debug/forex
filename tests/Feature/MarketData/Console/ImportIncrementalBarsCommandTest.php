<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Console;

use App\MarketData\Console\ImportIncrementalBarsCommand;
use App\MarketData\Models\DailyBar;
use App\MarketData\Models\Instrument;
use App\MarketData\Models\MarketDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ImportIncrementalBarsCommand::class)]
final class ImportIncrementalBarsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

    public function testHandle(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $this->instrument();
        $this->fakeBars();

        $this->importIncremental('2019-03-13')->assertSuccessful();

        $this->assertSame(1, DailyBar::query()->count());
    }

    public function testHandleNonTradingDay(): void
    {
        MarketDay::factory()->create(['date' => '2019-12-25', 'is_open' => false]);
        $this->instrument();
        $this->fakeBars();

        $this->importIncremental('2019-12-25')->assertSuccessful();

        $this->assertSame(0, DailyBar::query()->count());
        Http::assertNothingSent();
    }

    public function testHandleNoActiveSymbols(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $this->fakeBars();

        $this->importIncremental('2019-03-13')->assertSuccessful();

        $this->assertSame(0, DailyBar::query()->count());
        Http::assertNothingSent();
    }

    private function fakeBars(): void
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
    }

    private function instrument(): void
    {
        $instrument = Instrument::factory()->create(['id' => self::INSTRUMENT]);
        $instrument->symbols()->create(['symbol' => 'AAPL', 'valid_from' => '2000-01-03', 'valid_to' => null]);
    }

    /** Viz EnsurePartitionsCommandTest — artisan() vrací PendingCommand|int. */
    private function importIncremental(string $to): PendingCommand
    {
        $command = $this->artisan('market-data:import-incremental', ['--to' => $to]);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
