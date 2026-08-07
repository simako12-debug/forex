<?php

declare(strict_types=1);

namespace App\MarketData\Export;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Parquet nezapisuje PHP — v PHP neexistuje zralý zapisovač. Export je DuckDB skript,
 * který přes postgres extension čte view daily_bars_adjusted. Adjustment vzorec žije
 * ve view, takže ho Python neimplementuje.
 */
class ParquetExporter
{
    private const int TIMEOUT_SECONDS = 3600;

    public function __construct(
        private readonly string $sharedPath,
        private readonly string $scriptPath,
        private readonly string $pythonBinary,
        private readonly string $dsn,
    ) {
    }

    public function exportYear(int $year): string
    {
        $outPath = $this->pathForYear($year);

        $process = new Process([
            $this->pythonBinary,
            $this->scriptPath,
            '--year',
            (string) $year,
            '--out',
            $outPath,
            '--dsn',
            $this->dsn,
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if ($process->isSuccessful() === false) {
            throw new RuntimeException(sprintf(
                'Export Parquetu za rok %d selhal (exit %s): %s',
                $year,
                (string) $process->getExitCode(),
                $process->getErrorOutput(),
            ));
        }

        return $outPath;
    }

    public function pathForYear(int $year): string
    {
        return sprintf('%s/daily/year=%d/part.parquet', rtrim($this->sharedPath, '/'), $year);
    }
}
