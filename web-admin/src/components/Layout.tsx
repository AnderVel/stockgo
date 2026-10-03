import { Link, NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

const items = [
  { to: '/', label: 'Dashboard' },
  { to: '/productos', label: 'Productos' },
  { to: '/inventario', label: 'Inventario' },
  { to: '/movimientos', label: 'Movimientos' },
  { to: '/pedidos', label: 'Pedidos' },
  { to: '/clientes', label: 'Clientes' },
  { to: '/proveedores', label: 'Proveedores' },
  { to: '/usuarios', label: 'Usuarios', admin: true },
  { to: '/auditoria', label: 'Auditoría', admin: true },
]

export default function Layout({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth()
  const nav = useNavigate()

  return (
    <div className="layout">
      <aside className="sidebar">
        <h1>StockGo</h1>
        <nav>
          {items
            .filter((i) => !i.admin || user?.rol === 'Administrador')
            .map((i) => (
              <NavLink key={i.to} to={i.to} end={i.to === '/'}>
                {i.label}
              </NavLink>
            ))}
        </nav>
        <button className="linkbtn" onClick={() => { logout(); nav('/login') }}>Cerrar sesión</button>
      </aside>
      <main>
        <header className="topbar">
          <span>{user?.username} · {user?.rol} · {user?.bodega_asignada}</span>
        </header>
        <section>{children}</section>
      </main>
    </div>
  )
}
