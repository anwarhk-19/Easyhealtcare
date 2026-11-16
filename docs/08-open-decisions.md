# 08 — Decisions Needed Before Phase 3

Each item shows the **recommended default**. Phase 3 will proceed with these unless changed.

| # | Decision | Recommendation | Why it matters |
|---|---|---|---|
| D1 | Tenancy model | Shared schema + `organization_id` + composite FKs; dedicated-DB option reserved for Enterprise | Cost and ops at thousands of clinics |
| D2 | Can one patient record be shared across organisations? | **No.** Each org owns its records; the patient app links them only with patient consent | DPDP fiduciary boundaries, simpler isolation |
| D3 | Can clinic owners/admins read clinical notes by default? | **No**, it's an org setting (`admins_can_view_clinical`) | Doctor confidentiality versus owner oversight |
| D4 | Default clinical visibility between doctors in the same org | All doctors in the patient's branches (configurable) | Continuity of care versus privacy |
| D5 | Clinic approval | Auto-activate trial after verifying email and mobile; super admin can suspend. Manual approval only when flagged | Onboarding friction |
| D6 | Trial length and plans | 14-day trial. Starter (1 doctor, 1 branch), Professional (≤10 doctors, 1 branch), Business (multi-branch), Enterprise (custom). Prices set by super admin | Plan limits drive feature gates |
| D7 | Hosting | AWS Mumbai (ap-south-1), DR in Hyderabad. Start with a lean setup (Forge/Laravel Cloud), move to ECS when scale requires | Data residency, cost |
| D8 | WhatsApp provider | Meta Cloud API direct, with the adapter allowing a BSP (Gupshup/Interakt/AiSensy) | Cost, template approval process |
| D9 | SMS provider | MSG91 or similar DLT-compliant gateway | TRAI DLT |
| D10 | Payment gateway (SaaS billing and patient payment links) | Razorpay | Indian market fit |
| D11 | Who pays WhatsApp/SMS costs? | Quota included in plan; overage billed or org's own WABA credentials | Margin |
| D12 | Clinical record retention | Configurable, default and minimum 7 years, no hard delete | Legal and medico-legal |
| D13 | Frontend delivery | Separate Vue SPA plus Sanctum (not Inertia), so the API serves the patient app and integrations too | API-first requirement |
| D14 | Medicine master source | Seed a curated generic and brand list. Licensed database (e.g. CIMS/Medindia) is a later decision | Rx quality, licensing |
| D15 | Languages at launch | English UI. Rx instructions in English plus one regional language in Phase 10 | Scope |
| D16 | ABDM integration | Capture ABHA in MVP. HIP/HIU integration post-MVP | Certification effort |
| D17 | Brand and domain | Working name "EasyHealthcare". Final name, logo and domain needed before Phase 3 design work | UI, emails, templates |

## Assumptions made in this pack
- Primary market is India (INR, GST, DLT, DPDP). Multi-country support is possible via org currency and timezone fields but is not tested in MVP.
- Inventory is limited to clinic consumables and dispensing, not a retail pharmacy.
- A "clinic" in the brief maps to an **organisation** with one or more branches.
