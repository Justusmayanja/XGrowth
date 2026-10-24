"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { BarChart3, Heart, List, MessageCircle, PenLine, Quote, Repeat2, Search } from "lucide-react";
import {
  Avatar,
  RailPanel,
  TimelineHeader,
  avatarColor,
  compactCount,
  minutesLabel,
  relativeTime,
} from "@/components/x/timeline";
import { contentFormatLabel } from "@/lib/content-formats";
import { api } from "@/lib/api";
import { PLATFORM_ORDER, platformName, type Platform } from "@/lib/profile";

type Metric = { label: string; value: number; sub: string; suffix?: string };

export type XDesk = {
  chosen: boolean;
  setup: {
    completed: boolean;
    goals: string[];
    settings: { automation_level?: number; mode?: string; publish?: string };
  };
  connected: { username?: string | null } | null;
  metrics: Metric[];
  counts: { drafts: number; posted: number };
  drafts: { id: number; content: string; content_type: string; created_at: string }[];
  ideas: { name: string; suggestion?: string }[];
};

type LivePost = {
  id: string;
  author: string;
  content: string;
  likes: number;
  reposts: number;
  replies: number;
  minutes_ago: number;
  kind?: "mention" | "niche";
};

const TOOLS = [
  { format: "tweet", label: "Write a post", icon: PenLine },
  { format: "thread", label: "Thread", icon: List },
  { format: "poll", label: "Poll", icon: BarChart3 },
  { format: "quote", label: "Quote", icon: Quote },
] as const;

function composeHref(format = "tweet", topic?: string) {
  const params = new URLSearchParams({ platform: "x", format });
  if (topic) params.set("topic", topic);
  return `/content-studio?${params.toString()}`;
}

function modeHeadline(mode?: string) {
  return mode === "proactive" ? "Even on a quiet day" : "When something is happening";
}

function handleOf(username?: string | null) {
  return username ? `@${username.replace(/^@/, "")}` : "";
}

function displayName(username?: string | null) {
  if (!username) return "You";
  const bare = username.replace(/^@/, "");
  return bare.charAt(0).toUpperCase() + bare.slice(1);
}

