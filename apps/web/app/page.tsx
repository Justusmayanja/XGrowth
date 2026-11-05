"use client";

import { useEffect, useState } from "react";
import { openPage } from "@/lib/nav-recovery";
import { LandingPage } from "@/components/landing/LandingPage";
import { LoadingMark } from "@/components/LoadingMark";
import { getToken } from "@/lib/api";
import { getPostAuthPath } from "@/lib/auth";

export default function HomePage() {
  const [ready, setReady] = useState(false);

  useEffect(() => {
    async function init() {
      if (getToken()) {
        try {
          openPage(await getPostAuthPath());
          return;
        } catch {
          // Stale token (e.g. after DB reset) — cleared in api.ts, show landing page
        }
      }
      setReady(true);
    }
    init();
  }, []);

  if (!ready) {
    return (
      <div className="landing-canvas flex min-h-screen items-center justify-center">
        <LoadingMark />
      </div>
    );
  }

  return <LandingPage />;
}
