<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use App\MarketData\Enums\IngestModeEnum;
use App\MarketData\Enums\IngestStatusEnum;
use Carbon\CarbonImmutable;
use Database\Factories\IngestRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $source
 * @property IngestModeEnum $mode
 * @property null|string $file_hash
 * @property CarbonImmutable $started_at
 * @property null|CarbonImmutable $finished_at
 * @property int $rows_inserted
 * @property int $rows_updated
 * @property IngestStatusEnum $status
 * @property null|array<string,mixed> $checkpoint
 * @property null|string $error
 */
class IngestRun extends Model
{
    use HasUuids;

    /** @use HasFactory<IngestRunFactory> */
    use HasFactory;

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'mode' => IngestModeEnum::class,
        'status' => IngestStatusEnum::class,
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
        'checkpoint' => 'array',
    ];

    /**
     * Základ idempotence bulk importu. Jen dokončený běh blokuje reimport —
     * spadlý běh se musí dát zopakovat.
     */
    public static function completedForFileHash(string $hash): bool
    {
        return self::query()
            ->where('file_hash', $hash)
            ->where('status', IngestStatusEnum::COMPLETED)
            ->exists();
    }

    /** @return HasMany<ValidationFinding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(related: ValidationFinding::class);
    }

    /** Viz Instrument::newFactory() — factory nesedí na výchozí konvenci Laravelu. */
    protected static function newFactory(): IngestRunFactory
    {
        return IngestRunFactory::new();
    }
}
