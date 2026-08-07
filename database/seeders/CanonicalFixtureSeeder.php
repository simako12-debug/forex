<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\MarketData\Ingest\PartitionManager;
use App\MarketData\Models\CorporateAction;
use App\MarketData\Models\Instrument;
use App\MarketData\Models\InstrumentSymbol;
use App\MarketData\Models\MarketDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Pět instrumentů × 60 obchodních dní, v nichž je po jedné z každé datové pasti:
 * delisting v polovině období, instrument vzniklý až v průběhu, recyklovaný ticker,
 * split, dividenda, mezera v datech, bar porušující OHLC invariant, svátek
 * a zkrácený obchodní den.
 *
 * Struktura je pevná — žádný faker. Ceny jsou pseudonáhodné s pevným seedem,
 * takže fixture je reprodukovatelný.
 */
final class CanonicalFixtureSeeder extends Seeder
{
    public const string PERIOD_START = '2019-01-02';
    public const string PERIOD_END = '2019-03-27';
    public const string HOLIDAY = '2019-02-18';
    public const string EARLY_CLOSE = '2019-02-15';
    public const string DELISTING_DATE = '2019-02-15';
    public const string LATECOMER_LISTED = '2019-02-01';
    public const string GAP_DATE = '2019-02-20';
    public const string OHLC_VIOLATION_DATE = '2019-02-05';
    public const string RECYCLED_SYMBOL = 'XYZ';
    public const string RECYCLED_HANDOVER = '2019-03-01';

    public const string GAP_INSTRUMENT = '550e8400-e29b-41d4-a716-446655440001';
    private const string DELISTED_INSTRUMENT = '550e8400-e29b-41d4-a716-446655440002';
    private const string LATECOMER_INSTRUMENT = '550e8400-e29b-41d4-a716-446655440003';
    private const string RECYCLED_OLD_OWNER = '550e8400-e29b-41d4-a716-446655440004';
    private const string RECYCLED_NEW_OWNER = '550e8400-e29b-41d4-a716-446655440005';

    private const int PRICE_SEED = 20190102;
    private const string SOURCE = 'canonical-fixture';

    public function run(): void
    {
        $this->clear();

        App::make(PartitionManager::class)->ensureDailyYear((int) CarbonImmutable::parse(self::PERIOD_START)->year);

        $tradingDays = $this->seedCalendar();
        $this->seedInstruments();
        $this->seedCorporateActions();
        $this->seedBars($tradingDays);
    }

    /** Seeder je idempotentní — vlastní data smaže a postaví znovu. */
    private function clear(): void
    {
        DB::table('daily_bars')->delete();
        DB::table('corporate_actions')->delete();
        DB::table('instrument_symbols')->delete();
        DB::table('instruments')->delete();
        DB::table('market_days')->delete();
    }

