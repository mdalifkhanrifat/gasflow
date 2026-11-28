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
├── frontend/                  independently built Vue application
├── resources/
│   └── licensing/             trusted license public keys (part of the build)
├── routes/
│   ├── web.php                server-only web routes
│   ├── console.php            scheduled tasks
│   └── api.php                /api/v1 JSON for Vue, PWA and future Flutter
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
| a list endpoint (search, filter, sort) | a form request extending `App\Http\Requests\TableRequest` that allow-lists its columns     |
| a permission check                     | the model's policy in `app/Policies/<Domain>/`                                            |
| background work                        | a job in `app/Jobs/<Domain>/`                                                             |
| a scheduled task                       | `routes/console.php`, inside the licensed group unless it protects data                   |
| an artisan command                     | `app/Console/Commands/<Domain>/`                                                          |
| HTTP middleware                        | `app/Http/Middleware/`, registered in `bootstrap/app.php`                                 |
| an event listener                      | `app/Listeners/<Domain>/` (found automatically by its `handle()` type hint)               |
| a status                               | an enum case in `app/Enums/<Domain>/` and a row in `status_definitions` (its migration)   |
| a permission or system role            | a row in a migration (every environment needs it); demo data in a seeder (Phase 4/6)      |
| a setting                              | a `config/` file; secrets only through `.env`                                             |
| a UI translation                       | the same key in `frontend/src/locales/bn.json` and `en.json` (checked by `npm run check`) |
| a test                                 | `tests/Feature/<Domain>/` or `tests/Unit/<Domain>/`                                       |

## Request flow

```text
Route → middleware → Controller → FormRequest → Policy → Action → Model(s) → PostgreSQL
```

