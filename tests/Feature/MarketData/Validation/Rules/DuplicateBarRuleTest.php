<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Validation\Rules\DuplicateBarRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(DuplicateBarRule::class)]
final class DuplicateBarRuleTest extends TestCase
{
    use RefreshDatabase;

    public function testFindings(): void
    {
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
            ['symbol' => 'AAPL', 'date' => '2019-03-14', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
        ]);

        $this->assertSame([], iterator_to_array(new DuplicateBarRule()->findings($table)));
    }

    public function testFindingsDuplicate(): void
    {
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 12],
        ]);

        $findings = iterator_to_array(new DuplicateBarRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('2019-03-13', $findings[0]->detail);
        $this->assertStringContainsString('2', $findings[0]->detail);
    }

    public function testFindingsEmptyTable(): void
    {
        $this->assertSame([], iterator_to_array(new DuplicateBarRule()->findings(StagingFixture::withRows([]))));
    }
}
