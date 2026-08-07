FROM php:8.5-cli

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libicu-dev unzip git \
    && docker-php-ext-install pdo_pgsql zip intl bcmath \
    && rm -rf /var/lib/apt/lists/*

# Parquet nezapisuje PHP — export je DuckDB skript, který ParquetExporter spouští
# jako podproces. Musí tedy být ve stejném kontejneru jako PHP. Kontejner `research`
# zůstává pro Python výzkumnou práci (pandas, pytest, kontraktní testy).
RUN apt-get update && apt-get install -y --no-install-recommends python3 python3-pip \
    && pip3 install --no-cache-dir --break-system-packages duckdb \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd -g ${GID} app && useradd -u ${UID} -g ${GID} -m app

# Prázdný pojmenovaný volume dědí vlastnictví z adresáře v image. Bez tohoto kroku
# vznikne /shared jako root:root a kontejner běžící pod `app` do něj nezapíše.
RUN mkdir -p /shared && chown ${UID}:${GID} /shared

USER app
WORKDIR /app