- API controllers, jobs and commands use the same domain actions.
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
`SetLocale`, `EnsureLicensed`,
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
  and SaaS payments, `000010` SaaS invoice total check and frozen amounts), 7
  flats and customers (`000011` flats, customers and flat occupancies,
  `000012` attachments and the ownership evidence FK), 8 metering (`000013`
  meters, meter assignments, billed occupancy per meter and meter events,
  `000014` reading periods, meter readings, revisions and approvals, with
  their enforced status transitions), then fixes from the owner's review of
  groups 7 and 8 (`000015` the shared building access check, `000016`
  occupants only from organizations with access to the building, `000017`
  every attachment has an owner organization or building, `000018` meter
  assignments and readings carry their building, checked by composite keys,
  `000019` the closed-parent guard, `000020` readings frozen once their
  period is approved, locked or cancelled), 9 tariffs and reconciliation (`000021` tariff
  versions and building charge rules, `000022` meter reconciliations, `000023`
  the frozen-columns guard, `000024` approved tariffs frozen and started inside
  their vendor assignment, `000025` reconciliation checks, review transitions and
  freeze, `000026` percentage charges at most 100,
  `000027` ended gas vendor assignments also in the overlap check, `000028`
  the tariff timeline functions, `000029` the gas supply change register, `000030` charge quantities with six
  decimals, `000031` splitting a charge by meter readings), 10 billing
  (`2026_11_15_000032` billing cycles and invoices, tied to their building's
  reading period, cycle and flat, `000033` invoice totals and frozen billed
  fields after draft, `000034` invoice lines: one gas line per rate segment
  with its tariff, days and split method, frozen with their invoice, `000035`
  credit notes and billing disputes, `000036` retroactive rate differences
  per issued gas line and tariff version), then the owner's group 10
  decisions (V7.19, D46): `000037` the generic parent and children status
  rules, `000038` billing cycle rules: calculate only from an approved
  period, no new invoices once approved, cancel only with void invoices,
  `000039` an invoice's billing mode is its cycle's and its customer is the
  billed occupant or of an organization with access to the building,
  `000040` an invoice leaves draft only when its amounts are the sums of its
  lines (`invoice_line_totals()`), `000041` a line's rate is approved and
  covers its days, a gas line stays inside its cycle, and a retro line
  corrects an issued gas line of the same flat and vendor, `000042` credit
  notes only on issued invoices, frozen once decided, never more than the
  invoice total, `000043` retro adjustments tied to an issued gas line of
  their building and to their settlement, frozen once decided, `000044` a
  dispute is raised by the invoice's own customer. 11 payments
  (`2026_11_16_000045` payments, by a method of the catalogue, with an
  (id, building) key for the rows that must stay in their building, and
  payment attempts, unique per idempotency key and per provider transaction),
  `000046` payment allocations, tied to the payment's and the invoice's
  building by composite keys, and refunds with their enforced transitions),
  then the owner's group 11 decisions (V7.20, D47): `000047` columns frozen
  from the first write, `000048` who collects a payment: its billing mode
  and collector (a gas vendor of the building for authorized billing, the
  building's owner for its internal cost allocation), and a customer of an
  organization with access to the building, `000049` an allocation keeps
  the customer, billing mode, collector (`invoice_payee_organization_id()`)
  and currency of its payment and invoice, is frozen once written and is
  undone only by reversing it, `000050` the generic status rules: active
  allocations need an issued (or partially paid, overdue) invoice and a
  succeeded payment, refunds a succeeded payment, no new attempt once the
  payment is decided, and a payment is reversed or refunded only after its
  allocations are reversed, `000051` `payment_balance()` and
  `invoice_balance()`: a payment never gives more than it has, an invoice is
  never settled beyond its total, an invoice's status says what its balance
  says (checked again at COMMIT), a payment is refunded only when all of it
  was given back, and a paid invoice may go back after a reversal, `000052`
  payment attempts for their payment's amount and currency, with enforced
  transitions, and frozen once decided, `000053` a received payment and a
  decided refund keep their facts (a received payment has its receive time,
  a completed refund its processing time). 12 ledger (`000054` each
  organization's accounts and its double-entry journals and lines: one side
  per line, the journal's organization and currency, balanced at COMMIT,
  immutable once posted, reversed by a new journal, `000055` the financial
  transaction register (one per posted journal, never changed) and the
  `ledger_posted_lines` view, the only source for accounting reports), then
  the owner's group 12 decisions (V7.21, D49): `000056` books only in
  buildings the organization may access, posting time required, accounts
  frozen once written, a hidden journal never keeps a visible transaction,
  `000057` a transaction is in the books of whoever collects its payment or
  invoice, has their customer, and names the records its type needs,
  `000058` a reversal journal (source `journal_reversal`) reverses one posted
  journal of its organization and mirrors its lines; its transaction names
  the reversed one. Then the billing schedule (V7.22, D48):
  `2026_11_19_000059` building billing settings, one timeline per building
  and stream, with `billing_period_bounds()`, `000060` billing cycles follow
  their setting exactly and never overlap within a stream, and the timeline
  never changes under existing cycles, `000061` reading periods never overlap
  and close their cycle. 13 installations, portal, gateways, setup, gas
  supply and licensing (`000062` installation agreements and their
  installments, `000063` portal access policies and guest invoice links,
  `000064` payment and SMS gateway configurations and the gateway of a
  payment attempt, `000065` setup areas, pipeline zones and building setup
  tasks, `000066` gas supply receipts, `000067` installation statuses left to
  the status registry, `000068` license signing keys, licenses, activations,
  check-ins and the installation's own license), then the owner's group 13
  decisions (V7.23, D50): `000069` the generic rule that freezes chosen child
  columns once the parent is closed, `000070` installations inside their
  building, `000071` installment schedules and statuses in step with their
  agreement, `000072` guest links only for issued invoices and only while
  active, `000073` attempts only through the collector's active gateway of
  the right provider kind, `000074` setup tasks for the building's own people
  (`user_can_access_building()`), `000075` gas supply receipts true to what
  was bought, `000076` licenses used only as they were signed. 14
  notifications, audit and provider events (`000077` notification templates,
  notifications, audit logs and idempotency keys, `000078` webhook inbox,
  integration outbox and delivery attempts), then the owner's group 14
  decisions (V7.24, D51): `000079` the generic write-once rule, `000080` the
  audit log append-only and inside its tenant, `000081` notifications to
  their recipients, templates and statuses, `000082` idempotency keys true to
  their first request and response, `000083` webhook events kept as received
  and processed only when verified, `000084` outbox events and delivery
  attempts kept as they happened. 15 row-level security (V7.25, D52):
  `000085` integrity checks run as `gasflow_integrity`, `000086` the
  design's 52 policies, `000087` policies for the building tables the design
  left open, memberships and their roles, and user devices. 16 seed data
  (V7.26, D53): `000088` the permissions the status transitions name and a
  foreign key to them, `000089` the actor roles, `000090` one Bangla label
  per status code.
- Data every environment needs (statuses, catalogues, permissions, system
  roles) is seeded by a migration. Laravel seeders are for development and
  demo data only, and they write tenant rows in each organization's context
  (no bypass). A transition that names a `permission_code` needs that
  permission in `permissions`; a new status gets the Bangla label its code
  already has on other tables (a test checks one label per code).
- On a lifecycle table a DELETE becomes a soft delete, so the `down()` of a
  migration that seeds such a table switches the guard off
  (`ALTER TABLE ... DISABLE TRIGGER trg_00_lifecycle_guard`), deletes its
  rows and switches it on again. Otherwise the codes stay taken and the next
  migrate fails.
- A migration that enforces status transitions inserts its
  `status_transitions` rows next to its statuses, and its `down()` deletes the
  transitions before the statuses.
- Whether an organization may work in a building is answered by one SQL
  function, `organization_can_access_building(organization, building)`: it owns
  the building or holds an active grant in force today. Triggers call it
  instead of repeating the rule.
- A row that must stay inside one building carries `building_id` and composite
  foreign keys to its parents' `(id, building_id)` unique keys, so a parent of
  another building is refused by the database (and row-level security can
  check the building directly). The column is `NOT NULL`, because a null would
  skip the composite check.
- Rows that must stop changing once their parent is closed (readings of a
  locked reading period, later invoices of a closed billing cycle) get one
  call after the parent's statuses exist:
  `LifecycleSchema::freezeWhenParentClosed('meter_readings', parent: 'reading_periods', foreignKey: 'reading_period_id', closedStatuses: [...])`.
  It creates the generic trigger `trg_<table>_closed_parent`; only the
  maintenance override may still correct such a row.
- Columns that must stop changing once a row leaves its editable statuses (an
  approved tariff's rate, a reviewed reconciliation's numbers, an issued
  invoice's amounts) get one call after the table's statuses exist:
  `LifecycleSchema::freezeColumns('tariff_versions', [...], editableStatuses: ['draft'])`.
  It creates `trg_<table>_freeze_<name>`; a correction is a new record, or the
  maintenance override. With `editableStatuses: []` the columns are frozen
  from the first write (a payment allocation is reversed, never changed).
- Columns that start empty and, once filled, never change (a stored response,
  a signature time) get
  `LifecycleSchema::writeOnce('idempotency_keys', ['response_code', 'response_body'])`.
  It creates `trg_<table>_write_once_<name>`; only the maintenance override
  may still correct them (V7.24).
- Status rules between a parent and its children are declared, not
  hand-written, with one call each (calling it again with the same name
  replaces the rule, so a later migration can change the statuses):
    - `LifecycleSchema::refuseNewChildrenWhenParentClosed('invoices', parent: 'billing_cycles', foreignKey: 'billing_cycle_id', closedStatuses: [...])`:
      no new child (or move in) while the parent is closed; existing children
      may still change (`trg_<table>_closed_parent_insert`)
    - `LifecycleSchema::requireParentStatus('billing_cycles', parent: 'reading_periods', foreignKey: 'reading_period_id', parentStatuses: [...], whenStatuses: [...])`:
      a row in those statuses (all when none are given) needs its parent in
      the required ones (`trg_<table>_parent_<name>`)
    - `LifecycleSchema::requireChildrenStatus('billing_cycles', child: 'invoices', foreignKey: 'billing_cycle_id', whenStatuses: ['cancelled'], childStatuses: ['void'])`:
      the parent enters those statuses only when every visible child is in an
      allowed one (`trg_<parent>_children_<name>`)
    - `LifecycleSchema::freezeColumnsWithParent('installation_installments', parent: 'installation_agreements', foreignKey: 'installation_agreement_id', columns: [...], closedStatuses: [...])`:
      the listed columns of a child stop changing once its parent has one of
      the statuses; its other columns (status, paid amount) still change
      (`trg_<table>_parent_freeze_<name>`, V7.23)

    All of them let the maintenance override through, like the freeze guards.

- A trigger function that reads another table (directly, or through a helper
  such as `organization_can_access_building()` or `invoice_balance()`) is an
  integrity check and runs as `gasflow_integrity`: after creating it, call
  `SELECT adopt_integrity_check('validate_x()')` in the same migration (owner
  `gasflow_integrity`, `SECURITY DEFINER`, `search_path = public, pg_temp`).
  Under row-level security it would otherwise see only the caller's rows and
  accept another tenant's id. `CREATE OR REPLACE` of an adopted function
  resets it, so adopt it again. A check only reads and refuses; if it locks
  rows (`FOR SHARE` / `FOR UPDATE`), grant the lock with
  `SELECT lifecycle_allow_integrity_lock('table')` (the generic parent and
  children rules do it themselves). The helpers stay `SECURITY INVOKER`, so
  the app calling them sees only its own rows.
  `tests/Feature/Database/IntegrityChecksTest.php` finds every such function
  in `pg_proc` and fails until it is adopted (V7.25, D52).

- Row-level security (group 15, V7.25, D52). Every tenant table has one
  policy for all commands, with ENABLE and FORCE (the owner is filtered too):
  organization scope (`organization_id = app_current_organization_id()`),
  building scope (`tenant_scope_<table>`: the row's building is visible) or
  parent scope (the row's parent is visible). A new tenant table gets its
  policy in its own migration, joins `Tests\Support\RowLevelSecurity` and
  gets a row in `Tests\Support\TenantWorld`; the suite fails until it does.
  Catalogues, plans and licensing are platform tables without a policy;
  `users`, `organizations` and `platform_user_roles` get theirs in Phase 4.
  Without an organization context the app sees nothing (fail-closed).
- Work without a request context never bypasses row-level security (D52-4).
  `gasflow_app` has no `BYPASSRLS`, and no code path switches roles:
    - A scheduled or queued job that touches tenant data runs once per
      organization, in that organization's context (the job carries the
      organization id and sets `app.organization_id` / `app.user_id` before
      its first query, like the web middleware). A job for "all
      organizations" dispatches one job per organization.
    - A webhook names its gateway in the URL (the gateway's public id). A
      `SECURITY DEFINER` lookup owned by `gasflow_integrity` returns only that
      gateway's organization (and building), and the request then runs in
      that context; the signature is checked with that gateway's credentials
      (Phase 10).
    - Platform staff do not read across tenants: they switch into one
      organization (validated on the server, audited) and work in its context
      (Phase 4).
    - The operator's maintenance override works inside an organization too.

- Gas is priced only through the tariff timeline: `tariff_rate_segments()`
  says which vendor and rate applied on which days (several changes a month,
  customer category with the general rate as fallback, gaps shown), and
  `tariff_charge_segments()` splits a consumption over those days by the daily
  average and prices each part. Billing, retro adjustments and reports call
  these functions instead of looking up a rate themselves.
- When the gas supply changes (another company's gas, another price), the
  change and the meter readings at that moment go into `supply_changes` /
  `supply_change_readings`. `metered_charge_segments(..., meter, split)` then
  splits a consumption by the flat's own readings (`own_meter`), the main
  meter's usage (`main_meter`), the daily average (`days`) or the best one
  available (`auto`), and says which method it used.
- An invoice's amounts are added up from its lines by one SQL function,
  `invoice_line_totals(invoice)` (discount and credit lines are negative;
  previous-balance lines are shown, never billed again). The billing action
  fills a draft from it, and the database refuses to approve an invoice whose
  amounts differ.
- An invoice's total is always subtotal plus tax minus discount and credit
  (a SaaS invoice: subtotal plus tax), and once it leaves draft what it
  bills is frozen: a mistake is fixed by voiding it and issuing a new
  one. Only the maintenance override may correct it in place.
- Money is collected in two streams that never mix, named by the billing
  mode: `authorized_meter_billing` (the gas vendor bills and collects from the
  customers it supplies) and `internal_cost_allocation` (the building's owner
  shares the gas cost among its own tenants and may collect in its own way).
  A payment records its `billing_mode` and its collector
  (`payee_organization_id`), and settles only invoices of the same stream and
  collector.
- What a payment has left and what an invoice still owes come from two SQL
  functions, `payment_balance(payment)` and `invoice_balance(invoice)`. The
  database uses them to refuse over-allocation and wrong statuses; the
  payment actions and reports read the same numbers. A payment action moves
  the invoice to the status its balance gives in the same transaction (the
  check runs again at COMMIT).
- Catalogue tables keep English in their base columns and other languages in
  `translations` (`{"bn": {"name": ...}}`); every seeded row has a Bangla text.
  Tests for tables shared by several domains live in `tests/Feature/Catalogs`.
- A model uses Laravel's `SoftDeletes` exactly when its table's
  `soft_delete_policy` is `allowed` (master and configuration tables). Money,
  history and log tables never do. A test checks every model in `app/Models`
  against the registry (`tests/Feature/Database/LifecycleModelsTest.php`).

## Frontend

```text
frontend/
├── index.html, package.json, vite.config.ts
└── src/
    ├── main.ts, App.vue
    ├── router/      Vue Router route definitions
    ├── api/         shared JSON client for Laravel /api/v1
    ├── pages/       domain/feature folders for views and page-specific files
    ├── components/  shared primitives and application UI
    ├── composables/ theme and locale preferences
    ├── layouts/     app shell and auth layout when needed
    ├── locales/     frontend translation dictionaries
    ├── assets/      bundled files Vite fingerprints, such as fonts
    ├── styles/      semantic tokens and Tailwind mappings
    └── lib/         shared formatters and helpers
```

Group route pages by domain, such as `pages/billing/invoices/Index.vue` and
`pages/billing/invoices/Show.vue`. Keep invoice columns and page-specific
components next to those pages. Put cross-domain tables, forms, dialogs, and
states in shared components. Create folders when they get their first file.

Dashboards with distinct workflows have separate pages, starting with
`pages/dashboards/platform/Index.vue`. Each page orders its own panels and uses
the shared dashboard components. A workspace with dedicated navigation can
use a dedicated layout built from those same primitives.

`frontend/src/styles/tokens.css` owns semantic colors, density and component design
values. Keep workspace palette overrides at the document root. The shared
`useTheme` composable exposes the system, light and dark preference. All
pages use the same component styles and responsive behavior. Density has two
tokens: `--density-row-height` is the minimum table row height and
`--density-control-height` the form control height (list toolbar controls
follow it). The `density-compact` class makes everything inside it denser;
touch screens keep 44 px targets either way.

Use `components/ui/UiInput.vue` for text, number, search and date inputs,
and `NativeSelect.vue` for both single and multiple selection. Use
`ui-button`, `ui-button-primary` and `ui-button-icon` for actions, links
and downloads. The shared styles in `styles/app.css` own the control borders,
spacing, focus, disabled state and touch sizing. Module pages select an
existing variant and own their data, validation, labels and layout.

Icons come from `@lucide/vue`, imported by name where they are used.
Navigation icons live in `config/navigation.ts`. Never use text glyphs as
icons. Every icon takes its size and stroke from `--icon-size` and
`--icon-stroke`; a component that needs another size sets `--icon-size` on
itself, as `.table-action` and `.data-table-sort` do. Icons are decorative
(`aria-hidden`), so the button or link around them carries the accessible name.

Modal forms use `components/app/forms/FormDialog.vue`, a native `<dialog>`
opened with `showModal()`: the browser moves focus inside, keeps the page
behind it inert, closes on Escape and returns focus to the trigger. Menus and
panels that do not block the page use the native `popover` attribute. After an
action succeeds, call `notify()` from `composables/useToast.ts`; the single
`ToastRegion` in `App.vue` announces it politely and pauses while hovered or
focused. Errors that block a form stay inside the form.

Fonts are self-hosted, never loaded from a CDN. Each font has its own folder
under `src/assets/fonts/` with the file, its licence and a `SOURCE.md` that
says where it came from and how to rebuild it; `src/styles/fonts.css` holds the
`@font-face` rules. Bengali uses Noto Sans Bengali (weights 400–800, Bengali
characters only); Latin text uses the system font.

Record statuses come from the database. `GET /api/v1/statuses`
(`Http/Controllers/Lifecycle/StatusCatalogueController`) returns every
entity's statuses from `StatusRegistry` in display order, each with its tone
and a label per supported language. `composables/useStatusCatalogue.ts` loads
it once at start-up. Show a record status with
`<StatusBadge entity="invoices" :value="row.status" />`: pages keep only the
status code, never a label or color. A new status or a changed tone is a
`status_definitions` row; `config/status.ts` maps each tone to its colors.
`components/ui/UiBadge.vue` is the plain colored badge for anything that is
not a record status, such as a count.

Every list uses one query: `?search=&filter[status]=paid,overdue&sort=-due_date&page=2&per_page=25`.
On the server, a list endpoint's form request extends
`App\Http\Requests\TableRequest`, declares `$sortable`, `$filterable`,
`$searchable` and `$defaultSort`, and answers with
`XResource::collection($request->paginate($query))` (Laravel's `data`, `links`
and `meta`). Anything not allow-listed is a 422. In the UI, a page declares its
columns once with `TableColumn` (`components/app/tables/columns.ts`: key,
label, value, type `text | number | money | date | month | status`,
`sortable`) and gives `ModuleListing` a source from `lib/tableQuery.ts`:
`serverTableSource('/api/v1/…', mapRow)` for real data or
`usePreviewTableSource()` for preview rows. Both run through
`useTableQuery`, so search, filters, sorting, paging, selection and CSV behave
the same. The table parts (`DataTable`, `TableFilters`, `TablePagination`,
`RecordActions`, `RecordDialog`) are shared; on phones the rows become cards
with one labelled line per column, and the headings stay for screen readers.
Keep `pageSizes` and `maxSearchLength` in `lib/tableQuery.ts` equal to
`TableRequest::PAGE_SIZES` and `MAX_SEARCH_LENGTH`.

Forms build each field with `components/app/forms/FormField.vue`: it renders
the label, hint and error and hands the control its `id`, `aria-invalid` and
`aria-describedby` through the slot (`v-slot="{ control }"`, then
`v-bind="control"` on the input). `api/client.ts` throws `ApiValidationError`
for Laravel's 422 and `ApiError` for other failures; `useFormErrors().fromApi()`
turns the 422 into one message per field and `focusFirstError()` moves focus
to the first invalid control. Long forms open `FormDialog` with
`layout="sheet"`. A panel with nothing to show uses
`components/app/feedback/StatePanel.vue` (`empty`, `loading`, `error`,
`forbidden`). A record page uses `components/app/detail/DetailLayout.vue`:
actions, reka-ui tabs whose active tab is in the URL (`?tab=history`), one slot
per tab key and an optional summary; its History tab shows `RecordTimeline`.
reka-ui is used only where native HTML lacks the keyboard behavior, such as
tabs; dialogs and popovers stay native.

`npm run check` (in `make check` and CI) also runs
`frontend/scripts/check-ui-rules.mjs`: no hex color in `.vue` or `.ts` files
and no static `style="…"` in components (a `:style` binding for data-driven
sizes is fine); `bn.json` and `en.json` have the same keys and `{placeholders}`,
no empty text, and every key is used in `src` (keys built at run time count
through their prefix, such as `` `preferences.theme.${theme}` ``).

The app installs as a PWA. `frontend/public/manifest.webmanifest` and
`public/icons/` (192, 512 and a maskable 512 drawn from `favicon.svg`) are
static; their colors match `--surface` and `--background`. `vite-plugin-pwa`
builds `sw.js` in production builds only: it precaches the built files and
answers page loads with `index.html`, while `/api`, `/sanctum` and `/up` always
go to the network, so no record, invoice or balance is stored on the device.
Add a runtime cache only for data the design allows offline (Phase 7 reading
drafts use IndexedDB, not the service worker). nginx serves `sw.js` and the
manifest with `no-cache`. `composables/useServiceWorker.ts` registers it; a new
version waits for the user's "Reload" toast. `useOnlineStatus` drives the
offline banner, and `useInstallPrompt` with `InstallAppButton` offers the
browser's install dialog or, on iPhone and iPad, the Share-menu steps. Test the
PWA on the nginx build (http://localhost:8047); the Vite dev server has no
service worker.

Change shared font families and sizes in the Typography section of
`frontend/src/styles/tokens.css`. Use `PageHeading` for the title, eyebrow
and supporting copy. Module and dashboard tables share `DataTable` for cell
typography and padding. The table record-count footer, dashboard sample note
and sidebar footer note use `--footer-text-size`. Form labels and read-only
field values have their own tokens next to the input and button sizes.

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
- Feature tests run inside a rolled-back transaction, with an active license
  signed by a throw-away key (`Tests\TestCase`). They log in as `gasflow_test`:
  the privileges of `gasflow_app` without row-level security, so a test can
  arrange rows of several organizations without a context. A test of a policy
  or of the runtime role calls `useAppRole()` (`SET LOCAL ROLE gasflow_app`) and
  sets the context with `setDatabaseContext()`; the operator connection
  (`pgsql_maintenance`) works inside an organization too
  (`operatorOrganization()`).
- `tests/Feature/Security` holds the row-level security suite: which tables
  have which policy (`Tests\Support\RowLevelSecurity`), and what each role sees
  and may write with and without an organization context.
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
