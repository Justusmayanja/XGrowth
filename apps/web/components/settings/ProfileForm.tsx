"use client";

import { FormEvent, useEffect, useState } from "react";
import { toast } from "sonner";
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
import { AccountProfile, GOALS, NICHES, TONES } from "@/lib/profile";

export function ProfileForm() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [profile, setProfile] = useState<AccountProfile>({
    goal: "grow_followers",
    niche: "football",
    custom_niche: "",
    description: "",
    audience: "",
    tone: "professional",
    mode: "reactive",
    automation_level: 1,
  });

  useEffect(() => {
    api<{ profile: AccountProfile | null }>("/account/profile")
      .then((res) => {
        if (res.profile) {
          setProfile({
            ...profile,
            ...res.profile,
            niche: res.profile.custom_niche ? "custom" : res.profile.niche ?? "football",
          });
        }
      })
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      const res = await api<{ profile: AccountProfile }>("/account/profile", {
        method: "POST",
        body: JSON.stringify({
          goal: profile.goal,
          niche: profile.niche === "custom" ? null : profile.niche,
          custom_niche: profile.niche === "custom" ? profile.custom_niche : null,
          description: profile.description,
          audience: profile.audience,
          tone: profile.tone,
          mode: profile.mode,
          automation_level: profile.automation_level,
        }),
      });
      setProfile(res.profile);
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
          Update your goals and voice — Xpulse uses this to tailor trends and content.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label>Goal</Label>
              <Select value={profile.goal} onValueChange={(v) => v && setProfile({ ...profile, goal: v })}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {GOALS.map((g) => (
                    <SelectItem key={g.value} value={g.value}>
                      {g.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Niche</Label>
              <Select
                value={profile.niche ?? "football"}
                onValueChange={(v) => v && setProfile({ ...profile, niche: v })}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {NICHES.map((n) => (
                    <SelectItem key={n.value} value={n.value}>
                      {n.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          {profile.niche === "custom" && (
            <div className="space-y-2">
              <Label htmlFor="custom_niche">Custom niche</Label>
              <Input
                id="custom_niche"
                value={profile.custom_niche ?? ""}
                onChange={(e) => setProfile({ ...profile, custom_niche: e.target.value })}
              />
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="description">About your account</Label>
            <Textarea
              id="description"
              rows={4}
              value={profile.description ?? ""}
              onChange={(e) => setProfile({ ...profile, description: e.target.value })}
              required
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="audience">Audience</Label>
            <Textarea
              id="audience"
              rows={3}
              value={profile.audience ?? ""}
              onChange={(e) => setProfile({ ...profile, audience: e.target.value })}
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label>Voice</Label>
              <Select value={profile.tone} onValueChange={(v) => v && setProfile({ ...profile, tone: v })}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {TONES.map((t) => (
                    <SelectItem key={t.value} value={t.value}>
                      {t.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
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
          </div>

          <Button type="submit" disabled={saving}>
            {saving ? "Saving…" : "Save profile"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
