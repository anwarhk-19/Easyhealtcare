# 06 — Application Navigation, Screen Structure & UI/UX Design System

## 1. Applications

| App | Users | Form factor |
|---|---|---|
| **Staff app** (`app.<domain>`) | All tenant roles | Desktop + tablet (doctor), responsive to mobile (owner) |
| **Admin console** (`admin.<domain>`) | Platform staff | Desktop |
| **Queue display** (`display.<domain>/{token}`) | Waiting room TV | 1080p landscape, kiosk mode |
| **Public booking page** (`<slug>.<domain>` or `/c/{slug}`) | Patients | Mobile-first |
| **Patient app** (Phase 11) | Patients | PWA first, then Capacitor native |

## 2. Staff app layout

```
┌──────────────────────────────────────────────────────────────────────┐
│ [Logo] Org ▾  Branch ▾ │ 🔍 Search patient (/ to focus) │ + New ▾ │ 🔔 │ 👤 │
├────────────┬─────────────────────────────────────────────────────────┤
│ Sidebar    │ Page header: title · breadcrumbs · primary action        │
│ (role-     │─────────────────────────────────────────────────────────│
│  filtered, │ Content                                                   │
│  collapsible)                                                         │
└────────────┴─────────────────────────────────────────────────────────┘
```
- **Global patient search** in the top bar on every screen (mobile/name/code), with keyboard navigation; results offer *Book*, *Walk-in*, *Open*.
- **"+ New"** quick actions: Patient, Appointment, Walk-in, Invoice, Payment.
- Branch switcher limits all lists to the chosen branch (or "All my branches").
- Sidebar shows only modules allowed by permission **and** enabled by plan/org.

### Sidebar (full set; filtered per role)

1. **Dashboard**
2. **Front desk** — Today (queue board) · Appointments (calendar) · Waitlist
3. **Patients**
4. **My queue** (doctor)
5. **Consultations** (doctor: drafts, signed, follow-ups due)
6. **Billing** — Invoices · Payments · Refunds · Approvals · Cash closing
7. **Lab** — Worklist · Orders · Test catalogue
8. **Inventory** — Stock · Items · Purchases · Suppliers · Movements
9. **Reports**
10. **Messages** (notification log)
11. **Settings** — Clinic profile · Branches · Hours & holidays · Services & fees · Taxes · Doctors · Staff & roles · Templates (consultation, Rx, messages) · Modules · Integrations · Subscription · Audit log

### Default landing page per role

| Role | Lands on | Key widgets |
|---|---|---|
| Owner/Admin | Dashboard | Today's appts by status, revenue today/MTD, outstanding, doctor utilisation, branch comparison, subscription banner |
| Branch manager | Dashboard (branch) | Same, branch-scoped |
| Doctor | My queue | Current/next patient, waiting count, today's stats, follow-ups due, lab reports to review |
| Receptionist | Front desk › Today | All-doctor queue columns, arrivals, ready-for-billing list, quick search |
| Nurse | Front desk › Today (vitals mode) | Waiting patients without vitals |
| Lab tech | Lab › Worklist | Pending samples, in-progress, to release |
| Accountant | Billing › Invoices | Collections today, outstanding ageing, refund approvals |

## 3. Key screens

### 3.1 Front desk › Today (receptionist "cockpit")
- Columns per doctor (tokens: waiting → called → in consultation → done); drag to reorder priority; badge for wait time.
- Right rail: "Arriving soon" (booked, not checked-in) with one-click **Check in**; "Ready for billing" list.
- All updates real-time.

### 3.2 Booking drawer (opens over any screen)
1. Patient (search or quick-create inline: name, mobile, gender, age) →
2. Doctor/speciality + branch → 3. Date + slot grid (available/taken/leave greyed) → 4. Confirm (fee preview, send WhatsApp ✓).
Target: ≤ 6 clicks for returning patient.

### 3.3 Patient profile
Header (name, code, age/gender, mobile, allergy badge in red, chronic conditions chips) · tabs: **Overview** (timeline) · Visits · Prescriptions · Documents · Lab · Billing · Appointments · Consents.

### 3.4 Doctor consultation workspace (tablet-optimised)
```
┌ Patient bar: name · 34y F · ⚠ Penicillin · DM2 · token D1-014 · [Next ▸] ┐
├──────────────┬───────────────────────────────────────┬─────────────────┤
│ History rail │ Template sections (accordion/scroll):  │ Rx panel        │
│ prior visits │ Vitals · Complaints · Exam · Dx (ICD) │ medicine search │
│ last Rx      │ · Speciality widget · Plan · Follow-up│ 1-0-1 chips     │
│ reports      │                                        │ templates       │
│ (click=peek) │                                        │ [Preview PDF]   │
├──────────────┴───────────────────────────────────────┴─────────────────┤
│ Autosaved ✓ 10:42 │ Order lab │ Attach │      [Save draft] [Sign & finish]│
└──────────────────────────────────────────────────────────────────────────┘
```
- Keyboard-first: chips for common complaints/diagnoses/frequencies; "copy last Rx"; favourites.
- "Sign & finish" → locks, generates PDF, sends (if consented), marks queue complete, calls next (optional).

