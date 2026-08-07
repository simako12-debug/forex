<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Zatím jen prázdná třída, aby na ní mohl stát EloquentMatcher a jeho test.
 * Migrace a plná definice přijdou v Tasku 3; tento model nesahá na databázi.
 *
 * @property string $id
 */
class Instrument extends Model
{
    /** @var string */
    protected $keyType = 'string';
}
