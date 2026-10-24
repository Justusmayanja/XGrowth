"use client";

import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { SettingsToggle } from "@/components/settings/SettingsToggle";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import type { NotificationSettings } from "@/lib/settings";

type Props = {
  initial: NotificationSettings | null;
};

export function NotificationSettingsCard({ initial }: Props) {
  const [settings, setSettings] = useState<NotificationSettings | null>(initial);
  const [status, setStatus] = useState("");
  const latest = useRef(initial);
  const request = useRef(0);
  const savedTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    latest.current = initial;
    setSettings(initial);
  }, [initial]);

  if (!settings) {
    return null;
  }

  function update<K extends keyof NotificationSettings>(key: K, value: NotificationSettings[K]) {
    const current = latest.current;
    if (!current || current[key] === value) return;
    const next = { ...current, [key]: value };
    const previous = current;
    latest.current = next;
    setSettings(next);
    void persist(next, previous);
  }

  async function persist(next: NotificationSettings, previous: NotificationSettings) {
    const seq = ++request.current;
    setStatus("Saving…");
    try {
      const res = await api<{ notifications: NotificationSettings }>("/account/notifications", {
        method: "PATCH",
        body: JSON.stringify(next),
      });
      if (seq !== request.current) return;
      latest.current = res.notifications;
      setSettings(res.notifications);
      setStatus("Saved");
      if (savedTimer.current) clearTimeout(savedTimer.current);
      savedTimer.current = setTimeout(() => setStatus(""), 2000);
    } catch (err) {
      if (seq !== request.current) return;
      latest.current = previous;
      setSettings(previous);
      setStatus("");
      toast.error(err instanceof Error ? err.message : "Could not save notifications");
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Notifications</CardTitle>
        <CardDescription>
          Choose what Mackie17 tells you about. A switch applies immediately. Email delivery is coming soon.
          {status ? <span className="ml-2 text-foreground">{status}</span> : null}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <div className="space-y-3">
          <SettingsToggle
            id="notify_draft_ready"
            label="Draft ready for review"
            description="When a high-opportunity moment needs your approval."
            checked={settings.notify_draft_ready}
            onChange={(checked) => update("notify_draft_ready", checked)}
          />
          <SettingsToggle
            id="notify_post_results"
            label="Post success or failure"
            description="When Mackie17 publishes or fails to publish on your behalf."
            checked={settings.notify_post_results}
            onChange={(checked) => update("notify_post_results", checked)}
          />
          <SettingsToggle
            id="notify_safety_alerts"
            label="Safety limit warnings"
            description="When you're approaching daily post, reply, or engagement caps."
            checked={settings.notify_safety_alerts}
            onChange={(checked) => update("notify_safety_alerts", checked)}
          />
          <SettingsToggle
            id="notify_weekly_recap"
            label="Weekly growth recap"
            description="Summary of followers, top posts, and suggested experiments."
            checked={settings.notify_weekly_recap}
            onChange={(checked) => update("notify_weekly_recap", checked)}
          />
        </div>
      </CardContent>
    </Card>
  );
}
