import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";

export function ConfirmBar({
  time,
  label,
  busy,
  disabled,
  reason,
  onConfirm,
}: {
  time: string;
  label?: string;
  busy: boolean;
  disabled: boolean;
  reason: string | null;
  onConfirm: () => void;
}) {
  return (
    <div className="sticky bottom-0 z-20 -mx-4 border-t border-border bg-background/95 px-4 py-3 shadow-[0_-8px_24px_rgba(0,0,0,0.08)] backdrop-blur-sm pb-[max(0.75rem,env(safe-area-inset-bottom))] md:static md:mx-0 md:border-0 md:bg-transparent md:px-0 md:py-0 md:shadow-none md:backdrop-blur-none">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <Button type="button" className="min-h-11 w-full sm:w-auto" disabled={disabled || busy} aria-busy={busy} onClick={onConfirm}>
          {busy && <Loader2 className="motion-safe:animate-spin" aria-hidden />}
          {label ?? "Yes, send to my TikTok drafts"}
        </Button>
        <p className="text-sm text-muted-foreground">Best time to post: {time}.</p>
      </div>
      <p className="mt-2 max-w-xl text-sm text-muted-foreground">
        Mackie17 sends the file to your TikTok drafts. You finish and post it in TikTok.
      </p>
      {reason && (
        <p className="mt-2 max-w-xl text-sm" role="status">
          {reason}
        </p>
      )}
    </div>
  );
}
