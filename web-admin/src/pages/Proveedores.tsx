import { useEffect, useState } from 'react'
import { supplierService } from '../api/services'
import { DataTable, StatusBadge } from '../components/ui'

export default function Proveedores() {
  const [rows, setRows] = useState<any[]>([])

  useEffect(() => { supplierService.list().then(setRows).catch(() => {}) }, [])

  return (
    <div>
      <h2>Proveedores</h2>
      <DataTable
        rows={rows}
        columns={[
          { key: 'id_proveedor', label: 'ID' },
          { key: 'nombre', label: 'Nombre' },
          { key: 'contacto', label: 'Contacto' },
          { key: 'telefono', label: 'Teléfono' },
          { key: 'correo', label: 'Correo' },
          { key: 'estado', label: 'Estado', render: (r) => <StatusBadge estado={r.estado} /> },
        ]}
      />
    </div>
  )
}
