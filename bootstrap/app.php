<?php

use App\MarketData\Console\DataBenchmarkCommand;
use App\MarketData\Console\DataHealthCommand;
use App\MarketData\Console\EnsurePartitionsCommand;
use App\MarketData\Console\ExportParquetCommand;
use App\MarketData\Console\ImportBulkBarsCommand;
use App\MarketData\Console\ImportCalendarCommand;
use App\MarketData\Console\ImportIncrementalBarsCommand;
use App\MarketData\Console\ListValidationRulesCommand;
use App\MarketData\Console\RecalculateAdjustmentsCommand;
use App\MarketData\Console\RebuildUniverseCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Commandy modulu žijí v app/MarketData/Console, kam autodiscovery Laravelu
    // nesahá — ta prohledává jen app/Console/Commands.
    ->withCommands([
        DataBenchmarkCommand::class,
        DataHealthCommand::class,
        EnsurePartitionsCommand::class,
        ExportParquetCommand::class,
        ImportBulkBarsCommand::class,
        ImportCalendarCommand::class,
        ImportIncrementalBarsCommand::class,
        ListValidationRulesCommand::class,
        RebuildUniverseCommand::class,
        RecalculateAdjustmentsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
