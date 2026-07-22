"use client";

import { PlatformSettingsCard } from "@/components/admin/PlatformSettingsCard";

export default function AdminPlatformSettingsPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Platform Settings</h1>
        <p className="text-muted-foreground">
          Control trend fetch times (3× per day by default), growth evaluation cadence, content mix
          targets, safety limits, and AI behavior toggles from one place.
        </p>
      </div>

      <PlatformSettingsCard />
    </div>
  );
}
