import type { TikTokClip, TikTokDesk, TikTokIdea, TikTokRun } from "@/lib/tiktok";
import type { TikTokReview } from "./TikTokBody";

const idea = (kind: string, label: string, topic: string): TikTokIdea => ({
  kind,
  kind_label: label,
  topic,
  angle: "One specific step, then stop.",
  reason: "This shape fits the account you described.",
});

export function clip(overrides: Partial<TikTokClip> = {}): TikTokClip {
  return {
    id: 7,
    status: "waiting",
    status_label: "Waiting for your yes",
    topic: "Salt the pasta water",
    hook: "You are salting the water too late.",
    script_lines: [
      "You are salting the water too late.",
      "The water should taste like the sea first.",
      "Then the pasta goes in.",
    ],
    caption: "You are salting the water too late.\nTaste it before the pasta goes in.\n#budgetcooking",
    on_screen_text: ["Salt it first", "Taste the water"],
    shots: ["Pot on the stove", "Salt in the hand", "Pasta going in"],
    sound_note: "Quiet kitchen",
    suggested_for: "2026-10-27T16:30:00+00:00",
    timezone: "UTC",
    timezone_assumed: false,
    views: null,
    completion_percent: null,
    follows: null,
    learning_note: null,
    confirmed_at: null,
    queued_at: null,
    posted_at: null,
    learned_at: null,
    publish_note: null,
    platform_post_id: null,
    blockers: ["video_file", "tiktok_connection"],
    ...overrides,
  };
}

function desk(overrides: Partial<TikTokDesk> = {}): TikTokDesk {
  return {
    clip: null,
    recent: [],
    ideas: [
      idea("question", "question", "When do you salt the water?"),
      idea("tip", "tip", "One pan, one dinner"),
    ],
    pipeline: { ideas: 6, written: 2, waiting: 0, confirmed: 0, posted: 0 },
    allowance: { limit: 8, used: 1, remaining: 7, period_end: "2026-11-27T00:00:00+00:00", plan_found: true },
    daily_cap: { cap: 3, used: 0, reached: false, available_at: null },
    block: null,
    timezone: { value: "Africa/Kampala", assumed: false },
    connection: { connected: false, display_name: null, needs_reconnect: false, configured: false },
    ...overrides,
  };
}

export type TikTokStateFixture = {
  name: string;
  tiktok: TikTokDesk;
  review?: TikTokReview;
};

const nextCreated: TikTokRun = {
  outcome: "created",
  clip: clip({ id: 8 }),
  reason: null,
  message: "A clip is waiting for your yes.",
};

const nextSkipped: TikTokRun = {
  outcome: "skipped",
  clip: null,
  reason: "allowance_spent",
  message: "You have used every clip for this month.",
};

