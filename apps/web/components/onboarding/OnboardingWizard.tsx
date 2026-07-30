"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { BrandLogo } from "@/components/BrandLogo";
import { MultiSelectCards, SelectionHint } from "@/components/onboarding/MultiSelectCards";
import { api, getToken } from "@/lib/api";
import {
  GOALS,
  joinMultiValue,
  MAX_PROFILE_SELECTIONS,
  NICHES,
  parseMultiValue,
  TONES,
} from "@/lib/profile";

type OnboardingData = {
  goals: string[];
  niches: string[];
  custom_niche: string;
  description: string;
  audience: string;
  tones: string[];
  mode: string;
  automation_level: number;
};

type XConnectStatus = {
  available: boolean;
  connected: boolean;
  username?: string;
  authorize_url?: string | null;
  message: string;
};

type AccountProfile = {
  goal?: string;
  niche?: string | null;
  custom_niche?: string | null;
  description?: string;
  audience?: string;
  tone?: string;
  mode?: string;
  automation_level?: number;
  onboarding_completed?: boolean;
};

const STEPS = [
  { title: "Your goal", subtitle: "What do you want from X? Pick up to 3." },
  { title: "Your niche", subtitle: "What topics do you cover? Pick up to 3." },
  { title: "Your account", subtitle: "Describe what you're building" },
  { title: "Your audience", subtitle: "Who are you trying to reach?" },
  { title: "Your voice", subtitle: "How should your posts feel? Pick up to 3." },
  { title: "Your setup", subtitle: "How should Xpulse work for you?" },
  { title: "Connect X", subtitle: "Authorize Xpulse to act on your behalf" },
];

function profileToOnboardingData(profile: AccountProfile): OnboardingData {
  const niches = parseMultiValue(profile.niche);

  return {
    goals: parseMultiValue(profile.goal),
    niches: profile.custom_niche ? [...niches, "custom"] : niches,
    custom_niche: profile.custom_niche ?? "",
    description: profile.description ?? "",
    audience: profile.audience ?? "",
    tones: parseMultiValue(profile.tone),
    mode: profile.mode ?? "reactive",
    automation_level: profile.automation_level ?? 1,
  };
}

