<?php

declare(strict_types=1);

namespace App\MarketData\Validation\Rules;

use App\MarketData\Enums\FindingSeverityEnum;

class ZeroOrMissingVolumeRule extends AbstractStagingRule
{
    public function name(): string
    {
        return 'ZeroOrMissingVolume';
    }

    public function severity(): FindingSeverityEnum
    {
        return FindingSeverityEnum::WARNING;
    }

    protected function query(string $stagingTable): string
    {
        return sprintf(
            'SELECT s.instrument_id, s.date FROM %s AS s '
            . "JOIN market_days AS m ON m.exchange = 'XNYS' AND m.date = s.date AND m.is_open = true "
            . 'WHERE s.volume = 0 '
            . 'ORDER BY s.instrument_id, s.date LIMIT %d',
            $stagingTable,
            self::FINDING_CAP + 1,
        );
    }

    protected function detail(object $row): string
    {
        /** @var object{date:string} $row */
        return sprintf(
            'Nulový objem k %s v otevřený den — typický příznak IEX-only feedu',
            $row->date,
        );
    }
}
