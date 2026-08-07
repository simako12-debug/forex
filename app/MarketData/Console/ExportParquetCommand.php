<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Export\ParquetExporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ExportParquetCommand extends Command
{
    /** @var string */
    protected $signature = 'market-data:export-parquet {--year=}';

    /** @var string */
    protected $description = 'Vyexportuje denní bary jednoho roku do Parquetu';

    public function handle(ParquetExporter $exporter): int
    {
        $year = $this->yearOption();
        $path = $exporter->exportYear($year);

        $this->info(sprintf('Rok %d vyexportován do %s.', $year, $path));

        return self::SUCCESS;
    }

    private function yearOption(): int
    {
        $value = $this->option('year');

        if (is_numeric($value) === false) {
            return CarbonImmutable::now()->year;
        }

        return (int) $value;
    }
}
