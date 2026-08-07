<?php

declare(strict_types=1);

namespace App\MarketData\Health;

use App\MarketData\Enums\FindingSeverityEnum;
use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Models\IngestRun;
use App\MarketData\Models\MarketDay;
use App\MarketData\Models\ValidationFinding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Každá kontrola je samostatná privátní metoda, aby šla ověřit čtením kódu.
 * Report je datový objekt; rozhodnutí, co s ním, patří commandu.
 */
class HealthChecker
{
    private const int STALE_INGEST_HOURS = 48;
    private const int COVERAGE_WINDOW_DAYS = 30;

    public function check(): HealthReport
    {
        $lastIngest = $this->lastSuccessfulIngestAt();
        $coverage = $this->tradingDaysCoveredLast30();
        $errors = $this->openErrorFindings();
        $missingPartitions = $this->missingPartitionYears();

        $healthy = $this->isIngestFresh($lastIngest) === true
            && $errors === 0
            && $missingPartitions === [];

        return new HealthReport(
            lastSuccessfulIngestAt: $lastIngest,
            tradingDaysCoveredLast30: $coverage,
            openErrorFindings: $errors,
            missingPartitionYears: $missingPartitions,
            healthy: $healthy,
        );
    }

    private function lastSuccessfulIngestAt(): null|CarbonImmutable
    {
        $run = IngestRun::query()
            ->where('status', IngestStatusEnum::COMPLETED)
            ->orderByDesc('finished_at')
            ->first();

        return $run?->finished_at;
    }

    private function isIngestFresh(null|CarbonImmutable $lastIngest): bool
    {
        if ($lastIngest === null) {
            return false;
        }

        return $lastIngest->greaterThanOrEqualTo(CarbonImmutable::now()->subHours(self::STALE_INGEST_HOURS));
    }

    /** Kolik z posledních obchodních dní má aspoň jeden bar. */
    private function tradingDaysCoveredLast30(): int
    {
        $since = CarbonImmutable::now()->subDays(self::COVERAGE_WINDOW_DAYS)->toDateString();

        $covered = DB::scalar(
            'SELECT count(*) FROM market_days AS m '
            . "WHERE m.exchange = 'XNYS' AND m.is_open = true AND m.date >= ? "
            . 'AND EXISTS (SELECT 1 FROM daily_bars AS b WHERE b.date = m.date)',
            [$since],
        );

        if (is_numeric($covered) === false) {
            return 0;
        }

        return (int) $covered;
    }

    private function openErrorFindings(): int
    {
        return ValidationFinding::query()
            ->where('severity', FindingSeverityEnum::ERROR)
            ->count();
    }

    /**
     * Chybějící partition je tichá porucha: import spadne až ve chvíli, kdy do
     * daného roku přijde první bar.
     *
     * @return array<int,int>
     */
    private function missingPartitionYears(): array
    {
        $years = [CarbonImmutable::now()->year, CarbonImmutable::now()->year + 1];
        $missing = [];

        foreach ($years as $year) {
            $exists = DB::selectOne(
                'SELECT 1 AS found FROM pg_class WHERE relname = ?',
                [sprintf('daily_bars_%d', $year)],
            );

            if ($exists === null) {
                $missing[] = $year;
            }
        }

        return $missing;
    }

    /** Jen pro čitelnost testů a reportu — den, ke kterému se stáří posuzuje. */
    public function staleIngestThreshold(): CarbonImmutable
    {
        return CarbonImmutable::now()->subHours(self::STALE_INGEST_HOURS);
    }
}
