const REQUEST_TIMEOUT_MS = 8000;

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
    error?: { code?: string; message?: string };
    errors?: Record<string, string[] | string>;
  };
  const fields: Record<string, string> = {};
  if (body.errors && typeof body.errors === "object") {
    for (const [key, messages] of Object.entries(body.errors)) {
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
  options: RequestInit = {},
  timeoutMs = REQUEST_TIMEOUT_MS,
  timeoutMessage = "Request timed out. Make sure the backend is running: cd backend && php artisan serve",
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

    throw new Error(
      "Could not reach the API. Start the backend with: cd backend && php artisan serve"
    );
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
        "Admin access required. Run: php artisan xpulse:make-admin your@email.com",
        403,
      );
    }

    throw readApiError(response, data);
  }

  return data as T;
}

export async function apiForm<T = unknown>(path: string, body: FormData, timeoutMs = 60000): Promise<T> {
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
      throw new Error("The upload timed out. Try a shorter video.");
    }
    throw new Error("Could not reach the API. Start the backend with: cd backend && php artisan serve");
  } finally {
    clearTimeout(timeout);
  }

  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const errors = (data as { errors?: Record<string, string[]> }).errors;
    const firstFieldError = errors ? Object.values(errors).flat().find(Boolean) : undefined;
    throw new Error(firstFieldError || (data as { message?: string }).message || `Request failed (${response.status})`);
  }

  return data as T;
}
