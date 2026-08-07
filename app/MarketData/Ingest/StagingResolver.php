<?php

declare(strict_types=1);

namespace App\MarketData\Ingest;

use App\MarketData\Enums\FindingSeverityEnum;
use App\MarketData\Models\ValidationFinding;
use Illuminate\Support\Facades\DB;

class StagingResolver
{
    private const string RULE = 'UnknownSymbol';

    /**
     * Jedno množinové UPDATE ... FROM místo sto milionů resolvů po jednom v PHP.
     * Neznámý symbol se nikdy nehádá — řádek prostě zůstane s NULL v instrument_id
     * a odejde do karantény.
     */
    public function resolve(string $table): int
    {
        return DB::update(sprintf(
            'UPDATE %s AS s SET instrument_id = m.instrument_id '
            . 'FROM instrument_symbols AS m '
            . 'WHERE m.symbol = s.symbol '
            . 'AND m.valid_from <= s.date '
            . 'AND (m.valid_to IS NULL OR m.valid_to >= s.date)',
            $table,
        ));
    }

    /**
     * Nálezy se agregují per symbol, ne per řádek — u rozbitého dumpu by
     * per-řádkové nálezy vyrobily miliony záznamů.
     */
    public function quarantine(string $table, string $runId): int
    {
        /** @var array<int,object{symbol:string,row_count:int,first_date:string,last_date:string}> $groups */
        $groups = DB::select(sprintf(
            'SELECT symbol, count(*) AS row_count, min(date) AS first_date, max(date) AS last_date '
            . 'FROM %s WHERE instrument_id IS NULL GROUP BY symbol ORDER BY symbol',
            $table,
        ));

        foreach ($groups as $group) {
            ValidationFinding::query()->create([
                'ingest_run_id' => $runId,
                'instrument_id' => null,
                'date' => null,
                'rule' => self::RULE,
                'severity' => FindingSeverityEnum::ERROR,
                'detail' => sprintf(
                    'Symbol %s nenapárován: %d řádků, %s..%s',
                    $group->symbol,
                    $group->row_count,
                    $group->first_date,
                    $group->last_date,
                ),
            ]);
        }

        return DB::delete(sprintf('DELETE FROM %s WHERE instrument_id IS NULL', $table));
    }
}
