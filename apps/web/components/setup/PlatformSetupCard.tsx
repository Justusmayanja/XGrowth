"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { SocialName } from "@/components/SocialLogo";
import { api } from "@/lib/api";
import {
  formatMultiLabels,
  type Platform,
  PLATFORM_GOALS,
  PLATFORM_ORDER,
  platformName,
  type SetupState,
} from "@/lib/profile";

function useSetupState() {
  const [state, setState] = useState<SetupState | null>(null);

  useEffect(() => {
    api<SetupState>("/account/setup")
      .then(setState)
      .catch(() => setState(null));
  }, []);

  return state;
}

export function PendingPlatformsCard() {
  const state = useSetupState();
  if (!state) return null;

  const pending = state.platforms.filter((platform) => !state.setups[platform]?.completed);
  if (pending.length === 0) return null;

  return (
    <Card>
      <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
        <div>
          <p className="text-sm font-medium">
            Finish {pending.map(platformName).join(" and ")}
          </p>
          <p className="text-sm text-muted-foreground">
            {pending.length === 1 ? "It takes" : "Each takes"} about a minute. Until then, drafts for{" "}
            {pending.length === 1 ? "it use" : "them use"} your shared profile only.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {pending.map((platform) => (
            <Link key={platform} href={`/onboarding?platform=${platform}`}>
              <Button>Finish {platformName(platform)}</Button>
            </Link>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}

export function PlatformSetupCard() {
  const state = useSetupState();
  if (!state) return null;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Platforms</CardTitle>
        <CardDescription>
          Each platform has its own goals. Your niche, description, audience, and voice are shared.
        </CardDescription>
      </CardHeader>
      <CardContent className="divide-y divide-border">
        {PLATFORM_ORDER.map((platform: Platform) => {
          const chosen = state.platforms.includes(platform);
          const setup = state.setups[platform];
          const goals = setup?.goals.length
            ? formatMultiLabels(setup.goals, PLATFORM_GOALS[platform])
            : null;

          return (
            <div key={platform} className="flex flex-wrap items-center justify-between gap-3 py-4 first:pt-0 last:pb-0">
              <div className="min-w-0">
                <p className="font-medium">
                  <SocialName brand={platform}>{platformName(platform)}</SocialName>
                </p>
                <p className="text-sm text-muted-foreground">
                  {!chosen
                    ? "Not in your setup."
                    : setup?.completed
                      ? goals
                      : "Chosen, not set up yet."}
                </p>
              </div>
              <Link href={`/onboarding?platform=${platform}`}>
                <Button variant={chosen && setup?.completed ? "outline" : "default"} size="sm">
                  {!chosen ? `Add ${platformName(platform)}` : setup?.completed ? "Edit goals" : "Finish setup"}
                </Button>
              </Link>
            </div>
          );
        })}
      </CardContent>
    </Card>
  );
}
