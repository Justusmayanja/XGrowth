"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api";
import { browserTimeZone, saveTimezone } from "@/lib/tiktok";

export function TimezoneCard() {
  const [zone, setZone] = useState("");
  const [busy, setBusy] = useState(false);
  const browser = browserTimeZone();

  useEffect(() => {
    api<{ profile: { timezone?: string | null } | null }>("/account/profile")
      .then((res) => setZone(res.profile?.timezone ?? ""))
      .catch(() => setZone(""));
  }, []);

  async function save(next: string) {
    setBusy(true);
    try {
      const saved = await saveTimezone(next);
      setZone(saved.data.timezone);
      toast.success("Timezone saved");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save the timezone");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Timezone</CardTitle>
        <CardDescription>Suggested clip times use this. If it is empty, times are in UTC.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <label className="block text-sm" htmlFor="account-timezone">
          IANA timezone
          <Input
            id="account-timezone"
            className="mt-1"
            value={zone}
            onChange={(event) => setZone(event.target.value)}
            autoComplete="off"
          />
        </label>
        <div className="flex flex-wrap gap-2">
          <Button type="button" className="min-h-11" disabled={busy || zone.trim() === ""} onClick={() => void save(zone.trim())}>
            Save timezone
          </Button>
          <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={() => void save(browser)}>
            Use {browser}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
