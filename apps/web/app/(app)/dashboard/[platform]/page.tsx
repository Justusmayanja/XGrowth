"use client";

import { useParams } from "next/navigation";
import { PlatformDesk } from "@/components/dashboard/PlatformDesk";
import { PLATFORM_ORDER, type Platform } from "@/lib/profile";

export default function PlatformDashboardPage() {
  const params = useParams<{ platform: string }>();
  const platform = PLATFORM_ORDER.find((item) => item === params.platform) as Platform | undefined;

  if (!platform) {
    return <p className="text-muted-foreground">That platform is not part of Mackie17.</p>;
  }

  return <PlatformDesk platform={platform} />;
}
