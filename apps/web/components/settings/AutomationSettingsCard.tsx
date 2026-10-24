"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { toast } from "sonner";
import { SettingsToggle } from "@/components/settings/SettingsToggle";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import { AUTOMATION_LEVELS, type AutomationSettings } from "@/lib/settings";
import { cn } from "@/lib/utils";

type Props = {
  initial: AutomationSettings | null;
  xConnected?: boolean;
};

const CONTROLLED = AUTOMATION_LEVELS.filter((level) => level.value < 3);

export function AutomationSettingsCard({ initial, xConnected = false }: Props) {
  const [settings, setSettings] = useState<AutomationSettings | null>(initial);
  const [saveState, setSaveState] = useState("");
  const latest = useRef(initial);
  const request = useRef(0);
  const savedTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    latest.current = initial;
    setSettings(initial);
  }, [initial]);

  if (!settings) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>X posting</CardTitle>
          <CardDescription>Complete onboarding to choose full automation or controlled posting</CardDescription>
        </CardHeader>
      </Card>
    );
  }

  function patch(partial: Partial<AutomationSettings>) {
    const current = latest.current;
    if (!current) return;
    const next = { ...current, ...partial };
    if (JSON.stringify(next) === JSON.stringify(current)) return;
    const previous = current;
    latest.current = next;
    setSettings(next);
    void persist(next, previous);
  }

  async function persist(next: AutomationSettings, previous: AutomationSettings) {
    const seq = ++request.current;
    setSaveState("Saving…");
    try {
      const res = await api<{ automation: AutomationSettings }>("/account/automation", {
        method: "PATCH",
        body: JSON.stringify(next),
      });
      if (seq !== request.current) return;
      latest.current = res.automation;
      setSettings(res.automation);
      setSaveState("Saved");
      if (savedTimer.current) clearTimeout(savedTimer.current);
      savedTimer.current = setTimeout(() => setSaveState(""), 2000);
      api("/growth-agent/evaluate", { method: "POST" }, 60000).catch(() => {
        // Saved rules still apply on the next scheduled check.
      });
    } catch (err) {
      if (seq !== request.current) return;
      latest.current = previous;
      setSettings(previous);
      setSaveState("");
      toast.error(err instanceof Error ? err.message : "Could not save automation settings");
    }
  }

  const full = settings.automation_level >= 3;
  const paused = settings.engine_paused;
  const willPost = !paused && settings.auto_post_enabled && settings.automation_level >= 2;
  const status = paused
    ? "Paused. Nothing is posted until you turn pause off."
    : full && settings.auto_post_enabled
      ? "Full automation is on. It posts when the niche spikes, including while you are offline. A quiet stretch can wait, but not longer than three hours."
      : full
        ? "Full automation is selected, but posting is off. Turn on “Post on its own” or nothing goes live."
        : settings.automation_level === 2 && settings.auto_post_enabled
          ? "Controlled. Strong moments can be posted for you. Everything else stays a draft until you say yes."
          : "Controlled. Mackie17 drafts, and you decide what goes live.";

  return (
    <Card className={paused ? "border-amber-500/40" : full && willPost ? "border-primary/50" : ""}>
      <CardHeader>
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle>X posting</CardTitle>
          {paused && <Badge variant="outline">Paused</Badge>}
          {!paused && full && settings.auto_post_enabled && <Badge>Full automation</Badge>}
          {!paused && !willPost && <Badge variant="secondary">Controlled</Badge>}
          {!paused && !full && willPost && <Badge variant="secondary">Strong moments</Badge>}
        </div>
        <CardDescription>
          Choose who presses publish on X. A switch applies immediately, including while you are offline.
          {saveState ? <span className="ml-2 text-foreground">{saveState}</span> : null}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <div className="space-y-5">
          <p
            className={cn(
              "rounded-lg border px-4 py-3 text-sm",
              willPost && full ? "border-primary/40 bg-primary/10" : "border-border bg-muted/40"
            )}
          >
            {status}
          </p>

          <div className="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="How X posts">
            <button
              type="button"
              role="radio"
              aria-checked={full}
              onClick={() => patch({ automation_level: 3, auto_post_enabled: true })}
              className={cn(
                "rounded-lg border p-4 text-left transition-colors",
                full ? "border-primary bg-primary/10" : "border-border hover:bg-muted"
              )}
            >
              <span className="flex items-center justify-between gap-3">
                <span className="font-semibold">Full automation</span>
                <span className={cn("text-xs font-semibold uppercase", full ? "text-primary" : "text-muted-foreground")}>
                  {full ? "On" : "Off"}
                </span>
              </span>
              <span className="mt-2 block text-sm text-muted-foreground">
                Drafts a post, checks whether people in your niche would share it, and publishes it while you are away.
              </span>
            </button>
            <button
              type="button"
              role="radio"
              aria-checked={!full}
              onClick={() => {
                if (settings.automation_level >= 3) {
                  patch({ automation_level: 1, auto_post_enabled: false });
                }
              }}
              className={cn(
                "rounded-lg border p-4 text-left transition-colors",
                !full ? "border-primary bg-primary/10" : "border-border hover:bg-muted"
              )}
            >
              <span className="flex items-center justify-between gap-3">
                <span className="font-semibold">Controlled</span>
                <span className={cn("text-xs font-semibold uppercase", !full ? "text-primary" : "text-muted-foreground")}>
                  {!full ? "On" : "Off"}
                </span>
              </span>
              <span className="mt-2 block text-sm text-muted-foreground">
                You stay in charge. Pick how much Mackie17 does, then use the switches below.
              </span>
            </button>
          </div>

          {!full && (
            <div className="space-y-2" role="radiogroup" aria-label="Controlled options">
              <p className="text-sm font-medium">How much you stay involved</p>
              {CONTROLLED.map((level) => {
                const selected = settings.automation_level === level.value;
                return (
                  <button
                    key={level.value}
                    type="button"
                    role="radio"
                    aria-checked={selected}
                    onClick={() =>
                      patch({
                        automation_level: level.value,
                        auto_post_enabled: level.value >= 2,
                      })
                    }
                    className={cn(
                      "flex w-full items-center justify-between gap-4 rounded-lg border p-4 text-left",
                      selected ? "border-primary bg-primary/10" : "border-border hover:bg-muted"
                    )}
                  >
                    <span>
                      <span className="block font-medium">{level.label}</span>
                      <span className="mt-1 block text-sm text-muted-foreground">{level.hint}</span>
                    </span>
                    <span className="shrink-0 text-xs font-semibold uppercase text-muted-foreground">
                      {selected ? "On" : "Off"}
                    </span>
                  </button>
                );
              })}
            </div>
          )}

          {full && !xConnected && (
            <p className="text-sm text-muted-foreground">
              Connect X below. Until then, full automation can draft, but it cannot publish.
            </p>
          )}

          <div className="space-y-3 border-t border-border pt-4">
            <p className="text-sm font-medium">Switches</p>
            <SettingsToggle
              id="engine_paused"
              label="Pause Mackie17"
              description="Stop posting and replying until you turn this off. Drafts already saved stay put."
              checked={settings.engine_paused}
              onChange={(checked) => patch({ engine_paused: checked })}
            />
            <SettingsToggle
              id="auto_post_enabled"
              label="Post on its own"
              description="On publishes when the mode above allows it. Off keeps every post as a draft, even in full automation."
              checked={settings.auto_post_enabled}
              onChange={(checked) => patch({ auto_post_enabled: checked })}
              disabled={paused}
            />
            <SettingsToggle
              id="auto_reply_enabled"
              label="Reply on its own"
              description="On lets Mackie17 send replies for you when that action is allowed. Off leaves replies for you."
              checked={settings.auto_reply_enabled}
              onChange={(checked) => patch({ auto_reply_enabled: checked })}
              disabled={paused}
            />
            <SettingsToggle
              id="auto_engage_enabled"
              label="Suggest conversations"
              description="On recommends replies and conversations to join. Off stops those suggestions."
              checked={settings.auto_engage_enabled}
              onChange={(checked) => patch({ auto_engage_enabled: checked })}
              disabled={paused}
            />
            <SettingsToggle
              id="posting_mode"
              label="Post on quiet days"
              description="A quiet niche can wait. It still gets a post before three hours have passed."
              checked={settings.mode === "proactive"}
              onChange={(checked) => patch({ mode: checked ? "proactive" : "reactive" })}
              disabled={paused}
            />
          </div>

          <p className="text-xs text-muted-foreground">
            Automation posts for a strong niche moment. If the niche stays quiet, the wait stops at three hours.
          </p>

          <Link href="/growth-agent">
            <Button type="button" variant="outline">
              View engine activity
            </Button>
          </Link>
        </div>
      </CardContent>
    </Card>
  );
}
