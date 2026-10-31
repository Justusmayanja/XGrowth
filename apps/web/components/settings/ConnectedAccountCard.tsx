"use client";

import Link from "next/link";
import { useEffect, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import { usePlatform } from "@/lib/platform";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { type SocialBrand, SocialName } from "@/components/SocialLogo";
import { TikTokConnectionCard } from "@/components/settings/TikTokConnectionCard";
import type { XConnectStatus } from "@/lib/settings";

type Props = {
  status: XConnectStatus | null;
  onDisconnected?: (status: XConnectStatus) => void;
};

const pending: Array<{ name: string; brand: SocialBrand; href: string; note: string }> = [
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

  async function disconnect(accountId?: number) {
    try {
      const path = accountId ? `/connected-accounts/x?account=${accountId}` : "/connected-accounts/x";
      const next = await api<XConnectStatus>(path, { method: "DELETE" });
      onDisconnected?.(next);
      toast.success(accountId ? "That X account was disconnected" : "X disconnected");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not disconnect X");
    }
  }

  const several = (status?.accounts?.length ?? 0) > 1;

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
              {!several && (
                <Button variant="outline" size="sm" className="mt-3" onClick={() => void disconnect()}>
                  Disconnect
                </Button>
              )}
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

        {several && status?.accounts && (
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Each account posts in its own voice. Leave a field blank to keep using the voice on your profile.
            </p>
            {status.accounts.map((account, index) => (
              <AccountVoiceForm
                key={account.id}
                account={account}
                first={index === 0}
                onSaved={onDisconnected}
                onDisconnect={() => void disconnect(account.id)}
              />
            ))}
          </div>
        )}

        <TikTokConnectionCard />

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

function AccountVoiceForm({
  account,
  first,
  onSaved,
  onDisconnect,
}: {
  account: { id: number; username: string | null; niche: string; description: string; audience: string };
  first: boolean;
  onSaved?: (status: XConnectStatus) => void;
  onDisconnect: () => void;
}) {
  const [niche, setNiche] = useState(account.niche);
  const [description, setDescription] = useState(account.description);
  const [audience, setAudience] = useState(account.audience);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    setNiche(account.niche);
    setDescription(account.description);
    setAudience(account.audience);
  }, [account.niche, account.description, account.audience]);

  async function save(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    try {
      const next = await api<XConnectStatus>(`/connected-accounts/x/${account.id}/voice`, {
        method: "PUT",
        body: JSON.stringify({ niche, description, audience }),
      });
      onSaved?.(next);
      toast.success(`Saved the voice for @${account.username || "this account"}`);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save that voice");
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="space-y-3 rounded-[0.75rem] border border-border p-4" onSubmit={(event) => void save(event)}>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p className="text-sm font-medium">@{account.username || "account"}</p>
        <button type="button" className="text-xs text-muted-foreground hover:text-foreground" onClick={onDisconnect}>
          Disconnect
        </button>
      </div>
      <p className="text-xs text-muted-foreground">
        {first ? "This is the main account. A blank field keeps the voice on your profile." : "This account posts from these words, not the main account's."}
      </p>
      <label className="block text-xs text-muted-foreground">
        Niche
        <Input className="mt-1" value={niche} maxLength={80} onChange={(event) => setNiche(event.target.value)} />
      </label>
      <label className="block text-xs text-muted-foreground">
        What this account is for
        <Textarea className="mt-1" rows={3} value={description} maxLength={500} onChange={(event) => setDescription(event.target.value)} />
      </label>
      <label className="block text-xs text-muted-foreground">
        Who it is for
        <Input className="mt-1" value={audience} maxLength={200} onChange={(event) => setAudience(event.target.value)} />
      </label>
      <Button type="submit" size="sm" variant="secondary" disabled={busy}>
        {busy ? "Saving…" : "Save this voice"}
      </Button>
    </form>
  );
}
