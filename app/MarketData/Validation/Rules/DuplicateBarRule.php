<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Enums\FindingSeverityEnum;

class DuplicateBarRule extends AbstractStagingRule
{
    public function name(): string
    {
        return 'DuplicateBar';
    }

    public function severity(): FindingSeverityEnum
    {
        return FindingSeverityEnum::ERROR;
    }

    protected function query(string $stagingTable): string
    {
        return sprintf(
            'SELECT instrument_id, date, count(*) AS occurrences FROM %s '
            . 'GROUP BY instrument_id, date HAVING count(*) > 1 '
            . 'ORDER BY instrument_id, date LIMIT %d',
            $stagingTable,
            self::FINDING_CAP + 1,
        );
    }

    protected function detail(object $row): string
    {
        /** @var object{date:string,occurrences:int} $row */
        return sprintf('Duplicitní bar k %s: %d výskytů', $row->date, $row->occurrences);
    }
}
