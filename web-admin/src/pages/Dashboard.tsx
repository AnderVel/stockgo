import { useEffect, useState } from 'react'
import { dashboardService, type DashboardSummary } from '../api/services'
import { EmptyState, ErrorState, formatDate, LoadingState, PageHeader, StatCard, StatusBadge } from '../components/ui'

const initialData: DashboardSummary = { kpis: {}, pedidos_por_estado: {}, movimientos_recientes: [] }

export default function Dashboard() {
  const [data, setData] = useState<DashboardSummary>(initialData)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<any>(null)

  useEffect(() => {
    dashboardService.summary()
      .then(setData)
      .catch(setError)
      .finally(() => setLoading(false))
  }, [])

  const kpi = data.kpis

  return (
    <div className="dashboard-page">
      <PageHeader title="Dashboard" description="Resumen operativo del inventario y la distribución." />
      {loading ? <LoadingState text="Preparando el resumen operativo…" /> : error ? <ErrorState error={error} /> : (
        <>
          <div className="stat-grid">
            <StatCard label="Productos" value={kpi.productos ?? 0} detail="Catálogo activo" />
            <StatCard label="Stock disponible" value={kpi.stock_disponible ?? 0} detail="Unidades listas" tone="mint" />
            <StatCard label="Stock bajo" value={kpi.stock_bajo ?? 0} detail="Requieren atención" tone="amber" />
            <StatCard label="Pedidos" value={kpi.pedidos ?? 0} detail="Registrados" tone="blue" />
            <StatCard label="Clientes" value={kpi.clientes ?? 0} detail="Cuentas activas" tone="rose" />
          </div>

          <div className="dashboard-columns">
            <section className="surface-panel">
              <div className="section-heading"><div><p className="eyebrow">Actividad</p><h3>Movimientos recientes</h3></div><span className="section-count">Últimos 12</span></div>
              {data.movimientos_recientes.length === 0 ? <EmptyState text="Todavía no hay movimientos." /> : <div className="activity-list">
                {data.movimientos_recientes.map((movement) => {
                  const esPedido = movement.origen === 'pedido'
                  const etiquetaPedido: Record<string, string> = {
                    PEDIDO_CREADO: 'CREADO',
                    PEDIDO_SURTIDO: 'SURTIDO',
                    PEDIDO_ENVIADO: 'ENVIADO',
                    PEDIDO_ENTREGADO: 'ENTREGADO',
                    PEDIDO_CANCELADO: 'CANCELADO',
                  }
                  return (
                    <article className="activity-item" key={movement.id}>
                      {esPedido ? (
                        <span className="activity-icon activity-pedido" aria-hidden="true">P</span>
                      ) : (
                        <span className={`activity-icon activity-${String(movement.tipo).toLowerCase()}`} aria-hidden="true">{movement.tipo === 'ENTRADA' ? '+' : '−'}</span>
                      )}
                      <div className="activity-main"><div className="activity-title"><strong>{movement.titulo || 'Movimiento de inventario'}</strong><StatusBadge estado={esPedido ? (etiquetaPedido[movement.tipo] ?? 'PENDIENTE') : movement.tipo} /></div><span>{movement.producto || (esPedido ? 'Gestión de pedidos' : `Producto #${movement.id_producto}`)}</span><small>{formatDate(movement.created_at)}</small></div>
                      {!esPedido && <strong className={movement.tipo === 'ENTRADA' ? 'quantity-positive' : 'quantity-negative'}>{movement.tipo === 'ENTRADA' ? '+' : '−'}{movement.cantidad}</strong>}
                    </article>
                  )
                })}
              </div>}
            </section>

            <section className="surface-panel order-summary">
              <div className="section-heading"><div><p className="eyebrow">Pedidos</p><h3>Estado actual</h3></div></div>
              {['PENDIENTE', 'SURTIDO', 'ENVIADO', 'ENTREGADO', 'CANCELADO'].map((status) => <div className="order-row" key={status}><StatusBadge estado={status} /><strong>{data.pedidos_por_estado[status] ?? 0}</strong></div>)}
              <div className="flow-note"><span className="pulse-dot" /> Datos agregados directamente desde la API</div>
            </section>
          </div>
        </>
      )}
    </div>
  )
}
