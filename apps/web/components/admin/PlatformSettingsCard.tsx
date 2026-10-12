"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SettingsToggle } from "@/components/settings/SettingsToggle";
import { api } from "@/lib/api";

export type PlatformSetting = {
  key: string;
  label: string;
  description: string;
  type: "string" | "integer" | "boolean";
  value: string;
  default: string;
  updated_at: string | null;
  updated_by: string | null;
};

export type PlatformSettingGroup = {
  key: string;
  label: string;
  settings: PlatformSetting[];
};

type DraftValues = Record<string, string>;

export function PlatformSettingsCard() {
  const [groups, setGroups] = useState<PlatformSettingGroup[]>([]);
  const [drafts, setDrafts] = useState<DraftValues>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api<{ groups: PlatformSettingGroup[] }>("/admin/platform-settings");
      setGroups(res.groups);
      const nextDrafts: DraftValues = {};
      for (const group of res.groups) {
        for (const setting of group.settings) {
          nextDrafts[setting.key] = setting.value;
        }
      }
      setDrafts(nextDrafts);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Failed to load platform settings");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  function updateDraft(key: string, value: string) {
    setDrafts((prev) => ({ ...prev, [key]: value }));
  }

  async function save() {
    setSaving(true);
    try {
      const settings = Object.entries(drafts).map(([key, value]) => ({ key, value }));
      await api<{ groups: PlatformSettingGroup[] }>("/admin/platform-settings", {
        method: "PATCH",
        body: JSON.stringify({ settings }),
      });
      toast.success("Platform settings updated");
      await load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save platform settings");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Platform configuration</CardTitle>
        <CardDescription>
          Scheduler, trend fetch schedule, Growth Brain, content mix, safety limits, AI defaults, and the free
          trial length.
          Changes apply immediately — no redeploy needed. OpenAI API keys stay in server environment
          variables.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-8">
        {loading ? (
          <p className="text-sm text-muted-foreground">Loading settings…</p>
        ) : (
          <>
            {groups.map((group) => (
              <section key={group.key} className="space-y-4">
                <h3 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                  {group.label}
                </h3>
                <div className="space-y-3">
                  {group.settings.map((setting) => {
                    const draft = drafts[setting.key] ?? setting.value;

                    if (setting.type === "boolean") {
                      const checked = ["1", "true", "yes", "on"].includes(draft.toLowerCase());
                      return (
                        <SettingsToggle
                          key={setting.key}
                          id={`platform-${setting.key}`}
                          label={setting.label}
                          description={setting.description}
                          checked={checked}
                          onChange={(next) => updateDraft(setting.key, next ? "1" : "0")}
                        />
                      );
                    }

                    return (
                      <div
                        key={setting.key}
                        className="space-y-2 rounded-lg border border-border p-4"
                      >
                        <Label htmlFor={`platform-${setting.key}`}>{setting.label}</Label>
                        <p className="text-sm text-muted-foreground">{setting.description}</p>
                        <Input
                          id={`platform-${setting.key}`}
                          type={setting.type === "integer" ? "number" : "text"}
                          value={draft}
                          onChange={(e) => updateDraft(setting.key, e.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                          Default: {setting.default}
                          {setting.updated_by && (
                            <> · Last updated by {setting.updated_by}</>
                          )}
                        </p>
                      </div>
                    );
                  })}
                </div>
              </section>
            ))}

            <div className="flex justify-end">
              <Button onClick={save} disabled={saving}>
                {saving ? "Saving…" : "Save all settings"}
              </Button>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}
