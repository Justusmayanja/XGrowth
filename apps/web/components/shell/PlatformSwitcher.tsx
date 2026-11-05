"use client";

import { useEffect, useState } from "react";
import { PageLink as Link } from "@/components/PageLink";
import { api } from "@/lib/api";
import { PLATFORM_ORDER, platformName, type Platform } from "@/lib/profile";

export function PlatformSwitcher({ current, onNavigate }: { current: Platform; onNavigate?: () => void }) {
  const [chosen, setChosen] = useState<Platform[]>([]);

  useEffect(() => {
    api<{ platforms: Platform[] }>("/account/setup")
      .then((state) => setChosen(PLATFORM_ORDER.filter((platform) => state.platforms.includes(platform))))
      .catch(() => setChosen([]));
  }, []);

  const shown = chosen.includes(current) ? chosen : [...chosen, current];
  if (shown.length < 2) {
    return <p className="mb-4 px-2 text-sm font-medium">{platformName(current)}</p>;
  }

  const items = PLATFORM_ORDER.filter((platform) => shown.includes(platform));

  return (
    <nav
      className="mb-4 grid gap-0.5 rounded-lg border border-border p-0.5"
      style={{ gridTemplateColumns: `repeat(${items.length}, minmax(0, 1fr))` }}
      aria-label="Platform"
    >
      {items.map((platform) => {
        const on = platform === current;
        return (
          <Link
            key={platform}
            href={`/dashboard/${platform}`}
            aria-current={on ? "page" : undefined}
            onClick={() => onNavigate?.()}
            className={`shell-hit rounded-md px-2 py-1.5 text-center text-xs ${
              on ? "bg-primary font-medium text-primary-foreground" : "text-muted-foreground hover:text-foreground"
            }`}
          >
            {platformName(platform)}
          </Link>
        );
      })}
    </nav>
  );
}
