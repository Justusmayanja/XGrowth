"use client";

import Link from "next/link";
import { PenLine } from "lucide-react";
import { FormEvent, useEffect, useState } from "react";
import { DeskHeading } from "@/components/DeskHeading";
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
import { api } from "@/lib/api";
import { CONTENT_FORMATS, contentFormatLabel } from "@/lib/content-formats";
import { formatsFor, platformOfFormat, usePlatform } from "@/lib/platform";
import { type AccountProfile, parseMultiValue } from "@/lib/profile";

const TONE_LABELS: Record<string, string> = {
  professional: "Professional",
  passionate: "Passionate",
  funny: "Witty",
  motivational: "Motivational",
  news: "News-style",
};

const COMPOSE_COPY = {
  x: { title: "Compose", lede: "Write a post, thread, poll, or quote for X." },
  tiktok: { title: "New clip", lede: "Start from an idea. It writes the hook and caption for the clip." },
  youtube: { title: "New video", lede: "Start from an idea. It writes the title, description, and what should be said." },
} as const;

type Variant = { content: string; angle?: string | null; virality_score: number; estimated_reach: string };

type ComposeMode = "suggest" | "describe";

const MODES: Array<{ value: ComposeMode; label: string }> = [
  { value: "suggest", label: "Suggest for me" },
  { value: "describe", label: "I'll describe it" },
];

type AiUsageStatus = {
  web_search_enabled: boolean;
  ai_generations: { used: number; limit: number; remaining: number };
  web_searches: { used: number; limit: number; remaining: number };
};

export default function ContentStudioPage() {
  const [variants, setVariants] = useState<Variant[]>([]);
  const [note, setNote] = useState("");
  const [loading, setLoading] = useState(false);
  const [savingId, setSavingId] = useState<number | null>(null);
  const [postingId, setPostingId] = useState<number | null>(null);
  const [tone, setTone] = useState("professional");
  const [contentType, setContentType] = useState("tweet");
  const [useWebSearch, setUseWebSearch] = useState(false);
  const [usage, setUsage] = useState<AiUsageStatus | null>(null);

  const [topic, setTopic] = useState("");
  const [mode, setMode] = useState<ComposeMode>("suggest");
  const platform = usePlatform();
  const formats = formatsFor(platform);

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

  const reachedDraftLimit =
    usage != null && usage.ai_generations.limit > 0 && usage.ai_generations.remaining <= 0;

  async function write(search: boolean) {
    if (reachedDraftLimit && usage) {
      toast.error(`You've reached today's limit of ${usage.ai_generations.limit} drafts.`);
      return;
    }
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
    if (nextMode === "describe" && typed.length < 3) {
      toast.error("Say what you want to post about.");
      return;
    }
    setUseWebSearch(search);
    setLoading(true);
    try {
      const data = await api<{
        variants: Variant[];
        note: string;
        usage?: AiUsageStatus;
      }>("/content/generate", {
        method: "POST",
        body: JSON.stringify({
          mode: nextMode,
          topic: nextMode === "describe" ? typed : null,
          tone,
          content_type: contentType,
          use_web_search: search && !webSearchDisabled,
        }),
      });
      setVariants(data.variants);
      setNote(data.note);
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
      await api("/content/drafts", {
        method: "POST",
        body: JSON.stringify({
          content: variant.content,
          content_type: contentType,
          virality_predicted: variant.virality_score,
        }),
      });
      toast.success("Draft saved");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save draft");
    } finally {
      setSavingId(null);
    }
  }

  async function postNow(variant: Variant, index: number) {
    setPostingId(index);
    try {
      const saved = await api<{ post: { id: number } }>("/content/drafts", {
        method: "POST",
        body: JSON.stringify({
          content: variant.content,
          content_type: contentType,
          virality_predicted: variant.virality_score,
        }),
      });
      try {
        await api(`/content/drafts/${saved.post.id}/publish`, { method: "POST" });
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
        {usage && (
          <p className="mt-2 text-sm text-muted-foreground">
            {reachedDraftLimit
              ? `You've reached today's limit of ${usage.ai_generations.limit} drafts.`
              : `Drafts left today: ${usage.ai_generations.remaining}`}
            {usage.web_search_enabled && (
              <> · Web lookups left: {usage.web_searches.remaining}</>
            )}
          </p>
        )}
      </DeskHeading>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Brief</CardTitle>
            <CardDescription>Joins what people are talking about, from this account&apos;s point of view</CardDescription>
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
                  {mode === "suggest" ? "Anything it should include?" : "What do you want to post about?"}
                </Label>
                <Textarea
                  id="topic"
                  rows={4}
                  maxLength={1000}
                  value={topic}
                  onChange={(e) => setTopic(e.target.value)}
                  placeholder={
                    mode === "suggest"
                      ? "Leave this blank and Suggest posts writes from this account. Or add a point it must include."
                      : "e.g. Why I stopped buying ready meals. Mention the price difference and end with a question."
                  }
                />
                <p className="text-xs text-muted-foreground">
                  {mode === "suggest"
                    ? "Suggest posts follows the account's description, the people it is for, and posts already written on X."
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
              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  disabled={loading}
                  onClick={() => void write(false)}
                >
                  {loading
                    ? "Writing…"
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
              {reachedDraftLimit && usage && (
                <p className="text-xs text-muted-foreground">
                  You've reached today's limit of {usage.ai_generations.limit} drafts. Automatic checks no longer use this allowance. Try again tomorrow.
                </p>
              )}
              <p className="text-xs text-muted-foreground">
                Search looks up what is happening in this account, then writes from the description and the people it is for.
              </p>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Drafts</CardTitle>
            {note && <CardDescription>{note}</CardDescription>}
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
                  {v.angle && <p className="mb-2 text-xs font-medium text-muted-foreground">{v.angle}</p>}
                  <Textarea
                    value={v.content}
                    onChange={(e) => updateVariant(i, e.target.value)}
                    rows={4}
                    className="mb-3"
                  />
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs text-muted-foreground">
                      Score {v.virality_score}/100 · Reach {v.estimated_reach}
                    </span>
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
