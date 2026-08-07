<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Matchers;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Helpers\Matchers\CollectionMatcher;
use Tests\TestCase;

#[CoversClass(CollectionMatcher::class)]
final class CollectionMatcherTest extends TestCase
{
    public function testMatches(): void
    {
        $matcher = new CollectionMatcher(new Collection(['a', 'b']));

        $this->assertTrue($matcher->matches(new Collection(['a', 'b'])));
    }

    public function testMatchesDifferentCount(): void
    {
        $matcher = new CollectionMatcher(new Collection(['a', 'b']));

        $this->assertFalse($matcher->matches(new Collection(['a'])));
    }

    public function testMatchesDifferentValues(): void
    {
        $matcher = new CollectionMatcher(new Collection(['a', 'b']));

        $this->assertFalse($matcher->matches(new Collection(['a', 'c'])));
    }

    public function testMatchesNonCollection(): void
    {
        $matcher = new CollectionMatcher(new Collection(['a']));

        $this->assertFalse($matcher->matches(['a']));
    }
}
