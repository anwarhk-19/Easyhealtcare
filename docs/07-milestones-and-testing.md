# 07 — Development Milestones & Testing Strategy

## 1. Milestones

Estimates assume a core team of 2 backend, 2 frontend, 1 QA, 0.5 designer, 0.5 DevOps. Each phase ends with the review gate in §1.2.

| Phase | Scope | Exit criteria (demo-able) | Est. |
|---|---|---|---|
| **1** Product analysis | PRD, roles, MVP scope (this pack) | Approved by product owner | done → review |
| **2** Architecture & DB | Architecture, schema, ERD, API inventory, design system | Approved; ADRs recorded | done → review |
| **3** Foundation: auth, tenancy, permissions | Repo scaffold (Laravel 12 + Vue3/TS monorepo, Docker), CI pipeline, `TenantContext`/scopes/CFKs, Sanctum SPA auth + 2FA, memberships & org switch, Spatie teams + seeded roles/permissions, audit logging, plans/subscriptions/feature gating skeleton, super-admin org approval, sign-up + onboarding wizard shell, design-system base components | Two clinics sign up; isolation test suite green; super admin approves/suspends | 4 wks |
| **4** Clinic, branch, doctor, staff | Org profile, branches, hours, holidays, departments, services/taxes/fees, doctors (profiles, schedules, leaves, fees), staff invites & custom roles | Clinic fully configured via UI; availability endpoint returns correct slots | 3 wks |
| **5** Patients & appointments | Patient registration/search/duplicates/merge, medical history, documents (S3 + AV scan), consents; booking engine, walk-in, lifecycle, reschedule/cancel, waitlist, calendar, real-time | Reception books 100 appointments with zero double-bookings under concurrency test | 4 wks |
| **6** Queue & consultation | Tokens, queue board, call/skip/transfer, wait estimates, TV display; template engine + General template, vitals, ICD-10 diagnoses, autosave, sign/lock/addenda, history rail | End-to-end walk-in → consultation signed | 4 wks |
| **7** Prescriptions, billing, payments | Medicine master, Rx editor, PDF (letterhead), share links; invoices, GST, discounts+approvals, split/partial payments, advances, refunds, receipts, cash closing | Full visit billed, paid, receipt on WhatsApp sandbox | 4 wks |
| **8** Lab & inventory | Lab catalogue, orders, worklist, results, report upload/verify/release; inventory items, batches, GRN, dispense, alerts | Lab report flow + stock ledger reconciles | 4 wks |
| **9** Notifications & reports | Channel adapters (WhatsApp Cloud API/BSP, SMS DLT, SES), templates, preferences, reminders scheduler, delivery webhooks; dashboards per role, report suite, exports | **MVP release candidate**; pilot with 3–5 clinics | 4 wks |
| **10** Speciality modules | Module registry, Dental, Ophthalmology, Physio, Derma, Paeds, Gynae template packs/widgets | New speciality enabled per org without deploy of core | 5 wks |
| **11** Patient app | Patient accounts, OTP, record linking + consent, discovery, booking, records, notifications | Patient books and sees shared Rx | 5 wks |
| **12** AI & advanced integrations | AI gateway with PHI controls, analytics Q&A, message drafting, document extraction (doctor-confirmed); online payments; ABDM (scoping) | DPA flow + audit for every AI call | 4 wks |
| **13** Hardening & production | Pen-test, load test, DR drill, security review, compliance docs, runbooks, production cut-over | Go-live checklist signed off | 3 wks |

MVP (Phases 3–9) ≈ **27 weeks**; full roadmap ≈ 44 weeks. Phases 10–12 can partially overlap with pilot feedback.

### 1.2 Phase review gate (applies to every phase)
1. Code review + static analysis clean (Larastan level 8, ESLint/vue-tsc strict).
2. All acceptance tests for the phase green; tenant-isolation suite green.
3. Manual workflow walkthrough on staging by QA + product owner.
4. Docs updated: OpenAPI spec, ADRs, this pack, changelog.
5. Known issues triaged; P0/P1 fixed.
6. **Explicit approval** recorded before next phase starts.

## 2. Testing strategy

### 2.1 Test pyramid

| Layer | Tooling | Scope / target |
|---|---|---|
| Static | Larastan (lvl 8), Pint, Rector; ESLint, vue-tsc, Prettier | Every commit |
| Unit (backend) | Pest | Value objects (Money, phone), slot calculator, fee rules, GST calc, sequence generator, state machines. ≥ 90% on domain/Actions |
| Feature / API | Pest + Laravel HTTP tests, MySQL 8 + Redis in CI (not SQLite — need generated columns, locks, FULLTEXT) | Every endpoint: auth, validation, policy, happy path, error codes |
| **Tenant isolation** | Pest dataset-driven suite | For each tenant model & route: Org A user cannot list/read/update/delete/link Org B data (expects 404). Also job & broadcast channel isolation. **Blocking in CI.** |
| **Permission matrix** | Pest dataset from `02-roles-and-permissions.md` | Role × permission expected allow/deny; matrix doc and seeder generated from one source file |
| Concurrency | Parallel processes (pcntl / k6 against staging) | Double-booking, token numbering, invoice numbering, stock decrement under contention |
| Contract | OpenAPI validation of responses (spectator) + generated TS client compile | Breaking changes caught |
| Frontend unit/component | Vitest + Vue Test Utils | Components, composables, form schemas |
| E2E | Playwright | Critical journeys: sign-up/onboarding, walk-in → consult → Rx → bill → pay, booking/reschedule/cancel, lab flow, refund approval, role-based nav visibility |
| Accessibility | axe-core in Playwright | No serious/critical violations on key screens |
| PDF | Snapshot (text extraction) tests | Rx/invoice contain mandatory fields (reg. no., GSTIN, etc.) |
| Notifications | Fake providers + webhook replay tests | Consent respected, idempotency, retries |
| Performance | k6 | p95 targets from PRD at 2× expected peak; patient search on 1M-row tenant |
| Security | ZAP baseline (DAST) in CI nightly, composer/npm audit, secret scanning, external pen-test before go-live | OWASP Top 10, IDOR focus |
| Migrations | Up/down on prod-sized anonymised snapshot in staging | Zero-downtime (expand/contract) |
| DR | Quarterly restore drill | RPO/RTO met |

### 2.2 Test data
- Factories for every model with tenant-aware states; seeders for demo clinics per speciality.
- No real patient data in non-production environments — ever. Staging uses synthetic data.

### 2.3 Definition of Done (per story)
Acceptance criteria met · tests (unit + feature, isolation where tenant data) · policy + Form Request · API Resource · OpenAPI updated · audit events where relevant · UI states (loading/empty/error) · a11y checked · reviewed & merged · deployed to staging.

## 3. Architecture Decision Records to file in Phase 3

ADR-001 Modular monolith · ADR-002 Shared-schema tenancy with composite FKs · ADR-003 ULIDs for public IDs · ADR-004 Money in paise · ADR-005 Template-driven consultations + module tables · ADR-006 Sanctum SPA auth · ADR-007 Broadcasts as invalidation hints · ADR-008 AWS Mumbai hosting & data residency · ADR-009 AI gateway & PHI policy.
