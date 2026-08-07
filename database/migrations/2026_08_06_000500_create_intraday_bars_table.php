<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE intraday_bars (
                instrument_id uuid NOT NULL REFERENCES instruments(id) ON DELETE CASCADE,
                ts timestamptz NOT NULL,
                open numeric(14,6) NOT NULL,
                high numeric(14,6) NOT NULL,
                low numeric(14,6) NOT NULL,
                close numeric(14,6) NOT NULL,
                volume bigint NOT NULL,
                source varchar(32) NOT NULL,
                ingested_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (instrument_id, ts)
            ) PARTITION BY RANGE (ts)
        SQL);

        DB::statement('CREATE INDEX intraday_bars_ts_index ON intraday_bars (ts)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS intraday_bars');
    }
};
