"use client";

import { useEffect, useState } from "react";
import { usePathname } from "next/navigation";
import { CONTENT_FORMATS } from "@/lib/content-formats";
import { PLATFORM_ORDER, type Platform } from "@/lib/profile";

const STORAGE_KEY = "mackie17-platform";

export function isPlatform(value: string | null | undefined): value is Platform {
  return PLATFORM_ORDER.includes(value as Platform);
}

export function platformOfFormat(contentType: string): Platform {
  if (contentType.startsWith("tiktok")) return "tiktok";
  if (contentType.startsWith("youtube")) return "youtube";
  return "x";
}

export function formatsFor(platform: Platform) {
  return CONTENT_FORMATS.filter((format) => platformOfFormat(format.value) === platform);
}

export function rememberPlatform(platform: Platform) {
  window.localStorage.setItem(STORAGE_KEY, platform);
}

function readPlatform(pathname: string): Platform | null {
  const fromPath = pathname.match(/^\/dashboard\/(x|tiktok|youtube)/)?.[1];
  if (isPlatform(fromPath)) return fromPath;
  const fromQuery = new URLSearchParams(window.location.search).get("platform");
  if (isPlatform(fromQuery)) return fromQuery;
  const saved = window.localStorage.getItem(STORAGE_KEY);
  return isPlatform(saved) ? saved : null;
}

export function usePlatform(fallback: Platform = "x"): Platform {
  const pathname = usePathname();
  const [platform, setPlatform] = useState<Platform>(fallback);

  useEffect(() => {
    const current = readPlatform(pathname);
    if (current) {
      rememberPlatform(current);
      setPlatform(current);
    }
  }, [pathname]);

  return platform;
}

export function withPlatform(href: string, platform: Platform) {
  return `${href}${href.includes("?") ? "&" : "?"}platform=${platform}`;
}
