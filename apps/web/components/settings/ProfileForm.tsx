"use client";

import { FormEvent, useEffect, useState } from "react";
import { LoadingMark } from "@/components/LoadingMark";
import { toast } from "sonner";
import { MultiSelectCards, SelectionHint } from "@/components/onboarding/MultiSelectCards";
import { Button } from "@/components/ui/button";
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
  const [saving, setSaving] = useState(false);
  const [profile, setProfile] = useState<ProfileFormState>({
    purpose: "creator",
    niches: ["football"],
    custom_niche: "",
    description: "",
    audience: "",
    tones: ["professional"],
  });

  useEffect(() => {
    api<{ profile: AccountProfile | null }>("/account/profile")
      .then((res) => {
        if (res.profile) {
          setProfile(profileToForm(res.profile));
        }
      })
      .finally(() => setLoading(false));
  }, []);

  function showLimitError() {
    toast.error(`You can select at most ${MAX_PROFILE_SELECTIONS} options.`);
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();

    if (profile.niches.length === 0 || profile.tones.length === 0) {
      toast.error("Select at least one niche and voice.");
      return;
    }

    if (profile.niches.includes("custom") && profile.custom_niche.trim().length < 3) {
      toast.error("Enter your custom niche.");
      return;
    }

    setSaving(true);
    try {
      const presetNiches = profile.niches.filter((niche) => niche !== "custom");
      await api<{ profile: AccountProfile }>("/account/profile", {
        method: "POST",
        body: JSON.stringify({
          purpose: profile.purpose,
          niche: presetNiches.length > 0 ? joinMultiValue(presetNiches) : null,
          custom_niche: profile.niches.includes("custom") ? profile.custom_niche : null,
          description: profile.description,
          audience: profile.audience,
          tone: joinMultiValue(profile.tones),
        }),
      });
      toast.success("Profile updated");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save profile");
    } finally {
      setSaving(false);
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
          Shared by every platform. Pick up to 3 options per section where shown.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="space-y-6">
          <div className="space-y-2">
            <Label>Using Mackie17 for</Label>
            <Select
              value={profile.purpose}
              onValueChange={(v) => v && setProfile({ ...profile, purpose: v as Purpose })}
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
              onChange={(niches) => setProfile({ ...profile, niches })}
              onLimitReached={showLimitError}
            />
          </div>

          {profile.niches.includes("custom") && (
            <div className="space-y-2">
              <Label htmlFor="custom_niche">Custom niche</Label>
              <Input
                id="custom_niche"
                value={profile.custom_niche}
                onChange={(e) => setProfile({ ...profile, custom_niche: e.target.value })}
              />
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="description">About your account</Label>
            <Textarea
              id="description"
              rows={4}
              value={profile.description}
              onChange={(e) => setProfile({ ...profile, description: e.target.value })}
              required
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="audience">Audience</Label>
            <Textarea
              id="audience"
              rows={3}
              value={profile.audience}
              onChange={(e) => setProfile({ ...profile, audience: e.target.value })}
            />
          </div>

          <div className="space-y-2">
            <Label>Voice</Label>
            <SelectionHint selectedCount={profile.tones.length} />
            <MultiSelectCards
              options={TONES}
              selected={profile.tones}
              onChange={(tones) => setProfile({ ...profile, tones })}
              onLimitReached={showLimitError}
              columns={1}
            />
          </div>

          <Button type="submit" disabled={saving}>
            {saving ? "Saving…" : "Save profile"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
