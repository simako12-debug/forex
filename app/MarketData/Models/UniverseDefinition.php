<?php

declare(strict_types=1);

namespace App\MarketData\Models;

use App\MarketData\Data\UniverseRulesData;
use Database\Factories\UniverseDefinitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property int $version
 * @property UniverseRulesData $rules
 */
class UniverseDefinition extends Model
{
    use HasUuids;

    /** @use HasFactory<UniverseDefinitionFactory> */
    use HasFactory;

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'version' => 'integer',
        'rules' => UniverseRulesData::class,
    ];

    /** @return HasMany<UniverseMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(related: UniverseMember::class, foreignKey: 'definition_id');
    }

    /** Viz Instrument::newFactory() — factory nesedí na výchozí konvenci Laravelu. */
    protected static function newFactory(): UniverseDefinitionFactory
    {
        return UniverseDefinitionFactory::new();
    }
}
