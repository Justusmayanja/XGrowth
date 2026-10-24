"use client";

import { Heart, MessageCircle, Repeat2 } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Avatar, TimelineColumn, TimelineHeader, avatarColor, compactCount, minutesLabel } from "@/components/x/timeline";
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
  kind?: "mention" | "niche";
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
    <TimelineColumn>
      <TimelineHeader title="Replies" />
      {loading && <p className="px-4 py-8 text-[15px] text-[#71767b]">Loading replies from X…</p>}
      {!loading && posts.length === 0 && (
        <div className="px-8 py-12">
          <h2 className="text-[28px] font-extrabold leading-tight text-[#e7e9ea]">No conversations yet</h2>
          <p className="mt-2 max-w-sm text-[15px] leading-5 text-[#71767b]">
            {note || "Replies, mentions, and posts in your niche show up here."}
          </p>
        </div>
      )}
      {posts.map((post) => {
        const handle = post.author.startsWith("@") ? post.author : `@${post.author}`;
        const name = handle.slice(1);
        const open = activePost?.id === post.id;
        return (
          <article key={post.id} className="border-b border-[#2f3336] px-4 py-3 transition-colors hover:bg-white/[0.03]">
            {post.kind === "mention" && (
              <p className="mb-1 ml-[52px] text-[13px] font-medium text-[#71767b]">Mentioned you</p>
            )}
            <div className="flex gap-3">
              <Avatar name={name} color={avatarColor(handle)} />
              <div className="min-w-0 flex-1">
                <p className="flex min-w-0 flex-wrap items-center gap-x-1 text-[15px] leading-5">
                  <span className="truncate font-bold text-[#e7e9ea]">{name}</span>
                  <span className="truncate text-[#71767b]">{handle}</span>
                  <span className="text-[#71767b]">·</span>
                  <span className="text-[#71767b]">{minutesLabel(post.minutes_ago)}</span>
                </p>
                <p className="mt-1 whitespace-pre-wrap break-words text-[15px] leading-5 text-[#e7e9ea]">{post.content}</p>
                <div className="mt-2 flex max-w-md items-center justify-between text-[#71767b]">
                  <button
                    type="button"
                    onClick={() => generateReplies(post)}
                    aria-label={`${post.replies} replies. Draft a reply`}
                    className="inline-flex items-center rounded-full p-2 hover:bg-[#1d9bf0]/10 hover:text-[#1d9bf0]"
                  >
                    <MessageCircle className="size-[18px]" strokeWidth={1.8} />
                    {post.replies > 0 && (
                      <span className="min-w-[1ch] px-1 text-[13px] tabular-nums">{compactCount(post.replies)}</span>
                    )}
                  </button>
                  <span className="inline-flex items-center p-2" aria-label={`${post.reposts} reposts`}>
                    <Repeat2 className="size-[18px]" strokeWidth={1.8} />
                    {post.reposts > 0 && (
                      <span className="min-w-[1ch] px-1 text-[13px] tabular-nums">{compactCount(post.reposts)}</span>
                    )}
                  </span>
                  <span className="inline-flex items-center p-2" aria-label={`${post.likes} likes`}>
                    <Heart className="size-[18px]" strokeWidth={1.8} />
                    {post.likes > 0 && (
                      <span className="min-w-[1ch] px-1 text-[13px] tabular-nums">{compactCount(post.likes)}</span>
                    )}
                  </span>
                </div>
                {open && (
                  <div className="mt-2 border-t border-[#2f3336] pt-3">
                    <p className="text-[13px] text-[#71767b]">Replying to {handle}</p>
                    {generating && <p className="mt-3 text-[15px] text-[#71767b]">Writing replies…</p>}
                    {!generating && drafts.length === 0 && (
                      <p className="mt-3 text-[15px] text-[#71767b]">No reply options came back for this post.</p>
                    )}
                    {drafts.map((draft, index) => (
                      <div key={index} className="mt-3 flex gap-3">
                        <Avatar name="You" />
                        <div className="min-w-0 flex-1">
                          <p className="whitespace-pre-wrap break-words text-[15px] leading-5 text-[#e7e9ea]">{draft.content}</p>
                          <button
                            type="button"
                            onClick={() => saveAsDraft(draft.content)}
                            className="mt-2 rounded-full border border-[#536471] px-4 py-1 text-sm font-bold text-[#e7e9ea] hover:bg-[#1d9bf0]/10 hover:text-[#1d9bf0]"
                          >
                            Save draft
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </article>
        );
      })}
    </TimelineColumn>
  );
}
