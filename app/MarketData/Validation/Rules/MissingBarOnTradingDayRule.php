<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Enums\FindingSeverityEnum;

class MissingBarOnTradingDayRule extends AbstractStagingRule
{
    public function name(): string
    {
        return 'MissingBarOnTradingDay';
    }

    public function severity(): FindingSeverityEnum
    {
        return FindingSeverityEnum::WARNING;
    }

    /**
     * Mezery se hledají jen v rozsahu, který soubor pokrývá (BETWEEN first_date
     * AND last_date per instrument). Bez toho by inkrementální import jednoho dne
     * hlásil chybějící bary za dvacet let historie.
     */
    protected function query(string $stagingTable): string
    {
        return sprintf(
            'SELECT s.instrument_id, m.date FROM ('
            . '  SELECT instrument_id, min(date) AS first_date, max(date) AS last_date FROM %s'
            . '  GROUP BY instrument_id'
            . ') AS s '
            . 'JOIN instruments AS i ON i.id = s.instrument_id '
            . "JOIN market_days AS m ON m.exchange = 'XNYS' AND m.is_open = true "
            . '  AND m.date BETWEEN s.first_date AND s.last_date '
            . '  AND (i.delisted_at IS NULL OR m.date <= i.delisted_at) '
            . '  AND (i.listed_at IS NULL OR m.date >= i.listed_at) '
            . 'LEFT JOIN %s AS b ON b.instrument_id = s.instrument_id AND b.date = m.date '
            . 'WHERE b.date IS NULL '
            . 'ORDER BY s.instrument_id, m.date LIMIT %d',
            $stagingTable,
            $stagingTable,
            self::FINDING_CAP + 1,
        );
    }

    protected function detail(object $row): string
    {
        /** @var object{date:string} $row */
        return sprintf('Chybí bar k obchodnímu dni %s', $row->date);
    }
}
