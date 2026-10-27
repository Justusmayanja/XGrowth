"use client";

import { useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  browserTimeZone,
  dateFromLocalInput,
  localInputValue,
  slotOn,
  toIsoWithOffset,
} from "@/lib/tiktok";

export function ReschedulePopover({
  profileZone,
  assumed,
  error,
  busy,
  onSave,
}: {
  profileZone: string | null;
  assumed: boolean;
  error: string | null;
  busy: boolean;
  onSave: (iso: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const browser = browserTimeZone();
  const differs = Boolean(profileZone && !assumed && profileZone !== browser);
  const bounds = useMemo(() => {
    const min = new Date(Date.now() + 60 * 60 * 1000);
    const max = new Date(Date.now() + 14 * 24 * 60 * 60 * 1000);
    const tonight = slotOn(new Date());
    const tomorrow = slotOn(new Date(Date.now() + 24 * 60 * 60 * 1000));
    return { min, max, tonight: tonight.getTime() >= min.getTime() ? tonight : null, tomorrow };
  }, [open]);

  return (
    <div>
      <Button type="button" variant="outline" className="min-h-11" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
        Change time
      </Button>
      {open && (
        <div className="mt-3 max-w-md space-y-3 rounded-sm border border-border p-3">
          <p className="text-sm text-muted-foreground">Times use {browser}.</p>
          {differs && (
            <p className="text-sm text-muted-foreground">
              Your account timezone is {profileZone}. This control uses the browser clock so the offset we send is exact.
            </p>
          )}
          <div className="flex flex-wrap gap-2">
            {bounds.tonight && (
              <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={() => onSave(toIsoWithOffset(bounds.tonight!))}>
                Tonight 7:30 PM
              </Button>
            )}
            <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={() => onSave(toIsoWithOffset(bounds.tomorrow))}>
              Tomorrow 7:30 PM
            </Button>
          </div>
          <form
            className="space-y-2"
            onSubmit={(event) => {
              event.preventDefault();
              const data = new FormData(event.currentTarget);
              const date = dateFromLocalInput(String(data.get("when") ?? ""));
              if (!date) return;
              onSave(toIsoWithOffset(date));
            }}
          >
            <label className="block text-sm" htmlFor="tiktok-when">
              Pick a time
              <Input
                id="tiktok-when"
                name="when"
                type="datetime-local"
                className="mt-1"
                required
                min={localInputValue(bounds.min)}
                max={localInputValue(bounds.max)}
              />
            </label>
            <Button type="submit" variant="outline" className="min-h-11" disabled={busy}>
              Save this time
            </Button>
          </form>
          {error && (
            <p className="text-sm" role="status">
              {error}
            </p>
          )}
        </div>
      )}
    </div>
  );
}
