<?php

declare(strict_types=1);

namespace App\MarketData\Universe;

use App\MarketData\Models\UniverseDefinition;
use App\MarketData\Models\UniverseMember;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pravidlo členství k datu D: instrument je členem, pokud
 *   - listed_at <= D a (delisted_at IS NULL nebo delisted_at >= D),
 *   - close(D) >= minPrice,
 *   - průměr close × volume za posledních dollarVolumeLookbackDays obchodních dní
 *     končících D je >= minAvgDollarVolume.
 *
 * Všechny tři podmínky se vyhodnocují jen z dat s datem <= D.
 */
class UniverseMemberResolver
{
    public function rebuild(UniverseDefinition $definition, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $rules = $definition->rules;

        return DB::affectingStatement(
            'INSERT INTO universe_members (definition_id, date, instrument_id) '
            . 'SELECT ?, c.date, c.instrument_id FROM ('
            . '  SELECT b.instrument_id, b.date, b.close,'
            . '    avg(b.close * b.volume) OVER ('
            . '      PARTITION BY b.instrument_id ORDER BY b.date'
            // ROWS BETWEEN n PRECEDING AND CURRENT ROW — okno se nikdy nedívá dopředu.
            // FOLLOWING by byla přesně ta chyba, kterou test na zkrácená data hledá.
            . '      ROWS BETWEEN ? PRECEDING AND CURRENT ROW'
            . '    ) AS avg_dollar_volume'
            . '  FROM daily_bars AS b'
            . '  JOIN instruments AS i ON i.id = b.instrument_id'
            // b.date <= ? je záměrně v podzapytí, ne vně. Kdyby bylo vně, klouzavý
            // průměr by se počítal i z barů po $to a promítl by budoucnost do minulosti.
            . '  WHERE b.date <= ?'
            . '    AND (i.listed_at IS NULL OR i.listed_at <= b.date)'
            . '    AND (i.delisted_at IS NULL OR i.delisted_at >= b.date)'
            . ') AS c '
            . 'WHERE c.date >= ? AND c.close >= ? AND c.avg_dollar_volume >= ? '
            // Append-only: opakovaný přepočet pro tutéž verzi definice nic nepřepíše.
            . 'ON CONFLICT (definition_id, date, instrument_id) DO NOTHING',
            [
                $definition->id,
                $rules->dollarVolumeLookbackDays - 1,
                $to->toDateString(),
                $from->toDateString(),
                $rules->minPrice,
                $rules->minAvgDollarVolume,
            ],
        );
    }

    /** @return Collection<int,string> */
    public function membersAt(UniverseDefinition $definition, CarbonImmutable $date): Collection
    {
        /** @var Collection<int,string> $members */
        $members = UniverseMember::query()
            ->where('definition_id', $definition->id)
            ->where('date', $date->toDateString())
            ->orderBy('instrument_id')
            ->pluck('instrument_id');

        return $members;
    }
}
