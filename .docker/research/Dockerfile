FROM python:3.13-slim

ARG UID=1000
ARG GID=1000

RUN pip install --no-cache-dir duckdb pyarrow pandas numpy pytest ruff mypy pandas-stubs psycopg[binary]

RUN groupadd -g ${GID} app && useradd -u ${UID} -g ${GID} -m app

# Viz .docker/app/Dockerfile — /shared musí patřit `app` už v image.
RUN mkdir -p /shared && chown ${UID}:${GID} /shared

USER app
WORKDIR /app
