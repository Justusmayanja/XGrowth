"use client";

import { useEffect, useId, useRef, useState } from "react";
import { AlertCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { durationLabel, watchLabel } from "./media";

const RETRY = "The preview expired. Load it again.";
const SAFE = "Show safe area";

const SAFE_LEFT = `${(90 / 1080) * 100}%`;
const SAFE_TOP = `${(200 / 1920) * 100}%`;
const SAFE_BOTTOM = `${(480 / 1920) * 100}%`;

export function PhonePreview({
  src,
  poster,
  hook,
  bytes,
  durationSeconds,
  onRefresh,
  safeAreaDefault = false,
}: {
  src: string | null;
  poster: string | null;
  hook: string;
  bytes: number | null;
  durationSeconds: number | null;
  onRefresh: () => Promise<{ src: string | null; poster: string | null } | null>;
  safeAreaDefault?: boolean;
}) {
  const switchId = useId();
  const tried = useRef(false);
  const [current, setCurrent] = useState(src);
  const [posterUrl, setPosterUrl] = useState(poster);
  const [safe, setSafe] = useState(safeAreaDefault);
  const [broken, setBroken] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  useEffect(() => {
    setCurrent(src);
    setPosterUrl(poster);
    setBroken(false);
    tried.current = false;
  }, [src, poster]);

  async function refresh() {
    setRefreshing(true);
    try {
      const next = await onRefresh();
      if (!next?.src) {
        setBroken(true);
        return;
      }
      setCurrent(next.src);
      setPosterUrl(next.poster);
      setBroken(false);
    } catch {
      setBroken(true);
    } finally {
      setRefreshing(false);
    }
  }

  function onError() {
    if (!tried.current) {
      tried.current = true;
      void refresh();
      return;
    }
    setBroken(true);
  }

  return (
    <figure className="mx-auto w-full max-w-[300px] space-y-3 lg:max-w-[320px]">
      <div className="rounded-[1.25rem] border border-border bg-background p-2 shadow-sm">
        <div className="relative aspect-[9/16] overflow-hidden rounded-[0.9rem] bg-[#101113]">
          {current && !broken ? (
            <video
              key={current}
              className="h-full w-full bg-black"
              controls
              playsInline
              preload="none"
              poster={posterUrl ?? undefined}
              src={current}
              aria-label={hook || "Clip preview"}
              onError={onError}
            />
          ) : (
            <div className="flex h-full items-end p-4">
              <p className="text-sm text-balance text-white">{hook || "Clip preview"}</p>
            </div>
          )}
          {safe && (
            <div aria-hidden className="pointer-events-none absolute inset-0">
              <div className="absolute inset-y-0 left-0 bg-black/45" style={{ width: SAFE_LEFT }} />
              <div className="absolute inset-y-0 right-0 bg-black/45" style={{ width: SAFE_LEFT }} />
              <div className="absolute inset-x-0 top-0 bg-black/45" style={{ height: SAFE_TOP }} />
              <div className="absolute inset-x-0 bottom-0 bg-black/45" style={{ height: SAFE_BOTTOM }} />
            </div>
          )}
        </div>
      </div>
      <div className="flex flex-wrap gap-2">
        <span className="rounded-sm border border-border px-2 py-1 text-xs tabular-nums text-muted-foreground">{watchLabel(bytes)}</span>
        <span className="rounded-sm border border-border px-2 py-1 text-xs tabular-nums text-muted-foreground">{durationLabel(durationSeconds)}</span>
      </div>
      <div className="flex min-h-11 items-center justify-between gap-3">
        <label id={switchId} htmlFor={`${switchId}-switch`} className="text-sm">
          {SAFE}
        </label>
        <button
          id={`${switchId}-switch`}
          type="button"
          role="switch"
          aria-checked={safe}
          aria-labelledby={switchId}
          onClick={() => setSafe((value) => !value)}
          className={`relative h-7 w-12 shrink-0 rounded-full transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background motion-reduce:transition-none ${safe ? "bg-primary" : "bg-foreground/25"}`}
        >
          <span className={`absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-background shadow transition-transform duration-150 motion-reduce:transition-none ${safe ? "translate-x-5" : "translate-x-0"}`} />
        </button>
      </div>
      {broken && (
        <div className="space-y-2" role="alert">
          <p className="flex items-start gap-2 text-sm">
            <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden />
            {RETRY}
          </p>
          <Button type="button" variant="outline" className="min-h-11" disabled={refreshing} onClick={() => void refresh()}>
            Load preview again
          </Button>
        </div>
      )}
    </figure>
  );
}
