import { useEffect, useState } from 'react'
import { orderService } from '../api/services'
import { DataTable, StatusBadge } from '../components/ui'

export default function Pedidos() {
  const [rows, setRows] = useState<any[]>([])
  const [estado, setEstado] = useState('')
  const [error, setError] = useState('')

  const load = () => orderService.list().then(setRows).catch((e) => setError(e.message))
  useEffect(() => { load() }, [])

  const act = async (fn: () => Promise<any>) => {
    try { await fn(); await load() } catch (e: any) { setError(e.message) }
  }

  const filtered = estado ? rows.filter((p) => p.estado === estado) : rows

  return (
    <div>
      <h2>Pedidos</h2>
      <select value={estado} onChange={(e) => setEstado(e.target.value)}>
        <option value="">Todos</option>
        {['PENDIENTE', 'SURTIDO', 'ENVIADO', 'ENTREGADO', 'CANCELADO'].map((s) => <option key={s}>{s}</option>)}
      </select>
      {error && <p className="error">{error}</p>}
      <DataTable
        rows={filtered}
        columns={[
          { key: 'id_pedido', label: 'ID' },
          { key: 'fecha_pedido', label: 'Fecha' },
          { key: 'estado', label: 'Estado', render: (r) => <StatusBadge estado={r.estado} /> },
          { key: 'total', label: 'Total' },
          { key: 'id_cliente', label: 'Cliente' },
          {
            key: 'acciones', label: 'Acciones', render: (r) => (
              <div className="row">
                {r.estado === 'PENDIENTE' && <button onClick={() => act(() => orderService.surtir(r.id_pedido))}>Surtir</button>}
                {r.estado === 'PENDIENTE' && <button className="secondary" onClick={() => act(() => orderService.cancel(r.id_pedido))}>Cancelar</button>}
                {r.estado === 'SURTIDO' && <button onClick={() => act(() => orderService.entregar(r.id_pedido))}>Enviar</button>}
                {r.estado === 'ENVIADO' && <button onClick={() => act(() => orderService.recibir(r.id_pedido))}>Entregar</button>}
              </div>
            ),
          },
        ]}
      />
    </div>
  )
}
