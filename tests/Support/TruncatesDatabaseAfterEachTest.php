<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Illuminate\Foundation\Testing\DatabaseTruncation trunká tabulky jen v setUp(),
 * tedy PŘED každým testem — nikdy po něm. Poslední instance takové testovací
 * třídy v celé sadě tak zanechá zacommitované řádky, které kolidují s navazujícími
 * RefreshDatabase testy (ty běží v transakci nad tím, co v DB už leží, ne nad
 * čistou databází). Přesně tohle způsobilo regresi 37 padajících testů v Tasku 3.
 *
 * Každá testovací třída používající DatabaseTruncation musí tento trait použít
 * vedle něj, aby po sobě uklidila strukturálně — ne jako konvenci, kterou si
 * musí každý autor pamatovat a kopírovat do vlastního tearDown().
 *
 * Metoda se jmenuje `tearDown<TraitBasename>` záměrně: Laravelí setUpTraits()
 * (Illuminate\Foundation\Testing\Concerns\InteractsWithTestCaseLifecycle) takovou
 * metodu automaticky najde a zaregistruje přes beforeApplicationDestroyed() — tedy
 * se spustí PŘED $this->app->flush(), na rozdíl od PHPUnit atributu #[After], který
 * běží až po Laravelím tearDown() (kdy už je $this->app zahozené a truncateDatabaseTables()
 * by spadlo na volání App::make() na null).
 */
trait TruncatesDatabaseAfterEachTest
{
    protected function tearDownTruncatesDatabaseAfterEachTest(): void
    {
        $this->truncateDatabaseTables();
    }
}
