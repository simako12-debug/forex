<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\MarketData\Enums\CorporateActionTypeEnum;
use App\MarketData\Models\CorporateAction;
use App\MarketData\Models\DailyBar;
use App\MarketData\Models\Instrument;
use App\MarketData\Models\InstrumentSymbol;
use App\MarketData\Models\MarketDay;
use Database\Seeders\CanonicalFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(CanonicalFixtureSeeder::class)]
final class CanonicalFixtureSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CanonicalFixtureSeeder::class);
    }

    public function testRunInstrumentCount(): void
    {
        $this->assertSame(5, Instrument::query()->count());
    }

    public function testRunTradingDayCount(): void
    {
        $this->assertSame(60, MarketDay::query()->where('is_open', true)->count());
    }

    public function testRunDelistedMidPeriod(): void
    {
        $delisted = Instrument::query()->whereNotNull('delisted_at')->get();

        $this->assertCount(1, $delisted);
        $this->assertSame(
            CanonicalFixtureSeeder::DELISTING_DATE,
            $delisted->first()?->delisted_at?->toDateString(),
        );
    }

    public function testRunLatecomerListedMidPeriod(): void
    {
        $latecomer = Instrument::query()
            ->where('listed_at', '>', CanonicalFixtureSeeder::PERIOD_START)
            ->get();

        $this->assertCount(1, $latecomer);
    }

    /** Recyklovaný ticker: dva různé instrumenty, stejný symbol, nepřekrývající se intervaly. */
    public function testRunRecycledSymbol(): void
    {
        $owners = InstrumentSymbol::query()
            ->where('symbol', CanonicalFixtureSeeder::RECYCLED_SYMBOL)
            ->orderBy('valid_from')
            ->get();

        $this->assertCount(2, $owners);

        $first = $owners->first();
        $second = $owners->last();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->instrument_id, $second->instrument_id);
        $this->assertTrue($first->valid_to?->lessThan($second->valid_from) ?? false);
    }

    public function testRunHolidayAndEarlyClose(): void
    {
        $this->assertSame(1, MarketDay::query()->where('is_open', false)->count());
        $this->assertSame(1, MarketDay::query()->where('is_early_close', true)->count());
    }

    public function testRunCorporateActions(): void
    {
        $this->assertSame(1, CorporateAction::query()->where('type', CorporateActionTypeEnum::SPLIT)->count());
        $this->assertSame(1, CorporateAction::query()->where('type', CorporateActionTypeEnum::DIVIDEND)->count());
    }

    /** Mezera v datech: obchodní den bez baru u instrumentu, který v ten den existoval. */
    public function testRunDataGap(): void
    {
        $missing = DailyBar::query()
            ->where('instrument_id', CanonicalFixtureSeeder::GAP_INSTRUMENT)
            ->where('date', CanonicalFixtureSeeder::GAP_DATE)
            ->exists();

        $this->assertFalse($missing);
        $this->assertTrue(MarketDay::query()->where('date', CanonicalFixtureSeeder::GAP_DATE)
            ->where('is_open', true)->exists());
    }

    public function testRunOhlcViolation(): void
    {
        $this->assertSame('1', $this->scalar('SELECT count(*) FROM daily_bars WHERE low > high'));
    }

    public function testRunIsDeterministic(): void
    {
        $firstRun = $this->scalar('SELECT sum(close) FROM daily_bars');

        DailyBar::query()->delete();
        $this->seed(CanonicalFixtureSeeder::class);

        $this->assertSame($firstRun, $this->scalar('SELECT sum(close) FROM daily_bars'));
    }

    /** DB::scalar() vrací mixed, takže přetyp na levelu max potřebuje ošetření. */
    private function scalar(string $sql): string
    {
        $value = DB::scalar($sql);

        $this->assertIsNumeric($value);

        return (string) $value;
    }
}
