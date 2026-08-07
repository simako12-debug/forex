<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Models\UniverseDefinition;
use App\MarketData\Universe\UniverseMemberResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class RebuildUniverseCommand extends Command
{
    private const string DEFAULT_FROM = '2000-01-01';

    /** @var string */
    protected $signature = 'market-data:rebuild-universe {name} {version} {--from=} {--to=}';

    /** @var string */
    protected $description = 'Přepočítá point-in-time členství v univerzu';

    public function handle(UniverseMemberResolver $resolver): int
    {
        $definition = UniverseDefinition::query()
            ->where('name', $this->stringArgument('name'))
            ->where('version', $this->stringArgument('version'))
            ->first();

        if ($definition === null) {
            $this->error('Definice univerza s tímto jménem a verzí neexistuje.');

            return self::FAILURE;
        }

        $from = $this->dateOption('from', CarbonImmutable::parse(self::DEFAULT_FROM));
        $to = $this->dateOption('to', CarbonImmutable::now());

        $inserted = $resolver->rebuild($definition, $from, $to);

        $this->info(sprintf(
            'Univerzum %s v%d: zapsáno %d nových členství v rozsahu %s..%s.',
            $definition->name,
            $definition->version,
            $inserted,
            $from->toDateString(),
            $to->toDateString(),
        ));

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        if (is_string($value) === false) {
            return '';
        }

        return $value;
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
