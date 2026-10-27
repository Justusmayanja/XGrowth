"use client";

import { useState } from "react";
import { Check, Copy } from "lucide-react";
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

  return (
    <Button
      type="button"
      variant="ghost"
      size="sm"
      className="min-h-11"
      onClick={() => {
        void copyText(text)
          .then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
          })
          .catch(() => setCopied(false));
      }}
    >
      {copied ? <Check aria-hidden /> : <Copy aria-hidden />}
      {copied ? "Copied" : label}
    </Button>
  );
}

export function ClipCard({ clip, collapsed = false }: { clip: TikTokClip; collapsed?: boolean }) {
  const [open, setOpen] = useState(!collapsed);
  const script = clip.script_lines.filter(Boolean).join("\n");
  const screens = clip.on_screen_text.map(lineText).filter(Boolean);
  const shots = clip.shots.map(lineText).filter(Boolean);

  return (
    <Card id="clip">
      <CardContent className="space-y-5 pt-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="text-xs text-muted-foreground">{clip.status_label}</p>
            <h3 className="mt-1 text-lg font-medium break-words">{clip.hook || clip.topic || "Clip"}</h3>
          </div>
          <div className="flex flex-wrap gap-2">
            {clip.hook && <CopyControl text={clip.hook} label="Copy hook" />}
            {collapsed && (
              <Button type="button" variant="outline" className="min-h-11" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
                {open ? "Hide the script" : "Show the script"}
              </Button>
            )}
          </div>
        </div>

        {open && (
          <div className="space-y-5">

            {script && (
              <section>
                <div className="flex items-center justify-between gap-2">
                  <h4 className="text-sm font-medium">Say this</h4>
                  <CopyControl text={script} label="Copy script" />
                </div>
                <ol className="space-y-2">
                  {clip.script_lines.filter(Boolean).map((line, index) => (
                    <li key={`${index}-${line}`} className="text-sm break-words">
                      <span className="mr-2 text-muted-foreground">{index + 1}.</span>
                      {line}
                    </li>
                  ))}
                </ol>
              </section>
            )}

            {clip.caption && (
              <section>
                <div className="flex items-center justify-between gap-2">
                  <h4 className="text-sm font-medium">Caption</h4>
                  <CopyControl text={clip.caption} label="Copy caption" />
                </div>
                <p className="whitespace-pre-wrap break-words text-sm">{clip.caption}</p>
              </section>
            )}

            {screens.length > 0 && (
              <section>
                <h4 className="text-sm font-medium">On screen</h4>
                <ul className="mt-2 space-y-1">
                  {screens.map((line) => (
                    <li key={line} className="text-sm break-words text-muted-foreground">
                      {line}
                    </li>
                  ))}
                </ul>
              </section>
            )}

            {shots.length > 0 && (
              <section>
                <h4 className="text-sm font-medium">Shots</h4>
                <ol className="mt-2 space-y-1">
                  {shots.map((line, index) => (
                    <li key={`${index}-${line}`} className="text-sm break-words text-muted-foreground">
                      {index + 1}. {line}
                    </li>
                  ))}
                </ol>
              </section>
            )}

            {clip.sound_note && (
              <section>
                <h4 className="text-sm font-medium">Sound</h4>
                <p className="mt-1 text-sm break-words text-muted-foreground">{clip.sound_note}</p>
              </section>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
