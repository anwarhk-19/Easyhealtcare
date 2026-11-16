# 01 — Product Requirements Document (PRD)

**Product working name:** EasyHealthcare CMS
**Status:** Draft for review (Phase 1)
**Audience:** Product owner, engineering, design, compliance

---

## 1. Problem statement

Indian clinics — from a single GP to multi-branch, multi-speciality groups — run on paper registers, spreadsheets, or speciality-specific desktop tools that do not talk to each other. Reception, doctors, billing and labs work in silos. Patients get no digital prescriptions, reminders or receipts. Owners have no reliable view of revenue, doctor utilisation or outstanding dues.

## 2. Goals

| # | Goal | Measure of success |
|---|------|--------------------|
| G1 | Register a returning patient and book them in < 30 s | Median front-desk booking time |
| G2 | Doctor completes a routine consultation + prescription in < 3 min | Median consultation-close time |
| G3 | Zero cross-tenant data exposure | Automated isolation tests, pen-test, audit |
| G4 | One codebase serves every speciality | New speciality shipped as config/template, no core change |
| G5 | Self-serve onboarding: sign-up → first appointment in < 15 min | Onboarding funnel |
| G6 | Sustainable SaaS revenue | MRR, trial→paid conversion, churn |

## 3. Non-goals (for now)

- Inpatient / IPD, bed management, OT scheduling (hospital features).
- Full pharmacy retail POS (inventory is scoped to clinic consumables + dispensing).
- Insurance/TPA claim processing (planned post-MVP).
- AI diagnosis or AI prescribing — **never** a goal (see §9).
- Telemedicine video (post-MVP; schema leaves room for it).

## 4. Personas

| Persona | Context | Top needs |
|---|---|---|
| **Solo GP (owner + doctor)** | 1 doctor, 1 receptionist or none | Very fast booking, simple Rx, daily collection |
| **Receptionist** | Busy front desk, phone + walk-ins | Patient search by mobile, one-screen booking, queue control, billing |
| **Doctor (multi-doctor clinic)** | 30–80 patients/day | Queue view, history at a glance, templates, 1-click Rx PDF |
| **Clinic owner / group admin** | 2–20 branches | Revenue, utilisation, staff, branch comparison, controls on discounts |
| **Nurse** | Vitals, triage | Quick vitals entry against queue |
| **Lab technician** | In-house diagnostics | Worklist, report upload, notify doctor |
| **Accountant** | Finance | Invoices, refunds, outstanding, GST reports, exports |
| **Patient** | Smartphone, WhatsApp-first | Book, reminders, see Rx/reports/receipts, control consent |
| **Platform super admin** | Our company | Approve/suspend clinics, plans, revenue, support |

## 5. Core concepts & glossary

- **Organisation (Tenant)** — the paying customer. All clinical/financial data belongs to exactly one organisation. A "clinic" in the brief = an organisation.
- **Branch** — a physical location of an organisation. Every organisation has ≥ 1 branch. Appointments, queues, invoices and stock are branch-scoped.
- **Speciality** — platform-level catalogue (Dental, Dermatology, …). An organisation enables one or more; doctors are tagged with them.
- **Module** — a toggleable feature set (Inventory, Lab, Dental Chart, AI …) gated by plan and by org setting.
- **Consultation template** — a versioned, speciality-linked form definition (JSON schema) that drives the consultation workspace.
- **Encounter / Consultation** — one doctor-patient interaction, usually linked to an appointment.
- **Patient** — belongs to an organisation (shared across its branches). A person visiting two unrelated organisations has two independent patient records. Optional link to a global **Patient Account** (patient app) via explicit consent.

## 6. Module-wise feature breakdown

Priority: **M** = MVP (Phases 3–9), **P** = Post-MVP v1.x (Phases 10–12), **L** = Later.

### A. Platform Super Admin
| Feature | Pri |
|---|---|
| Clinic (organisation) list, search, detail, approve / suspend / reactivate | M |
| Plan management (price monthly/annual, limits, feature flags, trial days) | M |
| Subscription view per org, manual extend/comp, payment history | M |
| Speciality catalogue + default templates | M |
| Platform users (internal staff) with own roles (support, billing, admin) | M |
| Global settings (default tax, notification providers, branding) | M |
| Integration credentials (WhatsApp BSP, SMS, email, payment gateway, AI) | M |
| Platform audit log + support "impersonation" (time-boxed, reason, logged, org-visible) | M |
| Subscription revenue dashboard (MRR, ARR, churn, trials) | M |
| Platform activity monitoring (active orgs, usage, queue health) | P |

