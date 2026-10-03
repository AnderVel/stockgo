export function LoadingState() { return <p>Cargando…</p> }
export function EmptyState({ text }: { text: string }) { return <p className="empty">{text}</p> }
export function ErrorState({ error }: { error: any }) { return <p className="error">{error?.message || 'Error'}</p> }

export function StatusBadge({ estado }: { estado: string }) {
  const cls = estado.toLowerCase().replace(/\s/g, '')
  return <span className={`badge ${cls}`}>{estado}</span>
}

export function DataTable({ columns, rows }: { columns: { key: string; label: string; render?: (r: any) => React.ReactNode }[]; rows: any[] }) {
  if (!rows?.length) return <EmptyState text="Sin registros." />
  return (
    <table className="table">
      <thead>
        <tr>{columns.map((c) => <th key={c.key}>{c.label}</th>)}</tr>
      </thead>
      <tbody>
        {rows.map((r, i) => (
          <tr key={r.id ?? r.id_producto ?? r.id_pedido ?? r.id_cliente ?? r.id_proveedor ?? r.id_movimiento ?? i}>
            {columns.map((c) => <td key={c.key}>{c.render ? c.render(r) : r[c.key]}</td>)}
          </tr>
        ))}
      </tbody>
    </table>
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
