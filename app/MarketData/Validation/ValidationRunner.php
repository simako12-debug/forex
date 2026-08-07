<?php

declare(strict_types=1);

namespace App\MarketData\Validation;

use App\MarketData\Contracts\ValidationRule;
use App\MarketData\Enums\FindingSeverityEnum;
use App\MarketData\Models\ValidationFinding;

/**
 * Klíčové v této třídě je, co v ní není: žádná exception. Nález je datový záznam,
 * ne výjimečný stav. Karanténa je po instrumentu, ne po běhu — jeden rozbitý ticker
 * neshodí import ostatních.
 */
class ValidationRunner
{
    /** @param array<int,ValidationRule> $rules */
    public function __construct(private readonly array $rules)
    {
    }

    public function run(string $stagingTable, string $runId): ValidationOutcome
    {
        $errors = 0;
        $warnings = 0;
        $quarantined = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->findings($stagingTable) as $finding) {
                ValidationFinding::query()->create([
                    'ingest_run_id' => $runId,
                    'instrument_id' => $finding->instrumentId,
                    'date' => $finding->date?->toDateString(),
                    'rule' => $rule->name(),
                    'severity' => $rule->severity(),
                    'detail' => $finding->detail,
                ]);

                if ($rule->severity() === FindingSeverityEnum::ERROR) {
                    $errors++;

                    if ($finding->instrumentId !== null) {
                        $quarantined[$finding->instrumentId] = true;
                    }

                    continue;
                }

                $warnings++;
            }
        }

        return new ValidationOutcome($errors, $warnings, array_keys($quarantined));
    }
}
