"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { TrialBanner } from "@/components/TrialBanner";
import {
  BarChart3,
  Clapperboard,
  Clock,
  FileText,
  Hash,
  House,
  Lightbulb,
  ListOrdered,
  Menu,
  MessageCircle,
  PenLine,
  Settings,
  Shield,
  Sun,
  Video,
  X,
  type LucideIcon,
} from "lucide-react";
import { BrandLogo } from "@/components/BrandLogo";
import { api, clearToken } from "@/lib/api";
import { usePlatform, withPlatform } from "@/lib/platform";
import { PLATFORM_ORDER, platformName, type Platform } from "@/lib/profile";

type NavGroup = { label: string; links: { href: string; label: string; icon: LucideIcon }[] };

const NAV: Record<Platform, NavGroup[]> = {
  x: [
    {
      label: "Desk",
      links: [
        { href: "/dashboard/x", label: "Home", icon: House },
        { href: "/content-studio", label: "Compose", icon: PenLine },
        { href: "/drafts", label: "Drafts", icon: FileText },
        { href: "/growth-agent", label: "Queue", icon: ListOrdered },
      ],
    },
    {
      label: "Coverage",
      links: [
        { href: "/trends", label: "Topics", icon: Hash },
        { href: "/engagement", label: "Replies", icon: MessageCircle },
        { href: "/analytics", label: "Results", icon: BarChart3 },
      ],
    },
    {
      label: "Account",
      links: [
        { href: "/safety", label: "Limits", icon: Shield },
        { href: "/settings", label: "Settings", icon: Settings },
      ],
    },
  ],
  tiktok: [
    {
      label: "Studio",
      links: [
        { href: "/dashboard/tiktok", label: "Today", icon: Sun },
        { href: "/content-studio", label: "New clip", icon: Clapperboard },
        { href: "/drafts", label: "Waiting for you", icon: Clock },
      ],
    },
    {
      label: "Coverage",
      links: [
        { href: "/trends", label: "Ideas", icon: Lightbulb },
        { href: "/analytics", label: "What landed", icon: BarChart3 },
      ],
    },
    {
      label: "Account",
      links: [{ href: "/settings", label: "Settings", icon: Settings }],
    },
  ],
  youtube: [
    {
      label: "Studio",
      links: [
        { href: "/dashboard/youtube", label: "Today", icon: Sun },
        { href: "/content-studio", label: "New video", icon: Video },
        { href: "/drafts", label: "Titles and scripts", icon: FileText },
      ],
    },
    {
      label: "Coverage",
      links: [
        { href: "/trends", label: "Ideas", icon: Lightbulb },
        { href: "/analytics", label: "What landed", icon: BarChart3 },
      ],
    },
    {
      label: "Account",
      links: [{ href: "/settings", label: "Settings", icon: Settings }],
    },
  ],
};

type SidebarProps = {
  mobileOpen?: boolean;
  onNavigate?: () => void;
};

function PlatformSwitch({ current, onNavigate }: { current: Platform; onNavigate?: () => void }) {
  const [chosen, setChosen] = useState<Platform[]>([]);

  useEffect(() => {
    api<{ platforms: Platform[] }>("/account/setup")
      .then((state) => setChosen(PLATFORM_ORDER.filter((platform) => state.platforms.includes(platform))))
      .catch(() => setChosen([]));
  }, []);

  const shown = chosen.includes(current) ? chosen : [...chosen, current];
  if (shown.length < 2) {
    if (current === "x") return null;
    return <p className="mb-4 px-2 text-sm font-medium">{platformName(current)}</p>;
  }

  return (
    <div className="mb-4 flex rounded-lg border border-border p-0.5" role="tablist" aria-label="Platform">
      {PLATFORM_ORDER.filter((platform) => shown.includes(platform)).map((platform) => (
        <Link
          key={platform}
          href={`/dashboard/${platform}`}
          role="tab"
          aria-selected={platform === current}
          onClick={() => onNavigate?.()}
          className={`flex-1 rounded-md px-2 py-1 text-center text-xs transition-colors ${
            platform === current ? "bg-[#232429] text-foreground" : "text-muted-foreground hover:text-foreground"
          }`}
        >
          {platformName(platform)}
        </Link>
      ))}
    </div>
  );
}

type SessionUser = {
  name?: string | null;
  username?: string | null;
  email?: string | null;
  connected_accounts?: { platform: string; username?: string | null; is_active?: boolean }[];
};

