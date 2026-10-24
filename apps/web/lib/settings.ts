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
  { value: 0, label: "I approve everything", hint: "Mackie17 only suggests. You post." },
  { value: 1, label: "I decide what goes live", hint: "It drafts. You say yes." },
  { value: 2, label: "Post when the moment is strong", hint: "It publishes the clear ones and holds the rest." },
  {
    value: 3,
    label: "Full automation",
    hint: "It drafts, checks whether the post can travel in your niche, and publishes while you are offline.",
  },
];
