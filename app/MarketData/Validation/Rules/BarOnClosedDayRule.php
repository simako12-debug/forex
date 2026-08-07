<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Enums\FindingSeverityEnum;

class BarOnClosedDayRule extends AbstractStagingRule
{
    public function name(): string
    {
        return 'BarOnClosedDay';
    }

    public function severity(): FindingSeverityEnum
    {
        return FindingSeverityEnum::ERROR;
    }

    protected function query(string $stagingTable): string
    {
        return sprintf(
            'SELECT s.instrument_id, s.date, m.is_open FROM %s AS s '
            . "LEFT JOIN market_days AS m ON m.date = s.date AND m.exchange = 'XNYS' "
            . 'WHERE m.date IS NULL OR m.is_open = false '
            . 'ORDER BY s.instrument_id, s.date LIMIT %d',
            $stagingTable,
            self::FINDING_CAP + 1,
        );
    }

    protected function detail(object $row): string
    {
        /** @var object{date:string,is_open:null|bool} $row */
        if ($row->is_open === null) {
            return sprintf('Bar k %s, ale den není v kalendáři', $row->date);
        }

        return sprintf('Bar k %s, ale burza byla zavřená', $row->date);
    }
}
