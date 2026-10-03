import { useEffect, useState } from 'react'
import { productService } from '../api/services'
import { DataTable, StatusBadge } from '../components/ui'

export default function Inventario() {
  const [rows, setRows] = useState<any[]>([])
  const [q, setQ] = useState('')

  useEffect(() => { productService.list().then(setRows).catch(() => {}) }, [])

  const filtered = rows.filter((p) => p.nombre?.toLowerCase().includes(q.toLowerCase()) || p.codigo_barras?.includes(q))

  return (
    <div>
      <h2>Inventario</h2>
      <input placeholder="Buscar…" value={q} onChange={(e) => setQ(e.target.value)} />
      <DataTable
        rows={filtered}
        columns={[
          { key: 'codigo_barras', label: 'Código' },
          { key: 'nombre', label: 'Producto' },
          { key: 'ubicacion', label: 'Ubicación' },
          { key: 'stock_fisico', label: 'Físico' },
          { key: 'stock_reservado', label: 'Reservado' },
          { key: 'stock_disponible', label: 'Disponible' },
          { key: 'estado', label: 'Estado', render: (r) => <StatusBadge estado={r.estado} /> },
        ]}
      />
    </div>
  )
}
