"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { api } from "@/lib/api";
import { cn } from "@/lib/utils";

type DecisionRow = {
  id: number;
  decision: string;
  action_taken: string | null;
  opportunity_score: number | string | null;
  reason: string | null;
  created_at: string;
  user?: { name: string; email: string };
};

type PostRow = {
  id: number;
  content: string;
  status: string;
  impressions: number;
  likes: number;
  reposts: number;
  replies: number;
  posted_at: string | null;
  created_at: string;
  user?: { name: string; email: string };
  trend?: { name: string } | null;
};

type Paginated<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};

const decisionTypes = ["POST", "ENGAGE", "WAIT", "IGNORE"];

export default function AdminActivityPage() {
  const [tab, setTab] = useState<"decisions" | "content">("decisions");
  const [search, setSearch] = useState("");
  const [decisionFilter, setDecisionFilter] = useState("all");
  const [statusFilter, setStatusFilter] = useState("all");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [decisions, setDecisions] = useState<Paginated<DecisionRow> | null>(null);
  const [posts, setPosts] = useState<Paginated<PostRow> | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams({ page: String(page) });
      if (search.trim()) params.set("search", search.trim());

      if (tab === "decisions") {
        if (decisionFilter !== "all") params.set("decision", decisionFilter);
        const res = await api<{ decisions: Paginated<DecisionRow> }>(`/admin/decisions?${params}`);
        setDecisions(res.decisions);
      } else {
        if (statusFilter !== "all") params.set("status", statusFilter);
        const res = await api<{ posts: Paginated<PostRow> }>(`/admin/posts?${params}`);
        setPosts(res.posts);
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : "Failed to load activity";
      setError(message);
      toast.error(message);
    } finally {
      setLoading(false);
    }
  }, [tab, page, search, decisionFilter, statusFilter]);

  useEffect(() => {
    load();
  }, [load]);

  function switchTab(next: "decisions" | "content") {
    setTab(next);
    setPage(1);
    setSearch("");
  }

  const paginated = tab === "decisions" ? decisions : posts;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Activity</h1>
        <p className="text-muted-foreground">
          Growth Engine decisions and content across all customer accounts
        </p>
      </div>

      <div className="flex gap-2 border-b border-border pb-2">
        {(["decisions", "content"] as const).map((key) => (
          <button
            key={key}
            type="button"
            onClick={() => switchTab(key)}
            className={cn(
              "rounded-lg px-4 py-2 text-sm capitalize transition",
              tab === key ? "bg-surface-2 text-white" : "text-muted hover:text-white"
            )}
          >
            {key === "decisions" ? "Engine decisions" : "Content"}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        <Input
          placeholder={tab === "decisions" ? "Search customer or reason…" : "Search customer or content…"}
          value={search}
          onChange={(e) => {
            setPage(1);
            setSearch(e.target.value);
          }}
          className="max-w-sm"
        />
        {tab === "decisions" ? (
          <Select
            value={decisionFilter}
            onValueChange={(v) => {
              if (v) {
                setPage(1);
                setDecisionFilter(v);
              }
            }}
          >
            <SelectTrigger className="w-36">
              <SelectValue placeholder="All types" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All types</SelectItem>
              {decisionTypes.map((type) => (
                <SelectItem key={type} value={type}>
                  {type}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : (
          <Select
            value={statusFilter}
            onValueChange={(v) => {
              if (v) {
                setPage(1);
                setStatusFilter(v);
              }
            }}
          >
            <SelectTrigger className="w-36">
              <SelectValue placeholder="All statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="draft">Draft</SelectItem>
              <SelectItem value="posted">Posted</SelectItem>
            </SelectContent>
          </Select>
        )}
        <Button variant="secondary" onClick={load}>
          Refresh
        </Button>
      </div>

      {loading && <p className="text-muted-foreground">Loading activity…</p>}
      {!loading && error && (
        <div className="space-y-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4">
          <p className="text-sm text-destructive">{error}</p>
          <Button variant="secondary" size="sm" onClick={load}>
            Retry
          </Button>
        </div>
      )}

      {!loading && !error && tab === "decisions" && decisions && (
        <div className="space-y-4">
          <p className="text-sm text-muted-foreground">{decisions.total} decision(s)</p>
          {decisions.data.map((d) => (
            <Card key={d.id}>
              <CardHeader className="pb-2">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant="outline">{d.decision}</Badge>
                  {d.opportunity_score != null && (
                    <Badge variant="secondary">{Math.round(Number(d.opportunity_score))}/100</Badge>
                  )}
                  <span className="text-sm font-medium">{d.user?.name}</span>
                  <span className="text-xs text-muted-foreground">{d.user?.email}</span>
                </div>
              </CardHeader>
              <CardContent>
                <p className="text-sm">{d.reason}</p>
                <p className="mt-1 text-xs text-muted-foreground">
                  {new Date(d.created_at).toLocaleString()}
                  {d.action_taken ? ` · ${d.action_taken}` : ""}
                </p>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {!loading && !error && tab === "content" && posts && (
        <div className="space-y-4">
          <p className="text-sm text-muted-foreground">{posts.total} post(s)</p>
          {posts.data.map((post) => (
            <Card key={post.id}>
              <CardHeader className="pb-2">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={post.status === "posted" ? "secondary" : "outline"}>{post.status}</Badge>
                  <span className="text-sm font-medium">{post.user?.name}</span>
                  {post.trend?.name && <Badge variant="outline">{post.trend.name}</Badge>}
                </div>
              </CardHeader>
              <CardContent>
                <p className="text-sm">{post.content}</p>
                <p className="mt-1 text-xs text-muted-foreground">
                  {new Date(post.posted_at ?? post.created_at).toLocaleString()}
                  {post.status === "posted" &&
                    ` · ${post.impressions.toLocaleString()} impressions · ${post.likes} likes`}
                </p>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {!loading && !error && paginated && paginated.last_page > 1 && (
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Previous
          </Button>
          <span className="text-sm text-muted-foreground">
            Page {paginated.current_page} of {paginated.last_page}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={page >= paginated.last_page}
            onClick={() => setPage((p) => p + 1)}
          >
            Next
          </Button>
        </div>
      )}
    </div>
  );
}
