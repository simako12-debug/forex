<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use App\MarketData\Enums\FindingSeverityEnum;
use Carbon\CarbonImmutable;
use Database\Factories\ValidationFindingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $ingest_run_id
 * @property null|string $instrument_id
 * @property null|CarbonImmutable $date
 * @property string $rule
 * @property FindingSeverityEnum $severity
 * @property string $detail
 */
class ValidationFinding extends Model
{
    use HasUuids;

    /** @use HasFactory<ValidationFindingFactory> */
    use HasFactory;

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'severity' => FindingSeverityEnum::class,
        'date' => 'immutable_date',
    ];

    /** @return BelongsTo<IngestRun, $this> */
    public function ingestRun(): BelongsTo
    {
        return $this->belongsTo(related: IngestRun::class);
    }

    /** Viz Instrument::newFactory() — factory nesedí na výchozí konvenci Laravelu. */
    protected static function newFactory(): ValidationFindingFactory
    {
        return ValidationFindingFactory::new();
    }
}
