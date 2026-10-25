"use client";

import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowUpRight } from "lucide-react";
import { LoadingMark } from "@/components/LoadingMark";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api, apiForm, getToken } from "@/lib/api";
import { contentFormatLabel } from "@/lib/content-formats";
import {
  formatMultiLabels,
  PLATFORM_GOALS,
  PLATFORM_ORDER,
  platformName,
  type Platform,
  YOUTUBE_FORMATS,
  YOUTUBE_MODES,
} from "@/lib/profile";

type Metric = { label: string; value: number; sub: string; suffix?: string };

type YouTubePackage = {
  hook?: string;
  thumbnail?: string;
  on_screen?: { text: string; at: string }[];
  shots?: { prompt: string; seconds: number; kind: string }[];
  render?: { ready?: boolean; note?: string; kind?: string };
  publish?: { note?: string };
  edit?: {
    caption_style?: string;
    caption_note?: string;
    captions?: { text: string; at: string }[];
    zooms?: { at_ratio: number; scale: number; reason: string }[];
    color?: { preset?: string; contrast?: number };
    audio?: { loudness_lufs?: number };
    silence?: { trim_over_seconds?: number };
  };
};

type YouTubeItem = {
  id: number;
  mode: string;
  format: string | null;
  format_label: string | null;
  status: string;
  status_label: string;
  topic: string | null;
  title: string | null;
  script: string | null;
  description: string | null;
  episode_number: number | null;
  source_name: string | null;
  views: number;
  average_view_percent: number | null;
  learning_note: string | null;
  package: YouTubePackage | null;
};

type YouTubeSeriesCard = {
  title: string;
  premise: string;
  next_episode: number;
  holds_until: string | null;
  characters: { name: string; role: string }[];
  outline: { number: number; title: string; beat: string }[];
};

type Desk = {
  platform: Platform;
  chosen: boolean;
  setup: {
    completed: boolean;
    goals: string[];
    settings: {
      automation_level?: number;
      mode?: string;
      publish?: string;
      video_mode?: string;
      format?: string;
      auto_post_enabled?: boolean;
      engine_paused?: boolean;
    };
  };
  connected: { username?: string | null } | null;
  preview: boolean;
  metrics: Metric[];
  counts: { drafts: number; posted: number };
  drafts: { id: number; content: string; content_type: string; created_at: string }[];
  ideas: { name: string; suggestion?: string }[];
  pipeline?: YouTubeItem[];
  series?: YouTubeSeriesCard | null;
};

const LEDE: Record<Platform, string> = {
  x: "It watches your niche and posts when a moment is strong. There is no schedule.",
  tiktok: "It finds ideas that suit the brand, writes them, makes the clip, and suggests a time. You say yes.",
  youtube: "It searches the niche, writes the script or the edit, and posts when the file and the channel connection are both ready.",
};

const FORMAT: Record<Platform, string> = {
  x: "tweet",
  tiktok: "tiktok_caption",
  youtube: "youtube_title",
};

function composeHref(platform: Platform, topic?: string) {
  const params = new URLSearchParams({ platform, format: FORMAT[platform] });
  if (topic) params.set("topic", topic);
  return `/content-studio?${params.toString()}`;
}

function paceLine(desk: Desk): string {
  return desk.setup.settings.mode === "proactive" ? "Even on a quiet day" : "When something is happening";
}

function relativeTime(iso: string): string {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return "";
  const minutes = Math.max(0, Math.round((Date.now() - then) / 60000));
  if (minutes < 1) return "just now";
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.round(hours / 24);
  if (days < 7) return `${days}d ago`;
  return new Date(iso).toLocaleDateString();
}

function postingLine(desk: Desk): string {
  if (desk.platform === "tiktok") return "Suggests a time, then waits for your yes";
  if (desk.platform === "youtube") {
    const mode = YOUTUBE_MODES.find((item) => item.value === desk.setup.settings.video_mode)?.label ?? "YouTube";
    const format = YOUTUBE_FORMATS.find((item) => item.value === desk.setup.settings.format)?.label;
    const publish = desk.setup.settings.publish === "auto" ? "Posts on its own when the file is ready" : "Waits for your yes before posting";
    return [mode, format, publish].filter(Boolean).join(" · ");
  }
  if (desk.setup.settings.engine_paused) return "Paused. Nothing is posted until you turn it back on";
  const posting = desk.setup.settings.auto_post_enabled !== false;
  switch (desk.setup.settings.automation_level) {
    case 0:
      return "Suggests only. You post";
    case 2:
      return posting ? "Posts the strong moments on its own" : "Drafts the strong moments. You approve";
    case 3:
      return posting
        ? "Posts on its own, even while you are offline"
        : "Full automation is selected, but posting is off";
    default:
      return "Drafts the moment. You approve";
  }
}

