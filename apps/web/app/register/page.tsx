"use client";

import { FormEvent, useEffect, useState, type ChangeEvent } from "react";
import { useRouter } from "next/navigation";
import { AuthError, AuthLink } from "@/components/auth/AuthScreen";
import { SetupFrame } from "@/components/setup/SetupFrame";
import { SocialLogo } from "@/components/SocialLogo";
import { api, getToken, setToken } from "@/lib/api";
import { getPostAuthPath, safeNext } from "@/lib/auth";

type Setup = "email" | "manual";

export default function RegisterPage() {
  const router = useRouter();
  const [setup, setSetup] = useState<Setup | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [providers, setProviders] = useState({ google: false, x: false });
  const [finish, setFinish] = useState<{ provider: string; pending: string } | null>(null);
  const [nextPath, setNextPath] = useState<string | null>(null);
  const [fields, setFields] = useState({
    username: "",
    name: "",
    email: "",
    password: "",
  });

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const next = safeNext(params.get("next"));
    setNextPath(next);
    const pending = new URLSearchParams(window.location.hash.slice(1)).get("pending");
    const provider = params.get("finish");
    if ((provider === "google" || provider === "x") && pending) {
      setFinish({ provider, pending });
    }

    if (getToken()) {
      if (next) {
        router.replace(next);
        return;
      }
      getPostAuthPath()
        .then((path) => router.replace(path))
        .catch(() => {});
    }

    api<{ google: boolean; x: boolean }>("/auth/providers")
      .then(setProviders)
      .catch(() => setProviders({ google: false, x: false }));
  }, [router]);

  function startProvider(provider: "google" | "x") {
    if (!providers[provider]) {
      const name = provider === "google" ? "Google" : "X";
      setError(`${name} sign-in is not turned on for this server yet. Use email, or write your username and details.`);
      return;
    }
    window.location.href = `/api/auth/${provider}/redirect`;
  }

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!setup) return;
    setLoading(true);
    setError("");
    const form = new FormData(e.currentTarget);
    const password = String(form.get("password") ?? "");
    const confirmation = String(form.get("password_confirmation") ?? "");
    if (password !== confirmation) {
      setError("Those passwords do not match.");
      setLoading(false);
      return;
    }

    try {
      const data = await api<{ token: string }>("/auth/register", {
        method: "POST",
        body: JSON.stringify({
          setup,
          name: form.get("name"),
          email: form.get("email"),
          username: setup === "manual" ? form.get("username") : undefined,
          password,
          password_confirmation: confirmation,
        }),
      });
      setToken(data.token);
      router.push(nextPath ?? "/onboarding");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not create the account");
    } finally {
      setLoading(false);
    }
  }

  async function onFinish(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!finish) return;
    setLoading(true);
    setError("");
    const form = new FormData(e.currentTarget);
    try {
      const data = await api<{ token: string }>("/auth/social/complete", {
        method: "POST",
        body: JSON.stringify({
          pending: finish.pending,
          email: form.get("email"),
          username: form.get("username") || undefined,
        }),
      });
      setToken(data.token);
      router.push(nextPath ?? "/onboarding");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not finish the account");
    } finally {
      setLoading(false);
    }
  }

  const loginHref = nextPath ? `/login?next=${encodeURIComponent(nextPath)}` : "/login";

  function onField(event: ChangeEvent<HTMLInputElement>) {
    const { name, value } = event.target;
    if (name === "password_confirmation") return;
    setFields((current) => ({ ...current, [name]: value }));
  }

  const nextSteps = setup ? (
    <ul>
      {setup === "manual" ? (
        <li>
          <span>Username</span>
          <strong>{fields.username || "—"}</strong>
        </li>
      ) : null}
      <li>
        <span>Name</span>
        <strong>{fields.name || "—"}</strong>
      </li>
      <li>
        <span>Email</span>
        <strong>{fields.email || "—"}</strong>
      </li>
      <li>
        <span>Password</span>
        <strong>{fields.password.length >= 8 ? "Ready" : "8 characters"}</strong>
      </li>
    </ul>
  ) : (
    <ul>
      <li>
        <span>1</span>
        <strong>Account</strong>
      </li>
      <li>
        <span>2</span>
        <strong>A few questions</strong>
      </li>
      <li>
        <span>3</span>
        <strong>Today</strong>
      </li>
    </ul>
  );

  if (finish) {
    const name = finish.provider === "google" ? "Google" : "X";
    return (
      <SetupFrame
        step={2}
        total={2}
        title={`An email for ${name}.`}
        lede={`${name} confirmed who you are. The email is how you get back in.`}
        asideTitle="Then"
        aside={nextSteps}
        footer={
          <>
            Already have an account? <AuthLink href={loginHref}>Sign in</AuthLink>
          </>
        }
      >
        <AuthError>{error}</AuthError>
        <form onSubmit={onFinish} className="space-y-4">
          <div>
            <label htmlFor="email">Email</label>
            <input id="email" name="email" type="email" autoComplete="email" required onChange={onField} />
          </div>
          <div>
            <label htmlFor="username">Username, if you want one</label>
            <input id="username" name="username" autoComplete="username" minLength={3} pattern="[A-Za-z0-9_]+" onChange={onField} />
            <p className="mt-1.5 text-xs text-[#7f93ab]">Letters, numbers, and underscores. You can sign in with it later.</p>
          </div>
          <button type="submit" className="th-cta" disabled={loading}>
            {loading ? "Finishing…" : "Create the account"}
          </button>
        </form>
      </SetupFrame>
    );
  }

  return (
    <SetupFrame
      step={setup ? 2 : 1}
      total={2}
      title={setup === "manual" ? "Write the account." : setup === "email" ? "Your email." : "Create the account."}
      lede={
        setup
          ? "After this, a few questions about the account you want Mackie17 to run."
          : "Email, Google, X, or a username. One of these is enough."
      }
      asideTitle={setup ? "So far" : "Then"}
      aside={nextSteps}
      footer={
        <>
          Already have an account? <AuthLink href={loginHref}>Sign in</AuthLink>
        </>
      }
    >
      <AuthError>{error}</AuthError>
      {setup ? (
        <form onSubmit={onSubmit} className="space-y-4">
          <button
            type="button"
            className="text-sm text-[#9ec0ea] underline underline-offset-4"
            onClick={() => {
              setSetup(null);
              setError("");
            }}
          >
            Choose another way
          </button>
          {setup === "manual" ? (
            <div>
              <label htmlFor="username">Username</label>
              <input id="username" name="username" autoComplete="username" minLength={3} pattern="[A-Za-z0-9_]+" required onChange={onField} />
              <p className="mt-1.5 text-xs text-[#7f93ab]">Letters, numbers, and underscores. You can sign in with this later.</p>
            </div>
          ) : null}
          <div>
            <label htmlFor="name">Name</label>
            <input id="name" name="name" autoComplete="name" required onChange={onField} />
          </div>
          <div>
            <label htmlFor="email">Email</label>
            <input id="email" name="email" type="email" autoComplete="email" required onChange={onField} />
          </div>
          <div>
            <label htmlFor="password">Password</label>
            <input id="password" name="password" type="password" autoComplete="new-password" minLength={8} required onChange={onField} />
            <p className="mt-1.5 text-xs text-[#7f93ab]">At least 8 characters.</p>
          </div>
          <div>
            <label htmlFor="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autoComplete="new-password" minLength={8} required />
          </div>
          <button type="submit" className="th-cta" disabled={loading}>
            {loading ? "Creating account…" : "Continue"}
          </button>
        </form>
      ) : (
        <div className="flex flex-col gap-2">
          <button type="button" className="choice-row" onClick={() => { setSetup("email"); setError(""); }}>
            <span>
              <strong>Email</strong>
              <em>Name, email, and a password.</em>
            </span>
          </button>
          <button type="button" className="choice-row" onClick={() => startProvider("google")}>
            <span>
              <strong><SocialLogo brand="google" />Google</strong>
              <em>{providers.google ? "The Google account you already use." : "Not turned on here. Email still works."}</em>
            </span>
          </button>
          <button type="button" className="choice-row" onClick={() => startProvider("x")}>
            <span>
              <strong><SocialLogo brand="x" />X</strong>
              <em>{providers.x ? "You add an email after X confirms you." : "Not turned on here. Email still works."}</em>
            </span>
          </button>
          <button type="button" className="choice-row" onClick={() => { setSetup("manual"); setError(""); }}>
            <span>
              <strong>Username</strong>
              <em>A username, your name, email, and a password.</em>
            </span>
          </button>
        </div>
      )}
    </SetupFrame>
  );
}
