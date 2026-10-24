import type { ReactNode } from "react";

const AVATAR_COLORS = ["#1d9bf0", "#7856ff", "#ff7a00", "#00ba7c", "#f91880"];

export function avatarColor(seed: string): string {
  let hash = 0;
  for (let i = 0; i < seed.length; i += 1) hash = (hash * 31 + seed.charCodeAt(i)) >>> 0;
  return AVATAR_COLORS[hash % AVATAR_COLORS.length];
}

export function Avatar({ name, color = "#1d9bf0" }: { name: string; color?: string }) {
  const letter = (name.replace(/^@/, "").trim()[0] || "M").toUpperCase();
  return (
    <span
      aria-hidden
      className="grid h-10 w-10 shrink-0 place-items-center rounded-full text-[15px] font-bold text-white"
      style={{ backgroundColor: color }}
    >
      {letter}
    </span>
  );
}

export function TimelineHeader({
  title,
  pinned = false,
  children,
}: {
  title: string;
  pinned?: boolean;
  children?: ReactNode;
}) {
  return (
    <div
      className={`sticky top-0 z-20 border-b border-[#2f3336] bg-[#101113]/85 backdrop-blur-md ${pinned ? "max-lg:pt-14" : ""}`}
    >
      <div className="flex h-[53px] items-center px-4">
        <h1 className="text-xl font-bold tracking-tight text-[#e7e9ea]">{title}</h1>
      </div>
      {children}
    </div>
  );
}

export function TimelineColumn({ children }: { children: ReactNode }) {
  return <div className="mx-auto min-h-[70vh] w-full max-w-[600px] border-x border-[#2f3336]">{children}</div>;
}

export function RailPanel({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="mb-4 overflow-hidden rounded-2xl bg-[#16181c]">
      <h2 className="px-4 py-3 text-xl font-extrabold tracking-tight text-[#e7e9ea]">{title}</h2>
      {children}
    </section>
  );
}

export function compactCount(value: number): string {
  return new Intl.NumberFormat(undefined, {
    notation: Math.abs(value) >= 10000 ? "compact" : "standard",
    maximumFractionDigits: 1,
  }).format(value);
}

export function relativeTime(iso: string): string {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return "";
  const minutes = Math.max(0, Math.round((Date.now() - then) / 60000));
  if (minutes >= 60 * 24 * 7) {
    return new Date(iso).toLocaleDateString(undefined, { month: "short", day: "numeric" });
  }
  return minutesLabel(minutes);
}

export function minutesLabel(minutes: number): string {
  if (minutes < 1) return "now";
  if (minutes < 60) return `${minutes}m`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours}h`;
  const days = Math.round(hours / 24);
  if (days < 7) return `${days}d`;
  return `${days}d`;
}
