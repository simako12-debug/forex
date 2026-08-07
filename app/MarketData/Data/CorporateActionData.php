<?php

declare(strict_types=1);

namespace App\MarketData\Data;

use App\MarketData\Enums\CorporateActionTypeEnum;
use Carbon\CarbonImmutable;
use Faker\Factory;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

final class CorporateActionData extends Data
{
    public function __construct(
        public readonly string $symbol,
        public readonly CorporateActionTypeEnum $type,
        // Ex-date je čisté datum; výchozí ATOM z laravel-data by ho neposkládal.
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public readonly CarbonImmutable $exDate,
        public readonly null|float $ratio,
        public readonly null|float $amount,
    ) {
    }

    /** @param array<string,mixed> $attributes */
    public static function fake(array $attributes = []): self
    {
        $faker = Factory::create();

        return self::from([
            'symbol' => strtoupper($faker->lexify('????')),
            'type' => CorporateActionTypeEnum::SPLIT,
            'exDate' => $faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'ratio' => 2.0,
            'amount' => null,
            ...$attributes,
        ]);
    }
}
