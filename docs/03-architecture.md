# 03 — System, Multi-Tenant & Deployment Architecture

## 1. Architectural style

**Modular monolith** (one Laravel 12 app, one deployable) with strict module boundaries, plus a separate **Vue 3 + TypeScript SPA** for staff and (later) a patient app. Rationale: a small team ships faster with one codebase and one database transaction boundary; modules are designed so any one (e.g. Notifications, Reports) can be extracted into a service later.

```
┌─────────────────────────────────────────────────────────────────────┐
│  Clients                                                            │
│  Staff SPA (Vue3/TS)   TV Queue Display   Patient App (PWA→native)  │
│  Super-admin SPA       Public booking page                          │
└──────────────┬──────────────────────────────────┬───────────────────┘
               │ HTTPS (REST /api/v1, JSON)       │ WSS (Reverb)
┌──────────────▼──────────────────────────────────▼───────────────────┐
│  Edge: CDN (static SPA) · WAF · Load balancer (TLS)                  │
└──────────────┬──────────────────────────────────┬───────────────────┘
┌──────────────▼───────────────┐   ┌──────────────▼───────────────────┐
│ Laravel app (PHP-FPM/Octane) │   │ Laravel Reverb (WebSocket)        │
│  Middleware: auth, tenant,   │   │  private-org.{id}.branch.{id}...  │
│  branch, plan-feature, rate  │   └───────────────────────────────────┘
│  Modules (Domain/App/Http)   │
└──────┬─────────┬─────────────┘
       │         │ dispatch jobs/events
┌──────▼───┐ ┌───▼────────┐ ┌──────────────┐ ┌───────────────────────┐
│ MySQL 8  │ │ Redis      │ │ Queue workers│ │ Scheduler (cron)       │
│ primary  │ │ cache,     │ │ (Horizon):   │ │ reminders, expiry,     │
│ + replica│ │ queue,     │ │ notifications│ │ subscription renewals, │
│ (reports)│ │ locks,     │ │ pdf, exports,│ │ low-stock, cleanup     │
└──────────┘ │ sessions   │ │ ai, webhooks │ └───────────────────────┘
             └────────────┘ └──────┬───────┘
                                   │
     ┌──────────────┬──────────────┼─────────────┬───────────────┐
     ▼              ▼              ▼             ▼               ▼
  S3 (ap-south) WhatsApp BSP   SMS (DLT)   Email (SES)   Payment GW / LLM
  private bucket (Meta Cloud    gateway                    (Razorpay) (opt-in)
  + KMS          API / BSP)
```

## 2. Backend code organisation

```
app/
  Core/                     # cross-cutting: tenancy, auth, audit, settings, money, files
    Tenancy/  (TenantContext, BelongsToTenant trait, TenantScope, middleware)
    Audit/    (Auditable trait, AuditLogger, observers)
    Support/  (Money VO, PhoneNumber VO, SequenceGenerator, Pdf)
  Modules/
    Platform/        Organizations/   Branches/   Staff/      Doctors/
    Patients/        Appointments/    Queue/      Consultations/
    Prescriptions/   Billing/         Lab/        Inventory/
    Notifications/   Reports/         Subscriptions/  Specialities/
    PatientPortal/   AI/
      each module:
        Models/  Actions/ (single-purpose use cases)  Services/
        Http/Controllers/  Http/Requests/  Http/Resources/
        Policies/  Events/  Listeners/  Jobs/  Enums/
        Database/ (migrations, factories, seeders)  routes.php  Tests/
```

Rules:
- Controllers are thin: validate (Form Request) → authorise (Policy) → call an **Action** (e.g. `BookAppointment`, `IssueToken`, `FinalizeInvoice`) → return **API Resource**.
- Actions own transactions and emit **domain events** (`AppointmentBooked`, `ConsultationSigned`, `InvoicePaid`). Notifications, real-time broadcasts, audit and analytics react via listeners/queued jobs — modules never call each other's internals directly, only public Actions/Contracts.
- Money stored as **integer paise** (`BIGINT`), wrapped in a `Money` value object.
- All enums are PHP backed enums, stored as `VARCHAR` (not MySQL ENUM, for safe migrations).
- Timestamps stored UTC; branch timezone used for presentation and slot computation.

