"use client";

import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowUpRight } from "lucide-react";
import { LoadingMark } from "@/components/LoadingMark";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api, apiForm, getToken } from "@/lib/api";
import { TikTokBody } from "@/components/dashboard/tiktok/TikTokBody";
import { contentFormatLabel } from "@/lib/content-formats";
import type { TikTokDesk } from "@/lib/tiktok";
import {
  formatMultiLabels,
  PLATFORM_GOALS,
  PLATFORM_ORDER,
  platformName,
  type Platform,
  YOUTUBE_FORMATS,
  YOUTUBE_MODES,
} from "@/lib/profile";

type Metric = { label: string; value: number | null; sub: string; suffix?: string };

type ScoredTitle = { title: string; chars: number; score: number; issues: [string, string][] };
type ScoredHook = { text: string; score: number; band: string; formula: string };
type YouTubeChapter = { label: string; title: string; seconds: number };
type YouTubeShort = {
  start: string;
  end: string;
  first_line: string;
  opening: { text: string; score: number; formula: string };
  on_screen: string;
  loop: string;
  crop: string;
};

type YouTubePackage = {
  hook?: string;
  hooks?: ScoredHook[];
  winning_formula?: string;
  formula_why?: string;
  titles?: ScoredTitle[];
  thumbnail?: string;
  thumbnail_brief?: { expression?: string; framing?: string; words?: string; background?: string };
  payoff?: string;
  ask?: string;
  runtime_seconds?: number;
  queries?: string[];
  search_facing?: boolean;
  tags?: string[];
  chapters?: YouTubeChapter[];
  chapters_valid?: boolean;
  gate?: string;
  has_transcript?: boolean;
  shorts?: YouTubeShort[];
  comments?: {
    counts: { questions: number; corrections: number; praise: number; bait: number };
    replies: { pile: string; comment: string; reply: string }[];
    pin: { comment: string; why: string } | null;
  };
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
  package: YouTubePackage | null;
};

type NicheResearch = {
  working: { channel: string; title: string; multiple: number; formula: string; views: number; median: number }[];
  shared: string;
  makeable: { title: string; multiple: number; formula: string; idea: string; have: string; voice: string }[];
  skipped: { channel: string; count: number }[];
  note: string;
};

type WeekPiece = { topic: string; reason: string; source?: string };
type ChannelAudit = { fix: string; working: string[]; not_yet: string };

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
  week?: WeekPiece[];
  niche?: NicheResearch | null;
  audit?: ChannelAudit | null;
  tiktok?: TikTokDesk;
  pace?: { ceiling: number; low: number; high: number } | null;
  accounts?: { connected: number; included: number } | null;
  judgment?: { decision: string | null; line: string; reason: string } | null;
  goal_progress?: { goal: string; label: string; value: number | null; sub: string; lesson: string } | null;
  earned?: {
    profile: { content: string; visits: number } | null;
    replies: { content: string; replies: number } | null;
  } | null;
  reply_record?: {
    answered: { author: string; theirs: string; ours: string }[];
    skipped: { author: string; theirs: string; reason: string }[];
  } | null;
  voices?: { id: number; username: string | null; niche: string; own_voice: boolean; uses_main_voice: boolean; selected: boolean }[] | null;
};

const LEDE: Record<Platform, string> = {
  x: "It watches your niche and posts when a moment is strong. There is no schedule.",
  tiktok: "It finds ideas that suit the brand, writes them, makes the clip, and suggests a time. You say yes.",
  youtube: "It reads what is working in the niche, writes this week's piece, and waits on you to ship it or change it.",
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
  if (desk.pace) {
    return `Today's cap is ${desk.pace.ceiling}. It posts only when a moment is strong.`;
  }
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
              ? `${moments} from this account's home timeline, read a few times a day when they fit the description.`
              : "From this account's home timeline, read a few times a day when they fit the description."
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
                ? "Who was answered this morning, and who was left because the post does not fit."
                : "Connect X and mentions on this account show up here."
            }
          >
            <ReplyRecord desk={desk} />
          </Section>
        </div>
      </div>
    </div>
  );
}

