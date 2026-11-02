"use client";

import { MessageCircle } from "lucide-react";
import { useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
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
  kind?: "mention" | "niche" | "ambition";
  fits?: boolean;
};

type ReplyStats = {
  conversations: number;
  likes: number;
  reposts: number;
  replies: number;
  impressions: number;
};

type ReplyDraft = { content: string; tone: string };

type CircleAccount = {
  author: string;
  followers: number;
  likes: number;
  replies: number;
  impressions: number;
  content: string;
};

type ReplyRecord = {
  answered: { author: string; theirs: string; ours: string }[];
  skipped: { author: string; theirs: string; reason: string }[];
};

export default function EngagementPage() {
  const search = useSearchParams();
  const account = search.get("account");
  const [posts, setPosts] = useState<ViralPost[]>([]);
  const [circle, setCircle] = useState<CircleAccount[]>([]);
  const [record, setRecord] = useState<ReplyRecord>({ answered: [], skipped: [] });
  const [stats, setStats] = useState<ReplyStats | null>(null);
  const [note, setNote] = useState("");
  const [loading, setLoading] = useState(true);
  const [activePost, setActivePost] = useState<ViralPost | null>(null);
  const [drafts, setDrafts] = useState<ReplyDraft[]>([]);
  const [generating, setGenerating] = useState(false);

  useEffect(() => {
    const path = account ? `/engagement/feed?account=${account}` : "/engagement/feed";
    api<{ posts: ViralPost[]; circle?: CircleAccount[]; record?: ReplyRecord; note: string; stats: ReplyStats }>(path)
      .then((res) => {
        setPosts(res.posts);
        setCircle(res.circle ?? []);
        setRecord(res.record ?? { answered: [], skipped: [] });
        setStats(res.stats);
        setNote(res.note);
      })
      .finally(() => setLoading(false));
  }, [account]);

  const shown = activePost ?? posts[0] ?? null;

  async function generateReplies(post: ViralPost) {
    setActivePost(post);
    setGenerating(true);
    setDrafts([]);
    try {
      const res = await api<{ drafts: ReplyDraft[] }>("/engagement/reply-drafts", {
        method: "POST",
        body: JSON.stringify({
          post_id: post.id,
          context: post.content.slice(0, 200),
          account: account ? Number(account) : undefined,
        }),
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
          People whose posts match what this account is for come first. The reply is written in this voice, with no link.
        </p>
      </DeskHeading>

      {(record.answered.length > 0 || record.skipped.length > 0) && (
        <Card>
          <CardHeader>
            <CardTitle>This morning</CardTitle>
            <CardDescription>Replies that went out, and posts that were left because they do not fit.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {record.answered.map((row) => (
              <div key={`${row.author}-${row.ours}`}>
                <p className="text-sm font-medium">{row.author}</p>
                {row.theirs && <p className="text-xs text-muted-foreground">{row.theirs}</p>}
                <p className="mt-1 text-sm">{row.ours}</p>
              </div>
            ))}
            {record.skipped.map((row) => (
              <div key={`${row.author}-${row.theirs}`}>
                <p className="text-sm font-medium">{row.author}</p>
                {row.theirs && <p className="text-sm">{row.theirs}</p>}
                <p className="text-xs text-muted-foreground">{row.reason}</p>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

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

      {circle.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Larger accounts in this circle</CardTitle>
            <CardDescription>
              Accounts already on this home timeline with more followers, and how their latest post did.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {circle.map((account) => (
              <div key={account.author} className="border-t border-border pt-4 first:border-t-0 first:pt-0">
                <div className="flex items-baseline justify-between gap-3">
                  <p className="text-sm font-medium">{account.author}</p>
                  <p className="text-xs text-muted-foreground">{account.followers.toLocaleString()} followers</p>
                </div>
                <p className="mt-2 text-sm leading-relaxed">{account.content}</p>
                <p className="mt-2 text-xs text-muted-foreground">
                  {account.likes.toLocaleString()} likes · {account.replies.toLocaleString()} replies
                  {account.impressions > 0 ? ` · ${account.impressions.toLocaleString()} impressions` : ""}
                </p>
              </div>
            ))}
          </CardContent>
        </Card>
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
                      <Badge variant="secondary">
                        {post.fits ? "Fits this account" : post.kind === "mention" ? "On your account" : "Reply"}
                      </Badge>
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
                  {generating ? "Writing…" : "Reply in this voice"}
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
