<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Export\SnapshotExporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ExportSnapshotCommand extends Command
{
    private const int DEFAULT_FROM_YEAR = 2000;

    /** @var string */
    protected $signature = 'market-data:export-snapshot {--from-year=} {--to-year=}';

    /** @var string */
    protected $description = 'Vyexportuje kompletní snapshot pro Python: bary, metadata a manifest';

    public function handle(SnapshotExporter $exporter): int
    {
        $fromYear = $this->yearOption('from-year', self::DEFAULT_FROM_YEAR);
        $toYear = $this->yearOption('to-year', CarbonImmutable::now()->year);

        $manifest = $exporter->export(range($fromYear, $toYear));

        $this->info(sprintf(
            'Snapshot zapsán: roky %d–%d, %d barů, verze adjustmentu %d.',
            $fromYear,
            $toYear,
            $manifest->rowCounts['daily_bars'] ?? 0,
            $manifest->adjustmentLogicVersion,
        ));

        return self::SUCCESS;
    }

    private function yearOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (is_numeric($value) === false) {
            return $default;
        }

        return (int) $value;
    }
}
