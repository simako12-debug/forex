<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Data\UniverseRulesData;
use App\MarketData\Models\UniverseDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UniverseDefinition> */
class UniverseDefinitionFactory extends Factory
{
    /** @var class-string<UniverseDefinition> */
    protected $model = UniverseDefinition::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'name' => 'liquid_us',
            'version' => 1,
            'rules' => UniverseRulesData::fake(),
        ];
    }
}
