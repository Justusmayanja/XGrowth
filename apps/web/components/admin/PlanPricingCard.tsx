"use client";

import { useCallback, useEffect, useState } from "react";
import { LoadingMark } from "@/components/LoadingMark";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api } from "@/lib/api";

type PriceRow = {
  platform: string;
  platform_name: string;
  tier: string;
  tier_name: string;
  list_price_cents: number;
  yearly_price_cents: number;
  minimum_cents: number;
};

type PriceResponse = {
  gateway: string;
  configured: boolean;
  missing: string[];
  paddle_environment: string;
  discount_percent: number;
  prices: PriceRow[];
};

type Draft = { monthly: string; yearly: string };

function dollars(cents: number): string {
  return (cents / 100).toFixed(2);
}

function toCents(value: string): number | null {
  const trimmed = value.trim().replace(/^\$/, "");
  if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) return null;
  const [whole, fraction = ""] = trimmed.split(".");
  return Number(whole) * 100 + Number(fraction.padEnd(2, "0").slice(0, 2));
}

function yearlySaving(draft: Draft | undefined): string {
  const monthly = toCents(draft?.monthly ?? "");
  const yearly = toCents(draft?.yearly ?? "");
  if (!monthly || yearly === null) return "";
  const percent = Math.round((1 - yearly / (monthly * 12)) * 100);
  return percent > 0 ? `${percent}% off 12 months` : "No yearly saving";
}

export function PlanPricingCard() {
  const [prices, setPrices] = useState<PriceRow[]>([]);
  const [drafts, setDrafts] = useState<Record<string, Draft>>({});
  const [discount, setDiscount] = useState("");
  const [gateway, setGateway] = useState<Pick<PriceResponse, "gateway" | "configured" | "missing" | "paddle_environment"> | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const apply = useCallback((res: PriceResponse) => {
    setPrices(res.prices);
    setGateway({
      gateway: res.gateway,
      configured: res.configured,
      missing: res.missing,
      paddle_environment: res.paddle_environment,
    });
    setDiscount(String(res.discount_percent));
    setDrafts(
      Object.fromEntries(
        res.prices.map((row) => [
          `${row.platform}:${row.tier}`,
          { monthly: dollars(row.list_price_cents), yearly: dollars(row.yearly_price_cents) },
        ])
      )
    );
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      apply(await api<PriceResponse>("/admin/billing/prices"));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Failed to load pricing");
    } finally {
      setLoading(false);
    }
  }, [apply]);

  useEffect(() => {
    void load();
  }, [load]);

  function setDraft(key: string, field: keyof Draft, value: string) {
    setDrafts((current) => ({ ...current, [key]: { ...current[key], [field]: value } }));
  }

  async function save() {
    const discountPercent = Number(discount);
    if (!/^\d+$/.test(discount.trim()) || discountPercent > 50) {
      toast.error("Bundle discount must be a whole number from 0 to 50.");
      return;
    }

    const payload: Array<{ platform: string; tier: string; list_price_cents: number; yearly_price_cents: number }> = [];

    for (const row of prices) {
      const draft = drafts[`${row.platform}:${row.tier}`];
      const label = `${row.platform_name} ${row.tier_name}`;
      const monthly = toCents(draft?.monthly ?? "");
      const yearly = toCents(draft?.yearly ?? "");

      if (monthly === null || yearly === null) {
        toast.error(`Enter dollar amounts for ${label}.`);
        return;
      }
      if (monthly < row.minimum_cents) {
        toast.error(`${label} cannot be priced below $${dollars(row.minimum_cents)} a month.`);
        return;
      }
      if (yearly < row.minimum_cents * 12) {
        toast.error(`${label} cannot be priced below $${dollars(row.minimum_cents * 12)} a year.`);
        return;
      }
      if (yearly >= monthly * 12) {
        toast.error(`${label} yearly price must be lower than 12 monthly payments ($${dollars(monthly * 12)}).`);
        return;
      }
      payload.push({ platform: row.platform, tier: row.tier, list_price_cents: monthly, yearly_price_cents: yearly });
    }

    setSaving(true);
    try {
      apply(
        await api<PriceResponse>("/admin/billing/prices", {
          method: "PATCH",
          body: JSON.stringify({ prices: payload, discount_percent: discountPercent }),
        })
      );
      toast.success("Platform prices updated");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save pricing");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Platform pricing</CardTitle>
        <CardDescription>
          Monthly and yearly price for each platform and tier. A second or third platform gets the bundle discount
          at checkout, and no price can go below its minimum. New prices apply to new checkouts and plan changes.
          Running plans renew at the price they were bought at.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {gateway && (
          <div className="rounded-lg border border-border p-4 text-sm">
            <p className="font-medium capitalize">
              {gateway.gateway || "No gateway"}{" "}
              {gateway.gateway === "paddle" ? `(${gateway.paddle_environment})` : ""}
            </p>
            {gateway.configured ? (
              <p className="mt-1 text-muted-foreground">
                API keys are set. Webhook: /api/billing/webhooks/{gateway.gateway}
              </p>
            ) : (
              <p className="mt-1 text-muted-foreground">
                Add {gateway.missing.join(", ")} to the backend environment. Checkout stays closed until those
                keys are present.
              </p>
            )}
          </div>
        )}

        {loading ? (
          <LoadingMark />
        ) : (
          <>
            <div className="grid gap-3 rounded-lg border border-border p-4 sm:grid-cols-[1fr_10rem]">
              <div>
                <p className="font-medium">Bundle discount</p>
                <p className="text-sm text-muted-foreground">
                  Taken off every platform except the most expensive one. 0 to 50%.
                </p>
              </div>
              <div>
                <Label htmlFor="bundle-discount">Discount %</Label>
                <Input
                  id="bundle-discount"
                  inputMode="numeric"
                  value={discount}
                  onChange={(event) => setDiscount(event.target.value)}
                  className="mt-1"
                />
              </div>
            </div>
            {prices.map((row) => {
              const key = `${row.platform}:${row.tier}`;
              const yearlyNote = yearlySaving(drafts[key]);
              return (
                <div
                  key={key}
                  className="grid gap-3 rounded-lg border border-border p-4 sm:grid-cols-[1fr_10rem_10rem]"
                >
                  <div>
                    <p className="font-medium">
                      {row.platform_name} · {row.tier_name}
                    </p>
                    <p className="text-sm text-muted-foreground">
                      Lowest ${dollars(row.minimum_cents)} / month, ${dollars(row.minimum_cents * 12)} / year
                    </p>
                  </div>
                  <div>
                    <Label htmlFor={`price-${key}`}>Monthly price</Label>
                    <Input
                      id={`price-${key}`}
                      inputMode="decimal"
                      value={drafts[key]?.monthly ?? ""}
                      onChange={(event) => setDraft(key, "monthly", event.target.value)}
                      className="mt-1"
                    />
                  </div>
                  <div>
                    <Label htmlFor={`yearly-${key}`}>Yearly price</Label>
                    <Input
                      id={`yearly-${key}`}
                      inputMode="decimal"
                      value={drafts[key]?.yearly ?? ""}
                      onChange={(event) => setDraft(key, "yearly", event.target.value)}
                      className="mt-1"
                    />
                    {yearlyNote && <p className="mt-1 text-xs text-muted-foreground">{yearlyNote}</p>}
                  </div>
                </div>
              );
            })}
            <Button type="button" onClick={() => void save()} disabled={saving}>
              {saving ? "Saving…" : "Save prices"}
            </Button>
          </>
        )}
      </CardContent>
    </Card>
  );
}
