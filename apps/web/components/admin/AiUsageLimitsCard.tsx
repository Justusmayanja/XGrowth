"use client";

import { useCallback, useEffect, useState } from "react";
import { LoadingMark } from "@/components/LoadingMark";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api } from "@/lib/api";
import type { SubscriptionPlan } from "@/lib/plans";

type LimitDraft = {
  daily_ai_generations: string;
  daily_web_searches: string;
};

export function AiUsageLimitsCard() {
  const [plans, setPlans] = useState<SubscriptionPlan[]>([]);
  const [drafts, setDrafts] = useState<Record<string, LimitDraft>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api<{ plans: SubscriptionPlan[] }>("/admin/plans");
      setPlans(res.plans);
      setDrafts(
        Object.fromEntries(
          res.plans.map((plan) => [
            plan.slug,
            {
              daily_ai_generations: String(plan.daily_ai_generations ?? 30),
              daily_web_searches: String(plan.daily_web_searches ?? 5),
            },
          ])
        )
      );
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Failed to load usage limits");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  function updateDraft(slug: string, patch: Partial<LimitDraft>) {
    setDrafts((prev) => ({
      ...prev,
      [slug]: { ...prev[slug], ...patch },
    }));
  }

  async function save() {
    setSaving(true);
    try {
      await api<{ plans: SubscriptionPlan[] }>("/admin/plans", {
        method: "PATCH",
        body: JSON.stringify({
          plans: plans.map((plan) => {
            const draft = drafts[plan.slug];
            return {
              slug: plan.slug,
              daily_ai_generations: Number(draft?.daily_ai_generations ?? plan.daily_ai_generations ?? 30),
              daily_web_searches: Number(draft?.daily_web_searches ?? plan.daily_web_searches ?? 5),
            };
          }),
        }),
      });
      toast.success("Draft limits updated");
      await load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save usage limits");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Draft limits</CardTitle>
        <CardDescription>
          Daily quotas per subscription plan. Standard generation is cheaper; web search uses extra
          tokens plus ~$0.01 per search call — keep search limits lower.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {loading ? (
          <LoadingMark />
        ) : (
          <>
            {plans.map((plan) => {
              const draft = drafts[plan.slug] ?? {
                daily_ai_generations: String(plan.daily_ai_generations ?? 30),
                daily_web_searches: String(plan.daily_web_searches ?? 5),
              };

              return (
                <div key={plan.slug} className="space-y-3 rounded-lg border border-border p-4">
                  <p className="font-medium capitalize">{plan.name}</p>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                      <Label htmlFor={`ai-gen-${plan.slug}`}>Daily drafts</Label>
                      <Input
                        id={`ai-gen-${plan.slug}`}
                        type="number"
                        min={1}
                        value={draft.daily_ai_generations}
                        onChange={(e) =>
                          updateDraft(plan.slug, { daily_ai_generations: e.target.value })
                        }
                        className="mt-1"
                      />
                    </div>
                    <div>
                      <Label htmlFor={`web-search-${plan.slug}`}>Daily web searches</Label>
                      <Input
                        id={`web-search-${plan.slug}`}
                        type="number"
                        min={0}
                        value={draft.daily_web_searches}
                        onChange={(e) =>
                          updateDraft(plan.slug, { daily_web_searches: e.target.value })
                        }
                        className="mt-1"
                      />
                    </div>
                  </div>
                </div>
              );
            })}
            <Button onClick={save} disabled={saving || loading}>
              {saving ? "Saving…" : "Save usage limits"}
            </Button>
          </>
        )}
      </CardContent>
    </Card>
  );
}