export function XHome({
  desk,
  chosen,
  error,
  posting,
  goals,
}: {
  desk: XDesk | null;
  chosen: Platform[];
  error: string;
  posting: string;
  goals: string;
}) {
  const router = useRouter();
  const [tab, setTab] = useState<"foryou" | "drafts">("foryou");
  const [composer, setComposer] = useState("");
  const [posts, setPosts] = useState<LivePost[]>([]);
  const [feedNote, setFeedNote] = useState("");
  const [feedReady, setFeedReady] = useState(false);

  const ready = Boolean(desk && !error && desk.chosen && desk.setup.completed);
  const username = desk?.connected?.username;
  const name = displayName(username);
  const handle = handleOf(username);

  useEffect(() => {
    if (!ready) return;
    let cancel = false;
    api<{ posts: LivePost[]; note?: string }>("/engagement/feed")
      .then((res) => {
        if (cancel) return;
        setPosts(res.posts ?? []);
        setFeedNote(res.note ?? "");
      })
      .catch(() => {
        if (!cancel) setFeedNote("");
      })
      .finally(() => {
        if (!cancel) setFeedReady(true);
      });
    return () => {
      cancel = true;
    };
  }, [ready]);

  function openCompose(format = "tweet") {
    const topic = composer.trim();
    router.push(composeHref(format, topic || undefined));
  }

  const missing = PLATFORM_ORDER.filter((platform) => platform !== "x" && !chosen.includes(platform));

  return (
    <div className="flex w-full flex-col items-start xl:flex-row">
        <section className="w-full min-w-0 border-[#2f3336] xl:min-h-screen xl:w-[600px] xl:shrink-0 xl:border-r">
          <TimelineHeader title="Home" pinned>
            {ready && (
              <div role="tablist" aria-label="Home" className="relative grid grid-cols-2">
                {(
                  [
                    ["foryou", "For you"],
                    ["drafts", "Drafts"],
                  ] as const
                ).map(([id, label]) => (
                  <button
                    key={id}
                    type="button"
                    role="tab"
                    aria-selected={tab === id}
                    onClick={() => setTab(id)}
                    className="h-[53px] text-[15px] hover:bg-white/[0.08] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[#1d9bf0]"
                  >
                    <span className={tab === id ? "font-bold text-[#e7e9ea]" : "text-[#71767b]"}>{label}</span>
                  </button>
                ))}
                <span
                  aria-hidden
                  className="pointer-events-none absolute bottom-0 h-1 w-14 -translate-x-1/2 rounded-full bg-[#1d9bf0] motion-safe:transition-[left] motion-safe:duration-200"
                  style={{ left: tab === "foryou" ? "25%" : "75%" }}
                />
              </div>
            )}
          </TimelineHeader>

          {error && <p className="px-4 py-8 text-[15px] text-[#71767b]">{error}</p>}

          {!desk && !error && <TimelineSkeleton />}

          {desk && !error && !ready && (
            <div className="px-8 py-16">
              <h2 className="text-[28px] font-extrabold leading-tight text-[#e7e9ea]">
                {desk.chosen ? "Finish setting up X" : "Add X"}
              </h2>
              <p className="mt-2 max-w-sm text-[15px] leading-5 text-[#71767b]">
                About a minute. Your niche and voice carry over. X keeps the goals you set for it.
              </p>
              <Link
                href="/onboarding?platform=x"
                className="mt-6 inline-flex rounded-full bg-[#1d9bf0] px-5 py-2.5 text-[15px] font-bold text-white hover:bg-[#1a8cd8]"
              >
                {desk.chosen ? "Finish X" : "Add X"}
              </Link>
            </div>
          )}

          {ready && desk && (
            <>
              <div className="flex gap-3 border-b border-[#2f3336] px-4 py-3">
                <Avatar name={name} />
                <div className="min-w-0 flex-1">
                  <textarea
                    value={composer}
                    onChange={(event) => setComposer(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key === "Enter" && (event.metaKey || event.ctrlKey)) {
                        event.preventDefault();
                        openCompose();
                      }
                    }}
                    rows={composer ? 3 : 1}
                    placeholder="What's happening?"
                    aria-label="Start a post"
                    className="x-composer"
                  />
                  <div className="mt-2 flex items-center justify-between">
                    <div className="-ml-2 flex">
                      {TOOLS.map((tool) => {
                        const Icon = tool.icon;
                        return (
                          <button
                            key={tool.format}
                            type="button"
                            aria-label={tool.label}
                            title={tool.label}
                            onClick={() => openCompose(tool.format)}
                            className="rounded-full p-2 text-[#1d9bf0] hover:bg-[#1d9bf0]/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#1d9bf0]"
                          >
                            <Icon className="size-[18px]" strokeWidth={1.8} />
                          </button>
                        );
                      })}
                    </div>
                    <button
                      type="button"
                      onClick={() => openCompose()}
                      className="rounded-full bg-[#1d9bf0] px-4 py-1.5 text-[15px] font-bold text-white hover:bg-[#1a8cd8] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1d9bf0]"
                    >
                      Post
                    </button>
                  </div>
                </div>
              </div>

              <p className="border-b border-[#2f3336] px-4 py-2 text-[13px] leading-5 text-[#71767b] xl:hidden">
                <span className="font-bold text-[#e7e9ea]">{modeHeadline(desk.setup.settings.mode)}</span>
                {" · "}
                {posting}
              </p>

              {tab === "foryou" && desk.counts.drafts > 0 && (
                <button
                  type="button"
                  onClick={() => setTab("drafts")}
                  className="flex w-full items-center justify-center border-b border-[#2f3336] py-3 text-[15px] text-[#1d9bf0] hover:bg-white/[0.03]"
                >
                  {desk.counts.drafts === 1 ? "1 draft ready" : `${desk.counts.drafts} drafts ready`}
                </button>
              )}

              {tab === "foryou" && (
                <ForYou
                  desk={desk}
                  posts={posts}
                  name={name}
                  handle={handle}
                  note={feedNote}
                  waiting={!feedReady && desk.ideas.length === 0}
                />
              )}

              {tab === "drafts" && <DraftFeed desk={desk} name={name} handle={handle} />}
            </>
          )}
        </section>

        {ready && desk && (
          <aside className="w-full border-t border-[#2f3336] px-4 py-4 xl:sticky xl:top-0 xl:max-h-screen xl:w-[350px] xl:shrink-0 xl:overflow-y-auto xl:border-t-0 xl:px-6 xl:py-3">
            <Link
              href="/trends?platform=x"
              className="mb-4 flex h-11 items-center gap-3 rounded-full bg-[#202327] px-4 text-[15px] text-[#71767b] hover:bg-[#2a2d31] hover:text-[#e7e9ea] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#1d9bf0]"
            >
              <Search className="size-4" strokeWidth={2} />
              Search topics
            </Link>

            <RailPanel title="What's happening">
              {desk.ideas.length === 0 ? (
                <p className="px-4 pb-4 text-[15px] leading-5 text-[#71767b]">
                  Topics in your niche collect here as they come in.
                </p>
              ) : (
                desk.ideas.slice(0, 5).map((idea) => (
                  <Link
                    key={idea.name}
                    href={composeHref("tweet", idea.name)}
                    className="block px-4 py-3 hover:bg-white/[0.03]"
                  >
                    <p className="text-[13px] text-[#71767b]">In your niche</p>
                    <p className="mt-0.5 text-[15px] font-bold leading-5 text-[#e7e9ea]">{idea.name}</p>
                    {idea.suggestion && (
                      <p className="mt-0.5 line-clamp-2 text-[13px] leading-5 text-[#71767b]">{idea.suggestion}</p>
                    )}
                  </Link>
                ))
              )}
              <Link href="/trends?platform=x" className="block px-4 py-3 text-[15px] text-[#1d9bf0] hover:bg-white/[0.03]">
                Show more
              </Link>
            </RailPanel>

            <RailPanel title={handle || name}>
              <div className="px-4 pb-4">
                <p className="text-[15px] font-bold text-[#e7e9ea]">{modeHeadline(desk.setup.settings.mode)}</p>
                <p className="mt-1 text-[15px] leading-5 text-[#e7e9ea]">{posting}</p>
                <p className="mt-2 text-[13px] leading-5 text-[#71767b]">
                  {goals === "Not set" ? "No goals set yet." : goals}
                </p>
                <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[14px]">
                  <Link href="/drafts?platform=x" className="hover:underline">
                    <span className="font-bold text-[#e7e9ea]">{desk.counts.drafts}</span>{" "}
                    <span className="text-[#71767b]">Drafts</span>
                  </Link>
                  <Link href="/analytics?platform=x" className="hover:underline">
                    <span className="font-bold text-[#e7e9ea]">{desk.counts.posted}</span>{" "}
                    <span className="text-[#71767b]">Posted</span>
                  </Link>
                </div>
                {desk.connected ? (
                  <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1">
                    {desk.metrics.map((metric) => (
                      <Link key={metric.label} href="/analytics?platform=x" className="hover:underline" title={metric.sub}>
                        <span className="font-bold text-[#e7e9ea]">
                          {compactCount(metric.value)}
                          {metric.suffix ?? ""}
                        </span>{" "}
                        <span className="text-[14px] text-[#71767b]">{metric.label}</span>
                      </Link>
                    ))}
                  </div>
                ) : (
                  <Link
                    href="/settings?platform=x"
                    className="mt-4 inline-flex rounded-full bg-[#eff3f4] px-4 py-1.5 text-sm font-bold text-black hover:bg-white"
                  >
                    Connect
                  </Link>
                )}
                <Link href="/onboarding?platform=x" className="mt-3 block text-[13px] text-[#1d9bf0] hover:underline">
                  Change this pace
                </Link>
              </div>
            </RailPanel>

            {missing.length > 0 && (
              <p className="px-4 text-[13px] leading-5 text-[#71767b]">
                Also on Mackie17:{" "}
                {missing.map((platform, index) => (
                  <span key={platform}>
                    {index > 0 && " · "}
                    <Link href={`/onboarding?platform=${platform}`} className="text-[#1d9bf0] hover:underline">
                      {platformName(platform)}
                    </Link>
                  </span>
                ))}
              </p>
            )}
          </aside>
        )}
      </div>
  );
}