### 3.5 Invoice screen
Line items (auto-filled from visit), discount with inline approval request, tax breakdown, split payment entry, print/share receipt.

### 3.6 Settings › Onboarding wizard
Clinic profile → Speciality → Branch & hours → Doctors (invite or "I am the doctor") → Services & fees → WhatsApp setup (optional) → Done (sample booking walkthrough).

### 3.7 Admin console
Organisations (table, filters by status/plan, approve/suspend), Plans, Subscriptions & revenue, Specialities & templates, Masters (medicines, ICD, lab tests), Integrations, Platform users, Audit, Support sessions.

## 4. Design system ("EHC UI")

### 4.1 Principles
1. **Calm and clinical** — white/neutral surfaces, one accent, colour reserved for meaning (status, alerts).
2. **Speed over decoration** — dense but breathable tables; keyboard shortcuts; no modal stacks (use side drawers).
3. **Safety cues are unmistakable** — allergies, critical lab values and destructive actions use red + icon + text (never colour alone).
4. **Progressive disclosure** — simple defaults for a solo clinic; advanced options behind "More".

### 4.2 Tokens (Tailwind theme)

| Token | Value | Use |
|---|---|---|
| `primary-600` | `#0F766E` (teal) | Primary actions, active nav |
| `primary-50` | `#F0FDFA` | Selected rows, highlights |
| `neutral-900/700/500/200/50` | Slate scale | Text, borders, surfaces |
| `success-600` | `#15803D` | Completed, paid |
| `warning-600` | `#B45309` | Waiting long, partially paid, low stock |
| `danger-600` | `#B91C1C` | Allergy, critical, cancel, overdue |
| `info-600` | `#1D4ED8` | Confirmed, informational |
| Font | Inter (UI), tabular numerals for money/tokens; Noto Sans for Indic scripts |
| Type scale | 12 / 14 (base) / 16 / 18 / 20 / 24 / 30 |
| Spacing | 4-px grid; Tailwind default scale |
| Radius | 6 px controls, 10 px cards |
| Elevation | 3 levels (card, dropdown, drawer) |
| Dark mode | Supported via CSS variables; default light |
| Min touch target | 44 × 44 px (tablet/doctor views) |
| Contrast | ≥ 4.5:1 text, ≥ 3:1 UI components |

### 4.3 Status colour map

| Appointment status | Colour |
|---|---|
| Booked | neutral |
| Confirmed | info |
| Checked in / Waiting | warning (amber), turns danger after configurable wait |
| In consultation | primary |
| Completed | success |
| Cancelled | neutral, strikethrough |
| No show | danger-outline |

### 4.4 Components (`packages/ui`)
Button, IconButton, Input, PhoneInput (+91 default), DateInput (DD/MM/YYYY), AgeOrDobInput, Select/Combobox (async search), ChipSelect, Textarea (autosize), Checkbox/Radio/Switch, Badge/StatusPill, Avatar, Card, Table (sortable, sticky header, column chooser, empty/loading/error states), Pagination, Tabs, Drawer, Modal (confirm only), Toast, Tooltip, Popover, Dropdown, Breadcrumbs, PageHeader, StatTile, Chart wrappers (ECharts), SlotGrid, QueueColumn, TokenCard, PatientBanner, AllergyAlert, MoneyInput/MoneyText (₹ with Indian grouping 1,00,000), FileDropzone, PdfPreview, Stepper, EmptyState, Skeleton, KeyboardShortcut hint, PermissionGate.

### 4.5 Interaction rules
- Forms: inline validation on blur, server errors mapped to fields, autosave for long clinical forms, never lose input on network error.
- Destructive actions: confirm with explicit verb ("Cancel appointment"), and reason where audit needs it.
- Lists: filters persisted in URL query; default sort most-recent; 25 rows.
- Shortcuts: `/` search, `N` new appointment, `W` walk-in, `Ctrl+Enter` sign/save, `J/K` next/prev in queue.
- Locale formats: ₹ Indian digit grouping, DD-MMM-YYYY display, 12-h time with AM/PM (configurable).
- Accessibility: focus rings, semantic landmarks, ARIA on custom widgets, screen-reader labels for icon buttons, reduced-motion support.

## 5. Responsive breakpoints

| Breakpoint | Layout |
|---|---|
| < 640 (phone) | Bottom nav (Dashboard, Today, Patients, More); drawers become full-screen sheets; tables become cards |
| 640–1024 (tablet) | Collapsible sidebar; consultation workspace 2-pane (history rail as slide-over) |
| ≥ 1024 (desktop) | Full sidebar; 3-pane consultation |
