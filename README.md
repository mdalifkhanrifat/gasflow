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

| Role                  | Login | Purpose                                                                                  |
| --------------------- | ----- | ---------------------------------------------------------------------------------------- |
| `gasflow_owner`       | yes   | Owns the database and schema; runs migrations                                            |
| `gasflow_app`         | yes   | Runtime role: owns nothing, no `BYPASSRLS`, no `TRUNCATE`, so row-level security applies |
| `gasflow_maintenance` | no    | Group required for the audited backend maintenance override                              |
| `gasflow_operator`    | yes   | Member of `gasflow_maintenance`; created only when `DB_OPERATOR_PASSWORD` is set         |

The init script also creates the `gasflow_testing` database and the
`pgcrypto` and `btree_gist` extensions. It runs only on an empty volume. To
start over, run `docker compose down -v`. This deletes all local data.

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
make migrate                 # run the migrations
make test                    # PHP test suite
make check                   # frontend lint, format and type checks
make psql                    # psql on the app database
make queue-restart           # reload the workers after code changes
```

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
make test            # PHP test suite
make check           # frontend lint, format and type checks
composer test        # (host) Pint check, PHPStan and the test suite
```

## License

Proprietary. All rights reserved. Running GasFlow requires a valid license.
