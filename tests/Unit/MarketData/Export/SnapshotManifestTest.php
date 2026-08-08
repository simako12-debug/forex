<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Export;

use App\MarketData\Export\SnapshotManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(SnapshotManifest::class)]
final class SnapshotManifestTest extends TestCase
{
    public function testToArray(): void
    {
        $manifest = new SnapshotManifest(
            adjustmentLogicVersion: 1,
            exportedAt: '2026-08-07T10:00:00+00:00',
            years: [2019, 2020],
            rowCounts: ['daily_bars' => 42, 'instruments' => 5],
        );

        $this->assertSame([
            'adjustment_logic_version' => 1,
            'exported_at' => '2026-08-07T10:00:00+00:00',
            'years' => [2019, 2020],
            'row_counts' => ['daily_bars' => 42, 'instruments' => 5],
        ], $manifest->toArray());
    }

    public function testFromArray(): void
    {
        $manifest = SnapshotManifest::fromArray([
            'adjustment_logic_version' => 3,
            'exported_at' => '2026-08-07T10:00:00+00:00',
            'years' => [2019],
            'row_counts' => ['daily_bars' => 7],
        ]);

        $this->assertSame(3, $manifest->adjustmentLogicVersion);
        $this->assertSame([2019], $manifest->years);
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new SnapshotManifest(
            1,
            '2026-08-07T10:00:00+00:00',
            [2019],
            ['daily_bars' => 1]
        );

        $this->assertEquals($original, SnapshotManifest::fromArray($original->toArray()));
    }
}
