export type AccountInfo = {
  name: string;
  email: string;
  subscription_plan: string;
};

export type AutomationSettings = {
  engine_paused: boolean;
  mode: string;
  automation_level: number;
  max_silence_minutes: number;
  auto_post_enabled: boolean;
  auto_reply_enabled: boolean;
  auto_engage_enabled: boolean;
};

export type NotificationSettings = {
  notify_post_results: boolean;
  notify_draft_ready: boolean;
  notify_weekly_recap: boolean;
  notify_safety_alerts: boolean;
};

export type XConnectStatus = {
  available: boolean;
  connected: boolean;
  username?: string;
  authorize_url?: string | null;
  message: string;
};

export type SafetyStatus = {
  safety_score: number;
  actions: Record<string, { count: number; daily_limit: number }>;
};

export const AUTOMATION_LEVELS = [
  { value: 0, label: "Suggest only", hint: "Xpulse drafts; you approve everything" },
  { value: 1, label: "Semi-auto", hint: "Xpulse drafts; you decide what goes live" },
  { value: 2, label: "Mostly auto", hint: "Post when opportunity score is high" },
  { value: 3, label: "Full auto", hint: "Xpulse handles growth with minimal input" },
];