function ReplyRecord({ desk }: { desk: Desk }) {
  const selected = desk.voices?.find((voice) => voice.selected);
  const href = !desk.connected
    ? "/settings"
    : selected
      ? `/engagement?account=${selected.id}`
      : "/engagement";
  const answered = desk.reply_record?.answered ?? [];
  const skipped = desk.reply_record?.skipped ?? [];

  if (!desk.connected) {
    return (
      <Link href="/settings">
        <Button variant="secondary" size="sm">Connect X</Button>
      </Link>
    );
  }

  return (
    <div className="space-y-4">
      <div>
        <p className="text-xs text-muted-foreground">Answered</p>
        {answered.length === 0 ? (
          <p className="mt-1 text-sm text-muted-foreground">No replies went out today.</p>
        ) : (
          <ul className="mt-2 space-y-3">
            {answered.map((row) => (
              <li key={`${row.author}-${row.ours}`}>
                <p className="text-sm">{row.author}</p>
                {row.theirs && <p className="text-xs text-muted-foreground">{row.theirs}</p>}
                <p className="mt-1 text-sm">{row.ours}</p>
              </li>
            ))}
          </ul>
        )}
      </div>
      {skipped.length > 0 && (
        <div>
          <p className="text-xs text-muted-foreground">Left alone</p>
          <ul className="mt-2 space-y-3">
            {skipped.map((row) => (
              <li key={`${row.author}-${row.theirs}`}>
                <p className="text-sm">{row.author}</p>
                {row.theirs && <p className="text-sm">{row.theirs}</p>}
                <p className="text-xs text-muted-foreground">{row.reason}</p>
              </li>
            ))}
          </ul>
        </div>
      )}
      <Link href={href}>
        <Button variant="secondary" size="sm">Open replies</Button>
      </Link>
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

function XDeskView({
  desk,
  chosen,
  goals,
  onAccount,
}: {
  desk: Desk;
  chosen: Platform[];
  goals: string;
  onAccount: (id: number) => void;
}) {
  const voices = desk.voices ?? [];
  const selected = voices.find((voice) => voice.selected);
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-medium">X</h1>
          <p className="mt-1 max-w-xl text-sm text-muted-foreground">{postingLine(desk)}</p>
        </div>
        <AddPlatforms chosen={chosen} />
      </div>

      {voices.length > 1 && (
        <div className="flex flex-wrap gap-2">
          {voices.map((voice) => (
            <button
              key={voice.id}
              type="button"
              onClick={() => onAccount(voice.id)}
              className={
                voice.selected
                  ? "rounded-full bg-foreground px-3 py-1 text-xs text-background"
                  : "rounded-full border border-border px-3 py-1 text-xs text-muted-foreground hover:text-foreground"
              }
            >
              @{voice.username || "account"}
            </button>
          ))}
        </div>
      )}

      <div className="desk-arrive grid gap-4 sm:grid-cols-3">
        <Card className="h-full">
          <CardContent className="pt-6">
            <p className="text-xs text-muted-foreground">Posting</p>
            <p className="mt-1 text-sm font-medium">{paceLine(desk)}</p>
            <p className="mt-1 text-sm text-muted-foreground">{postingLine(desk)}</p>
            {desk.pace && (
              <p className="mt-1 text-xs text-muted-foreground">
                The cap changes by day: {desk.pace.low}, {desk.pace.low + 1}, or {desk.pace.high}. It is not a schedule.
              </p>
            )}
          </CardContent>
        </Card>
        <Card className="h-full">
          <CardContent className="pt-6">
            <p className="text-xs text-muted-foreground">Goals</p>
            <p className="mt-1 text-sm">{goals}</p>
            {desk.goal_progress && (
              <div className="mt-3 border-t border-border pt-3">
                <p className="text-xs text-muted-foreground">{desk.goal_progress.label}</p>
                <p className="mt-1 text-sm font-medium tabular-nums">
                  {desk.goal_progress.value == null ? "—" : desk.goal_progress.value.toLocaleString()}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">{desk.goal_progress.sub}</p>
                <p className="mt-2 text-xs text-muted-foreground">{desk.goal_progress.lesson}</p>
              </div>
            )}
            <Link href="/onboarding?platform=x" className="mt-1 inline-block text-xs text-muted-foreground hover:text-foreground">
              Change
            </Link>
          </CardContent>
        </Card>
        <Card className="h-full">
          <CardContent className="pt-6">
            <p className="text-xs text-muted-foreground">Account</p>
            {desk.connected ? (
              <>
                <p className="mt-1 text-sm">{desk.connected.username ? `@${desk.connected.username}` : "Connected"}</p>
                {selected?.niche && (
                  <p className="mt-1 text-xs text-muted-foreground">{selected.niche}</p>
                )}
                {desk.accounts && desk.accounts.included > 1 && (
                  <p className="mt-1 text-xs text-muted-foreground">
                    {desk.accounts.connected} of {desk.accounts.included} accounts on this plan
                  </p>
                )}
              </>
            ) : (
              <Link href="/settings" className="mt-1 inline-block text-sm underline underline-offset-4">
                Connect X
              </Link>
            )}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardContent className="pt-6">
          <p className="text-xs text-muted-foreground">Today</p>
          <p className="mt-1 text-sm font-medium">{desk.judgment?.line ?? "It has not looked yet today."}</p>
          <p className="mt-1 text-sm text-muted-foreground">
            {desk.judgment?.reason ?? "When it looks, this line says why it posted or why it stayed quiet."}
          </p>
          {selected?.uses_main_voice && (
            <p className="mt-2 text-xs text-muted-foreground">
              This account is still using the main voice. Set its own under Settings.
            </p>
          )}
        </CardContent>
      </Card>

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
                  {metric.value == null ? "—" : `${metric.value.toLocaleString()}${metric.suffix ?? ""}`}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">{metric.sub}</p>
              </CardContent>
            </Card>
          </Link>
        ))}
      </div>

      <Card>
        <CardContent className="space-y-4 pt-6">
          <p className="text-xs text-muted-foreground">What reached the profile</p>
          {desk.earned?.profile ? (
            <div>
              <p className="text-sm font-medium">This post sent people to the profile</p>
              <p className="mt-1 text-sm">{desk.earned.profile.content}</p>
              <p className="mt-1 text-xs text-muted-foreground">
                {desk.earned.profile.visits.toLocaleString()} profile {desk.earned.profile.visits === 1 ? "visit" : "visits"}
              </p>
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">
              The latest read did not show a post that sent people to the profile.
            </p>
          )}
          {desk.earned?.replies && (
            <div>
              <p className="text-sm font-medium">This post earned replies</p>
              <p className="mt-1 text-sm">{desk.earned.replies.content}</p>
              <p className="mt-1 text-xs text-muted-foreground">
                {desk.earned.replies.replies.toLocaleString()} {desk.earned.replies.replies === 1 ? "reply" : "replies"}
              </p>
            </div>
          )}
        </CardContent>
      </Card>

      <XBody desk={desk} />
    </div>
  );
}

