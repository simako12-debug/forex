<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Enums\FindingSeverityEnum;

class PriceJumpWithoutCorporateActionRule extends AbstractStagingRule
{
    public function __construct(private readonly float $thresholdPct = 0.4)
    {
    }

    public function name(): string
    {
        return 'PriceJumpWithoutCorporateAction';
    }

    public function severity(): FindingSeverityEnum
    {
        return FindingSeverityEnum::WARNING;
    }

    /**
     * Práh je na logaritmickém výnosu, ne na procentu — logaritmus je symetrický,
     * takže pokles na čtvrtinu a nárůst na čtyřnásobek dají stejnou absolutní
     * hodnotu. S procentem by pravidlo hlásilo poklesy ochotněji než nárůsty.
     */
    protected function query(string $stagingTable): string
    {
        return sprintf(
            'SELECT j.instrument_id, j.date, j.close, j.prev_close FROM ('
            . '  SELECT instrument_id, date, close,'
            . '    lag(close) OVER (PARTITION BY instrument_id ORDER BY date) AS prev_close'
            . '  FROM %s'
            . ') AS j '
            . 'LEFT JOIN corporate_actions AS ca ON ca.instrument_id = j.instrument_id AND ca.ex_date = j.date '
            . 'WHERE j.prev_close IS NOT NULL AND ca.id IS NULL '
            . '  AND abs(ln(j.close / j.prev_close)) > %F '
            . 'ORDER BY j.instrument_id, j.date LIMIT %d',
            $stagingTable,
            $this->thresholdPct,
            self::FINDING_CAP + 1,
        );
    }

    protected function detail(object $row): string
    {
        /** @var object{date:string,close:string,prev_close:string} $row */
        return sprintf(
            'Skok ceny k %s bez corporate action: %s → %s',
            $row->date,
            $row->prev_close,
            $row->close,
        );
    }
}
