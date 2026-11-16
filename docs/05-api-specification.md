# 05 — API Endpoint Specification (v1, overview)

The authoritative, machine-readable contract will be `docs/openapi.yaml` (OpenAPI 3.1), generated and kept in sync from Phase 3 (Scribe/Scramble + contract tests). This document is the reviewed endpoint inventory.

## 1. Conventions

- Base: `/api/v1` (staff), `/api/platform/v1` (super admin), `/api/patient/v1` (patient app), `/api/public/v1` (unauthenticated: booking page, share links, webhooks).
- Auth: Sanctum (SPA cookie for web apps; bearer token for mobile/integrations). Active tenant bound to session/token; switch via `POST /auth/switch-organization`.
- IDs in URLs and payloads are **ULIDs**.
- Response envelope:
  ```json
  { "data": { ... } , "meta": { "pagination": {"page":1,"per_page":25,"total":240} } }
  ```
  Errors (RFC 7807-style):
  ```json
  { "error": { "code": "SLOT_UNAVAILABLE", "message": "This slot was just booked.", "fields": {"scheduled_start": ["..."]} } }
  ```
  Status codes: 200/201/204, 401, 403 (`FORBIDDEN`), 404 (also used for other-tenant resources), 409 (conflict: slot taken, state transition invalid), 422 (validation), 402 (`PLAN_LIMIT_REACHED` / `FEATURE_NOT_IN_PLAN`), 423 (`ORG_SUSPENDED`), 429.
- Lists: `?page=&per_page=(≤100)&sort=-created_at&filter[status]=booked&filter[branch]=…&include=patient,doctor`.
- Money: integer paise in `amount` + formatted string `amount_display`.
- Dates: ISO-8601 UTC; date-only fields `YYYY-MM-DD` in branch timezone.
- State transitions are explicit action endpoints (`POST …/check-in`) rather than free PATCH of `status`.
- Idempotency: `Idempotency-Key` header honoured on POSTs that create payments, invoices, appointments.
- Rate limiting headers `X-RateLimit-*`.

## 2. Auth & session (`/api/v1/auth`)

| Method | Path | Purpose |
|---|---|---|
| POST | `/auth/register-clinic` | Self sign-up: owner + organisation + first branch (status pending/trial) |
| POST | `/auth/login` | Email/mobile + password → 2FA challenge if enabled |
| POST | `/auth/two-factor/challenge` | TOTP / recovery code |
| POST | `/auth/logout` | |
| POST | `/auth/forgot-password`, `/auth/reset-password` | |
| POST | `/auth/verify-email`, `/auth/verify-mobile` | |
| GET | `/auth/me` | User, memberships, active org, permissions, features, branch scope, plan limits |
| POST | `/auth/switch-organization` | Change active tenant |
| GET/DELETE | `/auth/sessions`, `/auth/sessions/{id}` | Device/session management |
| POST/DELETE | `/auth/two-factor` | Enable/disable 2FA |
| POST | `/invitations/{token}/accept` | Staff invite acceptance |

## 3. Organisation setup

| Method | Path |
|---|---|
| GET/PATCH | `/organization` (profile, logo upload via `/organization/logo`) |
| GET/PATCH | `/organization/settings` |
| GET/PUT | `/organization/specialities` |
| GET/PATCH | `/organization/modules`, `/organization/modules/{key}` |
| GET/POST/PATCH/DELETE | `/branches`, `/branches/{id}` |
| GET/PUT | `/branches/{id}/operating-hours` |
| CRUD | `/holidays` |
| CRUD | `/departments` |
| CRUD | `/services`, `PUT /services/{id}/branch-prices` |
| CRUD | `/tax-rates` |
| GET | `/subscription`, `/subscription/invoices`; POST `/subscription/change-plan`, `/subscription/checkout` |

## 4. Staff, roles, doctors

| Method | Path |
|---|---|
| GET | `/staff` · GET/PATCH `/staff/{id}` · POST `/staff/{id}/deactivate` |
| POST | `/staff/invitations` · DELETE `/staff/invitations/{id}` · POST `/staff/invitations/{id}/resend` |
| GET | `/permissions` (catalogue) · CRUD `/roles` |
| CRUD | `/doctors` (profile + specialities + branches) · POST `/doctors/{id}/signature` |
| GET/PUT | `/doctors/{id}/schedules` |
| CRUD | `/doctors/{id}/leaves` · GET `/doctors/{id}/leaves/{leave}/affected-appointments` |
| GET/PUT | `/doctors/{id}/fees` |
| GET | `/doctors/{id}/availability?branch=&date=` → slots |
| GET | `/doctors/{id}/stats?from=&to=` |

