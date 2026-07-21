import { api } from "./api";

export async function getPostAuthPath(): Promise<"/onboarding" | "/dashboard"> {
  const me = await api<{ user: { account_profile?: { onboarding_completed?: boolean } } }>(
    "/auth/me"
  );
  return me.user.account_profile?.onboarding_completed ? "/dashboard" : "/onboarding";
}