## 3. Multi-tenancy design

### 3.1 Strategy: shared database, shared schema, `organization_id` discriminator

| Option | Isolation | Cost / ops at 5k tenants | Cross-tenant platform reports | Chosen |
|---|---|---|---|---|
| DB per tenant | Strongest | High (5k schemas, migrations fan-out) | Hard | ✗ (Enterprise add-on later) |
| Schema per tenant | Strong | High in MySQL (schema = DB) | Hard | ✗ |
| **Shared schema + tenant column** | Logical, enforced in app + tests | Low | Easy | **✓** |

The design keeps the door open for "dedicated database" Enterprise tenants: the tenant resolver can return a connection name, and every tenant table already carries `organization_id`.

### 3.2 Enforcement layers (defence in depth)

1. **Tenant resolution** — `ResolveTenant` middleware: from the authenticated user's active membership (stored on the Sanctum token / session `current_organization_id`). Never from a client-supplied header alone; an `X-Organization-Id` header is accepted only if the user has an active membership in it.
2. **TenantContext** singleton — holds current org, branch scope, and user; set once per request/job. Queued jobs serialise `organization_id` and restore context in a job middleware (`RestoreTenantContext`).
3. **Global scope** — `BelongsToTenant` trait adds `TenantScope` (`where organization_id = ?`) and auto-fills `organization_id` on create. If no tenant context is set and the model is tenant-scoped, the scope **throws** (fail closed), except under explicit `Tenant::bypass(fn() => …)` used by platform code — and every bypass is logged.
4. **Branch scope** — `BelongsToBranch` trait adds a second scope from the membership's allowed branches.
5. **Composite foreign keys** — child tables reference parents with `(organization_id, id)` composite FKs where cross-tenant mis-linking would be harmful (e.g. `appointments(organization_id, patient_id) → patients(organization_id, id)`). This makes it *physically impossible* to link a record to another tenant's patient even if application code has a bug.
6. **Route-model binding** — models are resolved through scoped queries so `/patients/{id}` of another tenant returns 404, not 403 (no existence leak).
7. **Policies** — every controller action authorises; a CI test asserts every route has a policy or explicit `->withoutAuthorization()` allow-list.
8. **Unique constraints scoped** — e.g. `UNIQUE(organization_id, patient_code)`.
9. **Caching** — cache keys always prefixed `org:{id}:`; helper `TenantCache` makes un-prefixed tenant caching impossible.
10. **Files** — S3 path `orgs/{org_uuid}/{module}/{yyyy}/{mm}/{uuid}.{ext}`; downloads only via signed, short-lived (5 min) URLs issued after a policy check.
11. **Broadcast channels** — `private-org.{orgId}.branch.{branchId}.queue`, authorised in `channels.php` against membership + branch scope.
12. **Automated isolation test suite** — for every tenant model and every API route: create data in Org A and Org B, act as Org A user, assert B's data is never returned/mutable. Runs in CI on every PR.

### 3.3 IDs

- Internal PK: `BIGINT UNSIGNED AUTO_INCREMENT` (index-friendly).
- Public identifier: `ulid CHAR(26)` on externally exposed entities (patients, appointments, invoices, documents, share links). APIs expose ULIDs only → no enumeration.
- Human codes: patient code, invoice number, token number generated by `SequenceGenerator` using a `sequences` table row lock (`SELECT … FOR UPDATE`) per `(organization_id, branch_id, key, period)`.

## 4. Speciality extensibility

Goal: add a speciality without touching core tables.

