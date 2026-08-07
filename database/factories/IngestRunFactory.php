<?php

declare(strict_types=1);

namespace Database\Factories;

use App\MarketData\Enums\IngestModeEnum;
use App\MarketData\Enums\IngestStatusEnum;
use App\MarketData\Models\IngestRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IngestRun> */
class IngestRunFactory extends Factory
{
    /** @var class-string<IngestRun> */
    protected $model = IngestRun::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'source' => 'fixture',
            'mode' => IngestModeEnum::BULK,
            'file_hash' => null,
            'started_at' => '2026-08-06 08:00:00',
            'finished_at' => '2026-08-06 08:05:00',
            'rows_inserted' => 0,
            'rows_updated' => 0,
            'status' => IngestStatusEnum::COMPLETED,
            'checkpoint' => null,
            'error' => null,
        ];
    }
}
