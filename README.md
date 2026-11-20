# GasFlow

GasFlow is a multi-tenant SaaS platform for pipeline (reticulated) gas billing in
apartment buildings. It covers the whole cycle in one place:

meter readings → reconciliation → vendor tariffs → invoices → payments
(bKash, Nagad, SSLCommerz, cash) → double-entry ledger.

## Main features

- **Multi-tenant**: every organization (gas vendor, building management) sees
  only its own data. Access is enforced by policies and by PostgreSQL row-level
  security.
- **Buildings, flats, customers**: ownership history, occupancy and handover,
  vendor assignments.
- **Metering**: main and sub meters, reading periods, approvals, revisions, and
  a mobile reading screen for caretakers that also works offline.
- **Billing**: versioned tariffs, billing cycles, invoice snapshots, credit
  notes and disputes.
- **Payments and ledger**: immutable double-entry journals; corrections are
  made with reversals, never by editing history.
- **Record lifecycle**: every record has `status`, `is_active`, `is_deleted` and
  a full change trail (who, when, which device, where).
- **Bangla and English** user interface, installable as a PWA.
- **Licensing**: every installation needs a signed license.

## Tech stack

| Layer                 | Technology                                   |
| --------------------- | -------------------------------------------- |
| Backend               | PHP 8.5, Laravel 13                          |
| Frontend              | Vue 3, TypeScript, Inertia 3, Tailwind CSS 4 |
| Database              | PostgreSQL 18                                |
| Cache, queue, session | Redis 8                                      |
| Web server            | Nginx 1.30                                   |
| Tests and quality     | Pest, Larastan, Pint, vue-tsc                |

## Project structure

[docs/project-structure.md](docs/project-structure.md) shows where every kind of
file lives (modules, actions, models, migrations, tests) and the conventions to
follow when adding code.

## Development with Docker

Docker is the supported development environment. Development uses the same
services as production.

Requirements: Docker Engine with Compose v2, and `make`.

```bash
make setup   # create .env, build images, start services, install, migrate
```

`make setup` creates `.env` from `.env.example` with random passwords. You can
also run `make env` by itself and edit the values before the first start.

Open the app at <http://localhost:8047>. Mail sent by the app appears in Mailpit
at <http://localhost:8247>.

### Services

| Service          | Image                                | Purpose                                       |
| ---------------- | ------------------------------------ | --------------------------------------------- |
| `app`            | `docker/php/Dockerfile` (target dev) | PHP 8.5 FPM                                   |
| `nginx`          | `nginx:1.30-alpine`                  | Web server, security headers                  |
| `postgres`       | `postgres:18.7-alpine`               | Database with separate owner and app roles    |
| `redis`          | `redis:8-alpine`                     | Cache, queue and session (password protected) |
| `queue-default`  | same as `app`                        | Worker for the `default` queue                |
| `queue-critical` | same as `app`                        | Worker for the `critical` queue (payments)    |
| `scheduler`      | same as `app`                        | `schedule:work`                               |
| `node`           | `app` image plus Node.js 26 LTS      | Vite dev server with hot reload (dev profile) |
| `mailpit`        | `axllent/mailpit`                    | Catches outgoing mail (dev profile)           |

`node_modules` for the `node` service lives in a Docker volume, so it never
mixes with a `node_modules` folder on your machine.

### Ports

Host ports are set in `.env`. The defaults use a GasFlow-only block (all ending
in 47), so they do not clash with other local projects. Change one if it is
already taken on your machine, then run `make up` again.

| Variable                 | Default | Service           |
| ------------------------ | ------- | ----------------- |
| `APP_PORT`               | 8047    | nginx             |
| `VITE_PORT`              | 5147    | Vite (hot reload) |
| `DB_FORWARD_PORT`        | 5447    | PostgreSQL        |
| `REDIS_FORWARD_PORT`     | 6347    | Redis             |
| `MAILPIT_DASHBOARD_PORT` | 8247    | Mailpit           |

`APP_URL` must use the same port as `APP_PORT`
(`APP_URL=http://localhost:8047`). PostgreSQL, Redis, Vite and Mailpit only listen
on `127.0.0.1`.

### Database roles

The database is initialised by `docker/postgres/init` the first time the
`postgres` volume is created:

| Role                  | Login | Purpose                                                                                                                       |
| --------------------- | ----- | ----------------------------------------------------------------------------------------------------------------------------- |
| `gasflow_owner`       | yes   | Owns the database and schema; runs migrations                                                                                 |
| `gasflow_app`         | yes   | Runtime role: owns nothing, no `BYPASSRLS`, no `TRUNCATE`, so row-level security applies                                      |
| `gasflow_maintenance` | no    | Group required for the audited backend maintenance override                                                                   |
| `gasflow_operator`    | yes   | Member of `gasflow_maintenance`; created only when `DB_OPERATOR_PASSWORD` is set (`make env` sets it on development machines) |
| `gasflow_integrity`   | no    | `BYPASSRLS`, reads only; owns the integrity trigger functions so a check sees every row it compares                           |
| `gasflow_test`        | yes   | Test suite only: `BYPASSRLS`, member of `gasflow_app`; created only when `DB_TEST_PASSWORD` is set (development and CI)       |