1. **Speciality catalogue** (`specialities`) — platform-managed.
2. **Consultation templates** (`consultation_templates`, versioned) — a JSON definition of sections and fields:
   ```json
   {"sections":[
     {"key":"vitals","widget":"core.vitals"},
     {"key":"complaints","widget":"core.complaints"},
     {"key":"eye_exam","widget":"ophthalmology.refraction","required":false},
     {"key":"custom","title":"Ortho exam","fields":[
        {"key":"rom_knee_left","type":"number","unit":"deg","min":0,"max":180},
        {"key":"gait","type":"select","options":["Normal","Antalgic","Trendelenburg"]}]}
   ]}
   ```
   - `widget` refers to a registered frontend component + backend validator (`SpecialityWidget` contract). Simple speciality needs use generic `fields`; rich ones (dental chart) ship a widget.
3. **Storage**
   - Generic field data: `consultation_sections(consultation_id, section_key, template_version, data JSON)`.
   - Rich, queryable speciality data gets **its own module tables** (e.g. `dental_tooth_records`, `eye_examinations`, `therapy_sessions`) — registered by a module service provider; core never references them.
4. **Module registry** (`modules` + `organization_modules`) — each speciality module declares: key, required plan feature, migrations, permissions, widgets, report providers. Enabling it for an org is a row, not a deploy.
5. Orgs can clone a platform template and customise; consultations store `template_id + version` so old records render exactly as captured.

## 5. Subscription & feature gating

- `plans` define limits (`max_doctors`, `max_branches`, `max_staff`, `max_patients`, `storage_gb`, `monthly_whatsapp_quota`) and `features` (keys like `inventory`, `lab`, `speciality.dental`, `ai.analytics`, `patient_app`).
- `EnsureFeature:inventory` route middleware + `Feature::for($org)->allows('inventory')` in code; limit checks in Actions (`AddDoctor` checks `max_doctors`).
- Subscription states: `trialing → active → past_due → grace → suspended → cancelled`. Suspended orgs: read-only for 30 days (data export allowed), then locked; data retained per contract.
- Payment gateway via `SubscriptionGateway` interface (Razorpay subscriptions first); manual invoicing for Enterprise.

## 6. Real-time

