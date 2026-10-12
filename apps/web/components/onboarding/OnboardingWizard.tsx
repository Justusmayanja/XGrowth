"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Check } from "lucide-react";
import { MultiSelectCards, SelectionHint } from "@/components/onboarding/MultiSelectCards";
import { SetupFrame } from "@/components/setup/SetupFrame";
import { SocialLogo } from "@/components/SocialLogo";
import { api, getToken } from "@/lib/api";
import { readCart } from "@/lib/billing";
import {
  ABOUT_COPY,
  type Discovery,
  formatMultiLabels,
  MAX_PROFILE_SELECTIONS,
  needsNicheDiscovery,
  type NicheIdea,
  NICHES,
  type Platform,
  PLATFORM_GOALS,
  PLATFORM_ORDER,
  platformName,
  PLATFORMS,
  PRESENCE,
  type Purpose,
  PURPOSES,
  type SetupState,
  SIDE_HUSTLE_GOALS,
  TONES,
  WEEKLY_TIME,
} from "@/lib/profile";

type ChapterSetup = {
  goals: string[];
  automation_level: number;
  mode: string;
  publish: string;
  completed: boolean;
};

type WizardData = {
  platforms: Platform[];
  purpose: Purpose | null;
  discovery: Discovery;
  niches: string[];
  custom_niche: string;
  description: string;
  audience: string;
  tones: string[];
  setups: Record<Platform, ChapterSetup>;
};

type XConnectStatus = {
  available: boolean;
  connected: boolean;
  username?: string;
  authorize_url?: string | null;
  message: string;
};

type SharedStep = "platforms" | "purpose" | "discovery" | "niche" | "describe" | "audience" | "voice";
type ChapterStage = "goals" | "pace" | "connect" | "publish" | "done";
type Step = { kind: "shared"; key: SharedStep } | { kind: "chapter"; platform: Platform; stage: ChapterStage };

const MODES = [
  { value: "reactive", label: "When something is happening", hint: "News, events, and topics people are already talking about." },
  { value: "proactive", label: "Even on a quiet day", hint: "Original posts from the account you described, when the niche is still." },
];

const HANDS = [
  { value: 0, label: "I approve everything", hint: "Mackie17 only suggests." },
  { value: 1, label: "I decide what goes live", hint: "It drafts. You say yes." },
  { value: 2, label: "Post when the moment is strong", hint: "It publishes the clear ones and holds the rest." },
  { value: 3, label: "Run it while I am away", hint: "Full posting, inside the limits you set." },
];

const YOUTUBE_PUBLISH = [
  { value: "wait", label: "Wait for me", hint: "It prepares the title, description, and timing. You publish." },
  { value: "auto", label: "Post on its own", hint: "Uploads you approve go out at the suggested time." },
];

const CHAPTER_STAGES: Record<Platform, ChapterStage[]> = {
  x: ["goals", "pace", "connect", "done"],
  tiktok: ["goals", "publish", "done"],
  youtube: ["goals", "publish", "done"],
};

const EMPTY_SETUP: ChapterSetup = {
  goals: [],
  automation_level: 1,
  mode: "reactive",
  publish: "wait",
  completed: false,
};

function sortPlatforms(platforms: Platform[]): Platform[] {
  return PLATFORM_ORDER.filter((platform) => platforms.includes(platform));
}

function buildSteps(
  platforms: Platform[],
  purpose: Purpose | null,
  focus: Platform | null,
  includeAbout: boolean
): Step[] {
  const steps: Step[] = [];

  if (!focus) steps.push({ kind: "shared", key: "platforms" });

  if (!focus || includeAbout) {
    steps.push({ kind: "shared", key: "purpose" });
    steps.push(
      needsNicheDiscovery(purpose) ? { kind: "shared", key: "discovery" } : { kind: "shared", key: "niche" }
    );
    steps.push({ kind: "shared", key: "describe" });
    steps.push({ kind: "shared", key: "audience" });
    steps.push({ kind: "shared", key: "voice" });
  }

  const chapters = focus ? [focus] : sortPlatforms(platforms);
  for (const platform of chapters) {
    for (const stage of CHAPTER_STAGES[platform]) {
      steps.push({ kind: "chapter", platform, stage });
    }
  }

  return steps;
}

