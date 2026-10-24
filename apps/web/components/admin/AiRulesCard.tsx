"use client";

import { useCallback, useEffect, useState } from "react";
import { LoadingMark } from "@/components/LoadingMark";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { api } from "@/lib/api";

export type AiRule = {
  key: string;
  label: string;
  description: string | null;
  rules: string;
  is_active: boolean;
  sort_order: number;
  updated_at: string | null;
  updated_by: string | null;
};

type RuleDraft = {
  rules: string;
  is_active: boolean;
};

export function AiRulesCard() {
  const [rules, setRules] = useState<AiRule[]>([]);
  const [drafts, setDrafts] = useState<Record<string, RuleDraft>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api<{ rules: AiRule[] }>("/admin/ai-rules");
      setRules(res.rules);
      setDrafts(
        Object.fromEntries(
          res.rules.map((rule) => [
            rule.key,
            {
              rules: rule.rules,
              is_active: rule.is_active,
            },
          ])
        )
      );
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not load drafting instructions");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  function updateDraft(key: string, patch: Partial<RuleDraft>) {
    setDrafts((prev) => ({
      ...prev,
      [key]: { ...prev[key], ...patch },
    }));
  }

  async function save() {
    setSaving(true);
    try {
      await api<{ rules: AiRule[] }>("/admin/ai-rules", {
        method: "PATCH",
        body: JSON.stringify({
          rules: rules.map((rule) => {
            const draft = drafts[rule.key];
            return {
              key: rule.key,
              rules: draft?.rules ?? rule.rules,
              is_active: draft?.is_active ?? rule.is_active,
            };
          }),
        }),
      });
      toast.success("Drafting instructions saved");
      await load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save drafting instructions");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Drafting instructions</CardTitle>
        <CardDescription>
          Sent with every draft. The general set applies everywhere. Each section adds rules for
          that job.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {loading ? (
          <LoadingMark />
        ) : (
          <>
            {rules.map((rule) => {
              const draft = drafts[rule.key] ?? {
                rules: rule.rules,
                is_active: rule.is_active,
              };

              return (
                <div key={rule.key} className="space-y-3 rounded-lg border border-border p-4">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <p className="font-medium">{rule.label}</p>
                      {rule.description && (
                        <p className="text-sm text-muted-foreground">{rule.description}</p>
                      )}
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={draft.is_active}
                        onChange={(e) => updateDraft(rule.key, { is_active: e.target.checked })}
                        className="rounded border-border"
                      />
                      Active
                    </label>
                  </div>

                  <div>
                    <Label htmlFor={`ai-rules-${rule.key}`}>Rules</Label>
                    <Textarea
                      id={`ai-rules-${rule.key}`}
                      value={draft.rules}
                      onChange={(e) => updateDraft(rule.key, { rules: e.target.value })}
                      rows={rule.key === "global" ? 10 : 7}
                      className="mt-1 font-mono text-sm"
                      placeholder="One rule per line works well."
                    />
                    <p className="mt-1 text-xs text-muted-foreground">
                      {draft.rules.length.toLocaleString()} characters
                      {rule.updated_at && (
                        <>
                          {" "}
                          · Last saved{" "}
                          {new Date(rule.updated_at).toLocaleString()}
                          {rule.updated_by ? ` by ${rule.updated_by}` : ""}
                        </>
                      )}
                    </p>
                  </div>
                </div>
              );
            })}

            <Button onClick={save} disabled={saving || loading}>
              {saving ? "Saving…" : "Save instructions"}
            </Button>
          </>
        )}
      </CardContent>
    </Card>
  );
}
