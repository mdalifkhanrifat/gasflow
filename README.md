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

## Requirements

- PHP 8.5 and Composer 2
- Node.js 24 LTS or newer and npm

## Local setup

```bash
composer setup   # install dependencies, create .env, generate key, migrate, build assets
composer dev     # start the app, queue worker, logs and Vite together
```

## Quality checks

```bash
composer lint        # format PHP code with Pint
composer test        # Pint check, PHPStan and the test suite
npm run check        # lint and format check for the frontend
npm run types:check  # TypeScript check
```

## License

Proprietary. All rights reserved. Running GasFlow requires a valid license.
