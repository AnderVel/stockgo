import type { ReactNode } from 'react'

export function LoadingState({ text = 'Cargando información…' }: { text?: string }) {
  return <div className="state-card state-loading"><span className="spinner" aria-hidden="true" />{text}</div>
}

export function EmptyState({ text }: { text: string }) {
  return <div className="state-card state-empty"><span className="state-icon">∅</span><strong>{text}</strong></div>
}

export function ErrorState({ error }: { error: any }) {
  return <div className="state-card state-error"><strong>No fue posible cargar la información</strong><span>{error?.message || 'Intenta nuevamente.'}</span></div>
}

export function PageHeader({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return <div className="page-header"><div><p className="eyebrow">StockGo</p><h2>{title}</h2>{description && <p className="page-description">{description}</p>}</div>{action}</div>
}

export function StatCard({ label, value, detail, tone = 'violet' }: { label: string; value: string | number; detail?: string; tone?: string }) {
  return <div className={`stat-card stat-${tone}`}><div className="stat-topline"><span>{label}</span><span className="stat-mark" aria-hidden="true" /></div><strong>{value}</strong>{detail && <small>{detail}</small>}</div>
}

export function formatDate(value?: string | null) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date).replace('.', '')
}

export function formatCurrency(value: number | string | null | undefined) {
  return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(value || 0))
}

export function StatusBadge({ estado }: { estado: string }) {
  const cls = estado.toLowerCase().replace(/\s/g, '')
  return <span className={`badge ${cls}`}>{estado}</span>
}

export function DataTable({ columns, rows }: { columns: { key: string; label: string; render?: (r: any) => ReactNode }[]; rows: any[] }) {
  if (!rows?.length) return <EmptyState text="Sin registros." />
  return (
    <div className="table-shell"><table className="table">
      <thead><tr>{columns.map((c) => <th key={c.key}>{c.label}</th>)}</tr></thead>
      <tbody>{rows.map((r, i) => <tr key={r.id ?? r.id_producto ?? r.id_pedido ?? r.id_cliente ?? r.id_proveedor ?? r.id_movimiento ?? i}>{columns.map((c) => <td key={c.key}>{c.render ? c.render(r) : r[c.key] ?? '—'}</td>)}</tr>)}</tbody>
    </table></div>
  )
}

export function Pagination({
  currentPage,
  lastPage,
  total,
  from,
  to,
  onChange,
}: {
  currentPage: number
  lastPage: number
  total: number
  from: number | null
  to: number | null
  onChange: (page: number) => void
}) {
  if (lastPage <= 1) return null

  return (
    <div className="pagination" aria-label="Paginación">
      <span>{from ?? 0}–{to ?? 0} de {total}</span>
      <div>
        <button type="button" className="button-quiet" disabled={currentPage <= 1} onClick={() => onChange(currentPage - 1)}>Anterior</button>
        <strong>Página {currentPage} de {lastPage}</strong>
        <button type="button" className="button-quiet" disabled={currentPage >= lastPage} onClick={() => onChange(currentPage + 1)}>Siguiente</button>
      </div>
    </div>
  )
}

export function ConfirmDialog({ text, onConfirm, onCancel }: { text: string; onConfirm: () => void; onCancel: () => void }) {
  return (
    <div className="modal-backdrop">
      <div className="modal">
        <p>{text}</p>
        <div className="row">
          <button onClick={onConfirm}>Confirmar</button>
          <button className="secondary" onClick={onCancel}>Cancelar</button>
        </div>
      </div>
    </div>
  )
}
