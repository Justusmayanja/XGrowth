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
    name: "Waiting, pace is full",
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
    name: "Uploading",
    tiktok: desk({
      clip: clip({
        status: "uploading",
        status_label: "Sending to TikTok",
        blockers: [],
        video: { filename: "salt.mp4", size: 18_000_000, duration_seconds: 24, width: 1080, height: 1920, warning: null },
        upload_progress: 40,
      }),
      connection: { connected: true, display_name: "Kitchen", configured: true, upload_configured: true },
      pipeline: { ideas: 6, written: 2, waiting: 0, confirmed: 1, posted: 0 },
    }),
  },
  {
    name: "In TikTok drafts",
    tiktok: desk({
      clip: clip({
        status: "in_drafts",
        status_label: "In your TikTok drafts",
        blockers: [],
        video: { filename: "salt.mp4", size: 18_000_000, duration_seconds: 24, width: 1080, height: 1920, warning: null },
      }),
      connection: { connected: true, display_name: "Kitchen", configured: true, upload_configured: true },
      pipeline: { ideas: 6, written: 2, waiting: 0, confirmed: 1, posted: 0 },
    }),
  },
  {
    name: "Upload failed",
    tiktok: desk({
      clip: clip({
        status: "upload_failed",
        status_label: "Couldn't send",
        blockers: [],
        upload_error: "The send stopped. You can try again.",
        video: { filename: "salt.mp4", size: 18_000_000, duration_seconds: 24, width: 1080, height: 1920, warning: null },
      }),
      connection: { connected: true, display_name: "Kitchen", configured: true, upload_configured: true },
    }),
  },
  {
    name: "Needs upload permission",
    tiktok: desk({
      clip: clip({
        status: "queued",
        status_label: "Confirmed, not posted yet",
        blockers: ["tiktok_upload_permission"],
        video: { filename: "salt.mp4", size: 8_000_000, duration_seconds: 18, width: 1080, height: 1080, warning: "This video is not 9:16. TikTok can still take it. A vertical video fits the feed better." },
      }),
      connection: { connected: true, display_name: "Kitchen", configured: true, upload_configured: true, needs_upload_permission: true },
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
  {
    name: "Video rendering",
    tiktok: desk({
      clip: clip({
        render: {
          status: "rendering",
          label: "Making your video",
          note: null,
          ready: false,
          provider: "pending",
          ai_generated: false,
          can_regenerate: false,
        },
      }),
    }),
  },
  {
    name: "Video ready",
    tiktok: desk({
      clip: clip({
        blockers: ["tiktok_connection"],
        render: {
          status: "ready",
          label: "Ready to watch",
          note: null,
          ready: true,
          provider: "ffmpeg",
          ai_generated: true,
          can_regenerate: true,
        },
        video: { filename: "clip.mp4", size: 4_000_000, duration_seconds: 22, width: 1080, height: 1920, warning: null },
      }),
    }),
  },
  {
    name: "Video skipped",
    tiktok: desk({
      clip: clip({
        render: {
          status: "skipped",
          label: "Not made",
          note: "Voice isn't available right now. Try again later, or add your own video.",
          ready: false,
          provider: "pending",
          ai_generated: false,
          can_regenerate: false,
        },
      }),
    }),
  },
  {
    name: "Video rendering, long",
    tiktok: desk({
      clip: clip({
        render: {
          status: "rendering",
          label: "Making your video",
          note: null,
          ready: false,
          provider: "pending",
          ai_generated: false,
          can_regenerate: false,
        },
      }),
    }),
    review: { elapsedSeconds: 45 },
  },
  {
    name: "Video rendering, stalled",
    tiktok: desk({
      clip: clip({
        render: {
          status: "rendering",
          label: "Making your video",
          note: null,
          ready: false,
          provider: "pending",
          ai_generated: false,
          can_regenerate: false,
        },
      }),
    }),
    review: { stalled: true, elapsedSeconds: 600 },
  },
  {
    name: "Video ready, safe area",
    tiktok: desk({
      clip: clip({
        blockers: ["tiktok_connection"],
        script_lines: [
          "You are salting the water too late.",
          "The water should taste like the sea first.",
          "Then the pasta goes in.",
          "Taste it again before you plate.",
          "Then stop and plate the bowl.",
        ],
        render: {
          status: "ready",
          label: "Ready to watch",
          note: null,
          ready: true,
          provider: "ffmpeg",
          ai_generated: true,
          can_regenerate: true,
        },
        video: { filename: "clip.mp4", size: 4_194_304, duration_seconds: 22, width: 1080, height: 1920, warning: null },
      }),
    }),
    review: { safeArea: true },
  },
  {
    name: "Video failed",
    tiktok: desk({
      clip: clip({
        render: {
          status: "failed",
          label: "Couldn't make the video",
          note: "We couldn't make the video. Try again, or add your own.",
          ready: false,
          provider: "pending",
          ai_generated: false,
          can_regenerate: true,
        },
      }),
    }),
  },
  {
    name: "Regenerate limit reached",
    tiktok: desk({
      clip: clip({
        blockers: ["tiktok_connection"],
        render: {
          status: "ready",
          label: "Ready to watch",
          note: null,
          ready: true,
          provider: "ffmpeg",
          ai_generated: true,
          can_regenerate: false,
        },
        video: { filename: "clip.mp4", size: 4_000_000, duration_seconds: 22, width: 1080, height: 1920, warning: null },
      }),
    }),
  },
  {
    name: "Your video",
    tiktok: desk({
      clip: clip({
        blockers: ["tiktok_connection"],
        render: {
          status: "ready",
          label: "Ready to watch",
          note: null,
          ready: true,
          provider: "user",
          ai_generated: false,
          can_regenerate: false,
        },
        video: {
          filename: "salt.mp4",
          size: 8_000_000,
          duration_seconds: 18,
          width: 1080,
          height: 1080,
          warning: "This video is not 9:16. TikTok can still take it. A vertical video fits the feed better.",
        },
      }),
    }),
  },
  {
    name: "Upload in progress",
    tiktok: desk({
      clip: clip({ blockers: ["video_file", "tiktok_connection"] }),
    }),
    review: { upload: { progress: 42, loaded: 4_200_000, total: 10_000_000 } },
  },
  {
    name: "Upload error",
    tiktok: desk({
      clip: clip({ blockers: ["video_file", "tiktok_connection"] }),
    }),
    review: { upload: { error: "This file is over 100 MB. Export a shorter clip and try again." } },
  },
];
