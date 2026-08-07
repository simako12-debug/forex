<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Console;

use App\MarketData\Console\DataHealthCommand;
use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Ingest\PartitionManager;
use App\MarketData\Models\IngestRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(DataHealthCommand::class)]
final class DataHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-07 12:00:00');
    }

    /** Nenulový exit kód je celý smysl příkazu. */
    public function testHandleUnhealthy(): void
    {
        $this->health()->assertExitCode(1);
    }

    public function testHandleHealthy(): void
    {
        $partitions = App::make(PartitionManager::class);
        $partitions->ensureDailyYear(2026);
        $partitions->ensureDailyYear(2027);

        IngestRun::factory()->create([
            'status' => IngestStatusEnum::COMPLETED,
            'finished_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->health()->assertExitCode(0);
    }

    /** Viz EnsurePartitionsCommandTest — artisan() vrací PendingCommand|int. */
    private function health(): PendingCommand
    {
        $command = $this->artisan('market-data:health');

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
