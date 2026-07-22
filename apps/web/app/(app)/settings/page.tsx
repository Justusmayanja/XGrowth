"use client";

import { useEffect, useState } from "react";
import { AccountSettingsCard } from "@/components/settings/AccountSettingsCard";
import { AutomationSettingsCard } from "@/components/settings/AutomationSettingsCard";
import { BillingSettingsCard } from "@/components/settings/BillingSettingsCard";
import { ConnectedAccountCard } from "@/components/settings/ConnectedAccountCard";
import { NotificationSettingsCard } from "@/components/settings/NotificationSettingsCard";
import { PrivacySettingsCard } from "@/components/settings/PrivacySettingsCard";
import { ProfileForm } from "@/components/settings/ProfileForm";
import { SafetySummaryCard } from "@/components/settings/SafetySummaryCard";
import { api } from "@/lib/api";
import type {
  AccountInfo,
  AutomationSettings,
  NotificationSettings,
  SafetyStatus,
  XConnectStatus,
} from "@/lib/settings";
import type { SubscriptionPlan } from "@/lib/plans";

export default function SettingsPage() {
  const [account, setAccount] = useState<AccountInfo | null>(null);
  const [automation, setAutomation] = useState<AutomationSettings | null>(null);
  const [notifications, setNotifications] = useState<NotificationSettings | null>(null);
  const [xStatus, setXStatus] = useState<XConnectStatus | null>(null);
  const [safety, setSafety] = useState<SafetyStatus | null>(null);
  const [plans, setPlans] = useState<SubscriptionPlan[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      api<{
        account: AccountInfo;
        automation: AutomationSettings;
        notifications: NotificationSettings;
      }>("/account/settings"),
      api<XConnectStatus>("/connected-accounts/x/status"),
      api<SafetyStatus>("/safety/status"),
      api<{ plans: SubscriptionPlan[] }>("/plans"),
    ])
      .then(([settings, x, s, planRes]) => {
        setAccount(settings.account);
        setAutomation(settings.automation);
        setNotifications(settings.notifications);
        setXStatus(x);
        setSafety(s);
        setPlans(planRes.plans);
      })
      .finally(() => setLoading(false));
  }, []);

  if (loading || !account) {
    return <p className="text-muted-foreground">Loading settings…</p>;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Settings</h1>
        <p className="text-muted-foreground">
          Manage your account, automation, connections, and preferences
        </p>
      </div>

      <AccountSettingsCard account={account} onUpdated={setAccount} />

      <AutomationSettingsCard initial={automation} />

      <ProfileForm />

      <ConnectedAccountCard status={xStatus} />

      <NotificationSettingsCard initial={notifications} />

      <SafetySummaryCard safety={safety} loading={loading} />

      <BillingSettingsCard account={account} plans={plans} />

      <PrivacySettingsCard />
    </div>
  );
}
