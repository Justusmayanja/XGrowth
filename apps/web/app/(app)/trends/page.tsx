"use client";

import Link from "next/link";
import { Hash } from "lucide-react";
import { useEffect, useState } from "react";
import { DeskHeading } from "@/components/DeskHeading";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingMark } from "@/components/LoadingMark";
import { api } from "@/lib/api";
import { usePlatform } from "@/lib/platform";

type Trend = {
  name: string;
  figures?: string;
  posts?: number | null;
  impressions?: number | null;
  likes?: number | null;
  replies?: number | null;
};

export default function TrendsPage() {
  const platform = usePlatform();
  const [trends, setTrends] = useState<Trend[]>([]);
  const [note, setNote] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api<{ trends: Trend[]; note?: string | null }>("/trends")
      .then((r) => {
        setTrends(r.trends);
        setNote(r.note ?? "");
      })
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <DeskHeading icon={Hash} title="Topics">
          <p className="text-muted-foreground">
            {platform === "x"
              ? "Topics from the connected X account, with the counts X returned."
              : "Topics for this account."}
          </p>
        </DeskHeading>
        <Link href="/growth-agent">
          <Button variant="secondary">Open the queue</Button>
        </Link>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            Topics
            <Badge variant="secondary">From X</Badge>
          </CardTitle>
        </CardHeader>
        <CardContent>
          {loading ? (
            <LoadingMark />
          ) : trends.length === 0 ? (
            <p className="text-sm text-muted-foreground">{note || "Connect X to load topics from the account."}</p>
          ) : (
            <>
              {note && <p className="mb-4 text-sm text-muted-foreground">{note}</p>}
              {trends.map((t) => (
                <div key={t.name} className="mb-4 rounded-lg border border-border p-4 last:mb-0">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="font-medium">{t.name}</span>
                    {t.figures && <Badge variant="outline">{t.figures}</Badge>}
                  </div>
                </div>
              ))}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
