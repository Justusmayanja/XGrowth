"use client";

import { Hash } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { DeskHeading } from "@/components/DeskHeading";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingMark } from "@/components/LoadingMark";
import { api } from "@/lib/api";
import { usePlatform } from "@/lib/platform";

type Trend = {
  name: string;
  angle?: string;
  draft?: string;
};

export default function TrendsPage() {
  const platform = usePlatform();
  const [trends, setTrends] = useState<Trend[]>([]);
  const [note, setNote] = useState("");
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState<Trend | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api<{ trends: Trend[]; note?: string | null }>("/trends")
      .then((r) => {
        setTrends(r.trends);
        setNote(r.note ?? "");
        setSelected(r.trends[0] ?? null);
      })
      .finally(() => setLoading(false));
  }, []);

  const active = selected ?? trends[0] ?? null;

  async function saveDraft() {
    if (!active?.draft) {
      return;
    }
    setSaving(true);
    try {
      await api("/content/drafts", {
        method: "POST",
        body: JSON.stringify({ content: active.draft, content_type: "tweet" }),
      });
      toast.success("Saved to drafts");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save the draft");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-6">
      <DeskHeading icon={Hash} title="Topics">
        <p className="text-muted-foreground">
          {platform === "x"
            ? "Three to five conversations happening now, written as a post from this account."
            : "Topics for this account."}
        </p>
      </DeskHeading>

      {loading ? (
        <LoadingMark />
      ) : trends.length === 0 ? (
        <p className="text-sm text-muted-foreground">{note || "Connect X to load topics from the account."}</p>
      ) : (
        <div className="grid gap-6 lg:grid-cols-2">
          <div className="space-y-4">
            {note && <p className="text-sm text-muted-foreground">{note}</p>}
            {trends.map((topic) => {
              const on = active?.name === topic.name;
              return (
                <button
                  key={topic.name}
                  type="button"
                  onClick={() => setSelected(topic)}
                  className={`w-full rounded-lg border p-4 text-left transition-colors ${
                    on ? "border-foreground/30 bg-white/[0.04]" : "border-border hover:bg-white/[0.03]"
                  }`}
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium">{topic.name}</span>
                    <Badge variant="secondary">Now</Badge>
                  </div>
                  {topic.angle && <p className="mt-2 text-sm text-muted-foreground">{topic.angle}</p>}
                </button>
              );
            })}
          </div>

          <Card>
            <CardHeader>
              <CardTitle>A post for this</CardTitle>
              <CardDescription>
                {active ? active.name : "Pick a topic"}
              </CardDescription>
            </CardHeader>
            <CardContent>
              {active?.draft ? (
                <>
                  <p className="mb-4 text-sm leading-relaxed">{active.draft}</p>
                  <Button size="sm" variant="secondary" onClick={saveDraft} disabled={saving}>
                    {saving ? "Saving…" : "Save to drafts"}
                  </Button>
                </>
              ) : (
                <p className="text-sm text-muted-foreground">
                  A post for this topic is not ready yet.
                </p>
              )}
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
}
