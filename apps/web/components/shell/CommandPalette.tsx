"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { usePathname } from "next/navigation";
import { useTheme } from "next-themes";
import { NAV, navHrefActive } from "@/lib/nav";
import { openPage } from "@/lib/nav-recovery";
import { usePlatform, withPlatform } from "@/lib/platform";
import { PLATFORM_ORDER, platformName, type Platform } from "@/lib/profile";

type Entry = { id: string; group: string; label: string; hint?: string; run: () => void; current?: boolean };

function matches(query: string, label: string): boolean {
  const q = query.trim().toLowerCase();
  if (!q) return true;
  const text = label.toLowerCase();
  if (text.includes(q)) return true;
  let i = 0;
  for (const char of text) {
    if (char === q[i]) i += 1;
    if (i === q.length) return true;
  }
  return false;
}

export function useCommandShortcut(onOpen: () => void) {
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      const meta = event.metaKey || event.ctrlKey;
      if (meta && event.key.toLowerCase() === "k") {
        event.preventDefault();
        onOpen();
        return;
      }
      if (event.key !== "/" || meta || event.altKey) return;
      const target = event.target as HTMLElement | null;
      const tag = target?.tagName;
      if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || target?.isContentEditable) return;
      event.preventDefault();
      onOpen();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onOpen]);
}

export function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
  const pathname = usePathname();
  const platform = usePlatform();
  const { resolvedTheme, setTheme } = useTheme();
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(0);
  const [hash, setHash] = useState("");
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    setQuery("");
    setActive(0);
    setHash(window.location.hash);
    const timer = window.setTimeout(() => inputRef.current?.focus(), 0);
    return () => window.clearTimeout(timer);
  }, [open]);

  const entries = useMemo<Entry[]>(() => {
    const go = (href: string) => () => {
      onClose();
      openPage(href);
    };
    const pages: Entry[] = NAV[platform].flatMap((group) =>
      group.links.map((link) => {
        const href = link.href.startsWith("/dashboard/") ? link.href : withPlatform(link.href, platform);
        return {
          id: href,
          group: "Pages",
          label: link.label,
          current: navHrefActive(pathname, hash, link.href),
          run: go(href),
        };
      }),
    );
    const switches: Entry[] = PLATFORM_ORDER.map((item: Platform) => ({
      id: `switch-${item}`,
      group: "Platforms",
      label: `Switch to ${platformName(item)}`,
      current: item === platform,
      run: go(`/dashboard/${item}`),
    }));
    return [
      ...pages,
      ...switches,
      { id: "billing", group: "Account", label: "Billing", run: go("/pricing") },
      {
        id: "theme",
        group: "Appearance",
        label: resolvedTheme === "light" ? "Switch to dark" : "Switch to light",
        run: () => {
          setTheme(resolvedTheme === "light" ? "dark" : "light");
          onClose();
        },
      },
    ];
  }, [hash, onClose, pathname, platform, resolvedTheme, setTheme]);

  const shown = entries.filter((entry) => matches(query, entry.label));
  const current = shown[Math.min(active, Math.max(shown.length - 1, 0))];

  useEffect(() => {
    setActive(0);
  }, [query]);

  if (!open) return null;

  return (
    <div className="shell-dialog fixed inset-0 flex items-start justify-center bg-black/50 px-4 pt-[12vh]" onMouseDown={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Search pages"
        className="w-full max-w-lg overflow-hidden rounded-lg border border-border bg-popover text-popover-foreground shadow-lg"
        onMouseDown={(event) => event.stopPropagation()}
        onKeyDown={(event) => {
          if (event.key === "Tab") {
            const nodes = event.currentTarget.querySelectorAll<HTMLElement>("input, button");
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
            return;
          }
          if (event.key === "Escape") {
            event.preventDefault();
            onClose();
          } else if (event.key === "ArrowDown") {
            event.preventDefault();
            setActive((index) => (shown.length ? (index + 1) % shown.length : 0));
          } else if (event.key === "ArrowUp") {
            event.preventDefault();
            setActive((index) => (shown.length ? (index - 1 + shown.length) % shown.length : 0));
          } else if (event.key === "Enter" && current) {
            event.preventDefault();
            current.run();
          }
        }}
      >
        <input
          ref={inputRef}
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Search pages…"
          aria-controls="command-list"
          aria-activedescendant={current ? `command-${current.id}` : undefined}
          className="w-full border-0 border-b border-border bg-transparent px-4 py-3 text-sm outline-none"
        />
        <div ref={listRef} id="command-list" role="listbox" aria-label="Pages" className="max-h-80 overflow-y-auto p-1">
          {shown.length === 0 ? (
            <p className="px-3 py-6 text-center text-sm text-muted-foreground">No matching pages.</p>
          ) : (
            shown.map((entry, index) => {
              const heading = index === 0 || shown[index - 1].group !== entry.group;
              return (
                <div key={entry.id}>
                  {heading ? <p className="px-2 pb-1 pt-2 text-[11px] uppercase tracking-wide text-muted-foreground">{entry.group}</p> : null}
                  <button
                    id={`command-${entry.id}`}
                    type="button"
                    role="option"
                    aria-selected={index === active}
                    className={`flex w-full items-center justify-between rounded-sm px-2 py-2 text-left text-sm ${
                      index === active ? "bg-muted" : ""
                    }`}
                    onMouseEnter={() => setActive(index)}
                    onClick={entry.run}
                  >
                    <span>{entry.label}</span>
                    {entry.current ? <span className="text-xs text-muted-foreground">Current</span> : null}
                  </button>
                </div>
              );
            })
          )}
        </div>
      </div>
    </div>
  );
}
