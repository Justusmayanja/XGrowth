"use client";

import { FormEvent, useEffect, useState, type ChangeEvent } from "react";
import { useRouter } from "next/navigation";
import { AuthError, AuthLink } from "@/components/auth/AuthScreen";
import { SetupFrame } from "@/components/setup/SetupFrame";
import { api, getToken, setToken } from "@/lib/api";
import { getPostAuthPath, safeNext } from "@/lib/auth";

const EMAIL_TIMEOUT = "The email is taking too long. Try again in a minute.";

export default function RegisterPage() {
  const router = useRouter();
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [nextPath, setNextPath] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const [fields, setFields] = useState({
    username: "",
    name: "",
    email: "",
  });

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
        .catch(() => {});
    }
  }, [router]);

  async function sendCode() {
    setLoading(true);
    setError("");
    try {
      await api(
        "/auth/email/send",
        {
          method: "POST",
          body: JSON.stringify({
            purpose: "register",
            name: fields.name,
            email: fields.email,
            username: fields.username,
          }),
        },
        25000,
        EMAIL_TIMEOUT,
      );
      setSent(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not send the code");
    } finally {
      setLoading(false);
    }
  }

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    await sendCode();
  }

  async function onCode(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setLoading(true);
    setError("");
    const form = new FormData(e.currentTarget);
    try {
      const data = await api<{ token: string }>(
        "/auth/email/verify",
        {
          method: "POST",
          body: JSON.stringify({
            email: fields.email,
            code: String(form.get("code") ?? "").trim(),
          }),
        },
        25000,
        EMAIL_TIMEOUT,
      );
      setToken(data.token);
      router.push(nextPath ?? "/onboarding");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not create the account");
    } finally {
      setLoading(false);
    }
  }

  const loginHref = nextPath ? `/login?next=${encodeURIComponent(nextPath)}` : "/login";

  function onField(event: ChangeEvent<HTMLInputElement>) {
    const { name, value } = event.target;
    setFields((current) => ({ ...current, [name]: value }));
  }

  const nextSteps = (
    <ul>
      <li>
        <span>Username</span>
        <strong>{fields.username || "—"}</strong>
      </li>
      <li>
        <span>Name</span>
        <strong>{fields.name || "—"}</strong>
      </li>
      <li>
        <span>Email</span>
        <strong>{fields.email || "—"}</strong>
      </li>
      <li>
        <span>Code</span>
        <strong>{sent ? "Sent" : "Waiting"}</strong>
      </li>
    </ul>
  );

  return (
    <SetupFrame
      step={sent ? 2 : 1}
      total={2}
      title={sent ? "Enter the code." : "Create the account."}
      lede={
        sent
          ? `We sent a 6-digit code to ${fields.email}. It expires in 10 minutes.`
          : "A username, your name, and your email. We send a code that expires in 10 minutes."
      }
      asideTitle="So far"
      aside={nextSteps}
      footer={
        <>
          Already have an account? <AuthLink href={loginHref}>Sign in</AuthLink>
        </>
      }
    >
      <AuthError>{error}</AuthError>
      {sent ? (
        <form onSubmit={onCode} className="space-y-4">
          <button
            type="button"
            className="text-sm text-[#9ec0ea] underline underline-offset-4"
            onClick={() => {
              setSent(false);
              setError("");
            }}
          >
            Change these details
          </button>
          <div>
            <label htmlFor="code">Code</label>
            <input
              id="code"
              name="code"
              className="otp"
              inputMode="numeric"
              autoComplete="one-time-code"
              pattern="[0-9]{6}"
              minLength={6}
              maxLength={6}
              required
              autoFocus
            />
          </div>
          <button type="submit" className="th-cta" disabled={loading}>
            {loading ? "Creating account…" : "Create the account"}
          </button>
          <button
            type="button"
            className="text-sm text-[#9ec0ea] underline underline-offset-4"
            disabled={loading}
            onClick={() => void sendCode()}
          >
            Send a new code
          </button>
        </form>
      ) : (
        <form onSubmit={onSubmit} className="space-y-4">
          <div>
            <label htmlFor="username">Username</label>
            <input
              id="username"
              name="username"
              autoComplete="username"
              minLength={3}
              pattern="[A-Za-z0-9_]+"
              required
              value={fields.username}
              onChange={onField}
            />
            <p className="mt-1.5 text-xs text-[#7f93ab]">Letters, numbers, and underscores.</p>
          </div>
          <div>
            <label htmlFor="name">Name</label>
            <input id="name" name="name" autoComplete="name" required value={fields.name} onChange={onField} />
          </div>
          <div>
            <label htmlFor="email">Email</label>
            <input
              id="email"
              name="email"
              type="email"
              autoComplete="email"
              required
              value={fields.email}
              onChange={onField}
            />
          </div>
          <button type="submit" className="th-cta" disabled={loading}>
            {loading ? "Sending code…" : "Email me a code"}
          </button>
        </form>
      )}
    </SetupFrame>
  );
}