function AddPlatforms({ chosen }: { chosen: Platform[] }) {
  const missing = PLATFORM_ORDER.filter((platform) => !chosen.includes(platform));
  if (missing.length === 0) return null;
  return (
    <div className="flex flex-wrap gap-2">
      {missing.map((platform) => (
        <Link key={platform} href={`/onboarding?platform=${platform}`}>
          <Button variant="outline" size="sm">
            Add {platformName(platform)}
          </Button>
        </Link>
      ))}
    </div>
  );
}

function Section({
  title,
  description,
  action,
  children,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
  children: ReactNode;
}) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0 pb-5">
        <div>
          <CardTitle className="text-base">{title}</CardTitle>
          {description && <CardDescription className="mt-1">{description}</CardDescription>}
        </div>
        {action}
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

function IdeaList({ desk, verb, loose = false }: { desk: Desk; verb: string; loose?: boolean }) {
  if (desk.ideas.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        {desk.platform === "x"
          ? desk.connected
            ? "No English topics from X yet."
            : "Connect X and topics from the account show here."
          : "Topics for this account show here."}
      </p>
    );
  }
  return (
    <ul className={loose ? "space-y-1" : "divide-y divide-border"}>
      {desk.ideas.map((idea) => (
        <li
          key={idea.name}
          className={
            loose
              ? "group flex items-start justify-between gap-4 rounded-md px-2 py-2.5 transition-colors hover:bg-white/[0.04]"
              : "flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0"
          }
        >
          <div className="min-w-0">
            <p className="font-medium">{idea.name}</p>
            {idea.suggestion && <p className="mt-0.5 text-sm text-muted-foreground">{idea.suggestion}</p>}
          </div>
          <Link
            href={composeHref(desk.platform, idea.name)}
            className={`flex shrink-0 items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground ${loose ? "group-hover:text-foreground" : ""}`}
          >
            {verb}
            <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Link>
        </li>
      ))}
    </ul>
  );
}

function DraftList({ desk, empty, loose = false }: { desk: Desk; empty: string; loose?: boolean }) {
  if (desk.drafts.length === 0) {
    return <p className="text-sm text-muted-foreground">{empty}</p>;
  }
  return (
    <ul className={loose ? "space-y-1" : "divide-y divide-border"}>
      {desk.drafts.map((draft) => (
        <li key={draft.id} className={loose ? "rounded-md px-2 py-2.5 transition-colors hover:bg-white/[0.04]" : "py-3 first:pt-0 last:pb-0"}>
          <Link href="/drafts" className="block rounded-md hover:text-foreground">
            <p className="line-clamp-2 text-sm">{draft.content}</p>
            <p className="mt-1 text-xs text-muted-foreground">
              {contentFormatLabel(draft.content_type)} · {loose ? relativeTime(draft.created_at) : new Date(draft.created_at).toLocaleDateString()}
            </p>
          </Link>
        </li>
      ))}
    </ul>
  );
}

function XBody({ desk }: { desk: Desk }) {
  const moments = desk.ideas.length;
  const waiting = desk.counts.drafts;
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <div className="desk-arrive h-full" style={{ animationDelay: "120ms" }}>
        <Section
          title="Topics"
          description={
            moments
              ? `${moments} English posts from what people are talking about.`
              : "English posts from what people are talking about."
          }
          action={
            <Link href="/trends" className="text-sm text-muted-foreground hover:text-foreground">
              All topics
            </Link>
          }
        >
          <IdeaList desk={desk} verb="Write a post" loose />
        </Section>
      </div>
      <div className="space-y-6">
        <div className="desk-arrive" style={{ animationDelay: "160ms" }}>
          <Section
            title="Ready to go out"
            description={
              waiting
                ? `${waiting} waiting for a strong moment or your yes.`
                : "Drafts waiting for a strong moment or your yes."
            }
            action={
              <Link href="/growth-agent" className="text-sm text-muted-foreground hover:text-foreground">
                Queue
              </Link>
            }
          >
            <DraftList desk={desk} empty="Nothing waiting. Write a post from a moment on the left." loose />
          </Section>
        </div>
        <div className="desk-arrive" style={{ animationDelay: "200ms" }}>
          <Section
            title="Replies"
            description={
              desk.connected
                ? "Mentions and replies on this account, with the counts X returned."
                : "Connect X and mentions on this account show up here."
            }
          >
            <Link href={desk.connected ? "/engagement" : "/settings"}>
              <Button variant="secondary" size="sm">{desk.connected ? "Open replies" : "Connect X"}</Button>
            </Link>
          </Section>
        </div>
      </div>
    </div>
  );
}

