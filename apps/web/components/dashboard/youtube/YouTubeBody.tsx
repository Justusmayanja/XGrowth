"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Textarea } from "@/components/ui/textarea";
import {
  confirmYouTubeVideo,
  refreshYouTubeResearch,
  refreshYouTubeVideoData,
  renderYouTubeVideo,
  saveYouTubeListings,
  saveYouTubeResults,
  saveYouTubeText,
  sendYouTubeVideo,
  uploadYouTubeFootage,
  type YouTubeDesk,
} from "@/lib/youtube";
import { YouTubeRenderPanel } from "@/components/dashboard/youtube/YouTubeRenderPanel";
import { YouTubeUploadPanel } from "@/components/dashboard/youtube/YouTubeUploadPanel";

function Section({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: React.ReactNode;
}) {
  return (
    <Card>
      <CardHeader className="pb-5">
        <CardTitle className="text-base">{title}</CardTitle>
        {description && <CardDescription>{description}</CardDescription>}
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

export function YouTubeBody({ desk, onChange }: { desk: YouTubeDesk; onChange: () => Promise<void> }) {
  const [note, setNote] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [transcript, setTranscript] = useState("");
  const [retention, setRetention] = useState("");
  const [comments, setComments] = useState("");
  const [listings, setListings] = useState("");
  const [views, setViews] = useState("");
  const [averageViewPercent, setAverageViewPercent] = useState("");
  const [nicheQuery, setNicheQuery] = useState("");
  const [youtubeVideoId, setYoutubeVideoId] = useState("");
  const [busy, setBusy] = useState("");
  const [message, setMessage] = useState("");
  const mode = desk.setup.settings.video_mode;
  const pipeline = desk.pipeline ?? [];
  const front = pipeline[0];
  const sourceVideoId = youtubeVideoId.trim();

  useEffect(() => {
    setYoutubeVideoId(front?.youtube_video_id || "");
  }, [front?.id, front?.youtube_video_id]);

  function pullState(key: keyof YouTubeDesk["research"]["pulls"], label: string) {
    const pull = desk.research.pulls[key];
    if (!pull) return <p className="text-xs text-muted-foreground">{label}: not refreshed yet.</p>;
    return (
      <div className="text-xs text-muted-foreground">
        <p>{label}: {pull.refreshed_at ? `cached ${new Date(pull.refreshed_at).toLocaleString()}` : "no cached result"}.</p>
        {pull.error && <p className="text-destructive">{pull.error} The last stored result was kept.</p>}
      </div>
    );
  }

  async function run(action: () => Promise<unknown>, busyKey: string, saved = "") {
    setBusy(busyKey);
    setMessage("");
    try {
      await action();
      setMessage(saved);
      await onChange();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Could not save that.");
    } finally {
      setBusy("");
    }
  }

  async function upload(event: FormEvent) {
    event.preventDefault();
    if (!file) {
      setMessage("Choose a video file first.");
      return;
    }
    const body = new FormData();
    body.append("footage", file);
    if (note.trim()) body.append("note", note.trim());
    await run(async () => {
      await uploadYouTubeFootage(body);
      setFile(null);
      setNote("");
    }, "upload");
  }

  async function sendUpload(privacy: "public" | "unlisted" | "private") {
    if (!front) return;
    setBusy("send");
    setMessage("");
    try {
      await sendYouTubeVideo(front.id, privacy);
      setMessage("YouTube posted the video. The desk shows the visibility YouTube applied.");
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "The video was not posted.");
    } finally {
      setBusy("");
      await onChange();
    }
  }

  async function saveListings(event: FormEvent) {
    event.preventDefault();
    const videos = listings
      .split(/\n/)
      .map((line) => line.split("|").map((part) => part.trim()))
      .filter((parts) => parts.length >= 3 && parts[0] && parts[1] && parts[2] !== "")
      .map(([channel, title, count]) => ({ channel, title, views: Number(count) }));
    if (videos.length === 0 || videos.some((video) => !Number.isFinite(video.views))) {
      setMessage("Each line needs a channel, a title, and a view count, separated by |.");
      return;
    }
    await run(async () => {
      await saveYouTubeListings(videos);
      setListings("");
    }, "listings");
  }

  async function saveResults(event: FormEvent) {
    event.preventDefault();
    if (!front) return;
    const count = Number(views);
    const percent = Number(averageViewPercent);
    if (!Number.isInteger(count) || count < 0 || !Number.isFinite(percent) || percent < 0 || percent > 100) {
      setMessage("Enter whole-number views and an average view percentage from 0 to 100.");
      return;
    }
    await run(async () => {
      await saveYouTubeResults(front.id, count, percent);
      setViews("");
      setAverageViewPercent("");
    }, "results", "Results saved. Average view percentage is recorded; only a retention export can change the next opening.");
  }

  async function send(path: "transcript" | "retention" | "comments", body: Record<string, string>, saved: string) {
    if (!front) return;
    await run(async () => {
      await saveYouTubeText(front.id, path, body);
      if (path === "transcript") setTranscript("");
      if (path === "retention") setRetention("");
      if (path === "comments") setComments("");
    }, path, saved);
  }

  return (
    <div className="space-y-6">
      {mode === "footage" && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Bring your own footage</CardTitle>
            <CardDescription>Upload writes an edit plan. Making the local file applies that plan on this server. Nothing is uploaded.</CardDescription>
          </CardHeader>
          <CardContent>
            <form className="space-y-3" onSubmit={upload}>
              <label className="block text-sm">
                Video file
                <input
                  className="mt-1 block w-full text-sm"
                  type="file"
                  accept="video/mp4,video/quicktime,video/webm"
                  onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                />
              </label>
              <label className="block text-sm">
                What is said, if you want the edit plan started from your words
                <Textarea className="mt-1" rows={3} value={note} onChange={(event) => setNote(event.target.value)} />
              </label>
              <Button type="submit" size="sm" disabled={busy === "upload"}>
                {busy === "upload" ? "Writing the edit…" : "Write the edit plan"}
              </Button>
            </form>
          </CardContent>
        </Card>
      )}

      <Section
        title="YouTube research reads"
        description={`Nothing refreshes on page load. Reads use ${desk.research.used}/${desk.research.budget} daily units; successful pulls stay cached for ${desk.research.cache_minutes} minutes.`}
      >
        <div className="space-y-3">
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={!desk.connected || busy === "channel-videos"}
              onClick={() => run(() => refreshYouTubeResearch("channel-videos"), "channel-videos", "Channel videos refreshed or served from cache.")}
            >
              {busy === "channel-videos" ? "Refreshing…" : "Refresh channel videos"}
            </Button>
          </div>
          {pullState("channel_videos", "Channel videos")}
          <form
            className="flex flex-col gap-2 sm:flex-row"
            onSubmit={(event) => {
              event.preventDefault();
              void run(() => refreshYouTubeResearch("niche-search", { query: nicheQuery.trim() }), "niche-search", "Niche search refreshed or served from cache.");
            }}
          >
            <input
              className="min-w-0 flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm"
              value={nicheQuery}
              onChange={(event) => setNicheQuery(event.target.value)}
              placeholder="Bounded niche search"
              maxLength={100}
            />
            <Button type="submit" size="sm" variant="outline" disabled={!desk.connected || busy === "niche-search" || nicheQuery.trim().length < 2}>
              {busy === "niche-search" ? "Searching…" : "Refresh niche search"}
            </Button>
          </form>
          {pullState("niche_search", "Niche search")}
          {!desk.connected && <p className="text-xs text-muted-foreground">Connect the read-only YouTube channel in Settings to use API refreshes. Paste forms below remain available.</p>}
        </div>
      </Section>

      <Section title="What is working in the niche" description={desk.niche?.note || "Videos pasted here that beat their own channel's median."}>
        {(desk.niche?.working.length ?? 0) === 0 ? (
          <p className="text-sm text-muted-foreground">Public listings are not in yet. A channel needs four videos before a median means anything.</p>
        ) : (
          <ul className="space-y-3">
            {desk.niche?.working.map((video) => (
              <li key={`${video.channel}-${video.title}`}>
                <p className="font-medium">{video.multiple}× · {video.title}</p>
                <p className="text-sm text-muted-foreground">{video.formula} · {video.channel}</p>
              </li>
            ))}
          </ul>
        )}
        {desk.niche?.shared && <p className="mt-3 text-sm">{desk.niche.shared}</p>}
        {(desk.niche?.makeable.length ?? 0) > 0 && (
          <ul className="mt-3 space-y-2">
            {desk.niche?.makeable.map((item) => (
              <li key={item.title} className="text-sm text-muted-foreground">
                This channel can make a version this week, in a {item.voice} voice, with {item.have}: {item.idea}
              </li>
            ))}
          </ul>
        )}
        {(desk.niche?.skipped.length ?? 0) > 0 && (
          <p className="mt-3 text-xs text-muted-foreground">
            Skipped {desk.niche?.skipped.map((channel) => `${channel.channel} (${channel.count})`).join(", ")}. Fewer than four videos is not a median.
          </p>
        )}
        <form className="mt-4 space-y-2" onSubmit={saveListings}>
          <label className="block text-sm">
            Public listings, one video per line: channel | title | views
            <Textarea className="mt-1" rows={3} value={listings} onChange={(event) => setListings(event.target.value)} />
          </label>
          <Button type="submit" size="sm" variant="outline" disabled={busy === "listings"}>
            {busy === "listings" ? "Reading…" : "Rank these listings"}
          </Button>
        </form>
      </Section>

      <Section title="This week" description="Three pieces at most, from pasted listings this channel can make and questions repeated in pasted comments.">
        {(desk.week?.length ?? 0) === 0 ? (
          <p className="text-sm text-muted-foreground">Nothing to write yet. The list fills from public listings and from questions asked more than once.</p>
        ) : (
          <ol className="space-y-3">
            {desk.week?.map((piece, index) => (
              <li key={piece.topic}>
                <p className="font-medium">{index + 1}. {piece.topic}</p>
                <p className="text-sm text-muted-foreground">{piece.reason}</p>
              </li>
            ))}
          </ol>
        )}
      </Section>

      {desk.audit?.fix && (
        <Section title="This week's fix" description="One change. The rest can wait.">
          <p className="text-sm">{desk.audit.fix}</p>
          <ul className="mt-3 space-y-1 text-sm text-muted-foreground">
            {(desk.audit.working.length ? desk.audit.working : ["Nothing recorded yet that should be protected."]).map((item) => <li key={item}>{item}</li>)}
          </ul>
          <p className="mt-3 text-sm">{desk.audit.not_yet}</p>
        </Section>
      )}

      {desk.series && (
        <Section title={desk.series.title} description={desk.series.premise}>
          <p className="text-sm text-muted-foreground">
            {desk.series.holds_until
              ? `The next episode can be written after ${desk.series.holds_until}. The six-day floor still applies even after results are saved.`
              : `Episode ${desk.series.next_episode} is next.`}
          </p>
          {desk.series.has_retention_lesson && (
            <p className="mt-2 text-sm">A retention lesson from the last episode is ready for the next script.</p>
          )}
          <ol className="mt-3 space-y-2">
            {desk.series.outline.map((episode) => (
              <li key={episode.number} className="text-sm">
                <span className="font-medium">{episode.number}. {episode.title}</span>
                <span className="mt-0.5 block text-muted-foreground">{episode.beat}</span>
              </li>
            ))}
          </ol>
        </Section>
      )}

      {front ? (
        <Section title="The piece in front" description={front.package?.formula_why || "Scored title, two hooks, the script, and the description."}>
          <div className="space-y-5">
            <div className="flex flex-wrap items-center gap-2">
              <p className="font-medium">{front.title}</p>
              <Badge variant="outline" className="font-normal">{front.status_label}</Badge>
            </div>
            {front.package?.titles?.slice(0, 2).map((title) => (
              <div key={title.title}>
                <p className="text-sm">{title.title}</p>
                <p className="text-xs text-muted-foreground">
                  {title.score}/100 · {title.chars} characters
                  {title.issues[0] ? ` · ${title.issues.map((issue) => issue[1]).join(" ")}` : ""}
                </p>
              </div>
            ))}
            {front.package?.thumbnail_brief && (
              <div className="text-sm text-muted-foreground">
                <p>Thumbnail: {front.package.thumbnail_brief.words}</p>
                <p>{front.package.thumbnail_brief.expression}</p>
                <p>{front.package.thumbnail_brief.framing}</p>
                <p>{front.package.thumbnail_brief.background}</p>
              </div>
            )}
            {front.package?.hooks?.map((hook) => (
              <div key={hook.text}>
                <p className="text-sm">{hook.text}</p>
                <p className="text-xs text-muted-foreground">{hook.score}/100 · {hook.formula} · {hook.band}</p>
              </div>
            ))}
            {front.package?.runtime_seconds != null && (
              <p className="text-xs text-muted-foreground">
                {front.package.runtime_seconds >= 90
                  ? `About ${Math.max(1, Math.round(front.package.runtime_seconds / 60))} minutes at 150 words a minute.`
                  : `About ${front.package.runtime_seconds} seconds at 150 words a minute.`}
              </p>
            )}
            {front.script && <p className="max-h-72 overflow-y-auto whitespace-pre-wrap text-sm text-muted-foreground">{front.script}</p>}
            {front.description && <p className="max-h-64 overflow-y-auto whitespace-pre-wrap text-sm text-muted-foreground">{front.description}</p>}
            {front.package?.chapters && <ul className="space-y-1 text-sm">{front.package.chapters.map((chapter) => <li key={chapter.label}>{chapter.label} {chapter.title}</li>)}</ul>}
            {front.package?.search_facing === false && <p className="text-sm text-muted-foreground">This one is for the people who already watch. The work is in the title and the thumbnail.</p>}
            {front.package?.has_transcript && front.package.shorts && front.package.shorts.length > 0 && (
              <div className="space-y-3">
                <p className="text-sm font-medium">Shorts cut from this video</p>
                {front.package.shorts.map((clip) => (
                  <div key={`${clip.start}-${clip.first_line}`} className="text-sm text-muted-foreground">
                    <p>{clip.start}–{clip.end} · {clip.first_line}</p>
                    <p>New first line ({clip.opening.score}): {clip.opening.text}</p>
                    <p>On screen: {clip.on_screen}</p><p>{clip.loop}</p><p>{clip.crop}</p>
                  </div>
                ))}
              </div>
            )}
            {front.package?.has_transcript && front.package.edit && (
              <div className="space-y-1 text-sm text-muted-foreground">
                <p>{front.package.edit.caption_style}</p><p>{front.package.edit.caption_note}</p>
                {front.package.edit.zooms?.map((zoom) => <p key={zoom.reason}>Zoom to {zoom.scale}× · {zoom.reason}</p>)}
              </div>
            )}
            {front.package?.comments && (
              <div className="space-y-2 text-sm">
                <p>{front.package.comments.counts.questions} questions, {front.package.comments.counts.corrections} corrections, {front.package.comments.counts.praise} praise, {front.package.comments.counts.bait} bait.</p>
                {front.package.comments.pin && <p>Pin: {front.package.comments.pin.comment} {front.package.comments.pin.why}</p>}
                {front.package.comments.replies.map((reply) => <p key={reply.comment} className="text-muted-foreground">{reply.reply}</p>)}
              </div>
            )}
            {front.package?.gate && <p className="text-sm font-medium">{front.package.gate}</p>}
            <YouTubeRenderPanel
              item={front}
              busy={busy === "render"}
              onRender={() => void run(() => renderYouTubeVideo(front.id), "render", "The local file step finished. Nothing was uploaded.")}
            />
            <YouTubeUploadPanel
              desk={desk}
              item={front}
              busy={busy === "send"}
              onSend={(privacy) => void sendUpload(privacy)}
            />
            {front.status === "waiting" && (
              <div className="space-y-2">
                <Button size="sm" variant="secondary" disabled={busy === "confirm"} onClick={() => run(() => confirmYouTubeVideo(front.id), "confirm", "Approved and queued locally. Nothing was posted.")}>
                  {busy === "confirm" ? "Approving…" : "Ship it"}
                </Button>
                <p className="text-xs text-muted-foreground">Ship it approves this package. It does not upload or post it.</p>
              </div>
            )}
            {["waiting", "queued", "posted", "learned"].includes(front.status) && (
              <form className="space-y-2" onSubmit={saveResults}>
                <p className="text-sm font-medium">Record results</p>
                <div className="grid gap-3 sm:grid-cols-2">
                  <label className="text-sm">Views<input className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2" type="number" min="0" step="1" value={views} onChange={(event) => setViews(event.target.value)} /></label>
                  <label className="text-sm">Average view percentage<input className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2" type="number" min="0" max="100" step="0.1" value={averageViewPercent} onChange={(event) => setAverageViewPercent(event.target.value)} /></label>
                </div>
                <p className="text-xs text-muted-foreground">This marks the piece learned, but does not choose the next opening. Only a retention export creates that lesson.</p>
                <Button type="submit" size="sm" variant="outline" disabled={busy === "results" || views === "" || averageViewPercent === ""}>Save results</Button>
              </form>
            )}
            <div className="space-y-3 rounded-md border border-border p-3">
              <label className="block text-sm">
                Published YouTube video ID for API reads
                <input
                  className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2"
                  value={youtubeVideoId}
                  onChange={(event) => setYoutubeVideoId(event.target.value)}
                  placeholder="For example: dQw4w9WgXcQ"
                />
              </label>
              <div className="flex flex-wrap gap-2">
                {(["views", "retention", "comments"] as const).map((path) => (
                  <Button
                    key={path}
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={!desk.connected || busy === `refresh-${path}` || sourceVideoId.length < 6}
                    onClick={() => run(
                      () => refreshYouTubeVideoData(front.id, path, sourceVideoId),
                      `refresh-${path}`,
                      `${path[0].toUpperCase()}${path.slice(1)} refreshed or served from cache.`,
                    )}
                  >
                    {busy === `refresh-${path}` ? "Refreshing…" : `Refresh ${path}`}
                  </Button>
                ))}
              </div>
              {pullState("views", "Views")}
              {pullState("retention", "Retention")}
              {pullState("comments", "Comments")}
              <p className="text-xs text-muted-foreground">API views use the same results path below. API retention is the only fetched read that can change the next opening. API comments use the same triage as pasted comments.</p>
            </div>
            <form className="space-y-2" onSubmit={(event) => { event.preventDefault(); void send("transcript", { transcript }, "Shorts are ready."); }}>
              <label className="block text-sm">
                Transcript of the finished video
                <Textarea className="mt-1" rows={3} value={transcript} onChange={(event) => setTranscript(event.target.value)} />
              </label>
              <p className="text-xs text-muted-foreground">Paste still cuts Shorts. A configured local transcription provider can fill this after the file is made. After a real YouTube video id exists, captions.list can refresh the transcript. If that read fails, the pasted or local transcript stays.</p>
              <Button type="submit" size="sm" variant="outline" disabled={busy === "transcript" || transcript.trim() === ""}>Add the transcript</Button>
            </form>
            <form className="space-y-2" onSubmit={(event) => { event.preventDefault(); void send("retention", { csv: retention }, "Saved for the next video."); }}>
              <label className="block text-sm">Audience retention export from YouTube Studio<Textarea className="mt-1" rows={3} value={retention} onChange={(event) => setRetention(event.target.value)} /></label>
              <Button type="submit" size="sm" variant="outline" disabled={busy === "retention" || retention.trim() === ""}>Save the export</Button>
            </form>
            <form className="space-y-2" onSubmit={(event) => { event.preventDefault(); void send("comments", { comments }, "Replies are ready."); }}>
              <label className="block text-sm">Comments, one per line<Textarea className="mt-1" rows={3} value={comments} onChange={(event) => setComments(event.target.value)} /></label>
              <Button type="submit" size="sm" variant="outline" disabled={busy === "comments" || comments.trim() === ""}>Sort and reply</Button>
            </form>
          </div>
        </Section>
      ) : (
        <p className="text-sm text-muted-foreground">
          {mode === "footage" ? "Upload footage to write an edit plan, then make the local file when you want a preview. Nothing is uploaded." : "The piece in front is written when this week's list has something the channel can make."}
        </p>
      )}
      {message && <p className="text-sm text-muted-foreground">{message}</p>}
    </div>
  );
}
