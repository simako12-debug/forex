<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Console;

use App\MarketData\Console\EnsurePartitionsCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(EnsurePartitionsCommand::class)]
final class EnsurePartitionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testHandle(): void
    {
        $this->ensurePartitions(2019, 2019)->assertSuccessful();

        $this->assertTrue($this->partitionExists('daily_bars_2019'));
        $this->assertTrue($this->partitionExists('intraday_bars_2019_01'));
        $this->assertTrue($this->partitionExists('intraday_bars_2019_12'));
    }

    public function testHandleIdempotence(): void
    {
        $this->ensurePartitions(2019, 2019)->assertSuccessful();
        $this->ensurePartitions(2019, 2019)->assertSuccessful();

        $this->assertTrue($this->partitionExists('daily_bars_2019'));
    }

    /**
     * TestCase::artisan() vrací PendingCommand|int, takže volat na výsledku
     * assertSuccessful() na levelu max neprojde. Assert typ zúží.
     */
    private function ensurePartitions(int $fromYear, int $toYear): PendingCommand
    {
        $command = $this->artisan('market-data:ensure-partitions', [
            '--from-year' => $fromYear,
            '--to-year' => $toYear,
        ]);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function partitionExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS found FROM pg_class WHERE relname = ?',
            [$name],
        ) !== null;
    }
}
