<?php

declare(strict_types=1);

namespace App\MarketData\Ingest;

use App\MarketData\Contracts\BarSourcePort;
use App\MarketData\Enums\IngestModeEnum;
use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Ingest\Bulk\BulkFileRegistry;
use App\MarketData\Models\IngestRun;
use App\MarketData\Validation\ValidationRunner;
use Carbon\CarbonImmutable;
use Throwable;

class IngestPipeline
{
    public function __construct(
        private readonly StagingTable $staging,
        private readonly StagingResolver $resolver,
        private readonly ValidationRunner $validation,
        private readonly BarMerger $merger,
        private readonly BulkFileRegistry $registry,
        private readonly PartitionManager $partitions,
    ) {
    }

    public function run(BarSourcePort $source, IngestModeEnum $mode, null|string $fileHash): IngestRun
    {
        if ($fileHash !== null && $this->registry->alreadyImported($fileHash) === true) {
            return $this->skippedRun($source, $mode, $fileHash);
        }

        $run = IngestRun::query()->create([
            'source' => $source->name(),
            'mode' => $mode,
            'file_hash' => $fileHash,
            'started_at' => CarbonImmutable::now(),
            'status' => IngestStatusEnum::RUNNING,
        ]);

        $table = $this->staging->create($run->id);

        try {
            $this->staging->write($table, $source->dailyBars());
            $this->resolver->resolve($table);
            $this->resolver->quarantine($table, $run->id);
            $this->partitions->ensureDailyYearsInStaging($table);

            $outcome = $this->validation->run($table, $run->id);
            $merged = $this->merger->merge($table, $outcome->quarantinedInstrumentIds, $source->name());

            $run->update([
                'rows_inserted' => $merged->inserted,
                'rows_updated' => $merged->updated,
                'status' => IngestStatusEnum::COMPLETED,
                'finished_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => IngestStatusEnum::FAILED,
                'error' => $exception->getMessage(),
                'finished_at' => CarbonImmutable::now(),
            ]);

            throw $exception;
        } finally {
            // Staging tabulky jsou reálné tabulky v databázi — spadlý import by je
            // jinak nechal ležet a po pár týdnech by jich bylo sto.
            $this->staging->drop($table);
        }

        return $run->fresh() ?? $run;
    }

    private function skippedRun(BarSourcePort $source, IngestModeEnum $mode, string $fileHash): IngestRun
    {
        return IngestRun::query()->create([
            'source' => $source->name(),
            'mode' => $mode,
            'file_hash' => $fileHash,
            'started_at' => CarbonImmutable::now(),
            'finished_at' => CarbonImmutable::now(),
            'rows_inserted' => 0,
            'rows_updated' => 0,
            'status' => IngestStatusEnum::COMPLETED,
            'error' => null,
        ]);
    }
}
