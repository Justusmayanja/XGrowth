export type AccountProfile = {
  purpose?: Purpose | null;
  platforms?: string | null;
  goal?: string;
  niche?: string | null;
  custom_niche?: string | null;
  description?: string;
  tone?: string;
  audience?: string | null;
  mode?: string;
  automation_level?: number;
  max_silence_minutes?: number;
  onboarding_completed?: boolean;
};

export const MAX_PROFILE_SELECTIONS = 3;

export function parseMultiValue(value?: string | null): string[] {
  if (!value?.trim()) return [];
  return value
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);
}

export function joinMultiValue(values: string[]): string {
  return values.join(",");
}

export function formatMultiLabels(
  values: string[],
  options: Array<{ value: string; label: string }>
): string {
  return values
    .map((value) => options.find((option) => option.value === value)?.label ?? value)
    .join(", ");
}

export const GOALS = [
  { value: "grow_followers", label: "Grow followers", hint: "Build a larger, engaged audience" },
  { value: "build_brand", label: "Build a brand", hint: "Become known in your space" },
  { value: "drive_traffic", label: "Drive traffic", hint: "Send people to your site or product" },
  { value: "promote_business", label: "Promote a business", hint: "Reach customers and clients" },
  { value: "influencer", label: "Build influence", hint: "Grow authority in your niche" },
  { value: "monetize", label: "Monetize", hint: "Work toward each platform’s payout lines" },
];

export const NICHES = [
  { value: "football", label: "Football & sports" },
  { value: "technology", label: "Technology" },
  { value: "business", label: "Business" },
  { value: "crypto", label: "Crypto & finance" },
  { value: "motivation", label: "Motivation" },
  { value: "memes", label: "Memes & humor" },
  { value: "local_news", label: "Local news" },
  { value: "entertainment", label: "Entertainment" },
  { value: "custom", label: "Something else" },
];

export type Platform = "x" | "tiktok" | "youtube";

export const PLATFORM_ORDER: Platform[] = ["x", "tiktok", "youtube"];

export const PLATFORMS: Array<{ value: Platform; label: string; hint: string }> = [
  { value: "x", label: "X", hint: "Posts, threads, and replies while a topic is moving." },
  { value: "tiktok", label: "TikTok", hint: "Short clips, hooks, and captions." },
  { value: "youtube", label: "YouTube", hint: "Titles, descriptions, and community posts." },
];

export function platformName(platform: Platform): string {
  return PLATFORMS.find((item) => item.value === platform)?.label ?? platform;
}

export type Purpose = "business" | "creator" | "side_hustle" | "exploring";

export const PURPOSES: Array<{ value: Purpose; label: string; hint: string }> = [
  { value: "business", label: "A business I run", hint: "Reach customers and keep the business visible." },
  { value: "creator", label: "My brand or creator account", hint: "Grow an audience around you or your work." },
  {
    value: "side_hustle",
    label: "To start earning online",
    hint: "A side hustle. We help you pick a niche and work toward payouts.",
  },
  { value: "exploring", label: "Not sure yet", hint: "We suggest a few directions you can try." },
];

export function needsNicheDiscovery(purpose: Purpose | null | undefined): boolean {
  return purpose === "side_hustle" || purpose === "exploring";
}

type AboutCopy = {
  describeLabel: string;
  describeHint: string;
  describePlaceholder: string;
  audienceLabel: string;
  audiencePlaceholder: string;
  voiceTitle: string;
  voiceLede: string;
};

