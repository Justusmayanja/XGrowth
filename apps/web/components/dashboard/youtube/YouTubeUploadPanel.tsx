"use client";

import { useState } from "react";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { startYouTubeUploadConsent, type YouTubeDesk, type YouTubeItem } from "@/lib/youtube";

const PRIVACY_LABEL: Record<string, string> = {
  private: "Private",
  unlisted: "Unlisted",
  public: "Public",
};

export function YouTubeUploadPanel({
  desk,
  item,
  busy,
  onSend,
}: {
  desk: YouTubeDesk;
  item: YouTubeItem;
  busy: boolean;
  onSend: (privacy: "public" | "unlisted" | "private") => void;
}) {
  const upload = desk.upload;
  const row = item.package?.upload;
  const auditApproved = upload?.audit_approved === true;
  const [chosen, setChosen] = useState<"public" | "unlisted" | "private">("private");
  const [consentNotice, setConsentNotice] = useState("");
  const [redirecting, setRedirecting] = useState(false);
  const privacy = auditApproved ? chosen : "private";
  const canSend = item.status === "queued" && item.package?.render?.ready === true;
  const retry = row?.retryable === true && (row.state === "failed" || row.state === "queued");

  async function allowUploads() {
    setRedirecting(true);
    setConsentNotice("");
    try {
      const payload = await startYouTubeUploadConsent();
      window.location.assign(payload.data.authorize_url);
    } catch (error) {
      setRedirecting(false);
      setConsentNotice(error instanceof Error ? error.message : "Upload permission was not granted.");
    }
  }

  return (
    <div className="space-y-3 rounded-md border border-border p-3">
      <p className="text-sm font-medium">Send to YouTube</p>
      <p className="text-sm text-muted-foreground">
        {upload?.scope.connected
          ? upload.scope.upload_granted
            ? "Upload permission is on. It was granted separately from the read-only channel connection."
            : "The channel connection is still read-only. Uploads need a second consent."
          : "No YouTube channel is connected. Connect it in Settings, then allow uploads as a second step."}
      </p>
      {upload?.scope.needs_upload_consent && (
        <Button type="button" size="sm" variant="outline" disabled={redirecting} aria-busy={redirecting} onClick={() => void allowUploads()}>
          {redirecting && <Loader2 className="motion-safe:animate-spin" aria-hidden />}
          Allow uploads
        </Button>
      )}
      {consentNotice && <p className="text-sm text-muted-foreground">{consentNotice}</p>}

      <p className="text-sm text-muted-foreground">
        {upload?.allowance.plan_found
          ? `${upload.allowance.used} of ${upload.allowance.limit} videos used this month. ${upload.allowance.remaining} left. This monthly allowance is separate from the shared daily upload budget.`
          : "No active YouTube plan. Sending stays off until a plan includes a video allowance."}
      </p>
      <p className="text-sm text-muted-foreground">
        {upload
          ? `Shared uploads today: ${upload.daily_budget.used} of ${upload.daily_budget.limit}. ${upload.daily_budget.remaining} left. The budget resets on ${upload.daily_budget.resets_on}.`
          : "The shared daily upload budget is not loaded."}
      </p>
      <p className="text-sm text-muted-foreground">{upload?.privacy_policy}</p>

      {item.status === "posted" && item.posted_video_id ? (
        <div className="text-sm">
          <p>Posted. YouTube applied {PRIVACY_LABEL[item.posted_privacy ?? ""] ?? "an unknown visibility"}.</p>
          <p className="text-muted-foreground">Video id {item.posted_video_id}</p>
        </div>
      ) : (
        <div className="space-y-2">
          {item.status === "queued" && (
            <p className="text-sm text-muted-foreground">Queued means approved on this server. It does not mean the video was posted.</p>
          )}
          {row?.note && <p className="text-sm">{row.note}</p>}
          {row?.next_attempt_on && (
            <p className="text-sm text-muted-foreground">Next send after the daily budget resets: {row.next_attempt_on}.</p>
          )}
          {auditApproved ? (
            <label className="block text-sm">
              Visibility to request
              <select
                className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2"
                value={chosen}
                onChange={(event) => setChosen(event.target.value as "public" | "unlisted" | "private")}
              >
                <option value="private">Private</option>
                <option value="unlisted">Unlisted</option>
                <option value="public">Public</option>
              </select>
            </label>
          ) : (
            <p className="text-sm">Visibility requested: private.</p>
          )}
          {canSend ? (
            <Button type="button" size="sm" variant="secondary" disabled={busy} onClick={() => onSend(privacy)}>
              {busy ? "Sending…" : retry ? "Try again" : "Send to YouTube"}
            </Button>
          ) : (
            <p className="text-sm text-muted-foreground">
              {item.status === "waiting"
                ? "Ship it only approves this package. Sending stays unavailable until you approve it and a local file is ready."
                : "Sending waits until this package is approved and the local file is ready."}
            </p>
          )}
        </div>
      )}
    </div>
  );
}
