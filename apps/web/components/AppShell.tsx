"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { Menu, X } from "lucide-react";
import { BrandLogo } from "@/components/BrandLogo";
import { clearToken } from "@/lib/api";

const links = [
  { href: "/dashboard", label: "Dashboard" },
  { href: "/content-studio", label: "Content Studio" },
  { href: "/drafts", label: "Drafts" },
  { href: "/growth-agent", label: "Growth Engine" },
  { href: "/engagement", label: "Engagement" },
  { href: "/trends", label: "Trend Explorer" },
  { href: "/analytics", label: "Analytics" },
  { href: "/safety", label: "Safety Center" },
  { href: "/settings", label: "Settings" },
];

type SidebarProps = {
  mobileOpen?: boolean;
  onNavigate?: () => void;
};

export function Sidebar({ mobileOpen = false, onNavigate }: SidebarProps) {
  const pathname = usePathname();
  const router = useRouter();

  async function logout() {
    clearToken();
    onNavigate?.();
    router.push("/");
  }

  return (
    <aside
      className={`fixed left-0 top-0 z-50 flex h-screen w-60 flex-col border-r border-border bg-surface p-4 max-lg:transition-transform max-lg:duration-200 ${
        mobileOpen ? "max-lg:translate-x-0" : "max-lg:-translate-x-full"
      }`}
    >
      <div className="mb-8 flex items-center justify-between px-2">
        <BrandLogo className="text-base" />
        <button
          type="button"
          className="rounded-lg p-1 text-muted hover:bg-surface-2 hover:text-white lg:hidden"
          onClick={onNavigate}
          aria-label="Close menu"
        >
          <X className="h-5 w-5" />
        </button>
      </div>
      <nav className="flex flex-1 flex-col gap-1 overflow-y-auto">
        {links.map((link) => (
          <Link
            key={link.href}
            href={link.href}
            onClick={() => onNavigate?.()}
            className={`rounded-lg px-3 py-2 text-sm transition ${
              pathname === link.href
                ? "bg-surface-2 text-white"
                : "text-muted hover:bg-surface-2 hover:text-white"
            }`}
          >
            {link.label}
          </Link>
        ))}
      </nav>
      <button onClick={logout} className="btn btn-secondary w-full text-sm">
        Sign out
      </button>
    </aside>
  );
}

type AppShellProps = {
  children: React.ReactNode;
};

export function AppShell({ children }: AppShellProps) {
  const [mobileOpen, setMobileOpen] = useState(false);

  useEffect(() => {
    document.body.style.overflow = mobileOpen ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [mobileOpen]);

  return (
    <div className="min-h-screen bg-background text-foreground">
      <header className="fixed left-0 right-0 top-0 z-40 flex h-14 items-center gap-3 border-b border-border bg-surface px-4 lg:hidden">
        <button
          type="button"
          className="rounded-lg p-2 text-muted hover:bg-surface-2 hover:text-white"
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
          className="fixed inset-0 z-40 bg-black/50 lg:hidden"
          onClick={() => setMobileOpen(false)}
          aria-label="Close menu overlay"
        />
      )}

      <Sidebar mobileOpen={mobileOpen} onNavigate={() => setMobileOpen(false)} />
      <main className="min-h-screen overflow-x-hidden px-4 pb-8 pt-16 lg:ml-60 lg:p-8 lg:pt-8">{children}</main>
    </div>
  );
}