export const ABOUT_COPY: Record<Purpose, AboutCopy> = {
  business: {
    describeLabel: "What does the business do, and who buys from you?",
    describeHint: "Name what you sell, who it is for, and anything you never want posted.",
    describePlaceholder:
      "Example: We run a bakery in Kampala. Custom cakes and weekday bread. Customers are families and offices nearby. No discount spam.",
    audienceLabel: "Who should see the posts?",
    audiencePlaceholder: "Example: People within 10 km of the shop, offices ordering for events.",
    voiceTitle: "How should the business sound?",
    voiceLede: "Pick up to three. Drafts stay in this voice.",
  },
  creator: {
    describeLabel: "In your own words, what is this account about?",
    describeHint: "Name the subject, the audience, and what you refuse to post.",
    describePlaceholder:
      "Example: A fan account focused on transfer news, match reactions, and honest opinions about Manchester United.",
    audienceLabel: "Who follows you, or who do you want to reach?",
    audiencePlaceholder: "Example: Football fans aged 18–35, mostly UK and Nigeria, who like bold takes.",
    voiceTitle: "How should it sound?",
    voiceLede: "Pick up to three. Drafts stay in this voice.",
  },
  side_hustle: {
    describeLabel: "What could you talk about every week without getting bored?",
    describeHint: "It does not need to be polished. Say what you like and what you already know.",
    describePlaceholder:
      "Example: I cook cheap meals for one after work. I know which shortcuts save money and which ones taste bad.",
    audienceLabel: "Who would find that useful?",
    audiencePlaceholder: "Example: Students and young workers cooking on a small budget.",
    voiceTitle: "How do you sound when you explain something to a friend?",
    voiceLede: "Pick up to three. This is the voice drafts will use.",
  },
  exploring: {
    describeLabel: "What could you talk about every week without getting bored?",
    describeHint: "Rough is fine. You can change all of this later in Settings.",
    describePlaceholder:
      "Example: I follow tech news closely and like explaining new phones and apps to friends who are not into tech.",
    audienceLabel: "Who would you like to reach?",
    audiencePlaceholder: "Example: People who want tech explained without the jargon.",
    voiceTitle: "How do you sound when you explain something to a friend?",
    voiceLede: "Pick up to three. This is the voice drafts will use.",
  },
};

export const WEEKLY_TIME = [
  { value: "under_3", label: "Under 3 hours", hint: "A few short posts a week." },
  { value: "3_to_7", label: "3 to 7 hours", hint: "Regular posting, some longer pieces." },
  { value: "over_7", label: "More than 7 hours", hint: "Daily posting or weekly videos." },
];

export const PRESENCE = [
  { value: "on_camera", label: "On camera", hint: "Your face is part of the content." },
  { value: "voice_only", label: "Voice only", hint: "You narrate. No face." },
  { value: "faceless", label: "Faceless", hint: "Text, screen recordings, or stock footage." },
];

export type GoalOption = { value: string; label: string; hint: string };

export const PLATFORM_GOALS: Record<Platform, GoalOption[]> = {
  x: GOALS.map((goal) =>
    goal.value === "monetize" ? { ...goal, hint: "Work toward X creator payouts" } : goal
  ),
  tiktok: [
    { value: "for_you", label: "Get on the For You feed", hint: "Hooks and timing that reach new viewers." },
    { value: "followers", label: "Turn viewers into followers", hint: "Series and callbacks that bring people back." },
    { value: "traffic", label: "Send people to a link", hint: "Point viewers to a product or page." },
    { value: "promote_business", label: "Promote the business", hint: "Show the product and the people behind it." },
    { value: "monetize", label: "Earn from TikTok", hint: "Work toward TikTok creator payouts." },
  ],
  youtube: [
    { value: "subscribers", label: "Gain subscribers", hint: "Give people a reason to come back." },
    { value: "watch_time", label: "Hold watch time", hint: "Titles and openings that keep people watching." },
    { value: "search", label: "Be found in search", hint: "Titles and descriptions people actually search for." },
    { value: "traffic", label: "Send people to a site", hint: "Descriptions that point to a product or page." },
    { value: "promote_business", label: "Promote the business", hint: "Videos that show what you do." },
    { value: "monetize", label: "Join the Partner Program", hint: "Work toward YouTube ad revenue." },
  ],
};

export const SIDE_HUSTLE_GOALS: Record<Platform, string[]> = {
  x: ["monetize", "grow_followers"],
  tiktok: ["for_you", "monetize"],
  youtube: ["subscribers", "watch_time", "monetize"],
};

export type PlatformSetupState = {
  goals: string[];
  settings: { automation_level?: number; mode?: string; publish?: string };
  completed: boolean;
};

export type SetupState = {
  platforms: Platform[];
  paid_platforms: Platform[];
  about: {
    purpose: Purpose | null;
    niche: string[];
    custom_niche: string | null;
    description: string | null;
    audience: string | null;
    tone: string[];
    discovery: Discovery | null;
  } | null;
  about_complete: boolean;
  setups: Record<Platform, PlatformSetupState>;
  onboarding_completed: boolean;
};

export type Discovery = {
  interests?: string;
  weekly_time?: string;
  presence?: string;
  picked?: string;
};

export type NicheIdea = { niche: string; angle: string };

export const TONES = [
  { value: "professional", label: "Professional", hint: "Clear, credible, polished" },
  { value: "passionate", label: "Passionate", hint: "Bold opinions, high energy" },
  { value: "funny", label: "Witty", hint: "Light, sharp, entertaining" },
  { value: "motivational", label: "Motivational", hint: "Inspiring and uplifting" },
  { value: "news", label: "News-style", hint: "Fast, factual, to the point" },
];
