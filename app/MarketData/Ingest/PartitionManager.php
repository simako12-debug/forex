<?php

declare(strict_types=1);

namespace App\MarketData\Ingest;

use Illuminate\Support\Facades\DB;

/**
 * CREATE TABLE IF NOT EXISTS dělá obě metody idempotentní — pouštět se budou
 * ze scheduleru na začátku roku.
 */
class PartitionManager
{
    public function ensureDailyYear(int $year): void
    {
        DB::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS daily_bars_%d PARTITION OF daily_bars '
            . "FOR VALUES FROM ('%d-01-01') TO ('%d-01-01')",
            $year,
            $year,
            $year + 1,
        ));
    }

    /**
     * Nad rámec plánu. daily_bars je partitionovaná podle date, takže insert do roku
     * bez partition selže. Bulk dump může pokrývat libovolné roky, a spoléhat na to,
     * že operátor pustil ensure-partitions se správným rozsahem, by import dělalo
     * závislým na kroku, který nikdo nevynucuje.
     */
    public function ensureDailyYearsInStaging(string $stagingTable): void
    {
        /** @var array<int,object{year:int}> $years */
        $years = DB::select(sprintf(
            'SELECT DISTINCT extract(year FROM date)::int AS year FROM %s ORDER BY year',
            $stagingTable,
        ));

        foreach ($years as $year) {
            $this->ensureDailyYear($year->year);
        }
    }

    public function ensureIntradayMonth(int $year, int $month): void
    {
        $start = sprintf('%d-%02d-01', $year, $month);
        $end = $month === 12
            ? sprintf('%d-01-01', $year + 1)
            : sprintf('%d-%02d-01', $year, $month + 1);

        DB::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS intraday_bars_%d_%02d PARTITION OF intraday_bars '
            . "FOR VALUES FROM ('%s') TO ('%s')",
            $year,
            $month,
            $start,
            $end,
        ));
    }
}
