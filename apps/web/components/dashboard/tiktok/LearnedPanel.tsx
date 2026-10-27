import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import type { TikTokRun } from "@/lib/tiktok";

export function LearnedPanel({
  note,
  next,
  onContinue,
}: {
  note: string;
  next: TikTokRun | null;
  onContinue?: () => void;
}) {
  const created = next?.outcome === "created";
  const skipped = next && next.outcome !== "created" ? next.message : null;

  return (
    <Card>
      <CardContent className="pt-6">
        <h2 className="text-base font-medium">What this clip taught us</h2>
        <p className="mt-3 text-sm break-words">{note}</p>
        {created && (
          <div className="mt-4">
            {onContinue ? (
              <Button type="button" variant="outline" className="min-h-11" onClick={onContinue}>
                Your next clip is ready
              </Button>
            ) : (
              <p className="text-sm">Your next clip is ready</p>
            )}
          </div>
        )}
        {skipped && onContinue && (
          <Button type="button" variant="outline" className="mt-4 min-h-11 max-w-full whitespace-normal" onClick={onContinue}>
            {skipped}
          </Button>
        )}
        {skipped && !onContinue && <p className="mt-4 text-sm break-words">{skipped}</p>}
      </CardContent>
    </Card>
  );
}
