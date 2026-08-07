<?php

declare(strict_types=1);

namespace App\MarketData\Ingest\Bulk;

use RuntimeException;

final class InvalidCsvHeaderException extends RuntimeException
{
    /** @param array<int,string> $expected */
    public static function forHeader(string $path, array $expected): self
    {
        return new self(sprintf(
            'Soubor %s nemá očekávanou hlavičku: %s',
            $path,
            implode(',', $expected),
        ));
    }
}
