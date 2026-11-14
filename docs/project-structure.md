# Project structure

This guide says where every kind of file lives and why. GasFlow is a long-lived
product that will keep changing, so the structure follows three rules:

1. **Laravel first.** Use Laravel's own folders, names and features (models,
   policies, form requests, jobs, events, `routes/`, `database/migrations/`).
   Any Laravel developer should find things without a tour, and the `make:*`
   generators and packages keep working.
2. **One module per business area.** Code for one area (billing, metering, ...)
   sits together in `app/Modules/<Module>`, laid out like a small Laravel `app/`.
3. **No layer without a reason.** Repositories, data objects and services are
   added where they solve a real problem, not by default (see
   [When to add a layer](#when-to-add-a-layer)).

Update this file in the same commit that adds a new folder or convention.

## Top level

```text
gasflow/
├── app/
│   ├── Http/                  Laravel defaults: base Controller, HandleInertiaRequests
│   ├── Models/                User only (Laravel and packages expect App\Models\User)
│   ├── Providers/             AppServiceProvider: app-wide settings
│   ├── Modules/<Module>/      business modules (see "Inside a module")
│   └── Shared/<Area>/         infrastructure every module uses (same layout as a module)
├── bootstrap/
│   ├── app.php                routing, middleware groups, exception mapping
│   └── providers.php          every service provider, listed by hand
├── config/                    one file per concern; env() is read only here
├── database/
│   ├── migrations/            all migrations, in dependency order (see "Database")
│   ├── factories/<Module>/    model factories
│   └── seeders/
├── docker/                    images and service config (php, nginx, postgres)
├── docs/                      developer documentation (this file)
├── lang/                      bn and en translations (Phase 3)
├── resources/
│   ├── css/, js/, views/      frontend (see "Frontend")
│   └── licensing/             trusted license public keys (part of the build)
├── routes/
│   ├── web.php                web routes; includes routes/web/<module>.php
│   ├── console.php            scheduled tasks and closure commands
│   └── api.php                /api/v1 for the PWA and Flutter (Phase 3)
├── storage/                   runtime files (license, GeoIP database, logs); git-ignored
├── tests/                     see "Tests"
├── compose.yaml, Makefile     Docker stack and shortcuts (README)
└── .github/workflows/         CI on the same Docker stack
```

## Inside a module

`app/Modules/<Module>` uses Laravel's folder names. Create a folder only when
the module has a file for it; an empty folder is never committed.

```text
app/Modules/Billing/
├── BillingServiceProvider.php   bindings, commands, listeners (registered in bootstrap/providers.php)
├── Actions/                     one business operation per class: IssueInvoice
├── Models/                      Eloquent models: Invoice, InvoiceLine
├── Enums/                       statuses and fixed lists: InvoiceStatus
├── Policies/                    one per model: InvoicePolicy
├── Http/
│   ├── Controllers/             thin: authorize, validate, call an Action, respond
│   ├── Requests/                form requests (validation)
│   ├── Resources/               API resources for /api/v1
│   └── Middleware/
├── Jobs/                        queued work (Jobs/Middleware for job middleware)
├── Events/, Listeners/
├── Notifications/, Mail/
├── Console/                     artisan commands
├── Exceptions/
├── Concerns/                    traits for this module's classes
├── Queries/                     read-only queries too big for a model scope (lists, reports)
├── Services/                    logic shared by several actions: TariffResolver
├── Data/                        typed input objects, only where needed
└── <Concept>/                   a few closely related domain classes, such as Licensing/Issuing
```

The module's core domain classes (value objects, small services) may sit at the
module root, for example `Licensing/LicenseVerifier.php`.

### Connecting models to Laravel

Module models are not in `App\Models`, so Laravel cannot guess their policy or
factory. Each model says so itself with Laravel's attributes; there is no
custom discovery code:

```php
#[UsePolicy(InvoicePolicy::class)]
#[UseFactory(InvoiceFactory::class)]
final class Invoice extends Model
{
    use HasFactory, HasLifecycle;
}
```

### Modules

| Module               | Owns                                                                    | Status   |
| -------------------- | ----------------------------------------------------------------------- | -------- |
| `Licensing`          | license verification, read-only lock, owner key tools; later activation | in use   |
| `Identity`           | users, login, sessions, MFA, devices                                    | Phase 4  |
| `Tenancy`            | organization and building context, grants                               | Phase 4  |
| `Organizations`      | organization profiles                                                   | Phase 4  |
| `Subscriptions`      | SaaS plans, features, building subscriptions                            | Phase 5  |
| `Buildings`          | buildings, flats, ownership, vendor assignments                         | Phase 6  |
| `Customers`          | customers, occupancy                                                    | Phase 6  |
| `SystemSetup`        | setup areas, pipeline zones, setup tasks                                | Phase 6  |
| `Metering`           | meters, readings, approvals, handovers                                  | Phase 7  |
| `Reconciliation`     | main/sub-meter variance                                                 | Phase 7  |
| `Supply`             | gas supply receipts                                                     | Phase 7  |
| `Tariffs`            | vendor tariff versions, charge rules, tariff resolution                 | Phase 8  |
| `Billing`            | billing cycles, invoices, credit notes, disputes                        | Phase 8  |
| `Installations`      | installation agreements, EMI                                            | Phase 8  |
| `Ledger`             | double-entry journals, financial transactions                           | Phase 9  |
| `Payments`           | payments, allocations, refunds                                          | Phase 9  |
| `Integrations`       | gateway and SMS drivers, webhooks, outbox                               | Phase 10 |
| `CustomerPortal`     | guest links, OTP                                                        | Phase 10 |
| `Notifications`      | templates, delivery                                                     | Phase 10 |
| `Reporting`, `Audit` | statements, cashbook, trial balance; audit trail screens                | Phase 11 |

### Shared areas

`app/Shared/<Area>` holds infrastructure that every module uses. It follows
the same layout as a module and never depends on a module.

| Area             | What it does                                                                                                                    |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `Lifecycle`      | status / is_active / is_deleted standard, `LifecycleSchema`, `HasLifecycle`, status registry, recycle bin, maintenance override |
| `RequestContext` | device, IP city and consented GPS for each request, fed to the activity trail                                                   |

## Where does it go?

| I am adding...                         | Put it in                                                                   |
| -------------------------------------- | --------------------------------------------------------------------------- |
| a table                                | `database/migrations/`, ending with `LifecycleSchema::attach()`             |
| a model                                | `app/Modules/<Module>/Models/` with `HasLifecycle`                          |
| a business operation (create, approve) | `app/Modules/<Module>/Actions/<Verb><Noun>.php`                             |
| a page or endpoint                     | controller + form request in the module, route in `routes/web/<module>.php` |
| a permission check                     | the model's policy                                                          |
| background work                        | a job in the module's `Jobs/`                                               |
| a scheduled task                       | `routes/console.php`, inside the licensed group unless it protects data     |
| an artisan command                     | the module's `Console/`, registered in its service provider                 |
| a status                               | an enum case in `Enums/` and a row in `status_definitions` (seeder)         |
| a setting                              | a `config/` file; secrets only through `.env`                               |
| a translation                          | `lang/bn` and `lang/en` (Phase 3)                                           |
| a test                                 | `tests/Feature/<Module>/` or `tests/Unit/<Module>/` (see "Tests")           |

## Request flow

```text
Route → middleware → Controller → FormRequest → Policy → Action → Model(s) → PostgreSQL
```

- Web (Inertia) and API controllers call the same actions, so the rules live in
  one place. Jobs and commands call actions too.
- An action is a plain class with a `handle()` method. It owns the transaction
  and receives validated input (the form request's data, or a `Data` object).
- PostgreSQL enforces the hard rules as well: lifecycle triggers, constraints,
  row-level security. The app maps its refusals to clear errors (422).

The web middleware group runs, in order: Laravel's defaults,
`HandleInertiaRequests`, `AddLinkHeadersForPreloadedAssets`, `EnsureLicensed`, `RequestContext`, `LifecycleContext`
(one database transaction per request).

## When to add a layer

| Layer                      | Add it when                                                                                                |
| -------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `Services/` class          | logic is shared by several actions or is complex enough to test alone (tariff resolution, journal posting) |
| `Queries/` class           | a read is too big for a model scope, or is reused (report, paginated list with filters)                    |
| `Data/` object             | an action has many inputs or is called from several entry points (web, API, job)                           |
| interface + implementation | there really are several implementations (payment gateways, SMS drivers, GeoIP locator)                    |

Plain Eloquent through models and scopes is the default. A repository for every
model is not used: it doubles the files and hides Laravel features without
adding safety, because PostgreSQL already enforces the rules.

## Database

- All migrations live in `database/migrations/`, in the dependency order of the
  design (foundation and lifecycle first, row-level security last).
- They run as the owner role (`make migrate`); the app runs as `gasflow_app`.
- Infrastructure SQL (functions, triggers, partitions, RLS) is written in the
  migration with `DB::unprepared()`. Plain tables use the schema builder.
- Every domain table ends with `LifecycleSchema::attach()`.

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
├── Feature/<Module>/       through Laravel and PostgreSQL (RefreshPostgresDatabase)
├── Unit/<Module>/          plain PHP, no application
├── Unit/Architecture/      rules about the code itself (moves to tests/Architecture)
├── Support/                helpers shared by tests (TestLicense, LicenseFixtures, ...)
├── Fixtures/               test-only models, enums and tables (tests/Fixtures/migrations)
└── Concerns/               test case traits
```

- Every test uses Arrange / Act / Assert comments, one behaviour per test,
  grouped with `describe()`.
- Feature tests run as `gasflow_app` inside a rolled-back transaction, with an
  active license signed by a throw-away key (`Tests\TestCase`).
- Database behaviour is tested on the test-only tables in
  `tests/Fixtures/migrations`, so a test does not depend on a business table.

## Naming

| Kind            | Pattern                   | Example                 |
| --------------- | ------------------------- | ----------------------- |
| action          | verb + noun               | `IssueInvoice`          |
| controller      | noun + `Controller`       | `InvoiceController`     |
| form request    | action name + `Request`   | `IssueInvoiceRequest`   |
| policy          | model + `Policy`          | `InvoicePolicy`         |
| status enum     | model + `Status`          | `InvoiceStatus`         |
| job             | what it does              | `SendInvoiceSms`        |
| event           | what happened, past tense | `InvoiceIssued`         |
| artisan command | `<area>:<verb>`           | `audit:geoip-update`    |
| migration       | Laravel default           | `create_invoices_table` |
