"use client";

import Link from "next/link";
import { FileText } from "lucide-react";
import { useEffect, useState } from "react";
import { DeskHeading } from "@/components/DeskHeading";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Textarea } from "@/components/ui/textarea";
import { api } from "@/lib/api";
import { contentFormatLabel } from "@/lib/content-formats";
import { platformOfFormat, usePlatform } from "@/lib/platform";

const DRAFTS_COPY = {
  x: { title: "Drafts", lede: "Posts waiting for a strong moment or your yes.", empty: "No X drafts yet.", cta: "Compose" },
  tiktok: { title: "Waiting for you", lede: "Clips stay here until you confirm them.", empty: "No clips waiting.", cta: "New clip" },
  youtube: { title: "Titles and scripts", lede: "Written and waiting to become videos.", empty: "Nothing written yet.", cta: "New video" },
} as const;

type Draft = {
  id: number;
  content: string;
  content_type: string;
  status: string;
  virality_predicted: number | null;
  created_at: string;
};

type PaginatedDrafts = {
  data: Draft[];
};

export default function DraftsPage() {
  const platform = usePlatform();
  const copy = DRAFTS_COPY[platform];
  const [allDrafts, setDrafts] = useState<Draft[]>([]);
  const drafts = allDrafts.filter((draft) => platformOfFormat(draft.content_type) === platform);
  const [loading, setLoading] = useState(true);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editContent, setEditContent] = useState("");
  const [savingId, setSavingId] = useState<number | null>(null);

  async function loadDrafts() {
    setLoading(true);
    try {
      const res = await api<PaginatedDrafts>("/content/posts?status=draft");
      setDrafts(res.data);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Failed to load drafts");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadDrafts();
  }, []);

  function startEdit(draft: Draft) {
    setEditingId(draft.id);
    setEditContent(draft.content);
  }

  async function saveEdit(id: number) {
    setSavingId(id);
    try {
      await api(`/content/drafts/${id}`, {
        method: "PUT",
        body: JSON.stringify({ content: editContent }),
      });
      setDrafts((current) =>
        current.map((d) => (d.id === id ? { ...d, content: editContent } : d))
      );
      setEditingId(null);
      toast.success("Draft updated");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not update draft");
    } finally {
      setSavingId(null);
    }
  }

  async function publishDraft(id: number) {
    setSavingId(id);
    try {
      await api(`/content/drafts/${id}/publish`, { method: "POST" });
      setDrafts((current) => current.filter((draft) => draft.id !== id));
      toast.success("Posted to X");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not post to X");
    } finally {
      setSavingId(null);
    }
  }

  async function deleteDraft(id: number) {
    await api(`/content/drafts/${id}`, { method: "DELETE" });
    setDrafts((current) => current.filter((draft) => draft.id !== id));
    toast.success("Draft deleted");
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-4">
        <DeskHeading icon={FileText} title={copy.title}>
          <p className="text-muted-foreground">{copy.lede}</p>
        </DeskHeading>
        <Link href={`/content-studio?platform=${platform}`}>
          <Button>{copy.cta}</Button>
        </Link>
      </div>

      {loading && <p className="text-muted-foreground">Loading drafts…</p>}

      {!loading && drafts.length === 0 && (
        <Card>
          <CardContent className="py-10 text-center">
            <p className="mb-4 text-muted-foreground">{copy.empty}</p>
            <Link href={`/content-studio?platform=${platform}`}>
              <Button variant="secondary">{copy.cta}</Button>
            </Link>
          </CardContent>
        </Card>
      )}

      <div className="space-y-4">
        {drafts.map((draft) => (
          <Card key={draft.id}>
            <CardContent className="pt-6">
              {editingId === draft.id ? (
                <>
                  <Textarea
                    value={editContent}
                    onChange={(e) => setEditContent(e.target.value)}
                    rows={4}
                    className="mb-3"
                  />
                  <div className="flex gap-2">
                    <Button size="sm" disabled={savingId === draft.id} onClick={() => saveEdit(draft.id)}>
                      {savingId === draft.id ? "Saving…" : "Save"}
                    </Button>
                    <Button size="sm" variant="secondary" onClick={() => setEditingId(null)}>
                      Cancel
                    </Button>
                  </div>
                </>
              ) : (
                <>
                  <p className="mb-3 text-sm leading-relaxed">{draft.content}</p>
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs text-muted-foreground">{contentFormatLabel(draft.content_type)}</span>
                    {draft.virality_predicted != null && (
                      <span className="text-xs text-muted-foreground">
                        Score {Math.round(Number(draft.virality_predicted))}/100
                      </span>
                    )}
                    <span className="text-xs text-muted-foreground">
                      {new Date(draft.created_at).toLocaleString()}
                    </span>
                    {platform === "x" && (
                      <Button size="sm" disabled={savingId === draft.id} onClick={() => publishDraft(draft.id)}>
                        {savingId === draft.id ? "Posting…" : "Post to X"}
                      </Button>
                    )}
                    <Button size="sm" variant="secondary" onClick={() => startEdit(draft)}>
                      Edit
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => deleteDraft(draft.id)}>
                      Delete
                    </Button>
                  </div>
                </>
              )}
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}
