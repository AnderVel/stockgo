const BASE = '/api/web'

let accessToken: string | null = localStorage.getItem('sg_token')
let refreshToken: string | null = localStorage.getItem('sg_refresh')

export function setTokens(token: string | null, refresh: string | null) {
  accessToken = token
  refreshToken = refresh
  if (token) localStorage.setItem('sg_token', token)
  else localStorage.removeItem('sg_token')
  if (refresh) localStorage.setItem('sg_refresh', refresh)
  else localStorage.removeItem('sg_refresh')
}

export function getToken() {
  return accessToken
}

async function tryRefresh(): Promise<boolean> {
  if (!refreshToken) return false
  const res = await fetch(`${BASE}/auth/refresh`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ refresh_token: refreshToken }),
  })
  if (!res.ok) {
    setTokens(null, null)
    return false
  }
  const data = await res.json()
  setTokens(data.token, data.refresh_token)
  return true
}

export async function api<T = any>(path: string, options: RequestInit = {}, retry = true): Promise<T> {
  const res = await fetch(`${BASE}${path}`, {
    ...options,
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
    const err: any = new Error(data.message || data.mensaje || `Error ${res.status}`)
    err.status = res.status
    err.data = data
    throw err
  }

  return data as T
}

export const authService = {
  login: (usuario: string, password: string, code: string) =>
    fetch(`${BASE}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ usuario, password, code }),
    }).then(async (r) => ({ ok: r.ok, status: r.status, body: await r.json() })),
  logout: () =>
    api('/auth/logout', { method: 'POST', body: JSON.stringify({ refresh_token: refreshToken }) }).catch(() => {}),
  me: () => api('/auth/me'),
}
