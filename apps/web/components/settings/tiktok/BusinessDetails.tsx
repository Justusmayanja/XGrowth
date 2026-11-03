"use client";

import { Input } from "@/components/ui/input";
import { fieldMessage, type VideoSettings } from "./types";

const ONLY = "Only what you type here appears in the video.";
const LOCATION = "Location";
const CONTACT = "Contact details";
const NAME = "Business name";
const WEBSITE = "Website";
const PHONE = "Phone";

function SwitchRow({
  id,
  label,
  checked,
  onChange,
}: {
  id: string;
  label: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
}) {
  return (
    <div className="flex min-h-11 items-center justify-between gap-3">
      <label htmlFor={id} className="text-sm font-medium">
        {label}
      </label>
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        onClick={() => onChange(!checked)}
        className={`relative h-7 w-12 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background ${checked ? "bg-primary" : "bg-foreground/25"}`}
      >
        <span className={`absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-background shadow transition-transform duration-150 motion-reduce:transition-none ${checked ? "translate-x-5" : ""}`} />
      </button>
    </div>
  );
}

export function BusinessDetails({
  video,
  fieldErrors,
  onChange,
}: {
  video: VideoSettings;
  fieldErrors: Record<string, string>;
  onChange: (video: VideoSettings) => void;
}) {
  function setContext(patch: Partial<VideoSettings["context"]>) {
    onChange({ ...video, context: { ...video.context, ...patch } });
  }

  const locationError = fieldMessage(fieldErrors, "context.location_text");
  const nameError = fieldMessage(fieldErrors, "context.business_name");
  const siteError = fieldMessage(fieldErrors, "context.website");
  const phoneError = fieldMessage(fieldErrors, "context.phone");

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">{ONLY}</p>
      <SwitchRow id="mention-location" label={LOCATION} checked={video.context.mention_location} onChange={(checked) => setContext({ mention_location: checked })} />
      {video.context.mention_location && (
        <label className="block space-y-1 text-sm">
          <span className="text-muted-foreground">Shown as the place in the video</span>
          <Input value={video.context.location_text} maxLength={60} aria-invalid={Boolean(locationError)} onChange={(event) => setContext({ location_text: event.target.value })} />
          {locationError && <span className="text-destructive">{locationError}</span>}
        </label>
      )}
      <SwitchRow id="show-contact" label={CONTACT} checked={video.context.show_contact} onChange={(checked) => setContext({ show_contact: checked })} />
      {video.context.show_contact && (
        <div className="space-y-3">
          <label className="block space-y-1 text-sm">
            <span>{NAME}</span>
            <Input value={video.context.business_name} aria-invalid={Boolean(nameError)} onChange={(event) => setContext({ business_name: event.target.value })} />
            {nameError && <span className="text-destructive">{nameError}</span>}
          </label>
          <label className="block space-y-1 text-sm">
            <span>{WEBSITE}</span>
            <Input value={video.context.website} aria-invalid={Boolean(siteError)} onChange={(event) => setContext({ website: event.target.value })} />
            {siteError && <span className="text-destructive">{siteError}</span>}
          </label>
          <label className="block space-y-1 text-sm">
            <span>{PHONE}</span>
            <Input value={video.context.phone} aria-invalid={Boolean(phoneError)} onChange={(event) => setContext({ phone: event.target.value })} />
            {phoneError && <span className="text-destructive">{phoneError}</span>}
          </label>
        </div>
      )}
    </div>
  );
}
