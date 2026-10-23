"use client";

import Link from "next/link";
import { toast } from "sonner";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import { usePlatform } from "@/lib/platform";
import { Button } from "@/components/ui/button";
import { type SocialBrand, SocialName } from "@/components/SocialLogo";
import type { XConnectStatus } from "@/lib/settings";

type Props = {
  status: XConnectStatus | null;
  onDisconnected?: (status: XConnectStatus) => void;
};

const pending: Array<{ name: string; brand: SocialBrand; href: string; note: string }> = [
  {
    name: "TikTok",
    brand: "tiktok",
    href: "/content-studio",
    note: "The account connection waits on TikTok. Compose is open, so you can write the clip now.",
  },
  {
    name: "YouTube",
    brand: "youtube",
    href: "/content-studio",
    note: "The channel connection waits on YouTube. Compose is open, so you can write the video now.",
  },
];

export function ConnectedAccountCard({ status, onDisconnected }: Props) {
  const platform = usePlatform();

  function connect() {
    if (status?.authorize_url) {
      window.location.href = status.authorize_url;
    }
  }

  async function disconnect() {
    try {
      const next = await api<XConnectStatus>("/connected-accounts/x", { method: "DELETE" });
      onDisconnected?.(next);
      toast.success("X disconnected");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not disconnect X");
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Platforms</CardTitle>
        <CardDescription>
          {platform === "x"
            ? "Connect X to read replies and mentions, and to publish."
            : "Connect the accounts this desk can publish to."}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="rounded-[0.75rem] border border-border p-4">
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <p className="text-sm font-medium">
              <SocialName brand="x">X</SocialName>
            </p>
            <p className="text-xs text-muted">{status?.connected ? "Connected" : "Not connected"}</p>
          </div>
          {status?.connected ? (
            <>
              {status.username && <p className="mt-2 text-sm">@{status.username}</p>}
              <p className="mt-2 text-sm text-muted-foreground">{status.message}</p>
              <Button variant="outline" size="sm" className="mt-3" onClick={() => void disconnect()}>
                Disconnect
              </Button>
            </>
          ) : (
            <>
              <p className="mt-2 text-sm text-muted-foreground">
                {status?.message ?? "Checking the connection…"}
              </p>
              <ul className="mt-3 space-y-1 text-sm text-muted-foreground">
                <li>Read the profile and public metrics</li>
                <li>Save replies and posts for review</li>
                <li>Publish only when you approve, or when automation is on</li>
              </ul>
              {status?.authorize_url ? (
                <Button variant="secondary" className="mt-4" onClick={connect}>
                  Connect X
                </Button>
              ) : (
                <Link href="/content-studio" className="btn btn-secondary mt-4">
                  Draft for X
                </Link>
              )}
            </>
          )}
        </div>

        {platform !== "x" && pending.map((network) => (
          <Link
            key={network.name}
            href={network.href}
            className="block rounded-[0.75rem] border border-border p-4 transition hover:border-[#3f3f46] hover:bg-[#1c1d21]"
          >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <p className="text-sm font-medium">
                <SocialName brand={network.brand}>{network.name}</SocialName>
              </p>
              <p className="text-xs text-[#9ec0ea]">Open Compose</p>
            </div>
            <p className="mt-2 text-sm text-muted-foreground">{network.note}</p>
          </Link>
        ))}
      </CardContent>
    </Card>
  );
}
