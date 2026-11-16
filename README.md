# EasyHealthcare — Multi-Speciality Clinic Management SaaS

Multi-tenant, subscription-based clinic management for single-doctor clinics through multi-branch, multi-speciality groups (India-first: INR, GST, DPDP, WhatsApp/DLT).

**Stack:** Laravel 12 · PHP 8.3 · MySQL 8 · Redis · Sanctum · Reverb · Vue 3 + TypeScript · Tailwind CSS 4 · Vite.

## Status

Phases 1–12 are implemented and Phase 13 is code-complete. See **[docs/09-implementation-status.md](docs/09-implementation-status.md)** for what was verified, the deviations from the plan, and the list of gaps to close before production.

## Quick start (local)

```bash
# Backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite          # or point DB_* at MySQL 8
php artisan migrate --seed              # seeds plans, roles, specialities, templates + a demo clinic
php artisan serve                       # http://localhost:8000
php artisan queue:work & php artisan schedule:work &

# Frontend
cd ../frontend
npm install
npm run dev                             # http://localhost:5173 (proxies /api to :8000)
```

Sign in as `owner@demo.clinic` / `password` (other demo users are listed in the status doc). Or run everything with `docker compose up`.

## Tests

```bash
cd backend && php artisan test          # 61 tests incl. tenant isolation & permission matrix
cd frontend && npm run typecheck && npm test && npm run build
```

CI (`.github/workflows/ci.yml`) runs the backend suite against MySQL 8, code style, dependency audits, OpenAPI drift check, and the frontend typecheck/tests/build.

## Layout

```
backend/   Laravel API — app/Services (business rules), app/Support/Tenancy (isolation), routes/api.php
frontend/  Vue 3 SPA — staff app, /admin (platform), /patient (patient app), /display/:token (TV queue)
docs/      Planning pack (01–08), implementation status (09), generated OpenAPI (openapi.json)
infra/     Dockerfile(s)
```

## Documentation

| # | Document |
|---|---|
| 01 | [Product Requirements](docs/01-product-requirements.md) |
| 02 | [Roles & Permissions](docs/02-roles-and-permissions.md) — enforced by `backend/app/Support/Rbac/PermissionCatalog.php` |
| 03 | [Architecture](docs/03-architecture.md) |
| 04 | [Database Schema & ERD](docs/04-database-schema.md) |
| 05 | [API Specification](docs/05-api-specification.md) + generated [openapi.json](docs/openapi.json) |
| 06 | [Navigation & Design System](docs/06-navigation-and-design-system.md) |
| 07 | [Milestones & Testing](docs/07-milestones-and-testing.md) |
| 08 | [Decisions](docs/08-open-decisions.md) — recommended defaults were adopted |
| 09 | [Implementation Status](docs/09-implementation-status.md) |
