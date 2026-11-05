"use client";

import { BarChart3 } from "lucide-react";
import { useEffect, useState } from "react";
import { DeskHeading } from "@/components/DeskHeading";
import { LoadingMark } from "@/components/LoadingMark";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { loadYouTubeDesk, refreshYouTubeVideoData, type YouTubeDesk } from "@/lib/youtube";

const LESSONS = {
  hook_leak: "Rewrite the first fifteen seconds.",
  cliff: "Keep the opening. Cut the moment people left.",
  slide: "Keep the opening. Cut the middle.",
  packaging: "Keep the opening. Change the title and thumbnail.",
} as const;

export function YouTubeLanded() {
  const [desk, setDesk] = useState<YouTubeDesk | null>(null);
  const [error, setError] = useState("");
  const [videoIds, setVideoIds] = useState<Record<number, string>>({});
  const [busy, setBusy] = useState("");

  useEffect(() => {
    loadYouTubeDesk().then(setDesk).catch((err) => setError(err instanceof Error ? err.message : "Could not load YouTube results."));
  }, []);

  if (!desk && !error) return <LoadingMark className="min-h-[40vh]" />;

  async function refresh(id: number, path: "views" | "retention") {
    const youtubeVideoId = (videoIds[id] || desk?.learned.find((video) => video.id === id)?.youtube_video_id || "").trim();
    if (!youtubeVideoId) return;
    setBusy(`${id}-${path}`);
    setError("");
    try {
      await refreshYouTubeVideoData(id, path, youtubeVideoId);
      setDesk(await loadYouTubeDesk());
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not refresh YouTube Analytics.");
    } finally {
      setBusy("");
    }
  }

  return (
    <div className="space-y-6">
      <DeskHeading icon={BarChart3} title="What landed">
        <p className="text-muted-foreground">Stored results and retention lessons. YouTube Analytics reads happen only when you press refresh.</p>
      </DeskHeading>
      {error && <p className="text-sm text-muted-foreground">{error}</p>}
      {desk?.preview && (
        <p className="text-sm text-muted-foreground">The channel cards on Today are labeled Sample. They are placeholders, not channel metrics.</p>
      )}
      {desk && desk.learned.length === 0 && (
        <Card><CardContent className="py-8 text-sm text-muted-foreground">No learned videos yet. Record views and average view percentage on Today, or paste a Studio retention export.</CardContent></Card>
      )}
      <div className="space-y-4">
        {desk?.learned.map((video) => (
          <Card key={video.id}>
            <CardHeader>
              <div className="flex flex-wrap items-center gap-2">
                <CardTitle className="text-base">{video.title || "Untitled video"}</CardTitle>
                <Badge variant="outline">{video.mode === "series" ? "Series" : "Long piece"}</Badge>
              </div>
              <CardDescription>Learned {new Date(video.learned_at).toLocaleDateString()}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-2">
              <p className="text-sm">{video.views.toLocaleString()} views · {video.average_view_percent == null ? "Average view percentage not entered" : `${video.average_view_percent}% average viewed`}</p>
              <p className="text-sm text-muted-foreground">
                {video.lesson ? LESSONS[video.lesson] : "No retention lesson. Average view percentage is stored, but it never chooses the next opening."}
              </p>
              <div className="flex flex-wrap gap-2 pt-2">
                <input
                  className="min-w-52 flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm"
                  value={videoIds[video.id] ?? video.youtube_video_id ?? ""}
                  onChange={(event) => setVideoIds((current) => ({ ...current, [video.id]: event.target.value }))}
                  placeholder="Published YouTube video ID"
                />
                <Button size="sm" variant="outline" disabled={!desk.connected || busy !== "" || !(videoIds[video.id] ?? video.youtube_video_id)} onClick={() => void refresh(video.id, "views")}>
                  {busy === `${video.id}-views` ? "Refreshing…" : "Refresh views"}
                </Button>
                <Button size="sm" variant="outline" disabled={!desk.connected || busy !== "" || !(videoIds[video.id] ?? video.youtube_video_id)} onClick={() => void refresh(video.id, "retention")}>
                  {busy === `${video.id}-retention` ? "Refreshing…" : "Refresh retention"}
                </Button>
              </div>
              {(["views", "retention"] as const).map((key) => desk.research.pulls[key]?.error && (
                <p key={key} className="text-xs text-destructive">{desk.research.pulls[key]?.error} The previous stored result was kept.</p>
              ))}
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}
