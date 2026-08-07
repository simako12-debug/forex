<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MarketDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $exchange
 * @property CarbonImmutable $date
 * @property bool $is_open
 * @property null|string $open_at
 * @property null|string $close_at
 * @property bool $is_early_close
 */
class MarketDay extends Model
{
    /** @use HasFactory<MarketDayFactory> */
    use HasFactory;

    /** @var bool */
    public $incrementing = false;

    protected $primaryKey = null;

    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'date' => 'immutable_date',
        'is_open' => 'boolean',
        'is_early_close' => 'boolean',
    ];

    public static function isTradingDay(string $exchange, CarbonImmutable $date): bool
    {
        return self::query()
            ->where('exchange', $exchange)
            ->where('date', $date->toDateString())
            ->where('is_open', true)
            ->exists();
    }

    /** Viz Instrument::newFactory() — factory nesedí na výchozí konvenci Laravelu. */
    protected static function newFactory(): MarketDayFactory
    {
        return MarketDayFactory::new();
    }
}
