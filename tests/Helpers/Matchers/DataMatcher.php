<?php

declare(strict_types=1);

namespace Tests\Helpers\Matchers;

use Hamcrest\BaseMatcher;
use Hamcrest\Description;
use Spatie\LaravelData\Data;

final class DataMatcher extends BaseMatcher
{
    public function __construct(private readonly Data $expected)
    {
    }

    public function matches(mixed $item): bool
    {
        if ($item instanceof Data === false) {
            return false;
        }

        if ($item::class !== $this->expected::class) {
            return false;
        }

        return $item->toArray() === $this->expected->toArray();
    }

    public function describeTo(Description $description): void
    {
        $description->appendText(
            sprintf('%s matching %s', $this->expected::class, json_encode($this->expected->toArray())),
        );
    }
}
