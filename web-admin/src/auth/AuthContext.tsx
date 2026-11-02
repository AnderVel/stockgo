import { createContext, useContext, useEffect, useState } from 'react'
import { authService, clearSession, hasSession, setTokens } from '../api/client'

interface AuthState {
  user: any | null
  loading: boolean
  login: (u: string, p: string, c: string) => Promise<{ ok: boolean; message?: string; status?: number }>
  logout: () => void
}

const AuthContext = createContext<AuthState>(null as any)

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<any | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (window.location.pathname === '/login') {
      setLoading(false)
      return
    }

    if (!hasSession()) {
      setLoading(false)
      return
    }

    let cancelled = false

    authService
      .refresh()
      .then(async (ok) => {
        if (cancelled) return
        if (ok) {
          try {
            const r: any = await authService.me()
            if (!cancelled) setUser(r.user)
          } catch (error: any) {
            setTokens(null)
            if (error?.status === 401 || error?.status === 403) clearSession()
          }
        }
      })
      .catch(() => setTokens(null))
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [])

  const login = async (usuario: string, password: string, code: string) => {
    const res = await authService.login(usuario, password, code)
    if (!res.ok) return { ok: false, message: res.body?.message, status: res.status }
    setTokens(res.body.token)
    setUser(res.body.user)
    return { ok: true }
  }

  const logout = () => {
    authService.logout()
    setTokens(null)
    setUser(null)
  }

  return <AuthContext.Provider value={{ user, loading, login, logout }}>{children}</AuthContext.Provider>
}

export const useAuth = () => useContext(AuthContext)
