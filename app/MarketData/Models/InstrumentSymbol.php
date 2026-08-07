<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use Carbon\CarbonImmutable;
use Database\Factories\InstrumentSymbolFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $instrument_id
 * @property string $symbol
 * @property CarbonImmutable $valid_from
 * @property null|CarbonImmutable $valid_to
 */
class InstrumentSymbol extends Model
{
    use HasUuids;

    /** @use HasFactory<InstrumentSymbolFactory> */
    use HasFactory;

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'valid_from' => 'immutable_date',
        'valid_to' => 'immutable_date',
    ];

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(related: Instrument::class);
    }

    /** Viz Instrument::newFactory() — factory nesedí na výchozí konvenci Laravelu. */
    protected static function newFactory(): InstrumentSymbolFactory
    {
        return InstrumentSymbolFactory::new();
    }
}
