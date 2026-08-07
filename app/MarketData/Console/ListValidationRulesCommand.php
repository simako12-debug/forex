<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Contracts\ValidationRule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

final class ListValidationRulesCommand extends Command
{
    /** @var string */
    protected $signature = 'market-data:list-validation-rules';

    /** @var string */
    protected $description = 'Vypíše validační pravidla a jejich severity';

    /**
     * Čte tentýž seznam, který spouští ValidationRunner — jinak by příkaz mohl
     * tvrdit něco jiného, než se skutečně kontroluje.
     */
    public function handle(): int
    {
        /** @var array<int,ValidationRule> $rules */
        $rules = App::make('market-data.validation.rules');

        $this->table(
            ['Pravidlo', 'Severita'],
            array_map(
                fn (ValidationRule $rule): array => [$rule->name(), $rule->severity()->value],
                $rules,
            ),
        );

        return self::SUCCESS;
    }
}
