<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        // Laravel Schema builder partitionované tabulky neumí — proto DB::statement.
        // Partition key `date` musí být součástí primárního klíče, Postgres to vyžaduje.
        // (instrument_id, date) to splňuje a je zároveň klíč, po kterém se dotazuje.
        DB::statement(<<<'SQL'
            CREATE TABLE daily_bars (
                instrument_id uuid NOT NULL REFERENCES instruments(id) ON DELETE CASCADE,
                date date NOT NULL,
                open numeric(14,6) NOT NULL,
                high numeric(14,6) NOT NULL,
                low numeric(14,6) NOT NULL,
                close numeric(14,6) NOT NULL,
                volume bigint NOT NULL,
                source varchar(32) NOT NULL,
                ingested_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (instrument_id, date)
            ) PARTITION BY RANGE (date)
        SQL);

        DB::statement('CREATE INDEX daily_bars_date_index ON daily_bars (date)');
        DB::statement('CREATE INDEX daily_bars_instrument_id_index ON daily_bars (instrument_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS daily_bars');
    }
};
