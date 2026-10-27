"use client";

import { Hash } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { DeskHeading } from "@/components/DeskHeading";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingMark } from "@/components/LoadingMark";
import { api } from "@/lib/api";
import { usePlatform } from "@/lib/platform";

type Post = {
  name: string;
  post: string;
};

export default function TrendsPage() {
  const platform = usePlatform();
  const [posts, setPosts] = useState<Post[]>([]);
  const [note, setNote] = useState("");
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState<Post | null>(null);
  const [creating, setCreating] = useState(false);
  const [posting, setPosting] = useState(false);

  useEffect(() => {
    setLoading(true);
    const load =
      platform === "x"
        ? api<{ ideas: { name: string; suggestion?: string }[]; ideas_note?: string | null }>(`/dashboard/platform/${platform}`).then((desk) => {
            const next = (desk.ideas ?? []).map((idea) => ({
              name: idea.name,
              post: idea.suggestion?.trim() || idea.name,
            }));
            setPosts(next);
            setNote(desk.ideas_note ?? "");
            setSelected(next[0] ?? null);
          })
        : api<{ trends: { name: string; draft?: string }[]; note?: string | null }>("/trends").then((r) => {
            const next = r.trends.map((topic) => ({
              name: topic.name,
              post: topic.draft?.trim() || topic.name,
            }));
            setPosts(next);
            setNote(r.note ?? "");
            setSelected(next[0] ?? null);
          });

    load.finally(() => setLoading(false));
  }, [platform]);

  const active = selected ?? posts[0] ?? null;

  async function createTweet() {
    if (!active?.name || platform !== "x") {
      return;
    }
    setCreating(true);
    try {
      const data = await api<{ variants: { content: string }[] }>(
        "/content/generate",
        {
          method: "POST",
          body: JSON.stringify({
            mode: "describe",
            topic: active.name,
            content_type: "tweet",
          }),
        },
        60000,
        "Writing the tweet took too long. Try again.",
      );
      const content = data.variants[0]?.content?.trim();
      if (!content) {
        toast.error("No tweet came back.");
        return;
      }
      const next = { name: active.name, post: content };
      setPosts((current) => current.map((item) => (item.name === active.name ? next : item)));
      setSelected(next);
      toast.success("Tweet written");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not write the tweet");
    } finally {
      setCreating(false);
    }
  }

  async function postTweet() {
    if (!active?.post || active.post === active.name) {
      toast.error("Write the tweet first.");
      return;
    }
    setPosting(true);
    try {
      const saved = await api<{ post: { id: number } }>("/content/drafts", {
        method: "POST",
        body: JSON.stringify({ content: active.post, content_type: "tweet" }),
      });
      await api(`/content/drafts/${saved.post.id}/publish`, { method: "POST" }, 20000, "Posting to X took too long.");
      toast.success("Posted to X");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not post to X");
    } finally {
      setPosting(false);
    }
  }

  return (
    <div className="space-y-6">
      <DeskHeading icon={Hash} title="Topics">
        <p className="text-muted-foreground">
          {platform === "x"
            ? "Posts from this account's home timeline, kept for 7 days. If none fit, posts the account wrote itself."
            : "Topics for this account."}
        </p>
      </DeskHeading>

      {loading ? (
        <LoadingMark />
      ) : posts.length === 0 ? (
        <p className="text-sm text-muted-foreground">{note || "Nothing here yet. Open Today after X is connected."}</p>
      ) : (
        <div className="grid gap-6 lg:grid-cols-2">
          <div className="space-y-4">
            {posts.map((item) => {
              const on = active?.name === item.name && active?.post === item.post;
              return (
                <button
                  key={`${item.name}-${item.post}`}
                  type="button"
                  onClick={() => setSelected(item)}
                  className={`w-full rounded-lg border p-4 text-left transition-colors ${
                    on ? "border-foreground/30 bg-white/[0.04]" : "border-border hover:bg-white/[0.03]"
                  }`}
                >
                  <p className="font-medium">{item.name}</p>
                  {item.post !== item.name && (
                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{item.post}</p>
                  )}
                </button>
              );
            })}
          </div>

          <Card>
            <CardHeader>
              <CardTitle>{active?.name ?? "Post"}</CardTitle>
              <CardDescription>
                {active && active.post !== active.name ? "Written for this account" : "Write a tweet from this topic, then post it"}
              </CardDescription>
            </CardHeader>
            <CardContent>
              {active?.post ? (
                <>
                  <p className="mb-4 text-sm leading-relaxed">{active.post}</p>
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" onClick={createTweet} disabled={creating || posting || platform !== "x"}>
                      {creating ? "Writing…" : "Create tweet"}
                    </Button>
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={postTweet}
                      disabled={creating || posting || active.post === active.name}
                    >
                      {posting ? "Posting…" : "Post to X"}
                    </Button>
                  </div>
                </>
              ) : (
                <p className="text-sm text-muted-foreground">Nothing to show yet.</p>
              )}
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
}
