"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { LoadingMark } from "@/components/LoadingMark";
import { api, getToken } from "@/lib/api";
import { PLATFORM_ORDER, type SetupState } from "@/lib/profile";

export default function DashboardPage() {
  const router = useRouter();
  const [error, setError] = useState("");

  useEffect(() => {
    async function load() {
      try {
        const state = await api<SetupState>("/account/setup");
        if (!state.onboarding_completed) {
          router.replace("/onboarding");
          return;
        }
        const first = PLATFORM_ORDER.find((platform) => state.platforms.includes(platform)) ?? "x";
        router.replace(`/dashboard/${first}`);
      } catch (err) {
        if (!getToken()) {
          router.replace("/login");
          return;
        }
        setError(err instanceof Error ? err.message : "Could not load today.");
      }
    }
    load();
  }, [router]);

  if (error) return <p className="text-muted-foreground">{error}</p>;
  return <LoadingMark className="min-h-[40vh]" />;
}
