const BASE = '/api/web'

let accessToken: string | null = null

export function setTokens(token: string | null) {
  accessToken = token
}

export function getToken() {
  return accessToken
}

async function tryRefresh(): Promise<boolean> {
  const res = await fetch(`${BASE}/auth/refresh`, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  })
  if (!res.ok) return false
  const data = await res.json().catch(() => ({}))
  if (!data.token) return false
  setTokens(data.token)
  return true
}

export async function api<T = any>(path: string, options: RequestInit = {}, retry = true): Promise<T> {
  const res = await fetch(`${BASE}${path}`, {
    ...options,
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
      ...(options.headers || {}),
    },
  })

  if (res.status === 401 && retry && (await tryRefresh())) {
    return api<T>(path, options, false)
  }

  const data = await res.json().catch(() => ({}))

  if (!res.ok) {
    const validation = data.errors
      ? Object.values(data.errors).flat().join(' ')
      : ''
    const err: any = new Error(validation || data.message || data.mensaje || `Error ${res.status}`)
    err.status = res.status
    err.data = data
    throw err
  }

  return res.status === 204 ? (undefined as T) : data as T
}

export const authService = {
  login: (usuario: string, password: string, code: string) =>
    fetch(`${BASE}/auth/login`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ usuario, password, code }),
    }).then(async (r) => ({ ok: r.ok, status: r.status, body: await r.json().catch(() => ({})) })),
  refresh: () => tryRefresh(),
  logout: () =>
    fetch(`${BASE}/auth/logout`, { method: 'POST', credentials: 'include' }).catch(() => {}),
  me: () => api('/auth/me'),
}
