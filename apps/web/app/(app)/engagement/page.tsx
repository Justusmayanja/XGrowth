"use client";

import { FormEvent, useEffect, useState } from "react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";

type ViralPost = {
  id: string;
  author: string;
  author_followers: number;
  content: string;
  likes: number;
  reposts: number;
  replies: number;
  minutes_ago: number;
  opportunity_score: number;
};

type ReplyDraft = { content: string; tone: string };

export default function EngagementPage() {
  const [posts, setPosts] = useState<ViralPost[]>([]);
  const [note, setNote] = useState("");
  const [loading, setLoading] = useState(true);
  const [activePost, setActivePost] = useState<ViralPost | null>(null);
  const [drafts, setDrafts] = useState<ReplyDraft[]>([]);
  const [generating, setGenerating] = useState(false);

  useEffect(() => {
    api<{ posts: ViralPost[]; note: string }>("/engagement/feed")
      .then((res) => {
        setPosts(res.posts);
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
      <div>
        <h1 className="text-2xl font-bold">Engagement Center</h1>
        <p className="text-muted-foreground">
          High-opportunity conversations in your niche. Reply while it matters
        </p>
      </div>

      {note && <p className="text-sm text-muted-foreground">{note}</p>}

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-4">
          {loading ? (
            <p className="text-muted-foreground">Loading conversations…</p>
          ) : (
            posts.map((post) => (
              <Card key={post.id}>
                <CardHeader className="pb-2">
                  <div className="flex items-center justify-between gap-2">
                    <CardTitle className="text-sm font-medium">{post.author}</CardTitle>
                    <Badge>{post.opportunity_score} opp</Badge>
                  </div>
                  <CardDescription>
                    {(post.author_followers / 1000).toFixed(1)}K followers · {post.minutes_ago}m ago
                  </CardDescription>
                </CardHeader>
                <CardContent>
                  <p className="mb-3 text-sm leading-relaxed">{post.content}</p>
                  <p className="mb-3 text-xs text-muted-foreground">
                    {post.likes.toLocaleString()} likes · {post.reposts} reposts · {post.replies} replies
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
                : "Select a post to generate reply options"}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {generating && <p className="text-sm text-muted-foreground">Preparing replies…</p>}
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
