<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

final class SmokeTest extends TestCase
{
    public function testEnvironment(): void
    {
        $this->assertSame('testing', App::environment());
    }

    /**
     * Nad rámec plánu. Testy běží proti reálnému Postgresu, takže záměna databáze
     * není kosmetická — RefreshDatabase v pozdějších tascích by vývojová data smazal.
     * Proměnná z prostředí navíc phpunit.xml přebíjí, pokud chybí force="true".
     */
    public function testDatabaseIsSeparateFromDevelopment(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('forx_testing', config('database.connections.pgsql.database'));
    }
}
