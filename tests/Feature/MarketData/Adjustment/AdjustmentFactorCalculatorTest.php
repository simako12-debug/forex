<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Adjustment;

use App\MarketData\Adjustment\AdjustmentFactorCalculator;
use App\MarketData\Enums\CorporateActionTypeEnum;
use App\MarketData\Ingest\PartitionManager;
use App\MarketData\Models\AdjustmentFactor;
use App\MarketData\Models\CorporateAction;
use App\MarketData\Models\DailyBar;
use App\MarketData\Models\Instrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(AdjustmentFactorCalculator::class)]
final class AdjustmentFactorCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

    public function testRecalculateNoActions(): void
    {
        $this->bars(['2020-08-28' => 500.0, '2020-08-31' => 125.0]);

        $this->assertSame(0, App::make(AdjustmentFactorCalculator::class)->recalculate(self::INSTRUMENT));
    }

    public function testRecalculateSplitOnly(): void
    {
        $this->bars(['2020-08-27' => 500.0, '2020-08-28' => 500.0, '2020-08-31' => 125.0]);
        $this->split('2020-08-31', 4.0);

        App::make(AdjustmentFactorCalculator::class)->recalculate(self::INSTRUMENT);

        $before = AdjustmentFactor::query()->where('date', '2020-08-28')->firstOrFail();
        $this->assertEqualsWithDelta(4.0, $before->cum_split_factor, 0.0000001);
        $this->assertNull(AdjustmentFactor::query()->where('date', '2020-08-31')->first());
    }

    /**
     * Golden test, který specifikace vyžaduje: upravená řada nesmí mít v den ex-date
     * nespojitost. Kdyby se faktor aplikoval obráceně (násobil místo dělil), spadne
     * tenhle test, zatímco všechny ostatní by prošly.
     */
    public function testRecalculateSplitContinuity(): void
    {
        $this->bars(['2020-08-28' => 500.0, '2020-08-31' => 125.0]);
        $this->split('2020-08-31', 4.0);

        App::make(AdjustmentFactorCalculator::class)->recalculate(self::INSTRUMENT);

        $factor = AdjustmentFactor::query()->where('date', '2020-08-28')->firstOrFail();
        $adjustedBefore = 500.0 / $factor->cum_split_factor;
        $adjustedAfter = 125.0;

        $this->assertEqualsWithDelta(0.0, log($adjustedAfter / $adjustedBefore), 0.0000001);
    }

    public function testRecalculateSplitAndDividend(): void
    {
        $this->bars(['2020-08-27' => 500.0, '2020-08-28' => 500.0, '2020-08-31' => 125.0]);
        $this->split('2020-08-31', 4.0);
        CorporateAction::factory()->create([
            'instrument_id' => self::INSTRUMENT,
            'type' => CorporateActionTypeEnum::DIVIDEND,
            'ex_date' => '2020-08-28',
            'ratio' => null,
            'amount' => 5.0,
        ]);

        App::make(AdjustmentFactorCalculator::class)->recalculate(self::INSTRUMENT);

        $row = AdjustmentFactor::query()->where('date', '2020-08-27')->firstOrFail();
        $this->assertEqualsWithDelta(4.0, $row->cum_split_factor, 0.0000001);
        $this->assertEqualsWithDelta(1.0 - 5.0 / 500.0, $row->cum_div_factor, 0.0000001);
    }

    public function testRecalculateIdempotence(): void
    {
        $this->bars(['2020-08-28' => 500.0, '2020-08-31' => 125.0]);
        $this->split('2020-08-31', 4.0);
        $calculator = App::make(AdjustmentFactorCalculator::class);

        $first = $calculator->recalculate(self::INSTRUMENT);
        $second = $calculator->recalculate(self::INSTRUMENT);

        $this->assertSame($first, $second);
        $this->assertSame($first, AdjustmentFactor::query()->count());
    }

    /** @param array<string,float> $closes */
    private function bars(array $closes): void
    {
        Instrument::factory()->create(['id' => self::INSTRUMENT]);
        App::make(PartitionManager::class)->ensureDailyYear(2020);

        foreach ($closes as $date => $close) {
            DailyBar::factory()->create([
                'instrument_id' => self::INSTRUMENT,
                'date' => $date,
                'open' => $close,
                'high' => $close,
                'low' => $close,
                'close' => $close,
            ]);
        }
    }

    private function split(string $exDate, float $ratio): void
    {
        CorporateAction::factory()->create([
            'instrument_id' => self::INSTRUMENT,
            'type' => CorporateActionTypeEnum::SPLIT,
            'ex_date' => $exDate,
            'ratio' => $ratio,
        ]);
    }
}
