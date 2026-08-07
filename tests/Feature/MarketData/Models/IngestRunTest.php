<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData\Models;

use App\MarketData\Enums\FindingSeverityEnum;
use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Models\IngestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(IngestRun::class)]
final class IngestRunTest extends TestCase
{
    use RefreshDatabase;

    public function testCompletedForFileHash(): void
    {
        IngestRun::factory()->create([
            'file_hash' => 'abc123',
            'status' => IngestStatusEnum::COMPLETED,
        ]);

        $this->assertTrue(IngestRun::completedForFileHash('abc123'));
    }

    /**
     * Pointa idempotence: jen dokončený běh blokuje reimport, spadlý se musí
     * dát zopakovat.
     */
    public function testCompletedForFileHashFailedRun(): void
    {
        IngestRun::factory()->create([
            'file_hash' => 'abc123',
            'status' => IngestStatusEnum::FAILED,
        ]);

        $this->assertFalse(IngestRun::completedForFileHash('abc123'));
    }

    public function testCompletedForFileHashUnknownHash(): void
    {
        $this->assertFalse(IngestRun::completedForFileHash('nothing'));
    }

    public function testFindings(): void
    {
        $run = IngestRun::factory()->create();
        $run->findings()->create([
            'rule' => 'OhlcConsistency',
            'severity' => FindingSeverityEnum::ERROR,
            'detail' => 'low > high',
        ]);

        $this->assertSame(1, $run->findings()->count());
    }
}
