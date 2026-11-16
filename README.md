# EasyHealthcare — Multi-Speciality Clinic Management SaaS

A multi-tenant, subscription-based clinic management platform for single-doctor clinics through multi-branch, multi-speciality groups.

**Planned stack:** Laravel 12 · PHP 8.3 · MySQL 8 · Redis · Sanctum · Reverb · Vue 3 + TypeScript · Tailwind CSS.

## Status

**Phases 1–2 (requirements, architecture, database design) are drafted and awaiting review.** No application code yet. Implementation (Phase 3) starts after the documents below and the decisions in `docs/08-open-decisions.md` are approved.

## Planning pack

| # | Document | Covers |
|---|---|---|
| 01 | [Product Requirements](docs/01-product-requirements.md) | Vision, personas, module-wise feature breakdown with MVP priority, workflows, NFRs, AI policy, Indian regulatory notes, MVP scope |
| 02 | [Roles & Permissions](docs/02-roles-and-permissions.md) | Tenant and platform role matrices, data-scope rules, permission catalogue |
| 03 | [Architecture](docs/03-architecture.md) | Modular monolith, multi-tenant isolation layers, speciality extensibility, subscriptions, real-time, notifications, security, deployment, frontend |
| 04 | [Database Schema & ERD](docs/04-database-schema.md) | All tables by module, indexes, double-booking guard, ERD (Mermaid) |
| 05 | [API Specification](docs/05-api-specification.md) | Conventions, endpoint inventory, real-time channels |
| 06 | [Navigation & Design System](docs/06-navigation-and-design-system.md) | App structure, role landing pages, key screens, design tokens, components |
| 07 | [Milestones & Testing](docs/07-milestones-and-testing.md) | Phase plan with exit criteria and estimates, testing strategy, Definition of Done |
| 08 | [Open Decisions](docs/08-open-decisions.md) | Decisions needed before Phase 3, with recommended defaults |
