import { createContext, useContext, useEffect, useState } from 'react'
import { authService, setTokens, getToken } from '../api/client'

interface AuthState {
  user: any | null
  loading: boolean
  login: (u: string, p: string, c: string) => Promise<{ ok: boolean; message?: string }>
  logout: () => void
}

const AuthContext = createContext<AuthState>(null as any)

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<any | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (getToken()) {
      authService
        .me()
        .then((r: any) => setUser(r.user))
        .catch(() => setTokens(null, null))
        .finally(() => setLoading(false))
    } else setLoading(false)
  }, [])

  const login = async (usuario: string, password: string, code: string) => {
    const res = await authService.login(usuario, password, code)
    if (!res.ok) return { ok: false, message: res.body?.message }
    setTokens(res.body.token, res.body.refresh_token)
    setUser(res.body.user)
    return { ok: true }
  }

  const logout = () => {
    authService.logout()
    setTokens(null, null)
    setUser(null)
  }

  return <AuthContext.Provider value={{ user, loading, login, logout }}>{children}</AuthContext.Provider>
}

export const useAuth = () => useContext(AuthContext)
