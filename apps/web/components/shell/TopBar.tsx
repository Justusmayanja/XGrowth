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
    <header className="shell-header fixed left-0 right-0 top-0 border-b border-border bg-background lg:left-64">
      <div className="mx-auto grid h-14 w-full max-w-6xl grid-cols-[minmax(0,1fr)_auto] items-center gap-3 px-4 sm:px-6 lg:h-16 lg:grid-cols-[minmax(0,1fr)_minmax(12rem,24rem)_minmax(0,1fr)] lg:px-8">
      <div className="flex min-w-0 items-center gap-2">
        <button
          ref={menuRef}
          type="button"
          className="shell-hit inline-flex size-9 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-surface-2 hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:hidden"
          onClick={onMenu}
          aria-label="Open menu"
        >
          <Menu className="size-5" />
        </button>
        <BrandLogo className="shrink-0 text-sm lg:hidden" />
        <p className="hidden min-w-0 truncate text-sm leading-none text-muted-foreground lg:block">
          <span className="text-foreground">{platformName(platform)}</span>
          {section ? <span> / {section.label}</span> : null}
        </p>
      </div>
      <button
        type="button"
        onClick={onSearch}
        className="shell-hit hidden h-9 w-full items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm text-muted-foreground hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:inline-flex"
      >
        <Search className="size-4 shrink-0" />
        <span className="flex-1 text-left">Search pages…</span>
        <span className="text-xs">{keys}</span>
      </button>
      <div className="flex items-center justify-end gap-2">
        <button
          type="button"
          onClick={onSearch}
          className="shell-hit inline-flex size-9 items-center justify-center rounded-md text-muted-foreground hover:bg-surface-2 hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:hidden"
          aria-label="Search pages"
        >
          <Search className="size-4" />
        </button>
        <UserMenu />
      </div>
      </div>
    </header>
  );
}
