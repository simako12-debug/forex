<?php

declare(strict_types=1);

namespace App\MarketData\Ingest;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class BarMerger
{
    /** @param array<int,string> $excludedInstrumentIds */
    public function merge(string $stagingTable, array $excludedInstrumentIds, string $source): MergeResult
    {
        $exclusion = empty($excludedInstrumentIds) === true
            ? ''
            : sprintf(
                'AND s.instrument_id NOT IN (%s)',
                implode(',', array_map(
                    fn (string $id): string => $this->quote($id),
                    $excludedInstrumentIds,
                )),
            );

        $before = $this->count('SELECT count(*) FROM daily_bars');

        DB::statement(sprintf(
            'INSERT INTO daily_bars '
            . '(instrument_id, date, open, high, low, close, volume, source, ingested_at) '
            . 'SELECT s.instrument_id, s.date, s.open, s.high, s.low, s.close, s.volume, %s, now() '
            . 'FROM %s AS s WHERE s.instrument_id IS NOT NULL %s '
            . 'ON CONFLICT (instrument_id, date) DO UPDATE SET '
            . 'open = EXCLUDED.open, high = EXCLUDED.high, low = EXCLUDED.low, '
            . 'close = EXCLUDED.close, volume = EXCLUDED.volume, '
            . 'source = EXCLUDED.source, ingested_at = EXCLUDED.ingested_at',
            $this->quote($source),
            $stagingTable,
            $exclusion,
        ));

        $after = $this->count('SELECT count(*) FROM daily_bars');
        $affected = $this->count(sprintf(
            'SELECT count(*) FROM %s WHERE instrument_id IS NOT NULL %s',
            $stagingTable,
            str_replace('s.instrument_id', 'instrument_id', $exclusion),
        ));

        return new MergeResult(inserted: $after - $before, updated: $affected - ($after - $before));
    }

    /** DB::scalar() vrací mixed, takže přímý přetyp na int na levelu max neprojde. */
    private function count(string $sql): int
    {
        $value = DB::scalar($sql);

        if (is_numeric($value) === false) {
            throw new RuntimeException(sprintf('Dotaz "%s" nevrátil číslo.', $sql));
        }

        return (int) $value;
    }

    /** PDO::quote() vrací string|false, takže na levelu max potřebuje ošetření. */
    private function quote(string $value): string
    {
        $quoted = DB::getPdo()->quote($value);

        if (is_string($quoted) === false) {
            throw new RuntimeException(sprintf('Hodnotu "%s" nelze uvozovkovat pro SQL.', $value));
        }

        return $quoted;
    }
}
