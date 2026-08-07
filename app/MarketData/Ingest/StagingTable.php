<?php

declare(strict_types=1);

namespace App\MarketData\Ingest;

use App\MarketData\Data\BarData;
use Illuminate\Support\Facades\DB;

class StagingTable
{
    /**
     * Kompromis: pgsqlCopyFromArray chce pole v paměti, takže neomezená dávka
     * by paměť sežrala, a příliš malá by ztratila výhodu COPY.
     */
    private const int CHUNK = 20000;

    /**
     * UNLOGGED vypíná WAL — u stomilionového importu je to řádový rozdíl
     * a data ve staging tabulce jsou stejně jednorázová.
     *
     * instrument_id je nullable a při zápisu se neplní. Specifikace předepisuje
     * resolve → stage → validate; plán to mění na stage → resolve v SQL → validate,
     * protože resolvovat sto milionů řádků po jednom v PHP je zbytečné, když to
     * Postgres udělá jedním UPDATE ... FROM (Task 10). Karanténa se tím zjednoduší
     * na „řádky, kde instrument_id zůstal NULL" a je to množinová operace.
     */
    public function create(string $runId): string
    {
        $table = 'staging_bars_' . str_replace('-', '', $runId);

        DB::statement(sprintf(
            'CREATE UNLOGGED TABLE %s ('
            . 'symbol varchar(16) NOT NULL, date date NOT NULL, '
            . 'open numeric(14,6) NOT NULL, high numeric(14,6) NOT NULL, '
            . 'low numeric(14,6) NOT NULL, close numeric(14,6) NOT NULL, '
            . 'volume bigint NOT NULL, instrument_id uuid NULL)',
            $table,
        ));

        return $table;
    }

    /** @param iterable<int,BarData> $bars */
    public function write(string $table, iterable $bars): int
    {
        $pdo = DB::connection()->getPdo();
        $total = 0;
        $chunk = [];

        foreach ($bars as $bar) {
            $chunk[] = implode("\t", [
                $bar->symbol,
                $bar->date->toDateString(),
                $bar->open,
                $bar->high,
                $bar->low,
                $bar->close,
                $bar->volume,
            ]);

            if (count($chunk) < self::CHUNK) {
                continue;
            }

            $this->copy($pdo, $table, $chunk);
            $total += count($chunk);
            $chunk = [];
        }

        if (empty($chunk) === false) {
            $this->copy($pdo, $table, $chunk);
            $total += count($chunk);
        }

        return $total;
    }

    public function drop(string $table): void
    {
        DB::statement(sprintf('DROP TABLE IF EXISTS %s', $table));
    }

    /**
     * pgsqlCopyFromArray je metoda pgsql driveru, kterou základní PDO nedeklaruje.
     * Staging tabulka má osm sloupců, COPY plní jen prvních sedm — instrument_id
     * dopočítá Task 10.
     *
     * @param array<int,string> $rows
     */
    private function copy(object $pdo, string $table, array $rows): void
    {
        // @phpstan-ignore method.notFound
        $pdo->pgsqlCopyFromArray(
            $table,
            $rows,
            "\t",
            '\\\\N',
            'symbol, date, open, high, low, close, volume',
        );
    }
}
