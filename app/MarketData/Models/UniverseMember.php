<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $definition_id
 * @property CarbonImmutable $date
 * @property string $instrument_id
 */
class UniverseMember extends Model
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
    ];

    /** @return BelongsTo<UniverseDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(related: UniverseDefinition::class, foreignKey: 'definition_id');
    }

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(related: Instrument::class);
    }
}
