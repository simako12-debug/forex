<?php

declare(strict_types=1);

namespace App\Providers;

use App\MarketData\Calendar\AlpacaCalendarSource;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Config::string() místo (string) Config::get() — get() vrací mixed a přetyp
        // na levelu max neprojde.
        $this->app->bind(AlpacaCalendarSource::class, fn (): AlpacaCalendarSource => new AlpacaCalendarSource(
            baseUrl: Config::string('services.alpaca.base_url'),
            keyId: Config::string('services.alpaca.key_id'),
            secretKey: Config::string('services.alpaca.secret_key'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
