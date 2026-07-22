"use client";

import Link from "next/link";
import { FormEvent, useEffect, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
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

type Variant = { content: string; virality_score: number; estimated_reach: string };

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
  const [tone, setTone] = useState("professional");
  const [contentType, setContentType] = useState("tweet");
  const [useWebSearch, setUseWebSearch] = useState(false);
  const [usage, setUsage] = useState<AiUsageStatus | null>(null);

  useEffect(() => {
    api<AiUsageStatus>("/account/ai-usage")
      .then(setUsage)
      .catch(() => {});
  }, []);

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setLoading(true);
    const form = new FormData(e.currentTarget);
    try {
      const data = await api<{
        variants: Variant[];
        note: string;
        usage?: AiUsageStatus;
      }>("/content/generate", {
        method: "POST",
        body: JSON.stringify({
          topic: form.get("topic"),
          tone,
          content_type: contentType,
          use_web_search: useWebSearch,
        }),
      });
      setVariants(data.variants);
      setNote(data.note);
      if (data.usage) {
        setUsage(data.usage);
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not generate drafts");
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

  const webSearchDisabled =
    !usage?.web_search_enabled || (usage?.web_searches.remaining ?? 0) <= 0;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Content Studio</h1>
        <p className="text-muted-foreground">
          Turn a topic into post drafts in your voice.{" "}
          <Link href="/drafts" className="text-primary hover:underline">
            View drafts
          </Link>
        </p>
        {usage && (
          <p className="mt-2 text-sm text-muted-foreground">
            Today: {usage.ai_generations.remaining} AI generations left
            {usage.web_search_enabled && (
              <> · {usage.web_searches.remaining} web searches left</>
            )}
          </p>
        )}
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Inputs</CardTitle>
            <CardDescription>Xpulse uses your profile niche and tone by default</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={onSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="topic">Topic</Label>
                <Input
                  id="topic"
                  name="topic"
                  required
                  placeholder="Manchester United transfer news"
                />
              </div>
              <div className="space-y-2">
                <Label>Tone</Label>
                <Select value={tone} onValueChange={(v) => setTone(v ?? "professional")}>
                  <SelectTrigger>
                    <SelectValue />
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
                <Label>Content type</Label>
                <Select value={contentType} onValueChange={(v) => setContentType(v ?? "tweet")}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="tweet">Tweet</SelectItem>
                    <SelectItem value="thread">Thread</SelectItem>
                    <SelectItem value="poll">Poll</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              {usage?.web_search_enabled && (
                <label className="flex items-start gap-3 rounded-lg border border-border p-3 text-sm">
                  <input
                    type="checkbox"
                    checked={useWebSearch}
                    disabled={webSearchDisabled}
                    onChange={(e) => setUseWebSearch(e.target.checked)}
                    className="mt-1 rounded border-border"
                  />
                  <span>
                    <span className="font-medium">Search the web for current facts</span>
                    <span className="mt-1 block text-muted-foreground">
                      Best for breaking news, transfers, and time-sensitive topics. Uses extra
                      tokens and counts against your daily web search quota.
                    </span>
                  </span>
                </label>
              )}
              <Button type="submit" disabled={loading || (usage?.ai_generations.remaining ?? 1) <= 0}>
                {loading ? "Generating…" : "Generate drafts"}
              </Button>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Post options</CardTitle>
            {note && <CardDescription>{note}</CardDescription>}
          </CardHeader>
          <CardContent>
            {variants.length === 0 ? (
              <p className="text-sm text-muted-foreground">Enter a topic to see draft options</p>
            ) : (
              variants.map((v, i) => (
                <div key={i} className="mb-4 rounded-lg border border-border p-4 last:mb-0">
                  <Textarea
                    value={v.content}
                    onChange={(e) => updateVariant(i, e.target.value)}
                    rows={4}
                    className="mb-3"
                  />
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs text-muted-foreground">
                      Reach: {v.estimated_reach} · Score {v.virality_score}/100
                    </span>
                    <Button
                      type="button"
                      variant="secondary"
                      size="sm"
                      disabled={savingId === i}
                      onClick={() => saveDraft(v, i)}
                    >
                      {savingId === i ? "Saving…" : "Save draft"}
                    </Button>
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
