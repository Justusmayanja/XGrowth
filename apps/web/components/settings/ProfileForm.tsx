"use client";

import { FormEvent, useEffect, useState } from "react";
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
  GOALS,
  joinMultiValue,
  MAX_PROFILE_SELECTIONS,
  NICHES,
  parseMultiValue,
  TONES,
} from "@/lib/profile";

type ProfileFormState = {
  goals: string[];
  niches: string[];
  custom_niche: string;
  description: string;
  audience: string;
  tones: string[];
  mode: string;
  automation_level: number;
};

function profileToForm(profile: AccountProfile): ProfileFormState {
  const niches = parseMultiValue(profile.niche);

  return {
    goals: parseMultiValue(profile.goal),
    niches: profile.custom_niche ? [...niches, "custom"] : niches,
    custom_niche: profile.custom_niche ?? "",
    description: profile.description ?? "",
    audience: profile.audience ?? "",
    tones: parseMultiValue(profile.tone),
    mode: profile.mode ?? "reactive",
    automation_level: profile.automation_level ?? 1,
  };
}

export function ProfileForm() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [profile, setProfile] = useState<ProfileFormState>({
    goals: ["grow_followers"],
    niches: ["football"],
    custom_niche: "",
    description: "",
    audience: "",
    tones: ["professional"],
    mode: "reactive",
    automation_level: 1,
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

    if (profile.goals.length === 0 || profile.niches.length === 0 || profile.tones.length === 0) {
      toast.error("Select at least one goal, niche, and voice.");
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
          goal: joinMultiValue(profile.goals),
          niche: presetNiches.length > 0 ? joinMultiValue(presetNiches) : null,
          custom_niche: profile.niches.includes("custom") ? profile.custom_niche : null,
          description: profile.description,
          audience: profile.audience,
          tone: joinMultiValue(profile.tones),
          mode: profile.mode,
          automation_level: profile.automation_level,
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
    return <p className="text-muted-foreground">Loading profile…</p>;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Your Xpulse profile</CardTitle>
        <CardDescription>
          Update your goals and voice. Pick up to 3 options per section where shown.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="space-y-6">
          <div className="space-y-2">
            <Label>Goals</Label>
            <SelectionHint selectedCount={profile.goals.length} />
            <MultiSelectCards
              options={GOALS}
              selected={profile.goals}
              onChange={(goals) => setProfile({ ...profile, goals })}
              onLimitReached={showLimitError}
            />
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

          <div className="space-y-2">
            <Label>Posting style</Label>
            <Select value={profile.mode} onValueChange={(v) => v && setProfile({ ...profile, mode: v })}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="reactive">Reactive</SelectItem>
                <SelectItem value="proactive">Proactive</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <Button type="submit" disabled={saving}>
            {saving ? "Saving…" : "Save profile"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
