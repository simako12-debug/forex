<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Enums\IngestModeEnum;
use App\MarketData\Ingest\Bulk\BulkFileRegistry;
use App\MarketData\Ingest\Bulk\GenericOhlcvCsvSource;
use App\MarketData\Ingest\IngestPipeline;
use Illuminate\Console\Command;

final class ImportBulkBarsCommand extends Command
{
    /** @var string */
    protected $signature = 'market-data:import-bulk {path} {--force}';

    /** @var string */
    protected $description = 'Naimportuje denní bary z CSV dumpu';

    public function handle(IngestPipeline $pipeline, BulkFileRegistry $registry): int
    {
        $path = $this->pathArgument();

        if (is_readable($path) === false) {
            $this->error(sprintf('Soubor %s nelze přečíst.', $path));

            return self::FAILURE;
        }

        // --force vynechá hash, takže se soubor naimportuje znovu i když už prošel.
        $hash = $this->option('force') === true ? null : $registry->hash($path);
        $run = $pipeline->run(new GenericOhlcvCsvSource($path), IngestModeEnum::BULK, $hash);

        $this->info(sprintf(
            'Běh %s: vloženo %d, aktualizováno %d, nálezů %d.',
            $run->id,
            $run->rows_inserted,
            $run->rows_updated,
            $run->findings()->count(),
        ));

        return self::SUCCESS;
    }

    /** Command::argument() vrací array|string|null, takže přetyp potřebuje ošetření. */
    private function pathArgument(): string
    {
        $path = $this->argument('path');

        if (is_string($path) === false) {
            return '';
        }

        return $path;
    }
}
