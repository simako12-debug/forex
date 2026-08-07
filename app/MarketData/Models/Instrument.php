<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use Carbon\CarbonImmutable;
use Database\Factories\InstrumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string $asset_class
 * @property string $primary_exchange
 * @property null|string $sector
 * @property null|CarbonImmutable $listed_at
 * @property null|CarbonImmutable $delisted_at
 * @property null|string $delisting_reason
 */
class Instrument extends Model
{
    use HasUuids;

    /** @use HasFactory<InstrumentFactory> */
    use HasFactory;

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'listed_at' => 'immutable_date',
        'delisted_at' => 'immutable_date',
    ];

    /** @return HasMany<InstrumentSymbol, $this> */
    public function symbols(): HasMany
    {
        return $this->hasMany(related: InstrumentSymbol::class);
    }

    /**
     * Laravel by podle konvence hledal Database\Factories\MarketData\Models\InstrumentFactory.
     * Plán factory umístil rovnou do Database\Factories, takže je potřeba ji ukázat explicitně.
     */
    protected static function newFactory(): InstrumentFactory
    {
        return InstrumentFactory::new();
    }
}
