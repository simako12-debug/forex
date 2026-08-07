<?php

declare(strict_types=1);

namespace App\Providers;

use App\MarketData\Calendar\AlpacaCalendarSource;
use App\MarketData\Contracts\ValidationRule;
use App\MarketData\Validation\Rules\BarOnClosedDayRule;
use App\MarketData\Validation\Rules\CrossSourceDivergenceRule;
use App\MarketData\Validation\Rules\DuplicateBarRule;
use App\MarketData\Validation\Rules\MissingBarOnTradingDayRule;
use App\MarketData\Validation\Rules\OhlcConsistencyRule;
use App\MarketData\Validation\Rules\PriceJumpWithoutCorporateActionRule;
use App\MarketData\Validation\Rules\StaleSeriesRule;
use App\MarketData\Validation\Rules\ZeroOrMissingVolumeRule;
use App\MarketData\Validation\ValidationRunner;
use Illuminate\Contracts\Foundation\Application;
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

        // Seznam validačních pravidel musí existovat na jednom místě, aby
        // market-data:list-validation-rules vypisoval přesně to, co runner spouští.
        $this->app->singleton('market-data.validation.rules', fn (): array => [
            new OhlcConsistencyRule(),
            new DuplicateBarRule(),
            new BarOnClosedDayRule(),
            new MissingBarOnTradingDayRule(),
            new PriceJumpWithoutCorporateActionRule(),
            new ZeroOrMissingVolumeRule(),
            new StaleSeriesRule(),
            new CrossSourceDivergenceRule(),
        ]);

        $this->app->bind(
            ValidationRunner::class,
            function (Application $app): ValidationRunner {
                /** @var array<int,ValidationRule> $rules */
                $rules = $app->make('market-data.validation.rules');

                return new ValidationRunner($rules);
            },
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
