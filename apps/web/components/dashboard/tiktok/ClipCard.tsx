"use client";

import { useState, type ReactNode } from "react";
import { Check, CircleCheck, Clock, Copy } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { lineText, type TikTokClip } from "@/lib/tiktok";

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
    <Button
      type="button"
      variant="ghost"
      size="icon"
      className="size-11 shrink-0"
      title={name}
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
  const confirmed = clip.status === "queued";
  const Icon = clip.status === "posted" || clip.status === "learned" ? CircleCheck : confirmed ? Check : Clock;

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
          <span key={`${index}-${part}`} className="text-muted-foreground">
            {part}
          </span>
        ) : (
          <span key={`${index}-text`}>{part}</span>
        ),
      )}
    </p>
  );
}

export function ClipCard({ clip, collapsed = false }: { clip: TikTokClip; collapsed?: boolean }) {
  const lines = clip.script_lines.filter(Boolean);
  const long = lines.length > 4;
  const [open, setOpen] = useState(!collapsed);
  const [full, setFull] = useState(false);
  const script = lines.join("\n");
  const screens = clip.on_screen_text.map(lineText).filter(Boolean);
  const shots = clip.shots.map(lineText).filter(Boolean);
  const shown = long && !full ? lines.slice(0, 3) : lines;

  return (
    <Card id="clip" className="motion-safe:animate-in motion-safe:fade-in">
      <CardContent className="space-y-5 pt-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 space-y-2">
            <StatusChip clip={clip} />
            <h3 className="text-xl font-medium break-words sm:text-2xl">{clip.hook || clip.topic || "Clip"}</h3>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {clip.hook && <CopyControl text={clip.hook} label="Copy hook" />}
            {collapsed && (
              <Button type="button" variant="outline" className="min-h-11" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
                {open ? "Hide full script" : "Show full script"}
              </Button>
            )}
          </div>
        </div>

        {open && (
          <div className="space-y-5">
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

            {screens.length > 0 && (
              <Section label="On screen">
                <ul className="space-y-1">
                  {screens.map((line) => (
                    <li key={line} className="text-sm break-words">
                      {line}
                    </li>
                  ))}
                </ul>
              </Section>
            )}

            {shots.length > 0 && (
              <Section label="Shots">
                <ol className="space-y-1">
                  {shots.map((line, index) => (
                    <li key={`${index}-${line}`} className="text-sm break-words text-muted-foreground">
                      {index + 1}. {line}
                    </li>
                  ))}
                </ol>
              </Section>
            )}

            {clip.sound_note && (
              <Section label="Sound">
                <p className="text-sm break-words text-muted-foreground">{clip.sound_note}</p>
              </Section>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