function XDeskSkeleton() {
  return (
    <div className="space-y-6" aria-busy="true" aria-label="Loading X">
      <LoadingMark />
      <div className="grid gap-4 sm:grid-cols-3">
        {[0, 1, 2].map((item) => (
          <div key={item} className="h-24 animate-pulse rounded-lg bg-white/[0.04]" />
        ))}
      </div>
      <div className="grid gap-4 sm:grid-cols-3">
        {[0, 1, 2].map((item) => (
          <div key={item} className="h-28 animate-pulse rounded-lg bg-white/[0.04]" />
        ))}
      </div>
      <div className="grid gap-6 lg:grid-cols-2">
        <div className="h-64 animate-pulse rounded-lg bg-white/[0.04]" />
        <div className="space-y-6">
          <div className="h-40 animate-pulse rounded-lg bg-white/[0.04]" />
          <div className="h-28 animate-pulse rounded-lg bg-white/[0.04]" />
        </div>
      </div>
    </div>
  );
}

function XDeskView({ desk, chosen, goals }: { desk: Desk; chosen: Platform[]; goals: string }) {
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-medium">X</h1>
          <p className="mt-1 max-w-xl text-sm text-muted-foreground">{postingLine(desk)}</p>
        </div>
        <AddPlatforms chosen={chosen} />
      </div>

      <div className="desk-arrive grid gap-4 sm:grid-cols-3">
        <Card className="h-full">
          <CardContent className="pt-6">
            <p className="text-xs text-muted-foreground">Posting</p>
            <p className="mt-1 text-sm font-medium">{paceLine(desk)}</p>
            <p className="mt-1 text-sm text-muted-foreground">{postingLine(desk)}</p>
          </CardContent>
        </Card>
        <Card className="h-full">
          <CardContent className="pt-6">
            <p className="text-xs text-muted-foreground">Goals</p>
            <p className="mt-1 text-sm">{goals}</p>
            <Link href="/onboarding?platform=x" className="mt-1 inline-block text-xs text-muted-foreground hover:text-foreground">
              Change
            </Link>
          </CardContent>
        </Card>
        <Card className="h-full">
          <CardContent className="pt-6">
            <p className="text-xs text-muted-foreground">Account</p>
            {desk.connected ? (
              <p className="mt-1 text-sm">{desk.connected.username ? `@${desk.connected.username}` : "Connected"}</p>
            ) : (
              <Link href="/settings" className="mt-1 inline-block text-sm underline underline-offset-4">
                Connect X
              </Link>
            )}
          </CardContent>
        </Card>
      </div>

      <div className="desk-arrive grid gap-4 sm:grid-cols-3" style={{ animationDelay: "60ms" }}>
        {desk.metrics.map((metric) => (
          <Link key={metric.label} href="/analytics?platform=x" className="block h-full rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground">
            <Card className="h-full">
              <CardContent className="pt-6">
                <div className="flex items-center justify-between gap-2">
                  <p className="text-sm text-muted-foreground">{metric.label}</p>
                  {desk.preview && (
                    <Badge variant="outline" className="text-[10px] font-normal text-muted-foreground">
                      Sample
                    </Badge>
                  )}
                </div>
                <p className="mt-2 text-2xl font-medium tabular-nums">
                  {metric.value.toLocaleString()}
                  {metric.suffix ?? ""}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">{metric.sub}</p>
              </CardContent>
            </Card>
          </Link>
        ))}
      </div>

      <XBody desk={desk} />
    </div>
  );
}

function Learned({ desk }: { desk: Desk }) {
  return (
    <Section title="What landed" description="It notices what worked and leans into it next time.">
      <p className="text-sm text-muted-foreground">
        {desk.counts.posted === 0
          ? `This fills in after the first ${platformName(desk.platform)} posts go out.`
          : `${desk.counts.posted} posted so far. Results are in the Results page.`}
      </p>
    </Section>
  );
}

