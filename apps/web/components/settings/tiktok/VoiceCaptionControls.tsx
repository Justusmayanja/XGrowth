"use client";

import type { VideoSettings } from "./types";

const VOICE = "Voice";
const FEMALE = "Female";
const MALE = "Male";
const LANGUAGE = "Language";
const LANGUAGE_NOTE = "More languages are coming.";
const CAPTIONS = "Captions";
const BOLD = "Bold";
const CLEAN = "Clean";
const LENGTH = "Length";
const SHORT = "About 20s";
const STANDARD = "About 35s";
const WHEN = "When to make it";
const AHEAD = "Make it ahead";
const AHEAD_NOTE = "The video is ready while you read the clip.";
const CONFIRM = "Make it when I confirm";
const CONFIRM_NOTE = "The video starts after you confirm.";
const SAMPLE = "Salt the water first.";
const MUSIC_ON = "A quiet music bed can sit under the voice.";
const MUSIC_OFF = "Music stays off. No licensed tracks are installed.";

function Segment({
  label,
  value,
  options,
  onChange,
}: {
  label: string;
  value: string;
  options: { id: string; label: string }[];
  onChange: (value: string) => void;
}) {
  return (
    <div className="space-y-2">
      <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-2">
        {options.map((option) => {
          const selected = value === option.id;
          return (
            <button
              key={option.id}
              type="button"
              role="radio"
              aria-checked={selected}
              tabIndex={selected ? 0 : -1}
              onClick={() => onChange(option.id)}
              onKeyDown={(event) => {
                const index = options.findIndex((item) => item.id === value);
                if (event.key !== "ArrowRight" && event.key !== "ArrowLeft") return;
                event.preventDefault();
                const direction = event.key === "ArrowRight" ? 1 : -1;
                onChange(options[(index + direction + options.length) % options.length].id);
              }}
              className={`min-h-11 rounded-sm border px-3 text-sm transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background motion-reduce:transition-none ${
                selected ? "border-foreground bg-muted" : "border-border bg-background"
              }`}
            >
              {option.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}

export function VoiceCaptionControls({
  video,
  musicAvailable,
  background = "#101113",
  foreground = "#FFFFFF",
  onChange,
}: {
  video: VideoSettings;
  musicAvailable: boolean;
  background?: string;
  foreground?: string;
  onChange: (video: VideoSettings) => void;
}) {
  const bold = video.captions.style === "bold";

  return (
    <div className="space-y-5">
      <Segment
        label={VOICE}
        value={video.voice.style}
        options={[
          { id: "female", label: FEMALE },
          { id: "male", label: MALE },
        ]}
        onChange={(style) => onChange({ ...video, voice: { ...video.voice, style } })}
      />
      <div className="space-y-1">
        <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{LANGUAGE}</p>
        <p className="text-sm">English</p>
        <p className="text-sm text-muted-foreground">{LANGUAGE_NOTE}</p>
      </div>
      <div className="flex min-h-11 items-center justify-between gap-3">
        <label htmlFor="captions-enabled" className="text-sm font-medium">
          {CAPTIONS}
        </label>
        <button
          id="captions-enabled"
          type="button"
          role="switch"
          aria-checked={video.captions.enabled}
          onClick={() => onChange({ ...video, captions: { ...video.captions, enabled: !video.captions.enabled } })}
          className={`relative h-7 w-12 rounded-full transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background motion-reduce:transition-none ${video.captions.enabled ? "bg-primary" : "bg-foreground/25"}`}
        >
          <span className={`absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-background shadow transition-transform duration-150 motion-reduce:transition-none ${video.captions.enabled ? "translate-x-5" : ""}`} />
        </button>
      </div>
      {video.captions.enabled && (
        <>
          <Segment
            label="Caption style"
            value={video.captions.style}
            options={[
              { id: "bold", label: BOLD },
              { id: "clean", label: CLEAN },
            ]}
            onChange={(style) => onChange({ ...video, captions: { ...video.captions, style } })}
          />
          <div className="space-y-2">
            <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Caption sample</p>
            <div className="flex aspect-[9/16] max-w-[180px] items-end rounded-sm p-3" style={{ background, color: foreground }}>
              <p className={`w-full text-center ${bold ? "text-base font-semibold" : "text-sm font-normal"}`}>{SAMPLE}</p>
            </div>
          </div>
        </>
      )}
      <Segment
        label={LENGTH}
        value={video.length}
        options={[
          { id: "short", label: SHORT },
          { id: "standard", label: STANDARD },
        ]}
        onChange={(length) => onChange({ ...video, length })}
      />
      <fieldset className="space-y-2">
        <legend className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{WHEN}</legend>
        <div role="radiogroup" aria-label={WHEN} className="grid gap-2">
          {[
            { id: "ahead", label: AHEAD, note: AHEAD_NOTE },
            { id: "on_confirm", label: CONFIRM, note: CONFIRM_NOTE },
          ].map((option) => {
            const selected = video.generate === option.id;
            return (
              <button
                key={option.id}
                type="button"
                role="radio"
                aria-checked={selected}
                onClick={() => onChange({ ...video, generate: option.id })}
                className={`min-h-11 rounded-sm border p-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background ${
                  selected ? "border-foreground bg-muted/50" : "border-border"
                }`}
              >
                <span className="block text-sm font-medium">{option.label}</span>
                <span className="mt-1 block text-sm text-muted-foreground">{option.note}</span>
              </button>
            );
          })}
        </div>
      </fieldset>
      <p className="text-sm text-muted-foreground">{musicAvailable ? MUSIC_ON : MUSIC_OFF}</p>
      {musicAvailable && (
        <div className="flex min-h-11 items-center justify-between gap-3">
          <label htmlFor="music-enabled" className="text-sm font-medium">
            Music bed
          </label>
          <button
            id="music-enabled"
            type="button"
            role="switch"
            aria-checked={video.music.enabled}
            onClick={() => onChange({ ...video, music: { enabled: !video.music.enabled } })}
            className={`relative h-7 w-12 rounded-full transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background motion-reduce:transition-none ${video.music.enabled ? "bg-primary" : "bg-foreground/25"}`}
          >
            <span className={`absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-background shadow transition-transform duration-150 motion-reduce:transition-none ${video.music.enabled ? "translate-x-5" : ""}`} />
          </button>
        </div>
      )}
    </div>
  );
}
