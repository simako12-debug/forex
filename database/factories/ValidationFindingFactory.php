<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Enums\FindingSeverityEnum;
use App\MarketData\Models\ValidationFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ValidationFinding> */
class ValidationFindingFactory extends Factory
{
    /** @var class-string<ValidationFinding> */
    protected $model = ValidationFinding::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'instrument_id' => null,
            'date' => null,
            'rule' => 'OhlcConsistency',
            'severity' => FindingSeverityEnum::WARNING,
            'detail' => 'fixture finding',
        ];
    }
}
