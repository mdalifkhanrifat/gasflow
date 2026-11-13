#!/bin/sh
# Creates the GasFlow database roles and databases.
# Runs once, when the postgres data volume is empty (docker-entrypoint-initdb.d).
#
#   gasflow_owner        LOGIN. Owns the databases and the public schema. Used only to run
#                        migrations.
#   gasflow_app          LOGIN. Runtime role for web, queues and scheduler. Owns nothing,
#                        has no BYPASSRLS and no TRUNCATE, so row-level security and the
#                        lifecycle delete triggers always apply.
#   gasflow_maintenance  NOLOGIN group. Membership is required for the backend maintenance
#                        override (see the record lifecycle standard). Never granted to
#                        gasflow_app.
#   gasflow_operator     LOGIN, member of gasflow_maintenance. Created only when
#                        GASFLOW_OPERATOR_PASSWORD is set (operator machines only).
set -eu

: "${GASFLOW_DB:?GASFLOW_DB is required}"
: "${GASFLOW_TEST_DB:?GASFLOW_TEST_DB is required}"
: "${GASFLOW_OWNER_PASSWORD:?GASFLOW_OWNER_PASSWORD is required}"
: "${GASFLOW_APP_PASSWORD:?GASFLOW_APP_PASSWORD is required}"

psql_su() {
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --no-password "$@"
}

echo "gasflow: creating roles"
psql_su --dbname postgres \
    -v owner_pw="$GASFLOW_OWNER_PASSWORD" \
    -v app_pw="$GASFLOW_APP_PASSWORD" <<'SQL'
CREATE ROLE gasflow_owner LOGIN PASSWORD :'owner_pw'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE gasflow_app LOGIN PASSWORD :'app_pw'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE gasflow_maintenance NOLOGIN
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
SQL

if [ -n "${GASFLOW_OPERATOR_PASSWORD:-}" ]; then
    echo "gasflow: creating operator login (member of gasflow_maintenance)"
    psql_su --dbname postgres -v op_pw="$GASFLOW_OPERATOR_PASSWORD" <<'SQL'
CREATE ROLE gasflow_operator LOGIN PASSWORD :'op_pw' IN ROLE gasflow_maintenance
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
SQL
fi

for db in "$GASFLOW_DB" "$GASFLOW_TEST_DB"; do
    echo "gasflow: creating database $db"
    psql_su --dbname postgres -v db="$db" <<'SQL'
CREATE DATABASE :"db" OWNER gasflow_owner ENCODING 'UTF8';
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"db" TO gasflow_app, gasflow_maintenance;
ALTER DATABASE :"db" SET timezone TO 'UTC';
SQL

    psql_su --dbname "$db" <<'SQL'
-- Extensions need superuser on first install; the migrations only use IF NOT EXISTS.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- Only the owner may create objects in the schema.
ALTER SCHEMA public OWNER TO gasflow_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO gasflow_app, gasflow_maintenance;

-- Everything the owner creates later is usable by the runtime and maintenance roles.
-- No TRUNCATE: it would skip the lifecycle delete triggers.
ALTER DEFAULT PRIVILEGES FOR ROLE gasflow_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO gasflow_app, gasflow_maintenance;
ALTER DEFAULT PRIVILEGES FOR ROLE gasflow_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO gasflow_app, gasflow_maintenance;
ALTER DEFAULT PRIVILEGES FOR ROLE gasflow_owner IN SCHEMA public
    GRANT EXECUTE ON FUNCTIONS TO gasflow_app, gasflow_maintenance;
SQL
done

echo "gasflow: roles and databases ready"
