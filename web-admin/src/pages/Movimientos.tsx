import { useEffect, useState } from 'react'
import { inventoryService, productService, type PageResponse } from '../api/services'
import { DataTable, ErrorState, formatDate, LoadingState, PageHeader, Pagination, StatusBadge } from '../components/ui'
import Modal from '../components/Modal'
import { useToast } from '../components/Toast'

type MovementType = 'entrada' | 'salida' | 'ajuste'

interface ProductSelection {
  id_producto: number
  nombre: string
  codigo_barras: string
  stock_disponible: number
  ubicacion?: string | null
}

export default function Movimientos() {
  const [rows, setRows] = useState<any[]>([])
  const [tipo, setTipo] = useState('')
  const [mes, setMes] = useState('')
  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState<PageResponse<any> | null>(null)
  const [show, setShow] = useState(false)
  const [movementType, setMovementType] = useState<MovementType>('entrada')
  const [form, setForm] = useState({ id_producto: '', cantidad: '', motivo: '' })
  const [selectedProduct, setSelectedProduct] = useState<ProductSelection | null>(null)
  const [searchQuery, setSearchQuery] = useState('')
  const [searchResults, setSearchResults] = useState<ProductSelection[]>([])
  const [searching, setSearching] = useState(false)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [error, setError] = useState<any>(null)
  const { showToast } = useToast()

  const load = async (requestedPage = page, requestedType = tipo, requestedMes = mes) => {
    setError(null)
    setLoading(true)
    try {
      const response = await inventoryService.movements({
        page: requestedPage,
        tipo: requestedType,
        mes: requestedMes || undefined,
      })
      setRows(response.data)
      setMeta(response)
    } catch (caught) {
      setError(caught)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load(page, tipo, mes) }, [page, tipo, mes])

  const exportarPdf = async () => {
    setExporting(true)
    try {
      // Traer todas las páginas del corte elegido (máx. 10 páginas = 5000 filas)
      const coleccion: any[] = []
      let pagina = 1
      for (;;) {
        const respuesta = await inventoryService.movements({
          page: pagina,
          tipo,
          mes: mes || undefined,
          per_page: 500,
        })
        coleccion.push(...respuesta.data)
        if (pagina >= (respuesta.last_page ?? 1) || pagina >= 10) break
        pagina += 1
      }

      if (coleccion.length === 0) {
        showToast('No hay movimientos para exportar con ese filtro.', 'error')
        return
      }

      const { jsPDF } = await import('jspdf')
      const autoTable = (await import('jspdf-autotable')).default

      const doc = new jsPDF({ orientation: 'landscape' })
      doc.setFontSize(14)
      doc.text('StockGo — Movimientos de inventario', 14, 15)
      doc.setFontSize(9)
      doc.text(
        `Corte: ${mes ? new Date(`${mes}-01T12:00:00`).toLocaleDateString('es-MX', { month: 'long', year: 'numeric' }) : 'histórico completo'}`
        + `${tipo ? ` · Tipo: ${tipo}` : ''}`
        + ` · ${coleccion.length} registros · Generado ${new Date().toLocaleString('es-MX')}`,
        14, 21,
      )

      autoTable(doc, {
        startY: 26,
        head: [['#', 'Fecha', 'Tipo', 'Producto', 'Cantidad', 'Realizado por', 'Motivo', 'Antes', 'Después']],
        body: coleccion.map((m) => [
          m.id_movimiento,
          formatDate(m.created_at),
          m.tipo,
          m.producto?.nombre ?? `#${m.id_producto}`,
          `${m.tipo === 'ENTRADA' ? '+' : m.tipo === 'SALIDA' ? '−' : '±'}${m.cantidad}`,
          m.usuario?.username ?? m.usuario?.name ?? 'Sistema',
          m.motivo ?? '',
          m.stock_anterior ?? '',
          m.stock_nuevo ?? '',
        ]),
        styles: { fontSize: 8 },
        headStyles: { fillColor: [102, 89, 217] },
      })

      doc.save(`movimientos-${mes || 'completo'}.pdf`)
      showToast('PDF generado correctamente.', 'success')
    } catch (caught: any) {
      showToast(caught.message || 'No fue posible generar el PDF.', 'error')
    } finally {
      setExporting(false)
    }
  }

  const handleSearchChange = async (value: string) => {
    // Invalidar la selección anterior cuando el usuario modifica la búsqueda
    setSelectedProduct(null)
    setForm({ ...form, id_producto: '' })
    setSearchQuery(value)
    if (!value || value.trim().length < 2) {
      setSearchResults([])
      return
    }
    setSearching(true)
    try {
      const response = await productService.list({ search: value.trim(), per_page: 10, page: 1 })
      setSearchResults((response.data || []).map((p: any) => ({
        id_producto: p.id_producto,
        nombre: p.nombre,
        codigo_barras: p.codigo_barras,
        stock_disponible: p.stock_disponible,
        ubicacion: p.ubicacion,
      })))
    } catch {
      setSearchResults([])
    } finally {
      setSearching(false)
    }
  }

  const selectProduct = (product: ProductSelection) => {
    setSelectedProduct(product)
    setSearchQuery(`${product.nombre} (${product.codigo_barras})`)
    setSearchResults([])
    setForm({ ...form, id_producto: String(product.id_producto) })
  }

  const clearProduct = () => {
    setSelectedProduct(null)
    setSearchQuery('')
    setSearchResults([])
    setForm({ ...form, id_producto: '' })
  }

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    setSaving(true)
    try {
      if (!selectedProduct) {
        showToast('Debe seleccionar un producto.', 'error')
        return
      }
      const payload = {
        id_producto: selectedProduct.id_producto,
        cantidad: movementType === 'ajuste' ? Number(form.cantidad) : Math.abs(Number(form.cantidad)),
        motivo: form.motivo || undefined,
      }
      if (movementType === 'entrada') await inventoryService.entrada(payload)
      if (movementType === 'salida') await inventoryService.salida(payload)
      if (movementType === 'ajuste') await inventoryService.ajuste(payload)
      setShow(false)
      setForm({ id_producto: '', cantidad: '', motivo: '' })
      setSelectedProduct(null)
      setSearchQuery('')
      setSearchResults([])
      showToast('Movimiento registrado correctamente.', 'success')
      await load()
    } catch (caught: any) {
      showToast(caught.message, 'error')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="page-stack">
      <PageHeader
        title="Movimientos"
        description="Cada ajuste de stock queda trazado con usuario, motivo y saldo."
        action={<button className="button-primary" onClick={() => setShow(true)}>Registrar movimiento <span>+</span></button>}
      />
      <div className="toolbar">
        <select
          className="filter-select"
          value={tipo}
          onChange={(event) => {
            setTipo(event.target.value)
            setPage(1)
          }}
        >
          <option value="">Todos los tipos</option>
          <option value="ENTRADA">Entradas</option>
          <option value="SALIDA">Salidas</option>
          <option value="AJUSTE">Ajustes</option>
        </select>
        <input
          type="month"
          className="filter-select"
          value={mes}
          onChange={(event) => {
            setMes(event.target.value)
            setPage(1)
          }}
          title="Filtrar por mes (vacío = histórico completo)"
        />
        <button
          type="button"
          className="button-quiet"
          onClick={() => void exportarPdf()}
          disabled={exporting}
        >
          {exporting ? 'Generando…' : 'Descargar PDF'}
        </button>
        <span className="result-count">{meta?.total ?? 0} movimientos</span>
      </div>
      {loading ? (
        <LoadingState text="Cargando movimientos…" />
      ) : error ? (
        <ErrorState error={error} />
      ) : (
        <>
          <DataTable
            rows={rows}
            columns={[
              { key: 'tipo', label: 'Tipo', render: (row) => <StatusBadge estado={row.tipo} /> },
              { key: 'producto', label: 'Producto', render: (row) => row.producto?.nombre || `#${row.id_producto}` },
              { key: 'usuario', label: 'Realizado por', render: (row) => row.usuario?.username || row.usuario?.name || 'Sistema' },
              { key: 'cantidad', label: 'Cantidad' },
              { key: 'motivo', label: 'Motivo' },
              { key: 'stock_anterior', label: 'Antes' },
              { key: 'stock_nuevo', label: 'Después' },
              { key: 'created_at', label: 'Fecha', render: (row) => formatDate(row.created_at) },
            ]}
          />
          {meta && (
            <Pagination
              currentPage={meta.current_page}
              lastPage={meta.last_page}
              total={meta.total}
              from={meta.from}
              to={meta.to}
              onChange={setPage}
            />
          )}
        </>
      )}
      <Modal
        open={show}
        title="Registrar movimiento"
        onClose={() => !saving && setShow(false)}
        busy={saving}
      >
        <form className="form-grid" onSubmit={submit}>
          <div className="movement-switch form-span">
            <button
              type="button"
              className={movementType === 'entrada' ? 'active' : ''}
              onClick={() => setMovementType('entrada')}
            >
              Entrada
            </button>
            <button
              type="button"
              className={movementType === 'salida' ? 'active' : ''}
              onClick={() => setMovementType('salida')}
            >
              Salida
            </button>
            <button
              type="button"
              className={movementType === 'ajuste' ? 'active' : ''}
              onClick={() => setMovementType('ajuste')}
            >
              Ajuste
            </button>
          </div>
          <label>
            Producto
            <div className="product-selector">
              <input
                type="text"
                placeholder="Buscar producto por nombre o código de barras…"
                value={searchQuery}
                onChange={(event) => handleSearchChange(event.target.value)}
                required
              />
              {searching && <span className="searching">Buscando…</span>}
              {searchResults.length > 0 && (
                <ul className="search-results">
                  {searchResults.map((p) => (
                    <li
                      key={p.id_producto}
                      onClick={() => selectProduct(p)}
                    >
                      <div className="search-result-main">
                        <strong>{p.nombre}</strong>
                        <span className="codigo-barras">{p.codigo_barras}</span>
                      </div>
                      <div className="search-result-detail">
                        <span className="stock">Stock: {p.stock_disponible}</span>
                        {p.ubicacion && <span className="ubicacion">{p.ubicacion}</span>}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
              {selectedProduct && (
                <div className="selected-product-card">
                  <div className="selected-product-main">
                    <span className="selected-label">Seleccionado:</span>
                    <strong>{selectedProduct.nombre}</strong>
                    <span className="codigo-barras">{selectedProduct.codigo_barras}</span>
                  </div>
                  <div className="selected-product-detail">
                    <span className="stock">Stock disponible: {selectedProduct.stock_disponible}</span>
                    {selectedProduct.ubicacion && <span className="ubicacion">{selectedProduct.ubicacion}</span>}
                  </div>
                  <button type="button" className="button-secondary" onClick={clearProduct}>
                    Cambiar
                  </button>
                </div>
              )}
              {!selectedProduct && !searchQuery && (
                <span className="placeholder">Seleccione un producto para continuar</span>
              )}
            </div>
          </label>
          <label>
            {movementType === 'ajuste' ? 'Cantidad (+ / −)' : 'Cantidad'}
            <input
              type="number"
              min={movementType === 'ajuste' ? undefined : 1}
              value={form.cantidad}
              onChange={(event) => setForm({ ...form, cantidad: event.target.value })}
              required
              className="quantity-input"
            />
          </label>
          <label className="form-span">
            Motivo
            <input
              value={form.motivo}
              onChange={(event) => setForm({ ...form, motivo: event.target.value })}
              required={movementType === 'ajuste'}
            />
          </label>
          <div className="form-note form-span">
            El servidor valida stock disponible, reservas, transacción y usuario autenticado.
          </div>
          <div className="modal-footer form-span">
            <button
              type="button"
              className="button-secondary"
              onClick={() => setShow(false)}
              disabled={saving}
            >
              Cancelar
            </button>
            <button
              className="button-primary"
              disabled={saving || !selectedProduct}
            >
              {saving ? 'Registrando…' : 'Registrar movimiento'}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
