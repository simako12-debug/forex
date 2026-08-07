<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Models\Instrument;
use App\MarketData\Models\MarketDay;
use App\MarketData\Validation\Rules\MissingBarOnTradingDayRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(MissingBarOnTradingDayRule::class)]
final class MissingBarOnTradingDayRuleTest extends TestCase
{
    use RefreshDatabase;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

    public function testFindings(): void
    {
        $this->calendar(['2019-03-13', '2019-03-14']);
        $table = StagingFixture::withRows([
            $this->row('2019-03-13'),
            $this->row('2019-03-14'),
        ], self::INSTRUMENT);

        $this->assertSame([], iterator_to_array(new MissingBarOnTradingDayRule()->findings($table)));
    }

    public function testFindingsMissingDay(): void
    {
        $this->calendar(['2019-03-13', '2019-03-14', '2019-03-15']);
        $table = StagingFixture::withRows([
            $this->row('2019-03-13'),
            $this->row('2019-03-15'),
        ], self::INSTRUMENT);

        $findings = iterator_to_array(new MissingBarOnTradingDayRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('2019-03-14', $findings[0]->detail);
    }

    /**
     * Po delistingu se chybějící bary hlásit nesmí, jinak by každý delistovaný
     * ticker vygeneroval nález za každý zbývající obchodní den v historii.
     */
    public function testFindingsDelistedBeforeGap(): void
    {
        $this->calendar(['2019-03-13', '2019-03-14', '2019-03-15']);
        $table = StagingFixture::withRows([$this->row('2019-03-13')], self::INSTRUMENT);
        Instrument::query()->whereKey(self::INSTRUMENT)->update(['delisted_at' => '2019-03-13']);

        $this->assertSame([], iterator_to_array(new MissingBarOnTradingDayRule()->findings($table)));
    }

    /** @param array<int,string> $dates */
    private function calendar(array $dates): void
    {
        foreach ($dates as $date) {
            MarketDay::factory()->create(['date' => $date, 'is_open' => true]);
        }
    }

    /** @return array<string,mixed> */
    private function row(string $date): array
    {
        return ['symbol' => 'AAPL', 'date' => $date, 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10];
    }
}
