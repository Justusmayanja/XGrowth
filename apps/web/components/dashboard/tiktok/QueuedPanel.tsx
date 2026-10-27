import { Check, Circle } from "lucide-react";
import Link from "next/link";
import { Card, CardContent } from "@/components/ui/card";
import type { TikTokDesk } from "@/lib/tiktok";
import type { Ref } from "react";

const VIDEO = { id: "video_file", waiting: "Video file: not ready yet", done: "Video file: ready" } as const;

export function QueuedPanel({
  blockers,
  connection,
  headingRef,
}: {
  blockers: string[];
  connection?: TikTokDesk["connection"];
  headingRef?: Ref<HTMLHeadingElement>;
}) {
  const fileReady = !blockers.includes(VIDEO.id);
  const linked = !blockers.includes("tiktok_connection");
  const configured = Boolean(connection?.configured);
  const name = connection?.display_name;

  return (
    <Card>
      <CardContent className="pt-6">
        <h2 ref={headingRef} tabIndex={-1} className="text-base font-medium outline-none">
          Confirmed. Not posted yet.
        </h2>
        <p className="mt-2 text-sm text-muted-foreground">
          This confirms the clip. It posts once your video file is ready and your TikTok is connected.
        </p>
        <ul className="mt-4 space-y-2">
          <li className="flex items-start gap-2 text-sm">
            {fileReady ? <Check className="mt-0.5 size-4 shrink-0" aria-hidden /> : <Circle className="mt-0.5 size-4 shrink-0" aria-hidden />}
            <span>{fileReady ? VIDEO.done : VIDEO.waiting}</span>
          </li>
          <li className="flex items-start gap-2 text-sm">
            {linked ? <Check className="mt-0.5 size-4 shrink-0" aria-hidden /> : <Circle className="mt-0.5 size-4 shrink-0" aria-hidden />}
            <span>
              {linked ? (
                `TikTok connection: connected as ${name || "your account"}`
              ) : configured ? (
                <>
                  TikTok connection: not connected yet.{" "}
                  <Link href="/settings" className="underline underline-offset-4">
                    Settings
                  </Link>
                </>
              ) : (
                "TikTok connection: waiting for TikTok approval"
              )}
            </span>
          </li>
        </ul>
      </CardContent>
    </Card>
  );
}
