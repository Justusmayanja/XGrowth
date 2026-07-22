"use client";

import { FormEvent, useState } from "react";
import { toast } from "sonner";
import { SettingsToggle } from "@/components/settings/SettingsToggle";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api } from "@/lib/api";
import type { AccountInfo } from "@/lib/settings";

type Props = {
  account: AccountInfo;
  onUpdated: (account: AccountInfo) => void;
};

export function AccountSettingsCard({ account, onUpdated }: Props) {
  const [name, setName] = useState(account.name);
  const [currentPassword, setCurrentPassword] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [saving, setSaving] = useState(false);
  const [revoking, setRevoking] = useState(false);

  async function saveAccount(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      const body: Record<string, string> = { name };
      if (password) {
        body.current_password = currentPassword;
        body.password = password;
        body.password_confirmation = passwordConfirmation;
      }
      const res = await api<{ account: AccountInfo; message: string }>("/account", {
        method: "PATCH",
        body: JSON.stringify(body),
      });
      onUpdated(res.account);
      setCurrentPassword("");
      setPassword("");
      setPasswordConfirmation("");
      toast.success(res.message);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not update account");
    } finally {
      setSaving(false);
    }
  }

  async function revokeSessions() {
    setRevoking(true);
    try {
      const res = await api<{ message: string }>("/account/revoke-sessions", { method: "POST" });
      toast.success(res.message);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not revoke sessions");
    } finally {
      setRevoking(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Account</CardTitle>
        <CardDescription>Your login details and session security</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={saveAccount} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="name">Display name</Label>
            <Input id="name" value={name} onChange={(e) => setName(e.target.value)} required />
          </div>
          <div className="space-y-2">
            <Label htmlFor="email">Email</Label>
            <Input id="email" value={account.email} disabled />
          </div>
          <div className="rounded-lg border border-border p-4 space-y-3">
            <p className="text-sm font-medium">Change password</p>
            <div className="space-y-2">
              <Label htmlFor="current_password">Current password</Label>
              <Input
                id="current_password"
                type="password"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                autoComplete="current-password"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="password">New password</Label>
              <Input
                id="password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="new-password"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="password_confirmation">Confirm new password</Label>
              <Input
                id="password_confirmation"
                type="password"
                value={passwordConfirmation}
                onChange={(e) => setPasswordConfirmation(e.target.value)}
                autoComplete="new-password"
              />
            </div>
          </div>
          <Button type="submit" disabled={saving}>
            {saving ? "Saving…" : "Save account"}
          </Button>
        </form>
        <div className="mt-6 border-t border-border pt-4">
          <p className="mb-2 text-sm font-medium">Sessions</p>
          <p className="mb-3 text-sm text-muted-foreground">
            Sign out of Xpulse on all other devices. You&apos;ll stay signed in here.
          </p>
          <Button type="button" variant="secondary" onClick={revokeSessions} disabled={revoking}>
            {revoking ? "Signing out…" : "Sign out other devices"}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
