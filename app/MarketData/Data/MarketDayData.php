<?php

declare(strict_types=1);

namespace App\MarketData\Data;

use Carbon\CarbonImmutable;
use Faker\Factory;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

final class MarketDayData extends Data
{
    public function __construct(
        public readonly string $exchange,
        // Výchozí formát laravel-data je ATOM. Obchodní den je v pojmu burzy čisté
        // datum bez času a bez zóny, takže formát patří sem, ne do globální konfigurace.
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public readonly CarbonImmutable $date,
        public readonly bool $isOpen,
        public readonly null|string $openAt,
        public readonly null|string $closeAt,
        public readonly bool $isEarlyClose,
    ) {
    }

    /** @param array<string,mixed> $attributes */
    public static function fake(array $attributes = []): self
    {
        $faker = Factory::create();

        return self::from([
            'exchange' => 'XNYS',
            'date' => $faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'isOpen' => true,
            'openAt' => '09:30',
            'closeAt' => '16:00',
            'isEarlyClose' => false,
            ...$attributes,
        ]);
    }
}
