<?php

declare(strict_types=1);

namespace App\MarketData\Ingest\Incremental;

use Closure;
use Illuminate\Support\Facades\RateLimiter;

class ProviderRateLimiter
{
    private const int SLEEP_MICROSECONDS = 200000;

    public function throttle(string $key, int $perMinute, Closure $callback): mixed
    {
        while (RateLimiter::remaining($key, $perMinute) <= 0) {
            usleep(self::SLEEP_MICROSECONDS);
        }

        RateLimiter::hit($key, 60);

        return $callback();
    }
}