function Pipeline({ steps }: { steps: { label: string; value: number | string; hint: string }[] }) {
  return (
    <Card>
      <CardContent className="grid gap-0 p-0 sm:grid-cols-4">
        {steps.map((step, index) => (
          <div
            key={step.label}
            className={`p-5 ${index > 0 ? "border-t border-border sm:border-l sm:border-t-0" : ""}`}
          >
            <p className="text-xs text-muted-foreground">
              {index + 1}. {step.label}
            </p>
            <p className="mt-2 font-mono text-2xl tabular-nums">{step.value}</p>
            <p className="mt-1 text-xs text-muted-foreground">{step.hint}</p>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

function TikTokBody({ desk }: { desk: Desk }) {
  return (
    <div className="space-y-6">
      <Pipeline
        steps={[
          { label: "Ideas found", value: desk.ideas.length, hint: "Suited to your brand" },
          { label: "Written", value: desk.counts.drafts, hint: "Hooks and captions" },
          { label: "Waiting for you", value: desk.counts.drafts, hint: "Each with a suggested time" },
          { label: "Posted", value: desk.counts.posted, hint: "After your yes" },
        ]}
      />
      <div className="grid gap-6 lg:grid-cols-2">
        <Section title="Ideas that suit you" description="Picked for the brand you described.">
          <IdeaList desk={desk} verb="Write a clip" />
        </Section>
        <div className="space-y-6">
          <Section title="Waiting for your yes" description="Nothing goes out on TikTok until you confirm.">
            <DraftList desk={desk} empty="No clips waiting. Pick an idea to start one." />
          </Section>
          <Learned desk={desk} />
        </div>
      </div>
    </div>
  );
}

function YouTubeBody({ desk, onChange }: { desk: Desk; onChange: () => Promise<void> }) {
  const [note, setNote] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [views, setViews] = useState("");
  const [percent, setPercent] = useState("");
  const [busy, setBusy] = useState("");
  const [message, setMessage] = useState("");
  const mode = desk.setup.settings.video_mode;
  const pipeline = desk.pipeline ?? [];

  async function upload(event: FormEvent) {
    event.preventDefault();
    if (!file) {
      setMessage("Choose a video file first.");
      return;
    }
    setBusy("upload");
    setMessage("");
    try {
      const body = new FormData();
      body.append("footage", file);
      if (note.trim()) body.append("note", note.trim());
      await apiForm("/youtube/footage", body);
      setFile(null);
      setNote("");
      await onChange();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Could not upload the footage.");
    } finally {
      setBusy("");
    }
  }

  async function confirm(id: number) {
    setBusy(`confirm-${id}`);
    setMessage("");
    try {
      await api(`/youtube/videos/${id}/confirm`, { method: "POST" });
      await onChange();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Could not queue that video.");
    } finally {
      setBusy("");
    }
  }

  async function saveResults(id: number) {
    setBusy(`results-${id}`);
    setMessage("");
    try {
      await api(`/youtube/videos/${id}/results`, {
        method: "POST",
        body: JSON.stringify({
          views: Number(views),
          average_view_percent: Number(percent),
        }),
      });
      setViews("");
      setPercent("");
      await onChange();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Could not save the results.");
    } finally {
      setBusy("");
    }
  }

  return (
    <div className="space-y-6">
      {mode === "footage" && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Bring your own footage</CardTitle>
            <CardDescription>The edit plan is written from the file: captions, zooms, color, and sound.</CardDescription>
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
                What is said, if you want the captions started from your words
                <Textarea className="mt-1" rows={3} value={note} onChange={(event) => setNote(event.target.value)} />
              </label>
              <Button type="submit" size="sm" disabled={busy === "upload"}>
                {busy === "upload" ? "Writing the edit…" : "Write the edit plan"}
              </Button>
            </form>
          </CardContent>
        </Card>
      )}

      {desk.series && (
        <Section title={desk.series.title} description={desk.series.premise}>
          <p className="text-sm text-muted-foreground">
            {desk.series.holds_until
              ? `Next episode after ${desk.series.holds_until}.`
              : `Episode ${desk.series.next_episode} is next.`}
          </p>
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

      <div className="grid gap-6 lg:grid-cols-2">
        <Section title={mode === "series" ? "The season" : "Ideas likely to catch on"} description="Picked for this account. The engine writes the top one.">
          {desk.ideas.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {mode === "footage" ? "Footage edits do not search for a topic." : "Topics for this account show here."}
            </p>
          ) : (
            <ul className="divide-y divide-border">
              {desk.ideas.map((idea) => (
                <li key={idea.name} className="py-3 first:pt-0 last:pb-0">
                  <p className="font-medium">{idea.name}</p>
                  {idea.suggestion && <p className="mt-0.5 text-sm text-muted-foreground">{idea.suggestion}</p>}
                </li>
              ))}
            </ul>
          )}
        </Section>

        <Section title="Scripts and edits" description="Written here. The video file is added when the render or editor is connected.">
          {pipeline.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {mode === "footage" ? "Upload a video to write the first edit plan." : "The first script is written when YouTube setup finishes."}
            </p>
          ) : (
            <ul className="space-y-5">
              {pipeline.map((item) => (
                <li key={item.id} className="space-y-2 border-b border-border pb-5 last:border-0 last:pb-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-medium">{item.title}</p>
                    <Badge variant="outline" className="font-normal">{item.status_label}</Badge>
                  </div>
                  {item.format_label && <p className="text-xs text-muted-foreground">{item.format_label}</p>}
                  {item.script && (
                    <p className="max-h-64 overflow-y-auto whitespace-pre-wrap text-sm text-muted-foreground">{item.script}</p>
                  )}
                  {item.package?.render?.note && <p className="text-xs text-muted-foreground">{item.package.render.note}</p>}
                  {item.package?.publish?.note && <p className="text-xs text-muted-foreground">{item.package.publish.note}</p>}
                  {item.package?.shots && item.package.shots.length > 0 && (
                    <ul className="space-y-1 text-sm text-muted-foreground">
                      {item.package.shots.map((shot, index) => (
                        <li key={`${item.id}-shot-${index}`}>{shot.seconds}s · {shot.prompt}</li>
                      ))}
                    </ul>
                  )}
                  {item.package?.edit && (
                    <div className="space-y-1 text-sm text-muted-foreground">
                      <p>{item.package.edit.caption_style}</p>
                      <p>{item.package.edit.caption_note}</p>
                      {item.package.edit.zooms?.map((zoom) => (
                        <p key={zoom.reason}>Zoom to {zoom.scale}× · {zoom.reason}</p>
                      ))}
                      <p>
                        Color {item.package.edit.color?.preset}, audio {item.package.edit.audio?.loudness_lufs} LUFS, trim silences over {item.package.edit.silence?.trim_over_seconds}s.
                      </p>
                    </div>
                  )}
                  {item.learning_note && <p className="text-sm">{item.learning_note}</p>}
                  {item.status === "waiting" && (
                    <Button size="sm" variant="secondary" disabled={busy === `confirm-${item.id}`} onClick={() => confirm(item.id)}>
                      {busy === `confirm-${item.id}` ? "Queuing…" : "Yes, post this"}
                    </Button>
                  )}
                  {(item.status === "waiting" || item.status === "queued") && (
                    <form
                      className="flex flex-wrap items-end gap-2"
                      onSubmit={(event) => {
                        event.preventDefault();
                        void saveResults(item.id);
                      }}
                    >
                      <label className="text-xs text-muted-foreground">
                        Views
                        <input
                          className="mt-1 block w-24 rounded-md border border-border bg-transparent px-2 py-1 text-sm text-foreground"
                          inputMode="numeric"
                          value={views}
                          onChange={(event) => setViews(event.target.value)}
                          required
                        />
                      </label>
                      <label className="text-xs text-muted-foreground">
                        % watched
                        <input
                          className="mt-1 block w-24 rounded-md border border-border bg-transparent px-2 py-1 text-sm text-foreground"
                          inputMode="decimal"
                          value={percent}
                          onChange={(event) => setPercent(event.target.value)}
                          required
                        />
                      </label>
                      <Button type="submit" size="sm" variant="outline" disabled={busy === `results-${item.id}`}>
                        {busy === `results-${item.id}` ? "Saving…" : "Save results"}
                      </Button>
                    </form>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Section>
      </div>
      {message && <p className="text-sm text-muted-foreground">{message}</p>}
    </div>
  );
}

export function PlatformDesk({ platform }: { platform: Platform }) {
  const router = useRouter();
  const [desk, setDesk] = useState<Desk | null>(null);
  const [chosen, setChosen] = useState<Platform[]>([]);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    try {
      const state = await api<{ platforms: Platform[]; onboarding_completed: boolean }>("/account/setup");
      if (!state.onboarding_completed) {
        router.replace("/onboarding");
        return;
      }
      setChosen(state.platforms);
      setDesk(await api<Desk>(`/dashboard/platform/${platform}`, undefined, 20000, "The dashboard is taking too long. Refresh in a moment."));
    } catch (err) {
      if (!getToken()) {
        router.replace("/login");
        return;
      }
      setError(err instanceof Error ? err.message : "Could not load this dashboard.");
    }
  }, [platform, router]);

  useEffect(() => {
    setDesk(null);
    setError("");
    void load();
  }, [load]);

  const name = platformName(platform);

  const header = (
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 className="text-xl font-medium">{name}</h1>
        <p className="mt-1 max-w-xl text-sm text-muted-foreground">
          {platform === "x" && desk ? postingLine(desk) : LEDE[platform]}
        </p>
      </div>
      {chosen.length > 0 && <AddPlatforms chosen={chosen} />}
    </div>
  );

  if (error) {
    return (
      <div className="space-y-6">
        {header}
        <p className="text-muted-foreground">{error}</p>
      </div>
    );
  }

  if (!desk) {
    return (
      <div className="space-y-6">
        {header}
        {platform === "x" ? <XDeskSkeleton /> : <LoadingMark className="min-h-[40vh]" />}
      </div>
    );
  }

  if (!desk.chosen || !desk.setup.completed) {
    return (
      <div className="space-y-6">
        {header}
        <Card>
          <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
            <div>
              <p className="font-medium">{desk.chosen ? `Finish ${name}` : `Add ${name}`}</p>
              <p className="mt-1 text-sm text-muted-foreground">
                About a minute. Your niche and voice carry over. {name} gets its own goals.
              </p>
            </div>
            <Link href={`/onboarding?platform=${platform}`}>
              <Button>{desk.chosen ? `Finish ${name}` : `Add ${name}`}</Button>
            </Link>
          </CardContent>
        </Card>
      </div>
    );
  }

  const goals = desk.setup.goals.length ? formatMultiLabels(desk.setup.goals, PLATFORM_GOALS[platform]) : "Not set";

  if (platform === "x") {
    return <XDeskView desk={desk} chosen={chosen} goals={goals} />;
  }

  return (
    <div className="space-y-6">
      {header}

      <Card>
        <CardContent className="grid gap-0 p-0 sm:grid-cols-3">
          <div className="p-5">
            <p className="text-xs text-muted-foreground">Posting</p>
            <p className="mt-1 text-sm">{postingLine(desk)}</p>
          </div>
          <div className="border-t border-border p-5 sm:border-l sm:border-t-0">
            <p className="text-xs text-muted-foreground">Goals</p>
            <p className="mt-1 text-sm">{goals}</p>
            <Link href={`/onboarding?platform=${platform}`} className="mt-1 inline-block text-xs text-muted-foreground hover:text-foreground">
              Change
            </Link>
          </div>
          <div className="border-t border-border p-5 sm:border-l sm:border-t-0">
            <p className="text-xs text-muted-foreground">Account</p>
            {desk.connected ? (
              <p className="mt-1 text-sm">{desk.connected.username ? `@${desk.connected.username}` : "Connected"}</p>
            ) : (
              <Link href="/settings" className="mt-1 inline-block text-sm underline underline-offset-4">
                Connect {name}
              </Link>
            )}
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-4 sm:grid-cols-3">
        {desk.metrics.map((metric) => (
          <Card key={metric.label}>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between gap-2">
                <p className="text-sm text-muted-foreground">{metric.label}</p>
                {desk.preview && (
                  <Badge variant="outline" className="text-[10px] font-normal text-muted-foreground">
                    Sample
                  </Badge>
                )}
              </div>
              <p className="mt-2 font-mono text-2xl tabular-nums">
                {metric.value.toLocaleString()}
                {metric.suffix ?? ""}
              </p>
              <p className="mt-1 text-xs text-muted-foreground">{metric.sub}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      {platform === "tiktok" && <TikTokBody desk={desk} />}
      {platform === "youtube" && <YouTubeBody desk={desk} onChange={load} />}
    </div>
  );
}
