"use client";

import { useEffect, useRef, useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  disconnectYouTube,
  getYouTubeStatus,
  startYouTubeConnect,
  startYouTubeUploadConsent,
  type YouTubeConnectionStatus,
} from "@/lib/youtube";

const REASONS: Record<string, string> = {
  denied: "You cancelled the YouTube connection.",
  expired: "That connection request expired. Try again.",
  already_linked: "That YouTube channel is linked to another Mackie17 user.",
  failed: "We couldn't connect YouTube. Try again.",
  upload_denied: "You cancelled the upload permission. The read-only connection is unchanged.",
  upload_expired: "That upload request expired. The read-only connection is unchanged.",
  upload_failed: "YouTube did not grant upload permission. The read-only connection is unchanged.",
};

export function YouTubeConnectionCard() {
  const headingRef = useRef<HTMLHeadingElement>(null);
  const [status, setStatus] = useState<YouTubeConnectionStatus | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [redirecting, setRedirecting] = useState(false);
  const [allowing, setAllowing] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [disconnecting, setDisconnecting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    getYouTubeStatus()
      .then((payload) => {
        if (!cancelled) setStatus(payload.data);
      })
      .catch((error) => {
        if (!cancelled) setNotice(error instanceof Error ? error.message : "Could not check the YouTube connection.");
      });
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const flag = params.get("youtube");
    if (!flag) return;
    if (flag === "connected") {
      toast.success("YouTube is connected.");
      getYouTubeStatus().then((payload) => setStatus(payload.data)).catch(() => undefined);
    } else if (flag === "upload") {
      toast.success("Upload permission is saved. Sending a video is still a separate step.");
      getYouTubeStatus().then((payload) => setStatus(payload.data)).catch(() => undefined);
    } else if (flag === "error") {
      setNotice(REASONS[params.get("reason") ?? ""] ?? REASONS.failed);
    }
    const url = new URL(window.location.href);
    url.searchParams.delete("youtube");
    url.searchParams.delete("reason");
    window.history.replaceState({}, "", `${url.pathname}${url.search}`);
    headingRef.current?.focus();
  }, []);

  async function connect() {
    setRedirecting(true);
    setNotice(null);
    try {
      const payload = await startYouTubeConnect();
      window.location.assign(payload.data.authorize_url);
    } catch (error) {
      setRedirecting(false);
      setNotice(error instanceof Error ? error.message : REASONS.failed);
    }
  }

  async function allowUploads() {
    setAllowing(true);
    setNotice(null);
    try {
      const payload = await startYouTubeUploadConsent();
      window.location.assign(payload.data.authorize_url);
    } catch (error) {
      setAllowing(false);
      setNotice(error instanceof Error ? error.message : REASONS.upload_failed);
    }
  }

  async function disconnect() {
    setDisconnecting(true);
    try {
      const payload = await disconnectYouTube();
      setStatus(payload.data);
      setConfirming(false);
      toast.success("YouTube is disconnected.");
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "We couldn't disconnect YouTube. Try again.");
    } finally {
      setDisconnecting(false);
    }
  }

  return (
    <div className="rounded-[0.75rem] border border-border p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 ref={headingRef} tabIndex={-1} className="text-sm font-medium outline-none">
          YouTube
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
          YouTube channel linking is not configured on this server. Your local planning desk still works.
        </p>
      ) : status.needs_reconnect ? (
        <div className="mt-3 space-y-3">
          <p className="text-sm">YouTube needs you to sign in again before channel metrics can load.</p>
          <Button type="button" className="min-h-11" disabled={redirecting} aria-busy={redirecting} onClick={() => void connect()}>
            {redirecting && <Loader2 className="motion-safe:animate-spin" aria-hidden />}
            Reconnect YouTube
          </Button>
        </div>
      ) : status.connected ? (
        <div className="mt-3 space-y-3">
          <div>
            <p className="text-sm font-medium">{status.display_name || "YouTube channel"}</p>
            <p className="text-sm text-muted-foreground">
              {status.upload_granted
                ? "This channel can be read, and uploads were allowed by a separate consent. Sending a video is still a separate step."
                : "Read-only access loads subscribers, views, and watch time. It cannot upload or publish. Uploads need a second consent and do not change this grant until you allow them."}
            </p>
          </div>
          {status.needs_upload_consent && (
            <Button type="button" className="min-h-11" disabled={allowing} aria-busy={allowing} onClick={() => void allowUploads()}>
              {allowing && <Loader2 className="motion-safe:animate-spin" aria-hidden />}
              Allow uploads
            </Button>
          )}
          {status.metrics.error ? (
            <p className="text-sm text-muted-foreground">{status.metrics.error}</p>
          ) : (
            <div className="grid gap-2 text-sm sm:grid-cols-3">
              <p><span className="text-muted-foreground">Subscribers</span><br />{status.metrics.subscribers?.toLocaleString() ?? "—"}</p>
              <p><span className="text-muted-foreground">Watch hours</span><br />{status.metrics.watch_hours?.toLocaleString() ?? "—"}</p>
              <p><span className="text-muted-foreground">Views</span><br />{status.metrics.views?.toLocaleString() ?? "—"}</p>
            </div>
          )}
          {confirming ? (
            <div role="dialog" aria-labelledby="disconnect-youtube-title" className="max-w-md space-y-3 rounded-sm border border-border p-4">
              <p id="disconnect-youtube-title" className="text-sm">
                Disconnect YouTube? Your local packages stay in place, but channel metrics will stop loading.
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
            Connect your channel to read subscribers, views, and watch time. This step cannot upload or publish videos. Allowing uploads is a second consent after the channel is connected.
          </p>
          <Button type="button" className="min-h-11" disabled={redirecting} aria-busy={redirecting} onClick={() => void connect()}>
            {redirecting && <Loader2 className="motion-safe:animate-spin" aria-hidden />}
            Connect YouTube
          </Button>
        </div>
      )}
    </div>
  );
}
