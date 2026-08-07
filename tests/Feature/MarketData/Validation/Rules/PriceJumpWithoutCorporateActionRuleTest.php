<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Enums\CorporateActionTypeEnum;
use App\MarketData\Models\CorporateAction;
use App\MarketData\Validation\Rules\PriceJumpWithoutCorporateActionRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(PriceJumpWithoutCorporateActionRule::class)]
final class PriceJumpWithoutCorporateActionRuleTest extends TestCase
{
    use RefreshDatabase;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

    public function testFindings(): void
    {
        $table = $this->staged(100.0, 102.0);

        $this->assertSame([], iterator_to_array(new PriceJumpWithoutCorporateActionRule()->findings($table)));
    }

    public function testFindingsJumpWithoutAction(): void
    {
        $table = $this->staged(100.0, 25.0);

        $findings = iterator_to_array(new PriceJumpWithoutCorporateActionRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('2019-03-14', $findings[0]->detail);
    }

    public function testFindingsJumpWithSplit(): void
    {
        $table = $this->staged(100.0, 25.0);
        CorporateAction::factory()->create([
            'instrument_id' => self::INSTRUMENT,
            'type' => CorporateActionTypeEnum::SPLIT,
            'ex_date' => '2019-03-14',
            'ratio' => 4.0,
        ]);

        $this->assertSame([], iterator_to_array(new PriceJumpWithoutCorporateActionRule()->findings($table)));
    }

    private function staged(float $firstClose, float $secondClose): string
    {
        return StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => $firstClose, 'high' => $firstClose,
                'low' => $firstClose, 'close' => $firstClose],
            ['symbol' => 'AAPL', 'date' => '2019-03-14', 'open' => $secondClose, 'high' => $secondClose,
                'low' => $secondClose, 'close' => $secondClose],
        ], self::INSTRUMENT);
    }
}
