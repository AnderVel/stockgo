import { useEffect, useState } from 'react'
import { userService } from '../api/services'
import { DataTable, StatusBadge } from '../components/ui'
import Modal from '../components/Modal'

const campoStyle = { width: '100%', padding: '0.5rem', marginBottom: '0.5rem', borderRadius: '0.25rem', border: '1px solid #ccc' }

export default function Usuarios() {
  const [rows, setRows] = useState<any[]>([])
  const [error, setError] = useState('')
  const [editing, setEditing] = useState<any | null>(null)
  const [form, setForm] = useState<any>({})
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving] = useState(false)

  const load = () => userService.list().then(setRows).catch((e) => setError(e.message))
  useEffect(() => { load() }, [])

  const openAdd = () => { setForm({ name: '', username: '', email: '', password: '', rol: 'Operador de Almacén', bodega_asignada: '' }); setEditing(null); setShowForm(true) }
  const openEdit = (u: any) => { setForm({ name: u.name, rol: u.rol, bodega_asignada: u.bodega_asignada }); setEditing(u); setShowForm(true) }

  const save = async (e: React.FormEvent) => { e.preventDefault(); setSaving(true); try { if (editing) await userService.update(editing.id, form); else await userService.create(form); setForm({}); setEditing(null); setShowForm(false); await load() } catch (err: any) { setError(err.message) } finally { setSaving(false) } }

  const handleReset2fa = async (u: any) => { try { await userService.reset2fa(u.id); await load() } catch (err: any) { setError(err.message) } }

  return (
    <div>
      <h2>Usuarios</h2>
      <button style={{ marginBottom: '1rem' }} onClick={openAdd}>+ Nuevo usuario</button>
      {error && <p style={{ color: '#991b1b' }}>{error}</p>}
      <DataTable
        rows={rows}
        columns={[
          { key: 'id', label: 'ID' },
          { key: 'username', label: 'Usuario' },
          { key: 'name', label: 'Nombre' },
          { key: 'email', label: 'Correo' },
          { key: 'rol', label: 'Rol', render: (r) => <StatusBadge estado={r.rol} /> },
          { key: 'bodega_asignada', label: 'Bodega' },
          { key: 'two_factor_enabled', label: '2FA', render: (r) => <StatusBadge estado={r.two_factor_enabled ? 'ACTIVO' : 'INACTIVO'} /> },
          { key: 'actions', label: 'Acciones', render: (r) => (<>
            <button style={{ padding: '0.2rem 0.4rem' }} onClick={() => openEdit(r)}>Editar</button>
            <button style={{ padding: '0.2rem 0.4rem', background: '#f59e0b' }} onClick={() => handleReset2fa(r)}>Reset 2FA</button>
          </>) },
        ]}
      />
      <Modal open={showForm} title={editing ? 'Editar usuario' : 'Nuevo usuario'} onClose={() => { setForm({}); setEditing(null); setShowForm(false) }}>
        <form onSubmit={save}>
          <input style={campoStyle} name="name" placeholder="Nombre" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          <input style={campoStyle} name="username" placeholder="Usuario" value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} required={!editing} />
          <input style={campoStyle} name="email" placeholder="Correo" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          {!editing && <input style={campoStyle} type="password" name="password" placeholder="Contraseña" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required />}
          <select style={campoStyle} value={form.rol} onChange={(e) => setForm({ ...form, rol: e.target.value })} required>
            <option value="Administrador">Administrador</option>
            <option value="Operador de Almacén">Operador de Almacén</option>
          </select>
          <input style={campoStyle} name="bodega_asignada" placeholder="Bodega" value={form.bodega_asignada} onChange={(e) => setForm({ ...form, bodega_asignada: e.target.value })} required />
          <button type="submit" disabled={saving}>{saving ? 'Guardando...' : editing ? 'Actualizar' : 'Crear'}</button>
        </form>
      </Modal>
    </div>
  )
}
