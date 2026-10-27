"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api";

type Attention = {
  id: number;
  user_id: number;
  email: string | null;
  status: string;
  age_days: number;
};

type TikTokOps = {
  counts: Record<string, number>;
  created_24h: number;
  created_7d: number;
  attention: Attention[];
};

type Diagnosis = {
  user_id: number;
  email: string;
  diagnose: { can_run: boolean; reason: string | null; message: string | null };
};

type RunSummary = {
  message: string;
  summary: { created: number; skipped: Record<string, number>; failed: number };
};

const STATUSES = ["drafted", "waiting", "queued", "posted", "learned", "discarded"] as const;

export function TikTokOperations() {
  const [info, setInfo] = useState<TikTokOps | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [lookup, setLookup] = useState("");
  const [diagnosis, setDiagnosis] = useState<Diagnosis | null>(null);
  const [running, setRunning] = useState(false);
  const [summary, setSummary] = useState<RunSummary["summary"] | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      setInfo(await api<TikTokOps>("/admin/tiktok"));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not load TikTok operations.");
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function diagnose(event: FormEvent) {
    event.preventDefault();
    setDiagnosis(null);
    try {
      setDiagnosis(await api<Diagnosis>(`/admin/tiktok/diagnose?user=${encodeURIComponent(lookup.trim())}`));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No account matches that lookup.");
    }
  }

  async function runEngine() {
    setRunning(true);
    try {
      const result = await api<RunSummary>("/admin/tiktok/run", { method: "POST" });
      setSummary(result.summary);
      toast.success(result.message);
      await load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "The TikTok engine did not finish.");
    } finally {
      setRunning(false);
    }
  }

  const skipped = summary
    ? Object.entries(summary.skipped)
        .map(([reason, count]) => `${reason} ${count}`)
        .join(", ")
    : "";

  return (
    <Card>
      <CardHeader>
        <CardTitle>TikTok</CardTitle>
        <CardDescription>Clip counts, accounts that have been waiting, and a manual engine pass.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        {error && <p className="text-sm text-destructive">{error}</p>}
        {info && (
          <>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              {STATUSES.map((status) => (
                <div key={status} className="rounded-sm border border-border p-3">
                  <p className="text-xs text-muted-foreground">{status}</p>
                  <p className="mt-1 font-mono text-lg tabular-nums">{info.counts[status] ?? 0}</p>
                </div>
              ))}
            </div>
            <p className="text-sm text-muted-foreground">
              {info.created_24h} created in the last 24 hours. {info.created_7d} in the last 7 days.
            </p>
            <div>
              <p className="text-sm font-medium">Needs attention</p>
              {info.attention.length === 0 ? (
                <p className="mt-2 text-sm text-muted-foreground">Nothing has been waiting past a week or queued past two weeks.</p>
              ) : (
                <ul className="mt-2 space-y-2">
                  {info.attention.map((item) => (
                    <li key={item.id} className="text-sm">
                      {item.email ?? `User ${item.user_id}`} · {item.status} · {item.age_days} days
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </>
        )}

        <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => void diagnose(event)}>
          <label className="min-w-0 flex-1 space-y-1 text-sm">
            <span className="text-muted-foreground">Look up an account</span>
            <Input value={lookup} onChange={(event) => setLookup(event.target.value)} placeholder="User id or email" />
          </label>
          <Button type="submit" variant="secondary" className="min-h-11">
            Diagnose
          </Button>
        </form>
        {diagnosis && (
          <p className="text-sm">
            {diagnosis.email}: {diagnosis.diagnose.can_run ? "Ready for a new clip." : diagnosis.diagnose.message}
          </p>
        )}

        <div className="space-y-2">
          <Button type="button" onClick={() => void runEngine()} disabled={running}>
            {running ? "Running…" : "Run engine now"}
          </Button>
          {summary && (
            <p className="text-sm text-muted-foreground">
              Created {summary.created}. Failed {summary.failed}.
              {skipped ? ` Skipped ${skipped}.` : ""}
            </p>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
