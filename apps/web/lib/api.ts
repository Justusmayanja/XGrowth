const REQUEST_TIMEOUT_MS = 8000;
export const DRAFT_TIMEOUT_MS = 45000;

function unreachableMessage() {
  return process.env.NODE_ENV === "production"
    ? "Mackie17 could not reach the desk. Try again in a moment."
    : "Could not reach the API. Start the backend with: cd backend && php artisan serve";
}

function timedOutMessage() {
  return process.env.NODE_ENV === "production"
    ? "That took too long. Try again."
    : "Request timed out. Make sure the backend is running: cd backend && php artisan serve";
}

export class ApiError extends Error {
  readonly status: number;
  readonly code: string | null;
  readonly fields: Record<string, string>;

  constructor(message: string, status: number, code: string | null = null, fields: Record<string, string> = {}) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.code = code;
    this.fields = fields;
  }
}

export function readApiError(response: Response, data: unknown): ApiError {
  const body = (data ?? {}) as {
    message?: string;
    error?: { code?: string; message?: string; fields?: Record<string, string[] | string> };
    errors?: Record<string, string[] | string>;
  };
  const fields: Record<string, string> = {};
  const sources = [body.errors, body.error?.fields];
  for (const source of sources) {
    if (!source || typeof source !== "object") continue;
    for (const [key, messages] of Object.entries(source)) {
      const first = Array.isArray(messages) ? messages.find(Boolean) : messages;
      if (first) fields[key] = String(first);
    }
  }
  const message =
    body.error?.message ||
    Object.values(fields)[0] ||
    body.message ||
    `Request failed (${response.status})`;

  return new ApiError(message, response.status, body.error?.code ?? null, fields);
}

function resolveApiBase(): string {
  if (typeof window !== "undefined") {
    // Same-origin proxy via next.config rewrites — avoids CORS and hanging cross-origin calls
    return "/api";
  }

  return process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";
}

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return (
    localStorage.getItem("mackie17_token")
    ?? localStorage.getItem("xpulse_token")
    ?? localStorage.getItem("growthapp_token")
  );
}

export function setToken(token: string) {
  localStorage.setItem("mackie17_token", token);
  localStorage.removeItem("xpulse_token");
  localStorage.removeItem("growthapp_token");
}

export function clearToken() {
  localStorage.removeItem("mackie17_token");
  localStorage.removeItem("xpulse_token");
  localStorage.removeItem("growthapp_token");
}

export async function api<T = unknown>(
  path: string,
  options: RequestInit = {},
  timeoutMs = REQUEST_TIMEOUT_MS,
  timeoutMessage = timedOutMessage(),
): Promise<T> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Content-Type": "application/json",
    ...(options.headers as Record<string, string>),
  };

  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

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
      throw new Error(timeoutMessage);
    }

    throw new Error(unreachableMessage());
  } finally {
    clearTimeout(timeout);
  }

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    if (response.status === 401 && token) {
      clearToken();
    }

    if (response.status === 403 && path.startsWith("/admin")) {
      throw new ApiError(
        "This page is for the platform console.",
        403,
      );
    }

    throw readApiError(response, data);
  }

  return data as T;
}

export async function apiForm<T = unknown>(
  path: string,
  body: FormData,
  timeoutMs = 60000,
  timeoutMessage = "The upload timed out. Try a shorter video.",
): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);
  const apiBase = resolveApiBase();

  let response: Response;
  try {
    response = await fetch(`${apiBase}${path}`, { method: "POST", headers, body, signal: controller.signal });
  } catch (err) {
    if (err instanceof DOMException && err.name === "AbortError") {
      throw new Error(timeoutMessage);
    }
    throw new Error(unreachableMessage());
  } finally {
    clearTimeout(timeout);
  }

  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw readApiError(response, data);

  return data as T;
}