export function Sidebar({ mobileOpen = false, onNavigate }: SidebarProps) {
  const pathname = usePathname();
  const router = useRouter();
  const platform = usePlatform();
  const [session, setSession] = useState<SessionUser | null>(null);

  useEffect(() => {
    if (platform !== "x") return;
    api<{ user: SessionUser }>("/auth/me")
      .then((res) => setSession(res.user))
      .catch(() => setSession({ name: "Account" }));
  }, [platform]);

  async function logout() {
    clearToken();
    onNavigate?.();
    router.push("/");
  }

  return (
    <aside
      className={`fixed left-0 top-0 z-50 flex h-screen w-60 flex-col border-r border-border bg-sidebar px-3 py-4 max-lg:transition-transform max-lg:duration-200 ${
        mobileOpen ? "max-lg:translate-x-0" : "max-lg:-translate-x-full"
      }`}
    >
      <div className="mb-4 flex items-center justify-between px-2">
        <BrandLogo className="text-sm" />
        <button
          type="button"
          className="rounded-sm p-2 text-muted hover:bg-surface-2 hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground lg:hidden"
          onClick={onNavigate}
          aria-label="Close menu"
        >
          <X className="h-5 w-5" />
        </button>
      </div>
      <PlatformSwitch current={platform} onNavigate={onNavigate} />
      {platform === "x" && (
        <Link
          href={withPlatform("/content-studio", "x")}
          onClick={() => onNavigate?.()}
          className="mx-1 mb-3 flex h-[52px] items-center justify-center rounded-full bg-[#e7e9ea] text-[17px] font-bold text-black hover:bg-white"
        >
          Post
        </Link>
      )}
      <nav className={`flex flex-1 flex-col gap-3 ${platform === "x" ? "overflow-y-auto" : ""}`}>
        {NAV[platform].map((group) => (
          <div key={group.label}>
            <p className="px-2 pb-1 text-[11px] text-[#71717a]">{group.label}</p>
            <div className="flex flex-col gap-0.5">
              {group.links.map((link) => {
                const active = pathname === link.href;
                const Icon = link.icon;
                const href = link.href.startsWith("/dashboard/") ? link.href : withPlatform(link.href, platform);
                return (
                  <Link
                    key={link.href}
                    href={href}
                    onClick={() => onNavigate?.()}
                    aria-current={active ? "page" : undefined}
                    className={`desk-nav-link focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#52525b] ${
                      platform === "x" ? "x-nav" : ""
                    } ${active ? "is-on" : ""}`}
                  >
                    <Icon strokeWidth={platform === "x" && active ? 2.5 : 1.7} />
                    {link.label}
                  </Link>
                );
              })}
            </div>
          </div>
        ))}
      </nav>
      {platform === "x" && <XAccount session={session} onNavigate={onNavigate} />}
      <button
        onClick={logout}
        className={
          platform === "x"
            ? "mt-1 rounded-full px-3 py-2 text-left text-[15px] text-[#e7e9ea] hover:bg-white/10"
            : "mt-3 px-2 py-1 text-left text-sm text-[#a1a1aa] hover:text-[#ececee]"
        }
      >
        Sign out
      </button>
    </aside>
  );
}

type AppShellProps = {
  children: React.ReactNode;
};

function XAccount({ session, onNavigate }: { session: SessionUser | null; onNavigate?: () => void }) {
  if (!session) {
    return (
      <div className="mt-2 flex items-center gap-3 px-3 py-2" aria-hidden>
        <div className="h-10 w-10 animate-pulse rounded-full bg-[#2f3336]" />
        <div className="space-y-2">
          <div className="h-3 w-24 animate-pulse rounded-full bg-[#2f3336]" />
          <div className="h-3 w-16 animate-pulse rounded-full bg-[#2f3336]" />
        </div>
      </div>
    );
  }

  const xAccount = session.connected_accounts?.find((account) => account.platform === "x" && account.is_active);
  const title = session.name || session.username || "Account";
  const handle = xAccount?.username
    ? `@${xAccount.username.replace(/^@/, "")}`
    : session.username
      ? `@${session.username}`
      : session.email || "";
  const letter = (title.replace(/^@/, "")[0] || "M").toUpperCase();

  return (
    <Link
      href={withPlatform("/settings", "x")}
      onClick={() => onNavigate?.()}
      className="mt-2 flex items-center gap-3 rounded-full px-3 py-2 hover:bg-white/10"
    >
      <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[#1d9bf0] text-sm font-bold text-white">
        {letter}
      </span>
      <span className="min-w-0">
        <span className="block truncate text-[15px] font-bold leading-5 text-[#e7e9ea]">{title}</span>
        {handle && <span className="block truncate text-[15px] leading-5 text-[#71767b]">{handle}</span>}
      </span>
    </Link>
  );
}

export function AppShell({ children }: AppShellProps) {
  const pathname = usePathname();
  const platform = usePlatform();
  const [mobileOpen, setMobileOpen] = useState(false);
  const xHome = platform === "x" && pathname === "/dashboard/x";

  useEffect(() => {
    document.body.style.overflow = mobileOpen ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [mobileOpen]);

  return (
    <div className="desk min-h-screen bg-background text-foreground">
      <header className="fixed left-0 right-0 top-0 z-40 flex h-14 items-center gap-3 border-b border-border bg-background px-4 lg:hidden">
        <button
          type="button"
          className="rounded-sm p-2 text-muted hover:bg-surface-2 hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
          onClick={() => setMobileOpen(true)}
          aria-label="Open menu"
        >
          <Menu className="h-5 w-5" />
        </button>
        <BrandLogo className="text-sm" />
      </header>

      {mobileOpen && (
        <button
          type="button"
          className="fixed inset-0 z-40 bg-black/70 lg:hidden"
          onClick={() => setMobileOpen(false)}
          aria-label="Close menu overlay"
        />
      )}

      <Sidebar mobileOpen={mobileOpen} onNavigate={() => setMobileOpen(false)} />
      <main
        className={
          xHome
            ? "min-h-screen overflow-x-hidden px-0 pb-16 pt-0 lg:ml-60 lg:pb-0"
            : "min-h-screen overflow-x-hidden px-4 pb-10 pt-20 lg:ml-60 lg:px-8 lg:pb-12 lg:pt-8"
        }
      >
        <div className={xHome ? "px-4 lg:px-8" : undefined}>
          <TrialBanner />
        </div>
        {children}
      </main>
    </div>
  );
}
