<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Enums\FindingSeverityEnum;

class OhlcConsistencyRule extends AbstractStagingRule
{
    public function name(): string
    {
        return 'OhlcConsistency';
    }

    public function severity(): FindingSeverityEnum
    {
        return FindingSeverityEnum::ERROR;
    }

    protected function query(string $stagingTable): string
    {
        return sprintf(
            'SELECT instrument_id, date, open, high, low, close FROM %s '
            . 'WHERE low > least(open, close) OR high < greatest(open, close) '
            . 'OR low > high OR open <= 0 OR high <= 0 OR low <= 0 OR close <= 0 '
            . 'ORDER BY instrument_id, date LIMIT %d',
            $stagingTable,
            self::FINDING_CAP + 1,
        );
    }

    protected function detail(object $row): string
    {
        /** @var object{date:string,open:string,high:string,low:string,close:string} $row */
        return sprintf(
            'Nekonzistentní OHLC k %s: o=%s h=%s l=%s c=%s',
            $row->date,
            $row->open,
            $row->high,
            $row->low,
            $row->close,
        );
    }
}
