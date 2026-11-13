"use client";

import Link from "next/link";
import { PenLine } from "lucide-react";
import { FormEvent, useEffect, useState } from "react";
import { DeskHeading } from "@/components/DeskHeading";
import { AuthImage } from "@/components/PostMedia";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { api, apiForm, DRAFT_TIMEOUT_MS } from "@/lib/api";
import { CONTENT_FORMATS, contentFormatLabel } from "@/lib/content-formats";
import { formatsFor, platformOfFormat, usePlatform } from "@/lib/platform";
import { type AccountProfile, parseMultiValue } from "@/lib/profile";
import { X_COPY } from "@/lib/x-copy";

const TONE_LABELS: Record<string, string> = {
  professional: "Professional",
  passionate: "Passionate",
  funny: "Witty",
  motivational: "Motivational",
  news: "News-style",
};

const COMPOSE_COPY = {
  x: { title: "Compose", lede: X_COPY.composeLede },
  tiktok: { title: "New clip", lede: "Start from an idea. Mackie17 writes the hook and caption for the clip." },
  youtube: { title: "YouTube text", lede: "Write a title, description, or community post. These save as text drafts; they do not create a video package." },
} as const;

type Variant = {
  content: string;
  angle?: string | null;
  virality_score: number;
  estimated_reach: string;
  worth_posting?: boolean;
  virality_reason?: string;
  pick?: "post" | "leave" | "hold";
};

type VisualKind = "text" | "flyer" | "photo";

const VISUAL_LABELS: Record<VisualKind, string> = {
  text: "Text only",
  flyer: "Flyer",
  photo: "Photo",
};

function visualSavedLabel(kind: VisualKind): string {
  return kind === "photo" ? "a photo" : "a flyer";
}

type ComposeMode = "suggest" | "describe";

type AiUsageStatus = {
  web_search_enabled: boolean;
  ai_generations: { used: number; limit: number; remaining: number };
  web_searches: { used: number; limit: number; remaining: number };
};

type GeneratedVisual = { id: string; kind: Exclude<VisualKind, "text"> };

type GeneratedDrafts = {
  variants: Variant[];
  note: string;
  usage?: AiUsageStatus;
  visual?: GeneratedVisual;
  visual_error?: string;
};

const MODES: Array<{ value: ComposeMode; label: string }> = [
  { value: "suggest", label: "Suggest for me" },
  { value: "describe", label: "I'll describe it" },
];

