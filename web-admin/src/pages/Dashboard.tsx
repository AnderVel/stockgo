import { useEffect, useState } from 'react'
import { productService, orderService, inventoryService } from '../api/services'

export default function Dashboard() {
  const [productos, setProductos] = useState<any[]>([])
  const [pedidos, setPedidos] = useState<any[]>([])
  const [movimientos, setMovimientos] = useState<any[]>([])
  const [error, setError] = useState('')

  useEffect(() => {
    Promise.all([productService.list(), orderService.list(), inventoryService.movements()])
      .then(([p, pe, m]) => { setProductos(p); setPedidos(pe); setMovimientos(m) })
      .catch((e) => setError(e.message))
  }, [])

  const bajo = productos.filter((p) => (p.stock_disponible ?? 0) <= 5).length
  const count = (estado: string) => pedidos.filter((p) => p.estado === estado).length

  return (
    <div>
      <h2>Dashboard</h2>
      {error && <p className="error">{error}</p>}
      <div className="cards">
        <div className="card"><strong>{productos.length}</strong><span>Productos</span></div>
        <div className="card"><strong>{bajo}</strong><span>Stock bajo (≤5)</span></div>
        <div className="card"><strong>{count('PENDIENTE')}</strong><span>Pendientes</span></div>
        <div className="card"><strong>{count('SURTIDO')}</strong><span>Surtidos</span></div>
        <div className="card"><strong>{count('ENVIADO')}</strong><span>Enviados</span></div>
        <div className="card"><strong>{count('ENTREGADO')}</strong><span>Entregados</span></div>
        <div className="card"><strong>{count('CANCELADO')}</strong><span>Cancelados</span></div>
        <div className="card"><strong>{movimientos.length}</strong><span>Movimientos</span></div>
      </div>
      <h3>Movimientos recientes</h3>
      <ul>
        {movimientos.slice(0, 10).map((m) => (
          <li key={m.id_movimiento}>#{m.id_movimiento} {m.tipo} — {m.cantidad} — {m.motivo} ({m.created_at})</li>
        ))}
      </ul>
    </div>
  )
}
