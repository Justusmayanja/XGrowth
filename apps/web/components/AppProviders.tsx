"use client";

import { useEffect } from "react";
import { ThemeProvider } from "next-themes";
import { Toaster } from "@/components/ui/sonner";
import { destinationsMatch, navHrefAge, openPage, peekNavHref, rememberNavHref, takeNavHref } from "@/lib/nav-recovery";

const RELOAD_KEY = "mackie17-chunk-reload";

function isChunkFailure(message: string) {
  return /ChunkLoadError|Loading chunk|Failed to fetch dynamically imported module|Importing a module script failed|Failed to fetch RSC|Invalid RSC payload|Cannot find module '\.\/\d+/i.test(
    message,
  );
}

function currentPath() {
  return `${window.location.pathname}${window.location.search}${window.location.hash}`;
}

function recover(message: string) {
  if (!isChunkFailure(message)) return;
  if (sessionStorage.getItem(RELOAD_KEY) === "1") return;
  sessionStorage.setItem(RELOAD_KEY, "1");
  const href = takeNavHref();
  if (href && href !== currentPath()) {
    window.location.assign(href);
    return;
  }
  window.location.reload();
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input;
  if (input instanceof URL) return input.href;
  if (typeof Request !== "undefined" && input instanceof Request) return input.url;
  return "";
}

function isAppAsset(url: string) {
  return url.includes("_rsc=") || url.includes("/_next/static/chunks/");
}

export function AppProviders({ children }: { children: React.ReactNode }) {
  useEffect(() => {
    const timer = window.setTimeout(() => {
      sessionStorage.removeItem(RELOAD_KEY);
    }, 8000);

    const onRejection = (event: PromiseRejectionEvent) => {
      const reason = event.reason;
      const message = reason instanceof Error ? `${reason.name} ${reason.message}` : String(reason ?? "");
      recover(message);
    };

    const onError = (event: ErrorEvent) => {
      recover(`${event.message} ${event.error instanceof Error ? event.error.message : ""}`);
    };

    const originalFetch = window.fetch.bind(window);
    window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = requestUrl(input);
      try {
        const res = await originalFetch(input, init);
        if (isAppAsset(url) && (res.status === 404 || res.status === 500)) {
          recover(`Failed to fetch RSC ${res.status}`);
        }
        return res;
      } catch (err) {
        if (isAppAsset(url)) {
          recover(err instanceof Error ? err.message : "Failed to fetch RSC");
        }
        throw err;
      }
    };

    const onClick = (event: MouseEvent) => {
      if (event.defaultPrevented) return;
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
      const target = event.target;
      if (!(target instanceof Element)) return;
      const anchor = target.closest("a[href]");
      if (!(anchor instanceof HTMLAnchorElement)) return;
      if (anchor.target && anchor.target !== "_self") return;
      const href = anchor.getAttribute("href");
      if (!href || href.startsWith("mailto:") || href.startsWith("tel:") || href.startsWith("javascript:")) return;
      const url = new URL(anchor.href, window.location.href);
      if (url.origin !== window.location.origin) return;
      const next = `${url.pathname}${url.search}${url.hash}`;
      if (destinationsMatch(next)) return;
      rememberNavHref(next);
    };

    const watchdog = window.setInterval(() => {
      const href = peekNavHref();
      if (!href) return;
      if (destinationsMatch(href)) {
        takeNavHref();
        return;
      }
      if (navHrefAge() >= 1500) {
        recover("Failed to fetch RSC timeout");
      }
    }, 250);

    window.addEventListener("unhandledrejection", onRejection);
    window.addEventListener("error", onError);
    document.addEventListener("click", onClick, true);
    return () => {
      window.clearTimeout(timer);
      window.clearInterval(watchdog);
      window.fetch = originalFetch;
      window.removeEventListener("unhandledrejection", onRejection);
      window.removeEventListener("error", onError);
      document.removeEventListener("click", onClick, true);
    };
  }, []);

  return (
    <ThemeProvider attribute="class" defaultTheme="dark" enableSystem={false} storageKey="mackie17-theme" disableTransitionOnChange>
      {children}
      <Toaster richColors position="bottom-right" />
    </ThemeProvider>
  );
}
