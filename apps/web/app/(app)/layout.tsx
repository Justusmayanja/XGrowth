"use client";

import { useEffect, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { AppShell } from "@/components/AppShell";
import { LoadingMark } from "@/components/LoadingMark";
import { api, getToken } from "@/lib/api";

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const devPreview = process.env.NODE_ENV !== "production" && pathname.startsWith("/dev/");
  const [ready, setReady] = useState(devPreview);

  useEffect(() => {
    if (devPreview) return;
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
  }, [devPreview, router]);

  if (!ready) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <LoadingMark />
      </div>
    );
  }

  return <AppShell>{children}</AppShell>;
}
