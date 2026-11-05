import { api, apiForm } from "@/lib/api";
import type { Platform } from "@/lib/profile";

export type Metric = { label: string; value: number | null; sub: string; suffix?: string };
export type ScoredTitle = { title: string; chars: number; score: number; issues: [string, string][] };
export type ScoredHook = { text: string; score: number; band: string; formula: string };
export type YouTubeChapter = { label: string; title: string; seconds: number };
export type YouTubeShort = {
  start: string;
  end: string;
  first_line: string;
  opening: { text: string; score: number; formula: string };
  on_screen: string;
  loop: string;
  crop: string;
};

export type YouTubePackage = {
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
  render?: {
    ready?: boolean;
    provider?: string;
    note?: string;
    kind?: string;
    path?: string;
    bytes?: number | null;
    duration_seconds?: number | null;
  };
  upload?: {
    state?: "failed" | "held" | "queued" | "posted";
    retryable?: boolean;
    note?: string;
    privacy?: "public" | "unlisted" | "private" | null;
    youtube_video_id?: string | null;
    next_attempt_on?: string | null;
  };
  transcription?: { provider?: string; available?: boolean; note?: string | null };
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

export type YouTubeItem = {
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
  youtube_video_id: string | null;
  posted_video_id: string | null;
  posted_privacy: "public" | "unlisted" | "private" | null;
  posted_at: string | null;
  package: YouTubePackage | null;
};

export type LearnedVideo = {
  id: number;
  mode: string;
  title: string | null;
  views: number;
  average_view_percent: number | null;
  youtube_video_id: string | null;
  learned_at: string;
  lesson: "hook_leak" | "cliff" | "slide" | "packaging" | null;
};

export type NicheResearch = {
  working: { channel: string; title: string; multiple: number; formula: string; views: number; median: number }[];
  shared: string;
  makeable: { title: string; multiple: number; formula: string; idea: string; have: string; voice: string }[];
  skipped: { channel: string; count: number }[];
  note: string;
};

export type WeekPiece = { topic: string; reason: string; source?: string };
export type ChannelAudit = { fix: string; working: string[]; not_yet: string };
export type YouTubeSeriesCard = {
  title: string;
  premise: string;
  next_episode: number;
  holds_until: string | null;
  has_retention_lesson: boolean;
  characters: { name: string; role: string }[];
  outline: { number: number; title: string; beat: string }[];
};

export type YouTubeDesk = {
  platform: Platform;
  chosen: boolean;
  setup: {
    completed: boolean;
    goals: string[];
    settings: {
      publish?: string;
      video_mode?: string;
      format?: string;
    };
  };
  connected: { username?: string | null } | null;
  preview: boolean;
  metrics: Metric[];
  ideas: { name: string; suggestion?: string }[];
  pipeline: YouTubeItem[];
  learned: LearnedVideo[];
  series: YouTubeSeriesCard | null;
  week: WeekPiece[];
  niche: NicheResearch | null;
  audit: ChannelAudit | null;
  research: {
    budget: number;
    used: number;
    remaining: number;
    resets_on: string;
    cache_minutes: number;
    pulls: Partial<Record<"channel_videos" | "niche_search" | "comments" | "views" | "retention", {
      refreshed_at: string | null;
      last_attempt_at: string | null;
      cached_until: string | null;
      error: string | null;
      summary: Record<string, string | number> | null;
    }>>;
  };
  upload: {
    audit_approved: boolean;
    scope: {
      connected: boolean;
      upload_granted: boolean;
      needs_upload_consent: boolean;
    };
    allowance: {
      limit: number;
      used: number;
      remaining: number;
      period_end: string | null;
      plan_found: boolean;
    };
    daily_budget: {
      limit: number;
      used: number;
      remaining: number;
      resets_on: string;
    };
    privacy_policy: string;
  } | null;
};

export function loadYouTubeDesk(): Promise<YouTubeDesk> {
  return api<YouTubeDesk>("/dashboard/platform/youtube");
}

export function confirmYouTubeVideo(id: number): Promise<YouTubeDesk> {
  return api<YouTubeDesk>(`/youtube/videos/${id}/confirm`, { method: "POST" });
}

export function saveYouTubeListings(videos: { channel: string; title: string; views: number }[]): Promise<YouTubeDesk> {
  return api<YouTubeDesk>("/youtube/listings", { method: "POST", body: JSON.stringify({ videos }) });
}

export function refreshYouTubeResearch(path: "channel-videos" | "niche-search", body?: Record<string, string>): Promise<YouTubeDesk> {
  return api<YouTubeDesk>(`/youtube/research/${path}`, {
    method: "POST",
    body: body ? JSON.stringify(body) : undefined,
  });
}

export function refreshYouTubeVideoData(id: number, path: "comments" | "views" | "retention", youtubeVideoId: string): Promise<YouTubeDesk> {
  return api<YouTubeDesk>(`/youtube/videos/${id}/refresh-${path}`, {
    method: "POST",
    body: JSON.stringify({ youtube_video_id: youtubeVideoId }),
  });
}

export function saveYouTubeResults(id: number, views: number, averageViewPercent: number): Promise<YouTubeDesk> {
  return api<YouTubeDesk>(`/youtube/videos/${id}/results`, {
    method: "POST",
    body: JSON.stringify({ views, average_view_percent: averageViewPercent }),
  });
}

export function saveYouTubeText(id: number, path: "transcript" | "retention" | "comments", body: Record<string, string>): Promise<YouTubeDesk> {
  return api<YouTubeDesk>(`/youtube/videos/${id}/${path}`, { method: "POST", body: JSON.stringify(body) });
}

export function uploadYouTubeFootage(body: FormData): Promise<YouTubeDesk> {
  return apiForm<YouTubeDesk>("/youtube/footage", body);
}

export type YouTubeMedia = {
  preview_url: string;
  poster_url: string | null;
  download_url: string;
  expires_at: string;
  bytes: number | null;
  duration_seconds: number | null;
  note: string;
};

export function sendYouTubeVideo(id: number, privacy: "public" | "unlisted" | "private"): Promise<YouTubeDesk> {
  return api<YouTubeDesk>(`/youtube/videos/${id}/upload`, {
    method: "POST",
    body: JSON.stringify({ privacy }),
  });
}

export function renderYouTubeVideo(id: number): Promise<YouTubeDesk> {
  return api<YouTubeDesk>(
    `/youtube/videos/${id}/render`,
    { method: "POST" },
    180000,
    "The local render timed out. Nothing was uploaded.",
  );
}

export function loadYouTubeMedia(id: number): Promise<YouTubeMedia> {
  return api<{ data: YouTubeMedia }>(`/youtube/videos/${id}/media`).then((response) => response.data);
}

export type YouTubeConnectionStatus = {
  configured: boolean;
  connected: boolean;
  display_name: string | null;
  connected_at: string | null;
  needs_reconnect: boolean;
  upload_granted: boolean;
  needs_upload_consent: boolean;
  metrics: {
    subscribers: number | null;
    views: number | null;
    watch_hours: number | null;
    error: string | null;
  };
};

export function startYouTubeConnect() {
  return api<{ data: { authorize_url: string } }>("/connected-accounts/youtube/connect", { method: "POST" });
}

export function startYouTubeUploadConsent() {
  return api<{ data: { authorize_url: string } }>("/connected-accounts/youtube/upload-consent", { method: "POST" });
}

export function getYouTubeStatus() {
  return api<{ data: YouTubeConnectionStatus }>("/connected-accounts/youtube/status");
}

export function disconnectYouTube() {
  return api<{ data: YouTubeConnectionStatus }>("/connected-accounts/youtube", { method: "DELETE" });
}
