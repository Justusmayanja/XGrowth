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
  { value: "monetize", label: "Monetize on X", hint: "Hit impressions and engagement goals" },
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

export const TONES = [
  { value: "professional", label: "Professional", hint: "Clear, credible, polished" },
  { value: "passionate", label: "Passionate", hint: "Bold opinions, high energy" },
  { value: "funny", label: "Witty", hint: "Light, sharp, entertaining" },
  { value: "motivational", label: "Motivational", hint: "Inspiring and uplifting" },
  { value: "news", label: "News-style", hint: "Fast, factual, to the point" },
];
