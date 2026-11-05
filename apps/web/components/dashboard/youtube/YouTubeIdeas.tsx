"use client";

import Link from "next/link";
import { Lightbulb } from "lucide-react";
import { useEffect, useState } from "react";
import { DeskHeading } from "@/components/DeskHeading";
import { LoadingMark } from "@/components/LoadingMark";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { loadYouTubeDesk, refreshYouTubeResearch, type YouTubeDesk } from "@/lib/youtube";

export function YouTubeIdeas() {
  const [desk, setDesk] = useState<YouTubeDesk | null>(null);
  const [error, setError] = useState("");
  const [query, setQuery] = useState("");
  const [busy, setBusy] = useState("");

  useEffect(() => {
    loadYouTubeDesk().then(setDesk).catch((err) => setError(err instanceof Error ? err.message : "Could not load YouTube ideas."));
  }, []);

  if (!desk && !error) return <LoadingMark className="min-h-[40vh]" />;

  async function refresh(kind: "channel-videos" | "niche-search") {
    setBusy(kind);
    setError("");
    try {
      await refreshYouTubeResearch(kind, kind === "niche-search" ? { query: query.trim() } : undefined);
      setDesk(await loadYouTubeDesk());
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not refresh YouTube research.");
    } finally {
      setBusy("");
    }
  }

  return (
    <div className="space-y-6">
      <DeskHeading icon={Lightbulb} title="Ideas">
        <p className="text-muted-foreground">Ranked from cached API reads, public listings, and repeated questions. API reads happen only when you press refresh.</p>
      </DeskHeading>
      {error && <p className="text-sm text-muted-foreground">{error}</p>}
      {desk && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Refresh research</CardTitle>
            <CardDescription>{desk.research.used}/{desk.research.budget} daily read units used. Results are cached for {desk.research.cache_minutes} minutes.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="outline" disabled={!desk.connected || busy !== ""} onClick={() => void refresh("channel-videos")}>
                {busy === "channel-videos" ? "Refreshing…" : "Refresh channel videos"}
              </Button>
              <input className="min-w-52 flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Bounded niche search" />
              <Button size="sm" variant="outline" disabled={!desk.connected || busy !== "" || query.trim().length < 2} onClick={() => void refresh("niche-search")}>
                {busy === "niche-search" ? "Searching…" : "Refresh niche"}
              </Button>
            </div>
            {Object.values(desk.research.pulls).map((pull, index) => pull?.error && <p key={index} className="text-xs text-destructive">{pull.error} Cached research was kept.</p>)}
          </CardContent>
        </Card>
      )}
      {desk && desk.week.length === 0 && (
        <Card><CardContent className="py-8 text-sm text-muted-foreground">No ideas yet. Paste public listings on Today; a channel needs four videos before its median can be ranked.</CardContent></Card>
      )}
      <div className="grid gap-4 lg:grid-cols-2">
        {desk?.week.map((idea, index) => (
          <Card key={`${idea.topic}-${index}`}>
            <CardHeader>
              <CardTitle className="text-base">{idea.topic}</CardTitle>
              <CardDescription>{idea.reason}</CardDescription>
            </CardHeader>
            <CardContent>
              <Link href={`/content-studio?platform=youtube&format=youtube_title&topic=${encodeURIComponent(idea.topic)}`}>
                <Button size="sm" variant="secondary">Draft title text</Button>
              </Link>
              <p className="mt-2 text-xs text-muted-foreground">Compose saves text drafts. It does not create or change a video package.</p>
            </CardContent>
          </Card>
        ))}
      </div>
      {desk && desk.niche && desk.niche.working.length > 0 && (
        <Card>
          <CardHeader><CardTitle className="text-base">Ranked listings</CardTitle><CardDescription>{desk.niche.note}</CardDescription></CardHeader>
          <CardContent><ul className="space-y-3">{desk.niche.working.map((video) => <li key={`${video.channel}-${video.title}`}><p className="text-sm font-medium">{video.multiple}× · {video.title}</p><p className="text-xs text-muted-foreground">{video.channel} · {video.formula}</p></li>)}</ul></CardContent>
        </Card>
      )}
    </div>
  );
}
