import { useEffect, useState } from 'react'
import { bodegaService, userService, type PageResponse } from '../api/services'
import { DataTable, ErrorState, LoadingState, PageHeader, Pagination, StatusBadge } from '../components/ui'
import Modal from '../components/Modal'
import { useToast } from '../components/Toast'

const blank = { name: '', username: '', email: '', password: '', rol: 'Operador de Almacén', activo: true, bodega_id: '' as number | '' }

const roles = ['Administrador', 'Administrador Regular', 'Operador de Almacén']

export default function Usuarios() {
  const [rows, setRows] = useState<any[]>([]); const [query, setQuery] = useState(''); const [page, setPage] = useState(1); const [meta, setMeta] = useState<PageResponse<any> | null>(null); const [form, setForm] = useState<any>(blank); const [editing, setEditing] = useState<any | null>(null); const [show, setShow] = useState(false); const [loading, setLoading] = useState(true); const [saving, setSaving] = useState(false); const [error, setError] = useState<any>(null); const [bodegas, setBodegas] = useState<any[]>([]); const { showToast } = useToast()
  const load = async (requestedPage = page, search = query) => { setError(null); setLoading(true); try { const response = await userService.list({ page: requestedPage, search }); setRows(response.data); setMeta(response) } catch (caught) { setError(caught) } finally { setLoading(false) } }
  useEffect(() => { void load(page, query) }, [page, query])
  useEffect(() => { void bodegaService.list().then((data) => setBodegas(Array.isArray(data) ? data : [])).catch(() => setBodegas([])) }, [])
  const openAdd = () => { setForm({ ...blank }); setEditing(null); setShow(true) }
  const openEdit = (user: any) => { setForm({ name: user.name, username: user.username, email: user.email || '', rol: user.rol, activo: user.activo !== false, bodega_id: user.bodega_id ?? '' }); setEditing(user); setShow(true) }
  const payload = () => ({ name: form.name, rol: form.rol, activo: form.activo, bodega_id: form.bodega_id === '' ? null : Number(form.bodega_id) })
  const save = async (event: React.FormEvent) => { event.preventDefault(); setSaving(true); try { if (editing) { await userService.update(editing.id, payload()); showToast('Usuario actualizado correctamente.', 'success') } else { await userService.create({ ...form, bodega_id: form.bodega_id === '' ? null : Number(form.bodega_id) }); showToast('Usuario creado y activo correctamente.', 'success') }; setShow(false); setEditing(null); setForm({ ...blank }); await load() } catch (caught: any) { showToast(caught.message, 'error'); setError(caught) } finally { setSaving(false) } }
  const reset2fa = async (user: any) => { try { await userService.reset2fa(user.id); showToast('2FA reiniciado. El usuario deberá configurarlo de nuevo.', 'success'); await load() } catch (caught: any) { showToast(caught.message, 'error') } }
  const [deleting, setDeleting] = useState<any | null>(null)
  const [deletingBusy, setDeletingBusy] = useState(false)
  const [deletePassword, setDeletePassword] = useState('')
  const confirmDelete = async () => {
    if (!deleting) return
    if (!deletePassword) { showToast('Escribe tu contraseña de administrador para confirmar.', 'error'); return }
    setDeletingBusy(true)
    try {
      await userService.remove(deleting.id, deletePassword)
      showToast('Usuario eliminado correctamente. Su historial se conserva anónimo.', 'success')
      setDeleting(null); setDeletePassword('')
      await load(page, query)
    } catch (caught: any) {
      showToast(caught.message, 'error')
    } finally { setDeletingBusy(false) }
  }
  return <div className="page-stack"><PageHeader title="Usuarios" description="Controla acceso, rol, estado y segundo factor por cuenta." action={<button className="button-primary" onClick={openAdd}>Nuevo usuario <span>+</span></button>} /><div className="toolbar"><label className="search-field"><span>⌕</span><input placeholder="Buscar usuario, rol o bodega…" value={query} onChange={(event) => { setQuery(event.target.value); setPage(1) }} /></label><span className="result-count">{meta?.total ?? 0} usuarios</span></div>{error && !loading ? <ErrorState error={error} /> : loading ? <LoadingState text="Cargando usuarios…" /> : <><DataTable rows={rows} columns={[{ key: 'name', label: 'Usuario', render: (row) => <div className="entity-cell"><span className="entity-avatar user-avatar">{row.name?.slice(0, 1)}</span><span><strong>{row.name}</strong><small>@{row.username}</small></span></div> }, { key: 'rol', label: 'Rol', render: (row) => <StatusBadge estado={row.rol} /> }, { key: 'bodega_asignada', label: 'Bodega' }, { key: 'activo', label: 'Estado', render: (row) => <StatusBadge estado={row.activo === false ? 'INACTIVO' : 'ACTIVO'} /> }, { key: 'two_factor_enabled', label: '2FA', render: (row) => <StatusBadge estado={row.two_factor_enabled ? 'CONFIGURADO' : 'PENDIENTE'} /> }, { key: 'actions', label: '', render: (row) => <div className="table-actions"><button className="button-quiet" onClick={() => openEdit(row)}>Editar</button><button className="button-warning-quiet" onClick={() => reset2fa(row)}>Reset 2FA</button><button className="button-danger-quiet" onClick={() => setDeleting(row)}>Eliminar</button></div> }]} />{meta && <Pagination currentPage={meta.current_page} lastPage={meta.last_page} total={meta.total} from={meta.from} to={meta.to} onChange={setPage} />}</>}
    <Modal open={show} title={editing ? 'Editar usuario' : 'Nuevo usuario'} onClose={() => !saving && setShow(false)} busy={saving}><form className="form-grid" onSubmit={save}><label>Nombre completo<input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required /></label><label>Usuario<input value={form.username} onChange={(event) => setForm({ ...form, username: event.target.value })} required disabled={!!editing} /></label><label>Correo<input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} required disabled={!!editing} /></label>{!editing && <label>Contraseña<input type="password" minLength={12} value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} required /></label>}<label>Rol<select value={form.rol} onChange={(event) => setForm({ ...form, rol: event.target.value })}>{roles.map(r => <option key={r} value={r}>{r}</option>)}</select></label><label>Bodega<select value={form.bodega_id ?? ''} onChange={(event) => setForm({ ...form, bodega_id: event.target.value })}><option value="">Sin bodega (vee todas)</option>{bodegas.map((b: any) => <option key={b.id} value={b.id}>{b.nombre}</option>)}</select></label>{editing && <label className="toggle-label"><input type="checkbox" checked={form.activo} onChange={(event) => setForm({ ...form, activo: event.target.checked })} /> Cuenta activa</label>}<div className="form-note form-span">Cada usuario configura su propio secreto TOTP. Resetear 2FA no expone ni reutiliza secretos.</div><div className="modal-footer form-span"><button type="button" className="button-secondary" onClick={() => setShow(false)} disabled={saving}>Cancelar</button><button className="button-primary" disabled={saving}>{saving ? 'Guardando…' : editing ? 'Guardar cambios' : 'Crear usuario'}</button></div></form></Modal>
    <Modal open={!!deleting} title="Eliminar usuario" onClose={() => { if (!deletingBusy) { setDeleting(null); setDeletePassword('') } }} busy={deletingBusy}>
      <div className="confirm-copy"><p>¿Quieres eliminar <strong>{deleting?.name}</strong>?</p><small>Esta acción no se puede deshacer. Su historial de auditoría y movimientos se conserva de forma anónima.</small></div>
      <label>Contraseña de administrador<input type="password" value={deletePassword} onChange={(event) => setDeletePassword(event.target.value)} autoComplete="current-password" disabled={deletingBusy} /></label>
      <div className="modal-footer"><button className="button-secondary" onClick={() => { setDeleting(null); setDeletePassword('') }} disabled={deletingBusy}>Cancelar</button><button className="button-danger" onClick={confirmDelete} disabled={deletingBusy || !deletePassword}>{deletingBusy ? 'Eliminando…' : 'Eliminar usuario'}</button></div>
    </Modal>
  </div>
}
