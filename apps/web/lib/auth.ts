import { api } from "./api";

export type HomePath = "/admin" | "/onboarding" | "/dashboard";

export function safeNext(value: string | null): string | null {
  if (!value) return null;
  if (!value.startsWith("/") || value.startsWith("//") || value.includes("\\") || value.includes("://")) {
    return null;
  }

  return value;
}

export async function getPostAuthPath(): Promise<HomePath> {
  const me = await api<{
    user: {
      is_admin?: boolean;
      account_profile?: { onboarding_completed?: boolean };
    };
  }>("/auth/me");

  if (me.user.is_admin) {
    return "/admin";
  }

  return me.user.account_profile?.onboarding_completed ? "/dashboard" : "/onboarding";
}

export async function isPlatformAdmin(): Promise<boolean> {
  const me = await api<{ user: { is_admin?: boolean } }>("/auth/me");
  return Boolean(me.user.is_admin);
}
