export function watchLabel(bytes: number | null): string {
  if (!bytes || bytes < 1) return "A short video to watch";
  if (bytes < 1024 * 1024) return `About ${Math.max(1, Math.round(bytes / 1024))} KB to watch`;
  const mb = bytes / (1024 * 1024);
  const shown = mb >= 10 ? String(Math.round(mb)) : String(Math.round(mb * 10) / 10);
  return `About ${shown} MB to watch`;
}

export function durationLabel(seconds: number | null): string {
  if (!seconds || seconds < 1) return "Under a minute";
  const minutes = Math.floor(seconds / 60);
  const rest = seconds % 60;
  if (minutes < 1) return `${rest}s`;
  return `${minutes}:${String(rest).padStart(2, "0")}`;
}

export function byteLabel(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function elapsedLabel(seconds: number): string {
  const whole = Math.max(0, Math.floor(seconds));
  const minutes = Math.floor(whole / 60);
  const rest = whole % 60;
  if (minutes < 1) return `Working for ${rest} seconds.`;
  return `Working for ${minutes}m ${String(rest).padStart(2, "0")}s.`;
}
