<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Calendar\AlpacaCalendarSource;
use App\MarketData\Calendar\CalendarImporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ImportCalendarCommand extends Command
{
    /** @var string */
    protected $signature = 'market-data:import-calendar {--from=2000-01-01} {--to=}';

    /** @var string */
    protected $description = 'Naplní burzovní kalendář z Alpaca calendar endpointu';

    public function handle(AlpacaCalendarSource $source, CalendarImporter $importer): int
    {
        $from = $this->dateOption('from', CarbonImmutable::parse('2000-01-01'));
        $to = $this->dateOption('to', CarbonImmutable::now()->addYear());

        $imported = $importer->import($source->fetch($from, $to));

        $this->info(sprintf('Importováno %d obchodních dní.', $imported));

        return self::SUCCESS;
    }

    /**
     * Command::option() vrací array|bool|string|null, takže přímý přetyp na string
     * na levelu max neprojde. Nezadaná i prázdná volba spadne na výchozí hodnotu.
     */
    private function dateOption(string $name, CarbonImmutable $default): CarbonImmutable
    {
        $value = $this->option($name);

        if (is_string($value) === false || $value === '') {
            return $default;
        }

        return CarbonImmutable::parse($value);
    }
}