function stateToData(state: SetupState, focus: Platform | null): WizardData {
  const about = state.about;
  let platforms = state.platforms.length > 0 ? state.platforms : state.paid_platforms;
  if (focus && !platforms.includes(focus)) platforms = [...platforms, focus];

  const setups = {} as Record<Platform, ChapterSetup>;
  for (const platform of PLATFORM_ORDER) {
    const saved = state.setups[platform];
    setups[platform] = {
      goals: saved?.goals ?? [],
      automation_level: saved?.settings?.automation_level ?? 1,
      mode: saved?.settings?.mode ?? "reactive",
      publish: saved?.settings?.publish === "auto" ? "auto" : "wait",
      completed: saved?.completed ?? false,
    };
  }

  const niches = about?.niche ?? [];
  const discovery = about?.discovery ?? {};
  const usesDiscovery = needsNicheDiscovery(about?.purpose);

  return {
    platforms: sortPlatforms(platforms),
    purpose: about?.purpose ?? null,
    discovery,
    niches: about?.custom_niche && !usesDiscovery ? [...niches, "custom"] : niches,
    custom_niche: about?.custom_niche ?? "",
    description: about?.description ?? "",
    audience: about?.audience ?? "",
    tones: about?.tone ?? [],
    setups,
  };
}

function ChoiceList<T extends string | number>({
  options,
  value,
  onChange,
}: {
  options: Array<{ value: T; label: string; hint?: string }>;
  value: T | null | undefined;
  onChange: (value: T) => void;
}) {
  return (
    <div className="flex flex-col gap-2" role="radiogroup">
      {options.map((option) => {
        const on = value === option.value;
        return (
          <button
            key={String(option.value)}
            type="button"
            role="radio"
            aria-checked={on}
            className={`choice-row ${on ? "is-on" : ""}`}
            onClick={() => onChange(option.value)}
          >
            <span>
              <strong>{option.label}</strong>
              {option.hint ? <em>{option.hint}</em> : null}
            </span>
            {on ? <Check className="mt-0.5 h-4 w-4 shrink-0 text-[#7eb0e4]" aria-hidden="true" /> : null}
          </button>
        );
      })}
    </div>
  );
}

