"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { LandingPage } from "@/components/landing/LandingPage";
import { getToken } from "@/lib/api";
import { getPostAuthPath } from "@/lib/auth";

export default function HomePage() {
  const router = useRouter();
  const [ready, setReady] = useState(false);

  useEffect(() => {
    async function init() {
      if (getToken()) {
        router.replace(await getPostAuthPath());
        return;
      }
      setReady(true);
    }
    init();
  }, [router]);

  if (!ready) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <p className="text-muted">Loading…</p>
      </div>
    );
  }

  return <LandingPage />;
}
