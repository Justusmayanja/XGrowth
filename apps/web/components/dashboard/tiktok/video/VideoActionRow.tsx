import { Button } from "@/components/ui/button";

const AGAIN = "Make it again";
const OWN = "Use my own video instead";
const LIMIT = "This clip has already been made again twice.";

export function VideoActionRow({
  canRegenerate,
  busy,
  againLabel = AGAIN,
  onAgain,
  onUseOwn,
}: {
  canRegenerate: boolean;
  busy: boolean;
  againLabel?: string;
  onAgain: () => void;
  onUseOwn: () => void;
}) {
  return (
    <div className="space-y-2 border-t border-border pt-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
        <Button type="button" variant="secondary" className="min-h-11" disabled={!canRegenerate || busy} onClick={onAgain}>
          {againLabel}
        </Button>
        <Button type="button" variant="ghost" className="min-h-11" onClick={onUseOwn}>
          {OWN}
        </Button>
      </div>
      {!canRegenerate && <p className="text-sm text-muted-foreground">{LIMIT}</p>}
    </div>
  );
}
