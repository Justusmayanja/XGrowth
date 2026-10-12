"use client";

import { FormEvent, useEffect, useState } from "react";
import Link from "next/link";
import { toast } from "sonner";
import { SettingsToggle } from "@/components/settings/SettingsToggle";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { api } from "@/lib/api";
import { AUTOMATION_LEVELS, type AutomationSettings } from "@/lib/settings";

type Props = {
  initial: AutomationSettings | null;
};

export function AutomationSettingsCard({ initial }: Props) {
  const [settings, setSettings] = useState<AutomationSettings | null>(initial);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    setSettings(initial);
  }, [initial]);

  if (!settings) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Automation</CardTitle>
          <CardDescription>Complete onboarding to configure how Mackie17 acts for you</CardDescription>
        </CardHeader>
      </Card>
    );
  }

  function update<K extends keyof AutomationSettings>(key: K, value: AutomationSettings[K]) {
    setSettings((current) => (current ? { ...current, [key]: value } : current));
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!settings) return;
    setSaving(true);
    try {
      const res = await api<{ automation: AutomationSettings; message: string }>(
        "/account/automation",
        { method: "PATCH", body: JSON.stringify(settings) }
      );
      setSettings(res.automation);
      toast.success(res.message);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save automation settings");
    } finally {
      setSaving(false);
    }
  }

  const postingOff = settings.engine_paused || !settings.auto_post_enabled || settings.automation_level < 2;

  return (
    <Card className={settings.engine_paused ? "border-amber-500/30" : ""}>
      <CardHeader>
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle>Automation</CardTitle>
          {settings.engine_paused && <Badge variant="outline">Paused</Badge>}
          {postingOff && !settings.engine_paused && (
            <Badge variant="secondary">Auto-posting off</Badge>
          )}
        </div>
        <CardDescription>
          Control whether Mackie17 posts and replies without asking
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="space-y-4">
          <SettingsToggle
            id="engine_paused"
            label="Pause Mackie17"
            description="Stop posting and replying until you turn this off. Drafts already saved stay put."
            checked={settings.engine_paused}
            onChange={(checked) => update("engine_paused", checked)}
          />

          <div className="space-y-2">
            <Label>Hands-on level</Label>
            <Select
              value={String(settings.automation_level)}
              onValueChange={(v) => v && update("automation_level", Number(v))}
              disabled={settings.engine_paused}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {AUTOMATION_LEVELS.map((level) => (
                  <SelectItem key={level.value} value={String(level.value)}>
                    {level.value}: {level.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {AUTOMATION_LEVELS.find((l) => l.value === settings.automation_level)?.hint}
            </p>
          </div>

          <div className="space-y-2">
            <Label>Posting style</Label>
            <Select
              value={settings.mode}
              onValueChange={(v) => v && update("mode", v)}
              disabled={settings.engine_paused}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="reactive">Reactive — news, events, and trends first</SelectItem>
                <SelectItem value="proactive">Proactive — original posts when things are quiet</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>Max silence before nudge (minutes)</Label>
            <Select
              value={String(settings.max_silence_minutes)}
              onValueChange={(v) => v && update("max_silence_minutes", Number(v))}
              disabled={settings.engine_paused}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {[30, 60, 120, 240, 480].map((m) => (
                  <SelectItem key={m} value={String(m)}>
                    {m} minutes
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-3 border-t border-border pt-4">
            <p className="text-sm font-medium">Per-action controls</p>
            <SettingsToggle
              id="auto_post_enabled"
              label="Allow auto-posting"
              description="When off, Mackie17 only suggests drafts — nothing goes live without you."
              checked={settings.auto_post_enabled}
              onChange={(checked) => update("auto_post_enabled", checked)}
              disabled={settings.engine_paused}
            />
            <SettingsToggle
              id="auto_reply_enabled"
              label="Allow auto-replies"
              description="Let Mackie17 send replies on your behalf when enabled and automation level allows."
              checked={settings.auto_reply_enabled}
              onChange={(checked) => update("auto_reply_enabled", checked)}
              disabled={settings.engine_paused}
            />
            <SettingsToggle
              id="auto_engage_enabled"
              label="Allow engagement suggestions"
              description="When off, Mackie17 won't recommend replies or conversations to join."
              checked={settings.auto_engage_enabled}
              onChange={(checked) => update("auto_engage_enabled", checked)}
              disabled={settings.engine_paused}
            />
          </div>

          <div className="flex flex-wrap gap-2">
            <Button type="submit" disabled={saving}>
              {saving ? "Saving…" : "Save automation"}
            </Button>
            <Link href="/growth-agent">
              <Button type="button" variant="outline">
                View engine activity
              </Button>
            </Link>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
