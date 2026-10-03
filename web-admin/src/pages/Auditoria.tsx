import { useEffect, useState } from 'react'
import { auditService } from '../api/services'
import { DataTable } from '../components/ui'

export default function Auditoria() {
  const [rows, setRows] = useState<any[]>([])
  const [modulo, setModulo] = useState('')

  useEffect(() => {
    auditService.list(modulo ? `?modulo=${modulo}` : '').then((r: any) => setRows(r.data ?? [])).catch(() => {})
  }, [modulo])

  return (
    <div>
      <h2>Auditoría</h2>
      <input placeholder="Filtrar por módulo (ej. PRODUCTOS)…" value={modulo} onChange={(e) => setModulo(e.target.value)} />
      <DataTable
        rows={rows}
        columns={[
          { key: 'id_auditoria', label: 'ID' },
          { key: 'accion', label: 'Acción' },
          { key: 'modulo', label: 'Módulo' },
          { key: 'descripcion', label: 'Descripción' },
          { key: 'ip_address', label: 'IP' },
          { key: 'created_at', label: 'Fecha' },
        ]}
      />
    </div>
  )
}
