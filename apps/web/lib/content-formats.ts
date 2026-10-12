export const CONTENT_FORMATS = [
  { value: "tweet", label: "X post" },
  { value: "thread", label: "X thread" },
  { value: "poll", label: "X poll" },
  { value: "quote", label: "X quote post" },
  { value: "tiktok_caption", label: "TikTok caption" },
  { value: "youtube_title", label: "YouTube title" },
  { value: "youtube_description", label: "YouTube description" },
  { value: "youtube_community", label: "YouTube community post" },
] as const;

export function contentFormatLabel(value: string): string {
  return (
    CONTENT_FORMATS.find((item) => item.value === value)?.label ??
    value.replaceAll("_", " ")
  );
}
