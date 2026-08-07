<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Ingest;

use App\MarketData\Enums\IngestModeEnum;
use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Ingest\Bulk\GenericOhlcvCsvSource;
use App\MarketData\Ingest\IngestPipeline;
use App\MarketData\Models\DailyBar;
use App\MarketData\Models\Instrument;
use App\MarketData\Models\MarketDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(IngestPipeline::class)]
final class IngestPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURE = __DIR__ . '/../../../fixtures/market-data/daily-sample.csv';

    public function testRun(): void
    {
        $this->seedCatalogue();

        $run = App::make(IngestPipeline::class)->run(
            new GenericOhlcvCsvSource(self::FIXTURE),
            IngestModeEnum::BULK,
            'hash-1',
        );

        $this->assertSame(IngestStatusEnum::COMPLETED, $run->status);
        $this->assertSame(4, $run->rows_inserted);
        $this->assertSame(4, DailyBar::query()->count());
    }

    public function testRunIdempotence(): void
    {
        $this->seedCatalogue();
        $source = new GenericOhlcvCsvSource(self::FIXTURE);

        App::make(IngestPipeline::class)->run($source, IngestModeEnum::BULK, 'hash-1');
        $second = App::make(IngestPipeline::class)->run($source, IngestModeEnum::BULK, 'hash-1');

        $this->assertSame(0, $second->rows_inserted);
        $this->assertSame(4, DailyBar::query()->count());
    }

    /**
     * Nejdůležitější chování celé pipeline: jeden nenapárovaný ticker neshodí
     * import ostatních.
     */
    public function testRunUnknownSymbolQuarantined(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        MarketDay::factory()->create(['date' => '2019-03-14', 'is_open' => true]);
        $this->instrument('550e8400-e29b-41d4-a716-446655440000', 'AAPL');

        $run = App::make(IngestPipeline::class)->run(
            new GenericOhlcvCsvSource(self::FIXTURE),
            IngestModeEnum::BULK,
            'hash-1',
        );

        $this->assertSame(2, $run->rows_inserted);
        $this->assertSame(1, $run->findings()->where('rule', 'UnknownSymbol')->count());
    }

    private function seedCatalogue(): void
    {
        MarketDay::factory()->create(['date' => '2019-03-13', 'is_open' => true]);
        MarketDay::factory()->create(['date' => '2019-03-14', 'is_open' => true]);
        $this->instrument('550e8400-e29b-41d4-a716-446655440000', 'AAPL');
        $this->instrument('6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'XYZ');
    }

    private function instrument(string $id, string $symbol): void
    {
        $instrument = Instrument::factory()->create(['id' => $id]);
        $instrument->symbols()->create(['symbol' => $symbol, 'valid_from' => '2000-01-03', 'valid_to' => null]);
    }
}
