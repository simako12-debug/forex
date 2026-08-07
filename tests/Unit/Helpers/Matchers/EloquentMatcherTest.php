<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Matchers;

use App\MarketData\Models\Instrument;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Helpers\Matchers\EloquentMatcher;
use Tests\TestCase;

#[CoversClass(EloquentMatcher::class)]
final class EloquentMatcherTest extends TestCase
{
    public function testMatches(): void
    {
        $model = new Instrument();
        $model->id = '550e8400-e29b-41d4-a716-446655440000';

        $other = new Instrument();
        $other->id = '550e8400-e29b-41d4-a716-446655440000';

        $this->assertTrue(new EloquentMatcher($model)->matches($other));
    }

    public function testMatchesDifferentKey(): void
    {
        $model = new Instrument();
        $model->id = '550e8400-e29b-41d4-a716-446655440000';

        $other = new Instrument();
        $other->id = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

        $this->assertFalse(new EloquentMatcher($model)->matches($other));
    }

    public function testMatchesNonModel(): void
    {
        $model = new Instrument();
        $model->id = '550e8400-e29b-41d4-a716-446655440000';

        $this->assertFalse(new EloquentMatcher($model)->matches('not a model'));
    }
}
