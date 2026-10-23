"use client";

import { useEffect, useState } from "react";
import { Settings } from "lucide-react";
import { DeskHeading } from "@/components/DeskHeading";
import { AccountSettingsCard } from "@/components/settings/AccountSettingsCard";
import { AutomationSettingsCard } from "@/components/settings/AutomationSettingsCard";
import { BillingSettingsCard } from "@/components/settings/BillingSettingsCard";
import { ConnectedAccountCard } from "@/components/settings/ConnectedAccountCard";
import { NotificationSettingsCard } from "@/components/settings/NotificationSettingsCard";
import { PrivacySettingsCard } from "@/components/settings/PrivacySettingsCard";
import { ProfileForm } from "@/components/settings/ProfileForm";
import { SafetySummaryCard } from "@/components/settings/SafetySummaryCard";
import { PlatformSetupCard } from "@/components/setup/PlatformSetupCard";
import { toast } from "sonner";
import { api } from "@/lib/api";
import type {
  AccountInfo,
  AutomationSettings,
  NotificationSettings,
  SafetyStatus,
  XConnectStatus,
} from "@/lib/settings";

export default function SettingsPage() {
  const [account, setAccount] = useState<AccountInfo | null>(null);
  const [automation, setAutomation] = useState<AutomationSettings | null>(null);
  const [notifications, setNotifications] = useState<NotificationSettings | null>(null);
  const [xStatus, setXStatus] = useState<XConnectStatus | null>(null);
  const [safety, setSafety] = useState<SafetyStatus | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      api<{
        account: AccountInfo;
        automation: AutomationSettings;
        notifications: NotificationSettings;
      }>("/account/settings"),
      api<XConnectStatus>("/connected-accounts/x/status?next=/settings"),
      api<SafetyStatus>("/safety/status"),
    ])
      .then(([settings, x, s]) => {
        setAccount(settings.account);
        setAutomation(settings.automation);
        setNotifications(settings.notifications);
        setXStatus(x);
        setSafety(s);
      })
      .finally(() => setLoading(false));

    const flag = new URLSearchParams(window.location.search).get("x");
    if (flag === "connected") toast.success("X is connected.");
    if (flag === "error") toast.error("X could not be connected. Try again.");
    if (flag) {
      const url = new URL(window.location.href);
      url.searchParams.delete("x");
      window.history.replaceState({}, "", `${url.pathname}${url.search}`);
    }
  }, []);

  if (loading || !account) {
    return <p className="text-muted-foreground">Loading settings…</p>;
  }

  return (
    <div className="space-y-6">
      <DeskHeading icon={Settings} title="Settings">
        <p className="text-muted-foreground">
          Account, posting rules, and X, TikTok, and YouTube
        </p>
      </DeskHeading>

      <AccountSettingsCard account={account} onUpdated={setAccount} />

      <AutomationSettingsCard initial={automation} />

      <PlatformSetupCard />

      <ProfileForm />

      <ConnectedAccountCard
        status={xStatus}
        onDisconnected={() => {
          api<XConnectStatus>("/connected-accounts/x/status?next=/settings").then(setXStatus);
        }}
      />

      <NotificationSettingsCard initial={notifications} />

      <SafetySummaryCard safety={safety} loading={loading} />

      <BillingSettingsCard />

      <PrivacySettingsCard />
    </div>
  );
}
