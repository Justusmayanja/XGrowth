export type AccountProfile = {
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

export const GOALS = [
  { value: "grow_followers", label: "Grow followers" },
  { value: "build_brand", label: "Build a brand" },
  { value: "drive_traffic", label: "Drive traffic" },
  { value: "promote_business", label: "Promote a business" },
  { value: "influencer", label: "Build influence" },
  { value: "monetize", label: "Monetize on X" },
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
  { value: "custom", label: "Custom niche" },
];

export const TONES = [
  { value: "professional", label: "Professional" },
  { value: "passionate", label: "Passionate" },
  { value: "funny", label: "Witty" },
  { value: "motivational", label: "Motivational" },
  { value: "news", label: "News-style" },
];
