<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Enums\CorporateActionTypeEnum;
use App\MarketData\Models\CorporateAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CorporateAction> */
class CorporateActionFactory extends Factory
{
    /** @var class-string<CorporateAction> */
    protected $model = CorporateAction::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'type' => CorporateActionTypeEnum::SPLIT,
            'ex_date' => '2020-08-31',
            'ratio' => 4.0,
            'amount' => null,
            'source' => 'fixture',
        ];
    }
}
