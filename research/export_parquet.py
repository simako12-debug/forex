"""Vyexportuje jeden rok denních barů z Postgresu do Parquetu pomocí DuckDB."""

import argparse
import os
import sys

import duckdb


def export_year(year: int, out_path: str, dsn: str) -> int:
    tmp_path = f"{out_path}.tmp"
    directory = os.path.dirname(out_path)

    if directory:
        os.makedirs(directory, exist_ok=True)

    con = duckdb.connect()
    con.execute("INSTALL postgres; LOAD postgres;")
    con.execute(f"ATTACH '{dsn}' AS pg (TYPE POSTGRES, READ_ONLY)")
    con.execute(
        f"""
        COPY (
            SELECT * FROM pg.public.daily_bars_adjusted
            WHERE date >= DATE '{year}-01-01' AND date < DATE '{year + 1}-01-01'
            ORDER BY instrument_id, date
        ) TO '{tmp_path}' (FORMAT PARQUET, COMPRESSION ZSTD)
        """
    )
    rows = con.execute(f"SELECT count(*) FROM read_parquet('{tmp_path}')").fetchone()[0]
    con.close()

    # os.replace je atomický přesun — Python čtoucí data nikdy neuvidí rozepsaný soubor.
    os.replace(tmp_path, out_path)
    return int(rows)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--year", type=int, required=True)
    parser.add_argument("--out", required=True)
    parser.add_argument("--dsn", required=True)
    args = parser.parse_args()

    print(export_year(args.year, args.out, args.dsn))
    return 0


if __name__ == "__main__":
    sys.exit(main())
