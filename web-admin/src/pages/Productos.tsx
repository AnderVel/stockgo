import { useEffect, useMemo, useState } from 'react'
import { productService, type PageResponse } from '../api/services'
import { DataTable, ErrorState, formatCurrency, LoadingState, PageHeader, Pagination, StatusBadge } from '../components/ui'
import Modal from '../components/Modal'
import { useToast } from '../components/Toast'

type ProductForm = {
  codigo_barras: string
  nombre: string
  unidad_medida: string
  precio: string | number
  stock_fisico?: string | number
  stock_reservado?: string | number
  estado: string
  ubicacion: string
}

const emptyForm: ProductForm = { codigo_barras: '', nombre: '', unidad_medida: 'pza', precio: 0, stock_fisico: 0, stock_reservado: 0, estado: 'ACTIVO', ubicacion: '' }

export default function Productos() {
  const [rows, setRows] = useState<any[]>([])
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState<PageResponse<any> | null>(null)
  const [error, setError] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState<any | null>(null)
  const [deleting, setDeleting] = useState<any | null>(null)
  const [form, setForm] = useState<ProductForm>(emptyForm)
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving] = useState(false)
  const [deletingBusy, setDeletingBusy] = useState(false)
  const { showToast } = useToast()

  const load = async (requestedPage = page, search = query) => {
    setError(null)
    try {
      const response = await productService.list({ page: requestedPage, search })
      setRows(response.data)
      setMeta(response)
    } catch (caught) { setError(caught) } finally { setLoading(false) }
  }
  useEffect(() => { void load(page, query) }, [page, query])

  const filtered = useMemo(() => rows, [rows])

  const openAdd = () => { setForm({ ...emptyForm }); setEditing(null); setShowForm(true) }
  const openEdit = (product: any) => {
    setForm({ codigo_barras: product.codigo_barras, nombre: product.nombre, unidad_medida: product.unidad_medida, precio: product.precio, estado: product.estado, ubicacion: product.ubicacion || '' })
    setEditing(product)
    setShowForm(true)
  }
  const closeForm = () => { if (!saving) { setForm({ ...emptyForm }); setEditing(null); setShowForm(false) } }
  const updateField = (field: keyof ProductForm, value: string) => setForm((current) => ({ ...current, [field]: value }))

  const save = async (event: React.FormEvent) => {
    event.preventDefault()
    setSaving(true)
    try {
      if (editing) {
        await productService.update(editing.id_producto, form)
        showToast('Producto actualizado correctamente.', 'success')
      } else {
        await productService.create(form)
        showToast('Producto creado correctamente.', 'success')
      }
      closeForm()
      await load(page, query)
    } catch (caught: any) {
      setError(caught)
      showToast(caught.message, 'error')
    } finally { setSaving(false) }
  }

  const confirmDelete = async () => {
    if (!deleting) return
    setDeletingBusy(true)
    try {
      await productService.remove(deleting.id_producto)
      showToast('Producto eliminado correctamente.', 'success')
      setDeleting(null)
      await load(page, query)
    } catch (caught: any) {
      showToast(caught.message, 'error')
    } finally { setDeletingBusy(false) }
  }

  return <div className="page-stack">
    <PageHeader title="Productos" description="Administra el catálogo sin alterar el historial de inventario." action={<button className="button-primary" onClick={openAdd}>Nuevo producto <span>+</span></button>} />
    <div className="toolbar"><label className="search-field"><span>⌕</span><input placeholder="Buscar por nombre o código…" value={query} onChange={(event) => { setQuery(event.target.value); setPage(1) }} /></label><span className="result-count">{meta?.total ?? 0} registros</span></div>
    {error && !loading ? <ErrorState error={error} /> : loading ? <LoadingState text="Cargando productos…" /> : <DataTable rows={filtered} columns={[
      { key: 'nombre', label: 'Producto', render: (row) => <div className="entity-cell"><span className="entity-avatar product-avatar">{row.nombre?.slice(0, 1)}</span><span><strong>{row.nombre}</strong><small>{row.codigo_barras}</small></span></div> },
      { key: 'unidad_medida', label: 'Unidad' },
      { key: 'precio', label: 'Precio', render: (row) => formatCurrency(row.precio) },
      { key: 'stock_fisico', label: 'Físico' },
      { key: 'stock_reservado', label: 'Reservado' },
      { key: 'stock_disponible', label: 'Disponible', render: (row) => <strong className={row.stock_disponible <= 5 ? 'stock-warning' : 'stock-ok'}>{row.stock_disponible}</strong> },
      { key: 'estado', label: 'Estado', render: (row) => <StatusBadge estado={row.estado} /> },
      { key: 'actions', label: '', render: (row) => <div className="table-actions"><button className="button-quiet" onClick={() => openEdit(row)}>Editar</button><button className="button-danger-quiet" onClick={() => setDeleting(row)}>Eliminar</button></div> },
    ]} />}
    {!loading && !error && meta && <Pagination currentPage={meta.current_page} lastPage={meta.last_page} total={meta.total} from={meta.from} to={meta.to} onChange={setPage} />}

    <Modal open={showForm} title={editing ? 'Editar producto' : 'Nuevo producto'} onClose={closeForm} busy={saving}>
      <form className="form-grid" onSubmit={save}>
        <label>Código de barras<input value={form.codigo_barras} onChange={(event) => updateField('codigo_barras', event.target.value)} required /></label>
        <label>Nombre del producto<input value={form.nombre} onChange={(event) => updateField('nombre', event.target.value)} required /></label>
        <label>Unidad de medida<input value={form.unidad_medida} onChange={(event) => updateField('unidad_medida', event.target.value)} required /></label>
        <label>Precio<input type="number" step="0.01" min="0" value={form.precio} onChange={(event) => updateField('precio', event.target.value)} required /></label>
        {!editing && <><label>Stock físico inicial<input type="number" min="0" value={form.stock_fisico} onChange={(event) => updateField('stock_fisico', event.target.value)} required /></label><label>Stock reservado inicial<input type="number" min="0" value={form.stock_reservado} onChange={(event) => updateField('stock_reservado', event.target.value)} required /></label></>}
        {editing && <div className="form-note form-span">El stock se modifica desde Movimientos para conservar trazabilidad. Disponible actual: <strong>{editing.stock_disponible}</strong>.</div>}
        <label>Estado<select value={form.estado} onChange={(event) => updateField('estado', event.target.value)} required><option value="ACTIVO">ACTIVO</option><option value="INACTIVO">INACTIVO</option></select></label>
        <label>Ubicación<input value={form.ubicacion} onChange={(event) => updateField('ubicacion', event.target.value)} /></label>
        <div className="modal-footer form-span"><button type="button" className="button-secondary" onClick={closeForm} disabled={saving}>Cancelar</button><button type="submit" className="button-primary" disabled={saving}>{saving ? 'Guardando…' : editing ? 'Guardar cambios' : 'Crear producto'}</button></div>
      </form>
    </Modal>
    <Modal open={!!deleting} title="Eliminar producto" onClose={() => !deletingBusy && setDeleting(null)} busy={deletingBusy}>
      <div className="confirm-copy"><p>¿Quieres eliminar <strong>{deleting?.nombre}</strong>?</p><small>Los productos con movimientos, pedidos o reservas históricas están protegidos por el backend.</small></div><div className="modal-footer"><button className="button-secondary" onClick={() => setDeleting(null)} disabled={deletingBusy}>Cancelar</button><button className="button-danger" onClick={confirmDelete} disabled={deletingBusy}>{deletingBusy ? 'Eliminando…' : 'Eliminar producto'}</button></div>
    </Modal>
  </div>
}
