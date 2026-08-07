<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Data;

use App\MarketData\Data\UniverseRulesData;
use App\MarketData\Models\UniverseDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(UniverseRulesData::class)]
final class UniverseRulesDataTest extends TestCase
{
    use RefreshDatabase;

    public function testFake(): void
    {
        $rules = UniverseRulesData::fake();

        $this->assertEqualsWithDelta(5.0, $rules->minPrice, 0.0000001);
        $this->assertEqualsWithDelta(5_000_000.0, $rules->minAvgDollarVolume, 0.0000001);
        $this->assertSame(20, $rules->dollarVolumeLookbackDays);
    }

    public function testFakeWithOverrides(): void
    {
        $rules = UniverseRulesData::fake(['minPrice' => 1.5, 'dollarVolumeLookbackDays' => 5]);

        $this->assertEqualsWithDelta(1.5, $rules->minPrice, 0.0000001);
        $this->assertSame(5, $rules->dollarVolumeLookbackDays);
    }

    /** Pravidla se z databáze musí vracet jako Data objekt, ne jako pole. */
    public function testCastOnModel(): void
    {
        $definition = UniverseDefinition::factory()->create([
            'rules' => UniverseRulesData::fake(['minPrice' => 7.5]),
        ]);

        $reloaded = UniverseDefinition::query()->findOrFail($definition->id);

        $this->assertInstanceOf(UniverseRulesData::class, $reloaded->rules);
        $this->assertEqualsWithDelta(7.5, $reloaded->rules->minPrice, 0.0000001);
    }
}
