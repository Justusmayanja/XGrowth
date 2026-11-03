import { ApiError, api, getToken, readApiError } from "@/lib/api";

export type TikTokClipStatus =
  | "drafted"
  | "waiting"
  | "queued"
  | "uploading"
  | "in_drafts"
  | "upload_failed"
  | "posted"
  | "learned"
  | "discarded";

export type TikTokVideo = {
  filename: string;
  size: number;
  duration_seconds: number | null;
  width: number | null;
  height: number | null;
  warning: string | null;
};

export type TikTokLine = string | { text?: string; prompt?: string; at?: string; seconds?: number };

export type TikTokClip = {
  id: number;
  status: TikTokClipStatus | string;
  status_label: string;
  kind?: string | null;
  kind_label?: string | null;
  topic: string | null;
  hook: string | null;
  script_lines: string[];
  caption: string | null;
  on_screen_text: TikTokLine[];
  shots: TikTokLine[];
  sound_note: string | null;
  suggested_for: string | null;
  timezone: string | null;
  timezone_assumed: boolean;
  views: number | null;
  completion_percent: number | null;
  follows: number | null;
  learning_note: string | null;
  confirmed_at: string | null;
  queued_at: string | null;
  posted_at: string | null;
  learned_at: string | null;
  publish_note: string | null;
  platform_post_id: string | null;
  blockers: string[];
  video?: TikTokVideo | null;
  upload_error?: string | null;
  upload_progress?: number | null;
  render?: {
    status: string;
    label: string;
    note: string | null;
    ready: boolean;
    provider: string;
    ai_generated: boolean;
    can_regenerate: boolean;
  };
};

export type TikTokIdea = {
  kind: string;
  kind_label: string;
  topic: string;
  angle: string;
  reason: string;
};

export type TikTokBlock = {
  reason: "setup_incomplete" | "no_plan" | "allowance_spent" | string;
  message: string;
  action: "finish_setup" | "billing" | "wait" | null;
  renews_at: string | null;
};

export type TikTokDesk = {
  clip: TikTokClip | null;
  recent: TikTokClip[];
  ideas: TikTokIdea[];
  pipeline: { ideas: number; written: number; waiting: number; confirmed: number; posted: number };
  allowance: { limit: number; used: number; remaining: number; period_end: string | null; plan_found: boolean };
  daily_cap: { cap: number; used: number; reached: boolean; available_at: string | null };
  block: TikTokBlock | null;
  timezone: { value: string; assumed: boolean };
  connection: {
    connected: boolean;
    display_name?: string | null;
    needs_reconnect?: boolean;
    configured?: boolean;
    upload_configured?: boolean;
    needs_upload_permission?: boolean;
  };
};

export type TikTokConnectionStatus = {
  configured: boolean;
  connected: boolean;
  display_name: string | null;
  avatar_url: string | null;
  connected_at: string | null;
  needs_reconnect: boolean;
  needs_upload_permission?: boolean;
};

export type TikTokRun = {
  outcome: "created" | "skipped" | string;
  clip: TikTokClip | null;
  reason: string | null;
  message: string;
};

export type TikTokResults = {
  data: TikTokClip;
  next: TikTokRun | null;
};

const NETWORK = "Something went wrong. We're checking what happened.";

export function normalizeTikTokError(err: unknown): ApiError {
  if (err instanceof ApiError) {
    if (err.status === 429 && !err.code) {
      return new ApiError("Slow down a moment and try again.", 429);
    }
    if (err.status >= 500) return new ApiError(NETWORK, err.status, err.code, err.fields);
    return err;
  }
  return new ApiError(NETWORK, 0);
}

async function call<T>(path: string, options: RequestInit = {}, timeoutMs = 30000): Promise<T> {
  try {
    return await api<T>(path, options, timeoutMs);
  } catch (err) {
    throw normalizeTikTokError(err);
  }
}

export type TikTokHistory = {
  data: TikTokClip[];
  summary: {
    learned: number;
    average_completion: number | null;
    views: number;
    follows: number;
  };
  meta?: { current_page: number; last_page: number };
};

export function fetchTikTokIdeas() {
  return call<{ data: TikTokIdea[] }>("/tiktok/ideas");
}

export function fetchTikTokClips(page = 1) {
  return call<TikTokHistory>(`/tiktok/clips?status=learned&page=${page}`);
}

export function startTikTokConnect() {
  return call<{ data: { authorize_url: string } }>("/connected-accounts/tiktok/connect", { method: "POST" });
}

export function getTikTokStatus() {
  return call<{ data: TikTokConnectionStatus }>("/connected-accounts/tiktok/status");
}

export function disconnectTikTok() {
  return call<{ data: TikTokConnectionStatus }>("/connected-accounts/tiktok", { method: "DELETE" });
}

export function runTikTok() {
  return call<TikTokRun>("/tiktok/run", { method: "POST" });
}

export function confirmClip(id: number) {
  return call<{ data: TikTokClip }>(`/tiktok/clips/${id}/confirm`, { method: "POST" });
}

export function retryClipUpload(id: number) {
  return call<{ data: TikTokClip }>(`/tiktok/clips/${id}/upload/retry`, { method: "POST" });
}

