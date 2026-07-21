const API_BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";

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

  const response = await fetch(`${API_BASE}${path}`, { ...options, headers });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    const message =
      (data as { message?: string }).message ||
      (data as { errors?: { email?: string[] } }).errors?.email?.[0] ||
      "Request failed";
    throw new Error(message);
  }

  return data as T;
}