export default function ContentStudioPage() {
  const [variants, setVariants] = useState<Variant[]>([]);
  const [note, setNote] = useState("");
  const [loading, setLoading] = useState(false);
  const [savingId, setSavingId] = useState<number | null>(null);
  const [postingId, setPostingId] = useState<number | null>(null);
  const [tone, setTone] = useState("professional");
  const [contentType, setContentType] = useState("tweet");
  const [useWebSearch, setUseWebSearch] = useState(false);
  const [visualKind, setVisualKind] = useState<VisualKind>("text");
  const [usage, setUsage] = useState<AiUsageStatus | null>(null);

  const [topic, setTopic] = useState("");
  const [reference, setReference] = useState<File | null>(null);
  const [referenceUrl, setReferenceUrl] = useState<string | null>(null);
  const [visual, setVisual] = useState<GeneratedVisual | null>(null);
  const [visualError, setVisualError] = useState<string | null>(null);
  const [mode, setMode] = useState<ComposeMode>("suggest");
  const platform = usePlatform();
  const formats = formatsFor(platform);
  const pictureOn =
    visualKind !== "text" && platform === "x" && (contentType === "tweet" || contentType === "quote");

  useEffect(() => {
    return () => {
      if (referenceUrl) URL.revokeObjectURL(referenceUrl);
    };
  }, [referenceUrl]);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const format = params.get("format");
    if (format && platformOfFormat(format) === platform && CONTENT_FORMATS.some((item) => item.value === format)) {
      setContentType(format);
    } else {
      setContentType(formatsFor(platform)[0].value);
    }
    const linkedTopic = params.get("topic");
    if (linkedTopic) {
      setTopic(linkedTopic);
      setMode("describe");
    }
  }, [platform]);

  useEffect(() => {
    api<{ profile: AccountProfile | null }>("/account/profile")
      .then((res) => {
        const profile = res.profile;
        if (!profile) return;
        const tones = parseMultiValue(profile.tone);
        if (tones[0]) setTone(tones[0]);
      })
      .catch(() => {});
  }, []);

  useEffect(() => {
    api<AiUsageStatus>("/account/ai-usage")
      .then(setUsage)
      .catch(() => {});
  }, []);

  const webSearchDisabled =
    !usage?.web_search_enabled || (usage?.web_searches.remaining ?? 0) <= 0;

  async function write(search: boolean) {
    if (search && webSearchDisabled) {
      toast.error(
        usage && !usage.web_search_enabled
          ? "Search is turned off."
          : "No web lookups left today.",
      );
      return;
    }
    const typed = topic.trim();
    const nextMode: ComposeMode = mode === "describe" || typed.length >= 3 ? "describe" : "suggest";
    if (nextMode === "describe" && typed.length < 3 && !reference) {
      toast.error("Say what you want to post about.");
      return;
    }
    setUseWebSearch(search);
    setLoading(true);
    try {
      const timeout = pictureOn || reference ? 120000 : 60000;
      const visualKindToSend = pictureOn ? visualKind : "text";
      let data: GeneratedDrafts;
      if (reference) {
        const body = new FormData();
        body.append("mode", nextMode);
        if (nextMode === "describe" && typed !== "") body.append("topic", typed);
        body.append("tone", tone);
        body.append("content_type", contentType);
        body.append("use_web_search", search && !webSearchDisabled ? "1" : "0");
        body.append("visual_kind", visualKindToSend);
        body.append("reference", reference);
        data = await apiForm<GeneratedDrafts>(
          "/content/generate",
          body,
          timeout,
          "Writing took too long. Try again.",
        );
      } else {
        data = await api<GeneratedDrafts>(
          "/content/generate",
          {
            method: "POST",
            body: JSON.stringify({
              mode: nextMode,
              topic: nextMode === "describe" ? typed : null,
              tone,
              content_type: contentType,
              use_web_search: search && !webSearchDisabled,
              visual_kind: visualKindToSend,
            }),
          },
          timeout,
          "Writing took too long. Try again.",
        );
      }
      setVariants(data.variants);
      setNote(data.note);
      setVisual(pictureOn ? (data.visual ?? null) : null);
      setVisualError(pictureOn && !data.visual ? (data.visual_error ?? "A picture could not be made.") : null);
      if (data.usage) {
        setUsage(data.usage);
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not write drafts");
    } finally {
      setLoading(false);
    }
  }

  function updateVariant(index: number, content: string) {
    setVariants((current) =>
      current.map((v, i) => (i === index ? { ...v, content } : v))
    );
  }

  async function saveDraft(variant: Variant, index: number) {
    setSavingId(index);
    try {
      const saved = await api<{ media_attached?: boolean }>(
        "/content/drafts",
        {
          method: "POST",
          body: JSON.stringify({
            content: variant.content,
            content_type: contentType,
            virality_predicted: variant.virality_score,
            with_visual: pictureOn,
            visual_kind: pictureOn ? visualKind : "text",
            visual_preview: pictureOn && visual && index === 0 ? visual.id : undefined,
          }),
        },
        DRAFT_TIMEOUT_MS,
      );
      if (pictureOn && saved.media_attached === false) {
        toast.error("Saved the words. A picture could not be made.");
      } else {
        toast.success(pictureOn ? `Draft saved with ${visualSavedLabel(visualKind)}` : "Draft saved");
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save draft");
    } finally {
      setSavingId(null);
    }
  }

  async function postNow(variant: Variant, index: number) {
    setPostingId(index);
    try {
      const saved = await api<{ post: { id: number }; media_attached?: boolean }>(
        "/content/drafts",
        {
          method: "POST",
          body: JSON.stringify({
            content: variant.content,
            content_type: contentType,
            virality_predicted: variant.virality_score,
            with_visual: pictureOn,
            visual_kind: pictureOn ? visualKind : "text",
            visual_preview: pictureOn && visual && index === 0 ? visual.id : undefined,
          }),
        },
        DRAFT_TIMEOUT_MS,
      );
      try {
        const posted = await api<{ media_warning?: string }>(
          `/content/drafts/${saved.post.id}/publish`,
          { method: "POST" },
          DRAFT_TIMEOUT_MS,
        );
        if (posted.media_warning) {
          toast.success(posted.media_warning);
          return;
        }
      } catch (err) {
        const reason = err instanceof Error ? err.message : "Could not post to X";
        toast.error(`${reason} It is saved in drafts.`);
        return;
      }
      toast.success("Posted to X");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not post to X");
    } finally {
      setPostingId(null);
    }
  }

  return (
    <div className="space-y-6">
      <DeskHeading icon={PenLine} title={COMPOSE_COPY[platform].title}>
        <p className="text-muted-foreground">
          {COMPOSE_COPY[platform].lede}{" "}
          <Link href={`/drafts?platform=${platform}`} className="text-foreground underline underline-offset-4">
            Open drafts
          </Link>
        </p>
        {usage?.web_search_enabled && (
          <p className="mt-2 text-sm text-muted-foreground">
            Web lookups left: {usage.web_searches.remaining}
          </p>
        )}
      </DeskHeading>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Brief</CardTitle>
            <CardDescription>
              {platform === "youtube"
                ? "Writes a separate text draft from your brief and account description"
                : platform === "x"
                  ? X_COPY.composeBrief
                  : "Joins what people are talking about, from this account's point of view"}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form
              onSubmit={(e: FormEvent<HTMLFormElement>) => {
                e.preventDefault();
                void write(useWebSearch);
              }}
              className="space-y-4"
            >
              <div
                role="radiogroup"
                aria-label="How to start"
                className="grid grid-cols-2 gap-1 rounded-lg border border-border p-1"
              >
                {MODES.map((item) => (
                  <button
                    key={item.value}
                    type="button"
                    role="radio"
                    aria-checked={mode === item.value}
                    onClick={() => setMode(item.value)}
                    className={`rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                      mode === item.value
                        ? "bg-primary text-primary-foreground"
                        : "text-muted-foreground hover:text-foreground"
                    }`}
                  >
                    {item.label}
                  </button>
                ))}
              </div>

              <div className="space-y-2">
                <Label htmlFor="topic">
                  {mode === "suggest" ? "Anything Mackie17 should include?" : "What do you want to post about?"}
                </Label>
                <Textarea
                  id="topic"
                  rows={4}
                  maxLength={1000}
                  value={topic}
                  onChange={(e) => setTopic(e.target.value)}
                  placeholder={
                    mode === "suggest"
                      ? platform === "x"
                        ? X_COPY.composeSuggestPlaceholder
                        : "Leave this blank and Suggest posts writes from this account. Or add a point it must include."
                      : "e.g. Why I stopped buying ready meals. Mention the price difference and end with a question."
                  }
                />
                <div className="flex flex-wrap items-center gap-3">
                  <label
                    htmlFor="reference"
                    className="inline-flex min-h-9 cursor-pointer items-center rounded-sm border border-border px-3 text-sm focus-within:ring-2 focus-within:ring-ring"
                  >
                    {reference ? "Change photo" : "Add a photo"}
                    <input
                      id="reference"
                      type="file"
                      accept="image/png,image/jpeg,image/webp,image/gif"
                      className="sr-only"
                      onChange={(event) => {
                        const file = event.target.files?.[0];
                        event.target.value = "";
                        if (!file) return;
                        if (!file.type.startsWith("image/")) {
                          toast.error("Use a JPEG, PNG, or WebP photo.");
                          return;
                        }
                        if (file.size > 5 * 1024 * 1024) {
                          toast.error("That photo is too large. Use one under 5 MB.");
                          return;
                        }
                        setReference(file);
                        setReferenceUrl(URL.createObjectURL(file));
                      }}
                    />
                  </label>
                  {referenceUrl ? (
                    <>
                      {/* Local file chosen in this session. It is not a remote image. */}
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img
                        src={referenceUrl}
                        alt="Reference photo"
                        className="h-16 w-16 rounded-md object-cover"
                      />
                      <Button type="button" variant="ghost" size="sm" onClick={() => {
                        setReference(null);
                        setReferenceUrl(null);
                      }}>
                        Remove
                      </Button>
                    </>
                  ) : null}
                </div>
                <p className="text-xs text-muted-foreground">{X_COPY.composeReferenceHelp}</p>
                <p className="text-xs text-muted-foreground">
                  {mode === "suggest"
                    ? platform === "youtube"
                      ? "Suggestions follow the account description and audience. They do not read or update the YouTube video engine."
                      : platform === "x"
                        ? X_COPY.composeSuggestHelp
                        : "Suggest posts follows the account's description and the people it is for."
                    : "A topic, an opinion, or a rough idea. Add anything it must include."}
                </p>
              </div>
              <div className="space-y-2">
                <Label>Tone</Label>
                <Select value={tone} onValueChange={(v) => setTone(v ?? "professional")}>
                  <SelectTrigger>
                    <SelectValue>{(value: string) => TONE_LABELS[value] ?? value}</SelectValue>
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="professional">Professional</SelectItem>
                    <SelectItem value="passionate">Passionate</SelectItem>
                    <SelectItem value="funny">Witty</SelectItem>
                    <SelectItem value="motivational">Motivational</SelectItem>
                    <SelectItem value="news">News-style</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Format</Label>
                <Select value={contentType} onValueChange={(v) => setContentType(v ?? "tweet")}>
                  <SelectTrigger>
                    <SelectValue>{(value: string) => contentFormatLabel(value)}</SelectValue>
                  </SelectTrigger>
                  <SelectContent>
                    {formats.map((format) => (
                      <SelectItem key={format.value} value={format.value}>
                        {format.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              {platform === "x" && (contentType === "tweet" || contentType === "quote") && (
                <div className="space-y-2">
                  <Label>{X_COPY.composeVisualLabel}</Label>
                  <Select
                    value={visualKind}
                    onValueChange={(value) => {
                      setVisualKind((value as VisualKind) ?? "text");
                      setVisual(null);
                      setVisualError(null);
                    }}
                  >
                    <SelectTrigger>
                      <SelectValue>{(value: string) => VISUAL_LABELS[value as VisualKind] ?? VISUAL_LABELS.text}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="text">Text only</SelectItem>
                      <SelectItem value="flyer">Flyer</SelectItem>
                      <SelectItem value="photo">Photo</SelectItem>
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-muted-foreground">{X_COPY.composeVisualHelp}</p>
                </div>
              )}
              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  disabled={loading}
                  onClick={() => void write(false)}
                >
                  {loading
                    ? pictureOn
                      ? "Writing and making the picture…"
                      : "Writing…"
                    : mode === "suggest"
                      ? variants.length > 0
                        ? "Suggest different posts"
                        : "Suggest posts"
                      : "Write drafts"}
                </Button>
                <Button
                  type="button"
                  variant="secondary"
                  disabled={loading}
                  onClick={() => void write(true)}
                >
                  {loading ? "Searching…" : "Search"}
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">
                {platform === "youtube"
                  ? "Search can help write this text draft. It does not fetch channel videos, analytics, or retention."
                  : "Search looks up what is happening in this account, then writes from the description and the people it is for."}
              </p>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Drafts</CardTitle>
            {platform === "x" && variants.length > 1 ? (
              <CardDescription>{X_COPY.composeDraftsHelp}</CardDescription>
            ) : note ? (
              <CardDescription>{note}</CardDescription>
            ) : null}
            {platform === "x" && variants.length > 1 && note ? (
              <CardDescription>{note}</CardDescription>
            ) : null}
          </CardHeader>
          <CardContent>
            {variants.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                {mode === "suggest"
                  ? "Press Suggest posts and drafts for your account appear here."
                  : "Describe the post to see drafts."}
              </p>
            ) : (
              variants.map((v, i) => (
                <div key={i} className="mb-4 rounded-lg border border-border p-4 last:mb-0">
                  <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    {v.angle ? (
                      <p className="text-xs font-medium text-muted-foreground">{v.angle}</p>
                    ) : (
                      <span />
                    )}
                    {typeof v.virality_score === "number" && (
                      <p className="text-sm font-medium tabular-nums" title={v.virality_reason ?? ""}>
                        {v.virality_score}/100
                        {i === 0 && variants.length > 1 ? (
                          <span className="ml-2 text-xs font-normal text-muted-foreground">
                            {X_COPY.composeScoreHighest}
                          </span>
                        ) : null}
                      </p>
                    )}
                  </div>
                  {i === 0 && visual ? (
                    <div className="mb-3">
                      <p className="mb-2 text-xs text-muted-foreground">{VISUAL_LABELS[visual.kind]}</p>
                      <AuthImage
                        path={`/api/content/visual-previews/${visual.id}`}
                        alt={`${VISUAL_LABELS[visual.kind]} for this draft`}
                      />
                    </div>
                  ) : null}
                  {i === 0 && !visual && visualError ? (
                    <p className="mb-3 text-sm text-muted-foreground">{visualError}</p>
                  ) : null}
                  <Textarea
                    value={v.content}
                    onChange={(e) => updateVariant(i, e.target.value)}
                    rows={4}
                    className="mb-3"
                  />
                  <div className="flex flex-wrap items-center gap-2">
                    <Button
                      type="button"
                      variant="secondary"
                      size="sm"
                      disabled={savingId === i || postingId === i}
                      onClick={() => saveDraft(v, i)}
                    >
                      {savingId === i ? "Saving…" : "Save draft"}
                    </Button>
                    {platformOfFormat(contentType) === "x" && (
                      <Button
                        type="button"
                        size="sm"
                        disabled={savingId === i || postingId === i}
                        onClick={() => postNow(v, i)}
                      >
                        {postingId === i ? "Posting…" : "Post"}
                      </Button>
                    )}
                  </div>
                </div>
              ))
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
