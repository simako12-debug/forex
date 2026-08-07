<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DailyBarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $instrument_id
 * @property CarbonImmutable $date
 * @property float $open
 * @property float $high
 * @property float $low
 * @property float $close
 * @property int $volume
 * @property string $source
 */
class DailyBar extends Model
{
    /** @use HasFactory<DailyBarFactory> */
    use HasFactory;

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
        'open' => 'float',
        'high' => 'float',
        'low' => 'float',
        'close' => 'float',
        'volume' => 'integer',
    ];

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(related: Instrument::class);
    }

    /** Viz Instrument::newFactory() — factory nesedí na výchozí konvenci Laravelu. */
    protected static function newFactory(): DailyBarFactory
    {
        return DailyBarFactory::new();
    }
}
