<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

abstract class TestCase extends BaseTestCase
{
    private null|TestHandler $logHandler = null;

    protected function spyLogger(): TestHandler
    {
        $this->logHandler ??= $this->registerLogHandler();

        return $this->logHandler;
    }

    private function registerLogHandler(): TestHandler
    {
        $handler = new TestHandler();
        $logger = new Logger('testing', [$handler]);

        Log::swap($logger);
        App::instance(LoggerInterface::class, $logger);

        return $handler;
    }
}
