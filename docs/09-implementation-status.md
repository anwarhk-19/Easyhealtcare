# 09 — Implementation Status (Phases 3–13)

_Last updated: 2026-11-16. Branch `claude/clinic-management-saas-rhz0oq`._

## Verification

| Check | Result |
|---|---|
| Backend tests (PHPUnit), **MySQL 8.0.46** | 61 tests, 333 assertions, all passing |
| Backend tests, SQLite (fast local loop) | same 61 passing |
| Frontend typecheck (`vue-tsc --noEmit`, strict) | clean |
| Frontend unit tests (Vitest) | 4 passing |
| Frontend production build (Vite) | builds |
| Browser end-to-end (Playwright vs. real API + seeded demo clinic) | 9/9 steps pass: reception walk-in → doctor queue → consultation (diagnosis, Rx, allergy warning) → sign → bill → pay → owner dashboard → patient-app OTP login at phone width |
| `composer audit` / `npm audit --omit=dev` | 0 advisories |
| OpenAPI inventory (`php artisan docs:openapi --check`) | 206 paths, in sync with routes |

## Phase-by-phase

| Phase | Status | Where |
|---|---|---|
| 3 Auth, tenancy, permissions | ✅ Done | `app/Support/Tenancy/*`, `app/Support/Rbac/PermissionCatalog.php`, `ResolveTenant` middleware, `AuthController` (login, TOTP 2FA, sessions, reset, invitations), `TenantIsolationTest`, `PermissionMatrixTest` |
| 4 Clinic, branch, doctor, staff | ✅ Done | `OrganizationController`, `BranchController` (+ hours), `ServiceController`, `DoctorController` (schedules, fees, leave + affected appointments, availability, stats), `StaffController` (invite, roles, branch scope, last-owner guard), `RoleController` (custom roles, no escalation) |
| 5 Patients & appointments | ✅ Done | `PatientController` (search, duplicate check, merge, history, documents, consents, DPDP export), `AppointmentService` (book/walk-in/confirm/check-in/reschedule/cancel/no-show, waitlist offer), DB-enforced double-booking guard |
| 6 Queue & consultation | ✅ Done | `QueueService` (tokens, priority, call-next, skip, transfer, wait estimates), TV display (public, initials only), `ConsultationService` (template-validated sections, vitals, ICD-10, sign/lock, addenda), `ClinicalAccess` |
| 7 Prescription, billing, payments | ✅ Done | `PrescriptionService` (Rx, advisory allergy check, PDF, expiring share links with 2nd factor), `BillingService` (GST lines, discount approval threshold, FY invoice numbering, partial/advance payments, idempotency, refunds with approval, cash closing) |
| 8 Lab & inventory | ✅ Done | `LabService` (orders, sample, results with range/critical flags, report upload, release, share), `InventoryService` (GRN, batches, FEFO dispense, adjustments, transfers, ledger) |
| 9 Notifications & reports | ✅ Done | `NotificationService` (consent-checked, template-rendered, idempotent), drivers: log / WhatsApp Cloud API / MSG91 / mail, WhatsApp webhook with signature check, reminder scheduler; `ReportService` (dashboard KPIs, doctor/service/branch, GST, CSV export with formula-injection guard) |
| 10 Speciality modules | ✅ Done | 10 platform templates (general, dental, dermatology, ophthalmology, physiotherapy, paediatrics, gynaecology, cardiology, orthopaedics, ENT); dental chart (FDI), treatment plans/physio sessions with progress, eye exam; new specialities = data via platform API |
| 11 Patient app | ✅ API + PWA-style web UI | `PatientAppController`, `PatientPortalService` (OTP, consented linking, booking, shared records, consent centre, account deletion) |
| 12 AI & integrations | ✅ Admin-only AI | `AiService` + `AnthropicProvider` (official PHP SDK, `claude-opus-5-5`) / `FakeProvider`; off by default, plan-gated, PHI features need DPA flag, prompts encrypted, every call logged, outputs are drafts |
| 13 Testing, security, deployment | ✅ Code-level done; see gaps | CI workflow (MySQL 8 service), Docker compose, Dockerfile, security headers, rate limits, audits |

## Deviations from the planning documents (and why)

| Plan | Built | Reason |
|---|---|---|
| BIGINT PK + separate `ulid` column | **ULID primary keys** everywhere | Removes the dual-id mapping layer entirely; no enumerable IDs anywhere. Composite tenant FKs still apply. |
| Spatie Permission (teams) | **In-house RBAC** (`roles`, `permissions`, `membership_role`; catalogue in code) | Tenant semantics are explicit and testable; one source of truth for seeder + tests; no team-id global state. |
| Separate `platform_users` table | `users.platform_role` + separate `/api/platform` plane and middleware | Simpler auth; platform routes never run with tenant context. |
| Sanctum SPA cookies | Sanctum **bearer tokens** (12 h expiry, revocable), stored in `sessionStorage` | Works for web, patient app and integrations alike. Switch to cookie mode for a first-party domain in production (see below). |
| Generated `slot_lock` column | Model-maintained `slot_lock` + UNIQUE index | Same DB-level guarantee; portable across MySQL and SQLite tests. |
| Monorepo of 4 frontend apps | One Vue SPA with route areas (`/`, `/admin`, `/patient`, `/display/:token`) | Faster to ship; split later if bundles grow. |
| Monthly partitioning of audit/notification tables | Not yet | Add when volume requires (needs FK-free tables, already designed that way). |
| `daily_metrics` pre-aggregation | Live aggregates | Fine at current scale; add nightly rollups + read replica as data grows. |

## Known gaps — required before production

These are deliberately listed rather than hidden:

1. **Real-time sockets on the client.** Backend broadcasts `QueueUpdated` / `AppointmentChanged` over Reverb with authorised private channels, but the Vue app currently refreshes by **polling every 15 s**. Add `laravel-echo` + `pusher-js` and subscribe to the channels.
2. **Antivirus scanning.** Uploads record `scan_status`; with `CLINIC_ANTIVIRUS_ENABLED=true` they stay `pending` until a ClamAV job (not yet written) marks them clean.
3. **Payment gateway.** SaaS subscriptions and patient payment links are recorded manually (`mark_invoice_paid`). Razorpay integration is not built.
4. **Production notification credentials.** WhatsApp Cloud API and MSG91 drivers are implemented but untested against live accounts; WhatsApp templates must be approved by Meta and SMS templates DLT-registered.
5. **AI provider** is verified only through the fake provider in tests; the Anthropic SDK path compiles and is wired but has not been exercised against the live API.
6. **ABDM/ABHA** integration, telemedicine, insurance/TPA — out of scope (ABHA number is captured and encrypted).
7. **Localisation** (Hindi/regional) — strings are English only.
8. **Non-functional verification**: load test (k6), external penetration test, DR restore drill, accessibility audit (axe) — not run.
9. **Legal**: DPDP Act / IT Act review, DPA templates, data retention policy and clinical-record retention rules need counsel sign-off.
10. **Infrastructure as code** (Terraform for AWS Mumbai) is described in `03-architecture.md` but not written; `docker-compose.yml` covers local development.

## Demo data

`php artisan migrate:fresh --seed` creates a demo clinic (password `password` for all):
`owner@demo.clinic`, `doctor1@demo.clinic` (GP), `doctor2@demo.clinic` (dental), `doctor3@demo.clinic` (eye), `reception@demo.clinic`, `nurse@demo.clinic`, `lab@demo.clinic`, `accounts@demo.clinic`. Patient app OTP in non-production is `123456`. Set `PLATFORM_ADMIN_EMAIL`/`PASSWORD` before seeding to create a super admin.
