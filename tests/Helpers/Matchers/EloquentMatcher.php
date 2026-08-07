<?php

declare(strict_types=1);

namespace Tests\Helpers\Matchers;

use Hamcrest\BaseMatcher;
use Hamcrest\Description;
use Illuminate\Database\Eloquent\Model;

final class EloquentMatcher extends BaseMatcher
{
    public function __construct(private readonly Model $expected)
    {
    }

    public function matches(mixed $item): bool
    {
        if ($item instanceof Model === false) {
            return false;
        }

        return $item::class === $this->expected::class
            && $this->stringKey($item) === $this->stringKey($this->expected);
    }

    public function describeTo(Description $description): void
    {
        $description->appendText(
            sprintf('%s with key %s', $this->expected::class, $this->stringKey($this->expected)),
        );
    }

    /**
     * Model::getKey() vrací mixed, takže přímý přetyp na string na levelu max neprojde.
     * Klíč je v praxi vždy int nebo string; cokoliv jiného je nastavený model bez klíče.
     */
    private function stringKey(Model $model): string
    {
        $key = $model->getKey();

        if (is_scalar($key) === false) {
            return '';
        }

        return (string) $key;
    }
}
