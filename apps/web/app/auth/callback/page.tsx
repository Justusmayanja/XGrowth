"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { AuthError, AuthLink, AuthScreen } from "@/components/auth/AuthScreen";
import { setToken } from "@/lib/api";
import { getPostAuthPath } from "@/lib/auth";

export default function AuthCallbackPage() {
  const router = useRouter();
  const [error, setError] = useState("");

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const token = new URLSearchParams(window.location.hash.slice(1)).get("token");
    const message = params.get("error");

    if (message) {
      setError(message);
      return;
    }

    if (!token) {
      setError("The sign-in did not finish. Start again from the account page.");
      return;
    }

    setToken(token);
    window.history.replaceState(null, "", "/auth/callback");
    getPostAuthPath()
      .then((path) => router.replace(path))
      .catch(() => setError("The account was confirmed, but the next page did not open. Sign in with email."));
  }, [router]);

  return (
    <AuthScreen
      kicker="Sign in"
      title={error ? "That did not finish." : "Signing you in."}
      lede={error ? "Nothing was changed on the account." : "This takes a moment."}
      footer={
        <>
          <AuthLink href="/login">Back to sign in</AuthLink>
        </>
      }
    >
      <AuthError>{error}</AuthError>
    </AuthScreen>
  );
}
