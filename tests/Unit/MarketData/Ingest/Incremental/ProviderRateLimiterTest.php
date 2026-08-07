<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Ingest\Incremental;

use App\MarketData\Ingest\Incremental\ProviderRateLimiter;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ProviderRateLimiter::class)]
final class ProviderRateLimiterTest extends TestCase
{
    private const string KEY = 'test:provider';

    public function testThrottle(): void
    {
        $result = new ProviderRateLimiter()->throttle(self::KEY, 10, fn (): string => 'volano');

        $this->assertSame('volano', $result);
    }

    public function testThrottleCountsHits(): void
    {
        $limiter = new ProviderRateLimiter();

        $limiter->throttle(self::KEY, 3, fn (): null => null);
        $limiter->throttle(self::KEY, 3, fn (): null => null);

        $this->assertSame(1, RateLimiter::remaining(self::KEY, 3));
    }

    /**
     * Vyčerpaný limit se netestuje voláním throttle — to by čekalo minutu.
     * Ověří se, že po vyčerpání je remaining nula, tedy že by smyčka čekala.
     */
    public function testThrottleExhaustsLimit(): void
    {
        $limiter = new ProviderRateLimiter();

        $limiter->throttle(self::KEY, 1, fn (): null => null);

        $this->assertSame(0, RateLimiter::remaining(self::KEY, 1));
    }
}
