<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Adjustment\AdjustmentFactorCalculator;
use App\MarketData\Models\CorporateAction;
use Illuminate\Console\Command;

final class RecalculateAdjustmentsCommand extends Command
{
    /** @var string */
    protected $signature = 'market-data:recalculate-adjustments {--instrument=}';

    /** @var string */
    protected $description = 'Přepočítá adjustment faktory z corporate actions';

    public function handle(AdjustmentFactorCalculator $calculator): int
    {
        $instruments = $this->targetInstruments();
        $rows = 0;

        foreach ($instruments as $instrumentId) {
            $rows += $calculator->recalculate($instrumentId);
        }

        $this->info(sprintf(
            'Přepočítáno %d instrumentů, zapsáno %d řádků faktorů.',
            count($instruments),
            $rows,
        ));

        return self::SUCCESS;
    }

    /**
     * Bez --instrument se přepočítají všechny instrumenty, které mají aspoň jednu
     * corporate action; ostatní by stejně vyšly na nulu řádků.
     *
     * @return array<int,string>
     */
    private function targetInstruments(): array
    {
        $option = $this->option('instrument');

        if (is_string($option) === true && $option !== '') {
            return [$option];
        }

        /** @var array<int,string> $ids */
        $ids = CorporateAction::query()
            ->select('instrument_id')
            ->distinct()
            ->orderBy('instrument_id')
            ->pluck('instrument_id')
            ->all();

        return $ids;
    }
}
