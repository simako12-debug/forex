<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $instrument_id
 * @property CarbonImmutable $date
 * @property float $cum_split_factor
 * @property float $cum_div_factor
 */
class AdjustmentFactor extends Model
{
    /** @var bool */
    public $incrementing = false;

    /** @var bool */
    public $timestamps = false;

    protected $primaryKey = null;

    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'date' => 'immutable_date',
        'cum_split_factor' => 'float',
        'cum_div_factor' => 'float',
    ];

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(related: Instrument::class);
    }
}
