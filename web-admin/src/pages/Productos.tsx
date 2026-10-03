import { useEffect, useState } from 'react'
import { productService } from '../api/services'
import { DataTable, StatusBadge } from '../components/ui'

export default function Productos() {
  const [rows, setRows] = useState<any[]>([])
  const [q, setQ] = useState('')
  const [error, setError] = useState('')

  useEffect(() => { productService.list().then(setRows).catch((e) => setError(e.message)) }, [])

  const filtered = rows.filter((p) =>
    p.nombre?.toLowerCase().includes(q.toLowerCase()) || p.codigo_barras?.includes(q),
  )

  return (
    <div>
      <h2>Productos</h2>
      <input placeholder="Buscar por nombre o código…" value={q} onChange={(e) => setQ(e.target.value)} />
      {error && <p className="error">{error}</p>}
      <DataTable
        rows={filtered}
        columns={[
          { key: 'id_producto', label: 'ID' },
          { key: 'codigo_barras', label: 'Código' },
          { key: 'nombre', label: 'Nombre' },
          { key: 'precio', label: 'Precio' },
          { key: 'stock_fisico', label: 'Físico' },
          { key: 'stock_reservado', label: 'Reservado' },
          { key: 'stock_disponible', label: 'Disponible' },
          { key: 'ubicacion', label: 'Ubicación' },
          { key: 'estado', label: 'Estado', render: (r) => <StatusBadge estado={r.estado} /> },
        ]}
      />
    </div>
  )
}
