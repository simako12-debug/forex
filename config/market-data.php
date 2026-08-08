<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sdílená cesta
    |--------------------------------------------------------------------------
    |
    | Kam se zapisují Parquet snapshoty pro Python. V Dockeru je to pojmenovaný
    | volume namontovaný do app, worker i research.
    |
    */

    'shared_path' => env('MARKET_DATA_SHARED_PATH', storage_path('shared')),

    /*
    |--------------------------------------------------------------------------
    | Export Parquetu
    |--------------------------------------------------------------------------
    |
    | Export provádí DuckDB skript, ne PHP. Binárka i cesta ke skriptu jsou
    | konfigurovatelné, aby šel exporter otestovat i mimo kontejner.
    |
    */

    'python_binary' => env('MARKET_DATA_PYTHON_BINARY', 'python3'),

    'export_script' => env('MARKET_DATA_EXPORT_SCRIPT', base_path('research/export_parquet.py')),

    'metadata_script' => env('MARKET_DATA_METADATA_SCRIPT', base_path('research/export_metadata.py')),

];
