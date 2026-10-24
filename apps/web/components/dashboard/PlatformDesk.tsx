"use client";

import { useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowUpRight } from "lucide-react";
import { LoadingMark } from "@/components/LoadingMark";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api, getToken } from "@/lib/api";
import { contentFormatLabel } from "@/lib/content-formats";
import {
  formatMultiLabels,
  PLATFORM_GOALS,
  PLATFORM_ORDER,
  platformName,
  type Platform,
} from "@/lib/profile";

type Metric = { label: string; value: number; sub: string; suffix?: string };

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
};

const LEDE: Record<Platform, string> = {
  x: "It watches your niche and posts when a moment is strong. There is no schedule.",
  tiktok: "It finds ideas that suit the brand, writes them, makes the clip, and suggests a time. You say yes.",
  youtube: "It looks for ideas likely to catch on, writes what should be said, and makes the video or edits your footage.",
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
    return desk.setup.settings.publish === "auto" ? "Posts on its own when the video is ready" : "Waits for your yes before posting";
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

function YouTubeBody({ desk }: { desk: Desk }) {
  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2">
        <Card>
          <CardContent className="flex h-full flex-col justify-between gap-4 pt-6">
            <div>
              <p className="font-medium">Let Mackie17 make it</p>
              <p className="mt-1 text-sm text-muted-foreground">
                Start from an idea. It writes what should be said, then makes the video.
              </p>
            </div>
            <Link href={composeHref("youtube")}>
              <Button size="sm">Start a video</Button>
            </Link>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="flex h-full flex-col justify-between gap-4 pt-6">
            <div>
              <p className="font-medium">Bring your own footage</p>
              <p className="mt-1 text-sm text-muted-foreground">
                It edits what you recorded and writes the title and description around it.
                {desk.connected ? "" : " Uploads open once YouTube is connected."}
              </p>
            </div>
            <Link href={desk.connected ? "/content-studio?platform=youtube&format=youtube_description" : "/settings"}>
              <Button size="sm" variant="secondary">
                {desk.connected ? "Describe the footage" : "Connect YouTube"}
              </Button>
            </Link>
          </CardContent>
        </Card>
      </div>
      <div className="grid gap-6 lg:grid-cols-2">
        <Section title="Ideas likely to catch on" description="Worth a video in your niche this week.">
          <IdeaList desk={desk} verb="Write it" />
        </Section>
        <div className="space-y-6">
          <Section title="Titles and scripts" description="Written and waiting to become videos.">
            <DraftList desk={desk} empty="Nothing written yet. Start from an idea." />
          </Section>
          <Learned desk={desk} />
        </div>
      </div>
    </div>
  );
}

export function PlatformDesk({ platform }: { platform: Platform }) {
  const router = useRouter();
  const [desk, setDesk] = useState<Desk | null>(null);
  const [chosen, setChosen] = useState<Platform[]>([]);
  const [error, setError] = useState("");

  useEffect(() => {
    setDesk(null);
    async function load() {
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
    }
    load();
  }, [platform, router]);

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
      {platform === "youtube" && <YouTubeBody desk={desk} />}
    </div>
  );
}
