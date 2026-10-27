import { Check, Circle } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import type { Ref } from "react";

const ITEMS = [
  { id: "video_file", waiting: "Video file: not ready yet", done: "Video file: ready" },
  { id: "tiktok_connection", waiting: "TikTok connection: waiting for TikTok approval", done: "TikTok connection: connected" },
] as const;

export function QueuedPanel({
  blockers,
  headingRef,
}: {
  blockers: string[];
  headingRef?: Ref<HTMLHeadingElement>;
}) {
  return (
    <Card>
      <CardContent className="pt-6">
        <h2 ref={headingRef} tabIndex={-1} className="text-base font-medium outline-none">
          Confirmed. Not posted yet.
        </h2>
        <ul className="mt-4 space-y-2">
          {ITEMS.map((item) => {
            const done = !blockers.includes(item.id);
            return (
              <li key={item.id} className="flex items-start gap-2 text-sm">
                {done ? <Check className="mt-0.5 size-4 shrink-0" aria-hidden /> : <Circle className="mt-0.5 size-4 shrink-0" aria-hidden />}
                <span>{done ? item.done : item.waiting}</span>
              </li>
            );
          })}
        </ul>
      </CardContent>
    </Card>
  );
}