### B. Clinic (Organisation) Management
| Feature | Pri |
|---|---|
| Self sign-up → org created in *pending/trial* state | M |
| Profile: legal name, display name, logo, GSTIN, registration no., contacts | M |
| Specialities enabled | M |
| Branches: address, contact, timezone, letterhead, invoice prefix | M |
| Operating hours per branch per weekday (multiple sessions/day) | M |
| Holidays (org-wide or branch) | M |
| Departments | M |
| Services catalogue (consultation, procedure, diagnostic, package) with price, tax, branch overrides | M |
| Consultation fee rules (first visit / follow-up within N days / emergency) | M |
| Settings: patient ID format, token reset rules, slot length, reminder timings, discount approval threshold | M |

### C. Doctor Management
| Feature | Pri |
|---|---|
| Doctor profile (user + doctor profile): qualifications, specialities, registration council & no., signature image, bio | M |
| Branch assignment (many-to-many) | M |
| Fee overrides per doctor (per branch) | M |
| Weekly schedule templates per branch (sessions, slot length, max patients) | M |
| Leave / unavailability, with auto-handling of booked appointments (notify + reschedule list) | M |
| Appointment & consultation history | M |
| Performance report (consults, revenue, avg time, no-show rate) | M |

### D. Patient Management
| Feature | Pri |
|---|---|
| Quick registration (name, mobile, gender, age *or* DOB) — < 15 s | M |
| Auto patient code (configurable: `{PREFIX}-{YYYY}-{SEQ}`) | M |
| Full profile: address, emergency contact, blood group, ABHA no. (optional) | M |
| Medical history, allergies (with severity), chronic conditions, current medications | M |
| Family/guardian linking (child under parent's mobile) | M |
| Duplicate detection (same mobile + similar name + DOB) & merge | M |
| Documents upload (secure, categorised) | M |
| Timeline: visits, Rx, reports, invoices | M |
| Search by name / mobile / patient code (fast, typo-tolerant prefix search) | M |
| Consent records (data processing, communication channels, data sharing) | M |

### E. Appointment Management
| Feature | Pri |
|---|---|
| Book by doctor/speciality/branch/date/slot | M |
| Walk-in (instant check-in + token) | M |
| Slot availability engine (schedule − leave − holidays − booked) | M |
| Double-booking prevention (DB-enforced) | M |
| Status lifecycle: Booked → Confirmed → Checked-in → Waiting → In consultation → Completed / Cancelled / No-show | M |
| Reschedule & cancel (reason, history kept) | M |
| Waiting list (auto-offer freed slots) | M |
| Reminders (T-24h, T-2h configurable) | M |
| Real-time status updates across screens | M |
| Calendar views (day/week, per doctor, per branch) | M |
| Online booking via patient app / public booking page | P |
| Recurring appointments (physio sessions) | P |

### F. Queue & Token
| Feature | Pri |
|---|---|
| Auto token on check-in (per doctor per branch per day; configurable prefix) | M |
| Doctor-wise live queue; priority flags (emergency, elderly) | M |
| Call next / skip / recall / transfer to another doctor | M |
| Start / complete consultation from queue | M |
| Estimated waiting time (rolling avg consultation duration × position) | M |
| Reception dashboard (all doctors' queues) | M |
| TV display screen (token board, public-safe — no full names) | M |

### G. Consultation Workspace
| Feature | Pri |
|---|---|
| Patient header (age, gender, allergies flag, chronic conditions) | M |
| Previous visits sidebar (permission-scoped) | M |
| Chief complaints, symptoms, history, examination, vitals, diagnosis (ICD-10 search), plan, follow-up, notes | M |
| Template-driven sections per speciality; doctor favourites | M |
| Lab orders from consultation | M |
| Attachments | M |
| Autosave drafts; lock after sign-off; addendum instead of edit after lock | M |
| Speciality widgets (dental chart, eye exam, physio session) | P |

### H. Prescriptions
| Feature | Pri |
|---|---|
| Medicine master (platform-seeded generic + brand list; org can add) | M |
| Line: medicine, strength, dosage, frequency (1-0-1 style), duration, route, timing (before/after food), instructions | M |
| Rx templates / favourites; copy last Rx | M |
| Allergy warning on medicine with known allergen (string/class match — **advisory**) | M |
| PDF on branch letterhead with doctor registration no. and signature | M |
| Secure share link (signed, expiring) + WhatsApp/email | M |
| Multilingual instructions (EN + regional) | P |

### I. Billing & Payments
| Feature | Pri |
|---|---|
| Invoice from appointment/consultation (auto-adds consult fee + ordered services) | M |
| Ad-hoc invoice (procedures, lab, items) | M |
| Discounts — line/invoice level; above threshold needs approver role | M |
| Tax config (GST rates per service, CGST/SGST/IGST, exempt) | M |
| Payment methods: cash, UPI, card, bank transfer, wallet; split payments | M |
| Partial payment, outstanding balances, advance/deposit | M |
| Receipts (PDF + WhatsApp) | M |
| Refunds (approval, reason, method) and credit notes | M |
| Daily collection / cash-closing report per user & branch | M |
| Online payment links (Razorpay/Cashfree) | P |
| Packages / memberships (prepaid sessions) | P |

### J. Lab & Diagnostics
| Feature | Pri |
|---|---|
| Diagnostic test catalogue (with panels, sample type, TAT, price) | M |
| Lab order from consultation or reception | M |
| Status: Ordered → Sample collected → In progress → Report ready → Delivered | M |
| Worklist for lab technician | M |
| Result entry (structured values with ref ranges) or PDF upload | M |
| Attach to patient record; doctor notification when ready | M |
| Secure sharing with patient | M |
| External lab partner integration | L |

### K. Staff Management
| Feature | Pri |
|---|---|
| Invite staff by email/mobile; assign role(s) and branches | M |
| Custom roles (clone of system role + toggled permissions) | M |
| Activity log per user | M |
| Shifts, attendance (clock-in/out, optional geofence) | P |
| Leave requests & approvals | P |

### L. Inventory (optional module)
| Feature | Pri |
|---|---|
| Item master: categories, units, HSN, reorder level, batch & expiry tracking | P* |
| Suppliers, purchase entries (GRN) | P* |
| Stock ledger per branch (in/out/adjust/transfer) | P* |
| Dispense against Rx/invoice | P* |
| Low-stock & near-expiry alerts | P* |
| Reports: valuation, movement, expiry | P* |

\* Inventory is Phase 8 in the execution plan; it is *MVP-optional* — shipped, but disabled by default and plan-gated.

### M. Notifications
| Feature | Pri |
|---|---|
| Channel abstraction: WhatsApp (BSP), SMS (DLT-registered), email, in-app, push | M |
| Event-driven: booking, confirmation, reminder, cancel, follow-up, Rx, invoice, receipt, report ready | M |
| Template management (WhatsApp approved templates, SMS DLT template IDs) | M |
| Per-org channel preference + per-patient opt-out (consent) | M |
| Delivery log & retries; cost tracking per org | M |

### N. Reports & Analytics
| Feature | Pri |
|---|---|
| Role dashboards (owner, doctor, reception, accountant) | M |
| KPIs listed in brief (patients, new patients, appointments by status, revenue daily/monthly, outstanding, service-wise, doctor-wise, visit trends, branch comparison) | M |
| Date range + branch + doctor filters | M |
| Export CSV/XLSX/PDF (queued for big ranges) | M |
| GST summary report | M |

### O. Speciality modules (Phase 10)
| Speciality | Feature |
|---|---|
| General | Default template (vitals, complaints, diagnosis, Rx, follow-up) — **in MVP** |
| Dental | FDI/Universal tooth chart, tooth-wise conditions & procedures, multi-sitting treatment plans with cost estimate, procedure history |
| Dermatology | Lesion records, body-map location, clinical photos (consent-gated, encrypted), before/after comparison |
| Ophthalmology | VA (unaided/aided/pinhole), refraction (SPH/CYL/AXIS/ADD per eye), IOP, slit-lamp findings, spectacle Rx |
| Physiotherapy | Treatment plan with N sessions, per-session record (modalities, exercises, pain score), progress graph |
| Paediatrics | Growth chart (WHO percentiles), immunisation schedule tracker |
| Gynaecology | LMP/EDD calculator, obstetric history (G/P/L/A), antenatal visit card |
| Cardiology / Ortho / ENT | Template packs + structured exam fields |

### P. Patient App (Phase 11)
Register/login via OTP, discover clinics/doctors, availability, book/manage, view shared Rx/reports/receipts, notifications, consent & privacy centre (view/revoke sharing, download own data, request deletion where lawful).

### Q. AI (Phase 12, optional, opt-in per org)
See §9.

## 7. Key workflows (happy paths)

1. **Walk-in:** Reception searches mobile → selects/creates patient → picks doctor → "Walk-in" → token issued → patient appears on doctor queue → nurse enters vitals → doctor calls → consultation → Rx → complete → reception sees "ready for billing" → invoice → payment → receipt + Rx on WhatsApp.
2. **Pre-booked:** Booking (phone/app) → confirmation msg → reminder → arrival → check-in (token) → same as above.
3. **Follow-up:** Doctor sets follow-up date → system schedules a reminder → patient books → fee rule applies follow-up price if within window.
4. **Lab:** Doctor orders test → reception bills → technician collects sample → enters result / uploads → doctor notified → doctor reviews → shared to patient.
5. **Doctor leave:** Admin adds leave → system lists affected appointments → bulk reschedule/cancel with notifications.
6. **Onboarding:** Sign up → verify email/mobile → org + first branch + owner user created → trial starts → setup wizard (speciality, hours, doctors, services, fees) → super admin approval (configurable: auto or manual).

## 8. Non-functional requirements

| Area | Requirement |
|---|---|
| Performance | p95 API < 300 ms for interactive endpoints; patient search < 150 ms on 1M patients/org-shard |
| Scale target | 5,000 orgs, 20,000 concurrent staff users, 50M appointments over 5 years (single cluster, horizontally scalable app tier) |
| Availability | 99.9% monthly (MVP), maintenance windows announced |
| Data durability | RPO ≤ 15 min (binlog/PITR), RTO ≤ 4 h |
| Security | See `03-architecture.md` §Security; OWASP ASVS L2 target |
| Accessibility | WCAG 2.1 AA for staff and patient apps |
| Localisation | English UI at launch; i18n-ready (Hindi + regional in P); INR, Indian date formats, IST default but branch-level timezone stored |
| Browser/device | Last 2 versions of Chrome/Edge/Safari/Firefox; tablet-first for doctors; mobile for patients/owners |
| Offline | Not in MVP; network-loss tolerant UI (retry, draft autosave) |

## 9. AI policy (binding product rule)

- AI features are **opt-in per organisation**, plan-gated, and disabled by default.
- AI **must not** produce diagnoses, prescribe, or alter clinical records without explicit doctor action. Any clinical-adjacent output (e.g. summarising a past visit, extracting values from a scanned report) is labelled "AI draft — verify", requires human confirmation, and is audit-logged with model/version.
- No patient-identifiable data leaves the platform to an external LLM unless: (a) org has signed a DPA addendum enabling it, (b) the provider is contractually bound to no-training/zero-retention, (c) the data is minimised/pseudonymised where feasible, (d) patient consent covers it where required.
- Business-analytics AI operates on aggregated, de-identified data.
- MVP-eligible AI candidates (low risk, admin-only): natural-language report queries over aggregates, message drafting for staff, appointment slot suggestions.

## 10. Regulatory considerations (India) — to validate with counsel before production

- **Digital Personal Data Protection Act, 2023** (and DPDP Rules) — consent, purpose limitation, data principal rights, breach notification, Data Fiduciary obligations. Clinics are fiduciaries; we are a processor → DPA with each org.
- **IT Act 2000 & SPDI Rules 2011** — health data as sensitive personal data; reasonable security practices (ISO 27001-aligned).
- **ABDM / ABHA** (Ayushman Bharat Digital Mission) — optional HIP/HIU integration later; capture ABHA number now; align with **EHR Standards 2016** (ICD-10, LOINC, SNOMED CT where feasible).
- **NMC / Indian Medical Council regulations** — prescription must carry doctor name, qualification, registration number; generic-name preference.
- **Telemedicine Practice Guidelines 2020** — if/when tele-consult is added.
- **Clinical Establishments Act** (state adoption varies) — record retention (commonly 3 years OPD; we default to configurable, minimum 7 years, no hard delete of clinical records).
- **GST** — most healthcare services by clinical establishments are exempt; drugs/consumables/cosmetic procedures taxable → per-service tax configuration.
- **TRAI DLT** for SMS; **Meta WhatsApp Business policy** for templates/opt-in.
- **Data residency** — host in Indian region (e.g. AWS ap-south-1 / ap-south-2).

## 11. MVP scope (proposed for approval)

**MVP = Phases 3–9** of the execution plan, delivering a sellable product for single- and multi-doctor, multi-branch clinics:

✅ Tenancy, auth, RBAC, audit, subscriptions (manual + gateway-ready), super admin
✅ Org/branch/doctor/staff/services/schedules
✅ Patients, appointments, walk-ins, queue/tokens, TV display
✅ Consultation workspace with **General template** + template engine
✅ Prescriptions + PDF + secure share
✅ Billing, payments, refunds, GST, daily collection
✅ Lab orders & reports
✅ Inventory (optional, plan-gated)
✅ WhatsApp/SMS/email notifications
✅ Dashboards & exports

⏭ Post-MVP: speciality widgets (Phase 10), patient app (11), AI (12), HR attendance/shifts, online payments, packages, ABDM, tele-consult.

## 12. Open questions for the product owner

See `08-open-decisions.md`.
