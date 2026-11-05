"use client";

import { useEffect, useState } from "react";
import { LoadingMark } from "@/components/LoadingMark";
import { api, getToken } from "@/lib/api";
import { openPage } from "@/lib/nav-recovery";
import { PLATFORM_ORDER, type SetupState } from "@/lib/profile";

export default function DashboardPage() {
  const [error, setError] = useState("");

  useEffect(() => {
    async function load() {
      try {
        const state = await api<SetupState>("/account/setup");
        if (!state.onboarding_completed) {
          openPage("/onboarding");
          return;
        }
        const first = PLATFORM_ORDER.find((platform) => state.platforms.includes(platform)) ?? "x";
        openPage(`/dashboard/${first}`);
      } catch (err) {
        if (!getToken()) {
          openPage("/login");
          return;
        }
        setError(err instanceof Error ? err.message : "Could not load today.");
      }
    }
    load();
  }, []);

  if (error) return <p className="text-muted-foreground">{error}</p>;
  return <LoadingMark className="min-h-[40vh]" />;
}
