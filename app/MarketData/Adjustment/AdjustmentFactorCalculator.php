<?php

declare(strict_types=1);

namespace App\MarketData\Adjustment;

use App\MarketData\Enums\CorporateActionTypeEnum;
use App\MarketData\Models\AdjustmentFactor;
use App\MarketData\Models\CorporateAction;
use Illuminate\Support\Facades\DB;

/**
 * Vzorce:
 *
 *   cum_split_factor(d) = Π ratio_i                            pro splity s ex_date > d
 *   cum_div_factor(d)   = Π (1 − amount_i / close(den před ex_date_i))
 *                                                              pro dividendy s ex_date > d
 *   adj_price(d)  = raw_price(d)  / cum_split_factor(d) × cum_div_factor(d)
 *   adj_volume(d) = raw_volume(d) × cum_split_factor(d)
 *
 * Přepočet je vždy celý pro instrument, nikdy inkrementální — pro jeden instrument
 * jsou to stovky corporate actions, takže je to levné, a inkrementální dopočítávání
 * kumulativních koeficientů je místo, kde vznikají tiché chyby.
 *
 * Ukládají se jen řádky, kde se aspoň jeden faktor liší od 1. Materializovat faktor
 * pro každý (instrument, den) by znamenalo druhou stomilionovou tabulku; čtení
 * používá LEFT JOIN s COALESCE(..., 1).
 */
class AdjustmentFactorCalculator
{
    public function recalculate(string $instrumentId): int
    {
        return DB::transaction(function () use ($instrumentId): int {
            AdjustmentFactor::query()->where('instrument_id', $instrumentId)->delete();

            $actions = $this->actions($instrumentId);

            if ($actions === []) {
                return 0;
            }

            return $this->writeIntervals($instrumentId, $actions);
        });
    }

    /**
     * Vzestupně podle ex_date, protože kumulace jde od nejnovější akce k nejstarší
     * a potřebuje znát i hranici předchozí akce.
     *
     * @return array<int,CorporateAction>
     */
    private function actions(string $instrumentId): array
    {
        return CorporateAction::query()
            ->where('instrument_id', $instrumentId)
            ->whereIn('type', [CorporateActionTypeEnum::SPLIT, CorporateActionTypeEnum::DIVIDEND])
            ->orderBy('ex_date')
            ->get()
            ->all();
    }

    /**
     * Zápis probíhá po intervalech, ne po dnech: mezi dvěma po sobě jdoucími
     * corporate actions je faktor konstantní, takže se INSERT ... SELECT nad
     * daily_bars provede jednou na interval.
     *
     * @param array<int,CorporateAction> $actions
     */
    private function writeIntervals(string $instrumentId, array $actions): int
    {
        $cumSplit = 1.0;
        $cumDiv = 1.0;
        $written = 0;

        for ($index = count($actions) - 1; $index >= 0; $index--) {
            $action = $actions[$index];

            $cumSplit *= $this->splitRatio($action);
            $cumDiv *= $this->dividendFactor($instrumentId, $action);

            $upperBound = $action->ex_date->subDay()->toDateString();
            $lowerBound = $index > 0 ? $actions[$index - 1]->ex_date->toDateString() : null;

            if ($this->isNeutral($cumSplit, $cumDiv) === true) {
                continue;
            }

            $written += $this->insertInterval($instrumentId, $lowerBound, $upperBound, $cumSplit, $cumDiv);
        }

        return $written;
    }

    private function splitRatio(CorporateAction $action): float
    {
        if ($action->type !== CorporateActionTypeEnum::SPLIT || $action->ratio === null) {
            return 1.0;
        }

        return $action->ratio;
    }

    /** Dividenda se vztahuje k závěru dne před ex-date; bez něj ji spočítat nelze. */
    private function dividendFactor(string $instrumentId, CorporateAction $action): float
    {
        if ($action->type !== CorporateActionTypeEnum::DIVIDEND || $action->amount === null) {
            return 1.0;
        }

        $previousClose = DB::scalar(
            'SELECT close FROM daily_bars WHERE instrument_id = ? AND date < ? ORDER BY date DESC LIMIT 1',
            [$instrumentId, $action->ex_date->toDateString()],
        );

        if (is_numeric($previousClose) === false || (float) $previousClose <= 0.0) {
            return 1.0;
        }

        return 1.0 - $action->amount / (float) $previousClose;
    }

    private function isNeutral(float $cumSplit, float $cumDiv): bool
    {
        return abs($cumSplit - 1.0) < PHP_FLOAT_EPSILON && abs($cumDiv - 1.0) < PHP_FLOAT_EPSILON;
    }

    private function insertInterval(
        string $instrumentId,
        null|string $lowerBound,
        string $upperBound,
        float $cumSplit,
        float $cumDiv,
    ): int {
        $bindings = [$cumSplit, $cumDiv, $instrumentId, $upperBound];
        $lowerCondition = '';

        if ($lowerBound !== null) {
            $lowerCondition = 'AND date >= ? ';
            $bindings[] = $lowerBound;
        }

        return DB::affectingStatement(
            'INSERT INTO adjustment_factors (instrument_id, date, cum_split_factor, cum_div_factor) '
            . 'SELECT instrument_id, date, ?, ? FROM daily_bars '
            . 'WHERE instrument_id = ? AND date <= ? ' . $lowerCondition
            . 'ON CONFLICT (instrument_id, date) DO UPDATE SET '
            . 'cum_split_factor = EXCLUDED.cum_split_factor, cum_div_factor = EXCLUDED.cum_div_factor',
            $bindings,
        );
    }
}
