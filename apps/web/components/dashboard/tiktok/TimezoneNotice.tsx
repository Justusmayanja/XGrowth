import { Globe } from "lucide-react";
import { Button } from "@/components/ui/button";
import { browserTimeZone } from "@/lib/tiktok";

export function TimezoneNotice({ busy, onUse }: { busy: boolean; onUse: (zone: string) => void }) {
  const zone = browserTimeZone();

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-border px-3 py-3">
      <p className="flex min-w-0 items-start gap-2 text-sm">
        <Globe className="mt-0.5 size-4 shrink-0" aria-hidden />
        <span className="break-words">Times are in UTC because we don't know your timezone yet. Use {zone}?</span>
      </p>
      <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={() => onUse(zone)}>
        Use {zone}
      </Button>
    </div>
  );
}
