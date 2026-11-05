"use client";

import { FormEvent, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { AuthError } from "@/components/auth/AuthScreen";
import { CodeDigits } from "@/components/auth/CodeDigits";
import { BrandLogo } from "@/components/BrandLogo";
import { ThemeToggle } from "@/components/ThemeToggle";
import { SocialName } from "@/components/SocialLogo";
import { api, getToken, setToken } from "@/lib/api";
import { getPostAuthPath, safeNext } from "@/lib/auth";
import { openPage } from "@/lib/nav-recovery";

const EMAIL_TIMEOUT = "The email is taking too long. Try again in a minute.";
const textAction =
  "bg-transparent p-0 text-left text-sm font-medium text-[#6ea8fe] no-underline hover:text-[#b7d4ff] disabled:cursor-not-allowed disabled:opacity-50";

export default function LoginPage() {
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [sending, setSending] = useState(false);
  const [nextPath, setNextPath] = useState<string | null>(null);
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const [boxes, setBoxes] = useState(0);
  const codeRef = useRef("");
  const busy = useRef(false);

  useEffect(() => {
    const next = safeNext(new URLSearchParams(window.location.search).get("next"));
    setNextPath(next);

    if (getToken()) {
      if (next) {
        openPage(next);
        return;
      }
      getPostAuthPath()
        .then((path) => openPage(path))
        .catch(() => {
          // Stale token cleared in api.ts — stay on login
        });
    }
  }, []);

  async function sendCode() {
    setSending(true);
    setError("");
    try {
      await api(
        "/auth/email/send",
        {
          method: "POST",
          body: JSON.stringify({ purpose: "login", email }),
        },
        25000,
        EMAIL_TIMEOUT,
      );
      codeRef.current = "";
      setBoxes((current) => current + 1);
      setSent(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not send the code");
    } finally {
      setSending(false);
    }
  }

  async function onEmail(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    await sendCode();
  }

  async function verify(code: string) {
    const digits = code.replace(/\D/g, "");
    if (digits.length !== 6) {
      setError("Enter all 6 digits.");
      return;
    }
    if (busy.current) return;
    busy.current = true;
    setLoading(true);
    setError("");
    try {
      const data = await api<{ token: string }>(
        "/auth/email/verify",
        {
          method: "POST",
          body: JSON.stringify({
            email,
            code: digits,
          }),
        },
        25000,
        EMAIL_TIMEOUT,
      );
      setToken(data.token);
      openPage(nextPath ?? (await getPostAuthPath()));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Sign-in failed");
      codeRef.current = "";
      setBoxes((current) => current + 1);
    } finally {
      busy.current = false;
      setLoading(false);
    }
  }

  const registerHref = nextPath ? `/register?next=${encodeURIComponent(nextPath)}` : "/register";

  return (
    <div className="landing-canvas auth-screen relative min-h-screen">
      <ThemeToggle className="theme-slot" />
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
            {sent ? (
              <form
                key="code"
                onSubmit={(event) => {
                  event.preventDefault();
                  void verify(codeRef.current);
                }}
                className="flex flex-col gap-4"
              >
                <p className="text-sm text-muted-foreground">
                  We sent a 6-digit code to <strong className="text-foreground">{email}</strong>. It expires in 10 minutes.
                </p>
                <div>
                  <p id="code-label" className="mb-1.5 text-sm font-medium text-foreground">
                    Code
                  </p>
                  <CodeDigits
                    key={boxes}
                    disabled={loading || sending}
                    onChange={(code) => {
                      codeRef.current = code;
                    }}
                    onComplete={(code) => void verify(code)}
                  />
                </div>
                <button type="submit" className="th-cta w-full" disabled={loading || sending}>
                  {loading ? "Signing in…" : "Sign in"}
                </button>
                <div className="flex flex-col items-start gap-2">
                  <button type="button" className={textAction} disabled={loading || sending} onClick={() => void sendCode()}>
                    {sending ? "Sending…" : "Resend code"}
                  </button>
                  <button
                    type="button"
                    className={textAction}
                    disabled={loading || sending}
                    onClick={() => {
                      setSent(false);
                      setError("");
                    }}
                  >
                    Use a different email
                  </button>
                </div>
              </form>
            ) : (
              <form onSubmit={onEmail} className="space-y-4">
                <div>
                  <label htmlFor="email">Email</label>
                  <input
                    id="email"
                    name="email"
                    type="email"
                    autoComplete="email"
                    required
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                  />
                  <p className="mt-1.5 text-xs text-muted-foreground">We email a code. It expires in 10 minutes.</p>
                </div>
                <button type="submit" className="th-cta" disabled={sending}>
                  {sending ? "Sending the code…" : "Login"}
                </button>
              </form>
            )}
            <p className="mt-6 text-sm text-muted-foreground">
              New here?{" "}
              <Link href={registerHref} className={textAction}>
                Create an account
              </Link>
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
