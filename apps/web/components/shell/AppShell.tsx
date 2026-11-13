"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { PageContainer } from "@/components/layout/PageContainer";
import { BillingProvider } from "@/components/shell/BillingState";
import { CommandPalette, useCommandShortcut } from "@/components/shell/CommandPalette";
import { MobileNav } from "@/components/shell/MobileNav";
import { Sidebar } from "@/components/shell/Sidebar";
import { TopBar } from "@/components/shell/TopBar";
import { TrialBanner } from "@/components/shell/TrialBanner";

export function AppShell({ children }: { children: React.ReactNode }) {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [paletteOpen, setPaletteOpen] = useState(false);
  const menuRef = useRef<HTMLButtonElement>(null);
  const openPalette = useCallback(() => setPaletteOpen(true), []);
  useCommandShortcut(openPalette);

  useEffect(() => {
    document.body.style.overflow = mobileOpen || paletteOpen ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [mobileOpen, paletteOpen]);

  return (
    <BillingProvider>
      <div className="desk min-h-screen bg-background text-foreground">
        <a
          href="#main"
          className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[80] focus:rounded-md focus:bg-primary focus:px-3 focus:py-2 focus:text-sm focus:text-primary-foreground"
        >
          Skip to content
        </a>
        <TopBar menuRef={menuRef} onMenu={() => setMobileOpen(true)} onSearch={openPalette} />
        <Sidebar />
        <MobileNav open={mobileOpen} onClose={() => setMobileOpen(false)} menuRef={menuRef} />
        <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
        <div className="lg:pl-64">
          <div className="pt-14 lg:pt-16">
            <TrialBanner />
            <main id="main" tabIndex={-1} className="min-h-[calc(100vh-3.5rem)] overflow-x-hidden outline-none lg:min-h-[calc(100vh-4rem)]">
              <PageContainer>{children}</PageContainer>
            </main>
          </div>
        </div>
      </div>
    </BillingProvider>
  );
}

export { Sidebar } from "@/components/shell/Sidebar";
