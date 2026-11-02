"use client";

import { useEffect, useRef } from "react";
import { X } from "lucide-react";
import { BrandLogo } from "@/components/BrandLogo";
import { PlanCard } from "@/components/shell/PlanCard";
import { SidebarNav } from "@/components/shell/Sidebar";

export function MobileNav({ open, onClose, menuRef }: { open: boolean; onClose: () => void; menuRef: React.RefObject<HTMLButtonElement | null> }) {
  const closeRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) return;
    closeRef.current?.focus();
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  const wasOpen = useRef(false);
  useEffect(() => {
    if (wasOpen.current && !open) menuRef.current?.focus();
    wasOpen.current = open;
  }, [open, menuRef]);

  if (!open) return null;

  return (
    <div className="lg:hidden">
      <button type="button" className="shell-overlay fixed inset-0 bg-black/60" onClick={onClose} aria-label="Close menu overlay" />
      <aside
        className="shell-drawer fixed left-0 top-0 flex h-screen w-64 flex-col border-r border-border bg-sidebar"
        role="dialog"
        aria-modal="true"
        aria-label="Menu"
        onKeyDown={(event) => {
          if (event.key !== "Tab") return;
          const nodes = event.currentTarget.querySelectorAll<HTMLElement>("a[href], button:not([disabled])");
          const first = nodes[0];
          const last = nodes[nodes.length - 1];
          if (!first || !last) return;
          if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
          } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
          }
        }}
      >
        <div className="flex h-14 shrink-0 items-center justify-between border-b border-border px-4">
          <BrandLogo className="text-sm" />
          <button
            ref={closeRef}
            type="button"
            className="shell-hit rounded-md p-2 text-muted-foreground hover:bg-surface-2 hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            onClick={onClose}
            aria-label="Close menu"
          >
            <X className="size-5" />
          </button>
        </div>
        <div className="flex min-h-0 flex-1 flex-col px-3 py-4">
          <SidebarNav onNavigate={onClose} />
        </div>
        <div className="shrink-0 p-3">
          <PlanCard />
        </div>
      </aside>
    </div>
  );
}
