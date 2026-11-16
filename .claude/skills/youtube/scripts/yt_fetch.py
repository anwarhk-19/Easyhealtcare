#!/usr/bin/env python3
"""Fetch YouTube metadata + transcript with yt-dlp.

Usage: yt_fetch.py <url-or-id> [--lang en,hi] [--out DIR]
Writes <id>.meta.json and <id>.transcript.txt to DIR.
"""
import argparse, html, json, os, re, sys, tempfile, urllib.request

try:
    import yt_dlp
except ImportError:
    sys.exit("yt-dlp missing: pip install yt-dlp")

ID_RE = re.compile(r"(?:v=|youtu\.be/|shorts/|embed/|live/)([A-Za-z0-9_-]{11})")


def video_url(arg):
    if re.fullmatch(r"[A-Za-z0-9_-]{11}", arg):
        return f"https://www.youtube.com/watch?v={arg}"
    m = ID_RE.search(arg)
    return f"https://www.youtube.com/watch?v={m.group(1)}" if m else arg


def pick_track(info, langs):
    for source in ("subtitles", "automatic_captions"):
        tracks = info.get(source) or {}
        for lang in langs:
            # exact match, then regional variants (en-US, en-GB...)
            keys = [k for k in tracks if k == lang] + [k for k in tracks if k.startswith(lang + "-")]
            for k in keys:
                for fmt in tracks[k]:
                    if fmt.get("ext") == "json3":
                        return source, k, fmt["url"]
    return None


def stamp(ms):
    s = int(ms // 1000)
    h, rem = divmod(s, 3600)
    m, s = divmod(rem, 60)
    return f"{h}:{m:02d}:{s:02d}" if h else f"{m:02d}:{s:02d}"


def parse_json3(data):
    lines, last = [], None
    for ev in data.get("events", []):
        segs = ev.get("segs")
        if not segs:
            continue
        text = html.unescape("".join(s.get("utf8", "") for s in segs)).replace("\n", " ").strip()
        if not text or text == last:
            continue
        last = text
        lines.append(f"[{stamp(ev.get('tStartMs', 0))}] {text}")
    return lines


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("video")
    ap.add_argument("--lang", default="en,hi")
    ap.add_argument("--out", default=None)
    a = ap.parse_args()
    out = a.out or tempfile.mkdtemp(prefix="yt_")
    os.makedirs(out, exist_ok=True)

    with yt_dlp.YoutubeDL({"skip_download": True, "quiet": True, "no_warnings": True}) as ydl:
        info = ydl.extract_info(video_url(a.video), download=False)

    vid = info["id"]
    meta = {k: info.get(k) for k in (
        "id", "title", "channel", "channel_id", "uploader_id", "upload_date", "duration",
        "view_count", "like_count", "comment_count", "tags", "categories", "description",
        "chapters", "webpage_url", "language")}
    meta["chapters"] = [
        {"start": c.get("start_time"), "title": c.get("title")} for c in (info.get("chapters") or [])]

    track = pick_track(info, [l.strip() for l in a.lang.split(",") if l.strip()])
    transcript_path = None
    if track:
        source, lang, url = track
        with urllib.request.urlopen(url, timeout=30) as r:
            lines = parse_json3(json.load(r))
        transcript_path = os.path.join(out, f"{vid}.transcript.txt")
        with open(transcript_path, "w", encoding="utf-8") as f:
            f.write("\n".join(lines) + "\n")
        meta["transcript"] = {"source": source, "lang": lang, "lines": len(lines)}
    else:
        meta["transcript"] = None

    meta_path = os.path.join(out, f"{vid}.meta.json")
    with open(meta_path, "w", encoding="utf-8") as f:
        json.dump(meta, f, ensure_ascii=False, indent=2)

    dur = meta.get("duration") or 0
    print(f"{meta['title']} — {meta['channel']} ({dur // 60}m{dur % 60:02d}s, {meta.get('view_count')} views)")
    print(f"meta: {meta_path}")
    if transcript_path:
        t = meta["transcript"]
        print(f"transcript: {transcript_path} ({t['lines']} lines, {t['lang']}, {t['source']})")
    else:
        print("transcript: none available in requested languages")


if __name__ == "__main__":
    main()
