import { lazy, Suspense } from 'react'
import { Routes, Route, Navigate } from 'react-router-dom'
import Layout from './components/Layout'
import ProtectedRoute from './components/ProtectedRoute'
import Login from './pages/Login'

const Dashboard = lazy(() => import('./pages/Dashboard'))
const Productos = lazy(() => import('./pages/Productos'))
const Inventario = lazy(() => import('./pages/Inventario'))
const Movimientos = lazy(() => import('./pages/Movimientos'))
const Pedidos = lazy(() => import('./pages/Pedidos'))
const Clientes = lazy(() => import('./pages/Clientes'))
const Proveedores = lazy(() => import('./pages/Proveedores'))
const Usuarios = lazy(() => import('./pages/Usuarios'))
const Auditoria = lazy(() => import('./pages/Auditoria'))

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route
        path="*"
        element={
          <ProtectedRoute>
            <Layout>
              <Suspense fallback={<p className="center">Cargando módulo…</p>}>
                <Routes>
                  <Route path="/" element={<Dashboard />} />
                  <Route path="/productos" element={<Productos />} />
                  <Route path="/inventario" element={<Inventario />} />
                  <Route path="/movimientos" element={<Movimientos />} />
                  <Route path="/pedidos" element={<Pedidos />} />
                  <Route path="/clientes" element={<Clientes />} />
                  <Route path="/proveedores" element={<Proveedores />} />
                  <Route path="/usuarios" element={<Usuarios />} />
                  <Route path="/auditoria" element={<Auditoria />} />
                  <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
              </Suspense>
            </Layout>
          </ProtectedRoute>
        }
      />
    </Routes>
  )
}
