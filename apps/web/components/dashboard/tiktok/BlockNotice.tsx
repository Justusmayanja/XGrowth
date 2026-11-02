import Link from "next/link";
import { CircleAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { formatWhen, type TikTokBlock } from "@/lib/tiktok";

export function BlockNotice({ block }: { block: TikTokBlock }) {
  const renews = block.renews_at ? formatWhen(block.renews_at, "UTC") : null;

  const billing = block.action === "billing";

  return (
    <Card className="h-full">
      <CardContent className="flex min-h-[22rem] flex-col justify-between gap-8 p-6 sm:p-8">
        <div className="max-w-lg">
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <CircleAlert className="size-4 shrink-0" aria-hidden />
            <span>Today</span>
          </div>
          <h2 className="mt-4 text-2xl font-medium tracking-tight">{block.message}</h2>
          <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
            {billing
              ? "A plan is what turns clips on for this account. You still confirm each one. Nothing is posted until you say so."
              : "The desk stays quiet until this is sorted. Nothing is posted until you say so."}
          </p>
          {renews && <p className="mt-3 text-sm text-muted-foreground">Renews {renews}.</p>}
        </div>
        <div className="flex flex-col gap-6">
          <ul className="grid gap-3 text-sm sm:grid-cols-3">
            <li className="rounded-md border border-border bg-surface-2 px-3 py-3">Clips stay in your words.</li>
            <li className="rounded-md border border-border bg-surface-2 px-3 py-3">You confirm before a file leaves.</li>
            <li className="rounded-md border border-border bg-surface-2 px-3 py-3">A plan turns the allowance on.</li>
          </ul>
          {billing && (
            <Link href="/pricing" className="inline-block">
              <Button className="min-h-11">See plans</Button>
            </Link>
          )}
          {block.action === "finish_setup" && (
            <Link href="/onboarding?platform=tiktok" className="inline-block">
              <Button className="min-h-11">Finish setup</Button>
            </Link>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
