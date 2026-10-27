import Link from "next/link";
import { CircleAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { formatWhen, type TikTokBlock } from "@/lib/tiktok";

export function BlockNotice({ block }: { block: TikTokBlock }) {
  const renews = block.renews_at ? formatWhen(block.renews_at, "UTC") : null;

  return (
    <Card>
      <CardContent className="pt-6">
        <div className="flex items-start gap-3">
          <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
          <div className="min-w-0">
            <h2 className="text-base font-medium">Nothing new right now</h2>
            <p className="mt-2 text-sm break-words">{block.message}</p>
            {renews && <p className="mt-2 text-sm text-muted-foreground">Renews {renews}.</p>}
            {block.action === "billing" && (
              <Link href="/pricing" className="mt-4 inline-block">
                <Button className="min-h-11">See plans</Button>
              </Link>
            )}
            {block.action === "finish_setup" && (
              <Link href="/onboarding?platform=tiktok" className="mt-4 inline-block">
                <Button className="min-h-11">Finish setup</Button>
              </Link>
            )}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
