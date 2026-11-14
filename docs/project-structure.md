# Project structure

This guide says where every kind of file lives and why. GasFlow is a long-lived
product that will keep changing, so the structure follows three rules:

1. **Laravel's default layout.** Files go in Laravel's own folders (`app/Models`,
   `app/Http/Controllers`, `app/Policies`, `database/migrations`, `routes`, ...).
   Any Laravel developer finds things without a tour, and the docs, the `make:*`
   generators, auto-discovery and IDE tools all work unchanged.
2. **A domain subfolder inside each Laravel folder.** Code for one business
   area uses the same subfolder name everywhere: `Models/Billing`,
   `Http/Controllers/Billing`, `Policies/Billing`, `Actions/Billing`. One folder
   kind never appears twice.
3. **No layer without a reason.** Services, queries and data objects are added
   where they solve a real problem, not by default (see
   [When to add a layer](#when-to-add-a-layer)).

Update this file in the same commit that adds a new folder or convention.

## Top level

```text
gasflow/
├── app/                       see "Inside app/"
├── bootstrap/
│   ├── app.php                routing, middleware groups, exception mapping
│   └── providers.php          every service provider, listed by hand
├── config/                    one file per concern; env() is read only here
├── database/
│   ├── migrations/            all migrations, in dependency order (see "Database")
│   ├── factories/<Domain>/    model factories (found automatically)
│   └── seeders/
├── docker/                    images and service config (php, nginx, postgres)
├── docs/                      developer documentation (this file)
├── lang/                      bn and en translations (Phase 3)
├── resources/
│   ├── css/, js/, views/      frontend (see "Frontend")
│   └── licensing/             trusted license public keys (part of the build)
├── routes/
│   ├── web.php                web routes; includes routes/web/<domain>.php
│   ├── console.php            scheduled tasks
│   └── api.php                /api/v1 for the PWA and Flutter (Phase 3)
├── storage/                   runtime files (license, GeoIP database, logs); git-ignored
├── tests/                     see "Tests"
├── compose.yaml, Makefile     Docker stack and shortcuts (README)
└── .github/workflows/         CI on the same Docker stack
```

## Inside app/

```text
app/
├── Actions/<Domain>/            one business operation per class: Billing/IssueInvoice
├── Console/Commands/<Domain>/   artisan commands (found automatically)
├── Enums/<Domain>/              statuses and fixed lists: Billing/InvoiceStatus
├── Events/<Domain>/, Listeners/<Domain>/
├── Exceptions/<Domain>/
├── Http/
│   ├── Controllers/<Domain>/    thin: authorize, validate, call an Action, respond
│   ├── Middleware/              all HTTP middleware, in one place
│   ├── Requests/<Domain>/       form requests (validation)
│   └── Resources/<Domain>/      API resources for /api/v1
├── Jobs/<Domain>/               queued work; Jobs/Middleware for job middleware
├── Logging/                     log channel taps and processors (redaction)
├── Mail/, Notifications/
├── Models/<Domain>/             Eloquent models; Models/Concerns for model traits (HasLifecycle, HasPublicId)
├── Policies/<Domain>/           one per model (found automatically)
├── Providers/                   AppServiceProvider + one provider per domain that needs one
├── Queries/<Domain>/            read-only queries too big for a model scope
└── Services/<Domain>/           domain classes that are not a Laravel kind
```

Create a folder only when it gets its first file; an empty folder is never
committed. `App\Models\User` stays directly in `app/Models`, because Laravel
and packages expect it there.

### What is in use now

| Domain           | Files                                                                                                                                                                                                                                                                                                        |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `Licensing`      | `Services/Licensing` (verifier, state, license, `Issuing/` signer and key file), `Enums/Licensing`, `Exceptions/Licensing`, `Console/Commands/Licensing`, `Http/Middleware/EnsureLicensed`, `Jobs/Middleware/LicensedJob`, `Providers/LicensingServiceProvider`                                              |
| `Lifecycle`      | `Services/Lifecycle` (schema helper, status registry, database context, `RecycleBin/`), `Models/Concerns/HasLifecycle`, `Enums/Lifecycle`, `Exceptions/Lifecycle`, `Console/Commands/Lifecycle` (partitions, maintenance override), `Http/Middleware/LifecycleContext`, `Providers/LifecycleServiceProvider` |
| `RequestContext` | `Services/RequestContext` (device detection, IP city lookup), `Console/Commands/RequestContext`, `Http/Middleware/RequestContext`, `Providers/RequestContextServiceProvider`                                                                                                                                 |

`Lifecycle` and `RequestContext` are used by every domain: the status / is_active
/ is_deleted standard and the who / when / device / place trail.

### Domains

Each business area uses one subfolder name everywhere. Planned names:

| Domain           | Owns                                                    | Phase |
| ---------------- | ------------------------------------------------------- | ----- |
| `Identity`       | users, login, sessions, MFA, devices                    | 4     |
| `Tenancy`        | organization and building context, grants               | 4     |
| `Organizations`  | organization profiles                                   | 4     |
| `Subscriptions`  | SaaS plans, features, building subscriptions            | 5     |
| `Buildings`      | buildings, flats, ownership, vendor assignments         | 6     |
| `Customers`      | customers, occupancy                                    | 6     |
| `SystemSetup`    | setup areas, pipeline zones, setup tasks                | 6     |
| `Metering`       | meters, readings, approvals, handovers                  | 7     |
| `Reconciliation` | main/sub-meter variance                                 | 7     |
| `Supply`         | gas supply receipts                                     | 7     |
| `Tariffs`        | vendor tariff versions, charge rules, tariff resolution | 8     |
| `Billing`        | billing cycles, invoices, credit notes, disputes        | 8     |
| `Installations`  | installation agreements, EMI                            | 8     |
| `Ledger`         | double-entry journals, financial transactions           | 9     |
| `Payments`       | payments, allocations, refunds                          | 9     |
| `Integrations`   | gateway and SMS drivers, webhooks, outbox               | 10    |
| `CustomerPortal` | guest links, OTP                                        | 10    |
| `Notifications`  | templates, delivery                                     | 10    |
| `Reporting`      | statements, cashbook, trial balance                     | 11    |
| `Audit`          | audit trail screens                                     | 11    |

### Auto-discovery

Laravel finds these by name, so no registration code is needed:

- policies: `App\Models\Billing\Invoice` → `App\Policies\Billing\InvoicePolicy`
- factories: `App\Models\Billing\Invoice` → `Database\Factories\Billing\InvoiceFactory`
- commands: every command in `app/Console/Commands`, including subfolders

Generators accept the subfolder: `php artisan make:model Billing/Invoice`,
`make:policy Billing/InvoicePolicy --model=Billing/Invoice`,
`make:controller Billing/InvoiceController`.

## Where does it go?

| I am adding...                         | Put it in                                                                                 |
| -------------------------------------- | ----------------------------------------------------------------------------------------- |
| a table                                | `database/migrations/`, ending with `LifecycleSchema::attach()`                           |
| a model                                | `app/Models/<Domain>/` with `HasLifecycle` (+ `HasPublicId` if the table has `public_id`) |
| a business operation (create, approve) | `app/Actions/<Domain>/<Verb><Noun>.php`                                                   |
| a page or endpoint                     | controller + form request under `<Domain>/`, route in `routes/web/<domain>.php`           |
| a permission check                     | the model's policy in `app/Policies/<Domain>/`                                            |
| background work                        | a job in `app/Jobs/<Domain>/`                                                             |
| a scheduled task                       | `routes/console.php`, inside the licensed group unless it protects data                   |
| an artisan command                     | `app/Console/Commands/<Domain>/`                                                          |
| HTTP middleware                        | `app/Http/Middleware/`, registered in `bootstrap/app.php`                                 |
| an event listener                      | `app/Listeners/<Domain>/` (found automatically by its `handle()` type hint)               |
| a status                               | an enum case in `app/Enums/<Domain>/` and a row in `status_definitions` (seeder)          |
| a setting                              | a `config/` file; secrets only through `.env`                                             |
| a translation                          | `lang/bn` and `lang/en` (Phase 3)                                                         |
| a test                                 | `tests/Feature/<Domain>/` or `tests/Unit/<Domain>/`                                       |

## Request flow

```text
Route → middleware → Controller → FormRequest → Policy → Action → Model(s) → PostgreSQL
```

- Web (Inertia) and API controllers call the same actions, so the rules live in
  one place. Jobs and commands call actions too.
- An action is a plain class with a `handle()` method. It owns the transaction
  and receives validated input (the form request's data, or a data object).
- PostgreSQL enforces the hard rules as well: lifecycle triggers, constraints,
  row-level security. The app maps its refusals to clear errors (422).
- One domain does not reach into another domain's internals. It calls the
  other domain's actions or reacts to its events.

Every request first passes `AssignRequestId` (global): one UUID per request in
the `X-Request-Id` response header, in Laravel's `Context` (so in every log line
and queued job) and in `data_change_logs.request_id`. The web middleware group
then runs, in order: Laravel's defaults,
`HandleInertiaRequests`, `AddLinkHeadersForPreloadedAssets`, `EnsureLicensed`,
`RequestContext`, `LifecycleContext` (one database transaction per request).

### Logging

- Every log channel has the `RedactSensitiveData` tap (`app/Logging`). Values
  under keys such as password, token, secret, api key, authorization, OTP, PIN
  or card number become `[redacted]`, at any depth. Never put a secret into the
  message text itself. A test fails when a channel lacks the tap.
- Development writes `storage/logs/laravel.log` (`LOG_STACK=single`). Production
  on Docker uses `LOG_STACK=json`: one JSON object per line on stderr.
- Laravel's `Context` adds `request_id` to every line, and queued jobs carry it.

### Health check

`GET /up` is Laravel's own health route (for load balancers and uptime
monitors). `app/Listeners/Health/CheckServices` adds PostgreSQL and Redis to it:
200 `up` only when both answer, otherwise 500 `down`, without showing the
error. A new must-have service gets its check in that listener. A locked
license does not count as unhealthy.

## When to add a layer

| Layer                      | Add it when                                                                                                |
| -------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `Services/<Domain>` class  | logic is shared by several actions or is complex enough to test alone (tariff resolution, journal posting) |
| `Queries/<Domain>` class   | a read is too big for a model scope, or is reused (report, paginated list with filters)                    |
| data object                | an action has many inputs or is called from several entry points (web, API, job)                           |
| interface + implementation | there really are several implementations (payment gateways, SMS drivers, GeoIP locator)                    |

Plain Eloquent through models and scopes is the default. There is no
repository per model: it doubles the files and hides Laravel features without
adding safety, because PostgreSQL already enforces the rules.

## Database

Tables that are referred to from outside (users, organizations, buildings,
customers, invoices, payments, financial transactions, licenses) have a BIGINT
`id` for joins and foreign keys and a ULID `public_id` (`CHAR(26)`). Their models
use `HasPublicId`: URLs, API responses, exports and links show only `public_id`,
and it never changes once saved.

- All migrations live in `database/migrations/`, in the dependency order of the
  design (foundation and lifecycle first, row-level security last).
- They run as the owner role (`make migrate`); the app runs as `gasflow_app`.
- Infrastructure SQL (functions, triggers, partitions, RLS) is written in the
  migration with `DB::unprepared()`. Plain tables use the schema builder.
- Every domain table ends with `LifecycleSchema::attach()`.
- Functions and views are created with `CREATE OR REPLACE`: `migrate:fresh`
  keeps functions, so a plain `CREATE FUNCTION` fails on the next rebuild
  (`tests/Architecture/MigrationsTest.php`).
- Status codes (with Bangla labels) and redacted columns are inserted by the
  same migration that creates the table, because rows cannot be written
  without them.
- Timestamps keep microseconds (`Schema::defaultTimePrecision(null)` in
  `AppServiceProvider`), matching the design's `TIMESTAMPTZ`.
- Migration groups so far: 1 lifecycle foundation (`2026_11_13_*`), 2 identity
  (`2026_11_14_000001`: users, sessions, password reset tokens, Sanctum tokens;
  it replaces Laravel's default users migration), 3 organizations and access
  (`2026_11_14_000002` supported locales and `users.locale`, `000003`
  organizations, memberships, roles, permissions and the role-scope triggers,
  `000004` user devices, access sessions and the live user count views), 4
  buildings (`000005` buildings, ownership history and gas vendor
  assignments, `000006` building access grants, building memberships and
  their roles, with the triggers that refuse a wrong-scope role or a member
  without tenant or grant), 5 catalogues (`000007` features, meter types,
  payment methods and integration providers, seeded with Bangla names), 6
  subscriptions (`000008` SaaS packages, versions, features and the default
  feature set of each package, `000009` building subscriptions, SaaS invoices
  and SaaS payments, `000010` SaaS invoice total check and frozen amounts).
- An invoice's total is always subtotal plus tax, and once it leaves draft
  what it bills is frozen: a mistake is fixed by voiding it and issuing a new
  one. Only the maintenance override may correct it in place.
- Catalogue tables keep English in their base columns and other languages in
  `translations` (`{"bn": {"name": ...}}`); every seeded row has a Bangla text.
  Tests for tables shared by several domains live in `tests/Feature/Catalogs`.
- A model uses Laravel's `SoftDeletes` exactly when its table's
  `soft_delete_policy` is `allowed` (master and configuration tables). Money,
  history and log tables never do. A test checks every model in `app/Models`
  against the registry (`tests/Feature/Database/LifecycleModelsTest.php`).

## Frontend

```text
resources/js/
├── app.ts           Inertia entry
├── pages/           one folder per area of the app
├── components/      shared components (ui/ = base components)
├── composables/     shared logic (useCan, useLocale, ...)
├── layouts/         app shell, auth layout
├── lib/             helpers
└── types/           TypeScript types
```

Colors, spacing and status badges come from central tokens and components; a
page never hard-codes them. Details arrive with the UI design system (Phase 3).

## Tests

```text
tests/
├── Feature/<Domain>/       through Laravel and PostgreSQL (RefreshPostgresDatabase)
├── Unit/<Domain>/          plain PHP, no application
├── Architecture/          rules about the code itself (Pest arch tests)
├── Support/                helpers shared by tests (TestLicense, TestRecords, ...)
├── Fixtures/               test-only models, enums and tables (tests/Fixtures/migrations)
└── Concerns/               test case traits
```

- Every test uses Arrange / Act / Assert comments, one behaviour per test,
  grouped with `describe()`.
- Feature tests run as `gasflow_app` inside a rolled-back transaction, with an
  active license signed by a throw-away key (`Tests\TestCase`).
- Database behaviour is tested on the test-only tables in
  `tests/Fixtures/migrations`, so a test does not depend on a business table.
- Architecture tests (`tests/Architecture`) fail the suite when code breaks a
  rule:
    - Pest's `php`, `security` and `laravel` presets: no debug leftovers, no weak
      hashes, and Laravel naming (commands end in `Command`, and so on)
    - actions, services and models never depend on the HTTP layer
    - controllers never talk to the database directly
    - `Lifecycle` and `RequestContext` never depend on a business domain
    - `env()` only in `config/`
    - only the maintenance command may use the override
      When a new domain or rule is added, add its line there.

## Naming

| Kind            | Pattern                           | Example                                            |
| --------------- | --------------------------------- | -------------------------------------------------- |
| action          | verb + noun                       | `IssueInvoice`                                     |
| controller      | noun + `Controller`               | `InvoiceController`                                |
| form request    | action name + `Request`           | `IssueInvoiceRequest`                              |
| policy          | model + `Policy`                  | `InvoicePolicy`                                    |
| status enum     | model + `Status`                  | `InvoiceStatus`                                    |
| job             | what it does                      | `SendInvoiceSms`                                   |
| event           | what happened, past tense         | `InvoiceIssued`                                    |
| artisan command | `<area>:<verb>`, class `…Command` | `audit:geoip-update`, `UpdateGeoIpDatabaseCommand` |
| migration       | Laravel default                   | `create_invoices_table`                            |
