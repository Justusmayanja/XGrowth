const API_BASE = 'http://127.0.0.1:8000/api';

export function getToken() {
  return localStorage.getItem('growthapp_token');
}

export function setToken(token) {
  localStorage.setItem('growthapp_token', token);
}

export function clearToken() {
  localStorage.removeItem('growthapp_token');
}

export async function api(path, options = {}) {
  const headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...(options.headers || {}),
  };

  const token = getToken();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers,
  });

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    const message = data.message || data.errors?.email?.[0] || 'Request failed';
    throw new Error(message);
  }

  return data;
}
