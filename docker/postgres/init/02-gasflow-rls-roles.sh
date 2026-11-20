#!/bin/sh
# Creates or updates the roles that row-level security needs (decision D52).
# Idempotent: runs once on an empty data volume (docker-entrypoint-initdb.d, after
# 01-gasflow-roles.sh) and again on an existing volume with "make db-roles".
#
#   gasflow_integrity  NOLOGIN, BYPASSRLS. Owns the integrity trigger functions
#                      (SECURITY DEFINER), so a check sees every row it compares,
#                      whatever the caller's row-level security hides. It reads only:
#                      SELECT on every table. The migrations add UPDATE on the id
#                      column of the tables whose rows a trigger locks (FOR SHARE /
#                      FOR UPDATE needs it); no function it owns writes anything.
#                      gasflow_owner is a member, so the migrations can hand functions
#                      to it and replace them later. CREATE on the schema is needed
#                      to own functions; it cannot log in to use it.
#   gasflow_test       LOGIN, BYPASSRLS, member of gasflow_app with no grants of its
#                      own. Development and CI only: created only when
#                      GASFLOW_TEST_PASSWORD is set, never on production servers. The
#                      test suite arranges rows of several organizations with it and
#                      switches to gasflow_app (SET ROLE) to test the policies.
set -eu

: "${GASFLOW_DB:?GASFLOW_DB is required}"
: "${GASFLOW_TEST_DB:?GASFLOW_TEST_DB is required}"

psql_su() {
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --no-password "$@"
}

echo "gasflow: creating or updating gasflow_integrity"
psql_su --dbname postgres <<'SQL'
DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'gasflow_integrity') THEN
    CREATE ROLE gasflow_integrity NOLOGIN;
  END IF;
END $$;
ALTER ROLE gasflow_integrity NOLOGIN BYPASSRLS
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT gasflow_integrity TO gasflow_owner;
SQL

if [ -n "${GASFLOW_TEST_PASSWORD:-}" ]; then
    echo "gasflow: creating or updating gasflow_test (development and CI only)"
    psql_su --dbname postgres -v test_pw="$GASFLOW_TEST_PASSWORD" <<'SQL'
DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'gasflow_test') THEN
    CREATE ROLE gasflow_test NOLOGIN;
  END IF;
END $$;
ALTER ROLE gasflow_test LOGIN PASSWORD :'test_pw' BYPASSRLS
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT gasflow_app TO gasflow_test;
SQL
fi

for db in "$GASFLOW_DB" "$GASFLOW_TEST_DB"; do
    echo "gasflow: integrity privileges in $db"
    psql_su --dbname "$db" <<'SQL'
GRANT USAGE, CREATE ON SCHEMA public TO gasflow_integrity;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO gasflow_integrity;
ALTER DEFAULT PRIVILEGES FOR ROLE gasflow_owner IN SCHEMA public
    GRANT SELECT ON TABLES TO gasflow_integrity;
SQL
done

echo "gasflow: row-level security roles ready"
