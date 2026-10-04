import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

const mainItems = [
  { to: '/', label: 'Dashboard', icon: '⌂' },
  { to: '/productos', label: 'Productos', icon: '▦' },
  { to: '/inventario', label: 'Inventario', icon: '▤' },
  { to: '/movimientos', label: 'Movimientos', icon: '⇄' },
  { to: '/pedidos', label: 'Pedidos', icon: '⎈' },
  { to: '/clientes', label: 'Clientes', icon: '◎' },
  { to: '/proveedores', label: 'Proveedores', icon: '▣' },
]

const adminItems = [
  { to: '/usuarios', label: 'Usuarios', icon: '◉' },
  { to: '/auditoria', label: 'Auditoría', icon: '≡' },
]

export default function Layout({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth()
  const nav = useNavigate()
  const isAdmin = user?.rol === 'Administrador'

  return (
    <div className="layout">
      <aside className="sidebar">
        <div className="brand">
          <span className="brand-mark">S</span>
          <h1>StockGo</h1>
        </div>
        <nav>
          <p className="nav-section">General</p>
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
        <button className="linkbtn" onClick={() => { logout(); nav('/login') }}>Cerrar sesión</button>
      </aside>
      <main>
        <header className="topbar">
          <div>
            <strong>{user?.username}</strong>
            <span className="topbar-sub"> · {user?.rol}{user?.bodega_asignada ? ' · ' + user?.bodega_asignada : ''}</span>
          </div>
        </header>
        <section>{children}</section>
      </main>
    </div>
  )
}
