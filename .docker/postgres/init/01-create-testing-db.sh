#!/bin/bash
# POSTGRES_DB založí jen jednu databázi. Testová sada podle php-testing.md potřebuje
# vlastní databázi, aby běh testů nikdy nesáhl na vývojová data.
set -euo pipefail

psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
	CREATE DATABASE ${POSTGRES_DB}_testing OWNER $POSTGRES_USER;
SQL
