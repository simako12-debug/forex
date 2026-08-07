<?php

declare(strict_types=1);

namespace App\MarketData\Ingest\Incremental;

use App\MarketData\Contracts\BarSourcePort;
use App\MarketData\Data\BarData;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class AlpacaBarSource implements BarSourcePort
{
    private const int PAGE_LIMIT = 10000;

    /** @param array<int,string> $symbols */
    public function __construct(
        private readonly array $symbols,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly string $baseUrl,
        private readonly string $keyId,
        private readonly string $secretKey,
        private readonly string $feed,
    ) {
    }

    public function name(): string
    {
        return 'alpaca:bars';
    }

    /**
     * adjustment=raw je zásadní: sklad ukládá neupravené ceny a adjustment se počítá
     * z corporate actions. Kdyby zdroj vracel upravené hodnoty, aplikoval by se dvakrát.
     *
     * @return Generator<int,BarData>
     */
    public function dailyBars(): Generator
    {
        $token = null;

        do {
            $response = Http::withHeaders([
                'APCA-API-KEY-ID' => $this->keyId,
                'APCA-API-SECRET-KEY' => $this->secretKey,
            ])->get($this->baseUrl . '/v2/stocks/bars', array_filter([
                'symbols' => implode(',', $this->symbols),
                'timeframe' => '1Day',
                'start' => $this->from->toDateString(),
                'end' => $this->to->toDateString(),
                'adjustment' => 'raw',
                'feed' => $this->feed,
                'limit' => self::PAGE_LIMIT,
                'page_token' => $token,
            ], fn (mixed $value): bool => $value !== null))->throw();

            /**
             * @var array{
             *     bars:array<string,array<int,array{
             *         t:string,o:float|int,h:float|int,l:float|int,c:float|int,v:int
             *     }>>,
             *     next_page_token:null|string
             * } $payload
             */
            $payload = $response->json();

            foreach ($payload['bars'] as $symbol => $rows) {
                foreach ($rows as $row) {
                    yield BarData::from([
                        'symbol' => $symbol,
                        'date' => CarbonImmutable::parse($row['t'])->toDateString(),
                        'open' => (float) $row['o'],
                        'high' => (float) $row['h'],
                        'low' => (float) $row['l'],
                        'close' => (float) $row['c'],
                        'volume' => $row['v'],
                        'ts' => null,
                    ]);
                }
            }

            $token = $payload['next_page_token'];
        } while ($token !== null);
    }
}
