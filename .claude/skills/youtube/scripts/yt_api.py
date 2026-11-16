#!/usr/bin/env python3
"""Read-only YouTube Data API v3 helper. Needs YOUTUBE_API_KEY (or a proxy-injected X-goog-api-key header).

  yt_api.py search "<query>" [--max 10] [--order relevance] [--region IN]
  yt_api.py videos <id> [<id> ...]
  yt_api.py channel <channel-id | @handle>
  yt_api.py comments <video-id> [--max 50]
"""
import argparse, json, os, sys, urllib.error, urllib.parse, urllib.request

BASE = "https://www.googleapis.com/youtube/v3/"


def call(endpoint, **params):
    key = os.environ.get("YOUTUBE_API_KEY")
    params = {k: v for k, v in params.items() if v is not None}
    url = BASE + endpoint + "?" + urllib.parse.urlencode(params)
    # Key goes in a header, so it never lands in URLs/logs. In cloud sessions the
    # proxy can inject X-goog-api-key itself, so a missing env var is not fatal.
    headers = {"X-goog-api-key": key} if key else {}
    try:
        with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=30) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        body = e.read().decode(errors="replace")
        sys.exit(f"HTTP {e.code} from {endpoint}: {body[:500]}")


def search(a):
    items = call("search", part="snippet", q=a.query, type="video", maxResults=min(a.max, 50),
                 order=a.order, regionCode=a.region)["items"]
    return [{"id": i["id"]["videoId"], "title": i["snippet"]["title"],
             "channel": i["snippet"]["channelTitle"], "published": i["snippet"]["publishedAt"]}
            for i in items]


def videos(a):
    out = []
    for n in range(0, len(a.ids), 50):
        data = call("videos", part="snippet,statistics,contentDetails", id=",".join(a.ids[n:n + 50]))
        for v in data["items"]:
            s, st = v["snippet"], v.get("statistics", {})
            out.append({"id": v["id"], "title": s["title"], "channel": s["channelTitle"],
                        "published": s["publishedAt"], "duration": v["contentDetails"]["duration"],
                        "views": st.get("viewCount"), "likes": st.get("likeCount"),
                        "comments": st.get("commentCount"), "tags": s.get("tags", []),
                        "description": s.get("description", "")})
    return out


def channel(a):
    ref = a.channel
    kw = {"forHandle": ref} if ref.startswith("@") else {"id": ref}
    items = call("channels", part="snippet,statistics", **kw).get("items", [])
    return [{"id": c["id"], "title": c["snippet"]["title"], "handle": c["snippet"].get("customUrl"),
             "subscribers": c["statistics"].get("subscriberCount"),
             "videos": c["statistics"].get("videoCount"), "views": c["statistics"].get("viewCount"),
             "description": c["snippet"].get("description", "")} for c in items]


def comments(a):
    items = call("commentThreads", part="snippet", videoId=a.video, maxResults=min(a.max, 100),
                 order="relevance", textFormat="plainText")["items"]
    return [{"author": c["snippet"]["topLevelComment"]["snippet"]["authorDisplayName"],
             "likes": c["snippet"]["topLevelComment"]["snippet"]["likeCount"],
             "text": c["snippet"]["topLevelComment"]["snippet"]["textDisplay"],
             "replies": c["snippet"]["totalReplyCount"]} for c in items]


def main():
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)
    p = sub.add_parser("search"); p.add_argument("query"); p.add_argument("--max", type=int, default=10)
    p.add_argument("--order", default="relevance", choices=["relevance", "viewCount", "date", "rating"])
    p.add_argument("--region", default=None); p.set_defaults(fn=search)
    p = sub.add_parser("videos"); p.add_argument("ids", nargs="+"); p.set_defaults(fn=videos)
    p = sub.add_parser("channel"); p.add_argument("channel"); p.set_defaults(fn=channel)
    p = sub.add_parser("comments"); p.add_argument("video"); p.add_argument("--max", type=int, default=50)
    p.set_defaults(fn=comments)
    a = ap.parse_args()
    print(json.dumps(a.fn(a), ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
