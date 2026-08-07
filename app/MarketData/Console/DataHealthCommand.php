<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Health\HealthChecker;
use Illuminate\Console\Command;

final class DataHealthCommand extends Command
{
    /** @var string */
    protected $signature = 'market-data:health';

    /** @var string */
    protected $description = 'Zkontroluje stav datového skladu';

    /**
     * Nenulový exit kód je celý smysl příkazu — bez něj by ho monitoring
     * nedokázal použít.
     */
    public function handle(HealthChecker $checker): int
    {
        $report = $checker->check();

        $this->table(['Kontrola', 'Hodnota'], [
            ['Poslední úspěšný ingest', $report->lastSuccessfulIngestAt?->toDateTimeString() ?? 'nikdy'],
            ['Pokryté obchodní dny (30 d)', (string) $report->tradingDaysCoveredLast30],
            ['Otevřené error nálezy', (string) $report->openErrorFindings],
            ['Chybějící partitions', $this->formatYears($report->missingPartitionYears)],
        ]);

        if ($report->healthy === false) {
            $this->error('Datový sklad NENÍ v pořádku.');

            return self::FAILURE;
        }

        $this->info('Datový sklad je v pořádku.');

        return self::SUCCESS;
    }

    /** @param array<int,int> $years */
    private function formatYears(array $years): string
    {
        if ($years === []) {
            return 'žádné';
        }

        return implode(', ', array_map(fn (int $year): string => (string) $year, $years));
    }
}
