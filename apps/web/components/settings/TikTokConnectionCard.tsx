"use client";

import { useEffect, useRef, useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { disconnectTikTok, formatWhen, getTikTokStatus, startTikTokConnect, type TikTokConnectionStatus } from "@/lib/tiktok";

const REASONS: Record<string, string> = {
  denied: "You cancelled the TikTok sign-in.",
  expired: "That sign-in took too long. Try again.",
  already_linked: "That TikTok account is linked to another Mackie17 user.",
  failed: "We couldn't connect TikTok. Try again.",
};

export function TikTokConnectionCard() {
  const headingRef = useRef<HTMLHeadingElement>(null);
  const [status, setStatus] = useState<TikTokConnectionStatus | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [redirecting, setRedirecting] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [disconnecting, setDisconnecting] = useState(false);
  const [avatarFailed, setAvatarFailed] = useState(false);

  useEffect(() => {
    let cancelled = false;
    getTikTokStatus()
      .then((payload) => {
        if (!cancelled) setStatus(payload.data);
      })
      .catch(() => {
        if (!cancelled) {
          setStatus({
            configured: false,
            connected: false,
            display_name: null,
            avatar_url: null,
            connected_at: null,
            needs_reconnect: false,
          });
        }
      });
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const flag = params.get("tiktok");
    if (!flag) return;
    if (flag === "connected") {
      toast.success("TikTok is connected.");
      getTikTokStatus()
        .then((payload) => setStatus(payload.data))
        .catch(() => undefined);
    }
    if (flag === "error") {
      setNotice(REASONS[params.get("reason") ?? ""] ?? REASONS.failed);
    }
    const url = new URL(window.location.href);
    url.searchParams.delete("tiktok");
    url.searchParams.delete("reason");
    window.history.replaceState({}, "", `${url.pathname}${url.search}`);
    headingRef.current?.focus();
  }, []);

  async function connect() {
    setRedirecting(true);
    setNotice(null);
    try {
      const payload = await startTikTokConnect();
      window.location.assign(payload.data.authorize_url);
    } catch (err) {
      setRedirecting(false);
      setNotice(err instanceof Error ? err.message : "We couldn't connect TikTok. Try again.");
    }
  }

  async function disconnect() {
    setDisconnecting(true);
    try {
      const payload = await disconnectTikTok();
      setStatus(payload.data);
      setConfirming(false);
      toast.success("TikTok is disconnected.");
    } catch (err) {
      setNotice(err instanceof Error ? err.message : "We couldn't disconnect TikTok. Try again.");
    } finally {
      setDisconnecting(false);
    }
  }

  const name = status?.display_name || "TikTok";
  const initial = name.slice(0, 1).toUpperCase();

  return (
    <div className="rounded-[0.75rem] border border-border p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 ref={headingRef} tabIndex={-1} className="text-sm font-medium outline-none">
          TikTok
        </h3>
        <p className="text-xs text-muted-foreground">
          {status?.connected ? "Connected" : status?.needs_reconnect ? "Needs sign-in" : "Not connected"}
        </p>
      </div>
      <div aria-live="polite" className="mt-2 text-sm">
        {notice}
      </div>

      {!status ? (
        <p className="mt-2 text-sm text-muted-foreground">Checking the connection…</p>
      ) : !status.configured ? (
        <p className="mt-2 text-sm text-muted-foreground">
          The account connection waits on TikTok. Compose is open, so you can write the clip now.
        </p>
      ) : status.needs_reconnect ? (
        <div className="mt-3 space-y-3">
          <p className="text-sm">TikTok needs you to sign in again.</p>
          <Button type="button" className="min-h-11" disabled={redirecting} aria-busy={redirecting} onClick={() => void connect()}>
            {redirecting && <Loader2 className="motion-safe:animate-spin" aria-hidden />}
            Reconnect
          </Button>
        </div>
      ) : status.connected ? (
        <div className="mt-3 space-y-3">
          <div className="flex items-center gap-3">
            {status.avatar_url && !avatarFailed ? (
              <img
                src={status.avatar_url}
                alt=""
                className="size-10 rounded-full border border-border object-cover"
                onError={() => setAvatarFailed(true)}
              />
            ) : (
              <span className="flex size-10 items-center justify-center rounded-full border border-border text-sm" aria-hidden>
                {initial}
              </span>
            )}
            <div>
              <p className="text-sm font-medium">{status.display_name || "TikTok account"}</p>
              {status.connected_at && (
                <p className="text-xs text-muted-foreground">Connected {formatWhen(status.connected_at, "UTC")}.</p>
              )}
            </div>
          </div>
          <p className="text-sm text-muted-foreground">Connected. Posting still needs your video file.</p>
          {confirming ? (
            <div role="dialog" aria-labelledby="disconnect-tiktok-title" className="max-w-md space-y-3 rounded-sm border border-border p-4">
              <p id="disconnect-tiktok-title" className="text-sm">
                Disconnect TikTok? Confirmed clips stay queued but can't post until you reconnect.
              </p>
              <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline" className="min-h-11" disabled={disconnecting} onClick={() => void disconnect()}>
                  {disconnecting && <Loader2 className="motion-safe:animate-spin" aria-hidden />}
                  Disconnect
                </Button>
                <Button type="button" variant="ghost" className="min-h-11" onClick={() => setConfirming(false)}>
                  Keep connected
                </Button>
              </div>
            </div>
          ) : (
            <Button type="button" variant="outline" className="min-h-11" onClick={() => setConfirming(true)}>
              Disconnect
            </Button>
          )}
        </div>
      ) : (
        <div className="mt-3 space-y-3">
          <p className="text-sm text-muted-foreground">
            Connecting lets us post clips you've confirmed, once video posting is ready. Nothing is posted by connecting.
          </p>
          <Button type="button" className="min-h-11" disabled={redirecting} aria-busy={redirecting} onClick={() => void connect()}>
            {redirecting && <Loader2 className="motion-safe:animate-spin" aria-hidden />}
            Connect TikTok
          </Button>
        </div>
      )}
    </div>
  );
}
