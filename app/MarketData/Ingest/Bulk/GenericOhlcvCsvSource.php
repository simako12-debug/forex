<?php

declare(strict_types=1);

namespace App\MarketData\Ingest\Bulk;

use App\MarketData\Contracts\BarSourcePort;
use App\MarketData\Data\BarData;
use Generator;

/**
 * Čte jeden CSV stream, volitelně gzipovaný. Vendor dumpy přicházejí jako ZIP
 * a rozbalují se jednou, ručně, před importem — streamované čtení ZIPu by přidalo
 * netriviální kód pro jednorázovou operátorskou činnost.
 */
class GenericOhlcvCsvSource implements BarSourcePort
{
    /** @var array<int,string> */
    private const array HEADER = ['symbol', 'date', 'open', 'high', 'low', 'close', 'volume'];

    public function __construct(private readonly string $path)
    {
    }

    public function name(): string
    {
        return 'bulk:' . basename($this->path);
    }

    /**
     * Generator kvůli tomu, že dump má miliony řádků — fgetcsv ve smyčce drží
     * v paměti jeden řádek, ne soubor.
     *
     * @return Generator<int,BarData>
     */
    public function dailyBars(): Generator
    {
        $handle = str_ends_with($this->path, '.gz')
            ? gzopen($this->path, 'rb')
            : fopen($this->path, 'rb');

        if ($handle === false) {
            throw InvalidCsvHeaderException::forHeader($this->path, self::HEADER);
        }

        $header = fgetcsv($handle);

        if ($header !== self::HEADER) {
            fclose($handle);

            throw InvalidCsvHeaderException::forHeader($this->path, self::HEADER);
        }

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) {
                continue;
            }

            yield BarData::from([
                'symbol' => (string) $row[0],
                'date' => (string) $row[1],
                'open' => (float) $row[2],
                'high' => (float) $row[3],
                'low' => (float) $row[4],
                'close' => (float) $row[5],
                'volume' => (int) $row[6],
                'ts' => null,
            ]);
        }

        fclose($handle);
    }
}
