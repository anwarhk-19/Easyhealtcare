---
name: youtube
description: YouTube agent for EasyHealthcare. Use when the user gives a YouTube URL or video ID, or asks to summarize, research, or pull a transcript from YouTube videos; search YouTube or read video/channel stats and comments; write YouTube content (scripts, titles, descriptions, tags, chapters, thumbnail ideas), especially health-education videos for clinics; or design the in-app patient-education video library feature. Triggers on "youtube", "youtu.be", "video summary", "transcript", "video script", "channel stats".
---

# YouTube agent

Four jobs. Pick the matching section; several can chain (e.g. research competitor videos → write a better script → add it to the in-app library).

All scripts live in `.claude/skills/youtube/scripts/` and use Python 3. Run them from the repo root.

## Setup (once per environment)

```bash
pip install -q yt-dlp            # needed for summarize/research (mode 1)
export YOUTUBE_API_KEY=...       # needed for Data API (mode 3) only
```

- Network: the environment must allow `www.youtube.com`, `youtubei.googleapis.com`, `*.googlevideo.com` (yt-dlp) and `www.googleapis.com` (Data API). If a request is refused by the proxy, say which host was blocked and ask the user to allow it; do not fake output.
- In a cloud session, ask for the API key with the credential tool (host `www.googleapis.com`, header `X-goog-api-key`, no prefix) instead of having it pasted into chat. Never print or commit the key.

## 1. Summarize / research videos

```bash
python3 .claude/skills/youtube/scripts/yt_fetch.py <url-or-id> [--lang en,hi] [--out DIR]
```

Writes `<id>.meta.json` (title, channel, duration, views, upload date, description, chapters) and `<id>.transcript.txt` (`[mm:ss] text` lines) to `--out` (default: a scratch dir), and prints a short header. Prefers manual captions, falls back to auto-captions; tries languages in order.

Then:
1. Read the transcript (use offsets for long ones; don't dump it into chat).
2. Produce: one-paragraph TL;DR → 5–10 key points each with a `[mm:ss]` timestamp link (`https://youtu.be/<id>?t=<seconds>`) → notable claims/data → who it's for.
3. For multiple videos, compare in a table (angle, length, key claims, gaps).
4. Health content: flag claims that look medically unsupported or unsafe; don't restate them as fact.

No captions available → say so and offer a metadata/description-only summary. Never invent transcript content.

## 2. Write video content

Read `references/content-writing.md` first. Deliverables (produce only those asked for):
- Script with hook (first 15 s), sections, on-screen text cues, CTA
- 5 title options (≤ 60 chars, primary keyword early)
- Description (first 2 lines carry the value; chapters; disclaimer; links)
- Tags (10–15), hashtags (≤ 3), chapter list, 3 thumbnail concepts (≤ 4 words of text each)

Medical/health videos must follow the guardrails in that reference (disclaimer, no cure claims, no patient-identifiable info, Indian advertising rules for doctors).

## 3. YouTube Data API

```bash
python3 .claude/skills/youtube/scripts/yt_api.py search "<query>" [--max 10] [--order relevance|viewCount|date] [--region IN]
python3 .claude/skills/youtube/scripts/yt_api.py videos <id> [<id> ...]
python3 .claude/skills/youtube/scripts/yt_api.py channel <channel-id | @handle>
python3 .claude/skills/youtube/scripts/yt_api.py comments <video-id> [--max 50]
```

Outputs JSON. Quota: search costs 100 units, everything else 1 (default daily quota 10,000) — batch video IDs into one `videos` call (up to 50). Read-only: this skill does not upload, comment, or modify anything on YouTube.

## 4. Embed in the EasyHealthcare app

For product/feature work on a patient-education video library, read `references/app-integration.md`. It maps the feature onto the project's conventions (modular monolith, `organization_id` tenancy, ULIDs, plan feature gating, privacy-enhanced embeds). Update the relevant `docs/` files rather than writing code until Phase 3 is approved (see `README.md`).
