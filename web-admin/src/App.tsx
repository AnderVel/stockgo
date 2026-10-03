import { Routes, Route, Navigate } from 'react-router-dom'
import Layout from './components/Layout'
import ProtectedRoute from './components/ProtectedRoute'
import Login from './pages/Login'
import Dashboard from './pages/Dashboard'
import Productos from './pages/Productos'
import Inventario from './pages/Inventario'
import Movimientos from './pages/Movimientos'
import Pedidos from './pages/Pedidos'
import Clientes from './pages/Clientes'
import Proveedores from './pages/Proveedores'
import Usuarios from './pages/Usuarios'
import Auditoria from './pages/Auditoria'

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route
        path="*"
        element={
          <ProtectedRoute>
            <Layout>
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
            </Layout>
          </ProtectedRoute>
        }
      />
    </Routes>
  )
}
