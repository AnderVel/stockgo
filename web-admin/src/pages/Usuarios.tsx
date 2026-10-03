import { useEffect, useState } from 'react'
import { userService } from '../api/services'
import { DataTable, StatusBadge } from '../components/ui'

export default function Usuarios() {
  const [rows, setRows] = useState<any[]>([])
  const [error, setError] = useState('')

  const load = () => userService.list().then(setRows).catch((e) => setError(e.message))
  useEffect(() => { load() }, [])

  return (
    <div>
      <h2>Usuarios</h2>
      {error && <p className="error">{error}</p>}
      <DataTable
        rows={rows}
        columns={[
          { key: 'id', label: 'ID' },
          { key: 'username', label: 'Usuario' },
          { key: 'name', label: 'Nombre' },
          { key: 'rol', label: 'Rol' },
          { key: 'bodega_asignada', label: 'Bodega' },
          { key: 'two_factor_enabled', label: '2FA', render: (r) => <StatusBadge estado={r.two_factor_enabled ? 'ACTIVO' : 'INACTIVO'} /> },
          {
            key: 'acciones', label: 'Acciones', render: (r) => (
              <button className="secondary" onClick={async () => {
                try { await userService.reset2fa(r.id); await load() } catch (e: any) { setError(e.message) }
              }}>Reset 2FA</button>
            ),
          },
        ]}
      />
    </div>
  )
}
