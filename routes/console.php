<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Čas 23:00 je provizorní. Od podprojektu 6 se denní cyklus řídí burzovním
// kalendářem (výpočet po závěru, odesílání před otevřením), ne pevnou hodinou.
Schedule::command('market-data:import-incremental')->dailyAt('23:00')->withoutOverlapping();

Schedule::command('market-data:ensure-partitions')->yearlyOn(12, 1, '00:00');
