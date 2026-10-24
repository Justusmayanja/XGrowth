"use client";

import { MessageCircle } from "lucide-react";
import { useEffect, useState } from "react";
import { DeskHeading } from "@/components/DeskHeading";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingMark } from "@/components/LoadingMark";
import { api } from "@/lib/api";

type ViralPost = {
  id: string;
  author: string;
  author_followers: number;
  content: string;
  likes: number;
  reposts: number;
  replies: number;
  impressions?: number;
  minutes_ago: number;
  kind?: "mention" | "niche";
};

type ReplyStats = {
  conversations: number;
  likes: number;
  reposts: number;
  replies: number;
  impressions: number;
};

type ReplyDraft = { content: string; tone: string };

export default function EngagementPage() {
  const [posts, setPosts] = useState<ViralPost[]>([]);
  const [stats, setStats] = useState<ReplyStats | null>(null);
  const [note, setNote] = useState("");
  const [loading, setLoading] = useState(true);
  const [activePost, setActivePost] = useState<ViralPost | null>(null);
  const [drafts, setDrafts] = useState<ReplyDraft[]>([]);
  const [generating, setGenerating] = useState(false);

  useEffect(() => {
    api<{ posts: ViralPost[]; note: string; stats: ReplyStats }>("/engagement/feed")
      .then((res) => {
        setPosts(res.posts);
        setStats(res.stats);
        setNote(res.note);
      })
      .finally(() => setLoading(false));
  }, []);

  const shown = activePost ?? posts[0] ?? null;

  async function generateReplies(post: ViralPost) {
    setActivePost(post);
    setGenerating(true);
    setDrafts([]);
    try {
      const res = await api<{ drafts: ReplyDraft[] }>("/engagement/reply-drafts", {
        method: "POST",
        body: JSON.stringify({ post_id: post.id, context: post.content.slice(0, 200) }),
      });
      setDrafts(res.drafts);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not generate replies");
    } finally {
      setGenerating(false);
    }
  }

  async function saveAsDraft(content: string) {
    await api("/content/drafts", {
      method: "POST",
      body: JSON.stringify({ content, content_type: "tweet" }),
    });
    toast.success("Saved to drafts");
  }

  return (
    <div className="space-y-6">
      <DeskHeading icon={MessageCircle} title="Replies">
        <p className="text-muted-foreground">
          Three to five mentions and replies on this account, with the one worth answering on the right.
        </p>
      </DeskHeading>

      {stats && stats.conversations > 0 && (
        <div className="grid gap-4 sm:grid-cols-4">
          {[
            ["Conversations", stats.conversations],
            ["Likes", stats.likes],
            ["Replies", stats.replies],
            ["Impressions", stats.impressions],
          ].map(([label, value]) => (
            <Card key={label}>
              <CardContent className="pt-6">
                <p className="text-sm text-muted-foreground">{label}</p>
                <p className="mt-2 text-2xl font-medium tabular-nums">{Number(value).toLocaleString()}</p>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {loading ? (
        <LoadingMark />
      ) : posts.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          {note || "No replies or mentions yet. Connect X to load them."}
        </p>
      ) : (
      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-4">
          {posts.map((post) => {
              const on = shown?.id === post.id;
              return (
                <Card key={post.id} className={on ? "border-foreground/30" : undefined}>
                  <CardHeader className="pb-2">
                    <div className="flex items-center justify-between gap-2">
                      <CardTitle className="text-sm font-medium">{post.author}</CardTitle>
                      <Badge variant="secondary">{post.kind === "mention" ? "On your account" : "Reply"}</Badge>
                    </div>
                    <CardDescription>
                      {post.author_followers.toLocaleString()} followers · {post.minutes_ago}m ago
                    </CardDescription>
                  </CardHeader>
                  <CardContent>
                    <p className="mb-3 text-sm leading-relaxed">{post.content}</p>
                    <p className="mb-3 text-xs text-muted-foreground">
                      {post.likes.toLocaleString()} likes · {post.reposts.toLocaleString()} reposts · {post.replies.toLocaleString()} replies
                      {post.impressions ? ` · ${post.impressions.toLocaleString()} impressions` : ""}
                    </p>
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() => {
                        setActivePost(post);
                        setDrafts([]);
                      }}
                    >
                      Use this
                    </Button>
                  </CardContent>
                </Card>
              );
            })}
        </div>

        <Card>
          <CardHeader>
            <CardTitle>{shown ? shown.author : "Reply"}</CardTitle>
            <CardDescription>
              {shown
                ? `${shown.kind === "mention" ? "Mentioned this account" : "Replied on this account"} · ${shown.minutes_ago}m ago`
                : "Mentions and replies show here"}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {!shown && (
              <p className="text-sm text-muted-foreground">
                Nothing to answer yet.
              </p>
            )}
            {shown && (
              <>
                <p className="mb-3 text-sm leading-relaxed">{shown.content}</p>
                <p className="mb-4 text-xs text-muted-foreground">
                  {shown.likes.toLocaleString()} likes · {shown.replies.toLocaleString()} replies
                  {shown.author_followers ? ` · ${shown.author_followers.toLocaleString()} followers` : ""}
                </p>
                <Button size="sm" variant="secondary" onClick={() => generateReplies(shown)} disabled={generating}>
                  {generating ? "Writing…" : "Draft replies"}
                </Button>
              </>
            )}
            {generating && <div className="mt-4"><LoadingMark /></div>}
            {drafts.length > 0 && (
              <div className="mt-4 space-y-4">
                {drafts.map((draft, i) => (
                  <div key={i} className="rounded-lg border border-border p-3">
                    <p className="mb-3 text-sm leading-relaxed">{draft.content}</p>
                    <Button size="sm" variant="outline" onClick={() => saveAsDraft(draft.content)}>
                      Save to drafts
                    </Button>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
      )}
    </div>
  );
}
