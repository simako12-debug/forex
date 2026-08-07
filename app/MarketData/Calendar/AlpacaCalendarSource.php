<?php

declare(strict_types=1);

namespace App\MarketData\Calendar;

use App\MarketData\Data\MarketDayData;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

/**
 * Alpaca vrací jen otevřené dny — zavřené se dopočítají jako doplněk kalendářních dní.
 * Endpoint i názvy polí je potřeba držet proti aktuální dokumentaci; testy jedou proti
 * Http::fake, takže na změně formátu spadne test adaptéru, ne produkční ingest.
 */
class AlpacaCalendarSource
{
    private const string EXCHANGE = 'XNYS';
    private const string REGULAR_CLOSE = '16:00';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $keyId,
        private readonly string $secretKey,
    ) {
    }

    /** @return Generator<int,MarketDayData> */
    public function fetch(CarbonImmutable $from, CarbonImmutable $to): Generator
    {
        $response = Http::withHeaders([
            'APCA-API-KEY-ID' => $this->keyId,
            'APCA-API-SECRET-KEY' => $this->secretKey,
        ])->get($this->baseUrl . '/v2/calendar', [
            'start' => $from->toDateString(),
            'end' => $to->toDateString(),
        ])->throw();

        /** @var array<int,array{date:string,open:string,close:string}> $rows */
        $rows = $response->json();

        foreach ($rows as $row) {
            yield MarketDayData::from([
                'exchange' => self::EXCHANGE,
                'date' => $row['date'],
                'isOpen' => true,
                'openAt' => $row['open'],
                'closeAt' => $row['close'],
                'isEarlyClose' => $row['close'] !== self::REGULAR_CLOSE,
            ]);
        }
    }
}
