<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bez HasFactory záměrně — v plánu 1 do intraday_bars žádný task nenalévá data,
 * takže factory by neměla co obsluhovat. Přijde s adaptérem intradenního dumpu.
 *
 * @property string $instrument_id
 * @property CarbonImmutable $ts
 * @property float $open
 * @property float $high
 * @property float $low
 * @property float $close
 * @property int $volume
 * @property string $source
 */
class IntradayBar extends Model
{
    /** @var string */
    protected $table = 'intraday_bars';

    /** @var bool */
    public $incrementing = false;

    /** @var bool */
    public $timestamps = false;

    protected $primaryKey = null;

    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'ts' => 'immutable_datetime',
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
}
