"use client";

import { useId, useState } from "react";
import { AlertTriangle, Check } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { captionColour, contrastRatio, isHex } from "./contrast";
import { fieldMessage, type VideoSettings } from "./types";

const LOOK = "Preview of the look";
const LOGO_RULES = "PNG, JPG, or WebP. Under 2 MB and under 2000 pixels on each side.";
const COLOURS = "Use these colours";
const OUTRO = "Outro text";
const STATEMENT = "Salt the water first.";
const CAPTION = "Taste it before the pasta.";

export function ContrastBadge({ background, foreground }: { background: string; foreground: string }) {
  const ratio = contrastRatio(background, foreground);
  const pass = ratio >= 4.5;
  const Icon = pass ? Check : AlertTriangle;
  return (
    <p className="inline-flex min-h-7 items-center gap-1.5 text-sm">
      <Icon className="size-4" aria-hidden />
      <span>{pass ? "Pass" : "Fail"}</span>
      <span className="tabular-nums text-muted-foreground">{ratio.toFixed(2)}:1</span>
    </p>
  );
}

export function BrandKit({
  video,
  logoSaved,
  fieldErrors,
  contrastPair,
  onChange,
  onLogo,
  onRemoveLogo,
}: {
  video: VideoSettings;
  logoSaved: boolean;
  fieldErrors: Record<string, string>;
  contrastPair?: { background: string; foreground: string } | null;
  onChange: (video: VideoSettings) => void;
  onLogo: (file: File) => void;
  onRemoveLogo: () => void;
}) {
  const logoId = useId();
  const [localLogo, setLocalLogo] = useState<string | null>(null);
  const background = video.brand.use_colors && isHex(video.brand.primary) ? video.brand.primary : "#101113";
  const text = captionColour(background);
  const badgeBackground = contrastPair?.background ?? background;
  const badgeText = contrastPair?.foreground ?? text;
  const font = video.brand.font === "source_sans" ? '"Source Sans 3", sans-serif' : "inherit";

  function setBrand(patch: Partial<VideoSettings["brand"]>) {
    onChange({ ...video, brand: { ...video.brand, ...patch } });
  }

  return (
    <div className="space-y-5">
      <div className="space-y-2">
        <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Logo</p>
        <div className="flex gap-3">
          {[false, true].map((dark) => (
            <div key={String(dark)} className={`flex h-20 w-20 items-center justify-center rounded-sm border border-border ${dark ? "bg-[#101113]" : "bg-white"}`}>
              {localLogo ? (
                // The file is chosen in this session. A saved logo has no public URL.
                // eslint-disable-next-line @next/next/no-img-element
                <img src={localLogo} alt="" className="max-h-14 max-w-14 object-contain" />
              ) : (
                <span className={`text-xs ${dark ? "text-white" : "text-[#101113]"}`}>{logoSaved ? "Logo saved" : "No logo"}</span>
              )}
            </div>
          ))}
        </div>
        <p className="text-sm text-muted-foreground">{LOGO_RULES}</p>
        <div className="flex flex-wrap gap-2">
          <label htmlFor={logoId} className="inline-flex min-h-11 cursor-pointer items-center rounded-sm border border-border px-3 text-sm focus-within:ring-2 focus-within:ring-ring">
            {logoSaved || localLogo ? "Replace logo" : "Add logo"}
            <input
              id={logoId}
              type="file"
              accept="image/png,image/jpeg,image/webp"
              className="sr-only"
              onChange={(event) => {
                const file = event.target.files?.[0];
                event.target.value = "";
                if (!file) return;
                setLocalLogo(URL.createObjectURL(file));
                setBrand({ use_logo: true });
                onLogo(file);
              }}
            />
          </label>
          {(logoSaved || localLogo) && (
            <Button
              type="button"
              variant="ghost"
              className="min-h-11"
              onClick={() => {
                setLocalLogo(null);
                setBrand({ use_logo: false });
                onRemoveLogo();
              }}
            >
              Remove logo
            </Button>
          )}
        </div>
      </div>

      <div className="flex min-h-11 items-center justify-between gap-3">
        <label htmlFor="use-colors" className="text-sm font-medium">
          {COLOURS}
        </label>
        <button
          id="use-colors"
          type="button"
          role="switch"
          aria-checked={video.brand.use_colors}
          onClick={() => setBrand({ use_colors: !video.brand.use_colors })}
          className={`relative h-7 w-12 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background ${video.brand.use_colors ? "bg-primary" : "bg-foreground/25"}`}
        >
          <span className={`absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-background shadow transition-transform duration-150 motion-reduce:transition-none ${video.brand.use_colors ? "translate-x-5" : ""}`} />
        </button>
      </div>
      {(["primary", "secondary"] as const).map((key) => {
        const value = video.brand[key] ?? "";
        const picker = isHex(value) ? value : "#101113";
        const message = fieldMessage(fieldErrors, `brand.${key}`);
        return (
          <label key={key} className="block space-y-1 text-sm">
            <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{key === "primary" ? "Primary" : "Secondary"}</span>
            <span className="flex gap-2">
              <input
                type="color"
                aria-label={`${key} colour`}
                value={picker}
                className="h-11 w-14 cursor-pointer rounded-sm border border-border bg-background p-1"
                onChange={(event) => setBrand({ [key]: event.target.value, use_colors: true })}
              />
              <Input
                value={value}
                placeholder="#112233"
                aria-invalid={Boolean(message)}
                onChange={(event) => setBrand({ [key]: event.target.value, use_colors: true })}
              />
            </span>
            {message && <span className="text-sm text-destructive">{message}</span>}
          </label>
        );
      })}
      <div className="space-y-1">
        <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Caption contrast</p>
        <ContrastBadge background={badgeBackground} foreground={badgeText} />
      </div>

      <div className="space-y-2">
        <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Font</p>
        <div
          role="radiogroup"
          aria-label="Font"
          className="grid gap-2 sm:grid-cols-2"
          onKeyDown={(event) => {
            if (event.key !== "ArrowRight" && event.key !== "ArrowLeft") return;
            event.preventDefault();
            setBrand({ font: video.brand.font === "inter" ? "source_sans" : "inter" });
          }}
        >
          {[
            { id: "inter", label: "Inter", sample: "Salt the water" },
            { id: "source_sans", label: "Source Sans 3", sample: "Salt the water" },
          ].map((option) => {
            const selected = video.brand.font === option.id;
            return (
              <button
                key={option.id}
                type="button"
                role="radio"
                aria-checked={selected}
                onClick={() => setBrand({ font: option.id })}
                className={`min-h-11 rounded-sm border p-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background ${selected ? "border-foreground" : "border-border"}`}
                style={{ fontFamily: option.id === "source_sans" ? '"Source Sans 3", sans-serif' : "inherit" }}
              >
                <span className="block text-xs text-muted-foreground">{option.label}</span>
                <span className="mt-1 block text-base">{option.sample}</span>
              </button>
            );
          })}
        </div>
      </div>

      <label className="block space-y-1 text-sm">
        <span className="flex items-baseline justify-between">
          <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{OUTRO}</span>
          <span className="tabular-nums text-muted-foreground">{video.brand.outro_text.length}/80</span>
        </span>
        <Input
          value={video.brand.outro_text}
          maxLength={80}
          aria-invalid={Boolean(fieldMessage(fieldErrors, "brand.outro_text"))}
          onChange={(event) => setBrand({ outro_text: event.target.value.slice(0, 80) })}
        />
        {fieldMessage(fieldErrors, "brand.outro_text") && <span className="text-sm text-destructive">{fieldMessage(fieldErrors, "brand.outro_text")}</span>}
      </label>

      <div className="space-y-2">
        <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{LOOK}</p>
        <div className="flex flex-wrap gap-3">
          <div className="relative aspect-[9/16] w-[120px] overflow-hidden rounded-sm border border-border p-2" style={{ background, color: text, fontFamily: font }}>
            {video.brand.use_logo && (localLogo || logoSaved) && <span className="absolute top-2 right-2 size-4 rounded-sm border border-current" aria-hidden />}
            <p className={`text-xs text-balance ${video.captions?.style === "bold" ? "font-semibold" : "font-normal"}`}>{STATEMENT}</p>
            <p className="absolute inset-x-2 bottom-2 text-center text-[10px]">{CAPTION}</p>
          </div>
          <div className="flex aspect-[9/16] w-[120px] items-end rounded-sm border border-border p-2" style={{ background, color: text, fontFamily: font }}>
            <p className="w-full text-center text-xs text-balance">{video.brand.outro_text || "Outro"}</p>
          </div>
        </div>
      </div>
    </div>
  );
}