## 5. Patients

| Method | Path |
|---|---|
| GET | `/patients?q=` (name / mobile / code; typeahead) |
| POST | `/patients` (quick or full) · GET `/patients/duplicates-check?mobile=&name=&dob=` |
| GET/PATCH | `/patients/{id}` |
| POST | `/patients/{id}/merge` `{ "source_patient_id": … }` |
| GET | `/patients/{id}/timeline` |
| CRUD | `/patients/{id}/allergies`, `/conditions`, `/medications`, `/history` |
| GET/POST | `/patients/{id}/documents` · GET `/documents/{id}/download` (→ 302 to signed URL) · DELETE (soft, permission) |
| GET/POST | `/patients/{id}/consents` · POST `/consents/{id}/revoke` |
| GET | `/patients/{id}/vitals?type=` (trend) |
| GET | `/patients/{id}/consultations`, `/prescriptions`, `/invoices`, `/lab-orders`, `/appointments` |
| POST | `/patients/{id}/export` (DPDP data request, queued) |

## 6. Appointments, waitlist, queue

| Method | Path |
|---|---|
| GET | `/appointments?filter[date]=&filter[doctor]=&filter[branch]=&filter[status]=` |
| GET | `/appointments/calendar?view=day|week&from=&to=&branch=` |
| POST | `/appointments` (book; 409 `SLOT_UNAVAILABLE`) |
| POST | `/appointments/walk-in` (creates appointment + check-in + token) |
| GET/PATCH | `/appointments/{id}` (notes only) |
| POST | `/appointments/{id}/confirm` · `/reschedule` · `/cancel` · `/check-in` · `/no-show` |
| GET | `/appointments/{id}/history` |
| CRUD | `/waitlist` · POST `/waitlist/{id}/offer` |
| GET | `/queue?branch=&doctor=&date=` (reception: all doctors) |
| GET | `/queue/my` (doctor) |
| POST | `/queue/{token}/call` · `/recall` · `/skip` · `/start` · `/complete` · `/transfer` `{doctor_id}` · `/priority` |
| GET | `/queue/estimates?doctor=` |
| CRUD | `/queue-displays` · GET `/api/public/v1/display/{displayToken}` (public board payload) |

## 7. Consultations & templates

| Method | Path |
|---|---|
| GET | `/consultation-templates?speciality=` · CRUD (org templates) · POST `/consultation-templates/{id}/clone` |
| POST | `/consultations` `{appointment_id}` or `{patient_id}` (draft) |
| GET/PATCH | `/consultations/{id}` (autosave; rejects if signed) |
| PUT | `/consultations/{id}/sections/{key}` |
| POST | `/consultations/{id}/vitals` · `/diagnoses` · DELETE `/diagnoses/{id}` |
| POST | `/consultations/{id}/sign` · `/addenda` |
| GET | `/icd10?q=` |
| CRUD | `/favourites?type=` |

## 8. Prescriptions

| Method | Path |
|---|---|
| GET | `/medicines?q=` · POST `/medicines` (org-custom) |
| GET/PUT | `/consultations/{id}/prescription` (items replace) |
| POST | `/prescriptions/{id}/sign` · `/copy-from/{prevId}` |
| GET | `/prescriptions/{id}/pdf` |
| POST | `/prescriptions/{id}/share` `{channels:["whatsapp","email"], expires_in_hours}` |
| POST | `/prescriptions/check-allergies` (advisory warnings) |
| CRUD | `/rx-templates` |
| GET | `/api/public/v1/s/{shareToken}` (OTP/DOB gate → PDF) |

## 9. Billing

| Method | Path |
|---|---|
| GET | `/invoices?filter[status]=&filter[from]=&filter[patient]=` |
| POST | `/invoices` (draft) · POST `/invoices/from-appointment/{appointmentId}` |
| GET/PATCH | `/invoices/{id}` · PUT `/invoices/{id}/items` |
| POST | `/invoices/{id}/issue` · `/void` · `/discount-requests` · GET `/invoices/{id}/pdf` · POST `/invoices/{id}/share` |
| POST | `/discount-requests/{id}/approve` · `/reject` |
| POST | `/payments` `{patient_id, amount, method, allocations:[{invoice_id, amount}]}` |
| GET | `/payments` · `/payments/{id}/receipt` |
| POST | `/refunds` · `/refunds/{id}/approve` · `/reject` · `/process` |
| GET | `/patients/{id}/balance` |
| GET/POST | `/cash-closings` |