function ForYou({
  desk,
  posts,
  name,
  handle,
  note,
  waiting,
}: {
  desk: XDesk;
  posts: LivePost[];
  name: string;
  handle: string;
  note: string;
  waiting: boolean;
}) {
  if (waiting) return <TimelineSkeleton />;

  if (posts.length === 0 && desk.ideas.length === 0) {
    return (
      <div className="px-8 py-12">
        <h2 className="text-[28px] font-extrabold leading-tight text-[#e7e9ea]">Your timeline is quiet</h2>
        <p className="mt-2 max-w-sm text-[15px] leading-5 text-[#71767b]">
          {note || "Moments in your niche show up here. Write the first post above."}
        </p>
      </div>
    );
  }

  return (
    <div>
      {posts.map((post) => (
        <LiveRow key={post.id} post={post} />
      ))}
      {posts.length > 0 && desk.ideas.length > 0 && (
        <p className="border-b border-[#2f3336] px-4 py-3 text-[13px] font-bold text-[#71767b]">Moments you could post</p>
      )}
      {desk.ideas.map((idea) => (
        <article key={idea.name} className="border-b border-[#2f3336] px-4 py-3 transition-colors hover:bg-white/[0.03]">
          <div className="flex gap-3">
            <Avatar name={name} />
            <div className="min-w-0 flex-1">
              <Author name={name} handle={handle} />
              <p className="mt-1 whitespace-pre-wrap break-words text-[15px] leading-5 text-[#e7e9ea]">{idea.name}</p>
              {idea.suggestion && (
                <p className="mt-1 whitespace-pre-wrap break-words text-[15px] leading-5 text-[#e7e9ea]">{idea.suggestion}</p>
              )}
              <div className="mt-1 flex">
                <Link
                  href={composeHref("tweet", idea.name)}
                  aria-label={`Write a post about ${idea.name}`}
                  className="inline-flex items-center gap-1.5 rounded-full p-2 text-[#71767b] hover:bg-[#1d9bf0]/10 hover:text-[#1d9bf0]"
                >
                  <PenLine className="size-[18px]" strokeWidth={1.8} />
                  <span className="text-[13px]">Write</span>
                </Link>
              </div>
            </div>
          </div>
        </article>
      ))}
    </div>
  );
}

