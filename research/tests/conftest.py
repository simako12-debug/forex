"""Fixtury pro kontraktní test Parquetu.

Fixture se zakládá přímo v Pythonu přes psycopg, ne přes PHP seeder. Kontraktní
test má ověřit, že Python vidí v Parquetu totéž, co je v Postgresu — což vyžaduje
znát očekávané hodnoty nezávisle na PHP straně. Tabulky samotné vytvořily Laravel
migrace; tenhle soubor do nich jen vloží data a zase je uklidí.
"""

import os
import sys
import tempfile

import psycopg
import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from export_metadata import export_metadata  # noqa: E402
from export_parquet import export_year  # noqa: E402

YEAR = 2019
INSTRUMENT = "550e8400-e29b-41d4-a716-446655440000"
BARS = [
    ("2019-03-13", 100.0, 1_000_000),
    ("2019-03-14", 110.0, 2_000_000),
    ("2019-03-15", 120.0, 3_000_000),
]
SPLIT_FACTOR = 4.0
DIV_FACTOR = 0.99

UNIVERSE_DEFINITION_ID = "660e8400-e29b-41d4-a716-446655440000"
UNIVERSE_NAME = "liquid_us"
UNIVERSE_VERSION = 1
MARKET_EXCHANGE = "XNYS"


def dsn() -> str:
    host = os.environ.get("DB_HOST", "postgres")
    port = os.environ.get("DB_PORT", "5432")
    database = os.environ.get("DB_TESTING_DATABASE", "forx_testing")
    user = os.environ.get("DB_USERNAME", "forx")
    password = os.environ.get("DB_PASSWORD", "forx")

    return f"host={host} port={port} dbname={database} user={user} password={password}"


@pytest.fixture(scope="session")
def seeded_database():
    with psycopg.connect(dsn()) as connection:
        with connection.cursor() as cursor:
            _clear(cursor)
            _seed(cursor)
        connection.commit()

    yield

    with psycopg.connect(dsn()) as connection:
        with connection.cursor() as cursor:
            _clear(cursor)
        connection.commit()


def _clear(cursor) -> None:
    cursor.execute("DELETE FROM adjustment_factors")
    cursor.execute("DELETE FROM daily_bars")
    cursor.execute("DELETE FROM universe_members")
    cursor.execute("DELETE FROM universe_definitions")
    cursor.execute("DELETE FROM market_days")
    cursor.execute("DELETE FROM instrument_symbols")
    cursor.execute("DELETE FROM instruments")


def _seed(cursor) -> None:
    cursor.execute(
        f"CREATE TABLE IF NOT EXISTS daily_bars_{YEAR} PARTITION OF daily_bars "
        f"FOR VALUES FROM ('{YEAR}-01-01') TO ('{YEAR + 1}-01-01')"
    )
    cursor.execute(
        "INSERT INTO instruments (id, name, asset_class, primary_exchange, listed_at, delisted_at, "
        "created_at, updated_at) "
        "VALUES (%s, 'Contract Fixture', 'us_equity', 'NYSE', %s, NULL, now(), now())",
        (INSTRUMENT, BARS[0][0]),
    )

    for date, close, volume in BARS:
        cursor.execute(
            "INSERT INTO daily_bars (instrument_id, date, open, high, low, close, volume, source) "
            "VALUES (%s, %s, %s, %s, %s, %s, %s, 'contract')",
            (INSTRUMENT, date, close, close, close, close, volume),
        )

    # Faktor jen na prvním dni — ověří i větev, kde adjustment_factors řádek nemá
    # a COALESCE ve view musí dát 1.
    cursor.execute(
        "INSERT INTO adjustment_factors (instrument_id, date, cum_split_factor, cum_div_factor) "
        "VALUES (%s, %s, %s, %s)",
        (INSTRUMENT, BARS[0][0], SPLIT_FACTOR, DIV_FACTOR),
    )

    cursor.execute(
        "INSERT INTO universe_definitions (id, name, version, rules, created_at, updated_at) "
        "VALUES (%s, %s, %s, %s, now(), now())",
        (UNIVERSE_DEFINITION_ID, UNIVERSE_NAME, UNIVERSE_VERSION, '{}'),
    )

    for date, _, _ in BARS:
        cursor.execute(
            "INSERT INTO universe_members (definition_id, date, instrument_id) VALUES (%s, %s, %s)",
            (UNIVERSE_DEFINITION_ID, date, INSTRUMENT),
        )
        cursor.execute(
            "INSERT INTO market_days (exchange, date, is_open, is_early_close, created_at, updated_at) "
            "VALUES (%s, %s, true, false, now(), now())",
            (MARKET_EXCHANGE, date),
        )


@pytest.fixture(scope="session")
def exported_parquet_path(seeded_database):
    with tempfile.TemporaryDirectory() as directory:
        out_path = os.path.join(directory, f"year={YEAR}", "part.parquet")
        export_year(YEAR, out_path, dsn())

        yield out_path


@pytest.fixture(scope="session")
def exported_metadata_dir(seeded_database):
    """Vyexportuje metadatové Parquety stejnou cestou jako produkční příkaz.

    Jediný způsob, jak zachytit rozejití SQL v export_metadata.py a sloupců,
    které panel.py čte natvrdo — bez toho by přejmenování sloupce nechalo
    testy zelené.
    """
    with tempfile.TemporaryDirectory() as directory:
        export_metadata(directory, dsn())

        yield directory


@pytest.fixture(scope="session")
def expected_rows() -> int:
    return len(BARS)


@pytest.fixture(scope="session")
def expected_close_sum() -> float:
    first = BARS[0][1] / SPLIT_FACTOR * DIV_FACTOR

    return first + sum(close for _, close, _ in BARS[1:])