function YouTubeBody({ desk, onChange }: { desk: Desk; onChange: () => Promise<void> }) {
  const [note, setNote] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [transcript, setTranscript] = useState("");
  const [retention, setRetention] = useState("");
  const [comments, setComments] = useState("");
  const [listings, setListings] = useState("");
  const [busy, setBusy] = useState("");
  const [message, setMessage] = useState("");
  const mode = desk.setup.settings.video_mode;
  const pipeline = desk.pipeline ?? [];
  const front = pipeline[0];

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

  async function saveListings(event: FormEvent) {
    event.preventDefault();
    const videos = listings
      .split(/\n/)
      .map((line) => line.split("|").map((part) => part.trim()))
      .filter((parts) => parts.length >= 3 && parts[0] && parts[1] && parts[2] !== "")
      .map(([channel, title, views]) => ({ channel, title, views: Number(views) }));
    if (videos.length === 0 || videos.some((video) => !Number.isFinite(video.views))) {
      setMessage("Each line needs a channel, a title, and a view count, separated by |.");
      return;
    }
    setBusy("listings");
    setMessage("");
    try {
      await api("/youtube/listings", { method: "POST", body: JSON.stringify({ videos }) });
      setListings("");
      await onChange();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Could not read those listings.");
    } finally {
      setBusy("");
    }
  }

  async function send(id: number, path: string, body: Record<string, string>, busyKey: string, saved: string) {
    setBusy(busyKey);
    setMessage("");
    try {
      await api(`/youtube/videos/${id}/${path}`, { method: "POST", body: JSON.stringify(body) });
      setMessage(saved);
      if (path === "transcript") setTranscript("");
      if (path === "retention") setRetention("");
      if (path === "comments") setComments("");
      await onChange();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Could not save that.");
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
            <CardDescription>The edit stays off this page until a transcript of the footage is added.</CardDescription>
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

      <Section title="What is working in the niche" description={desk.niche?.note || "Videos that beat their own channel's median."}>
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

      <Section title="This week" description="Three pieces at most, from the videos this channel can actually make and from questions the comments repeat.">
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
            {(desk.audit.working.length ? desk.audit.working : ["Nothing recorded yet that should be protected."]).map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
          <p className="mt-3 text-sm">{desk.audit.not_yet}</p>
        </Section>
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

      {front ? (
        <Section title="The piece in front" description={front.package?.formula_why || "Scored title, two hooks, the script, and the description."}>
          <div className="space-y-5">
            <div className="flex flex-wrap items-center gap-2">
              <p className="font-medium">{front.title}</p>
              <Badge variant="outline" className="font-normal">{front.status_label}</Badge>
            </div>
            {front.package?.titles && front.package.titles.length > 0 && (
              <div className="space-y-2">
                {front.package.titles.slice(0, 2).map((title) => (
                  <div key={title.title}>
                    <p className="text-sm">{title.title}</p>
                    <p className="text-xs text-muted-foreground">
                      {title.score}/100 · {title.chars} characters
                      {title.issues[0] ? ` · ${title.issues.map((issue) => issue[1]).join(" ")}` : ""}
                    </p>
                  </div>
                ))}
              </div>
            )}
            {front.package?.thumbnail_brief && (
              <div className="text-sm text-muted-foreground">
                <p>Thumbnail: {front.package.thumbnail_brief.words}</p>
                <p>{front.package.thumbnail_brief.expression}</p>
                <p>{front.package.thumbnail_brief.framing}</p>
                <p>{front.package.thumbnail_brief.background}</p>
              </div>
            )}
            {front.package?.hooks && front.package.hooks.length > 0 && (
              <div className="space-y-2">
                {front.package.hooks.map((hook) => (
                  <div key={hook.text}>
                    <p className="text-sm">{hook.text}</p>
                    <p className="text-xs text-muted-foreground">{hook.score}/100 · {hook.formula} · {hook.band}</p>
                  </div>
                ))}
              </div>
            )}
            {front.package?.runtime_seconds != null && (
              <p className="text-xs text-muted-foreground">
                {front.package.runtime_seconds >= 90
                  ? `About ${Math.max(1, Math.round(front.package.runtime_seconds / 60))} minutes at 150 words a minute.`
                  : `About ${front.package.runtime_seconds} seconds at 150 words a minute.`}
              </p>
            )}
            {front.script && (
              <p className="max-h-72 overflow-y-auto whitespace-pre-wrap text-sm text-muted-foreground">{front.script}</p>
            )}
            {front.description && (
              <p className="max-h-64 overflow-y-auto whitespace-pre-wrap text-sm text-muted-foreground">{front.description}</p>
            )}
            {front.package?.chapters && front.package.chapters.length > 0 && (
              <ul className="space-y-1 text-sm">
                {front.package.chapters.map((chapter) => (
                  <li key={chapter.label}>{chapter.label} {chapter.title}</li>
                ))}
              </ul>
            )}
            {front.package?.search_facing === false && (
              <p className="text-sm text-muted-foreground">This one is for the people who already watch. The work is in the title and the thumbnail.</p>
            )}
            {front.package?.has_transcript && front.package.shorts && front.package.shorts.length > 0 && (
              <div className="space-y-3">
                <p className="text-sm font-medium">Shorts cut from this video</p>
                {front.package.shorts.map((clip) => (
                  <div key={`${clip.start}-${clip.first_line}`} className="text-sm text-muted-foreground">
                    <p>{clip.start}–{clip.end} · {clip.first_line}</p>
                    <p>New first line ({clip.opening.score}): {clip.opening.text}</p>
                    <p>On screen: {clip.on_screen}</p>
                    <p>{clip.loop}</p>
                    <p>{clip.crop}</p>
                  </div>
                ))}
              </div>
            )}
            {front.package?.has_transcript && front.package.edit && (
              <div className="space-y-1 text-sm text-muted-foreground">
                <p>{front.package.edit.caption_style}</p>
                <p>{front.package.edit.caption_note}</p>
                {front.package.edit.zooms?.map((zoom) => (
                  <p key={zoom.reason}>Zoom to {zoom.scale}× · {zoom.reason}</p>
                ))}
              </div>
            )}
            {front.package?.comments && (
              <div className="space-y-2 text-sm">
                <p>
                  {front.package.comments.counts.questions} questions, {front.package.comments.counts.corrections} corrections, {front.package.comments.counts.praise} praise, {front.package.comments.counts.bait} bait.
                </p>
                {front.package.comments.pin && <p>Pin: {front.package.comments.pin.comment} {front.package.comments.pin.why}</p>}
                {front.package.comments.replies.map((reply) => (
                  <p key={reply.comment} className="text-muted-foreground">{reply.reply}</p>
                ))}
              </div>
            )}
            {front.package?.gate && <p className="text-sm font-medium">{front.package.gate}</p>}
            {front.status === "waiting" && (
              <Button size="sm" variant="secondary" disabled={busy === `confirm-${front.id}`} onClick={() => confirm(front.id)}>
                {busy === `confirm-${front.id}` ? "Shipping…" : "Ship it"}
              </Button>
            )}
            <form className="space-y-2" onSubmit={(event) => { event.preventDefault(); void send(front.id, "transcript", { transcript }, "transcript", "Shorts are ready."); }}>
              <label className="block text-sm">
                Transcript of the finished video
                <Textarea className="mt-1" rows={3} value={transcript} onChange={(event) => setTranscript(event.target.value)} />
              </label>
              <Button type="submit" size="sm" variant="outline" disabled={busy === "transcript" || transcript.trim() === ""}>Add the transcript</Button>
            </form>
            <form className="space-y-2" onSubmit={(event) => { event.preventDefault(); void send(front.id, "retention", { csv: retention }, "retention", "Saved for the next video."); }}>
              <label className="block text-sm">
                Audience retention export from YouTube Studio
                <Textarea className="mt-1" rows={3} value={retention} onChange={(event) => setRetention(event.target.value)} />
              </label>
              <Button type="submit" size="sm" variant="outline" disabled={busy === "retention" || retention.trim() === ""}>Save the export</Button>
            </form>
            <form className="space-y-2" onSubmit={(event) => { event.preventDefault(); void send(front.id, "comments", { comments }, "comments", "Replies are ready."); }}>
              <label className="block text-sm">
                Comments, one per line
                <Textarea className="mt-1" rows={3} value={comments} onChange={(event) => setComments(event.target.value)} />
              </label>
              <Button type="submit" size="sm" variant="outline" disabled={busy === "comments" || comments.trim() === ""}>Sort and reply</Button>
            </form>
          </div>
        </Section>
      ) : (
        <p className="text-sm text-muted-foreground">
          {mode === "footage"
            ? "Upload footage, then add its transcript. The edit and any Shorts show after that."
            : "The piece in front is written when this week's list has something the channel can make."}
        </p>
      )}
      {message && <p className="text-sm text-muted-foreground">{message}</p>}
    </div>
  );
}

export function PlatformDesk({ platform }: { platform: Platform }) {
  const router = useRouter();
  const accountId = useRef<number | null>(null);
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
      const query = accountId.current ? `?account=${accountId.current}` : "";
      setDesk(await api<Desk>(`/dashboard/platform/${platform}${query}`, undefined, 20000, "The dashboard is taking too long. Refresh in a moment."));
    } catch (err) {
      if (!getToken()) {
        router.replace("/login");
        return;
      }
      setError(err instanceof Error ? err.message : "Could not load this dashboard.");
    }
  }, [platform, router]);

  useEffect(() => {
    accountId.current = null;
    setDesk(null);
    setError("");
    void load();
  }, [load]);

  function chooseAccount(id: number) {
    accountId.current = id;
    void load();
  }

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
    return <XDeskView desk={desk} chosen={chosen} goals={goals} onAccount={chooseAccount} />;
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

      {platform === "tiktok" && desk.preview && (
        <p className="text-sm text-muted-foreground">These numbers are a preview until your TikTok is connected.</p>
      )}

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
                {metric.value == null ? "—" : `${metric.value.toLocaleString()}${metric.suffix ?? ""}`}
              </p>
              <p className="mt-1 text-xs text-muted-foreground">{metric.sub}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      {platform === "tiktok" && desk.tiktok && <TikTokBody tiktok={desk.tiktok} onReload={load} />}
      {platform === "youtube" && <YouTubeBody desk={desk} onChange={load} />}
    </div>
  );
}
