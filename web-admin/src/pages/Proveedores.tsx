import { useEffect, useState } from 'react'
import { supplierService } from '../api/services'
import { DataTable, StatusBadge } from '../components/ui'
import Modal from '../components/Modal'

const campoStyle = { width: '100%', padding: '0.5rem', marginBottom: '0.5rem', borderRadius: '0.25rem', border: '1px solid #ccc' }

export default function Proveedores() {
  const [rows, setRows] = useState<any[]>([])
  const [error, setError] = useState('')
  const [editing, setEditing] = useState<any | null>(null)
  const [deleting, setDeleting] = useState<any | null>(null)
  const [form, setForm] = useState<any>({})
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving] = useState(false)

  const load = () => supplierService.list().then(setRows).catch((e) => setError(e.message))
  useEffect(() => { load() }, [])

  const openAdd = () => { setForm({ nombre: '', contacto: '', telefono: '', correo: '', direccion: '', estado: 'ACTIVO' }); setEditing(null); setShowForm(true) }
  const openEdit = (p: any) => { setForm({ ...p }); setEditing(p); setShowForm(true) }

  const save = async (e: React.FormEvent) => { e.preventDefault(); setSaving(true); try { if (editing) await supplierService.update(editing.id_proveedor, form); else await supplierService.create(form); setForm({}); setEditing(null); setShowForm(false); await load() } catch (err: any) { setError(err.message) } finally { setSaving(false) } }
  const confirmDelete = async () => { try { await supplierService.remove(deleting.id_proveedor); setDeleting(null); await load() } catch (err: any) { setError(err.message); setDeleting(null) } }

  return (
    <div>
      <h2>Proveedores</h2>
      <button style={{ marginBottom: '1rem' }} onClick={openAdd}>+ Nuevo proveedor</button>
      {error && <p style={{ color: '#991b1b' }}>{error}</p>}
      <DataTable
        rows={rows}
        columns={[
          { key: 'id_proveedor', label: 'ID' },
          { key: 'nombre', label: 'Nombre' },
          { key: 'contacto', label: 'Contacto' },
          { key: 'telefono', label: 'Teléfono' },
          { key: 'correo', label: 'Correo' },
          { key: 'estado', label: 'Estado', render: (r) => <StatusBadge estado={r.estado} /> },
          { key: 'actions', label: 'Acciones', render: (r) => (<>
            <button style={{ padding: '0.2rem 0.4rem' }} onClick={() => openEdit(r)}>Editar</button>
            <button style={{ padding: '0.2rem 0.4rem', background: '#ef4444' }} onClick={() => setDeleting(r)}>Eliminar</button>
          </>) },
        ]}
      />
      <Modal open={showForm} title={editing ? 'Editar proveedor' : 'Nuevo proveedor'} onClose={() => { setForm({}); setEditing(null); setShowForm(false) }}>
        <form onSubmit={save}>
          <input style={campoStyle} name="nombre" placeholder="Nombre" value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} required />
          <input style={campoStyle} name="contacto" placeholder="Contacto" value={form.contacto} onChange={(e) => setForm({ ...form, contacto: e.target.value })} />
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
      <Modal open={!!deleting} title="Eliminar proveedor" onClose={() => setDeleting(null)}>
        <p>¿Eliminar al proveedor <strong>{deleting?.nombre}</strong>?</p>
        <button onClick={confirmDelete} style={{ background: '#ef4444' }}>Eliminar</button>
      </Modal>
    </div>
  )
}