Laravel has two connections. The app, queues and scheduler use `pgsql`
(`gasflow_app`). Migrations use `pgsql_migrations` (`gasflow_owner`), so always
run them with `make migrate` or `php artisan migrate --database=pgsql_migrations`.
A plain `php artisan migrate` fails on purpose, because the app role cannot
change the schema.

The init script also creates the `gasflow_testing` database and the
`pgcrypto` and `btree_gist` extensions. It runs only on an empty volume. To
start over, run `docker compose down -v`. This deletes all local data.

The integrity and test roles come from `02-gasflow-rls-roles.sh`, which can run
again: on an existing volume, `make db-roles` creates or updates them.

### Maintenance override

Deliberate manual corrections, including hiding protected money rows, go
through one command on an operator machine. It uses its own connection,
`pgsql_maintenance` (`DB_MAINTENANCE_USERNAME` / `DB_MAINTENANCE_PASSWORD`,
never set on web or queue servers):

```bash
make artisan a="gasflow:maintenance \
  --sql='delete from financial_journals where id = 1992' \
  --reason='Audit request #17: duplicate entry' --user=1"
```

It runs every statement in one transaction, checks the rules that normally wait
for COMMIT, previews each changed row and commits only after you type `APPLY`.
`--dry-run` previews and rolls back. Every changed row is recorded in
`maintenance_override_logs` and `data_change_logs`.

### License

Every installation needs a license signed by the owner's key, development
included. The private key never enters the repository: it stays in
`~/.config/gasflow/license-keys` (override with `LICENSE_KEYS=...`), sealed with
the owner's passphrase. Only the public key is committed
(`resources/licensing/public-keys.json`).

```bash
make license-keygen a="--id=kdev-2026"     # once: create the key, type a passphrase
make license a="--licensee='Your name' --days=30"
make license a="--licensee='Rahim (dev)' --valid-until=2027-03-31 \
  --output=storage/app/license/rahim.json"  # a license for another developer
```

`make license` asks for the passphrase and writes the license to
`storage/app/license/license.json` (git-ignored). Options: `--domain`,
`--limit=buildings=10`, `--feature=module.billing`, `--grace`, `--edition`. Run
`make artisan a="license:issue --help"` for the full list. Back up the key file
and keep the passphrase: without both, no new license can be signed with that key.

#### Looking after the signing key

The key file and the passphrase together can sign a license for any
installation. Either one alone is useless. Keep them apart:

1. After `make license-keygen`, save the passphrase in a password manager. Never
   put it in the repository, a chat, a log or the key folder.
2. Copy the key file (`~/.config/gasflow/license-keys/<id>.key.json`) to an
   offline backup, such as a USB drive. The backup must not hold the passphrase.
3. To sign a new license later, run `make license ...` and paste the passphrase
   when asked.
4. If the key file or the passphrase is lost, that key can sign nothing more.
   Create a new key with a new `--id`, commit its public key and issue new
   licenses. Licenses already signed stay valid.
5. If the key file and the passphrase may have leaked together, create a new key,
   remove the old key from `resources/licensing/public-keys.json`, release, and
   issue every license again with the new key.

### Common commands

```bash
make                         # list every command
make up / make down          # start / stop the services
make ps                      # service status
make logs s=queue-default    # follow the logs of one service
make shell                   # shell in the app container
make artisan a="route:list"  # run artisan
make composer a="install"    # run composer
make npm a="run build"       # run npm in the node container
make migrate                 # run the migrations (as the owner role), then prepare the trail partitions
make test                    # Pint check, PHPStan and the PHP test suite
make check                   # frontend lint, format and type checks
make psql                    # psql on the app database
make queue-restart           # reload the workers after code changes
make geoip                   # download the IP → city database (monthly, also scheduled)
```

Logs go to `storage/logs/laravel.log` in development. In production on
Docker set `LOG_STACK=json`, which writes one JSON object per line to stderr.
Every line carries the request id (`X-Request-Id`), and secrets such as
passwords, tokens and API keys are replaced with `[redacted]`.

Queue workers load the code when they start. After you change PHP code that
jobs use, run `make queue-restart`.

### Production image

The same Dockerfile has a `prod` target. That image includes the code, the
production Composer dependencies and the built frontend assets. It has no
Composer, tests or development settings:

```bash
docker build -f docker/php/Dockerfile --target prod -t gasflow-php:prod .
```

## Quality checks

```bash
make lint            # format PHP code with Pint
make test            # Pint check, PHPStan and the PHP test suite
make check           # frontend lint, format and type checks
```

## Third-party data

IP geolocation by [DB-IP](https://db-ip.com) (DB-IP City Lite, licensed under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)). The database is
downloaded into `storage/app/geoip/` by `make geoip` and is not part of the
repository. Lookups run locally; no IP address is sent to a third party.

## License

Proprietary. All rights reserved. Running GasFlow requires a valid license.