- Laravel Reverb; events implement `ShouldBroadcast` and are queued.
- Channels: `org.{o}.branch.{b}.queue` (queue changes), `org.{o}.doctor.{u}` (doctor's queue, lab-ready alerts), `org.{o}.user.{u}` (personal notifications), `display.{displayToken}` (TV board — a public channel keyed by an unguessable token carrying only token numbers and initials).
- Clients treat broadcasts as **invalidation hints** and refetch via REST → no sensitive payloads over sockets, and missed events are harmless.

## 7. Notifications pipeline

`Domain event → NotificationDispatcher (rules: org channel prefs, patient consent/opt-out, quiet hours, quota) → notification_messages row (queued) → SendJob per channel → Provider adapter (WhatsAppCloudApi | Gupshup | Msg91Sms | SesEmail | FcmPush) → webhook updates delivery status`.
- WhatsApp: template-based outside 24-h window; templates stored with provider template name + variable map; documents (Rx/invoice PDF) sent as signed-URL media.
- Idempotency key per (event, recipient, channel) to prevent duplicates on retries.

## 8. Security architecture

| Control | Implementation |
|---|---|
| AuthN (staff) | Sanctum SPA cookie auth (same-site, CSRF via `/sanctum/csrf-cookie`); email/mobile + password; **TOTP 2FA** mandatory for owner/admin/accountant, optional others; device/session list with remote logout |
| AuthN (patient) | Mobile OTP (rate-limited, 5-min expiry, hashed) → Sanctum personal token for app |
| AuthN (API integrations) | Sanctum tokens with abilities, org-bound |
| Passwords | Argon2id; breached-password check (k-anonymity); reset tokens single-use 30 min; all sessions revoked on reset |
| Sessions | Idle timeout 30 min (configurable), absolute 12 h; Redis-backed |
| AuthZ | Spatie Permission (teams) + Policies + branch scope + clinical visibility rule |
| Rate limiting | Per-IP and per-user limiters: login 5/min, OTP 3/10min, API 120/min user, exports 5/hour |
| Input | Form Requests; strict types; file uploads: mime sniff, size caps, AV scan job (ClamAV) before available |
| Encryption in transit | TLS 1.2+; HSTS |
| Encryption at rest | RDS/MySQL storage encryption + S3 SSE-KMS; **field-level** Laravel encrypted casts for: ABHA no., ID numbers, clinical free-text notes, AI prompts; searchable fields (mobile) stored as normalised value + HMAC **blind index** if encryption required by policy |
| Audit | Append-only `audit_logs` (who, org, action, entity, before/after diff for writes, IP, UA, impersonator); clinical reads logged; shipped daily to WORM storage (S3 Object Lock) |
| Secrets | AWS Secrets Manager / SSM; never in repo; per-org integration creds encrypted |
| Headers | CSP, X-Frame-Options DENY (except display page), Referrer-Policy, Permissions-Policy |
| Backups | Automated daily snapshots + binlog PITR (35 days), cross-region encrypted copy, quarterly restore drill |
| Consent | `consents` table (purpose, channel, version, granted/revoked timestamps, evidence) checked by notification and sharing services |
| Data rights | Export patient data (JSON+PDF), account deletion workflow, retention policy jobs |
| Dependency security | `composer audit`, `npm audit`, Dependabot, SAST (Larastan + Psalm taint), DAST (ZAP) in CI |

## 9. Deployment architecture (AWS, ap-south-1 Mumbai; DR in ap-south-2 Hyderabad)

| Layer | Service |
|---|---|
| Static SPA | S3 + CloudFront |
| WAF | AWS WAF (OWASP managed rules, rate rules) |
| App | ECS Fargate (or EKS later): services `api` (PHP-FPM+Nginx or Octane/FrankenPHP), `reverb`, `horizon` workers (queues: `critical`, `notifications`, `default`, `pdf`, `exports`, `ai`), `scheduler` (single task) |
| DB | Amazon RDS / Aurora MySQL 8, Multi-AZ, 1 read replica for reports |
| Cache/queue | ElastiCache Redis (cluster mode off at start), Multi-AZ |
| Files | S3 private bucket, SSE-KMS, versioning, lifecycle to IA after 90 days |
| Email | Amazon SES |
| Observability | CloudWatch + Sentry (PII scrubbing) + OpenTelemetry traces; Laravel Pulse for app metrics; uptime checks |
| CI/CD | GitHub Actions: lint → static analysis → tests (MySQL+Redis services) → build images → deploy staging → manual approval → prod (blue/green via ECS) ; migrations run as a one-off task, backward-compatible (expand/contract) |
| Environments | local (Docker/Sail), CI, staging (anonymised data only), production |

Alternative for early stage / lower cost: a managed VPS stack (e.g. Laravel Forge/Cloud on AWS Mumbai) with the same components on fewer nodes; migrate to ECS when > ~300 active clinics.

## 10. Frontend architecture

- Vue 3 + TypeScript + Vite + Tailwind; **Pinia** (state), **Vue Router**, **TanStack Query (vue-query)** for server state & cache invalidation, **VeeValidate + Zod** for forms, **Headless UI**/Radix-Vue for accessible primitives.
- Typed API client generated from the OpenAPI spec (`openapi-typescript`), so backend contract changes break the frontend build, not production.
- Apps in one monorepo workspace: `apps/staff`, `apps/admin` (super admin), `apps/display` (TV), `apps/patient` (Phase 11), shared `packages/ui` (design system) and `packages/api`.
- Permission-aware UI: `/api/v1/me` returns permissions, features, branch scope; route guards + `v-can` directive (UI hints only — server is authoritative).
- Speciality widgets registered in a widget registry matching template `widget` keys, lazy-loaded.

## 11. Repository layout (proposed)

```
/backend        Laravel 12 app
/frontend       pnpm workspace (apps/*, packages/*)
/docs           these documents + OpenAPI spec (docs/openapi.yaml)
/infra          Terraform, Docker, CI
docker-compose.yml   local stack: php, mysql, redis, reverb, mailpit, minio
```
