<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Symbols;

use App\MarketData\Models\Instrument;
use App\MarketData\Symbols\SymbolResolver;
use App\MarketData\Symbols\UnknownSymbolException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(SymbolResolver::class)]
final class SymbolResolverTest extends TestCase
{
    use RefreshDatabase;

    public function testResolve(): void
    {
        $instrument = $this->instrumentWithSymbol(
            '550e8400-e29b-41d4-a716-446655440000',
            'AAPL',
            '2000-01-03',
            null,
        );

        $resolved = App::make(SymbolResolver::class)->resolve('AAPL', CarbonImmutable::parse('2019-03-15'));

        $this->assertSame($instrument->id, $resolved?->id);
    }

    public function testResolveRecycledSymbol(): void
    {
        $old = $this->instrumentWithSymbol(
            '550e8400-e29b-41d4-a716-446655440000',
            'XYZ',
            '2000-01-03',
            '2012-06-30',
        );
        $new = $this->instrumentWithSymbol(
            '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            'XYZ',
            '2015-01-05',
            null,
        );

        $resolver = App::make(SymbolResolver::class);

        $this->assertSame(
            $old->id,
            $resolver->resolve('XYZ', CarbonImmutable::parse('2010-05-04'))?->id,
        );
        $this->assertSame(
            $new->id,
            $resolver->resolve('XYZ', CarbonImmutable::parse('2020-05-04'))?->id,
        );
    }

    public function testResolveGapBetweenOwners(): void
    {
        $this->instrumentWithSymbol('550e8400-e29b-41d4-a716-446655440000', 'XYZ', '2000-01-03', '2012-06-30');
        $this->instrumentWithSymbol('6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'XYZ', '2015-01-05', null);

        $resolved = App::make(SymbolResolver::class)->resolve('XYZ', CarbonImmutable::parse('2013-08-08'));

        $this->assertNull($resolved);
    }

    public function testResolveUnknownSymbol(): void
    {
        $resolved = App::make(SymbolResolver::class)->resolve('NOPE', CarbonImmutable::parse('2019-03-15'));

        $this->assertNull($resolved);
    }

    public function testResolveOrFailUnknownSymbolExceptionThrow(): void
    {
        $this->expectException(UnknownSymbolException::class);
        $this->expectExceptionMessage('NOPE');

        App::make(SymbolResolver::class)->resolveOrFail('NOPE', CarbonImmutable::parse('2019-03-15'));
    }

    private function instrumentWithSymbol(
        string $id,
        string $symbol,
        string $validFrom,
        null|string $validTo,
    ): Instrument {
        $instrument = Instrument::factory()->create(['id' => $id]);
        $instrument->symbols()->create([
            'symbol' => $symbol,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ]);

        return $instrument;
    }
}
