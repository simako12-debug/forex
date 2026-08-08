<?php

declare(strict_types=1);

namespace App\MarketData\Export;

use App\MarketData\Adjustment\AdjustmentFactorCalculator;
use App\MarketData\Models\DailyBar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * Snapshot je jedna složka, ze které Python přečte všechno potřebné:
 *
 *   {shared}/manifest.json
 *   {shared}/daily/year=YYYY/part.parquet
 *   {shared}/meta/{instruments,universe_members,market_days}.parquet
 *
 * Manifest se zapisuje jako poslední. Kdyby se export v půlce rozbil, chybí
 * manifest a Python snapshot odmítne — místo aby počítal nad polovinou dat.
 */
class SnapshotExporter
{
    public function __construct(
        private readonly ParquetExporter $bars,
        private readonly MetadataExporter $metadata,
        private readonly string $sharedPath,
    ) {
    }

    /** @param array<int,int> $years */
    public function export(array $years): SnapshotManifest
    {
        foreach ($years as $year) {
            $this->bars->exportYear($year);
        }

        $rowCounts = $this->metadata->export();
        $rowCounts['daily_bars'] = DailyBar::query()->count();

        $manifest = new SnapshotManifest(
            adjustmentLogicVersion: AdjustmentFactorCalculator::LOGIC_VERSION,
            exportedAt: CarbonImmutable::now()->toIso8601String(),
            years: array_values($years),
            rowCounts: $rowCounts,
        );

        File::ensureDirectoryExists(dirname($this->manifestPath()));
        File::put(
            $this->manifestPath(),
            json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        return $manifest;
    }

    public function manifestPath(): string
    {
        return rtrim($this->sharedPath, '/') . '/manifest.json';
    }
}