export function OnboardingWizard() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const focusParam = searchParams.get("platform");
  const focus = PLATFORM_ORDER.includes(focusParam as Platform) ? (focusParam as Platform) : null;

  const [loading, setLoading] = useState(true);
  const [index, setIndex] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [paidPlatforms, setPaidPlatforms] = useState<Platform[]>([]);
  const [aboutDoneAtLoad, setAboutDoneAtLoad] = useState(false);
  const [xStatus, setXStatus] = useState<XConnectStatus | null>(null);
  const [connectingX, setConnectingX] = useState(false);
  const [ideas, setIdeas] = useState<NicheIdea[]>([]);
  const [ideasNote, setIdeasNote] = useState("");
  const [loadingIdeas, setLoadingIdeas] = useState(false);
  const [writingOwnNiche, setWritingOwnNiche] = useState(false);
  const [data, setData] = useState<WizardData>({
    platforms: [],
    purpose: null,
    discovery: {},
    niches: [],
    custom_niche: "",
    description: "",
    audience: "",
    tones: [],
    setups: { x: { ...EMPTY_SETUP }, tiktok: { ...EMPTY_SETUP }, youtube: { ...EMPTY_SETUP } },
  });

  const steps = useMemo(
    () => buildSteps(data.platforms, data.purpose, focus, focus ? !aboutDoneAtLoad : true),
    [data.platforms, data.purpose, focus, aboutDoneAtLoad]
  );
  const step = steps[Math.min(index, steps.length - 1)];

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }

    async function load() {
      try {
        const me = await api<{ user: { is_admin?: boolean } }>("/auth/me");
        if (me.user.is_admin) {
          router.replace("/admin");
          return;
        }

        const state = await api<SetupState>("/account/setup");
        const loaded = stateToData(state, focus);
        const pending = loaded.platforms.filter((platform) => !loaded.setups[platform].completed);

        if (!focus && state.about_complete && loaded.platforms.length > 0 && pending.length === 0) {
          router.replace("/dashboard");
          return;
        }

        if (needsNicheDiscovery(loaded.purpose) && loaded.discovery.picked === undefined && loaded.custom_niche) {
          setWritingOwnNiche(true);
        }

        setPaidPlatforms(state.paid_platforms);
        setAboutDoneAtLoad(state.about_complete);
        setData(loaded);

        const built = buildSteps(loaded.platforms, loaded.purpose, focus, focus ? !state.about_complete : true);
        let start = 0;
        if (focus) {
          start = state.about_complete
            ? built.findIndex((s) => s.kind === "chapter" && s.platform === focus)
            : 0;
        } else if (state.platforms.length === 0) {
          start = 0;
        } else if (!state.about_complete) {
          start = built.findIndex((s) => s.kind === "shared" && s.key === "purpose");
        } else {
          start = built.findIndex((s) => s.kind === "chapter" && s.platform === pending[0]);
        }
        enterStep(Math.max(0, start), built, loaded);
      } catch {
        router.replace("/login");
      } finally {
        setLoading(false);
      }
    }

    load();
  }, [router, focus]);

  useEffect(() => {
    if (step?.kind === "chapter" && step.stage === "connect" && !xStatus) {
      api<XConnectStatus>("/connected-accounts/x/status")
        .then(setXStatus)
        .catch(() =>
          setXStatus({ available: false, connected: false, message: "Could not check the X connection." })
        );
    }
  }, [step, xStatus]);

  function enterStep(next: number, list: Step[] = steps, current: WizardData = data) {
    const target = list[next];
    if (target?.kind === "chapter" && target.stage === "goals") {
      const setup = current.setups[target.platform];
      if (setup.goals.length === 0 && current.purpose === "side_hustle") {
        setData({
          ...current,
          setups: {
            ...current.setups,
            [target.platform]: { ...setup, goals: SIDE_HUSTLE_GOALS[target.platform] },
          },
        });
      }
    }
    setError("");
    setIndex(next);
    window.scrollTo({ top: 0 });
  }

  function update<K extends keyof WizardData>(key: K, value: WizardData[K]) {
    setData((current) => ({ ...current, [key]: value }));
    setError("");
  }

  function updateSetup(platform: Platform, patch: Partial<ChapterSetup>) {
    setData((current) => ({
      ...current,
      setups: { ...current.setups, [platform]: { ...current.setups[platform], ...patch } },
    }));
    setError("");
  }

  function updateDiscovery(patch: Partial<Discovery>) {
    setData((current) => ({ ...current, discovery: { ...current.discovery, ...patch } }));
    setError("");
  }

  function showLimitError() {
    setError(`You can select at most ${MAX_PROFILE_SELECTIONS} options.`);
  }

  function canContinue(): boolean {
    if (!step) return false;
    if (step.kind === "chapter") {
      if (step.stage === "goals") return data.setups[step.platform].goals.length > 0;
      return true;
    }
    switch (step.key) {
      case "platforms":
        return data.platforms.length > 0;
      case "purpose":
        return data.purpose !== null;
      case "discovery":
        return data.custom_niche.trim().length > 2;
      case "niche":
        return (
          data.niches.length > 0 &&
          (!data.niches.includes("custom") || data.custom_niche.trim().length > 2)
        );
      case "describe":
        return data.description.trim().length >= 20;
      case "audience":
        return data.audience.trim().length >= 10;
      case "voice":
        return data.tones.length > 0;
    }
  }

  async function savePlatforms() {
    const tiers = Object.fromEntries((readCart()?.lines ?? []).map((line) => [line.platform, line.tier]));
    await api("/account/setup/platforms", {
      method: "POST",
      body: JSON.stringify({ platforms: data.platforms, tiers }),
    });
  }

  async function saveAbout() {
    const discovery = needsNicheDiscovery(data.purpose);
    await api("/account/setup/about", {
      method: "POST",
      body: JSON.stringify({
        purpose: data.purpose,
        niche: discovery ? [] : data.niches.filter((niche) => niche !== "custom"),
        custom_niche: discovery || data.niches.includes("custom") ? data.custom_niche.trim() : null,
        description: data.description.trim(),
        audience: data.audience.trim(),
        tone: data.tones,
        discovery: discovery ? data.discovery : null,
      }),
    });
    setAboutDoneAtLoad(true);
  }

  async function saveChapter(platform: Platform) {
    const setup = data.setups[platform];
    const body: Record<string, unknown> = { goals: setup.goals };
    if (platform === "x") {
      body.automation_level = setup.automation_level;
      body.mode = setup.mode;
    }
    if (platform === "youtube") body.publish = setup.publish;

    const state = await api<SetupState>(`/account/setup/platforms/${platform}`, {
      method: "POST",
      body: JSON.stringify(body),
    });
    updateSetup(platform, { completed: state.setups[platform]?.completed ?? true });
  }

  async function next() {
    if (!step) return;
    if (!canContinue()) {
      setError("Please complete this step before continuing.");
      return;
    }

    const following = steps[index + 1];
    const savesChapter = step.kind === "chapter" && following?.kind === "chapter" && following.stage === "done";

    setSubmitting(true);
    try {
      if (step.kind === "shared" && step.key === "platforms") await savePlatforms();
      if (step.kind === "shared" && step.key === "voice") await saveAbout();
      if (savesChapter) await saveChapter(step.platform);
      enterStep(index + 1);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not save your answers");
    } finally {
      setSubmitting(false);
    }
  }

  function back() {
    if (index === 0) return;
    const previous = steps[index - 1];
    enterStep(previous?.kind === "chapter" && previous.stage === "done" ? index - 2 : index - 1);
  }

  async function findNiches() {
    const { interests, weekly_time, presence } = data.discovery;
    if (!interests?.trim() || !weekly_time || !presence) {
      setError("Answer the three questions first.");
      return;
    }
    setLoadingIdeas(true);
    setError("");
    try {
      const res = await api<{ ideas: NicheIdea[]; source: "ai" | "basic" }>(
        "/account/setup/niche-ideas",
        {
          method: "POST",
          body: JSON.stringify({ interests, weekly_time, presence, platforms: data.platforms }),
        },
        30000
      );
      setIdeas(res.ideas);
      setIdeasNote(
        res.source === "ai"
          ? "Pick the one you could keep up for a year."
          : "Starting points based on what you typed. Pick one and adjust it."
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not suggest niches");
    } finally {
      setLoadingIdeas(false);
    }
  }

  function pickIdea(idea: NicheIdea) {
    setWritingOwnNiche(false);
    setData((current) => ({
      ...current,
      custom_niche: idea.niche,
      discovery: { ...current.discovery, picked: idea.niche },
      description: current.description.trim() ? current.description : idea.angle,
    }));
    setError("");
  }

  if (loading || !step) {
    return (
      <div className="setup flex min-h-screen items-center justify-center">
        <p className="text-[#a1a1aa]">Loading…</p>
      </div>
    );
  }

  const purpose = data.purpose ?? "creator";
  const copy = ABOUT_COPY[purpose];
  const chapters = focus ? [focus] : data.platforms;

  function heading(): { title: string; lede: string } {
    if (step.kind === "shared") {
      switch (step.key) {
        case "platforms":
          return {
            title: "Which platforms are you interested in?",
            lede:
              paidPlatforms.length > 0
                ? "We ticked the ones in your plan. Each one gets its own short setup."
                : "Pick one or more. Your free trial starts with these, and each one gets its own short setup.",
          };
        case "purpose":
          return { title: "What are you using Mackie17 for?", lede: "The next questions change with your answer." };
        case "discovery":
          return {
            title: "Let's find your niche.",
            lede: "Three quick questions. We suggest niches that suit the platforms you picked.",
          };
        case "niche":
          return {
            title: purpose === "business" ? "What field is the business in?" : "What do you cover?",
            lede: "The niche is how it decides what is worth saying.",
          };
        case "describe":
          return { title: copy.describeLabel, lede: copy.describeHint };
        case "audience":
          return { title: copy.audienceLabel, lede: "Be specific. It decides what the drafts talk about." };
        case "voice":
          return { title: copy.voiceTitle, lede: copy.voiceLede };
      }
    }

    const name = platformName(step.platform);
    switch (step.stage) {
      case "goals":
        return {
          title: `What should ${name} do for you?`,
          lede: `Pick up to three. These goals only apply to ${name}.`,
        };
      case "pace":
        return { title: "How should X run?", lede: "You can change this later in Settings." };
      case "connect":
        return { title: "Connect X.", lede: "Optional for now. Drafts work without a connection." };
      case "publish":
        return step.platform === "tiktok"
          ? { title: "How TikTok publishes.", lede: "TikTok never posts on its own. It waits for your yes." }
          : { title: "How should YouTube publish?", lede: "Pick one. You can change it later in Settings." };
      case "done": {
        const nextPlatform = data.platforms.find((p) => p !== step.platform && !data.setups[p].completed);
        return {
          title: `${name} is ready.`,
          lede: nextPlatform
            ? `Next is ${platformName(nextPlatform)}. It takes about a minute.`
            : "Everything you picked is set up. The desk is waiting.",
        };
      }
    }
  }

  const { title, lede } = heading();
  const nextPending =
    step.kind === "chapter" ? data.platforms.find((p) => p !== step.platform && !data.setups[p].completed) : undefined;

  const summary: Array<[string, string]> = [
    ["Using it for", PURPOSES.find((p) => p.value === data.purpose)?.label ?? "—"],
    [
      "Niche",
      needsNicheDiscovery(data.purpose)
        ? data.custom_niche || "—"
        : data.niches.length
          ? formatMultiLabels(
              data.niches.map((n) => (n === "custom" && data.custom_niche ? data.custom_niche : n)),
              NICHES
            )
          : "—",
    ],
    ["Voice", data.tones.length ? formatMultiLabels(data.tones, TONES) : "—"],
    ...data.platforms.map((platform): [string, string] => [
      platformName(platform),
      data.setups[platform].completed
        ? "Ready"
        : step.kind === "chapter" && step.platform === platform
          ? "Setting up"
          : "Waiting",
    ]),
  ];

  const chapterLabel =
    step.kind === "chapter" && step.stage !== "done"
      ? `Setting up ${platformName(step.platform)}${
          chapters.length > 1 ? ` · ${chapters.indexOf(step.platform) + 1} of ${chapters.length}` : ""
        }`
      : null;

  return (
    <SetupFrame
      step={index + 1}
      total={steps.length}
      title={title}
      lede={lede}
      aside={
        <ul>
          {summary.map(([label, value]) => (
            <li key={label}>
              <span>{label}</span>
              <strong className="text-right font-medium">{value}</strong>
            </li>
          ))}
        </ul>
      }
    >
      {chapterLabel && (
        <p className="-mt-2 mb-5 text-xs font-medium uppercase tracking-[0.14em] text-[#7eb0e4]">{chapterLabel}</p>
      )}

      {error && (
        <div className="mb-4 rounded-lg border border-red-500/50 bg-red-500/10 p-3 text-sm text-red-300" role="alert">
          {error}
        </div>
      )}

      {step.kind === "shared" && step.key === "platforms" && (
        <>
          <MultiSelectCards
            options={PLATFORMS.map((p) => ({
              ...p,
              icon: <SocialLogo brand={p.value} />,
              hint: paidPlatforms.includes(p.value) ? `${p.hint} In your plan.` : p.hint,
            }))}
            selected={data.platforms}
            onChange={(selected) => update("platforms", sortPlatforms(selected as Platform[]))}
          />
          <p className="mt-3 text-sm text-[#a1a1aa]">We set them up one at a time, in this order.</p>
        </>
      )}

      {step.kind === "shared" && step.key === "purpose" && (
        <ChoiceList options={PURPOSES} value={data.purpose} onChange={(value) => update("purpose", value)} />
      )}

      {step.kind === "shared" && step.key === "discovery" && (
        <div className="space-y-6">
          <div>
            <label htmlFor="interests">What are you into, or good at?</label>
            <textarea
              id="interests"
              rows={3}
              value={data.discovery.interests ?? ""}
              onChange={(e) => updateDiscovery({ interests: e.target.value })}
              placeholder="Example: cooking cheap meals, football, fixing phones"
            />
          </div>
          <div className="flex flex-col gap-2">
            <p className="text-sm text-[#a1a1aa]">How much time can you give it each week?</p>
            <ChoiceList
              options={WEEKLY_TIME}
              value={data.discovery.weekly_time}
              onChange={(value) => updateDiscovery({ weekly_time: value })}
            />
          </div>
          <div className="flex flex-col gap-2">
            <p className="text-sm text-[#a1a1aa]">How do you want to show up?</p>
            <ChoiceList
              options={PRESENCE}
              value={data.discovery.presence}
              onChange={(value) => updateDiscovery({ presence: value })}
            />
          </div>

          <button type="button" className="setup-secondary" onClick={findNiches} disabled={loadingIdeas}>
            {loadingIdeas ? "Thinking…" : ideas.length ? "Suggest different niches" : "Suggest niches"}
          </button>

          {ideas.length > 0 && (
            <div className="flex flex-col gap-2">
              <p className="text-sm text-[#a1a1aa]">{ideasNote}</p>
              <ChoiceList
                options={[
                  ...ideas.map((idea) => ({ value: idea.niche, label: idea.niche, hint: idea.angle })),
                  { value: "__own", label: "None of these", hint: "I'll write my own niche." },
                ]}
                value={writingOwnNiche ? "__own" : data.discovery.picked}
                onChange={(value) => {
                  if (value === "__own") {
                    setWritingOwnNiche(true);
                    setData((current) => ({
                      ...current,
                      custom_niche: "",
                      discovery: { ...current.discovery, picked: undefined },
                    }));
                    return;
                  }
                  const idea = ideas.find((item) => item.niche === value);
                  if (idea) pickIdea(idea);
                }}
              />
            </div>
          )}

          {(writingOwnNiche || (ideas.length === 0 && data.custom_niche)) && (
            <div>
              <label htmlFor="own_niche">Your niche</label>
              <input
                id="own_niche"
                value={data.custom_niche}
                onChange={(e) => update("custom_niche", e.target.value)}
                placeholder="e.g. Budget cooking for students"
              />
            </div>
          )}
        </div>
      )}

      {step.kind === "shared" && step.key === "niche" && (
        <div className="space-y-4">
          <SelectionHint selectedCount={data.niches.length} />
          <MultiSelectCards
            options={NICHES}
            selected={data.niches}
            onChange={(niches) => update("niches", niches)}
            onLimitReached={showLimitError}
          />
          {data.niches.includes("custom") && (
            <div>
              <label htmlFor="custom_niche">Your niche</label>
              <input
                id="custom_niche"
                value={data.custom_niche}
                onChange={(e) => update("custom_niche", e.target.value)}
                placeholder="e.g. Manchester United fan page, Kampala startups"
              />
            </div>
          )}
        </div>
      )}

      {step.kind === "shared" && step.key === "describe" && (
        <div>
          <textarea
            id="description"
            aria-label={copy.describeLabel}
            rows={5}
            value={data.description}
            onChange={(e) => update("description", e.target.value)}
            placeholder={copy.describePlaceholder}
          />
          {data.description.trim().length < 20 && (
            <p className="mt-2 text-xs text-muted">{data.description.trim().length}/20 characters minimum.</p>
          )}
        </div>
      )}

      {step.kind === "shared" && step.key === "audience" && (
        <textarea
          id="audience"
          aria-label={copy.audienceLabel}
          rows={4}
          maxLength={255}
          value={data.audience}
          onChange={(e) => update("audience", e.target.value)}
          placeholder={copy.audiencePlaceholder}
        />
      )}

      {step.kind === "shared" && step.key === "voice" && (
        <>
          <SelectionHint selectedCount={data.tones.length} />
          <MultiSelectCards
            options={TONES}
            selected={data.tones}
            onChange={(tones) => update("tones", tones)}
            onLimitReached={showLimitError}
            columns={1}
          />
        </>
      )}

      {step.kind === "chapter" && step.stage === "goals" && (
        <>
          <SelectionHint selectedCount={data.setups[step.platform].goals.length} />
          {data.purpose === "side_hustle" && (
            <p className="-mt-1 mb-3 text-sm text-[#a1a1aa]">We ticked the goals that lead to earning. Change them if you like.</p>
          )}
          <MultiSelectCards
            options={PLATFORM_GOALS[step.platform]}
            selected={data.setups[step.platform].goals}
            onChange={(goals) => updateSetup(step.platform, { goals })}
            onLimitReached={showLimitError}
          />
        </>
      )}

      {step.kind === "chapter" && step.stage === "pace" && (
        <div className="space-y-6">
          <div className="flex flex-col gap-2">
            <p className="text-sm text-[#a1a1aa]">When it writes</p>
            <ChoiceList
              options={MODES}
              value={data.setups.x.mode}
              onChange={(mode) => updateSetup("x", { mode })}
            />
          </div>
          <div className="flex flex-col gap-2">
            <p className="text-sm text-[#a1a1aa]">Who presses publish</p>
            <ChoiceList
              options={HANDS}
              value={data.setups.x.automation_level}
              onChange={(automation_level) => updateSetup("x", { automation_level })}
            />
          </div>
        </div>
      )}

      {step.kind === "chapter" && step.stage === "connect" && (
        <div className="space-y-5">
          <div className="choice-note">
            <p className="text-sm font-medium">What Mackie17 asks X for</p>
            <ul className="mt-3 space-y-1 text-sm text-muted">
              <li>Read your profile and public metrics</li>
              <li>Save replies and posts for review</li>
              <li>Publish only when you approve, or when automation is on</li>
            </ul>
            <p className="mt-3 text-sm text-muted">You approve it on X. Mackie17 never sees your password.</p>
          </div>

          {xStatus?.connected ? (
            <div className="choice-note text-sm">
              <p className="font-medium">X is connected</p>
              <p className="mt-1 text-muted">{xStatus.username ? `@${xStatus.username}` : xStatus.message}</p>
            </div>
          ) : xStatus?.authorize_url ? (
            <button
              type="button"
              className="setup-secondary"
              disabled={connectingX}
              onClick={() => {
                setConnectingX(true);
                window.location.href = xStatus.authorize_url as string;
              }}
            >
              {connectingX ? "Opening X…" : "Connect X"}
            </button>
          ) : xStatus ? (
            <p className="text-sm text-muted">{xStatus.message} You can connect X later in Settings.</p>
          ) : null}
        </div>
      )}

      {step.kind === "chapter" && step.stage === "publish" && step.platform === "tiktok" && (
        <div className="space-y-4">
          <div className="choice-note text-sm">
            <p className="font-medium">Every clip waits for you</p>
            <p className="mt-2 leading-relaxed text-muted">
              Mackie17 writes the hook and caption and suggests a time. You post it, or say yes when TikTok
              publishing opens. There is no autopilot on TikTok.
            </p>
          </div>
          <div className="choice-note text-sm">
            <p className="font-medium">Connecting TikTok</p>
            <p className="mt-2 leading-relaxed text-muted">
              The connection opens after TikTok approves the app. You can draft captions now.
            </p>
          </div>
        </div>
      )}

      {step.kind === "chapter" && step.stage === "publish" && step.platform === "youtube" && (
        <div className="space-y-4">
          <ChoiceList
            options={YOUTUBE_PUBLISH}
            value={data.setups.youtube.publish}
            onChange={(publish) => updateSetup("youtube", { publish })}
          />
          <div className="choice-note text-sm">
            <p className="font-medium">Connecting YouTube</p>
            <p className="mt-2 leading-relaxed text-muted">
              The connection opens after Google approves the app. You can draft titles and descriptions now.
            </p>
          </div>
        </div>
      )}

      {step.kind === "chapter" && step.stage === "done" ? (
        <div className="mt-2 flex flex-wrap items-center gap-3">
          {nextPending ? (
            <>
              <button
                type="button"
                className="th-cta"
                onClick={() => {
                  if (focus) {
                    router.push(`/onboarding?platform=${nextPending}`);
                    return;
                  }
                  enterStep(steps.findIndex((s) => s.kind === "chapter" && s.platform === nextPending));
                }}
              >
                Set up {platformName(nextPending)}
              </button>
              <button
                type="button"
                className="setup-secondary"
                onClick={() => router.push(`/dashboard/${step.platform}`)}
              >
                Open the {platformName(step.platform)} desk
              </button>
              <p className="w-full text-sm text-[#a1a1aa]">
                If you stop here, the desk keeps a card to finish {platformName(nextPending)}.
              </p>
            </>
          ) : (
            <button
              type="button"
              className="th-cta"
              onClick={() => router.push(`/dashboard/${step.platform}`)}
            >
              Open the {platformName(step.platform)} desk
            </button>
          )}
        </div>
      ) : (
        <div className="mt-8 flex items-center justify-between gap-3">
          <button
            type="button"
            onClick={back}
            disabled={index === 0 || submitting}
            className="text-sm text-[#a1a1aa] disabled:opacity-40"
          >
            Back
          </button>
          <button type="button" onClick={next} disabled={submitting} className="th-cta">
            {submitting
              ? "Saving…"
              : step.kind === "chapter" && steps[index + 1]?.kind === "chapter" &&
                  (steps[index + 1] as { stage: ChapterStage }).stage === "done"
                ? `Finish ${platformName(step.platform)}`
                : "Continue"}
          </button>
        </div>
      )}
    </SetupFrame>
  );
}
