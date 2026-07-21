const REQUEST_TIMEOUT_MS = 8000;

function resolveApiBase(): string {
  if (typeof window !== "undefined") {
    // Same-origin proxy via next.config rewrites — avoids CORS and hanging cross-origin calls
    return "/api";
  }

  return process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";
}

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("xpulse_token") ?? localStorage.getItem("growthapp_token");
}

export function setToken(token: string) {
  localStorage.setItem("xpulse_token", token);
}

export function clearToken() {
  localStorage.removeItem("xpulse_token");
  localStorage.removeItem("growthapp_token");
}

export async function api<T = unknown>(
  path: string,
  options: RequestInit = {}
): Promise<T> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Content-Type": "application/json",
    ...(options.headers as Record<string, string>),
  };

  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

  let response: Response;
  const apiBase = resolveApiBase();

  try {
    response = await fetch(`${apiBase}${path}`, {
      ...options,
      headers,
      signal: controller.signal,
    });
  } catch (err) {
    if (err instanceof DOMException && err.name === "AbortError") {
      throw new Error(
        "Request timed out. Make sure the backend is running: cd backend && php artisan serve"
      );
    }

    throw new Error(
      "Could not reach the API. Start the backend with: cd backend && php artisan serve"
    );
  } finally {
    clearTimeout(timeout);
  }

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    const message =
      (data as { message?: string }).message ||
      (data as { errors?: { email?: string[] } }).errors?.email?.[0] ||
      `Request failed (${response.status})`;

    if (response.status === 403 && path.startsWith("/admin")) {
      throw new Error("Admin access required. Run: php artisan xpulse:make-admin your@email.com");
    }

    throw new Error(message);
  }

  return data as T;
}
