"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { type SocialBrand, SocialName } from "@/components/SocialLogo";
import { useReveal } from "@/hooks/useReveal";
import { api, getToken } from "@/lib/api";
import {
  BILLING_CART_KEY,
  type BillingCatalog,
  type BillingInterval,
  type BillingPreview,
  type BillingQuote,
  type BillingStatus,
  type BillingSubscriptionView,
  type CartLine,
  type PlanChange,
  type PlatformId,
  PENDING_SUBSCRIPTION_KEY,
  type TierId,
  confirmSubscription,
  fetchBillingStatus,
  formatCents,
  formatDate,
  readCart,
  writeCart,
} from "@/lib/billing";

type Selection = Record<PlatformId, { enabled: boolean; tier: TierId }>;

const EMPTY: Selection = {
  x: { enabled: true, tier: "starter" },
  tiktok: { enabled: false, tier: "starter" },
  youtube: { enabled: false, tier: "starter" },
};

function selectionFromCart(lines: CartLine[]): Selection {
  const next: Selection = {
    x: { enabled: false, tier: "starter" },
    tiktok: { enabled: false, tier: "starter" },
    youtube: { enabled: false, tier: "starter" },
  };

  for (const line of lines) {
    if (next[line.platform]) {
      next[line.platform] = { enabled: true, tier: line.tier };
    }
  }

  return next;
}

const PLAN_STATUSES = ["active", "past_due", "trialing", "trial_ended"];

function planFromStatus(status: BillingStatus): { lines: CartLine[]; interval: BillingInterval } | null {
  if (status.next_plan) {
    return { lines: status.next_plan.lines, interval: status.next_plan.interval };
  }

  const subscription = status.subscription;
  if (!subscription || !PLAN_STATUSES.includes(subscription.status) || subscription.lines.length === 0) {
    return null;
  }

  return {
    lines: subscription.lines.map((line) => ({ platform: line.platform, tier: line.tier })),
    interval: subscription.interval,
  };
}

