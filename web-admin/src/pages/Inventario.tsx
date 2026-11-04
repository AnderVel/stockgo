import { useEffect, useState } from 'react'
import { inventoryService, productService, type PageResponse } from '../api/services'
import { DataTable, ErrorState, LoadingState, PageHeader, Pagination, StatusBadge } from '../components/ui'

type BodegaResumen = {
  id: number | null
  nombre: string
  descripcion: string | null
  total_productos: number
  total_unidades: number
  alertas: number
}

export default function Inventario() {
  const [rows, setRows] = useState<any[]>([]); const [query, setQuery] = useState(''); const [page, setPage] = useState(1); const [meta, setMeta] = useState<PageResponse<any> | null>(null); const [loading, setLoading] = useState(true); const [error, setError] = useState<any>(null)
  const [bodegas, setBodegas] = useState<BodegaResumen[]>([])

  useEffect(() => { setLoading(true); productService.list({ page, search: query }).then((response) => { setRows(response.data); setMeta(response) }).catch(setError).finally(() => setLoading(false)) }, [page, query])
  useEffect(() => { void inventoryService.porBodega().then((response) => setBodegas(response.data ?? [])).catch(() => setBodegas([])) }, [])

  return <div className="page-stack">
    <PageHeader title="Inventario" description="Visibilidad del stock físico, reservado y disponible por producto y por bodega." />

    {bodegas.length > 0 && <>
      <h3 className="section-title">Inventario por bodega</h3>
      <div className="bodega-grid">
        {bodegas.map((b) => (
          <div className={`bodega-card${b.id === null ? ' bodega-card-pendiente' : ''}`} key={b.id ?? 'sin-bodega'}>
            <strong>{b.nombre}</strong>
            {b.descripcion && <small>{b.descripcion}</small>}
            <div className="bodega-stats">
              <span><b>{b.total_productos}</b> productos</span>
              <span><b>{b.total_unidades}</b> unidades</span>
              <span className={b.alertas > 0 ? 'bodega-alertas' : ''}><b>{b.alertas}</b> alertas</span>
            </div>
          </div>
        ))}
      </div>
    </>}

    <div className="toolbar"><label className="search-field"><span>⌕</span><input placeholder="Buscar producto o código…" value={query} onChange={(event) => { setQuery(event.target.value); setPage(1) }} /></label><span className="result-count">{meta?.total ?? 0} productos</span></div>
    {loading ? <LoadingState text="Cargando inventario…" /> : error ? <ErrorState error={error} /> : <>
      <DataTable rows={rows} columns={[
        { key: 'nombre', label: 'Producto', render: (row) => <div className="entity-cell"><span className="entity-avatar product-avatar">{row.nombre?.slice(0, 1)}</span><span><strong>{row.nombre}</strong><small>{row.codigo_barras}</small></span></div> },
        { key: 'bodega', label: 'Bodega', render: (row) => row.bodega?.nombre || <span className="cell-muted">Sin asignar</span> },
        { key: 'ubicacion', label: 'Ubicación' },
        { key: 'stock_fisico', label: 'Físico' },
        { key: 'stock_reservado', label: 'Reservado' },
        { key: 'stock_disponible', label: 'Disponible', render: (row) => <strong className={row.stock_disponible <= 5 ? 'stock-warning' : 'stock-ok'}>{row.stock_disponible}</strong> },
        { key: 'estado', label: 'Estado', render: (row) => <StatusBadge estado={row.estado} /> },
      ]} />
      {meta && <Pagination currentPage={meta.current_page} lastPage={meta.last_page} total={meta.total} from={meta.from} to={meta.to} onChange={setPage} />}
    </>}
  </div>
}
