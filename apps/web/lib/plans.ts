export const PLAN_SLUGS = ["creator", "pro", "agency"] as const;

export type PlanSlug = (typeof PLAN_SLUGS)[number];

export type BillingInterval = "monthly" | "yearly";

export type SubscriptionPlan = {
  slug: PlanSlug;
  name: string;
  price_display: string;
  period: string;
  yearly_price_display: string | null;
  yearly_period: string;
  description: string | null;
  features: string[];
  cta: string;
  highlighted: boolean;
  sort_order: number;
  daily_ai_generations?: number;
  daily_web_searches?: number;
};

export function formatPlanPrice(
  plan: Pick<SubscriptionPlan, "price_display" | "period" | "yearly_price_display" | "yearly_period">,
  interval: BillingInterval = "monthly"
): string {
  if (interval === "yearly" && plan.yearly_price_display) {
    return `${plan.yearly_price_display}${plan.yearly_period}`;
  }

  return `${plan.price_display}${plan.period}`;
}

export async function fetchPlans(api: <T>(path: string, init?: RequestInit) => Promise<T>): Promise<SubscriptionPlan[]> {
  const res = await api<{ plans: SubscriptionPlan[] }>("/plans");
  return res.plans;
}