function DraftFeed({ desk, name, handle }: { desk: XDesk; name: string; handle: string }) {
  if (desk.drafts.length === 0) {
    return (
      <div className="px-8 py-12">
        <h2 className="text-[28px] font-extrabold leading-tight text-[#e7e9ea]">No drafts yet</h2>
        <p className="mt-2 max-w-sm text-[15px] leading-5 text-[#71767b]">
          Posts you write, or that are waiting on a moment, show up here.
        </p>
        <Link
          href={composeHref()}
          className="mt-5 inline-flex rounded-full bg-[#1d9bf0] px-5 py-2.5 text-[15px] font-bold text-white hover:bg-[#1a8cd8]"
        >
          Post
        </Link>
      </div>
    );
  }

  return (
    <div>
      {desk.drafts.map((draft) => (
        <article key={draft.id} className="border-b border-[#2f3336] px-4 py-3 transition-colors hover:bg-white/[0.03]">
          <div className="flex gap-3">
            <Avatar name={name} />
            <div className="min-w-0 flex-1">
              <Author name={name} handle={handle} time={relativeTime(draft.created_at)} meta={contentFormatLabel(draft.content_type)} />
              <Link href="/drafts?platform=x" className="mt-1 block whitespace-pre-wrap break-words text-[15px] leading-5 text-[#e7e9ea]">
                {draft.content}
              </Link>
            </div>
          </div>
        </article>
      ))}
      {desk.counts.drafts > desk.drafts.length && (
        <Link href="/drafts?platform=x" className="block px-4 py-4 text-center text-[15px] text-[#1d9bf0] hover:bg-white/[0.03]">
          Show all drafts
        </Link>
      )}
    </div>
  );
}

