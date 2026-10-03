import { useEffect, useState } from 'react'
import { inventoryService } from '../api/services'
import { DataTable, StatusBadge } from '../components/ui'

export default function Movimientos() {
  const [rows, setRows] = useState<any[]>([])
  const [tipo, setTipo] = useState('')

  useEffect(() => { inventoryService.movements().then(setRows).catch(() => {}) }, [])

  const filtered = tipo ? rows.filter((m) => m.tipo === tipo) : rows

  return (
    <div>
      <h2>Movimientos</h2>
      <select value={tipo} onChange={(e) => setTipo(e.target.value)}>
        <option value="">Todos</option>
        <option value="ENTRADA">ENTRADA</option>
        <option value="SALIDA">SALIDA</option>
        <option value="AJUSTE">AJUSTE</option>
      </select>
      <DataTable
        rows={filtered}
        columns={[
          { key: 'id_movimiento', label: 'ID' },
          { key: 'tipo', label: 'Tipo', render: (r) => <StatusBadge estado={r.tipo} /> },
          { key: 'cantidad', label: 'Cantidad' },
          { key: 'motivo', label: 'Motivo' },
          { key: 'stock_anterior', label: 'Anterior' },
          { key: 'stock_nuevo', label: 'Nuevo' },
          { key: 'created_at', label: 'Fecha' },
        ]}
      />
    </div>
  )
}
