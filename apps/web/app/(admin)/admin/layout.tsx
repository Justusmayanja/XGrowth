"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { AdminShell } from "@/components/admin/AdminShell";
import { api, getToken } from "@/lib/api";
import { Button } from "@/components/ui/button";

type GateState = "checking" | "allowed" | "denied" | "error";

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const [gate, setGate] = useState<GateState>("checking");
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [slow, setSlow] = useState(false);
  const requestId = useRef(0);

  const runGuard = useCallback(async () => {
    const id = ++requestId.current;
    setGate("checking");
    setErrorMessage(null);
    setSlow(false);

    const slowTimer = window.setTimeout(() => {
      if (requestId.current === id) setSlow(true);
    }, 2000);

    if (!getToken()) {
      window.clearTimeout(slowTimer);
      router.replace("/login");
      return;
    }

    try {
      const res = await api<{ is_admin: boolean }>("/admin/access");
      if (requestId.current !== id) return;

      if (!res.is_admin) {
        setGate("denied");
        router.replace("/dashboard");
        return;
      }

      setGate("allowed");
    } catch (err) {
      if (requestId.current !== id) return;
      setErrorMessage(err instanceof Error ? err.message : "Could not verify admin access");
      setGate("error");
    } finally {
      window.clearTimeout(slowTimer);
    }
  }, [router]);

  useEffect(() => {
    runGuard();
  }, [runGuard]);

  if (gate === "checking") {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-background px-4 text-foreground">
        <p className="text-muted-foreground">Checking admin access…</p>
        {slow && (
          <>
            <p className="max-w-sm text-center text-sm text-muted-foreground">
              This is taking longer than usual. Make sure the backend is running on port 8000.
            </p>
            <Button variant="secondary" size="sm" onClick={runGuard}>
              Retry now
            </Button>
          </>
        )}
      </div>
    );
  }

  if (gate === "denied") {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background text-foreground">
        <p className="text-muted-foreground">Redirecting to dashboard…</p>
      </div>
    );
  }

  if (gate === "error") {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-background px-4 text-foreground">
        <p className="max-w-md text-center text-muted-foreground">
          {errorMessage ?? "Could not verify admin access."}
        </p>
        <div className="flex flex-wrap justify-center gap-2">
          <Button variant="secondary" onClick={runGuard}>
            Retry
          </Button>
          <Button variant="outline" onClick={() => router.replace("/login")}>
            Sign in again
          </Button>
        </div>
      </div>
    );
  }

  return <AdminShell>{children}</AdminShell>;
}
