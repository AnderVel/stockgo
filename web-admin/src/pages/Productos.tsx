import { useEffect, useState } from 'react'
import { productService } from '../api/services'
import { DataTable, StatusBadge } from '../components/ui'
import Modal from '../components/Modal'

const campoStyle = { width: '100%', padding: '0.5rem', marginBottom: '0.5rem', borderRadius: '0.25rem', border: '1px solid #ccc' }

export default function Productos() {
  const [rows, setRows] = useState<any[]>([])
  const [q, setQ] = useState('')
  const [error, setError] = useState('')
  const [editing, setEditing] = useState<any | null>(null)
  const [deleting, setDeleting] = useState<any | null>(null)
  const [form, setForm] = useState<any>({})
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving] = useState(false)

  const load = () => productService.list().then(setRows).catch((e) => setError(e.message))
  useEffect(() => { load() }, [])

  const filtered = rows.filter((p) =>
    p.nombre?.toLowerCase().includes(q.toLowerCase()) || p.codigo_barras?.includes(q),
  )

  const openAdd = () => {
    setForm({ codigo_barras: '', nombre: '', unidad_medida: 'pza', precio: 0, stock_fisico: 0, stock_reservado: 0, estado: 'ACTIVO', ubicacion: '' })
    setEditing(null)
    setShowForm(true)
  }

  const openEdit = (p: any) => {
    setForm({ ...p })
    setEditing(p)
    setShowForm(true)
  }

  const save = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      if (editing) await productService.update(editing.id_producto, form)
      else await productService.create(form)
      setForm({})
      setEditing(null)
      setShowForm(false)
      await load()
    } catch (err: any) {
      setError(err.message)
    } finally {
      setSaving(false)
    }
  }

  const confirmDelete = async () => {
    try {
      await productService.remove(deleting.id_producto)
      setDeleting(null)
      await load()
    } catch (err: any) {
      setError(err.message)
      setDeleting(null)
    }
  }

  return (
    <div>
      <h2>Productos</h2>
      <input placeholder="Buscar por nombre o código…" value={q} onChange={(e) => setQ(e.target.value)} />
      <button style={{ marginBottom: '1rem' }} onClick={openAdd}>+ Nuevo producto</button>
      {error && <p style={{ color: '#991b1b' }}>{error}</p>}
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
          {
            key: 'actions',
            label: 'Acciones',
            render: (r) => (
              <>
                <button style={{ padding: '0.2rem 0.4rem' }} onClick={() => openEdit(r)}>Editar</button>
                <button style={{ padding: '0.2rem 0.4rem', background: '#ef4444' }} onClick={() => setDeleting(r)}>Eliminar</button>
              </>
            ),
          },
        ]}
      />
      <Modal open={showForm} title={editing ? 'Editar producto' : 'Nuevo producto'} onClose={() => { setForm({}); setEditing(null); setShowForm(false) }}>
        <form onSubmit={save}>
          <input style={campoStyle} name="codigo_barras" placeholder="Código de barras" value={form.codigo_barras} onChange={(e) => setForm({ ...form, codigo_barras: e.target.value })} required />
          <input style={campoStyle} name="nombre" placeholder="Nombre" value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} required />
          <input style={campoStyle} name="unidad_medida" placeholder="Unidad (pza)" value={form.unidad_medida} onChange={(e) => setForm({ ...form, unidad_medida: e.target.value })} required />
          <input style={campoStyle} name="precio" type="number" step="0.01" min="0" placeholder="Precio" value={form.precio} onChange={(e) => setForm({ ...form, precio: e.target.value })} required />
          <input style={campoStyle} name="stock_fisico" type="number" min="0" placeholder="Stock físico" value={form.stock_fisico} onChange={(e) => setForm({ ...form, stock_fisico: e.target.value })} required />
          <input style={campoStyle} name="stock_reservado" type="number" min="0" placeholder="Stock reservado" value={form.stock_reservado} onChange={(e) => setForm({ ...form, stock_reservado: e.target.value })} required />
          <select style={campoStyle} name="estado" value={form.estado} onChange={(e) => setForm({ ...form, estado: e.target.value })} required>
            <option value="">Selecciona...</option>
            <option value="ACTIVO">ACTIVO</option>
            <option value="INACTIVO">INACTIVO</option>
          </select>
          <input style={campoStyle} name="ubicacion" placeholder="Ubicación" value={form.ubicacion} onChange={(e) => setForm({ ...form, ubicacion: e.target.value })} />
          <button type="submit" disabled={saving}>{saving ? 'Guardando...' : editing ? 'Actualizar' : 'Crear'}</button>
        </form>
      </Modal>
      <Modal open={!!deleting} title="Confirmar eliminación" onClose={() => setDeleting(null)}>
        <p>¿Eliminar el producto <strong>{deleting?.nombre}</strong>?</p>
        <p>Si tiene stock reservado o movimientos asociados, el backend protegerá el registro.</p>
        <button onClick={confirmDelete} style={{ background: '#ef4444' }}>Eliminar</button>
      </Modal>
    </div>
  )
}