function LiveRow({ post }: { post: LivePost }) {
  const handle = post.author.startsWith("@") ? post.author : `@${post.author}`;
  const name = handle.slice(1);
  return (
    <article className="border-b border-[#2f3336] px-4 py-3 transition-colors hover:bg-white/[0.03]">
      {post.kind === "mention" && (
        <p className="mb-1 ml-[52px] text-[13px] font-medium text-[#71767b]">Mentioned you</p>
      )}
      <div className="flex gap-3">
        <Avatar name={name} color={avatarColor(handle)} />
        <div className="min-w-0 flex-1">
          <Author name={name} handle={handle} time={minutesLabel(post.minutes_ago)} />
          <p className="mt-1 whitespace-pre-wrap break-words text-[15px] leading-5 text-[#e7e9ea]">{post.content}</p>
          <div className="mt-2 flex max-w-md items-center justify-between text-[#71767b]">
            <Link
              href="/engagement?platform=x"
              aria-label={`${post.replies} replies`}
              className="inline-flex items-center rounded-full p-2 hover:bg-[#1d9bf0]/10 hover:text-[#1d9bf0]"
            >
              <MessageCircle className="size-[18px]" strokeWidth={1.8} />
              {post.replies > 0 && <span className="min-w-[1ch] px-1 text-[13px] tabular-nums">{compactCount(post.replies)}</span>}
            </Link>
            <span className="inline-flex items-center p-2" aria-label={`${post.reposts} reposts`}>
              <Repeat2 className="size-[18px]" strokeWidth={1.8} />
              {post.reposts > 0 && <span className="min-w-[1ch] px-1 text-[13px] tabular-nums">{compactCount(post.reposts)}</span>}
            </span>
            <span className="inline-flex items-center p-2" aria-label={`${post.likes} likes`}>
              <Heart className="size-[18px]" strokeWidth={1.8} />
              {post.likes > 0 && <span className="min-w-[1ch] px-1 text-[13px] tabular-nums">{compactCount(post.likes)}</span>}
            </span>
          </div>
        </div>
      </div>
    </article>
  );
}

function Author({ name, handle, time, meta }: { name: string; handle: string; time?: string; meta?: string }) {
  return (
    <div className="flex min-w-0 flex-wrap items-center gap-x-1 text-[15px] leading-5">
      <span className="truncate font-bold text-[#e7e9ea]">{name}</span>
      {handle && <span className="truncate text-[#71767b]">{handle}</span>}
      {time && (
        <>
          <span className="text-[#71767b]">·</span>
          <span className="text-[#71767b]">{time}</span>
        </>
      )}
      {meta && (
        <>
          <span className="text-[#71767b]">·</span>
          <span className="text-[#71767b]">{meta}</span>
        </>
      )}
    </div>
  );
}

function TimelineSkeleton() {
  return (
    <div className="px-4" aria-busy="true" aria-label="Loading timeline">
      {[0, 1, 2, 3].map((row) => (
        <div key={row} className="flex gap-3 border-b border-[#2f3336] py-3">
          <div className="h-10 w-10 animate-pulse rounded-full bg-[#2f3336]" />
          <div className="flex-1 space-y-2 pt-1">
            <div className="h-3 w-36 animate-pulse rounded-full bg-[#2f3336]" />
            <div className="h-3 w-full animate-pulse rounded-full bg-[#2f3336]" />
            <div className="h-3 w-4/5 animate-pulse rounded-full bg-[#2f3336]" />
          </div>
        </div>
      ))}
    </div>
  );
}
