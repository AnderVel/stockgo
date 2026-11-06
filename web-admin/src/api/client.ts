
const BASE = (
  import.meta.env.VITE_API_BASE_URL || '/api/web'
).replace(/\/+$/, '')

const SESSION_MARKER = 'stockgo_web_session'

let accessToken: string | null = null
let refreshPromise: Promise<boolean> | null = null
let sessionVersion = 0

export function setTokens(token: string | null) {
  accessToken = token
}

export function getToken() {
  return accessToken
}

function markSession() {
  try {
    window.localStorage.setItem(SESSION_MARKER, '1')
  } catch {
    // La cookie HttpOnly sigue siendo la fuente de verdad para renovar la sesión.
  }
}

export function hasSession() {
  try {
    return window.localStorage.getItem(SESSION_MARKER) === '1'
  } catch {
    return false
  }
}

export function clearSession() {
  accessToken = null
  sessionVersion += 1

  try {
    window.localStorage.removeItem(SESSION_MARKER)
  } catch {
    // La limpieza local no debe impedir el cierre de sesión.
  }
}

async function performRefresh(): Promise<boolean> {
  const requestVersion = sessionVersion
  let res: Response

  try {
    res = await fetch(`${BASE}/auth/refresh`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
    })
  } catch {
    // Un error de red no demuestra que la sesión sea inválida.
    return false
  }

  if (!res.ok) {
    if (res.status === 401 || res.status === 403) {
      clearSession()
    }

    return false
  }

  if (requestVersion !== sessionVersion) {
    return false
  }

  const data = await res.json().catch(() => ({}))

  if (typeof data.token !== 'string' || !data.token) {
    return false
  }

  setTokens(data.token)
  markSession()

  return true
}

function tryRefresh(): Promise<boolean> {
  if (!refreshPromise) {
    refreshPromise = performRefresh().finally(() => {
      refreshPromise = null
    })
  }

  return refreshPromise
}

export async function api<T = any>(
  path: string,
  options: RequestInit = {},
  retry = true,
): Promise<T> {
  const headers = new Headers(options.headers)

  if (!headers.has('Content-Type') && options.body) {
    headers.set('Content-Type', 'application/json')
  }

  headers.set('Accept', 'application/json')

  if (accessToken) {
    headers.set('Authorization', `Bearer ${accessToken}`)
  } else {
    headers.delete('Authorization')
  }

  const res = await fetch(`${BASE}${path}`, {
    ...options,
    credentials: 'include',
    headers,
  })

  if (res.status === 401 && retry) {
    if (await tryRefresh()) {
      return api<T>(path, options, false)
    }

    setTokens(null)
  }

  const data = await res.json().catch(() => ({}))

  if (!res.ok) {
    const validation = data.errors
      ? Object.values(data.errors).flat().join(' ')
      : ''

    const message =
      validation ||
      data.message ||
      data.mensaje ||
      `Error ${res.status}`

    const error: any = new Error(message)
    error.status = res.status
    error.data = data

    throw error
  }

  return res.status === 204 ? (undefined as T) : (data as T)
}

export const authService = {
  login: async (usuario: string, password: string, code: string) => {
    if (refreshPromise) {
      await refreshPromise.catch(() => false)
    }

    let response: Response

    try {
      response = await fetch(`${BASE}/auth/login`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ usuario, password, code }),
      })
    } catch {
      return {
        ok: false,
        status: 0,
        body: {
          message:
            'No fue posible conectar con el servidor. Comprueba que la API esté disponible.',
        },
      }
    }

    const body = await response.json().catch(() => ({}))

    const authenticated =
      response.ok &&
      typeof body.token === 'string' &&
      body.token.length > 0

    if (authenticated) {
      setTokens(body.token)
      markSession()
    } else {
      // No conservar una sesión anterior después de un login fallido.
      clearSession()
    }

    return {
      ok: authenticated,
      status:
        !authenticated &&
        Number.isInteger(body.code) &&
        [401, 403, 422, 429].includes(body.code)
          ? body.code
          : response.status,
      body:
        authenticated || !response.ok
          ? body
          : {
              ...body,
              message:
                body.message ||
                'El servidor no devolvió una sesión válida.',
            },
    }
  },

  refresh: () => tryRefresh(),

  logout: () => {
    const token = getToken()

    return fetch(`${BASE}/auth/logout`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    })
      .catch(() => undefined)
      .finally(clearSession)
  },

  me: () => api('/auth/me'),
}
