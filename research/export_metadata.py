"""Vyexportuje metadatové tabulky z Postgresu do Parquetu pomocí DuckDB.

Metadata jsou malá (jednotky MB), takže se exportují celá, bez dělení po letech.
Univerzum se exportuje rozbalené o jméno a verzi definice, aby Python nemusel
dělat join — snapshot má být čitelný sám o sobě.
"""

import argparse
import json
import os
import sys

import duckdb

QUERIES = {
    "instruments": """
        SELECT id, name, asset_class, primary_exchange, sector,
               listed_at, delisted_at, delisting_reason
        FROM pg.public.instruments
        ORDER BY id
    """,
    "universe_members": """
        SELECT d.name AS definition_name, d.version AS definition_version,
               m.date, m.instrument_id
        FROM pg.public.universe_members AS m
        JOIN pg.public.universe_definitions AS d ON d.id = m.definition_id
        ORDER BY d.name, d.version, m.date, m.instrument_id
    """,
    "market_days": """
        SELECT exchange, date, is_open, is_early_close
        FROM pg.public.market_days
        ORDER BY exchange, date
    """,
}


def export_metadata(out_dir: str, dsn: str) -> dict[str, int]:
    """Vyexportuje všechny metadatové tabulky do out_dir a vrátí počty řádků po tabulkách."""
    os.makedirs(out_dir, exist_ok=True)

    con = duckdb.connect()
    con.execute("INSTALL postgres; LOAD postgres;")
    con.execute(f"ATTACH '{dsn}' AS pg (TYPE POSTGRES, READ_ONLY)")

    counts: dict[str, int] = {}

    for table, query in QUERIES.items():
        out_path = os.path.join(out_dir, f"{table}.parquet")
        tmp_path = f"{out_path}.tmp"

        con.execute(f"COPY ({query}) TO '{tmp_path}' (FORMAT PARQUET, COMPRESSION ZSTD)")
        counts[table] = int(
            con.execute(f"SELECT count(*) FROM read_parquet('{tmp_path}')").fetchone()[0]
        )
        os.replace(tmp_path, out_path)

    con.close()

    return counts


def main() -> int:
    """Zpracuje argumenty příkazové řádky a vypíše JSON s počty exportovaných řádků."""
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", required=True)
    parser.add_argument("--dsn", required=True)
    args = parser.parse_args()

    print(json.dumps(export_metadata(args.out, args.dsn)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
