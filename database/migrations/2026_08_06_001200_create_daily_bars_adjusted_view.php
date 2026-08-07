<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * View je jediné místo, kde žije aplikace adjustment vzorce. Specifikace požaduje,
     * aby Python adjustment logiku neimplementoval; kdyby byl vzorec v DuckDB dotazu,
     * byl by to druhý výskyt téhož vzorce a mohl by se rozejít. Ve view existuje
     * jednou, vlastní ho tato migrace, a export z něj jen čte.
     *
     * LEFT JOIN s COALESCE je důsledek toho, že adjustment_factors obsahuje jen
     * řádky s faktorem různým od 1.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW daily_bars_adjusted AS
            SELECT
                b.instrument_id,
                b.date,
                b.open, b.high, b.low, b.close, b.volume,
                b.open  / COALESCE(f.cum_split_factor, 1) * COALESCE(f.cum_div_factor, 1) AS adj_open,
                b.high  / COALESCE(f.cum_split_factor, 1) * COALESCE(f.cum_div_factor, 1) AS adj_high,
                b.low   / COALESCE(f.cum_split_factor, 1) * COALESCE(f.cum_div_factor, 1) AS adj_low,
                b.close / COALESCE(f.cum_split_factor, 1) * COALESCE(f.cum_div_factor, 1) AS adj_close,
                (b.volume * COALESCE(f.cum_split_factor, 1))::bigint AS adj_volume,
                COALESCE(f.cum_split_factor, 1) AS cum_split_factor,
                COALESCE(f.cum_div_factor, 1) AS cum_div_factor,
                b.source
            FROM daily_bars AS b
            LEFT JOIN adjustment_factors AS f
                ON f.instrument_id = b.instrument_id AND f.date = b.date
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS daily_bars_adjusted');
    }
};
