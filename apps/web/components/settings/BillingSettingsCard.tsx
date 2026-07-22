"use client";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { formatPlanPrice, type SubscriptionPlan } from "@/lib/plans";
import type { AccountInfo } from "@/lib/settings";

type Props = {
  account: AccountInfo;
  plans: SubscriptionPlan[];
};

export function BillingSettingsCard({ account, plans }: Props) {
  const current = plans.find((p) => p.slug === account.subscription_plan) ?? plans[0];

  if (!current) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Billing & plan</CardTitle>
        <CardDescription>Your subscription and feature access</CardDescription>
      </CardHeader>
      <CardContent>
        <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-border p-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="font-semibold capitalize">{current.name}</span>
              <Badge variant="secondary">{formatPlanPrice(current, "monthly")}</Badge>
            </div>
            <p className="mt-1 text-sm text-muted-foreground">
              {current.description ?? "Thank you for supporting Xpulse."}
            </p>
            {current.yearly_price_display && (
              <p className="mt-1 text-xs text-muted-foreground">
                Or {formatPlanPrice(current, "yearly")} with annual billing
              </p>
            )}
          </div>
          <Button variant="secondary" disabled>
            Manage billing (coming soon)
          </Button>
        </div>
        <ul className="mt-4 space-y-2 text-sm text-muted-foreground">
          {plans.map((plan) => (
            <li
              key={plan.slug}
              className={`flex justify-between gap-4 ${plan.slug === account.subscription_plan ? "font-medium text-foreground" : ""}`}
            >
              <span className="capitalize">{plan.name}</span>
              <span className="text-right">
                <span>{formatPlanPrice(plan, "monthly")}</span>
                {plan.yearly_price_display && (
                  <span className="block text-xs text-muted-foreground">
                    {formatPlanPrice(plan, "yearly")}
                  </span>
                )}
              </span>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
