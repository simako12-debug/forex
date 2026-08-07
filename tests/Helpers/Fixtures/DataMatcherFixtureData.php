<?php

declare(strict_types=1);

namespace Tests\Helpers\Fixtures;

use Spatie\LaravelData\Data;

/**
 * Plán měl tuhle fixturu ve stejném souboru jako DataMatcherTest, což phpcs
 * odmítá (PSR-1: každá třída ve vlastním souboru).
 */
final class DataMatcherFixtureData extends Data
{
    public function __construct(public readonly string $symbol, public readonly int $volume)
    {
    }
}
