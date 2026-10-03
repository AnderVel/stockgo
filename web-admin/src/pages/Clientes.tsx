import { useEffect, useState } from 'react'
import { clientService } from '../api/services'
import { DataTable, StatusBadge } from '../components/ui'

export default function Clientes() {
  const [rows, setRows] = useState<any[]>([])
  const [error, setError] = useState('')

  useEffect(() => { clientService.list().then(setRows).catch((e) => setError(e.message)) }, [])

  return (
    <div>
      <h2>Clientes</h2>
      {error && <p className="error">{error}</p>}
      <DataTable
        rows={rows}
        columns={[
          { key: 'id_cliente', label: 'ID' },
          { key: 'nombre', label: 'Nombre' },
          { key: 'telefono', label: 'Teléfono' },
          { key: 'correo', label: 'Correo' },
          { key: 'direccion', label: 'Dirección' },
          { key: 'estado', label: 'Estado', render: (r) => <StatusBadge estado={r.estado} /> },
        ]}
      />
    </div>
  )
}