export function OnboardingWizard() {
  const router = useRouter();
  const [step, setStep] = useState(0);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [profileSaved, setProfileSaved] = useState(false);
  const [xStatus, setXStatus] = useState<XConnectStatus | null>(null);
  const [connectingX, setConnectingX] = useState(false);
  const [data, setData] = useState<OnboardingData>({
    goals: [],
    niches: [],
    custom_niche: "",
    description: "",
    audience: "",
    tones: [],
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
        const me = await api<{ user: { is_admin?: boolean; account_profile?: AccountProfile } }>(
          "/auth/me"
        );
        if (me.user.is_admin) {
          router.replace("/admin");
          return;
        }
        if (me.user.account_profile?.onboarding_completed) {
          router.replace("/dashboard");
          return;
        }

        const profileRes = await api<{ profile: AccountProfile | null }>("/account/profile");
        const profile = profileRes.profile;

        if (profile?.goal) {
          setData(profileToOnboardingData(profile));
          setProfileSaved(true);
          setStep(STEPS.length - 1);
        }

        await loadXStatus();
      } catch {
        router.replace("/login");
      } finally {
        setLoading(false);
      }
    }

    checkProfile();
  }, [router]);

  async function loadXStatus() {
    try {
      const status = await api<XConnectStatus>("/connected-accounts/x/status");
      setXStatus(status);
    } catch {
      setXStatus({
        available: false,
        connected: false,
        message: "Could not check X connection status.",
      });
    }
  }

  useEffect(() => {
    if (step === STEPS.length - 1 && !xStatus) {
      loadXStatus();
    }
  }, [step, xStatus]);

  function showLimitError() {
    setError(`You can select at most ${MAX_PROFILE_SELECTIONS} options.`);
  }

  function updateField<K extends keyof OnboardingData>(key: K, value: OnboardingData[K]) {
    setData((current) => ({ ...current, [key]: value }));
    setError("");
  }

  function canContinue() {
    switch (step) {
      case 0:
        return data.goals.length >= 1;
      case 1:
        return (
          data.niches.length >= 1 &&
          (!data.niches.includes("custom") || data.custom_niche.trim().length > 2)
        );
      case 2:
        return data.description.trim().length >= 20;
      case 3:
        return data.audience.trim().length >= 10;
      case 4:
        return data.tones.length >= 1;
      case 5:
        return true;
      case 6:
        return true;
      default:
        return false;
    }
  }

  function buildProfilePayload(completeOnboarding: boolean) {
    const presetNiches = data.niches.filter((niche) => niche !== "custom");

    return {
      goal: joinMultiValue(data.goals),
      niche: presetNiches.length > 0 ? joinMultiValue(presetNiches) : null,
      custom_niche: data.niches.includes("custom") ? data.custom_niche : null,
      description: data.description,
      audience: data.audience,
      tone: joinMultiValue(data.tones),
      mode: data.mode,
      automation_level: data.automation_level,
      complete_onboarding: completeOnboarding,
    };
  }

  async function saveProfile(completeOnboarding: boolean) {
    await api("/account/profile", {
      method: "POST",
      body: JSON.stringify(buildProfilePayload(completeOnboarding)),
    });
    setProfileSaved(true);
  }

  async function connectX() {
    if (!xStatus?.authorize_url) {
      return;
    }
    setConnectingX(true);
    window.location.href = xStatus.authorize_url;
  }

  async function finish() {
    setSubmitting(true);
    setError("");
    try {
      if (!profileSaved) {
        await saveProfile(false);
      }
      await saveProfile(true);
      router.push("/dashboard");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not finish setup");
    } finally {
      setSubmitting(false);
    }
  }

  async function advanceFromSetup() {
    setSubmitting(true);
    setError("");
    try {
      await saveProfile(false);
      setStep((current) => current + 1);
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
    if (step === STEPS.length - 2) {
      advanceFromSetup();
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
    <div className="min-h-screen overflow-x-hidden px-4 py-8 sm:px-6 sm:py-10">
      <div className="mx-auto max-w-2xl">
        <BrandLogo className="mb-8 text-lg sm:mb-10" />

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
          <h1 className="text-xl font-bold sm:text-2xl">{STEPS[step].title}</h1>
          <p className="mb-6 mt-1 text-muted">{STEPS[step].subtitle}</p>

          {error && (
            <div className="mb-4 rounded-lg border border-red-500/50 bg-red-500/10 p-3 text-sm text-red-300">
              {error}
            </div>
          )}

          {step === 0 && (
            <>
              <SelectionHint selectedCount={data.goals.length} />
              <MultiSelectCards
                options={GOALS}
                selected={data.goals}
                onChange={(goals) => updateField("goals", goals)}
                onLimitReached={showLimitError}
              />
            </>
          )}

          {step === 1 && (
            <div className="space-y-4">
              <SelectionHint selectedCount={data.niches.length} />
              <MultiSelectCards
                options={NICHES}
                selected={data.niches}
                onChange={(niches) => updateField("niches", niches)}
                onLimitReached={showLimitError}
              />
              {data.niches.includes("custom") && (
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
                {data.description.length}/20 characters minimum. The more detail, the better Xpulse
                understands you.
              </p>
            </div>
          )}

          {step === 3 && (
            <div>
              <label htmlFor="audience">Who follows you, or who do you want to reach?</label>
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
            <>
              <SelectionHint selectedCount={data.tones.length} />
              <MultiSelectCards
                options={TONES}
                selected={data.tones}
                onChange={(tones) => updateField("tones", tones)}
                onLimitReached={showLimitError}
                columns={1}
              />
            </>
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
                  <option value="reactive">Reactive: news, events, and trends first</option>
                  <option value="proactive">Proactive: original posts when things are quiet</option>
                </select>
              </div>
              <div>
                <label htmlFor="automation_level">How hands-on do you want to be?</label>
                <select
                  id="automation_level"
                  value={data.automation_level}
                  onChange={(e) => updateField("automation_level", Number(e.target.value))}
                >
                  <option value={0}>Suggest only: I approve everything</option>
                  <option value={1}>Semi-auto: Xpulse drafts, I decide what goes live</option>
                  <option value={2}>Mostly auto: post when opportunity is high</option>
                  <option value={3}>Full auto: handle growth while I focus elsewhere</option>
                </select>
              </div>
              <div className="rounded-xl border border-accent/20 bg-accent/5 p-4 text-sm text-muted">
                Almost done. Next you&apos;ll connect your X account so Xpulse can read metrics and
                post on your behalf when you approve.
              </div>
            </div>
          )}

          {step === 6 && (
            <div className="space-y-5">
              <div className="rounded-xl border border-border bg-surface-2 p-5">
                <p className="text-sm text-muted">
                  Xpulse needs your permission to act on your X account. You&apos;ll be redirected
                  to X to review and approve access — we never see your password.
                </p>
                <ul className="mt-4 space-y-2 text-sm text-muted">
                  <li>Read your profile and public metrics</li>
                  <li>Draft replies and posts for your review</li>
                  <li>Publish content only when you approve or enable automation</li>
                </ul>
              </div>

              {xStatus?.connected ? (
                <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm">
                  <p className="font-semibold text-emerald-300">X account connected</p>
                  <p className="mt-1 text-muted">
                    {xStatus.username ? `@${xStatus.username}` : xStatus.message}
                  </p>
                </div>
              ) : (
                <button
                  type="button"
                  onClick={connectX}
                  disabled={connectingX || !xStatus?.authorize_url}
                  className="btn btn-primary w-full disabled:opacity-50"
                >
                  {connectingX
                    ? "Redirecting to X…"
                    : xStatus?.authorize_url
                      ? "Connect X account"
                      : "Connect X (coming soon)"}
                </button>
              )}

              {!xStatus?.connected && xStatus && !xStatus.authorize_url && (
                <p className="text-center text-xs text-muted">{xStatus.message}</p>
              )}

              {!xStatus?.connected && (
                <p className="text-center text-xs text-muted">
                  You can connect later from Settings. Finish setup to explore Xpulse with preview
                  data.
                </p>
              )}
            </div>
          )}

          <div className="mt-8 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
            <button
              type="button"
              onClick={back}
              disabled={step === 0 || submitting}
              className="btn btn-secondary w-full disabled:opacity-40 sm:w-auto"
            >
              Back
            </button>
            <button
              type="button"
              onClick={next}
              disabled={submitting}
              className="btn btn-primary w-full min-w-0 sm:w-auto sm:min-w-[140px]"
            >
              {submitting
                ? "Saving…"
                : step === STEPS.length - 1
                  ? xStatus?.connected
                    ? "Finish setup"
                    : "Skip for now"
                  : step === STEPS.length - 2
                    ? "Continue"
                    : "Continue"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
