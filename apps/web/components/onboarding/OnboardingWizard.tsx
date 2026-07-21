"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { BrandLogo } from "@/components/BrandLogo";
import { api, getToken } from "@/lib/api";

type OnboardingData = {
  goal: string;
  niche: string;
  custom_niche: string;
  description: string;
  audience: string;
  tone: string;
  mode: string;
  automation_level: number;
};

const GOALS = [
  { value: "grow_followers", label: "Grow followers", hint: "Build a larger, engaged audience" },
  { value: "build_brand", label: "Build a brand", hint: "Become known in your space" },
  { value: "drive_traffic", label: "Drive traffic", hint: "Send people to your site or product" },
  { value: "promote_business", label: "Promote a business", hint: "Reach customers and clients" },
  { value: "influencer", label: "Build influence", hint: "Grow authority in your niche" },
  { value: "monetize", label: "Monetize on X", hint: "Hit impressions and engagement goals" },
];

const NICHES = [
  "football",
  "technology",
  "business",
  "crypto",
  "motivation",
  "memes",
  "local_news",
  "entertainment",
  "custom",
];

const NICHE_LABELS: Record<string, string> = {
  football: "Football & sports",
  technology: "Technology",
  business: "Business",
  crypto: "Crypto & finance",
  motivation: "Motivation",
  memes: "Memes & humor",
  local_news: "Local news",
  entertainment: "Entertainment",
  custom: "Something else",
};

const TONES = [
  { value: "professional", label: "Professional", hint: "Clear, credible, polished" },
  { value: "passionate", label: "Passionate", hint: "Bold opinions, high energy" },
  { value: "funny", label: "Witty", hint: "Light, sharp, entertaining" },
  { value: "motivational", label: "Motivational", hint: "Inspiring and uplifting" },
  { value: "news", label: "News-style", hint: "Fast, factual, to the point" },
];

const STEPS = [
  { title: "Your goal", subtitle: "What do you want from X?" },
  { title: "Your niche", subtitle: "What topic do you cover?" },
  { title: "Your account", subtitle: "Describe what you're building" },
  { title: "Your audience", subtitle: "Who are you trying to reach?" },
  { title: "Your voice", subtitle: "How should your posts feel?" },
  { title: "Your setup", subtitle: "How should Xpulse work for you?" },
];

