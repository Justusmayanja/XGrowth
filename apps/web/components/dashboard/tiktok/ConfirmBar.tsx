import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";

export function ConfirmBar({
  time,
  busy,
  disabled,
  reason,
  onConfirm,
}: {
  time: string;
  busy: boolean;
  disabled: boolean;
  reason: string | null;
  onConfirm: () => void;
}) {
  return (
    <div className="sticky bottom-0 z-20 -mx-4 border-t border-border bg-background/95 px-4 py-3 backdrop-blur-sm pb-[max(0.75rem,env(safe-area-inset-bottom))] md:static md:mx-0 md:border-0 md:bg-transparent md:px-0 md:py-0 md:backdrop-blur-none">
      <Button type="button" className="min-h-11 w-full md:w-auto" disabled={disabled || busy} aria-busy={busy} onClick={onConfirm}>
        {busy && <Loader2 className="motion-safe:animate-spin" aria-hidden />}
        Yes, go ahead for {time}
      </Button>
      <p className="mt-2 max-w-xl text-sm text-muted-foreground">
        This confirms the clip. It posts once your video file is ready and your TikTok is connected.
      </p>
      {reason && (
        <p className="mt-2 max-w-xl text-sm" role="status">
          {reason}
        </p>
      )}
    </div>
  );
}
