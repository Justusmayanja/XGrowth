"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { Activity, LayoutDashboard, Menu, ScrollText, Settings, Settings2, Users, X } from "lucide-react";
import { BrandLogo } from "@/components/BrandLogo";
import { clearToken } from "@/lib/api";
import { cn } from "@/lib/utils";

const links = [
  { href: "/admin", label: "Overview", icon: LayoutDashboard, exact: true },
  { href: "/admin/customers", label: "Customers", icon: Users },
  { href: "/admin/activity", label: "Activity", icon: Activity },
  { href: "/admin/ai-rules", label: "Drafting rules", icon: ScrollText },
  { href: "/admin/platform-settings", label: "Platform Settings", icon: Settings },
  { href: "/admin/operations", label: "Operations", icon: Settings2 },
];

function isActive(pathname: string, href: string, exact?: boolean) {
  if (exact) return pathname === href;
  return pathname === href || pathname.startsWith(`${href}/`);
}

type AdminShellProps = {
  children: React.ReactNode;
};

export function AdminShell({ children }: AdminShellProps) {
  const pathname = usePathname();
  const router = useRouter();
  const [mobileOpen, setMobileOpen] = useState(false);

  useEffect(() => {
    document.body.style.overflow = mobileOpen ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [mobileOpen]);

  async function logout() {
    clearToken();
    router.push("/");
  }

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
        <span className="text-sm font-semibold">Platform Console</span>
      </header>

      {mobileOpen && (
        <button
          type="button"
          className="fixed inset-0 z-40 bg-black/50 lg:hidden"
          onClick={() => setMobileOpen(false)}
          aria-label="Close menu overlay"
        />
      )}

      <aside
        className={cn(
          "fixed left-0 top-0 z-50 flex h-screen w-60 flex-col border-r border-border bg-surface p-4",
          "max-lg:transition-transform max-lg:duration-200",
          mobileOpen ? "max-lg:translate-x-0" : "max-lg:-translate-x-full"
        )}
      >
        <div className="mb-6 flex items-center justify-between px-2">
          <div>
            <BrandLogo className="text-base" />
            <p className="mt-1 text-xs font-medium text-primary">Platform Console</p>
            <p className="text-xs text-muted-foreground">Customer accounts and connected platforms</p>
          </div>
          <button
            type="button"
            className="rounded-lg p-1 text-muted hover:bg-surface-2 hover:text-white lg:hidden"
            onClick={() => setMobileOpen(false)}
            aria-label="Close menu"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <nav className="flex flex-1 flex-col gap-1 overflow-y-auto">
          {links.map((link) => {
            const Icon = link.icon;
            return (
              <Link
                key={link.href}
                href={link.href}
                onClick={() => setMobileOpen(false)}
                className={cn(
                  "flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition",
                  isActive(pathname, link.href, link.exact)
                    ? "bg-primary/15 text-foreground"
                    : "text-muted hover:bg-surface-2 hover:text-foreground"
                )}
              >
                <Icon className="h-4 w-4 shrink-0" />
                {link.label}
              </Link>
            );
          })}
        </nav>

        <button onClick={logout} className="btn btn-secondary w-full text-sm">
          Sign out
        </button>
      </aside>

      <main className="min-h-screen overflow-x-hidden px-4 pb-8 pt-16 lg:ml-60 lg:p-8 lg:pt-8">{children}</main>
    </div>
  );
}
