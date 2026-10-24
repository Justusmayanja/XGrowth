"use client";

import { useState } from "react";
import { toast } from "sonner";
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
  const [status, setStatus] = useState("");
  const [revoking, setRevoking] = useState(false);

  async function saveAccount() {
    const next = name.trim();
    if (next === "" || next === account.name) return;
    setStatus("Saving…");
    try {
      const res = await api<{ account: AccountInfo }>("/account", {
        method: "PATCH",
        body: JSON.stringify({ name: next }),
      });
      setName(res.account.name);
      onUpdated(res.account);
      setStatus("Saved");
    } catch (err) {
      setName(account.name);
      setStatus("");
      toast.error(err instanceof Error ? err.message : "Could not update account");
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
        <CardDescription>
          Your name and email. The name is saved when you leave the field.
          {status ? <span className="ml-2 text-foreground">{status}</span> : null}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="name">Display name</Label>
            <Input
              id="name"
              value={name}
              onChange={(e) => {
                setName(e.target.value);
                setStatus("");
              }}
              onBlur={() => void saveAccount()}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.currentTarget.blur();
                }
              }}
              required
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="email">Email</Label>
            <Input id="email" value={account.email} disabled />
          </div>
        </div>
        <div className="mt-6 border-t border-border pt-4">
          <p className="mb-2 text-sm font-medium">Sessions</p>
          <p className="mb-3 text-sm text-muted-foreground">
            Sign out of Mackie17 on all other devices. You&apos;ll stay signed in here.
          </p>
          <Button type="button" variant="secondary" onClick={revokeSessions} disabled={revoking}>
            {revoking ? "Signing out…" : "Sign out other devices"}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
