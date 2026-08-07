<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Validation;

use App\MarketData\Models\IngestRun;
use App\MarketData\Models\MarketDay;
use App\MarketData\Models\ValidationFinding;
use App\MarketData\Validation\ValidationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\StagingFixture;
use Tests\TestCase;

#[CoversClass(ValidationRunner::class)]
final class ValidationRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const string INSTRUMENT = '550e8400-e29b-41d4-a716-446655440000';

    public function testRun(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $run = IngestRun::factory()->create();
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10],
        ], self::INSTRUMENT);

        $outcome = App::make(ValidationRunner::class)->run($table, $run->id);

        $this->assertSame(0, $outcome->errorCount);
        $this->assertSame([], $outcome->quarantinedInstrumentIds);
    }

    public function testRunErrorQuarantinesInstrument(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $run = IngestRun::factory()->create();
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 9, 'low' => 11, 'close' => 10],
        ], self::INSTRUMENT);

        $outcome = App::make(ValidationRunner::class)->run($table, $run->id);

        $this->assertSame(1, $outcome->errorCount);
        $this->assertSame([self::INSTRUMENT], $outcome->quarantinedInstrumentIds);
        $this->assertSame(1, ValidationFinding::query()->where('rule', 'OhlcConsistency')->count());
    }

    public function testRunWarningDoesNotQuarantine(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        $run = IngestRun::factory()->create();
        $table = StagingFixture::withRows([
            ['symbol' => 'AAPL', 'date' => '2019-03-13', 'open' => 10, 'high' => 11, 'low' => 9,
                'close' => 10, 'volume' => 0],
        ], self::INSTRUMENT);

        $outcome = App::make(ValidationRunner::class)->run($table, $run->id);

        $this->assertSame(0, $outcome->errorCount);
        $this->assertSame(1, $outcome->warningCount);
        $this->assertSame([], $outcome->quarantinedInstrumentIds);
    }
}
