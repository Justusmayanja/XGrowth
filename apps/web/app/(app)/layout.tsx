"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { AppShell } from "@/components/AppShell";
import { api, getToken } from "@/lib/api";

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const router = useRouter();
  const [ready, setReady] = useState(false);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }

    api<{ user: { is_admin?: boolean } }>("/auth/me")
      .then((res) => {
        if (res.user.is_admin) {
          router.replace("/admin");
          return;
        }
        setReady(true);
      })
      .catch(() => router.replace("/login"));
  }, [router]);

  if (!ready) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return <AppShell>{children}</AppShell>;
}
