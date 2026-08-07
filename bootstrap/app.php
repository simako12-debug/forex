<?php

use App\MarketData\Console\EnsurePartitionsCommand;
use App\MarketData\Console\ImportCalendarCommand;
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
        EnsurePartitionsCommand::class,
        ImportCalendarCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
