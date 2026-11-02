"use client";

import { useEffect, useState } from "react";
import { Menu, Search } from "lucide-react";
import { BrandLogo } from "@/components/BrandLogo";
import { UserMenu } from "@/components/shell/UserMenu";
import { NAV, navHrefActive } from "@/lib/nav";
import { usePlatform } from "@/lib/platform";
import { platformName } from "@/lib/profile";
import { usePathname } from "next/navigation";

export function shortcutLabel(): string {
  if (typeof navigator === "undefined") return "Ctrl K";
  return /Mac|iPhone|iPad/.test(navigator.userAgent) ? "⌘K" : "Ctrl K";
}

export function TopBar({ onMenu, onSearch, menuRef }: { onMenu: () => void; onSearch: () => void; menuRef: React.RefObject<HTMLButtonElement | null> }) {
  const pathname = usePathname();
  const platform = usePlatform();
  const [hash, setHash] = useState("");
  const [keys, setKeys] = useState("Ctrl K");

  useEffect(() => {
    setKeys(shortcutLabel());
    const read = () => setHash(window.location.hash);
    read();
    window.addEventListener("hashchange", read);
    return () => window.removeEventListener("hashchange", read);
  }, [pathname]);

  const section = NAV[platform].flatMap((group) => group.links).find((link) => navHrefActive(pathname, hash, link.href));

  return (
    <header className="shell-header fixed left-0 right-0 top-0 flex h-14 items-center gap-3 border-b border-border bg-background/95 px-4 backdrop-blur-sm lg:left-64 lg:h-16">
      <button
        ref={menuRef}
        type="button"
        className="shell-hit rounded-md p-2 text-muted-foreground hover:bg-surface-2 hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:hidden"
        onClick={onMenu}
        aria-label="Open menu"
      >
        <Menu className="size-5" />
      </button>
      <BrandLogo className="text-sm lg:hidden" />
      <p className="hidden min-w-0 truncate text-sm text-muted-foreground lg:block">
        {platformName(platform)}
        {section ? <span> / {section.label}</span> : null}
      </p>
      <button
        type="button"
        onClick={onSearch}
        className="shell-hit ml-auto hidden h-9 min-w-56 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm text-muted-foreground hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring md:inline-flex"
      >
        <Search className="size-4" />
        <span className="flex-1 text-left">Search pages…</span>
        <span className="text-xs">{keys}</span>
      </button>
      <button
        type="button"
        onClick={onSearch}
        className="shell-hit ml-auto inline-flex size-9 items-center justify-center rounded-md text-muted-foreground hover:bg-surface-2 hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring md:hidden"
        aria-label="Search pages"
      >
        <Search className="size-4" />
      </button>
      <UserMenu />
    </header>
  );
}