export function OnboardingWizard() {
  const router = useRouter();
  const [step, setStep] = useState(0);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [data, setData] = useState<OnboardingData>({
    goal: "",
    niche: "",
    custom_niche: "",
    description: "",
    audience: "",
    tone: "professional",
    mode: "reactive",
    automation_level: 1,
  });

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }

    async function checkProfile() {
      try {
        const me = await api<{ user: { account_profile?: { onboarding_completed?: boolean } } }>(
          "/auth/me"
        );
        if (me.user.account_profile?.onboarding_completed) {
          router.replace("/dashboard");
        }
      } catch {
        router.replace("/login");
      } finally {
        setLoading(false);
      }
    }

    checkProfile();
  }, [router]);

  function updateField<K extends keyof OnboardingData>(key: K, value: OnboardingData[K]) {
    setData((current) => ({ ...current, [key]: value }));
    setError("");
  }

  function canContinue() {
    switch (step) {
      case 0:
        return !!data.goal;
      case 1:
        return data.niche && (data.niche !== "custom" || data.custom_niche.trim().length > 2);
      case 2:
        return data.description.trim().length >= 20;
      case 3:
        return data.audience.trim().length >= 10;
      case 4:
        return !!data.tone;
      case 5:
        return true;
      default:
        return false;
    }
  }

  async function finish() {
    setSubmitting(true);
    setError("");
    try {
      await api("/account/profile", {
        method: "POST",
        body: JSON.stringify({
          goal: data.goal,
          niche: data.niche === "custom" ? null : data.niche,
          custom_niche: data.niche === "custom" ? data.custom_niche : null,
          description: data.description,
          audience: data.audience,
          tone: data.tone,
          mode: data.mode,
          automation_level: data.automation_level,
        }),
      });
      router.push("/dashboard");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not save your answers");
    } finally {
      setSubmitting(false);
    }
  }

  function next() {
    if (!canContinue()) {
      setError("Please complete this step before continuing.");
      return;
    }
    if (step === STEPS.length - 1) {
      finish();
      return;
    }
    setStep((current) => current + 1);
  }

  function back() {
    setError("");
    setStep((current) => Math.max(0, current - 1));
  }

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <p className="text-muted">Loading…</p>
      </div>
    );
  }

  return (
    <div className="min-h-screen px-6 py-10">
      <div className="mx-auto max-w-2xl">
        <BrandLogo className="mb-10 text-lg" />

        <div className="mb-8">
          <div className="mb-3 flex items-center justify-between text-sm text-muted">
            <span>
              Step {step + 1} of {STEPS.length}
            </span>
            <span>{Math.round(((step + 1) / STEPS.length) * 100)}% complete</span>
          </div>
          <div className="h-2 overflow-hidden rounded-full bg-surface-2">
            <div
              className="h-full rounded-full bg-accent transition-all duration-500"
              style={{ width: `${((step + 1) / STEPS.length) * 100}%` }}
            />
          </div>
        </div>

        <div className="card">
          <h1 className="text-2xl font-bold">{STEPS[step].title}</h1>
          <p className="mb-6 mt-1 text-muted">{STEPS[step].subtitle}</p>

          {error && (
            <div className="mb-4 rounded-lg border border-red-500/50 bg-red-500/10 p-3 text-sm text-red-300">
              {error}
            </div>
          )}

          {step === 0 && (
            <div className="grid gap-3 sm:grid-cols-2">
              {GOALS.map((goal) => (
                <button
                  key={goal.value}
                  type="button"
                  onClick={() => updateField("goal", goal.value)}
                  className={`rounded-xl border p-4 text-left transition ${
                    data.goal === goal.value
                      ? "border-accent bg-accent/10"
                      : "border-border hover:border-accent/40"
                  }`}
                >
                  <div className="font-semibold">{goal.label}</div>
                  <div className="mt-1 text-sm text-muted">{goal.hint}</div>
                </button>
              ))}
            </div>
          )}

          {step === 1 && (
            <div className="space-y-4">
              <div className="grid gap-3 sm:grid-cols-2">
                {NICHES.map((niche) => (
                  <button
                    key={niche}
                    type="button"
                    onClick={() => updateField("niche", niche)}
                    className={`rounded-xl border p-4 text-left transition ${
                      data.niche === niche
                        ? "border-accent bg-accent/10"
                        : "border-border hover:border-accent/40"
                    }`}
                  >
                    {NICHE_LABELS[niche]}
                  </button>
                ))}
              </div>
              {data.niche === "custom" && (
                <div>
                  <label htmlFor="custom_niche">Your niche</label>
                  <input
                    id="custom_niche"
                    value={data.custom_niche}
                    onChange={(e) => updateField("custom_niche", e.target.value)}
                    placeholder="e.g. Manchester United fan page, Kampala startups"
                  />
                </div>
              )}
            </div>
          )}

          {step === 2 && (
            <div>
              <label htmlFor="description">In your own words, what is this account about?</label>
              <textarea
                id="description"
                rows={5}
                value={data.description}
                onChange={(e) => updateField("description", e.target.value)}
                placeholder="Example: A fan account focused on transfer news, match reactions, and honest opinions about Manchester United. I want to be first on breaking stories and spark real debate."
              />
              <p className="mt-2 text-xs text-muted">
                {data.description.length}/20 characters minimum — the more detail, the better Xpulse
                understands you.
              </p>
            </div>
          )}

          {step === 3 && (
            <div>
              <label htmlFor="audience">Who follows you — or who do you want to reach?</label>
              <textarea
                id="audience"
                rows={4}
                value={data.audience}
                onChange={(e) => updateField("audience", e.target.value)}
                placeholder="Example: Football fans aged 18–35, mostly UK and Nigeria, who care about transfer rumours, match day banter, and bold takes."
              />
            </div>
          )}

          {step === 4 && (
            <div className="grid gap-3">
              {TONES.map((tone) => (
                <button
                  key={tone.value}
                  type="button"
                  onClick={() => updateField("tone", tone.value)}
                  className={`rounded-xl border p-4 text-left transition ${
                    data.tone === tone.value
                      ? "border-accent bg-accent/10"
                      : "border-border hover:border-accent/40"
                  }`}
                >
                  <div className="font-semibold">{tone.label}</div>
                  <div className="mt-1 text-sm text-muted">{tone.hint}</div>
                </button>
              ))}
            </div>
          )}

          {step === 5 && (
            <div className="space-y-5">
              <div>
                <label htmlFor="mode">Posting style</label>
                <select
                  id="mode"
                  value={data.mode}
                  onChange={(e) => updateField("mode", e.target.value)}
                >
                  <option value="reactive">Reactive — news, events, and trends first</option>
                  <option value="proactive">Proactive — original posts when things are quiet</option>
                </select>
              </div>
              <div>
                <label htmlFor="automation_level">How hands-on do you want to be?</label>
                <select
                  id="automation_level"
                  value={data.automation_level}
                  onChange={(e) => updateField("automation_level", Number(e.target.value))}
                >
                  <option value={0}>Suggest only — I approve everything</option>
                  <option value={1}>Semi-auto — Xpulse drafts, I decide what goes live</option>
                  <option value={2}>Mostly auto — post when opportunity is high</option>
                  <option value={3}>Full auto — handle growth while I focus elsewhere</option>
                </select>
              </div>
              <div className="rounded-xl border border-accent/20 bg-accent/5 p-4 text-sm text-muted">
                You&apos;re all set. Xpulse will use these answers to tailor trends, timing, and
                content to your account.
              </div>
            </div>
          )}

          <div className="mt-8 flex items-center justify-between gap-4">
            <button
              type="button"
              onClick={back}
              disabled={step === 0 || submitting}
              className="btn btn-secondary disabled:opacity-40"
            >
              Back
            </button>
            <button
              type="button"
              onClick={next}
              disabled={submitting}
              className="btn btn-primary min-w-[140px]"
            >
              {submitting
                ? "Saving…"
                : step === STEPS.length - 1
                  ? "Finish setup"
                  : "Continue"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