export const tiktokStateFixtures: TikTokStateFixture[] = [
  {
    name: "Setup incomplete",
    tiktok: desk({
      block: {
        reason: "setup_incomplete",
        message: "Finish the TikTok setup first.",
        action: "finish_setup",
        renews_at: null,
      },
      allowance: { limit: 0, used: 0, remaining: 0, period_end: null, plan_found: false },
      timezone: { value: "UTC", assumed: true },
    }),
  },
  {
    name: "No plan",
    tiktok: desk({
      block: {
        reason: "no_plan",
        message: "TikTok is not on this account.",
        action: "billing",
        renews_at: null,
      },
      allowance: { limit: 0, used: 0, remaining: 0, period_end: null, plan_found: false },
    }),
  },
  {
    name: "Allowance spent",
    tiktok: desk({
      block: {
        reason: "allowance_spent",
        message: "You have used every clip for this month.",
        action: "wait",
        renews_at: "2026-11-27T00:00:00+00:00",
      },
      allowance: { limit: 8, used: 8, remaining: 0, period_end: "2026-11-27T00:00:00+00:00", plan_found: true },
    }),
  },
  { name: "Empty", tiktok: desk() },
  { name: "Generating", tiktok: desk(), review: { writing: true } },
  {
    name: "Waiting, timezone assumed",
    tiktok: desk({
      clip: clip({ timezone: "UTC", timezone_assumed: true }),
      pipeline: { ideas: 6, written: 1, waiting: 1, confirmed: 0, posted: 0 },
      timezone: { value: "UTC", assumed: true },
    }),
  },
  {
    name: "Waiting, suggested time passed",
    tiktok: desk({
      clip: clip({
        suggested_for: "2020-01-01T16:30:00+00:00",
        script_lines: [
          "You are salting the water too late.",
          "The water should taste like the sea first.",
          "Then the pasta goes in.",
          "Taste it once more.",
          "That is the whole clip.",
        ],
      }),
      pipeline: { ideas: 6, written: 1, waiting: 1, confirmed: 0, posted: 0 },
    }),
  },
  {
    name: "Waiting, daily cap reached",
    tiktok: desk({
      clip: clip(),
      pipeline: { ideas: 6, written: 2, waiting: 1, confirmed: 1, posted: 0 },
      daily_cap: { cap: 3, used: 3, reached: true, available_at: "2026-10-28T09:00:00+00:00" },
    }),
  },
  {
    name: "Queued, TikTok not configured",
    tiktok: desk({
      clip: clip({
        status: "queued",
        status_label: "Confirmed, not posted yet",
        confirmed_at: "2026-10-27T12:00:00+00:00",
        queued_at: "2026-10-27T12:00:00+00:00",
      }),
      connection: { connected: false, display_name: null, needs_reconnect: false, configured: false },
    }),
  },
  {
    name: "Queued, TikTok not connected",
    tiktok: desk({
      clip: clip({
        status: "queued",
        status_label: "Confirmed, not posted yet",
        confirmed_at: "2026-10-27T12:00:00+00:00",
        queued_at: "2026-10-27T12:00:00+00:00",
      }),
      connection: { connected: false, display_name: null, needs_reconnect: false, configured: true },
    }),
  },
  {
    name: "Queued, connected, no video file",
    tiktok: desk({
      clip: clip({
        status: "queued",
        status_label: "Confirmed, not posted yet",
        confirmed_at: "2026-10-27T12:00:00+00:00",
        queued_at: "2026-10-27T12:00:00+00:00",
        blockers: ["video_file"],
      }),
      connection: { connected: true, display_name: "Kitchen", needs_reconnect: false, configured: true },
    }),
  },
  {
    name: "Queued, needs reconnect",
    tiktok: desk({
      clip: clip({
        status: "queued",
        status_label: "Confirmed, not posted yet",
        confirmed_at: "2026-10-27T12:00:00+00:00",
        queued_at: "2026-10-27T12:00:00+00:00",
      }),
      connection: { connected: false, display_name: "Kitchen", needs_reconnect: true, configured: true },
    }),
  },
  {
    name: "Confirmed, both blockers",
    tiktok: desk({
      clip: clip({
        status: "queued",
        status_label: "Confirmed, not posted yet",
        confirmed_at: "2026-10-27T12:00:00+00:00",
        queued_at: "2026-10-27T12:00:00+00:00",
      }),
      pipeline: { ideas: 6, written: 2, waiting: 0, confirmed: 1, posted: 0 },
    }),
  },
  {
    name: "Posted",
    tiktok: desk({
      clip: clip({
        status: "posted",
        status_label: "Posted",
        posted_at: "2026-10-27T18:00:00+00:00",
        platform_post_id: "https://www.tiktok.com/@kitchen/video/1",
        blockers: [],
      }),
      pipeline: { ideas: 6, written: 2, waiting: 0, confirmed: 0, posted: 1 },
      connection: { connected: true },
    }),
  },
  {
    name: "Results open",
    tiktok: desk({
      clip: clip({
        status: "queued",
        status_label: "Confirmed, not posted yet",
        confirmed_at: "2026-10-27T12:00:00+00:00",
        queued_at: "2026-10-27T12:00:00+00:00",
      }),
    }),
    review: { resultsOpen: true },
  },
  {
    name: "Learned, next clip ready",
    tiktok: desk({
      recent: [clip({ id: 6, status: "learned", status_label: "Results in", learning_note: "This question clip held. Lean into this kind of idea next." })],
    }),
    review: {
      learned: {
        note: "This question clip held. 800 views, 12 follows, 62% completion. Lean into this kind of idea next.",
        next: nextCreated,
      },
    },
  },
  {
    name: "Learned, next clip skipped",
    tiktok: desk(),
    review: {
      learned: {
        note: "This tip clip left early. Try a faster hook or a different kind next.",
        next: nextSkipped,
      },
    },
  },
];
