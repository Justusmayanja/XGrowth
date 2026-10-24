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
          Mentions and replies on the connected X account, with the counts X returned.
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

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-4">
          {loading ? (
            <LoadingMark />
          ) : posts.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {note || "No replies or mentions yet. Connect X to load them."}
            </p>
          ) : (
            posts.map((post) => (
              <Card key={post.id}>
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
                  <Button size="sm" variant="secondary" onClick={() => generateReplies(post)}>
                    Draft replies
                  </Button>
                </CardContent>
              </Card>
            ))
          )}
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Reply drafts</CardTitle>
            <CardDescription>
              {activePost
                ? `Options for ${activePost.author}'s post`
                : "Choose a post to draft replies"}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {generating && <LoadingMark />}
            {!generating && drafts.length === 0 && (
              <p className="text-sm text-muted-foreground">
                Pick a conversation on the left to see reply options in your voice.
              </p>
            )}
            {drafts.map((draft, i) => (
              <div key={i} className="mb-4 rounded-lg border border-border p-3 last:mb-0">
                <p className="mb-3 text-sm leading-relaxed">{draft.content}</p>
                <Button size="sm" variant="outline" onClick={() => saveAsDraft(draft.content)}>
                  Save to drafts
                </Button>
              </div>
            ))}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
