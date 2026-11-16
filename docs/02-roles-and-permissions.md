# 02 — User Roles & Permission Matrix

## 1. Model

- **Two permission planes**, never mixed:
  1. **Platform plane** — internal staff (`platform_users`, guard `platform`). Roles: `platform_super_admin`, `platform_support`, `platform_billing`.
  2. **Tenant plane** — clinic staff (`users`, guard `web`/`sanctum`), permissions evaluated **within an organisation** (Spatie Permission "teams" mode, `team_id = organization_id`).
- A tenant user can belong to **multiple organisations** (e.g. a visiting consultant) with a different role in each; the active org is chosen at login / switch and bound to the session/token.
- **Branch scope:** each membership has a list of allowed branches (or "all branches"). Branch scope is a data filter applied *after* the permission check.
- **Patients** use a separate guard (`patient`) and a separate table (`patient_accounts`); they never get tenant permissions.
- System roles are templates; orgs may create **custom roles** by cloning and toggling permissions. System roles cannot be deleted; `clinic_owner` cannot be removed from the last owner.
- Permissions are named `module.action` (e.g. `appointments.create`). Policies combine: permission + tenant match + branch scope + ownership rule (e.g. "own consultations only").

## 2. Data-access modifiers

| Code | Meaning |
|---|---|
| **A** | All records in the organisation |
| **B** | Only records in the user's assigned branches |
| **O** | Only records the user owns/created (e.g. doctor's own consultations) |
| **R** | Read-only |
| **—** | No access |
| **✓** | Allowed (subject to branch scope) |
| **⚑** | Allowed with approval / threshold (e.g. discount above org limit) |

## 3. Tenant role matrix

Roles: **OWN** Clinic Owner · **ADM** Clinic Admin · **BM** Branch Manager · **DOC** Doctor · **REC** Receptionist · **NUR** Nurse · **LAB** Lab Technician · **ACC** Accountant

| Module / Permission | OWN | ADM | BM | DOC | REC | NUR | LAB | ACC |
|---|---|---|---|---|---|---|---|---|
| **Organisation** settings, profile | ✓ | ✓ | R | — | — | — | — | R |
| Subscription & plan, payment of SaaS bill | ✓ | R | — | — | — | — | — | R |
| Branches create/edit | ✓ | ✓ | R(B) | — | — | — | — | — |
| Operating hours, holidays | ✓ | ✓ | ✓(B) | R | R | — | — | — |
| Services & pricing | ✓ | ✓ | R | R | R | — | — | R |
| **Staff** invite/edit/deactivate | ✓ | ✓ | ✓(B)¹ | — | — | — | — | — |
| Roles & permissions (custom roles) | ✓ | ✓² | — | — | — | — | — | — |
| **Doctors** profile/schedule/leave | ✓ | ✓ | ✓(B) | O³ | R | R | — | — |
| **Patients** view demographics | A | A | B | B | B | B | B | B |
| Patients create/edit demographics | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| Patients merge duplicates | ✓ | ✓ | ✓ | — | — | — | — | — |
| Patient **clinical history** (consultations, Rx) | ⚙⁴ | ⚙⁴ | — | ✓⁵ | — | R(limited)⁶ | — | — |
| Patient documents upload | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| **Appointments** create/reschedule/cancel | ✓ | ✓ | ✓ | ✓(O) | ✓ | ✓ | — | — |
| Appointments view calendar | A | A | B | O+B | B | B | — | R |
| **Queue** check-in, issue token | ✓ | ✓ | ✓ | — | ✓ | ✓ | — | — |
| Queue call next / start / complete | — | — | — | ✓(O) | ✓ | ✓ | — | — |
| **Consultation** create/edit/sign | — | — | — | ✓(O) | — | — | — | — |
| Consultation record vitals | — | — | — | ✓ | — | ✓ | — | — |
| Consultation addendum after lock | — | — | — | ✓(O) | — | — | — | — |
| **Prescription** create/sign | — | — | — | ✓(O) | — | — | — | — |
| Prescription print/share | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — |
| **Billing** create invoice | ✓ | ✓ | ✓ | — | ✓ | — | — | ✓ |
| Billing apply discount | ✓ | ✓ | ⚑ | ⚑ | ⚑ | — | — | ⚑ |
| Billing approve discount over threshold | ✓ | ✓ | ✓ | — | — | — | — | ✓ |
| Payments collect | ✓ | ✓ | ✓ | — | ✓ | — | — | ✓ |
| Refunds request | ✓ | ✓ | ✓ | — | ✓ | — | — | ✓ |
| Refunds approve | ✓ | ✓ | — | — | — | — | — | ✓ |
| Invoice cancel/void | ✓ | ✓ | — | — | — | — | — | ✓ |
| **Lab** order tests | — | — | — | ✓ | ✓ | — | — | — |
| Lab worklist, sample, results | — | — | — | R | — | ✓ | ✓ | — |
| Lab report verify/release | — | — | — | ✓ | — | — | ✓⁷ | — |
| **Inventory** items, suppliers | ✓ | ✓ | ✓ | — | — | R | — | R |
| Inventory purchase/GRN, adjust | ✓ | ✓ | ✓ | — | — | — | — | ✓ |
| Inventory dispense | ✓ | ✓ | ✓ | — | ✓ | ✓ | — | — |
| **Notifications** templates/config | ✓ | ✓ | — | — | — | — | — | — |
| **Reports** financial | A | A | B | O | B(daily own)⁸ | — | — | A |
| Reports operational/clinical stats | A | A | B | O | B | — | B(lab) | — |
| Exports | ✓ | ✓ | ✓ | ✓(O) | — | — | — | ✓ |
| **Audit log** view | ✓ | ✓ | B | — | — | — | — | — |
| **AI** features enable (org) | ✓ | — | — | — | — | — | — | — |
| AI features use | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | ✓ |

**Notes**
1. Branch manager can manage staff only in their branches and cannot assign `clinic_owner`/`clinic_admin`.
2. Admin cannot grant permissions they do not hold, and cannot edit the owner.
3. Doctor can edit own profile/schedule/leave if org setting `doctors_self_manage_schedule` is on; otherwise read-only.
4. Owner/Admin access to clinical notes is **off by default** and controlled by org setting `admins_can_view_clinical`. When the owner is also a doctor, they hold both roles.
5. Doctor sees clinical history of patients in their branches. Org setting `clinical_visibility` = `all_doctors` (default) | `treating_doctor_only` | `same_speciality`.
6. Nurse sees allergies, conditions, current medications, vitals and latest Rx — not free-text doctor notes.
7. Release by technician only if org setting allows; else requires doctor verification.
8. Receptionist sees own day's collection only.

Every access to a patient's **clinical** record is written to `audit_logs` (event `clinical.viewed`) — a read audit, not just write.

## 4. Platform role matrix

| Permission | Super Admin | Support | Billing |
|---|---|---|---|
| Organisations list/view metadata | ✓ | ✓ | ✓ |
| Approve / suspend / reactivate orgs | ✓ | — | — |
| Plans & pricing CRUD | ✓ | — | R |
| Subscriptions adjust (extend, comp, change plan) | ✓ | — | ✓ |
| SaaS invoices & payments | ✓ | — | ✓ |
| Speciality catalogue, global templates, medicine master | ✓ | ✓ | — |
| Integrations & global settings | ✓ | — | — |
| Platform users management | ✓ | — | — |
| Platform audit log | ✓ | R(own actions) | — |
| Impersonate tenant user (support session) | ✓ | ⚑⁹ | — |
| View tenant **clinical** data | — ¹⁰ | — | — |

9. Impersonation requires: reason, ticket ref, max 60 min, the org owner is notified, every request during the session is tagged `impersonated_by`.
10. Platform staff never see clinical data by default. Break-glass requires written org consent + dual approval; out of MVP.

## 5. Patient (app) permissions

- Own profile & linked dependants (minor children).
- Appointments they booked or which clinic linked to their account.
- Rx / reports / receipts **only** when the clinic has shared them (per-record `shared_with_patient_at`) and the patient has linked the clinic record (OTP-verified mobile match + consent).
- Consent centre: grant/revoke per-org sharing, download data, request deletion of account (clinical records stay with the clinic as legally required; the link is severed).

## 6. Permission catalogue (seed list)

```
organization.view organization.update subscription.view subscription.manage
branches.view branches.manage schedules.manage holidays.manage
services.view services.manage
staff.view staff.manage roles.manage
doctors.view doctors.manage doctors.manage_own
patients.view patients.create patients.update patients.merge patients.delete
patients.view_clinical patients.documents.upload patients.documents.view
appointments.view appointments.view_all appointments.create appointments.update appointments.cancel
queue.view queue.manage queue.call
consultations.view consultations.create consultations.update consultations.sign consultations.addendum
vitals.record
prescriptions.create prescriptions.sign prescriptions.print prescriptions.share
invoices.view invoices.create invoices.void discounts.apply discounts.approve
payments.collect refunds.request refunds.approve
lab.order lab.worklist lab.results.enter lab.results.release
inventory.view inventory.manage inventory.purchase inventory.adjust inventory.dispense
notifications.manage
reports.financial reports.operational reports.export
audit.view
ai.manage ai.use
```
