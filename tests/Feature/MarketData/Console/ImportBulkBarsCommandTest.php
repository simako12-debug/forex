<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Console;

use App\MarketData\Console\ImportBulkBarsCommand;
use App\MarketData\Models\DailyBar;
use App\MarketData\Models\Instrument;
use App\MarketData\Models\MarketDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ImportBulkBarsCommand::class)]
final class ImportBulkBarsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURE = __DIR__ . '/../../../fixtures/market-data/daily-sample.csv';

    public function testHandle(): void
    {
        $this->seedCatalogue();

        $this->importBulk(self::FIXTURE)->assertSuccessful();

        $this->assertSame(4, DailyBar::query()->count());
    }

    public function testHandleUnreadableFile(): void
    {
        $this->importBulk(__DIR__ . '/neexistuje.csv')->assertFailed();

        $this->assertSame(0, DailyBar::query()->count());
    }

    public function testHandleSecondRunInsertsNothing(): void
    {
        $this->seedCatalogue();

        $this->importBulk(self::FIXTURE)->assertSuccessful();
        $this->importBulk(self::FIXTURE)->assertSuccessful();

        $this->assertSame(4, DailyBar::query()->count());
    }

    /** Viz EnsurePartitionsCommandTest — artisan() vrací PendingCommand|int. */
    private function importBulk(string $path): PendingCommand
    {
        $command = $this->artisan('market-data:import-bulk', ['path' => $path]);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function seedCatalogue(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        MarketDay::factory()->create(['date' => '2019-03-14', 'is_open' => true]);

        $symbols = [
            '550e8400-e29b-41d4-a716-446655440000' => 'AAPL',
            '6ba7b810-9dad-11d1-80b4-00c04fd430c8' => 'XYZ',
        ];

        foreach ($symbols as $id => $symbol) {
            $instrument = Instrument::factory()->create(['id' => $id]);
            $instrument->symbols()->create(['symbol' => $symbol, 'valid_from' => '2000-01-03', 'valid_to' => null]);
        }
    }
}
