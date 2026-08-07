<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Models\MarketDay;
use App\MarketData\Validation\Rules\ZeroOrMissingVolumeRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(ZeroOrMissingVolumeRule::class)]
final class ZeroOrMissingVolumeRuleTest extends TestCase
{
    use RefreshDatabase;

    public function testFindings(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9,
                'close' => 10, 'volume' => 1000],
        ]);

        $this->assertSame([], iterator_to_array(new ZeroOrMissingVolumeRule()->findings($table)));
    }

    public function testFindingsZeroVolume(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9,
                'close' => 10, 'volume' => 0],
        ]);

        $findings = iterator_to_array(new ZeroOrMissingVolumeRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('IEX', $findings[0]->detail);
    }
}
