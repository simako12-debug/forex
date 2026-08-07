<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Export\ParquetExporter;
use App\MarketData\Models\DailyBar;
use Carbon\CarbonImmutable;
use Database\Seeders\CanonicalFixtureSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Nástroj pro člověka, ne test v CI. Když je měření horší než baseline, vypíše to
 * jako varování s čísly, ale nevrací chybu — regresi má posoudit člověk, protože
 * na jednom stroji můžou být zdroje obsazené jinou prací.
 */
final class DataBenchmarkCommand extends Command
{
    private const string DEFAULT_BASELINE = 'benchmarks/baseline.json';
    private const float REGRESSION_TOLERANCE = 0.2;

    /** @var string */
    protected $signature = 'market-data:benchmark {--baseline=}';

    /** @var string */
    protected $description = 'Změří průchod seedování a exportu a porovná s baseline';

    public function handle(CanonicalFixtureSeeder $seeder, ParquetExporter $exporter): int
    {
        $measurement = [
            'seed_rows_per_second' => $this->measureSeeding($seeder),
            'export_seconds' => $this->measureExport($exporter),
        ];

        $this->table(
            ['Metrika', 'Hodnota'],
            array_map(
                fn (string $key, float $value): array => [$key, sprintf('%.2f', $value)],
                array_keys($measurement),
                array_values($measurement),
            ),
        );

        $this->compareWithBaseline($measurement);

        return self::SUCCESS;
    }

    private function measureSeeding(CanonicalFixtureSeeder $seeder): float
    {
        $start = microtime(true);
        $seeder->run();
        $elapsed = microtime(true) - $start;

        $rows = DailyBar::query()->count();

        if ($elapsed <= 0.0) {
            return 0.0;
        }

        return $rows / $elapsed;
    }

    private function measureExport(ParquetExporter $exporter): float
    {
        $year = (int) CarbonImmutable::parse(CanonicalFixtureSeeder::PERIOD_START)->year;
        $start = microtime(true);

        try {
            $exporter->exportYear($year);
        } catch (Throwable $exception) {
            $this->warn(sprintf('Export se nezdařil, měření vynecháno: %s', $exception->getMessage()));

            return 0.0;
        }

        return microtime(true) - $start;
    }

    /** @param array<string,float> $measurement */
    private function compareWithBaseline(array $measurement): void
    {
        $path = $this->baselinePath();

        if (File::exists($path) === false) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($measurement, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->info(sprintf('Baseline zapsána do %s.', $path));

            return;
        }

        /** @var array<string,float> $baseline */
        $baseline = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        $this->reportRegression(
            'seed_rows_per_second',
            $baseline['seed_rows_per_second'] ?? 0.0,
            $measurement['seed_rows_per_second'],
            higherIsBetter: true,
        );
        $this->reportRegression(
            'export_seconds',
            $baseline['export_seconds'] ?? 0.0,
            $measurement['export_seconds'],
            higherIsBetter: false,
        );
    }

    private function reportRegression(string $metric, float $baseline, float $current, bool $higherIsBetter): void
    {
        if ($baseline <= 0.0) {
            return;
        }

        $ratio = $higherIsBetter === true ? $current / $baseline : $baseline / $current;

        if ($ratio >= 1.0 - self::REGRESSION_TOLERANCE) {
            return;
        }

        $this->warn(sprintf(
            'Metrika %s je horší než baseline: %.2f vs. %.2f (%.0f %% baseline).',
            $metric,
            $current,
            $baseline,
            $ratio * 100,
        ));
    }

    private function baselinePath(): string
    {
        $option = $this->option('baseline');

        if (is_string($option) === true && $option !== '') {
            return $option;
        }

        return storage_path(self::DEFAULT_BASELINE);
    }
}
