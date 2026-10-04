import { useEffect, useState } from 'react'
import { clientService } from '../api/services'
import { DataTable, StatusBadge } from '../components/ui'
import Modal from '../components/Modal'

const campoStyle = { width: '100%', padding: '0.5rem', marginBottom: '0.5rem', borderRadius: '0.25rem', border: '1px solid #ccc' }

export default function Clientes() {
  const [rows, setRows] = useState<any[]>([])
  const [error, setError] = useState('')
  const [editing, setEditing] = useState<any | null>(null)
  const [deleting, setDeleting] = useState<any | null>(null)
  const [form, setForm] = useState<any>({})
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving] = useState(false)

  const load = () => clientService.list().then(setRows).catch((e) => setError(e.message))
  useEffect(() => { load() }, [])

  const openAdd = () => { setForm({ nombre: '', telefono: '', correo: '', direccion: '', estado: 'ACTIVO' }); setEditing(null); setShowForm(true) }
  const openEdit = (c: any) => { setForm({ ...c }); setEditing(c); setShowForm(true) }

  const save = async (e: React.FormEvent) => { e.preventDefault(); setSaving(true); try { if (editing) await clientService.update(editing.id_cliente, form); else await clientService.create(form); setForm({}); setEditing(null); setShowForm(false); await load() } catch (err: any) { setError(err.message) } finally { setSaving(false) } }
  const confirmDelete = async () => { try { await clientService.remove(deleting.id_cliente); setDeleting(null); await load() } catch (err: any) { setError(err.message); setDeleting(null) } }

  return (
    <div>
      <h2>Clientes</h2>
      <button style={{ marginBottom: '1rem' }} onClick={openAdd}>+ Nuevo cliente</button>
      {error && <p style={{ color: '#991b1b' }}>{error}</p>}
      <DataTable
        rows={rows}
        columns={[
          { key: 'id_cliente', label: 'ID' },
          { key: 'nombre', label: 'Nombre' },
          { key: 'telefono', label: 'Teléfono' },
          { key: 'correo', label: 'Correo' },
          { key: 'direccion', label: 'Dirección' },
          { key: 'estado', label: 'Estado', render: (r) => <StatusBadge estado={r.estado} /> },
          { key: 'actions', label: 'Acciones', render: (r) => (<>
            <button style={{ padding: '0.2rem 0.4rem' }} onClick={() => openEdit(r)}>Editar</button>
            <button style={{ padding: '0.2rem 0.4rem', background: '#ef4444' }} onClick={() => setDeleting(r)}>Eliminar</button>
          </>) },
        ]}
      />
      <Modal open={showForm} title={editing ? 'Editar cliente' : 'Nuevo cliente'} onClose={() => { setForm({}); setEditing(null); setShowForm(false) }}>
        <form onSubmit={save}>
          <input style={campoStyle} name="nombre" placeholder="Nombre" value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} required />
          <input style={campoStyle} name="telefono" placeholder="Teléfono" value={form.telefono} onChange={(e) => setForm({ ...form, telefono: e.target.value })} />
          <input style={campoStyle} name="correo" type="email" placeholder="Correo" value={form.correo} onChange={(e) => setForm({ ...form, correo: e.target.value })} />
          <input style={campoStyle} name="direccion" placeholder="Dirección" value={form.direccion} onChange={(e) => setForm({ ...form, direccion: e.target.value })} />
          <select style={campoStyle} name="estado" value={form.estado} onChange={(e) => setForm({ ...form, estado: e.target.value })} required>
            <option value="">Selecciona...</option>
            <option value="ACTIVO">ACTIVO</option>
            <option value="INACTIVO">INACTIVO</option>
          </select>
          <button type="submit" disabled={saving}>{saving ? 'Guardando...' : editing ? 'Actualizar' : 'Crear'}</button>
        </form>
      </Modal>
      <Modal open={!!deleting} title="Eliminar cliente" onClose={() => setDeleting(null)}>
        <p>¿Eliminar al cliente <strong>{deleting?.nombre}</strong>?</p>
        <button onClick={confirmDelete} style={{ background: '#ef4444' }}>Eliminar</button>
      </Modal>
    </div>
  )
}