    /** @return array<int,string> */
    private function seedCalendar(): array
    {
        $day = CarbonImmutable::parse(self::PERIOD_START);
        $end = CarbonImmutable::parse(self::PERIOD_END);
        $tradingDays = [];
        $rows = [];

        while ($day->lessThanOrEqualTo($end) === true) {
            if ($day->isWeekday() === false) {
                $day = $day->addDay();

                continue;
            }

            $date = $day->toDateString();
            $isOpen = $date !== self::HOLIDAY;
            $isEarlyClose = $date === self::EARLY_CLOSE;

            $rows[] = [
                'exchange' => 'XNYS',
                'date' => $date,
                'is_open' => $isOpen,
                'open_at' => $isOpen === true ? '09:30' : null,
                'close_at' => $this->closeTime($isOpen, $isEarlyClose),
                'is_early_close' => $isEarlyClose,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($isOpen === true) {
                $tradingDays[] = $date;
            }

            $day = $day->addDay();
        }

        MarketDay::query()->insert($rows);

        return $tradingDays;
    }

    private function closeTime(bool $isOpen, bool $isEarlyClose): null|string
    {
        if ($isOpen === false) {
            return null;
        }

        if ($isEarlyClose === true) {
            return '13:00';
        }

        return '16:00';
    }

    private function seedInstruments(): void
    {
        $this->instrument(self::GAP_INSTRUMENT, 'Alpha Industries', self::PERIOD_START, null);
        $this->symbol(self::GAP_INSTRUMENT, 'AAA', self::PERIOD_START, null);

        $this->instrument(self::DELISTED_INSTRUMENT, 'Beta Holdings', self::PERIOD_START, self::DELISTING_DATE);
        $this->symbol(self::DELISTED_INSTRUMENT, 'BBB', self::PERIOD_START, self::DELISTING_DATE);

        $this->instrument(self::LATECOMER_INSTRUMENT, 'Ceres Mining', self::LATECOMER_LISTED, null);
        $this->symbol(self::LATECOMER_INSTRUMENT, 'CCC', self::LATECOMER_LISTED, null);

        // Recyklovaný ticker. Původní vlastník není delistovaný — jen mu ticker
        // přešel na jiný symbol, což je běžnější a záludnější případ.
        $handoverEnd = CarbonImmutable::parse(self::RECYCLED_HANDOVER)->subDay()->toDateString();
        $this->instrument(self::RECYCLED_OLD_OWNER, 'Xylo Group', self::PERIOD_START, null);
        $this->symbol(self::RECYCLED_OLD_OWNER, self::RECYCLED_SYMBOL, self::PERIOD_START, $handoverEnd);
        $this->symbol(self::RECYCLED_OLD_OWNER, 'XYLG', self::RECYCLED_HANDOVER, null);

        $this->instrument(self::RECYCLED_NEW_OWNER, 'Zenith Yield', self::PERIOD_START, null);
        $this->symbol(self::RECYCLED_NEW_OWNER, 'QQZ', self::PERIOD_START, $handoverEnd);
        $this->symbol(self::RECYCLED_NEW_OWNER, self::RECYCLED_SYMBOL, self::RECYCLED_HANDOVER, null);
    }

    private function instrument(string $id, string $name, string $listedAt, null|string $delistedAt): void
    {
        Instrument::query()->create([
            'id' => $id,
            'name' => $name,
            'asset_class' => 'us_equity',
            'primary_exchange' => 'NYSE',
            'sector' => 'Industrials',
            'listed_at' => $listedAt,
            'delisted_at' => $delistedAt,
            'delisting_reason' => $delistedAt === null ? null : 'acquired',
        ]);
    }

    private function symbol(string $instrumentId, string $symbol, string $validFrom, null|string $validTo): void
    {
        InstrumentSymbol::query()->create([
            'instrument_id' => $instrumentId,
            'symbol' => $symbol,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ]);
    }

    private function seedCorporateActions(): void
    {
        CorporateAction::query()->create([
            'instrument_id' => self::GAP_INSTRUMENT,
            'type' => 'split',
            'ex_date' => self::LATECOMER_LISTED,
            'ratio' => 2.0,
            'amount' => null,
            'source' => self::SOURCE,
        ]);

        CorporateAction::query()->create([
            'instrument_id' => self::GAP_INSTRUMENT,
            'type' => 'dividend',
            'ex_date' => self::RECYCLED_HANDOVER,
            'ratio' => null,
            'amount' => 0.5,
            'source' => self::SOURCE,
        ]);
    }

    /** @param array<int,string> $tradingDays */
    private function seedBars(array $tradingDays): void
    {
        mt_srand(self::PRICE_SEED);

        $rows = [];

        foreach ($this->barWindows() as $instrumentId => $window) {
            $close = 50.0;

            foreach ($tradingDays as $date) {
                if ($this->isInWindow($date, $window) === false) {
                    continue;
                }

                $close = round($close * (1 + (mt_rand(-200, 200) / 10000)), 2);

                if ($instrumentId === self::GAP_INSTRUMENT && $date === self::GAP_DATE) {
                    continue;
                }

                $rows[] = $this->barRow($instrumentId, $date, $close);
            }
        }

        DB::table('daily_bars')->insert($rows);
    }

    /** @return array<string,array{from:string,to:null|string}> */
    private function barWindows(): array
    {
        return [
            self::GAP_INSTRUMENT => ['from' => self::PERIOD_START, 'to' => null],
            self::DELISTED_INSTRUMENT => ['from' => self::PERIOD_START, 'to' => self::DELISTING_DATE],
            self::LATECOMER_INSTRUMENT => ['from' => self::LATECOMER_LISTED, 'to' => null],
            self::RECYCLED_OLD_OWNER => ['from' => self::PERIOD_START, 'to' => null],
            self::RECYCLED_NEW_OWNER => ['from' => self::PERIOD_START, 'to' => null],
        ];
    }

    /** @param array{from:string,to:null|string} $window */
    private function isInWindow(string $date, array $window): bool
    {
        if ($date < $window['from']) {
            return false;
        }

        return $window['to'] === null || $date <= $window['to'];
    }

    /** @return array<string,mixed> */
    private function barRow(string $instrumentId, string $date, float $close): array
    {
        // Jediný bar porušující OHLC invariant: low nad high.
        $broken = $instrumentId === self::LATECOMER_INSTRUMENT && $date === self::OHLC_VIOLATION_DATE;

        return [
            'instrument_id' => $instrumentId,
            'date' => $date,
            'open' => $close,
            'high' => $broken === true ? $close - 1.0 : $close + 1.0,
            'low' => $broken === true ? $close + 1.0 : $close - 1.0,
            'close' => $close,
            'volume' => 100000 + mt_rand(0, 900000),
            'source' => self::SOURCE,
        ];
    }
}