## 10. Lab

| Method | Path |
|---|---|
| GET | `/lab-tests?q=` · CRUD (org) |
| POST | `/lab-orders` · GET `/lab-orders?filter[status]=` (worklist) · GET `/lab-orders/{id}` |
| POST | `/lab-orders/{id}/items/{item}/collect-sample` · `/start` |
| PUT | `/lab-orders/{id}/items/{item}/results` |
| POST | `/lab-orders/{id}/report` (upload PDF) · `/verify` · `/release` · `/share` · `/cancel` |

## 11. Inventory (feature `inventory`)

| Method | Path |
|---|---|
| CRUD | `/inventory/categories`, `/inventory/items`, `/inventory/suppliers` |
| CRUD | `/inventory/purchase-orders` · POST `/inventory/goods-receipts` |
| GET | `/inventory/stock?branch=&low_stock=1&expiring_within_days=60` |
| POST | `/inventory/adjustments` · `/inventory/transfers` · `/inventory/dispense` |
| GET | `/inventory/movements?item=` |

## 12. Notifications

| Method | Path |
|---|---|
| GET/PUT | `/notification-preferences` |
| GET/POST/PATCH | `/notification-templates` (org overrides) |
| GET | `/notification-messages?filter[patient]=&filter[status]=` · POST `/notification-messages/{id}/retry` |
| GET | `/me/notifications` · POST `/me/notifications/read` |
| POST | `/api/public/v1/webhooks/{provider}` (WhatsApp/SMS/payment status — signature verified) |

## 13. Reports

| Method | Path |
|---|---|
| GET | `/dashboard?role=owner|doctor|reception|accountant&from=&to=&branch=` |
| GET | `/reports/appointments`, `/reports/revenue`, `/reports/collections`, `/reports/outstanding`, `/reports/services`, `/reports/doctors`, `/reports/branches`, `/reports/patients`, `/reports/gst`, `/reports/inventory` |
| POST | `/reports/{key}/export` `{format: csv|xlsx|pdf}` → 202 + `export_id` |
| GET | `/exports/{id}` (status + signed download URL) |
| GET | `/audit-logs?filter[user]=&filter[entity]=` |

## 14. Platform (`/api/platform/v1`)

| Method | Path |
|---|---|
| POST | `/auth/login` (+2FA mandatory) |
| GET | `/organizations` · GET `/organizations/{id}` · POST `/organizations/{id}/approve` · `/suspend` · `/reactivate` |
| GET/PATCH | `/organizations/{id}/subscription` · POST `/extend-trial` · `/change-plan` |
| CRUD | `/plans`, `/features`, `/specialities`, `/consultation-templates`, `/medicines`, `/lab-tests`, `/notification-templates` |
| CRUD | `/platform-users` |
| GET/PUT | `/settings`, `/integrations` |
| GET | `/metrics/revenue` (MRR, ARR, churn), `/metrics/usage` |
| GET | `/audit-logs` |
| POST | `/impersonations` `{user_id, reason, ticket_ref}` · DELETE `/impersonations/{id}` |

## 15. Patient app (`/api/patient/v1`, Phase 11)

`POST /auth/otp/request`, `POST /auth/otp/verify`, `GET/PATCH /me`, `CRUD /dependants`, `GET /clinics?city=&speciality=&q=`, `GET /clinics/{slug}`, `GET /doctors/{id}/availability`, `POST /appointments`, `GET /appointments`, `POST /appointments/{id}/cancel|reschedule`, `GET /records/prescriptions|reports|receipts`, `POST /links` (link clinic record via OTP), `GET/POST /consents`, `POST /data-export`, `POST /account/delete`.

## 16. Real-time channels (Reverb)

| Channel | Events |
|---|---|
| `private-org.{o}.branch.{b}.queue` | `QueueUpdated {doctor_id}` |
| `private-org.{o}.branch.{b}.appointments` | `AppointmentChanged {id, status}` |
| `private-org.{o}.user.{u}` | `NotificationCreated`, `LabReportReady`, `ExportReady`, `DiscountApprovalRequested` |
| `display.{token}` | `DisplayUpdated` (token numbers only) |

Payloads are minimal hints; clients refetch via REST.
