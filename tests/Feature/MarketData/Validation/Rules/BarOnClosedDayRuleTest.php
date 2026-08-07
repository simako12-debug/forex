<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation\Rules;

use App\MarketData\Models\MarketDay;
use App\MarketData\Validation\Rules\BarOnClosedDayRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(BarOnClosedDayRule::class)]
final class BarOnClosedDayRuleTest extends TestCase
{
    use RefreshDatabase;

    public function testFindings(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
        ]);

        $this->assertSame([], iterator_to_array(new BarOnClosedDayRule()->findings($table)));
    }

    public function testFindingsClosedDay(): void
    {
        MarketDay::factory()->create(['date' => '2019-12-25', 'is_open' => false]);
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-12-25', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
        ]);

        $findings = iterator_to_array(new BarOnClosedDayRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('2019-12-25', $findings[0]->detail);
    }

    /**
     * Den, který v kalendáři není vůbec, je taky nález. Kdyby se bral jako
     * v pořádku, nenaplněný kalendář by celé pravidlo tiše vypnul.
     */
    public function testFindingsUnknownDay(): void
    {
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
        ]);

        $findings = iterator_to_array(new BarOnClosedDayRule()->findings($table));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('kalendář', $findings[0]->detail);
    }
}
