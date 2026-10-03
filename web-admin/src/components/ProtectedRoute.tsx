import { Navigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

export default function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) return <div className="center">Cargando…</div>
  if (!user) return <Navigate to="/login" replace />
  return <>{children}</>
}

export function PermissionGuard({ rol, children }: { rol: string | string[]; children: React.ReactNode }) {
  const { user } = useAuth()
  const roles = Array.isArray(rol) ? rol : [rol]
  if (!user || !roles.includes(user.rol)) return null
  return <>{children}</>
}
