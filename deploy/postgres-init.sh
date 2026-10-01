#!/bin/bash
# Runs once, on the first start of an empty data volume. Same model as local dev:
# the app connects as `engage` — owner of its database, NOT superuser, NOT BYPASSRLS,
# so row-level security is always enforced.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<-EOSQL
	CREATE ROLE engage LOGIN PASSWORD '${ENGAGE_DB_PASSWORD}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
	CREATE DATABASE engage OWNER engage;
EOSQL
