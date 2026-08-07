<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Ingest\PartitionManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class EnsurePartitionsCommand extends Command
{
    /** @var string */
    protected $signature = 'market-data:ensure-partitions {--from-year=2000} {--to-year=}';

    /** @var string */
    protected $description = 'Vytvoří chybějící partitions pro tabulky barů';

    public function handle(PartitionManager $partitions): int
    {
        $fromYear = $this->yearOption('from-year', 2000);
        $toYear = $this->yearOption('to-year', CarbonImmutable::now()->year + 1);

        for ($year = $fromYear; $year <= $toYear; $year++) {
            $partitions->ensureDailyYear($year);

            for ($month = 1; $month <= 12; $month++) {
                $partitions->ensureIntradayMonth($year, $month);
            }
        }

        $this->info(sprintf('Partitions zajištěny pro roky %d–%d.', $fromYear, $toYear));

        return self::SUCCESS;
    }

    /**
     * Command::option() vrací array|bool|string|null, takže přímý přetyp na int
     * na levelu max neprojde. Nezadaná i prázdná volba spadne na výchozí hodnotu.
     */
    private function yearOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (is_numeric($value) === false) {
            return $default;
        }

        return (int) $value;
    }
}
