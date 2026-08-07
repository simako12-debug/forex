<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use App\MarketData\Enums\CorporateActionTypeEnum;
use Carbon\CarbonImmutable;
use Database\Factories\CorporateActionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $instrument_id
 * @property CorporateActionTypeEnum $type
 * @property CarbonImmutable $ex_date
 * @property null|float $ratio
 * @property null|float $amount
 * @property string $source
 */
class CorporateAction extends Model
{
    use HasUuids;

    /** @use HasFactory<CorporateActionFactory> */
    use HasFactory;

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'type' => CorporateActionTypeEnum::class,
        'ex_date' => 'immutable_date',
        'ratio' => 'float',
        'amount' => 'float',
    ];

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(related: Instrument::class);
    }

    /** Viz Instrument::newFactory() — factory nesedí na výchozí konvenci Laravelu. */
    protected static function newFactory(): CorporateActionFactory
    {
        return CorporateActionFactory::new();
    }
}
