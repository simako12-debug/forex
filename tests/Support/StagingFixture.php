<?php

declare(strict_types=1);

namespace Tests\Support;

use App\MarketData\Ingest\StagingTable;
use App\MarketData\Models\Instrument;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

final class StagingFixture
{
    /**
     * Vkládá se přes insert, ne přes COPY — jde o desítky řádků a test má být
     * čitelný. Zápis je dávkový, aby test se stropem nálezů nedělal tisíc
     * round tripů.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function withRows(array $rows, string $instrumentId = '550e8400-e29b-41d4-a716-446655440000'): string
    {
        Instrument::factory()->create(['id' => $instrumentId]);
        $table = App::make(StagingTable::class)->create('11111111-2222-3333-4444-555555555555');

        $payload = [];

        foreach ($rows as $row) {
            $payload[] = [
                'symbol' => $row['symbol'],
                'date' => $row['date'],
                'open' => $row['open'],
                'high' => $row['high'],
                'low' => $row['low'],
                'close' => $row['close'],
                'volume' => $row['volume'] ?? 1000,
                'instrument_id' => $row['instrument_id'] ?? $instrumentId,
            ];
        }

        if (empty($payload) === false) {
            DB::table($table)->insert($payload);
        }

        return $table;
    }
}
