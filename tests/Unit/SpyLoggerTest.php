<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class SpyLoggerTest extends TestCase
{
    public function testSpyLoggerCapturesWarning(): void
    {
        $handler = $this->spyLogger();

        Log::warning('Recycled symbol detected for AAPL');

        $this->assertTrue($handler->hasWarningThatContains('Recycled symbol detected'));
    }

    public function testSpyLoggerNoMatch(): void
    {
        $handler = $this->spyLogger();

        Log::info('nothing interesting');

        $this->assertFalse($handler->hasWarningThatContains('Recycled symbol detected'));
    }
}
