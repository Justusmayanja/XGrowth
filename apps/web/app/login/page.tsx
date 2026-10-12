"use client";

import { FormEvent, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { AuthError, AuthLink } from "@/components/auth/AuthScreen";
import { BrandLogo } from "@/components/BrandLogo";
import { SocialLogo, SocialName } from "@/components/SocialLogo";
import { api, getToken, setToken } from "@/lib/api";
import { getPostAuthPath, safeNext } from "@/lib/auth";

export default function LoginPage() {
  const router = useRouter();
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [providers, setProviders] = useState({ google: false, x: false });
  const [nextPath, setNextPath] = useState<string | null>(null);

  useEffect(() => {
    const next = safeNext(new URLSearchParams(window.location.search).get("next"));
    setNextPath(next);

    if (getToken()) {
      if (next) {
        router.replace(next);
        return;
      }
      getPostAuthPath()
        .then((path) => router.replace(path))
        .catch(() => {
          // Stale token cleared in api.ts — stay on login
        });
    }

    api<{ google: boolean; x: boolean }>("/auth/providers")
      .then(setProviders)
      .catch(() => setProviders({ google: false, x: false }));
  }, [router]);

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setLoading(true);
    setError("");
    const form = new FormData(e.currentTarget);
    try {
      const data = await api<{ token: string }>("/auth/login", {
        method: "POST",
        body: JSON.stringify({
          login: form.get("login"),
          password: form.get("password"),
        }),
      });
      setToken(data.token);
      router.push(nextPath ?? (await getPostAuthPath()));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Sign-in failed");
    } finally {
      setLoading(false);
    }
  }

  function startProvider(provider: "google" | "x") {
    if (!providers[provider]) {
      const name = provider === "google" ? "Google" : "X";
      setError(`${name} sign-in is not turned on for this server yet. Use your email or username.`);
      return;
    }
    window.location.href = `/api/auth/${provider}/redirect`;
  }

  const registerHref = nextPath ? `/register?next=${encodeURIComponent(nextPath)}` : "/register";

  return (
    <div className="landing-canvas auth-screen min-h-screen">
      <div className="auth-desk">
        <aside className="auth-aside">
          <BrandLogo className="text-xl" />
          <div>
            <p className="auth-line">Back to the accounts.</p>
            <ul className="auth-notes">
              <li className="auth-note">
                <strong><SocialName brand="youtube">YouTube</SocialName></strong>
                <p>The video, ready to post or waiting on you.</p>
              </li>
              <li className="auth-note">
                <strong><SocialName brand="tiktok">TikTok</SocialName></strong>
                <p>The clip, with a time waiting on your yes.</p>
              </li>
              <li className="auth-note">
                <strong><SocialName brand="x">X</SocialName></strong>
                <p>Goes out when the moment looks strong.</p>
              </li>
            </ul>
          </div>
          <p className="auth-quiet">No card required.</p>
        </aside>
        <div className="auth-form-wrap">
          <div className="auth-form">
            <h1>Sign in</h1>
            <AuthError>{error}</AuthError>
            <form onSubmit={onSubmit} className="space-y-4">
              <div>
                <label htmlFor="login">Email or username</label>
                <input id="login" name="login" autoComplete="username" required />
              </div>
              <div>
                <label htmlFor="password">Password</label>
                <input id="password" name="password" type="password" autoComplete="current-password" required />
              </div>
              <button type="submit" className="th-cta" disabled={loading}>
                {loading ? "Signing in…" : "Sign in"}
              </button>
            </form>
            <p className="auth-split">or</p>
            <div className="auth-socials">
              <button type="button" className="auth-social" onClick={() => startProvider("google")}>
                <SocialLogo brand="google" />
                Google
              </button>
              <button type="button" className="auth-social" onClick={() => startProvider("x")}>
                <SocialLogo brand="x" />
                X
              </button>
            </div>
            <p className="mt-6 text-sm text-[#b7c6d8]">
              New here? <AuthLink href={registerHref}>Create an account</AuthLink>
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
