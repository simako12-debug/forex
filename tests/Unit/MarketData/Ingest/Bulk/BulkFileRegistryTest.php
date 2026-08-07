<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Ingest\Bulk;

use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Ingest\Bulk\BulkFileRegistry;
use App\MarketData\Models\IngestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(BulkFileRegistry::class)]
final class BulkFileRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURE = __DIR__ . '/../../../../fixtures/market-data/daily-sample.csv';

    public function testHash(): void
    {
        $registry = App::make(BulkFileRegistry::class);

        $this->assertSame($registry->hash(self::FIXTURE), $registry->hash(self::FIXTURE));
        $this->assertSame(64, strlen($registry->hash(self::FIXTURE)));
    }

    public function testAlreadyImported(): void
    {
        $registry = App::make(BulkFileRegistry::class);
        $hash = $registry->hash(self::FIXTURE);

        IngestRun::factory()->create(['file_hash' => $hash, 'status' => IngestStatusEnum::COMPLETED]);

        $this->assertTrue($registry->alreadyImported($hash));
    }

    public function testAlreadyImportedFailedRun(): void
    {
        $registry = App::make(BulkFileRegistry::class);
        $hash = $registry->hash(self::FIXTURE);

        IngestRun::factory()->create(['file_hash' => $hash, 'status' => IngestStatusEnum::FAILED]);

        $this->assertFalse($registry->alreadyImported($hash));
    }
}
