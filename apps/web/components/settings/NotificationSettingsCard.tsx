"use client";

import { FormEvent, useEffect, useState } from "react";
import { toast } from "sonner";
import { SettingsToggle } from "@/components/settings/SettingsToggle";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import type { NotificationSettings } from "@/lib/settings";

type Props = {
  initial: NotificationSettings | null;
};

export function NotificationSettingsCard({ initial }: Props) {
  const [settings, setSettings] = useState<NotificationSettings | null>(initial);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    setSettings(initial);
  }, [initial]);

  if (!settings) {
    return null;
  }

  function update<K extends keyof NotificationSettings>(key: K, value: NotificationSettings[K]) {
    setSettings((current) => (current ? { ...current, [key]: value } : current));
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!settings) return;
    setSaving(true);
    try {
      const res = await api<{ notifications: NotificationSettings; message: string }>(
        "/account/notifications",
        { method: "PATCH", body: JSON.stringify(settings) }
      );
      setSettings(res.notifications);
      toast.success(res.message);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save notifications");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Notifications</CardTitle>
        <CardDescription>
          Choose what Xpulse tells you about. Email delivery is coming soon — preferences are saved now.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="space-y-3">
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
            description="When Xpulse publishes or fails to publish on your behalf."
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
          <Button type="submit" disabled={saving}>
            {saving ? "Saving…" : "Save notifications"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
