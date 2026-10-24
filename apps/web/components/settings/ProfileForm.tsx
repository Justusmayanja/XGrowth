"use client";

import { useEffect, useRef, useState } from "react";
import { LoadingMark } from "@/components/LoadingMark";
import { toast } from "sonner";
import { MultiSelectCards, SelectionHint } from "@/components/onboarding/MultiSelectCards";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { api } from "@/lib/api";
import {
  AccountProfile,
  joinMultiValue,
  MAX_PROFILE_SELECTIONS,
  NICHES,
  parseMultiValue,
  type Purpose,
  PURPOSES,
  TONES,
} from "@/lib/profile";

type ProfileFormState = {
  purpose: Purpose;
  niches: string[];
  custom_niche: string;
  description: string;
  audience: string;
  tones: string[];
};

function profileToForm(profile: AccountProfile): ProfileFormState {
  const niches = parseMultiValue(profile.niche);

  return {
    purpose: profile.purpose ?? "creator",
    niches: profile.custom_niche ? [...niches, "custom"] : niches,
    custom_niche: profile.custom_niche ?? "",
    description: profile.description ?? "",
    audience: profile.audience ?? "",
    tones: parseMultiValue(profile.tone),
  };
}

export function ProfileForm() {
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState("");
  const [profile, setProfile] = useState<ProfileFormState>({
    purpose: "creator",
    niches: ["football"],
    custom_niche: "",
    description: "",
    audience: "",
    tones: ["professional"],
  });
  const savedSnapshot = useRef<string | null>(null);
  const request = useRef(0);
  const savedTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    api<{ profile: AccountProfile | null }>("/account/profile")
      .then((res) => {
        if (res.profile) {
          setProfile(profileToForm(res.profile));
        }
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    if (loading) return;
    const snapshot = JSON.stringify(profile);
    if (savedSnapshot.current === null || savedSnapshot.current === snapshot) {
      savedSnapshot.current = snapshot;
      return;
    }

    const timer = setTimeout(() => void persist(profile, snapshot), 500);
    return () => clearTimeout(timer);
  }, [profile, loading]);

  function showLimitError() {
    toast.error(`You can select at most ${MAX_PROFILE_SELECTIONS} options.`);
  }

  function update(next: ProfileFormState) {
    setProfile(next);
    setStatus("");
  }

  async function persist(next: ProfileFormState, snapshot: string) {
    if (next.niches.length === 0 || next.tones.length === 0) return;
    if (next.niches.includes("custom") && next.custom_niche.trim().length < 3) return;
    if (next.description.trim() === "") return;

    const seq = ++request.current;
    setStatus("Saving…");
    try {
      const presetNiches = next.niches.filter((niche) => niche !== "custom");
      await api<{ profile: AccountProfile }>("/account/profile", {
        method: "POST",
        body: JSON.stringify({
          purpose: next.purpose,
          niche: presetNiches.length > 0 ? joinMultiValue(presetNiches) : null,
          custom_niche: next.niches.includes("custom") ? next.custom_niche : null,
          description: next.description,
          audience: next.audience,
          tone: joinMultiValue(next.tones),
        }),
      });
      if (seq !== request.current) return;
      savedSnapshot.current = snapshot;
      setStatus("Saved");
      if (savedTimer.current) clearTimeout(savedTimer.current);
      savedTimer.current = setTimeout(() => setStatus(""), 2000);
    } catch (err) {
      if (seq !== request.current) return;
      setStatus("");
      toast.error(err instanceof Error ? err.message : "Could not save profile");
    }
  }

  if (loading) {
    return <LoadingMark />;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Your Mackie17 profile</CardTitle>
        <CardDescription>
          Shared by every platform. A choice applies immediately. Text is saved shortly after you stop typing.
          {status ? <span className="ml-2 text-foreground">{status}</span> : null}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <div className="space-y-6">
          <div className="space-y-2">
            <Label>Using Mackie17 for</Label>
            <Select
              value={profile.purpose}
              onValueChange={(v) => v && update({ ...profile, purpose: v as Purpose })}
            >
              <SelectTrigger>
                <SelectValue placeholder="Choose one" />
              </SelectTrigger>
              <SelectContent>
                {PURPOSES.map((purpose) => (
                  <SelectItem key={purpose.value} value={purpose.value}>
                    {purpose.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">Goals are set per platform in the Platforms card.</p>
          </div>

          <div className="space-y-2">
            <Label>Niches</Label>
            <SelectionHint selectedCount={profile.niches.length} />
            <MultiSelectCards
              options={NICHES}
              selected={profile.niches}
              onChange={(niches) => update({ ...profile, niches })}
              onLimitReached={showLimitError}
            />
          </div>

          {profile.niches.includes("custom") && (
            <div className="space-y-2">
              <Label htmlFor="custom_niche">Custom niche</Label>
              <Input
                id="custom_niche"
                value={profile.custom_niche}
                onChange={(e) => update({ ...profile, custom_niche: e.target.value })}
              />
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="description">About your account</Label>
            <Textarea
              id="description"
              rows={4}
              value={profile.description}
              onChange={(e) => update({ ...profile, description: e.target.value })}
              required
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="audience">Audience</Label>
            <Textarea
              id="audience"
              rows={3}
              value={profile.audience}
              onChange={(e) => update({ ...profile, audience: e.target.value })}
            />
          </div>

          <div className="space-y-2">
            <Label>Voice</Label>
            <SelectionHint selectedCount={profile.tones.length} />
            <MultiSelectCards
              options={TONES}
              selected={profile.tones}
              onChange={(tones) => update({ ...profile, tones })}
              onLimitReached={showLimitError}
              columns={1}
            />
          </div>

        </div>
      </CardContent>
    </Card>
  );
}
