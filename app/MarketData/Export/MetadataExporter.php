<?php

declare(strict_types=1);

namespace App\MarketData\Export;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

class MetadataExporter
{
    private const int TIMEOUT_SECONDS = 600;

    public function __construct(
        private readonly string $sharedPath,
        private readonly string $scriptPath,
        private readonly string $pythonBinary,
        private readonly string $dsn,
    ) {
    }

    /** @return array<string,int> */
    public function export(): array
    {
        $process = new Process([
            $this->pythonBinary,
            $this->scriptPath,
            '--out',
            $this->metaDirectory(),
            '--dsn',
            $this->dsn,
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if ($process->isSuccessful() === false) {
            throw new RuntimeException(sprintf(
                'Export metadat selhal (exit %s): %s',
                (string) $process->getExitCode(),
                $process->getErrorOutput(),
            ));
        }

        return $this->decodeCounts($process->getOutput());
    }

    public function pathFor(string $table): string
    {
        return sprintf('%s/%s.parquet', $this->metaDirectory(), $table);
    }

    private function metaDirectory(): string
    {
        return rtrim($this->sharedPath, '/') . '/meta';
    }

    /** @return array<string,int> */
    private function decodeCounts(string $output): array
    {
        try {
            /** @var array<string,int> $counts */
            $counts = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Export metadat nevrátil JSON: %s', $output),
                previous: $exception,
            );
        }

        return $counts;
    }
}
