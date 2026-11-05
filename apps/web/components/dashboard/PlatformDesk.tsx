"use client";

import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowUpRight, Info, Link2 } from "lucide-react";
import { PageHeader } from "@/components/layout/PageHeader";
import { LoadingMark } from "@/components/LoadingMark";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api, getToken } from "@/lib/api";
import { TikTokBody } from "@/components/dashboard/tiktok/TikTokBody";
import { YouTubeBody } from "@/components/dashboard/youtube/YouTubeBody";
import { contentFormatLabel } from "@/lib/content-formats";
import type { TikTokDesk } from "@/lib/tiktok";
import type {
  ChannelAudit,
  LearnedVideo,
  NicheResearch,
  WeekPiece,
  YouTubeDesk,
  YouTubeItem,
  YouTubeSeriesCard,
} from "@/lib/youtube";
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
  metrics_error?: string | null;
  counts: { drafts: number; posted: number };
  drafts: { id: number; content: string; content_type: string; media_path?: string | null; created_at: string }[];
  ideas: { name: string; suggestion?: string }[];
  pipeline?: YouTubeItem[];
  learned?: LearnedVideo[];
  series?: YouTubeSeriesCard | null;
  week?: WeekPiece[];
  niche?: NicheResearch | null;
  audit?: ChannelAudit | null;
  research: YouTubeDesk["research"];
  upload: YouTubeDesk["upload"];
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
  x: "Mackie17 watches your niche and posts when a moment is strong. There is no schedule.",
  tiktok: "Mackie17 finds ideas that suit the brand, writes them, makes the clip, and suggests a time. You say yes.",
  youtube: "Mackie17 ranks listings and comments you paste, writes this week's package, and keeps it local.",
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
    return "Mackie17 posts when a moment is strong. A quiet day stays quiet.";
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
  if (desk.platform === "tiktok") return "Mackie17 suggests a time, then waits for your yes";
  if (desk.platform === "youtube") {
    const mode = YOUTUBE_MODES.find((item) => item.value === desk.setup.settings.video_mode)?.label ?? "YouTube";
    const format = YOUTUBE_FORMATS.find((item) => item.value === desk.setup.settings.format)?.label;
    const publish = desk.setup.settings.publish === "auto" ? "Mackie17 queues the package locally" : "Mackie17 waits for your yes, then queues locally";
    return [mode, format, publish].filter(Boolean).join(" · ");
  }
  if (desk.setup.settings.engine_paused) return "Paused. Mackie17 posts nothing until you turn posting back on";
  const posting = desk.setup.settings.auto_post_enabled !== false;
  switch (desk.setup.settings.automation_level) {
    case 0:
      return "Mackie17 suggests only. You post";
    case 2:
      return posting ? "Mackie17 posts the strong moments" : "Mackie17 drafts the strong moments. You approve";
    case 3:
      return posting
        ? "Mackie17 posts even while you are offline"
        : "Full automation is selected, but posting is off";
    default:
      return "Mackie17 drafts. You approve";
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
            {draft.media_path ? (
              <p className="mb-1 text-xs text-muted-foreground">Picture attached</p>
            ) : null}
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

function TikTokDeskSkeleton() {
  return (
    <div className="space-y-6" aria-hidden>
      <div className="h-24 animate-pulse rounded-md bg-muted" />
      <div className="grid gap-4 sm:grid-cols-3">
        <div className="h-28 animate-pulse rounded-md bg-muted" />
        <div className="h-28 animate-pulse rounded-md bg-muted" />
        <div className="h-28 animate-pulse rounded-md bg-muted" />
      </div>
      <div className="h-16 animate-pulse rounded-md bg-muted" />
      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="h-80 animate-pulse rounded-md bg-muted" />
        <div className="h-64 animate-pulse rounded-md bg-muted" />
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
      <PageHeader title="X" description={postingLine(desk)} actions={<AddPlatforms chosen={chosen} />} />

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
                No posting clock. Mackie17 waits for a moment that can travel.
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
          <p className="mt-1 text-sm font-medium">{desk.judgment?.line ?? "Mackie17 has not looked yet today."}</p>
          <p className="mt-1 text-sm text-muted-foreground">
            {desk.judgment?.reason ?? "This line says why Mackie17 posted or stayed quiet."}
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
    <PageHeader
      title={name}
      description={platform === "x" && desk ? postingLine(desk) : LEDE[platform]}
      actions={chosen.length > 0 ? <AddPlatforms chosen={chosen} /> : undefined}
    />
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
        {platform === "x" ? <XDeskSkeleton /> : platform === "tiktok" ? <TikTokDeskSkeleton /> : <LoadingMark className="min-h-[40vh]" />}
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
        <CardContent className="grid gap-0 divide-y divide-border p-0 md:grid-cols-3 md:divide-x md:divide-y-0">
          <div className="p-5">
            <p className="text-xs text-muted-foreground">Posting</p>
            <p className="mt-1 text-sm">{postingLine(desk)}</p>
          </div>
          <div className="p-5">
            <p className="text-xs text-muted-foreground">Goals</p>
            <ul className="mt-2 flex flex-wrap gap-1.5" aria-label="Goals">
              {(desk.setup.goals.length ? desk.setup.goals : ["Not set"]).map((goal) => (
                <li key={goal} className="rounded-full border border-border px-2 py-0.5 text-xs">
                  {desk.setup.goals.length ? formatMultiLabels([goal], PLATFORM_GOALS[platform]) : "Not set"}
                </li>
              ))}
            </ul>
            <Link href={`/onboarding?platform=${platform}`}>
              <Button variant="ghost" size="sm" className="mt-2 h-8 px-2 text-xs text-muted-foreground">
                Change
              </Button>
            </Link>
          </div>
          <div className="p-5">
            <p className="text-xs text-muted-foreground">Account</p>
            <p className="mt-2 inline-flex items-center gap-1.5 rounded-full border border-border px-2 py-0.5 text-xs">
              <Link2 className="size-3.5" aria-hidden />
              {platform === "tiktok" && desk.tiktok?.connection.needs_reconnect
                ? "Needs reconnect"
                : desk.connected
                  ? desk.connected.username
                    ? `Connected as ${desk.connected.username}`
                    : "Connected"
                  : "Not connected"}
            </p>
            {platform === "youtube" && !desk.connected ? (
              <Link href="/settings" className="mt-3 inline-block">
                <Button variant="outline" size="sm">
                  Connect YouTube
                </Button>
              </Link>
            ) : (!desk.connected || (platform === "tiktok" && desk.tiktok?.connection.needs_reconnect)) && (
              <Link href="/settings" className="mt-3 inline-block">
                <Button variant="outline" size="sm">
                  Connect {name}
                </Button>
              </Link>
            )}
          </div>
        </CardContent>
      </Card>

      {platform === "tiktok" && desk.preview && (
        <p className="flex items-start gap-2 rounded-md border border-border bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
          <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
          These numbers are a preview until your TikTok is connected.
        </p>
      )}

      {platform === "youtube" && desk.preview && (
        <p className="flex items-start gap-2 rounded-md border border-border bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
          <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
          These are deterministic sample numbers, not channel statistics. YouTube is not connected.
        </p>
      )}

      {platform === "youtube" && !desk.preview && desk.metrics_error && (
        <p className="flex items-start gap-2 rounded-md border border-border bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
          <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
          {desk.metrics_error} No sample values are shown while a channel is connected.
        </p>
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
              <p className="mt-2 text-2xl font-medium tabular-nums">
                {metric.value == null ? "—" : `${metric.value.toLocaleString()}${metric.suffix ?? ""}`}
              </p>
              <p className="mt-1 text-xs text-muted-foreground">{metric.sub}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      {platform === "tiktok" && desk.tiktok && <TikTokBody tiktok={desk.tiktok} onReload={load} />}
      {platform === "youtube" && <YouTubeBody desk={desk as YouTubeDesk} onChange={load} />}
    </div>
  );
}
