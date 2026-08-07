<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Health;

use App\MarketData\Enums\FindingSeverityEnum;
use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Health\HealthChecker;
use App\MarketData\Ingest\PartitionManager;
use App\MarketData\Models\IngestRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(HealthChecker::class)]
final class HealthCheckerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-07 12:00:00');
    }

    public function testCheckHealthy(): void
    {
        $this->partitions();
        $this->freshIngest();

        $report = App::make(HealthChecker::class)->check();

        $this->assertTrue($report->healthy);
        $this->assertSame(0, $report->openErrorFindings);
        $this->assertSame([], $report->missingPartitionYears);
    }

    public function testCheckStaleIngest(): void
    {
        $this->partitions();
        IngestRun::factory()->create([
            'status' => IngestStatusEnum::COMPLETED,
            'finished_at' => CarbonImmutable::now()->subDays(5),
        ]);

        $report = App::make(HealthChecker::class)->check();

        $this->assertFalse($report->healthy);
    }

    public function testCheckNoIngestAtAll(): void
    {
        $this->partitions();

        $report = App::make(HealthChecker::class)->check();

        $this->assertFalse($report->healthy);
        $this->assertNull($report->lastSuccessfulIngestAt);
    }

    public function testCheckOpenErrorFinding(): void
    {
        $this->partitions();
        $run = $this->freshIngest();
        $run->findings()->create([
            'rule' => 'OhlcConsistency',
            'severity' => FindingSeverityEnum::ERROR,
            'detail' => 'low > high',
        ]);

        $report = App::make(HealthChecker::class)->check();

        $this->assertFalse($report->healthy);
        $this->assertSame(1, $report->openErrorFindings);
    }

    public function testCheckWarningFindingStaysHealthy(): void
    {
        $this->partitions();
        $run = $this->freshIngest();
        $run->findings()->create([
            'rule' => 'ZeroOrMissingVolume',
            'severity' => FindingSeverityEnum::WARNING,
            'detail' => 'nulový objem',
        ]);

        $this->assertTrue(App::make(HealthChecker::class)->check()->healthy);
    }

    public function testCheckMissingPartition(): void
    {
        $this->freshIngest();

        $report = App::make(HealthChecker::class)->check();

        $this->assertFalse($report->healthy);
        $this->assertSame([2026, 2027], $report->missingPartitionYears);
    }

    private function partitions(): void
    {
        $partitions = App::make(PartitionManager::class);
        $partitions->ensureDailyYear(2026);
        $partitions->ensureDailyYear(2027);
    }

    private function freshIngest(): IngestRun
    {
        return IngestRun::factory()->create([
            'status' => IngestStatusEnum::COMPLETED,
            'finished_at' => CarbonImmutable::now()->subHour(),
        ]);
    }
}