export function markClipPosted(id: number) {
  return call<{ data: TikTokClip }>(`/tiktok/clips/${id}/posted`, { method: "POST" });
}

async function uploadRequest(path: string, init: RequestInit, timeoutMs: number, signal?: AbortSignal): Promise<unknown> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    ...(init.headers as Record<string, string>),
  };
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);
  const onAbort = () => controller.abort();
  signal?.addEventListener("abort", onAbort);
  try {
    const response = await fetch(`/api${path}`, { ...init, headers, signal: controller.signal });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw readApiError(response, data);
    return data;
  } catch (err) {
    if (signal?.aborted) throw new ApiError("Upload cancelled.", 0, "cancelled");
    if (err instanceof DOMException && err.name === "AbortError") {
      throw new ApiError("The upload timed out. Try a shorter video.", 0);
    }
    throw err instanceof ApiError ? err : new ApiError(NETWORK, 0);
  } finally {
    clearTimeout(timeout);
    signal?.removeEventListener("abort", onAbort);
  }
}

export async function uploadClipVideo(
  id: number,
  file: File,
  onProgress: (percent: number, loaded: number, total: number) => void,
  signal?: AbortSignal,
): Promise<TikTokClip> {
  const started = (await uploadRequest(
    `/tiktok/clips/${id}/video`,
    { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ filename: file.name, size: file.size }) },
    30000,
    signal,
  )) as { data: { chunk_bytes: number; chunk_count: number } };
  const chunkBytes = started.data.chunk_bytes;
  const chunkCount = started.data.chunk_count;
  for (let index = 0; index < chunkCount; index++) {
    if (signal?.aborted) throw new ApiError("Upload cancelled.", 0, "cancelled");
    const slice = file.slice(index * chunkBytes, Math.min(file.size, (index + 1) * chunkBytes));
    await uploadRequest(
      `/tiktok/clips/${id}/video/chunks/${index}`,
      { method: "PUT", headers: { "Content-Type": "application/octet-stream" }, body: slice },
      120000,
      signal,
    );
    const loaded = Math.min(file.size, (index + 1) * chunkBytes);
    onProgress(Math.round(((index + 1) / chunkCount) * 90), loaded, file.size);
  }
  const done = (await uploadRequest(`/tiktok/clips/${id}/video/complete`, { method: "POST" }, 120000, signal)) as { data: TikTokClip };
  onProgress(100, file.size, file.size);
  return done.data;
}

export async function fetchClipVideo(id: number): Promise<Blob> {
  const headers: Record<string, string> = {};
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const response = await fetch(`/api/tiktok/clips/${id}/video`, { headers });
  if (!response.ok) throw new ApiError("The video isn't available.", response.status);
  return response.blob();
}

export function discardClip(id: number) {
  return call<{ data: TikTokClip }>(`/tiktok/clips/${id}/discard`, { method: "POST" });
}

export function rescheduleClip(id: number, suggestedFor: string) {
  return call<{ data: TikTokClip }>(`/tiktok/clips/${id}/reschedule`, {
    method: "POST",
    body: JSON.stringify({ suggested_for: suggestedFor }),
  });
}

export function submitResults(id: number, body: { views: number; completion_percent: number; follows: number }) {
  return call<TikTokResults>(`/tiktok/clips/${id}/results`, {
    method: "POST",
    body: JSON.stringify(body),
  });
}

export function saveTimezone(timezone: string) {
  return call<{ data: { timezone: string; assumed: boolean } }>("/account/timezone", {
    method: "PUT",
    body: JSON.stringify({ timezone }),
  });
}

export function browserTimeZone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
  } catch {
    return "UTC";
  }
}

export function formatWhen(iso: string | null | undefined, timeZone: string): string {
  if (!iso) return "the suggested time";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "the suggested time";
  return new Intl.DateTimeFormat(undefined, {
    timeZone: timeZone || "UTC",
    weekday: "short",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  }).format(date);
}

export function lineText(line: TikTokLine): string {
  if (typeof line === "string") return line;
  return line.text || line.prompt || "";
}

export function toIsoWithOffset(date: Date): string {
  const pad = (value: number) => String(Math.abs(value)).padStart(2, "0");
  const offset = -date.getTimezoneOffset();
  const sign = offset >= 0 ? "+" : "-";
  const hours = Math.floor(Math.abs(offset) / 60);
  const minutes = Math.abs(offset) % 60;
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}${sign}${pad(hours)}:${pad(minutes)}`;
}

export function localInputValue(date: Date): string {
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function dateFromLocalInput(value: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value);
  if (!match) return null;
  const date = new Date(
    Number(match[1]),
    Number(match[2]) - 1,
    Number(match[3]),
    Number(match[4]),
    Number(match[5]),
    0,
    0,
  );
  return Number.isNaN(date.getTime()) ? null : date;
}

export function slotOn(day: Date): Date {
  const slot = new Date(day);
  slot.setHours(19, 30, 0, 0);
  return slot;
}

export function postUrl(id: string | null | undefined): string | null {
  if (!id) return null;
  return /^https?:\/\//i.test(id) ? id : null;
}
