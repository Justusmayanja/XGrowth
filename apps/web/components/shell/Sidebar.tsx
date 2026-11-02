"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { BrandLogo } from "@/components/BrandLogo";
import { PlanCard } from "@/components/shell/PlanCard";
import { PlatformSwitcher } from "@/components/shell/PlatformSwitcher";
import { NAV, navHrefActive } from "@/lib/nav";
import { usePlatform, withPlatform } from "@/lib/platform";

export function SidebarNav({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  const platform = usePlatform();
  const [hash, setHash] = useState("");

  useEffect(() => {
    const read = () => setHash(window.location.hash);
    read();
    window.addEventListener("hashchange", read);
    return () => window.removeEventListener("hashchange", read);
  }, [pathname]);

  return (
    <>
      <PlatformSwitcher current={platform} onNavigate={onNavigate} />
      <nav className="flex flex-1 flex-col gap-4 overflow-y-auto" aria-label="Sections">
        {NAV[platform].map((group) => (
          <div key={group.label}>
            <p className="px-2 pb-1 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{group.label}</p>
            <div className="flex flex-col gap-0.5">
              {group.links.map((link) => {
                const active = navHrefActive(pathname, hash, link.href);
                const Icon = link.icon;
                const href = link.href.startsWith("/dashboard/") ? link.href : withPlatform(link.href, platform);
                return (
                  <Link
                    key={link.href}
                    href={href}
                    onClick={() => onNavigate?.()}
                    aria-current={active ? "page" : undefined}
                    className={`desk-nav-link shell-hit focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring ${
                      active ? "is-on" : ""
                    }`}
                  >
                    <Icon strokeWidth={1.7} />
                    {link.label}
                  </Link>
                );
              })}
            </div>
          </div>
        ))}
      </nav>
    </>
  );
}

export function Sidebar() {
  return (
    <aside className="shell-drawer fixed left-0 top-0 hidden h-screen w-64 flex-col border-r border-border bg-sidebar lg:flex">
      <div className="flex h-16 shrink-0 items-center border-b border-border px-4">
        <BrandLogo className="text-sm" />
      </div>
      <div className="flex min-h-0 flex-1 flex-col px-3 py-4">
        <SidebarNav />
      </div>
      <div className="shrink-0 p-3">
        <PlanCard />
      </div>
    </aside>
  );
}
