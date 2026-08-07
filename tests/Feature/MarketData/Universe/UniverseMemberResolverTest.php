<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Universe;

use App\MarketData\Data\UniverseRulesData;
use App\MarketData\Ingest\PartitionManager;
use App\MarketData\Models\DailyBar;
use App\MarketData\Models\Instrument;
use App\MarketData\Models\MarketDay;
use App\MarketData\Models\UniverseDefinition;
use App\MarketData\Models\UniverseMember;
use App\MarketData\Universe\UniverseMemberResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(UniverseMemberResolver::class)]
final class UniverseMemberResolverTest extends TestCase
{
    use RefreshDatabase;

    private const string LIQUID = '550e8400-e29b-41d4-a716-446655440000';
    private const string PENNY = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
    private const string DELISTED = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    private const string PERIOD_START = '2019-02-01';
    private const string PERIOD_END = '2019-03-29';
    private const string DELISTING_DATE = '2019-02-28';

    public function testRebuild(): void
    {
        $definition = $this->definition();
        $this->scenario();

        App::make(UniverseMemberResolver::class)->rebuild(
            $definition,
            CarbonImmutable::parse(self::PERIOD_START),
            CarbonImmutable::parse(self::PERIOD_END),
        );

        $members = App::make(UniverseMemberResolver::class)
            ->membersAt($definition, CarbonImmutable::parse('2019-03-15'));

        $this->assertTrue($members->contains(self::LIQUID));
        $this->assertFalse($members->contains(self::PENNY));
    }

    public function testMembersAtDelistedInstrument(): void
    {
        $definition = $this->definition();
        $this->scenario();
        App::make(UniverseMemberResolver::class)->rebuild(
            $definition,
            CarbonImmutable::parse(self::PERIOD_START),
            CarbonImmutable::parse(self::PERIOD_END),
        );

        $resolver = App::make(UniverseMemberResolver::class);

        $this->assertTrue(
            $resolver->membersAt($definition, CarbonImmutable::parse('2019-02-15'))->contains(self::DELISTED),
            'Delistovaný instrument MUSÍ být členem k datům před delistingem — jinak je to survivorship bias.',
        );
        $this->assertFalse(
            $resolver->membersAt($definition, CarbonImmutable::parse('2019-03-15'))->contains(self::DELISTED),
        );
    }

    /**
     * Jádro celé sady: členství k datu D spočítané nad daty, ve kterých budoucnost
     * fyzicky není, se musí rovnat členství nad plnými daty. Když se to nerovná,
     * implementace se dívá dopředu — a žádné čtení kódu to nezjistí spolehlivěji.
     */
    public function testRebuildTruncatedHistory(): void
    {
        $cutoff = CarbonImmutable::parse('2019-03-15');
        $definition = $this->definition();
        $this->scenario();

        App::make(UniverseMemberResolver::class)->rebuild(
            $definition,
            CarbonImmutable::parse(self::PERIOD_START),
            CarbonImmutable::parse(self::PERIOD_END),
        );
        $full = App::make(UniverseMemberResolver::class)->membersAt($definition, $cutoff)->sort()->values();

        DailyBar::query()->where('date', '>', $cutoff->toDateString())->delete();
        $truncated = $this->definition(version: 2);
        App::make(UniverseMemberResolver::class)->rebuild(
            $truncated,
            CarbonImmutable::parse(self::PERIOD_START),
            $cutoff,
        );
        $partial = App::make(UniverseMemberResolver::class)->membersAt($truncated, $cutoff)->sort()->values();

        $this->assertSame($full->all(), $partial->all());
    }

    /**
     * Append-only znamená, že druhý běh nad stejným rozsahem nevloží nic a počet
     * řádků se nezmění. Plán tady tvrdil, že se obě návratové hodnoty rovnají,
     * ale ON CONFLICT DO NOTHING vrací počet skutečně vložených řádků, tedy nulu.
     */
    public function testRebuildAppendOnly(): void
    {
        $definition = $this->definition();
        $this->scenario();
        $resolver = App::make(UniverseMemberResolver::class);
        $from = CarbonImmutable::parse(self::PERIOD_START);
        $to = CarbonImmutable::parse(self::PERIOD_END);

        $first = $resolver->rebuild($definition, $from, $to);
        $countAfterFirst = UniverseMember::query()->count();
        $second = $resolver->rebuild($definition, $from, $to);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
        $this->assertSame($countAfterFirst, UniverseMember::query()->count());
    }

    private function definition(int $version = 1): UniverseDefinition
    {
        return UniverseDefinition::query()->create([
            'name' => 'liquid_us',
            'version' => $version,
            'rules' => UniverseRulesData::fake([
                'minPrice' => 5.0,
                'minAvgDollarVolume' => 1_000_000.0,
                'dollarVolumeLookbackDays' => 5,
            ]),
        ]);
    }

    /**
     * LIQUID   — close 100, volume 1M → dollar volume 100M, člen po celé období
     * PENNY    — close 2,   volume 1M → pod minPrice, nikdy člen
     * DELISTED — close 100, volume 1M, delisted_at 2019-02-28 → člen jen do delistingu
     */
    private function scenario(): void
    {
        if (Instrument::query()->exists() === true) {
            return;
        }

        App::make(PartitionManager::class)->ensureDailyYear(2019);
        $tradingDays = $this->seedCalendar();

        $this->instrument(self::LIQUID, null);
        $this->instrument(self::PENNY, null);
        $this->instrument(self::DELISTED, self::DELISTING_DATE);

        $rows = [];

        foreach ($tradingDays as $date) {
            $rows[] = $this->barRow(self::LIQUID, $date, 100.0);
            $rows[] = $this->barRow(self::PENNY, $date, 2.0);

            if ($date <= self::DELISTING_DATE) {
                $rows[] = $this->barRow(self::DELISTED, $date, 100.0);
            }
        }

        DB::table('daily_bars')->insert($rows);
    }

    /** @return array<int,string> */
    private function seedCalendar(): array
    {
        $day = CarbonImmutable::parse(self::PERIOD_START);
        $end = CarbonImmutable::parse(self::PERIOD_END);
        $tradingDays = [];
        $rows = [];

        while ($day->lessThanOrEqualTo($end) === true) {
            if ($day->isWeekday() === true) {
                $tradingDays[] = $day->toDateString();
                $rows[] = [
                    'exchange' => 'XNYS',
                    'date' => $day->toDateString(),
                    'is_open' => true,
                    'open_at' => '09:30',
                    'close_at' => '16:00',
                    'is_early_close' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $day = $day->addDay();
        }

        MarketDay::query()->insert($rows);

        return $tradingDays;
    }

    private function instrument(string $id, null|string $delistedAt): void
    {
        Instrument::factory()->create([
            'id' => $id,
            'listed_at' => '2000-01-03',
            'delisted_at' => $delistedAt,
        ]);
    }

    /** @return array<string,mixed> */
    private function barRow(string $instrumentId, string $date, float $close): array
    {
        return [
            'instrument_id' => $instrumentId,
            'date' => $date,
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'volume' => 1_000_000,
            'source' => 'fixture',
        ];
    }
}
