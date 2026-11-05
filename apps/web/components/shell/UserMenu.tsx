"use client";

import { useEffect, useState } from "react";
import { openPage } from "@/lib/nav-recovery";
import { Menu } from "@base-ui/react/menu";
import { useTheme } from "next-themes";
import { api, clearToken } from "@/lib/api";
import { planSummary, useBillingStatus } from "@/components/shell/BillingState";

type Me = { name?: string | null; email?: string | null };

function initialOf(user: Me | null): string {
  const name = user?.name?.trim();
  const email = user?.email?.trim();
  const source = name || email || "";
  return source ? source.slice(0, 1).toUpperCase() : "?";
}

export function UserMenu() {
  const billing = useBillingStatus();
  const { resolvedTheme, setTheme } = useTheme();
  const [user, setUser] = useState<Me | null>(null);
  const [pending, setPending] = useState(false);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    setReady(true);
    api<{ user: Me }>("/auth/me")
      .then((res) => setUser(res.user))
      .catch(() => setUser(null));
  }, []);

  const dark = ready && resolvedTheme !== "light";
  const name = user?.name?.trim() || "";
  const email = user?.email?.trim() || "";

  async function logout() {
    if (pending) return;
    setPending(true);
    try {
      await api("/auth/logout", { method: "POST" });
    } catch {
      // The session ends locally even when the server is unreachable.
    } finally {
      clearToken();
      openPage("/");
    }
  }

  return (
    <Menu.Root>
      <Menu.Trigger
        className="shell-hit inline-flex size-9 items-center justify-center rounded-full border border-border bg-surface text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        aria-label="Account menu"
      >
        {initialOf(user)}
      </Menu.Trigger>
      <Menu.Portal>
        <Menu.Positioner className="shell-dialog" side="bottom" align="end" sideOffset={8}>
          <Menu.Popup className="w-64 rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-md outline-none">
            <div className="px-2 py-2">
              {name ? <p className="truncate text-sm font-medium">{name}</p> : null}
              {email ? <p className="truncate text-xs text-muted-foreground">{email}</p> : null}
              <p className="mt-1 text-xs text-muted-foreground">{planSummary(billing?.subscription)}</p>
            </div>
            <Menu.LinkItem href="/settings" closeOnClick className="block rounded-sm px-2 py-2 text-sm outline-none data-[highlighted]:bg-muted">
              Settings
            </Menu.LinkItem>
            <Menu.LinkItem href="/pricing" closeOnClick className="block rounded-sm px-2 py-2 text-sm outline-none data-[highlighted]:bg-muted">
              Billing
            </Menu.LinkItem>
            <div className="my-1 h-px bg-border" />
            <p className="px-2 py-1 text-[11px] uppercase tracking-wide text-muted-foreground">Theme</p>
            <Menu.Item
              className="flex cursor-pointer items-center justify-between rounded-sm px-2 py-2 text-sm outline-none data-[highlighted]:bg-muted"
              onClick={() => setTheme("light")}
            >
              Light
              {!dark && ready ? <span aria-hidden>✓</span> : null}
            </Menu.Item>
            <Menu.Item
              className="flex cursor-pointer items-center justify-between rounded-sm px-2 py-2 text-sm outline-none data-[highlighted]:bg-muted"
              onClick={() => setTheme("dark")}
            >
              Dark
              {dark ? <span aria-hidden>✓</span> : null}
            </Menu.Item>
            <div className="my-1 h-px bg-border" />
            <Menu.Item
              disabled={pending}
              className="flex cursor-pointer rounded-sm px-2 py-2 text-sm outline-none data-[highlighted]:bg-muted data-[disabled]:opacity-50"
              onClick={() => void logout()}
            >
              {pending ? "Signing out…" : "Sign out"}
            </Menu.Item>
          </Menu.Popup>
        </Menu.Positioner>
      </Menu.Portal>
    </Menu.Root>
  );
}
