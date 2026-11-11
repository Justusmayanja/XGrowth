"use client";

import { useState, type ReactNode } from "react";
import { Check, CircleCheck, Clock, Copy } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { type TikTokClip } from "@/lib/tiktok";
import { ClipTimeline } from "./video/ClipTimeline";

async function copyText(text: string) {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(text);
    return;
  }
  const area = document.createElement("textarea");
  area.value = text;
  area.setAttribute("readonly", "");
  area.style.position = "fixed";
  area.style.left = "-9999px";
  document.body.appendChild(area);
  area.select();
  document.execCommand("copy");
  area.remove();
}

function CopyControl({ text, label }: { text: string; label: string }) {
  const [copied, setCopied] = useState(false);
  const name = copied ? "Copied" : label;

  return (
    <span className="group relative inline-flex">
    <Button
      type="button"
      variant="ghost"
      size="icon"
      className="size-11 shrink-0"
      aria-label={name}
      onClick={() => {
        void copyText(text)
          .then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
          })
          .catch(() => setCopied(false));
      }}
    >
      {copied ? <Check className="size-4" aria-hidden /> : <Copy className="size-4" aria-hidden />}
    </Button>
    <span role="tooltip" className="pointer-events-none absolute top-full right-0 z-10 mt-1 hidden rounded-sm border border-border bg-background px-2 py-1 text-xs group-hover:block group-focus-within:block">
      {name}
    </span>
    </span>
  );
}

function Section({ label, children }: { label: string; children: ReactNode }) {
  return (
    <section className="space-y-2">
      <h3 className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{label}</h3>
      {children}
    </section>
  );
}

function StatusChip({ clip }: { clip: TikTokClip }) {
  const confirmed = clip.status === "queued" || clip.status === "uploading" || clip.status === "in_drafts" || clip.status === "upload_failed";
  const Icon = clip.status === "posted" || clip.status === "learned" || clip.status === "in_drafts" ? CircleCheck : confirmed ? Check : Clock;

  return (
    <span className="inline-flex min-h-7 items-center gap-1.5 rounded-sm border border-border bg-muted/50 px-2 text-xs text-foreground">
      <Icon className="size-3.5 shrink-0" aria-hidden />
      {clip.status_label}
    </span>
  );
}

function CaptionText({ caption }: { caption: string }) {
  return (
    <p className="whitespace-pre-wrap break-words rounded-sm border border-border bg-muted/40 p-3 text-sm">
      {caption.split(/(#[^\s#]+)/g).map((part, index) =>
        part.startsWith("#") ? (
          <span key={`${index}-${part}`} className="mx-0.5 inline-flex items-center rounded-sm border border-border bg-background px-1.5 py-0.5 text-xs">
            {part}
          </span>
        ) : (
          <span key={`${index}-text`}>{part}</span>
        ),
      )}
    </p>
  );
}

export function ClipCard({ clip, collapsed = false, aside }: { clip: TikTokClip; collapsed?: boolean; aside?: ReactNode }) {
  const lines = clip.script_lines.filter(Boolean);
  const long = lines.length > 4;
  const [open, setOpen] = useState(!collapsed);
  const [full, setFull] = useState(false);
  const script = lines.join("\n");
  const shown = long && !full ? lines.slice(0, 3) : lines;

  return (
    <Card id="clip" className="motion-safe:animate-in motion-safe:fade-in">
      <CardContent className="space-y-5 pt-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 space-y-2">
            <StatusChip clip={clip} />
            {clip.week?.label && (
              <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{clip.week.label}</p>
            )}
            <h3 className="text-balance text-xl font-medium break-words sm:text-2xl">{clip.hook || clip.topic || "Clip"}</h3>
            <ClipTimeline clip={clip} />
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {clip.hook && !open && <CopyControl text={clip.hook} label="Copy hook" />}
            {collapsed && (
              <Button type="button" variant="outline" className="min-h-11" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
                {open ? "Hide full script" : "Show full script"}
              </Button>
            )}
          </div>
        </div>

        {(open || aside) && (
          <div className={aside ? "grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(160px,320px)]" : "space-y-5"}>
            {open && <div className="order-2 space-y-5 lg:order-1">
            {clip.hook && (
              <Section label="Hook">
                <div className="flex items-start justify-between gap-2">
                  <p className="text-sm text-balance">{clip.hook}</p>
                  <CopyControl text={clip.hook} label="Copy hook" />
                </div>
              </Section>
            )}
            {script && (
              <Section label="Script">
                <div className="flex items-start justify-end">
                  <CopyControl text={script} label="Copy script" />
                </div>
                <ol className="space-y-2">
                  {shown.map((line, index) => (
                    <li key={`${index}-${line}`} className="text-sm break-words">
                      <span className="mr-2 text-muted-foreground">{index + 1}.</span>
                      {line}
                    </li>
                  ))}
                </ol>
                {long && (
                  <Button type="button" variant="ghost" className="min-h-11" aria-expanded={full} onClick={() => setFull((value) => !value)}>
                    {full ? "Hide full script" : "Show full script"}
                  </Button>
                )}
              </Section>
            )}

            {clip.caption && (
              <Section label="Caption">
                <div className="flex items-start justify-end">
                  <CopyControl text={clip.caption} label="Copy caption" />
                </div>
                <CaptionText caption={clip.caption} />
              </Section>
            )}
            </div>}
            {aside && <div id="video" className="order-1 mx-auto w-full min-w-0 max-w-[320px] scroll-mt-24 lg:order-2 lg:mx-0">{aside}</div>}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
