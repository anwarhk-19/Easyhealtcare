# Patient-education video library: feature design notes

Use these notes when designing YouTube video features inside EasyHealthcare. The project is still in planning (Phases 1–2), so write the design into `docs/` (PRD, schema, API, navigation) rather than application code, unless the user says Phase 3 has started.

## Feature summary
Clinics curate YouTube videos (their own channel or trusted sources) into an **education library**. Doctors attach videos to a prescription or consultation, the patient receives them by WhatsApp or SMS link or in the patient portal, and the clinic can see which ones were sent.

| Capability | Priority |
|---|---|
| Org-level library: add by URL, fetch title/thumbnail/duration via Data API, tag by speciality/condition | MVP+ |
| Attach videos to a prescription / visit summary ("Recommended videos") | MVP+ |
| Send via the existing Notifications pipeline (WhatsApp template with link) | MVP+ |
| Patient portal "My videos" | Post-MVP (with PatientPortal) |
| Platform-curated starter library per speciality | Post-MVP |
| AI suggests videos from diagnosis codes (doctor confirms) | Post-MVP, `ai.*` feature-gated |

## Fit with architecture (docs/03)
- New module `app/Modules/Education/` with the standard layout (Models, Actions, Http, Policies, Events, Jobs).
- Actions: `AddEducationVideo`, `RefreshVideoMetadata` (queued job), `AttachVideosToPrescription`, `SendEducationVideos`. The last one emits `EducationVideosSent`; Notifications listens to it, so modules never call each other directly.
- Plan feature key: `education_library` in `features`, enforced by existing feature gating.
- The Data API key is platform-level, stored encrypted in `integration_providers` (add channel `youtube`). It is only called server-side and never exposed to the SPA.
- Metadata is cached in the DB, refreshed by a scheduled job (weekly) and on demand. This keeps usage within quota: `videos.list` costs 1 unit for 50 IDs.

## Schema sketch (follow docs/04 conventions)
| Table | Key columns | Notes |
|---|---|---|
| `education_videos` **T U** | organization_id, youtube_video_id CHAR(11), title, channel_title, duration_seconds, thumbnail_url, language, status (`active`,`unavailable`,`archived`), metadata_refreshed_at, created_by | UNIQUE(organization_id, youtube_video_id); soft deletes |
| `education_video_tags` **T** | organization_id, education_video_id, speciality_id NULL, tag | INDEX(organization_id, tag) |
| `prescription_education_videos` **T** | organization_id, prescription_id, education_video_id, position | CFK to both |
| `education_video_deliveries` **T** | organization_id, patient_id, education_video_id, channel, notification_id, sent_at, opened_at NULL | For analytics; opened_at via tracked redirect |

## API sketch (docs/05 style)
- `GET/POST /api/v1/education/videos`, `PATCH/DELETE /api/v1/education/videos/{ulid}`, `POST /api/v1/education/videos/{ulid}/refresh`
- `PUT /api/v1/prescriptions/{ulid}/education-videos`
- Permissions: `education.manage` (admin, doctor), `education.attach` (doctor), `education.view` (all clinical staff).

## Frontend / embedding
- Embed with `https://www.youtube-nocookie.com/embed/<id>` (privacy-enhanced mode) inside a lazy-loaded facade: show the thumbnail first and load the iframe on click. Add the nocookie host to the CSP `frame-src`.
- Patient-facing links go through a short tracked redirect (`/v/{token}`) that records `opened_at` and then forwards to YouTube. The token must not encode patient data.

## Privacy & compliance
- Never send PHI to YouTube or Google. Video IDs and links are not linked to patients outside our DB.
- The DPDP Act 2023: delivery tracking is clinic-operational data; mention it in the clinic's patient privacy notice.
- Show a note that videos are third-party content; doctors are responsible for what they recommend (see `content-writing.md` guardrails).
- Mark a video `unavailable` when the API stops returning it (deleted or private) and hide it from attach pickers.

## Open decisions to add to docs/08
1. Is it allowed to add videos from any channel, or only allow-listed channels?
2. Should there be a platform-curated starter library (and who reviews it medically)?
3. Is delivery open-tracking acceptable, or opt-in per org?
