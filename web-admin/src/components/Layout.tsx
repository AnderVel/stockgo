import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

const mainItems = [
  { to: '/', label: 'Resumen', icon: '01' },
  { to: '/productos', label: 'Productos', icon: '02' },
  { to: '/inventario', label: 'Inventario', icon: '03' },
  { to: '/pedidos', label: 'Pedidos', icon: '05' },
  { to: '/clientes', label: 'Clientes', icon: '06' },
  { to: '/proveedores', label: 'Proveedores', icon: '07' },
]

const adminItems = [
  { to: '/usuarios', label: 'Usuarios', icon: '08' },
  { to: '/roles', label: 'Roles', icon: '10' },
  { to: '/auditoria', label: 'Auditoría', icon: '09' },
]

export default function Layout({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth()
  const nav = useNavigate()
  const isAdmin = user?.rol === 'Administrador'
  const initial = user?.username?.slice(0, 1).toUpperCase() || 'S'

  return (
    <div className="layout">
      <aside className="sidebar">
        <div className="brand">
          <span className="brand-mark">S<span /></span>
          <div><h1>StockGo</h1><small>Control de inventario</small></div>
        </div>
        <nav>
          <p className="nav-section">Operación</p>
          {mainItems.map((i) => (
            <NavLink key={i.to} to={i.to} end={i.to === '/'}>
              <span className="nav-icon">{i.icon}</span> {i.label}
            </NavLink>
          ))}
          <p className="nav-section">Administración</p>
          {adminItems.map((i) =>
            isAdmin ? (
              <NavLink key={i.to} to={i.to}>
                <span className="nav-icon">{i.icon}</span> {i.label}
              </NavLink>
            ) : (
              <span key={i.to} className="nav-disabled" title="Solo administrador">
                <span className="nav-icon">{i.icon}</span> {i.label}
              </span>
            ),
          )}
        </nav>
        <div className="sidebar-footer"><span className="secure-label"><i /> Sesión protegida</span><button className="linkbtn" onClick={() => { logout(); nav('/login') }}>Cerrar sesión</button></div>
      </aside>
      <main>
        <header className="topbar">
          <div><p className="topbar-kicker">Panel operativo</p><span className="topbar-title">Gestión diaria</span></div>
          <div className="profile-chip"><span className="avatar">{initial}</span><span><strong>{user?.username}</strong><small>{user?.rol}</small></span><span className="online-dot" title="Activo" /></div>
        </header>
        <section className="content-area">{children}</section>
      </main>
    </div>
  )
}
