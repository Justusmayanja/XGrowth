"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api } from "@/lib/api";
import { formatPlanPrice, type SubscriptionPlan } from "@/lib/plans";

type PlanDraft = {
  price_display: string;
  period: string;
  yearly_price_display: string;
  yearly_period: string;
};

export function PlanPricingCard() {
  const [plans, setPlans] = useState<SubscriptionPlan[]>([]);
  const [drafts, setDrafts] = useState<Record<string, PlanDraft>>({});
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
              price_display: plan.price_display,
              period: plan.period,
              yearly_price_display: plan.yearly_price_display ?? "",
              yearly_period: plan.yearly_period,
            },
          ])
        )
      );
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Failed to load plans");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  function updateDraft(slug: string, patch: Partial<PlanDraft>) {
    setDrafts((prev) => ({
      ...prev,
      [slug]: { ...prev[slug], ...patch },
    }));
  }

  async function save() {
    setSaving(true);
    try {
      const res = await api<{ plans: SubscriptionPlan[] }>("/admin/plans", {
        method: "PATCH",
        body: JSON.stringify({
          plans: plans.map((plan) => {
            const draft = drafts[plan.slug];
            return {
              slug: plan.slug,
              price_display: draft?.price_display ?? plan.price_display,
              period: draft?.period ?? plan.period,
              yearly_price_display: draft?.yearly_price_display ?? plan.yearly_price_display,
              yearly_period: draft?.yearly_period ?? plan.yearly_period,
            };
          }),
        }),
      });
      setPlans(res.plans);
      setDrafts(
        Object.fromEntries(
          res.plans.map((plan) => [
            plan.slug,
            {
              price_display: plan.price_display,
              period: plan.period,
              yearly_price_display: plan.yearly_price_display ?? "",
              yearly_period: plan.yearly_period,
            },
          ])
        )
      );
      toast.success("Plan pricing updated");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save pricing");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Plan pricing</CardTitle>
        <CardDescription>
          Edit monthly and yearly displayed prices on the landing page and customer billing settings
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {loading ? (
          <p className="text-sm text-muted-foreground">Loading plans…</p>
        ) : (
          <>
            {plans.map((plan) => {
              const draft = drafts[plan.slug] ?? {
                price_display: plan.price_display,
                period: plan.period,
                yearly_price_display: plan.yearly_price_display ?? "",
                yearly_period: plan.yearly_period,
              };

              const previewPlan = {
                price_display: draft.price_display,
                period: draft.period,
                yearly_price_display: draft.yearly_price_display || null,
                yearly_period: draft.yearly_period,
              };

              return (
                <div key={plan.slug} className="space-y-3 rounded-lg border border-border p-4">
                  <div>
                    <p className="font-medium capitalize">{plan.name}</p>
                    <p className="text-sm text-muted-foreground">{plan.description}</p>
                  </div>

                  <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                      <Label htmlFor={`monthly-price-${plan.slug}`}>Monthly price</Label>
                      <Input
                        id={`monthly-price-${plan.slug}`}
                        value={draft.price_display}
                        onChange={(e) => updateDraft(plan.slug, { price_display: e.target.value })}
                        placeholder="$29"
                        className="mt-1"
                      />
                    </div>
                    <div>
                      <Label htmlFor={`monthly-period-${plan.slug}`}>Monthly period</Label>
                      <Input
                        id={`monthly-period-${plan.slug}`}
                        value={draft.period}
                        onChange={(e) => updateDraft(plan.slug, { period: e.target.value })}
                        placeholder="/mo"
                        className="mt-1"
                      />
                    </div>
                    <div>
                      <Label htmlFor={`yearly-price-${plan.slug}`}>Yearly price</Label>
                      <Input
                        id={`yearly-price-${plan.slug}`}
                        value={draft.yearly_price_display}
                        onChange={(e) => updateDraft(plan.slug, { yearly_price_display: e.target.value })}
                        placeholder="$290"
                        className="mt-1"
                      />
                    </div>
                    <div>
                      <Label htmlFor={`yearly-period-${plan.slug}`}>Yearly period</Label>
                      <Input
                        id={`yearly-period-${plan.slug}`}
                        value={draft.yearly_period}
                        onChange={(e) => updateDraft(plan.slug, { yearly_period: e.target.value })}
                        placeholder="/yr"
                        className="mt-1"
                      />
                    </div>
                  </div>

                  <p className="text-sm text-muted-foreground">
                    Preview: {formatPlanPrice(previewPlan, "monthly")} ·{" "}
                    {formatPlanPrice(previewPlan, "yearly")}
                  </p>
                </div>
              );
            })}
            <Button onClick={save} disabled={saving || loading}>
              {saving ? "Saving…" : "Save pricing"}
            </Button>
          </>
        )}
      </CardContent>
    </Card>
  );
}
