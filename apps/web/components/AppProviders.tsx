"use client";

import { useEffect } from "react";
import { Toaster } from "@/components/ui/sonner";

const RELOAD_KEY = "mackie17-chunk-reload";

function isChunkFailure(message: string) {
  return /ChunkLoadError|Loading chunk|Failed to fetch dynamically imported module|Importing a module script failed/i.test(
    message,
  );
}

export function AppProviders({ children }: { children: React.ReactNode }) {
  useEffect(() => {
    const timer = window.setTimeout(() => {
      sessionStorage.removeItem(RELOAD_KEY);
    }, 8000);

    const recover = (message: string) => {
      if (!isChunkFailure(message)) return;
      if (sessionStorage.getItem(RELOAD_KEY) === "1") return;
      sessionStorage.setItem(RELOAD_KEY, "1");
      window.location.reload();
    };

    const onRejection = (event: PromiseRejectionEvent) => {
      const reason = event.reason;
      const message = reason instanceof Error ? `${reason.name} ${reason.message}` : String(reason ?? "");
      recover(message);
    };

    window.addEventListener("unhandledrejection", onRejection);
    return () => {
      window.clearTimeout(timer);
      window.removeEventListener("unhandledrejection", onRejection);
    };
  }, []);

  return (
    <>
      {children}
      <Toaster richColors position="bottom-right" theme="dark" />
    </>
  );
}
