<?php

declare(strict_types=1);

namespace Tests\Helpers\Matchers;

use Hamcrest\BaseMatcher;
use Hamcrest\Description;
use Illuminate\Support\Enumerable;

/**
 * Šablony jsou tu proto, že Enumerable není kovariantní — bez nich by pevné
 * Enumerable<array-key,mixed> odmítlo i běžnou Collection<int,string>.
 *
 * @template TKey of array-key
 * @template TValue
 */
final class CollectionMatcher extends BaseMatcher
{
    /** @param Enumerable<TKey,TValue> $expected */
    public function __construct(private readonly Enumerable $expected)
    {
    }

    public function matches(mixed $item): bool
    {
        if ($item instanceof Enumerable === false) {
            return false;
        }

        if ($item->count() !== $this->expected->count()) {
            return false;
        }

        // Srovnání je ==, ne ===, aby fungovalo i pro kolekce objektů se stejným
        // obsahem. U kolekcí modelů se na jednotlivé prvky používá EloquentMatcher;
        // tady jde o hodnoty.
        return $item->all() == $this->expected->all();
    }

    public function describeTo(Description $description): void
    {
        $description->appendText(sprintf('collection of %d items', $this->expected->count()));
    }
}