export function Pricing({ showIntro = true }: { showIntro?: boolean }) {
  const { ref, visible } = useReveal();
  const router = useRouter();
  const alertRef = useRef<HTMLDivElement>(null);
  const receiptRef = useRef<HTMLDivElement>(null);
  const requestId = useRef(0);
  const [showDock, setShowDock] = useState(false);
  const [catalog, setCatalog] = useState<BillingCatalog | null>(null);
  const [selection, setSelection] = useState<Selection>(EMPTY);
  const [interval, setInterval] = useState<BillingInterval>("monthly");
  const [quote, setQuote] = useState<BillingQuote | null>(null);
  const [quoting, setQuoting] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [loadError, setLoadError] = useState("");
  const [waitingId, setWaitingId] = useState<number | null>(null);
  const [paid, setPaid] = useState<BillingSubscriptionView | null>(null);
  const [signedIn, setSignedIn] = useState(false);
  const [offerTrial, setOfferTrial] = useState<boolean | null>(null);
  const [change, setChange] = useState<PlanChange | null>(null);
  const [scheduledAt, setScheduledAt] = useState<string | null>(null);
  const [trialing, setTrialing] = useState(false);

  useEffect(() => {
    const saved = readCart();
    if (saved) {
      setSelection(selectionFromCart(saved.lines));
      setInterval(saved.interval);
    }

    if (getToken()) {
      setSignedIn(true);
      fetchBillingStatus()
        .then((status) => {
          const plan = status.trial_available ? null : planFromStatus(status);
          if (plan) {
            setSelection(selectionFromCart(plan.lines));
            setInterval(plan.interval);
          }
          setTrialing(status.subscription?.status === "trialing");
          setOfferTrial(status.trial_available);
        })
        .catch(() => setOfferTrial(true));
    } else {
      setOfferTrial(true);
    }

    api<BillingCatalog>("/billing/catalog")
      .then(setCatalog)
      .catch((err) => {
        setLoadError(err instanceof Error ? err.message : "Could not load pricing.");
      });
  }, []);

  const lines: CartLine[] = (catalog?.platforms ?? [])
    .filter((platform) => selection[platform.id]?.enabled)
    .map((platform) => ({ platform: platform.id, tier: selection[platform.id].tier }));

  useEffect(() => {
    if (!catalog || offerTrial === null) return;
    writeCart(lines, interval);
    setScheduledAt(null);

    if (lines.length === 0) {
      setQuote(null);
      setChange(null);
      setQuoting(false);
      return;
    }

    const id = ++requestId.current;
    const previewChange = signedIn && offerTrial === false;
    setQuoting(true);
    api<BillingQuote | BillingPreview>(previewChange ? "/billing/preview" : "/billing/quote", {
      method: "POST",
      body: JSON.stringify({ lines, interval }),
    })
      .then((next) => {
        if (id !== requestId.current) return;
        setQuote(next);
        setChange("change" in next ? next.change : null);
      })
      .catch((err) => {
        if (id !== requestId.current) return;
        setQuote(null);
        setError(err instanceof Error ? err.message : "Could not price this mix.");
      })
      .finally(() => {
        if (id === requestId.current) setQuoting(false);
      });
    // lines is derived from selection; stringify keeps the effect stable.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [catalog, interval, offerTrial, signedIn, JSON.stringify(lines)]);

  useEffect(() => {
    if (!waitingId || !getToken()) return;

    let stopped = false;

    async function tick() {
      try {
        const res = await confirmSubscription(waitingId as number);
        if (stopped || !res.paid) return;
        setPaid(res.subscription);
        setWaitingId(null);
        sessionStorage.removeItem(PENDING_SUBSCRIPTION_KEY);
      } catch {
        // The gateway can take a moment. The next poll tries again.
      }
    }

    void tick();
    const timer = window.setInterval(() => void tick(), 4000);

    return () => {
      stopped = true;
      window.clearInterval(timer);
    };
  }, [waitingId]);

  useEffect(() => {
    function onFocus() {
      const raw = sessionStorage.getItem(PENDING_SUBSCRIPTION_KEY);
      if (raw && getToken()) setWaitingId(Number(raw));
    }

    window.addEventListener("focus", onFocus);
    return () => window.removeEventListener("focus", onFocus);
  }, []);

  useEffect(() => {
    const section = ref.current;
    const receipt = receiptRef.current;
    if (!section || !receipt) return;

    let sectionVisible = false;
    let receiptVisible = false;
    const update = () => setShowDock(sectionVisible && !receiptVisible);

    if (offerTrial !== false) {
      setShowDock(false);
      return;
    }

    const sectionObserver = new IntersectionObserver(([entry]) => {
      sectionVisible = entry.isIntersecting;
      update();
    });
    const receiptObserver = new IntersectionObserver(
      ([entry]) => {
        receiptVisible = entry.isIntersecting;
        update();
      },
      { threshold: 0.2 }
    );

    sectionObserver.observe(section);
    receiptObserver.observe(receipt);
    return () => {
      sectionObserver.disconnect();
      receiptObserver.disconnect();
    };
  }, [catalog, ref, offerTrial]);

  function setEnabled(platform: PlatformId, enabled: boolean) {
    setError("");
    setSelection((current) => ({
      ...current,
      [platform]: { ...current[platform], enabled },
    }));
  }

  function setTier(platform: PlatformId, tier: TierId) {
    setError("");
    setSelection((current) => ({
      ...current,
      [platform]: { enabled: true, tier },
    }));
  }

  function startTrial() {
    writeCart(lines, interval);
    router.push(signedIn ? "/onboarding" : "/register");
  }

  async function checkout() {
    setError("");

    if (lines.length === 0 || !quote) return;

    if (!getToken()) {
      writeCart(lines, interval);
      router.push("/login?next=/pricing");
      return;
    }

    setSubmitting(true);

    try {
      const res = await api<
        | { checkout_url: string; subscription_id: number; scheduled?: undefined }
        | { scheduled: true; effective_at: string | null }
      >(
        "/billing/checkout",
        {
          method: "POST",
          body: JSON.stringify({ lines, interval }),
        },
        30000
      );
      if (res.scheduled) {
        setScheduledAt(res.effective_at);
        return;
      }
      sessionStorage.setItem(PENDING_SUBSCRIPTION_KEY, String(res.subscription_id));
      sessionStorage.setItem(BILLING_CART_KEY, JSON.stringify({ lines, interval }));
      const popup = window.open(res.checkout_url, "_blank", "noopener,noreferrer");
      if (!popup) {
        window.location.href = res.checkout_url;
        return;
      }
      setWaitingId(res.subscription_id);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Checkout failed.");
      receiptRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
      requestAnimationFrame(() => alertRef.current?.focus());
    } finally {
      setSubmitting(false);
    }
  }

  const limited = quote?.lines.some((line) => line.discount_limited) ?? false;

  return (
    <section
      id="pricing"
      ref={ref}
      className={`landing-reveal ${showIntro ? "py-24" : ""} ${visible ? "visible" : ""}`}
    >
      <div className={showIntro ? "mx-auto max-w-6xl px-6" : ""}>
        {showIntro && (
          <div className="mx-auto max-w-2xl text-center">
            <p className="mb-3 text-sm font-medium uppercase tracking-widest text-[#f0b2a6]">Pricing</p>
            <h2 className="landing-section-title motion-copy motion-live">Pay for the platforms you use</h2>
            <p className="motion-copy mt-4 text-lg text-muted">
              X, TikTok, and YouTube are priced on their own. Starter, Pro, and Agency can differ on each
              one. The most expensive line stays full price. Every other platform is{" "}
              {catalog?.discount_percent ?? 15}% off.
            </p>
          </div>
        )}

        <div className={`${showIntro ? "mt-12" : "mt-2"} flex justify-center`}>
          <div className="inline-flex gap-1 rounded-full border border-border bg-card p-1">
            <button
              type="button"
              onClick={() => setInterval("monthly")}
              className={`rounded-full px-5 py-2 text-sm ${
                interval === "monthly" ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"
              }`}
              aria-pressed={interval === "monthly"}
            >
              Monthly
            </button>
            <button
              type="button"
              onClick={() => setInterval("yearly")}
              className={`rounded-full px-5 py-2 text-sm ${
                interval === "yearly" ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"
              }`}
              aria-pressed={interval === "yearly"}
            >
              Yearly
            </button>
          </div>
        </div>

        {loadError && (
          <p className="mt-8 text-center text-sm text-red-300" role="alert">
            {loadError}
          </p>
        )}

        <div className="mt-6">
          {catalog?.platforms.map((platform) => {
            const state = selection[platform.id];

            return (
              <div key={platform.id} className="border-t border-border pt-14">
                <div className="flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
                  <div className="max-w-xl">
                    <h3 className="display text-3xl text-foreground">
                      <SocialName brand={platform.id as SocialBrand} className="gap-3" logoClassName="h-7 w-7">
                        {platform.name}
                      </SocialName>
                    </h3>
                    <p className="mt-3 max-w-md text-base leading-7 text-muted-foreground">{platform.summary}</p>
                  </div>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    aria-pressed={state.enabled}
                    onClick={() => setEnabled(platform.id, !state.enabled)}
                  >
                    {state.enabled ? "Remove" : `Add ${platform.name}`}
                  </button>
                </div>

                <div
                  className="mt-10 grid items-stretch gap-6 md:grid-cols-3"
                  role="radiogroup"
                  aria-label={`${platform.name} packages`}
                >
                  {platform.tiers.map((tier) => {
                    const selected = state.enabled && state.tier === tier.id;
                    return (
                      <button
                        key={tier.id}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => setTier(platform.id, tier.id)}
                        className={`flex h-full flex-col rounded-[0.9rem] border bg-card p-6 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground sm:p-7 ${
                          selected ? "border-accent" : "border-border hover:border-foreground/25"
                        }`}
                      >
                        <span className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                          {tier.name}
                          {selected && <span className="text-foreground">In this mix</span>}
                        </span>
                        <span className="display mt-5 text-4xl leading-none text-foreground">
                          {formatCents(interval === "yearly" ? tier.yearly_cents : tier.list_cents)}
                        </span>
                        <span className="mt-2 text-sm text-muted-foreground">
                          {interval === "yearly"
                            ? `per year, save ${Math.round((1 - tier.yearly_cents / (tier.list_cents * 12)) * 100)}%`
                            : "per month"}
                        </span>
                        <ul className="mt-8 space-y-0 border-t border-border pt-2 text-sm leading-6 text-muted-foreground">
                          {tier.features.map((feature) => (
                            <li key={feature} className="border-b border-border/80 py-3 last:border-b-0">
                              {feature}
                            </li>
                          ))}
                        </ul>
                      </button>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>

        <div ref={receiptRef} className="mt-20 scroll-mt-28 border-t border-border pt-12">
          {offerTrial && (
            <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-end">
              <div>
                <h3 className="display text-3xl text-foreground">
                  Try it free for {catalog?.trial_days ?? 7} days
                </h3>
                <p className="mt-3 max-w-xl text-base leading-7 text-muted-foreground">
                  No card needed. Create your account and pick your platforms. The trial starts then, with the
                  packages you chose above. When it ends, you choose whether to upgrade.
                </p>
              </div>
              <button type="button" className="btn btn-primary w-full" onClick={startTrial}>
                Start free trial
              </button>
            </div>
          )}

          {offerTrial === false && (
            <>
              <h3 className="display text-3xl text-foreground">The total</h3>
              <p className="mt-3 max-w-xl text-base leading-7 text-muted-foreground">
                One payment. The highest package stays at its shelf price. Each other platform is{" "}
                {catalog?.discount_percent ?? 15}% off.
              </p>

              {error && (
                <div
                  ref={alertRef}
                  tabIndex={-1}
                  role="alert"
                  className="mt-8 max-w-xl rounded-md border border-red-500/50 bg-red-500/10 p-4 text-sm text-red-200 outline-none"
                >
                  <p className="font-medium text-red-100">Checkout didn&apos;t start</p>
                  <p className="mt-1 leading-6">{error}</p>
                </div>
              )}

              <div className="mt-10 grid gap-10 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-end">
                <ul className="divide-y divide-border border-y border-border">
                  {quote?.lines.map((line) => (
                    <li key={line.platform} className="flex items-baseline justify-between gap-6 py-4">
                      <span>
                        <span className="block text-base text-foreground">
                          {line.platform_name} {line.tier_name}
                        </span>
                        <span className="mt-1 block text-sm leading-6 text-muted-foreground">
                          {line.monthly_allowance} {line.allowance_unit} a month, {line.accounts_included}{" "}
                          {line.accounts_included === 1 ? "account" : "accounts"}
                        </span>
                      </span>
                      <span className="text-right text-base text-foreground">
                        {line.discount_cents > 0 && (
                          <span className="mr-3 text-sm text-muted-foreground line-through">
                            {formatCents(line.list_cents)}
                          </span>
                        )}
                        {formatCents(line.charged_cents)}
                      </span>
                    </li>
                  ))}
                  {lines.length === 0 && (
                    <li className="py-4 text-base text-muted-foreground">Add at least one platform.</li>
                  )}
                </ul>

                <div>
                  <p className="text-sm text-muted-foreground">
                    {change?.kind === "upgrade"
                      ? "Due today"
                      : change?.kind === "downgrade"
                        ? `From ${formatDate(change.effective_at)}`
                        : interval === "yearly"
                          ? "Due yearly"
                          : "Due monthly"}
                  </p>
                  <p className="display mt-2 text-5xl leading-none text-foreground">
                    {!quote ? "—" : change?.kind === "upgrade" ? formatCents(change.charge_now_cents) : formatCents(quote.total_cents)}
                  </p>
                  {quote && change?.kind === "upgrade" && (
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">
                      {formatCents(quote.total_cents)} minus {formatCents(change.credit_cents)} credit for the unused
                      time on your current plan. Then {formatCents(quote.total_cents)}
                      {quote.period}.
                    </p>
                  )}
                  {quote && change?.kind === "downgrade" && (
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">
                      Nothing is charged today. Your current plan stays until {formatDate(change.effective_at)}, then this
                      mix starts and we ask you to confirm the payment.
                    </p>
                  )}
                  {quote && change?.kind === "same" && (
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">
                      {change.current?.renews === false
                        ? `This is your current plan. It stops renewing on ${formatDate(change.current.current_period_ends_at)}. Keep it to confirm payment for it then.`
                        : "This is your current plan. Change a platform or package to update it."}
                    </p>
                  )}
                  {quote && change?.kind === "new" && (
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">
                      {quote.discount_cents > 0 &&
                        (limited
                          ? `You save ${formatCents(quote.discount_cents)}. One platform is already at its lowest price. `
                          : `You save ${formatCents(quote.discount_cents)} against the shelf prices. `)}
                      {interval === "yearly" && "Billed once a year. "}
                      {trialing && "Paying now ends your free trial today."}
                    </p>
                  )}
                  {scheduledAt && (
                    <p className="mt-3 text-sm leading-6 text-foreground" role="status">
                      Change saved. Your current plan runs until {formatDate(scheduledAt)}.
                    </p>
                  )}
                  {paid ? (
                    <div className="mt-6">
                      <p className="text-sm leading-6 text-foreground">Payment confirmed. This mix is active.</p>
                      <Link href="/dashboard" className="btn btn-primary mt-4 w-full">
                        Go to the desk
                      </Link>
                    </div>
                  ) : (
                    <button
                      type="button"
                      className="btn btn-primary mt-6 w-full"
                      disabled={
                        lines.length === 0 ||
                        quoting ||
                        submitting ||
                        !quote ||
                        Boolean(scheduledAt) ||
                        (change?.kind === "same" && change.current?.renews !== false)
                      }
                      onClick={() => void checkout()}
                    >
                      {submitting
                        ? "Saving…"
                        : waitingId
                          ? "Waiting for payment…"
                          : change?.kind === "downgrade"
                            ? "Schedule this change"
                            : change?.kind === "same"
                              ? change.current?.renews === false
                                ? "Keep this plan"
                                : "Current plan"
                              : change?.kind === "upgrade"
                                ? "Upgrade now"
                                : "Continue to checkout"}
                    </button>
                  )}
                  {waitingId && !paid && (
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">
                      Finish payment in the checkout tab. This page updates when it confirms.
                    </p>
                  )}
                </div>
              </div>
            </>
          )}
        </div>
      </div>

      {showDock && (
        <div className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card px-6 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <div className="mx-auto flex max-w-6xl items-center justify-between gap-4">
            <div className="min-w-0">
              <p className="truncate text-sm text-muted-foreground">
                {quote?.lines.map((line) => `${line.platform_name} ${line.tier_name}`).join(", ") ||
                  "Add a platform"}
              </p>
              <p className="display text-2xl leading-none text-foreground">
                {!quote ? "—" : change?.kind === "upgrade" ? formatCents(change.charge_now_cents) : formatCents(quote.total_cents)}
                <span className="ml-1 font-sans text-sm text-muted-foreground">
                  {change?.kind === "upgrade" ? "today" : quote?.period}
                </span>
              </p>
            </div>
            <button
              type="button"
              className="btn btn-primary shrink-0"
              disabled={
                lines.length === 0 || quoting || submitting || !quote || Boolean(paid) || change?.kind === "same"
              }
              onClick={() => void checkout()}
            >
              {paid ? "Paid" : change?.kind === "downgrade" ? "Schedule change" : "Checkout"}
            </button>
          </div>
        </div>
      )}
    </section>
  );
}
