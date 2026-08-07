<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Enums\IngestModeEnum;
use App\MarketData\Ingest\Incremental\AlpacaBarSource;
use App\MarketData\Ingest\IngestPipeline;
use App\MarketData\Models\InstrumentSymbol;
use App\MarketData\Models\MarketDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

final class ImportIncrementalBarsCommand extends Command
{
    private const string LOCK = 'market-data:ingest:incremental';
    private const int LOCK_SECONDS = 3600;
    private const int DEFAULT_LOOKBACK_DAYS = 5;

    /** @var string */
    protected $signature = 'market-data:import-incremental {--from=} {--to=}';

    /** @var string */
    protected $description = 'Doplní denní bary z Alpaca API za posledních několik dní';

    /**
     * Cache::lock nad `database` storem je to, co v tomto plánu zastupuje Redis —
     * chování je stejné, jen bez další služby.
     */
    public function handle(IngestPipeline $pipeline): int
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if ($lock->get() === false) {
            $this->warn('Inkrementální ingest už běží, přeskakuji.');

            return self::SUCCESS;
        }

        try {
            return $this->import($pipeline);
        } finally {
            $lock->release();
        }
    }

    private function import(IngestPipeline $pipeline): int
    {
        $to = $this->dateOption('to', CarbonImmutable::now());
        $from = $this->dateOption('from', $to->subDays(self::DEFAULT_LOOKBACK_DAYS));

        if (MarketDay::isTradingDay('XNYS', $to) === false) {
            $this->info('Cílový den nebyl obchodní, nic k importu.');

            return self::SUCCESS;
        }

        $symbols = $this->activeSymbols();

        if (empty($symbols) === true) {
            $this->warn('Žádné aktivní symboly, nic k importu.');

            return self::SUCCESS;
        }

        $run = $pipeline->run(
            new AlpacaBarSource(
                symbols: $symbols,
                from: $from,
                to: $to,
                baseUrl: Config::string('services.alpaca.data_url'),
                keyId: Config::string('services.alpaca.key_id'),
                secretKey: Config::string('services.alpaca.secret_key'),
                feed: Config::string('services.alpaca.feed'),
            ),
            IngestModeEnum::INCREMENTAL,
            null,
        );

        $this->info(sprintf(
            'Běh %s: vloženo %d, aktualizováno %d.',
            $run->id,
            $run->rows_inserted,
            $run->rows_updated,
        ));

        return self::SUCCESS;
    }

    /**
     * Symboly = aktuální členové univerza. Do doby, než existuje point-in-time
     * členství z Tasku 20, se berou všechny stále platné symboly.
     *
     * @return array<int,string>
     */
    private function activeSymbols(): array
    {
        return InstrumentSymbol::query()
            ->whereNull('valid_to')
            ->get()
            ->map(fn (InstrumentSymbol $symbol): string => $symbol->symbol)
            ->all();
    }

    private function dateOption(string $name, CarbonImmutable $default): CarbonImmutable
    {
        $value = $this->option($name);

        if (is_string($value) === false || $value === '') {
            return $default;
        }

        return CarbonImmutable::parse($value);
    }
}
