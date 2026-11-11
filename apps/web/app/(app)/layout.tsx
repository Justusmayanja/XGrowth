"use client";

import { useEffect, useState } from "react";
import { usePathname } from "next/navigation";
import { AppShell } from "@/components/shell/AppShell";
import { LoadingMark } from "@/components/LoadingMark";
import { api, getToken } from "@/lib/api";
import { openPage } from "@/lib/nav-recovery";

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  const devPreview = process.env.NODE_ENV !== "production" && pathname.startsWith("/dev/");
  const [ready, setReady] = useState(devPreview);

  useEffect(() => {
    if (devPreview) return;
    if (!getToken()) {
      openPage("/login");
      return;
    }

    api<{ user: { is_admin?: boolean } }>("/auth/me")
      .then((res) => {
        if (res.user.is_admin) {
          openPage("/admin");
          return;
        }
        setReady(true);
      })
      .catch(() => openPage("/login"));
  }, [devPreview]);

  if (!ready) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <LoadingMark />
      </div>
    );
  }

  return <AppShell>{children}</AppShell>;
}
