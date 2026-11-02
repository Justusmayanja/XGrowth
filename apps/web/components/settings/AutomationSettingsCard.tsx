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

const POSTING_MODES = [
  AUTOMATION_LEVELS.find((level) => level.value === 3)!,
  {
    value: 1,
    label: "Confirm before posting",
    hint: "It drafts the post and waits for your yes.",
  },
  AUTOMATION_LEVELS.find((level) => level.value === 2)!,
  AUTOMATION_LEVELS.find((level) => level.value === 0)!,
];

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

  return (
    <Card className={paused ? "border-amber-500/40" : full && willPost ? "border-primary/50" : ""}>
      <CardHeader>
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle>X posting</CardTitle>
          {paused && <Badge variant="outline">Paused</Badge>}
        </div>
        <CardDescription>
          Choose who presses publish on X. A switch applies immediately, including while you are offline.
          {saveState ? <span className="ml-2 text-foreground">{saveState}</span> : null}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <div className="space-y-5">
          <div className="space-y-2">
            <p className="text-sm font-medium">How it posts</p>
            <div className="flex flex-col gap-2" role="radiogroup" aria-label="How it posts">
              {POSTING_MODES.map((mode) => {
                const selected = settings.automation_level === mode.value;
                return (
                  <button
                    key={mode.value}
                    type="button"
                    role="radio"
                    aria-checked={selected}
                    onClick={() =>
                      patch({
                        automation_level: mode.value,
                        auto_post_enabled: mode.value >= 2,
                      })
                    }
                    className={`choice-row ${selected ? "is-on" : ""}`}
                  >
                    <span className="flex items-start gap-3">
                      <span
                        className={cn(
                          "mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border",
                          selected ? "border-primary" : "border-muted-foreground"
                        )}
                        aria-hidden
                      >
                        {selected ? <span className="h-2 w-2 rounded-full bg-primary" /> : null}
                      </span>
                      <span>
                        <strong>{mode.label}</strong>
                        <em>{mode.hint}</em>
                      </span>
                    </span>
                  </button>
                );
              })}
            </div>
          </div>

          {full && !xConnected && (
            <p className="text-sm text-muted-foreground">
              Connect X below. Until then, full automation can draft, but it cannot publish.
            </p>
          )}

          <div className="space-y-3 border-t border-border pt-4">
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
              description="On sends a few replies a day, written for that post. It does not reply to every post."
              checked={settings.auto_reply_enabled}
              onChange={(checked) => patch({ auto_reply_enabled: checked })}
              disabled={paused}
            />
            <SettingsToggle
              id="auto_engage_enabled"
              label="Like some posts"
              description="On likes a few posts in the niche. It does not like every post, and it leaves gaps between likes."
              checked={settings.auto_engage_enabled}
              onChange={(checked) => patch({ auto_engage_enabled: checked })}
              disabled={paused}
            />
            <SettingsToggle
              id="posting_mode"
              label="Smaller moments"
              description="On leans a little toward a take that is not breaking news. Off favors the spike. Neither one posts because time passed."
              checked={settings.mode === "proactive"}
              onChange={(checked) => patch({ mode: checked ? "proactive" : "reactive" })}
              disabled={paused}
            />
          </div>

          <p className="text-xs text-muted-foreground">
            It skips some big accounts, and it likes and replies only sometimes. A post goes out when a moment in the niche is loud. A quiet timeline stays quiet, so there is no hour a tweet is due.
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
