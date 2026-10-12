import { api } from "@/lib/api";

export const BILLING_CART_KEY = "mackie17_billing_cart";
export const PENDING_SUBSCRIPTION_KEY = "mackie17_pending_subscription";

export type PlatformId = "x" | "tiktok" | "youtube";
export type TierId = "starter" | "pro" | "agency";
export type BillingInterval = "monthly" | "yearly";

export type CartLine = {
  platform: PlatformId;
  tier: TierId;
};

export type CatalogTier = {
  id: TierId;
  name: string;
  list_cents: number;
  yearly_cents: number;
  accounts: number;
  allowance: number;
  allowance_unit: string;
  features: string[];
};

export type CatalogPlatform = {
  id: PlatformId;
  name: string;
  summary: string;
  tiers: CatalogTier[];
};

export type BillingCatalog = {
  currency: string;
  discount_percent: number;
  trial_days: number;
  platforms: CatalogPlatform[];
};

export type QuoteLine = {
  platform: PlatformId;
  platform_name: string;
  tier: TierId;
  tier_name: string;
  list_cents: number;
  charged_cents: number;
  discount_cents: number;
  is_anchor: boolean;
  discount_limited: boolean;
  yearly_floor_applied: boolean;
  accounts_included: number;
  monthly_allowance: number;
  allowance_unit: string;
  features: string[];
};

export type BillingQuote = {
  interval: BillingInterval;
  currency: string;
  discount_percent: number;
  lines: QuoteLine[];
  subtotal_cents: number;
  discount_cents: number;
  total_cents: number;
  yearly_floor_applied: boolean;
  period: string;
};

export type BillingSubscriptionView = {
  id: number;
  status: string;
  interval: BillingInterval;
  currency: string;
  subtotal_cents: number;
  discount_cents: number;
  total_cents: number;
  period: string;
  first_charge_cents: number;
  credit_cents: number;
  renews: boolean;
  current_period_ends_at: string | null;
  trial_days: number | null;
  trial_ends_at: string | null;
  trial_days_left: number | null;
  failure_reason: string | null;
  lines: Array<{
    platform: PlatformId;
    platform_name: string;
    tier: TierId;
    tier_name: string;
    list_cents: number;
    charged_cents: number;
    discount_cents: number;
    is_anchor: boolean;
    accounts_included: number;
    monthly_allowance: number;
    allowance_unit: string;
  }>;
};

export type NextPlan = {
  interval: BillingInterval;
  starts_at: string | null;
  due: boolean;
  lines: Array<CartLine & { platform_name: string; tier_name: string }>;
};

export type BillingStatus = {
  subscription: BillingSubscriptionView | null;
  trial_available: boolean;
  trial_days: number;
  next_plan: NextPlan | null;
};

export type PlanChange = {
  kind: "new" | "upgrade" | "downgrade" | "same";
  credit_cents: number;
  charge_now_cents: number;
  effective_at: string | null;
  current: BillingSubscriptionView | null;
};

export type BillingPreview = BillingQuote & { change: PlanChange };

export function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" }) : "";
}

export function fetchBillingStatus() {
  return api<BillingStatus>("/billing/subscription");
}

export function formatCents(cents: number): string {
  const negative = cents < 0;
  const abs = Math.abs(cents);
  const dollars = Math.floor(abs / 100);
  const remainder = abs % 100;
  const text =
    remainder === 0 ? `$${dollars}` : `$${dollars}.${String(remainder).padStart(2, "0")}`;

  return negative ? `−${text}` : text;
}

export function readCart(): { lines: CartLine[]; interval: BillingInterval } | null {
  if (typeof window === "undefined") return null;

  try {
    const raw = sessionStorage.getItem(BILLING_CART_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as { lines?: CartLine[]; interval?: BillingInterval };
    if (!Array.isArray(parsed.lines) || parsed.lines.length === 0) return null;
    if (parsed.interval !== "monthly" && parsed.interval !== "yearly") return null;
    return { lines: parsed.lines, interval: parsed.interval };
  } catch {
    return null;
  }
}

export function writeCart(lines: CartLine[], interval: BillingInterval) {
  sessionStorage.setItem(BILLING_CART_KEY, JSON.stringify({ lines, interval }));
}

export function confirmSubscription(id: number) {
  return api<{ paid: boolean; subscription: BillingSubscriptionView; message?: string }>(
    `/billing/subscriptions/${id}/confirm`,
    { method: "POST" },
    30000
  );
}
