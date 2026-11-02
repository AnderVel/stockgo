import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react'
import { api } from '../api/client'
import { clientService, orderService, productService, type PageResponse } from '../api/services'
import { DataTable, EmptyState, ErrorState, formatCurrency, formatDate, LoadingState, PageHeader, Pagination, StatusBadge } from '../components/ui'
import { useToast } from '../components/Toast'
import Modal from '../components/Modal'
import './Pedidos.css'

type ClientePedido = {
  id_cliente: number
  nombre: string
  correo: string | null
  telefono: string | null
  direccion: string | null
  estado: string
}

type ProductoCatalogo = {
  id_producto: number
  nombre: string
  codigo_barras: string | null
  unidad_medida: string | null
  precio: number | string
  stock_disponible: number
  ubicacion: string | null
  estado: string
}

type LineaPedido = {
  id_producto: number
  nombre: string
  codigo_barras: string | null
  unidad_medida: string | null
  precio_unitario: number
  stock_disponible: number
  cantidad: number
}

type DetallePedido = {
  id_detalle_pedido: number
  cantidad: number
  precio_unitario: number | string
  subtotal: number | string
  producto?: ProductoCatalogo
}

type Pedido = {
  id_pedido: number
  id_cliente: number
  fecha_pedido: string | null
  estado: string
  total: number | string
  observaciones: string | null
  cliente?: ClientePedido
  detalles?: DetallePedido[]
}

type PedidoDetalleVista = {
  nombre: string
  codigo: string | null
  unidad_medida: string | null
  cantidad: number
  precio_unitario: number
  subtotal: number
}

const estadosPedido = ['PENDIENTE', 'SURTIDO', 'ENVIADO', 'ENTREGADO', 'CANCELADO']

function rowsFrom<T>(response: PageResponse<T> | T[]): T[] {
  return Array.isArray(response) ? response : response.data
}

function fechaLocal(): string {
  const ahora = new Date()
  const desplazada = new Date(ahora.getTime() - ahora.getTimezoneOffset() * 60_000)

  return desplazada.toISOString().slice(0, 10)
}

function precioEnCentavos(precio: number | string): number {
  return Math.round(Number(precio) * 100)
}

export default function Pedidos() {
  const [rows, setRows] = useState<Pedido[]>([])
  const [estado, setEstado] = useState('')
  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState<PageResponse<Pedido> | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<unknown>(null)
  const [busy, setBusy] = useState<number | null>(null)
  const { showToast } = useToast()

  const [showCreate, setShowCreate] = useState(false)
  const [editingPedidoId, setEditingPedidoId] = useState<number | null>(null)
  const [loadingEdit, setLoadingEdit] = useState(false)
  const [originalQuantities, setOriginalQuantities] = useState<Record<number, number>>({})
  const [pedidoParaCancelar, setPedidoParaCancelar] = useState<Pedido | null>(null)
  const [clientQuery, setClientQuery] = useState('')
  const [clientResults, setClientResults] = useState<ClientePedido[]>([])
  const [clientLoading, setClientLoading] = useState(false)
  const [clientError, setClientError] = useState<string | null>(null)
  const [clientOpen, setClientOpen] = useState(false)
  const [activeClientIndex, setActiveClientIndex] = useState(-1)
  const [selectedClient, setSelectedClient] = useState<ClientePedido | null>(null)

  const [productQuery, setProductQuery] = useState('')
  const [productResults, setProductResults] = useState<ProductoCatalogo[]>([])
  const [productLoading, setProductLoading] = useState(false)
  const [productError, setProductError] = useState<string | null>(null)
  const [productOpen, setProductOpen] = useState(false)
  const [activeProductIndex, setActiveProductIndex] = useState(-1)
  const [selectedProduct, setSelectedProduct] = useState<ProductoCatalogo | null>(null)
  const [draftQuantity, setDraftQuantity] = useState('1')
  const [lines, setLines] = useState<LineaPedido[]>([])
  const [fechaPedido, setFechaPedido] = useState(fechaLocal)
  const [observaciones, setObservaciones] = useState('')
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const [showDetail, setShowDetail] = useState(false)
  const [detailPedido, setDetailPedido] = useState<Pedido | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState<string | null>(null)

  const submitLock = useRef(false)
  const editLock = useRef(false)
  const editRequestId = useRef(0)
  const newOrderButton = useRef<HTMLButtonElement>(null)
  const dialogRef = useRef<HTMLDivElement>(null)
  const closeButton = useRef<HTMLButtonElement>(null)
  const cancelDialogContentRef = useRef<HTMLDivElement>(null)
  const cancelDialogStatusRef = useRef<HTMLParagraphElement>(null)
  const detailDialogContentRef = useRef<HTMLDivElement>(null)
  const clientOpenRef = useRef(false)
  const productOpenRef = useRef(false)

  clientOpenRef.current = clientOpen
  productOpenRef.current = productOpen

  const load = useCallback(async (requestedPage: number, requestedEstado: string) => {
    setError(null)
    setLoading(true)

    try {
      const response = await orderService.list({ page: requestedPage, estado: requestedEstado })

      setRows(response.data)
      setMeta(response)
    } catch (caught) {
      setError(caught)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load(page, estado)
  }, [estado, load, page])

  useEffect(() => {
    if (!showCreate) {
      return
    }

    let isCurrent = true
    const timeout = window.setTimeout(async () => {
      setClientLoading(true)
      setClientError(null)

      try {
        const response = await clientService.list({ search: clientQuery.trim(), per_page: 50 })

        if (isCurrent) {
          setClientResults(rowsFrom(response) as ClientePedido[])
        }
      } catch (caught) {
        if (isCurrent) {
          setClientError(caught instanceof Error ? caught.message : 'No fue posible buscar clientes.')
        }
      } finally {
        if (isCurrent) {
          setClientLoading(false)
        }
      }
    }, 180)

    return () => {
      isCurrent = false
      window.clearTimeout(timeout)
    }
  }, [clientQuery, showCreate])

  useEffect(() => {
    if (!showCreate) {
      return
    }

    let isCurrent = true
    const timeout = window.setTimeout(async () => {
      setProductLoading(true)
      setProductError(null)

      try {
        const response = await productService.list({ search: productQuery.trim(), per_page: 50 })

        if (isCurrent) {
          setProductResults(rowsFrom(response) as ProductoCatalogo[])
        }
      } catch (caught) {
        if (isCurrent) {
          setProductError(caught instanceof Error ? caught.message : 'No fue posible buscar productos.')
        }
      } finally {
        if (isCurrent) {
          setProductLoading(false)
        }
      }
    }, 180)

    return () => {
      isCurrent = false
      window.clearTimeout(timeout)
    }
  }, [productQuery, showCreate])

  useEffect(() => {
    if (!showCreate) {
      return
    }

    const previouslyFocused = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null
    const focusableSelector = 'button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'

    closeButton.current?.focus()

    const handleKeyDown = (event: globalThis.KeyboardEvent) => {
      if (event.key === 'Escape' && !submitting) {
        event.preventDefault()

        if (clientOpenRef.current) {
          setClientOpen(false)
          return
        }

        if (productOpenRef.current) {
          setProductOpen(false)
          return
        }

        closeCreate()
        return
      }

      if (event.key !== 'Tab' || !dialogRef.current) {
        return
      }

      const focusable = Array.from(dialogRef.current.querySelectorAll<HTMLElement>(focusableSelector))
        .filter((element) => element.getClientRects().length > 0)
      const first = focusable[0]
      const last = focusable[focusable.length - 1]

      if (!first || !last) {
        return
      }

      if (!dialogRef.current.contains(document.activeElement)) {
        event.preventDefault()
        first.focus()
        return
      }

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      previouslyFocused?.focus()
    }
  }, [showCreate, submitting, loadingEdit])

  useEffect(() => {
    if (!pedidoParaCancelar) {
      return
    }

    const previouslyFocused = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null
    const dialog = cancelDialogContentRef.current?.closest<HTMLElement>('[role="dialog"]')

    if (!dialog) {
      return
    }

    const focusableSelector = 'button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'

    dialog.querySelector<HTMLElement>('.button-secondary')?.focus()

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Tab') {
        return
      }

      const focusable = Array.from(dialog.querySelectorAll<HTMLElement>(focusableSelector))
        .filter((element) => element.getClientRects().length > 0)
      const first = focusable[0]
      const last = focusable[focusable.length - 1]

      if (!first || !last) {
        return
      }

      if (!dialog.contains(document.activeElement)) {
        event.preventDefault()
        first.focus()
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('keydown', handleKeyDown)

      if (previouslyFocused?.isConnected) {
        previouslyFocused.focus()
      } else {
        newOrderButton.current?.focus()
      }
    }
  }, [pedidoParaCancelar])

  useEffect(() => {
    if (!pedidoParaCancelar) {
      return
    }

    const isCancelling = busy === pedidoParaCancelar.id_pedido
    const dialog = (isCancelling ? cancelDialogStatusRef.current : cancelDialogContentRef.current)
      ?.closest<HTMLElement>('[role="dialog"]')

    if (isCancelling) {
      dialog?.querySelector<HTMLElement>('[role="status"]')?.focus()
    } else {
      dialog?.querySelector<HTMLElement>('.button-secondary')?.focus()
    }
  }, [busy, pedidoParaCancelar])

  useEffect(() => {
    if (!showDetail) {
      return
    }

    const previouslyFocused = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null
    const dialog = detailDialogContentRef.current?.closest<HTMLElement>('[role="dialog"]')

    if (!dialog) {
      return
    }

    const focusableSelector = 'button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])' as string

    (dialog.querySelector<HTMLElement>('.icon-button:not([disabled])') ?? detailDialogContentRef.current)?.focus()

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Tab') {
        return
      }

      const focusable = Array.from(dialog.querySelectorAll<HTMLElement>(focusableSelector))
        .filter((element) => element.getClientRects().length > 0)
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      const activeElement = document.activeElement

      if (!first || !last) {
        event.preventDefault()
        detailDialogContentRef.current?.focus()
      } else if (!focusable.includes(activeElement as HTMLElement)) {
        event.preventDefault()
        ;(event.shiftKey ? last : first).focus()
      } else if (event.shiftKey && activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('keydown', handleKeyDown)

      if (previouslyFocused?.isConnected) {
        previouslyFocused.focus()
      } else {
        newOrderButton.current?.focus()
      }
    }
  }, [showDetail])

  const orderTotalCentavos = useMemo(
    () => lines.reduce((total, line) => total + precioEnCentavos(line.precio_unitario) * line.cantidad, 0),
    [lines],
  )

  const invalidLine = lines.some((line) => (
    !Number.isInteger(line.cantidad)
    || line.cantidad < 1
    || line.cantidad > line.stock_disponible
  ))

  const availableForProduct = (product: ProductoCatalogo): number => (
    Number(product.stock_disponible)
    + (editingPedidoId ? originalQuantities[product.id_producto] ?? 0 : 0)
  )

  const visibleProducts = productResults.filter((product) => (
    product.estado === 'ACTIVO'
    && (Number(product.stock_disponible) > 0 || (originalQuantities[product.id_producto] ?? 0) > 0)
  ))

  const act = async (id: number, action: () => Promise<unknown>, message: string): Promise<boolean> => {
    setBusy(id)

    try {
      await action()
      showToast(message, 'success')
      await load(page, estado)

      return true
    } catch (caught) {
      showToast(caught instanceof Error ? caught.message : 'No fue posible actualizar el pedido.', 'error')

      return false
    } finally {
      setBusy(null)
    }
  }

  const confirmCancellation = async () => {
    if (!pedidoParaCancelar) {
      return
    }

    const wasCancelled = await act(
      pedidoParaCancelar.id_pedido,
      () => orderService.cancel(pedidoParaCancelar.id_pedido),
      'Pedido cancelado y reserva liberada.',
    )

    if (wasCancelled) {
      setPedidoParaCancelar(null)
    }
  }

  const openCreate = () => {
    setEditingPedidoId(null)
    setLoadingEdit(false)
    setOriginalQuantities({})
    setClientQuery('')
    setClientResults([])
    setClientError(null)
    setClientOpen(false)
    setSelectedClient(null)
    setProductQuery('')
    setProductResults([])
    setProductError(null)
    setProductOpen(false)
    setSelectedProduct(null)
    setDraftQuantity('1')
    setLines([])
    setFechaPedido(fechaLocal())
    setObservaciones('')
    setFormError(null)
    setShowCreate(true)
  }

  const openEdit = async (pedidoId: number) => {
    if (editLock.current) {
      return
    }

    const requestId = editRequestId.current + 1

    editRequestId.current = requestId
    editLock.current = true
    setEditingPedidoId(pedidoId)
    setLoadingEdit(true)
    setShowCreate(true)
    setClientQuery('')
    setClientResults([])
    setClientError(null)
    setClientOpen(false)
    setSelectedClient(null)
    setProductQuery('')
    setProductResults([])
    setProductError(null)
    setProductOpen(false)
    setSelectedProduct(null)
    setDraftQuantity('1')
    setLines([])
    setOriginalQuantities({})
    setFormError(null)

    try {
      const pedido = await orderService.get(pedidoId) as Pedido

      if (editRequestId.current !== requestId) {
        return
      }

      if (!pedido.cliente || !pedido.detalles?.length) {
        throw new Error('El pedido no tiene los datos necesarios para editarse.')
      }

      const quantities: Record<number, number> = {}
      const orderLines = pedido.detalles.map((detail) => {
        const product = detail.producto

        if (!product) {
          throw new Error('No fue posible cargar uno de los productos del pedido.')
        }

        const quantity = Number(detail.cantidad)

        quantities[product.id_producto] = quantity

        return {
          id_producto: product.id_producto,
          nombre: product.nombre,
          codigo_barras: product.codigo_barras,
          unidad_medida: product.unidad_medida,
          precio_unitario: Number(product.precio),
          stock_disponible: Number(product.stock_disponible) + quantity,
          cantidad: quantity,
        }
      })

      setSelectedClient(pedido.cliente)
      setClientQuery(pedido.cliente.nombre)
      setLines(orderLines)
      setOriginalQuantities(quantities)
      setFechaPedido(pedido.fecha_pedido ?? fechaLocal())
      setObservaciones(pedido.observaciones ?? '')
    } catch (caught) {
      if (editRequestId.current === requestId) {
        setShowCreate(false)
        setEditingPedidoId(null)
        showToast(caught instanceof Error ? caught.message : 'No fue posible cargar el pedido para editarlo.', 'error')
      }
    } finally {
      if (editRequestId.current === requestId) {
        setLoadingEdit(false)
        editLock.current = false
      }
    }
  }

  function closeCreate() {
    if (submitLock.current) {
      return
    }

    editRequestId.current += 1
    editLock.current = false
    setShowCreate(false)
    setEditingPedidoId(null)
    setLoadingEdit(false)
    setOriginalQuantities({})
    setFormError(null)
    setSelectedClient(null)
    setSelectedProduct(null)
    setLines([])
    newOrderButton.current?.focus()
  }

  const chooseClient = (client: ClientePedido) => {
    setSelectedClient(client)
    setClientQuery(client.nombre)
    setClientOpen(false)
    setActiveClientIndex(-1)
    setFormError(null)
  }

  const chooseProduct = (product: ProductoCatalogo) => {
    setSelectedProduct(product)
    setProductQuery(product.nombre)
    setProductOpen(false)
    setActiveProductIndex(-1)
    setDraftQuantity('1')
    setFormError(null)
  }

  const addProduct = () => {
    if (!selectedProduct) {
      setFormError('Busca y selecciona un producto antes de agregarlo.')
      return
    }

    const quantity = Number(draftQuantity)
    const existing = lines.find((line) => line.id_producto === selectedProduct.id_producto)
    const alreadyAdded = existing?.cantidad ?? 0
    const availableForLine = availableForProduct(selectedProduct)

    if (!Number.isInteger(quantity) || quantity < 1) {
      setFormError('La cantidad debe ser un número entero mayor que cero.')
      return
    }

    if (alreadyAdded + quantity > availableForLine) {
      setFormError(`La cantidad supera el disponible. Puedes agregar hasta ${Math.max(0, availableForLine - alreadyAdded)} unidades más.`)
      return
    }

    setLines((current) => {
      const currentLine = current.find((line) => line.id_producto === selectedProduct.id_producto)

      if (currentLine) {
        return current.map((line) => line.id_producto === selectedProduct.id_producto
          ? { ...line, stock_disponible: availableForLine, cantidad: line.cantidad + quantity }
          : line)
      }

      return [...current, {
        id_producto: selectedProduct.id_producto,
        nombre: selectedProduct.nombre,
        codigo_barras: selectedProduct.codigo_barras,
        unidad_medida: selectedProduct.unidad_medida,
        precio_unitario: Number(selectedProduct.precio),
        stock_disponible: availableForLine,
        cantidad: quantity,
      }]
    })

    setSelectedProduct(null)
    setProductQuery('')
    setDraftQuantity('1')
    setProductOpen(false)
    setFormError(null)
  }

  const updateQuantity = (productId: number, value: string) => {
    const quantity = value === '' ? 0 : Number(value)

    setLines((current) => current.map((line) => line.id_producto === productId
      ? { ...line, cantidad: quantity }
      : line))
    setFormError(null)
  }

  const removeLine = (productId: number) => {
    setLines((current) => current.filter((line) => line.id_producto !== productId))
    setFormError(null)
  }

  const submitOrder = async () => {
    if (submitLock.current) {
      return
    }

    if (!selectedClient) {
      setFormError('Selecciona un cliente para continuar.')
      return
    }

    if (!fechaPedido) {
      setFormError('Indica la fecha del pedido.')
      return
    }

    if (lines.length === 0) {
      setFormError('Agrega al menos un producto al pedido.')
      return
    }

    if (invalidLine) {
      setFormError('Revisa las cantidades: deben ser enteras y no superar el stock disponible.')
      return
    }

    submitLock.current = true
    setSubmitting(true)
    setFormError(null)

    try {
      const payload = {
        id_cliente: selectedClient.id_cliente,
        fecha_pedido: fechaPedido,
        observaciones: observaciones.trim() || null,
        detalles: lines.map((line) => ({
          id_producto: line.id_producto,
          cantidad: line.cantidad,
        })),
      }

      if (editingPedidoId) {
        await api(`/pedidos/${editingPedidoId}`, {
          method: 'PUT',
          body: JSON.stringify(payload),
        })
      } else {
        await orderService.create(payload)
      }

      setShowCreate(false)
      setEditingPedidoId(null)
      setOriginalQuantities({})
      showToast(editingPedidoId ? 'Pedido actualizado correctamente.' : 'Pedido creado correctamente.', 'success')
      await load(page, estado)
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : 'No fue posible crear el pedido.'

      setFormError(message)
    } finally {
      submitLock.current = false
      setSubmitting(false)
    }
  }

  const viewDetail = async (id: number) => {
    setShowDetail(true)
    setDetailLoading(true)
    setDetailError(null)
    setDetailPedido(null)

    try {
      const pedido = await orderService.get(id) as Pedido

      setDetailPedido(pedido)
    } catch (caught) {
      setDetailError(caught instanceof Error ? caught.message : 'No fue posible cargar el detalle.')
    } finally {
      setDetailLoading(false)
    }
  }

  const handleClientKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown' && clientResults.length > 0) {
      event.preventDefault()
      setClientOpen(true)
      setActiveClientIndex((index) => Math.min(index + 1, clientResults.length - 1))
    } else if (event.key === 'ArrowUp' && clientResults.length > 0) {
      event.preventDefault()
      setClientOpen(true)
      setActiveClientIndex((index) => index <= 0 ? clientResults.length - 1 : index - 1)
    } else if (event.key === 'Enter' && clientOpen && activeClientIndex >= 0 && clientResults[activeClientIndex]) {
      event.preventDefault()
      chooseClient(clientResults[activeClientIndex])
    } else if (event.key === 'Escape') {
      setClientOpen(false)
    }
  }

  const handleProductKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown' && visibleProducts.length > 0) {
      event.preventDefault()
      setProductOpen(true)
      setActiveProductIndex((index) => Math.min(index + 1, visibleProducts.length - 1))
    } else if (event.key === 'ArrowUp' && visibleProducts.length > 0) {
      event.preventDefault()
      setProductOpen(true)
      setActiveProductIndex((index) => index <= 0 ? visibleProducts.length - 1 : index - 1)
    } else if (event.key === 'Enter' && productOpen && activeProductIndex >= 0 && visibleProducts[activeProductIndex]) {
      event.preventDefault()
      chooseProduct(visibleProducts[activeProductIndex])
    } else if (event.key === 'Escape') {
      setProductOpen(false)
    }
  }

  return (
    <div className="page-stack orders-page">
      <PageHeader
        title="Pedidos"
        description="Prepara pedidos, reserva existencias y sigue cada entrega desde un solo lugar."
        action={(
          <button ref={newOrderButton} type="button" className="button-primary" onClick={openCreate}>
            Nuevo pedido <span aria-hidden="true">+</span>
          </button>
        )}
      />

      <div className="toolbar orders-toolbar">
        <label className="orders-filter">
          <span>Estado del pedido</span>
          <select
            className="filter-select"
            value={estado}
            onChange={(event) => {
              setEstado(event.target.value)
              setPage(1)
            }}
          >
            <option value="">Todos los estados</option>
            {estadosPedido.map((value) => <option key={value} value={value}>{value}</option>)}
          </select>
        </label>
        <span className="result-count">{meta?.total ?? 0} pedidos</span>
      </div>

      {loading ? <LoadingState text="Cargando pedidos…" /> : error ? <ErrorState error={error} /> : (
        <>
          <DataTable
            rows={rows}
            columns={[
              {
                key: 'id_pedido',
                label: 'Pedido',
                render: (row: Pedido) => <strong>#{row.id_pedido}</strong>,
              },
              {
                key: 'cliente',
                label: 'Cliente',
                render: (row: Pedido) => row.cliente?.nombre || `#${row.id_cliente}`,
              },
              { key: 'fecha_pedido', label: 'Fecha', render: (row: Pedido) => formatDate(row.fecha_pedido) },
              { key: 'total', label: 'Total', render: (row: Pedido) => formatCurrency(row.total) },
              { key: 'estado', label: 'Estado', render: (row: Pedido) => <StatusBadge estado={row.estado} /> },
              {
                key: 'actions',
                label: 'Acciones',
                render: (row: Pedido) => (
                  <div className="table-actions">
                    <button type="button" className="button-quiet" onClick={() => void viewDetail(row.id_pedido)}>
                      Ver detalles
                    </button>
                    {row.estado === 'PENDIENTE' && (
                      <>
                        <button
                          type="button"
                          className="button-quiet"
                          disabled={busy === row.id_pedido || loadingEdit}
                          onClick={() => void openEdit(row.id_pedido)}
                        >
                          Editar
                        </button>
                        <button
                          type="button"
                          className="button-quiet"
                          disabled={busy === row.id_pedido}
                          onClick={() => void act(row.id_pedido, () => orderService.surtir(row.id_pedido), 'Pedido surtido.')}
                        >
                          Surtir
                        </button>
                        <button
                          type="button"
                          className="button-danger-quiet"
                          disabled={busy === row.id_pedido}
                          onClick={() => setPedidoParaCancelar(row)}
                        >
                          Cancelar
                        </button>
                      </>
                    )}
                    {row.estado === 'SURTIDO' && (
                      <button
                        type="button"
                        className="button-quiet"
                        disabled={busy === row.id_pedido}
                        onClick={() => void act(row.id_pedido, () => orderService.entregar(row.id_pedido), 'Pedido enviado.')}
                      >
                        Enviar
                      </button>
                    )}
                    {row.estado === 'ENVIADO' && (
                      <button
                        type="button"
                        className="button-quiet"
                        disabled={busy === row.id_pedido}
                        onClick={() => void act(row.id_pedido, () => orderService.recibir(row.id_pedido), 'Pedido recibido.')}
                      >
                        Recibir
                      </button>
                    )}
                  </div>
                ),
              },
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

      {showCreate && (
        <div
          className="pedido-dialog-backdrop"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget && !submitting) {
              closeCreate()
            }
          }}
        >
          <section
            ref={dialogRef}
            className="pedido-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="pedido-dialog-title"
            aria-describedby="pedido-dialog-description"
          >
            <header className="pedido-dialog-header">
              <div>
                <p className="pedido-dialog-kicker">{editingPedidoId ? 'Actualizar registro' : 'Nuevo registro'}</p>
                <h2 id="pedido-dialog-title">{editingPedidoId ? `Editar pedido #${editingPedidoId}` : 'Crear pedido'}</h2>
                <p id="pedido-dialog-description">
                  {editingPedidoId
                    ? 'Ajusta las cantidades o los productos del pedido pendiente.'
                    : 'Selecciona a quién se enviará y agrega los productos que necesitas reservar.'}
                </p>
              </div>
              <button
                ref={closeButton}
                type="button"
                className="icon-button pedido-close"
                onClick={closeCreate}
                disabled={submitting}
                aria-label={editingPedidoId ? 'Cerrar edición de pedido' : 'Cerrar creación de pedido'}
              >
                ×
              </button>
            </header>

            <div className="pedido-dialog-content" aria-busy={loadingEdit}>
              {loadingEdit ? (
                <div className="pedido-edit-loading">
                  <LoadingState text="Cargando pedido para editar…" />
                </div>
              ) : (
                <>
              <div className="pedido-form-column">
                <section className="pedido-form-section" aria-labelledby="pedido-client-title">
                  <div className="pedido-section-heading">
                    <span className="pedido-section-mark" aria-hidden="true">01</span>
                    <div>
                      <h3 id="pedido-client-title">Cliente</h3>
                      <p>Elige la cuenta que recibirá este pedido.</p>
                    </div>
                  </div>

                  <div className="pedido-combobox-wrap">
                    <label htmlFor="pedido-client-search">Buscar cliente</label>
                    <input
                      id="pedido-client-search"
                      type="search"
                      role="combobox"
                      aria-autocomplete="list"
                      aria-expanded={clientOpen && clientResults.length > 0}
                      aria-controls="pedido-client-results"
                      aria-activedescendant={clientOpen && activeClientIndex >= 0 && clientResults[activeClientIndex] ? `pedido-client-option-${activeClientIndex}` : undefined}
                      placeholder="Nombre, correo o teléfono"
                      value={clientQuery}
                      onFocus={() => setClientOpen(true)}
                      onChange={(event) => {
                        setClientQuery(event.target.value)
                        setSelectedClient(null)
                        setClientOpen(true)
                        setActiveClientIndex(-1)
                      }}
                      onKeyDown={handleClientKeyDown}
                      autoComplete="off"
                    />
                    {clientOpen && clientResults.length > 0 && !selectedClient && (
                      <ul id="pedido-client-results" className="pedido-suggestions" role="listbox" aria-label="Clientes encontrados">
                        {clientResults.map((client, index) => (
                          <li
                            id={`pedido-client-option-${index}`}
                            key={client.id_cliente}
                            role="option"
                            aria-selected={activeClientIndex >= 0 && index === activeClientIndex}
                          >
                            <button type="button" onClick={() => chooseClient(client)}>
                              <strong>{client.nombre}</strong>
                              <span>{client.correo || client.telefono || 'Sin datos de contacto'}</span>
                            </button>
                          </li>
                        ))}
                      </ul>
                    )}
                    {clientLoading && <p className="pedido-field-note">Buscando clientes…</p>}
                    {!clientLoading && !clientError && clientOpen && clientQuery.trim() && clientResults.length === 0 && (
                      <p className="pedido-field-note">No encontramos clientes con esos datos.</p>
                    )}
                    {clientError && <p className="pedido-inline-error" role="alert">{clientError}</p>}
                  </div>

                  {selectedClient && (
                    <div className="pedido-client-summary">
                      <div className="pedido-client-avatar" aria-hidden="true">{selectedClient.nombre.slice(0, 1)}</div>
                      <div className="pedido-client-copy">
                        <strong>{selectedClient.nombre}</strong>
                        <span>{selectedClient.correo || 'Correo no registrado'}</span>
                        <span>{selectedClient.telefono || 'Teléfono no registrado'}{selectedClient.direccion ? ` · ${selectedClient.direccion}` : ''}</span>
                      </div>
                      <button
                        type="button"
                        className="button-quiet pedido-change-client"
                        onClick={() => {
                          setSelectedClient(null)
                          setClientQuery('')
                          setClientOpen(true)
                        }}
                      >
                        Cambiar
                      </button>
                    </div>
                  )}
                </section>

                <section className="pedido-form-section pedido-products-section" aria-labelledby="pedido-products-title">
                  <div className="pedido-section-heading">
                    <span className="pedido-section-mark pedido-section-mark-products" aria-hidden="true">02</span>
                    <div>
                      <h3 id="pedido-products-title">Productos</h3>
                      <p>Las existencias se reservan al confirmar el pedido.</p>
                    </div>
                  </div>

                  <div className="pedido-product-picker">
                    <div className="pedido-combobox-wrap">
                      <label htmlFor="pedido-product-search">Buscar producto</label>
                      <input
                        id="pedido-product-search"
                        type="search"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded={productOpen && visibleProducts.length > 0}
                        aria-controls="pedido-product-results"
                        aria-activedescendant={productOpen && activeProductIndex >= 0 && visibleProducts[activeProductIndex] ? `pedido-product-option-${activeProductIndex}` : undefined}
                        placeholder="Nombre o código de barras"
                        value={productQuery}
                        onFocus={() => setProductOpen(true)}
                        onChange={(event) => {
                          setProductQuery(event.target.value)
                          setSelectedProduct(null)
                          setProductOpen(true)
                          setActiveProductIndex(-1)
                        }}
                        onKeyDown={handleProductKeyDown}
                        autoComplete="off"
                      />
                      {productOpen && visibleProducts.length > 0 && !selectedProduct && (
                        <ul id="pedido-product-results" className="pedido-suggestions pedido-product-results" role="listbox" aria-label="Productos disponibles">
                          {visibleProducts.map((product, index) => (
                            <li
                              id={`pedido-product-option-${index}`}
                              key={product.id_producto}
                              role="option"
                              aria-selected={activeProductIndex >= 0 && index === activeProductIndex}
                            >
                              <button type="button" onClick={() => chooseProduct(product)}>
                                <span className="pedido-option-main">
                                  <strong>{product.nombre}</strong>
                                  <span>{formatCurrency(product.precio)}</span>
                                </span>
                                <span className="pedido-option-meta">
                                  <span>{product.codigo_barras || 'Sin código'}</span>
                          <span>{availableForProduct(product)} disponibles para este pedido · {product.unidad_medida || 'unidad'}</span>
                                </span>
                              </button>
                            </li>
                          ))}
                        </ul>
                      )}
                      {productLoading && <p className="pedido-field-note">Buscando productos…</p>}
                      {!productLoading && !productError && productOpen && productQuery.trim() && visibleProducts.length === 0 && (
                        <p className="pedido-field-note">No hay productos activos con existencias que coincidan.</p>
                      )}
                      {productError && <p className="pedido-inline-error" role="alert">{productError}</p>}
                    </div>

                    {selectedProduct && (
                      <div className="pedido-selected-product">
                        <div>
                          <strong>{selectedProduct.nombre}</strong>
                          <span>{selectedProduct.codigo_barras || 'Sin código'} · {availableForProduct(selectedProduct)} disponibles</span>
                        </div>
                        <strong className="pedido-current-price">{formatCurrency(selectedProduct.precio)}</strong>
                      </div>
                    )}

                    <div className="pedido-add-row">
                      <label htmlFor="pedido-draft-quantity">
                        Cantidad
                        <input
                          id="pedido-draft-quantity"
                          type="number"
                          inputMode="numeric"
                          min="1"
                          max={selectedProduct ? availableForProduct(selectedProduct) : undefined}
                          step="1"
                          value={draftQuantity}
                          onChange={(event) => setDraftQuantity(event.target.value)}
                          disabled={!selectedProduct || submitting}
                        />
                      </label>
                      <button
                        type="button"
                        className="button-secondary pedido-add-button"
                        onClick={addProduct}
                        disabled={!selectedProduct || submitting}
                      >
                        Agregar producto <span aria-hidden="true">+</span>
                      </button>
                    </div>
                  </div>

                  <div className="pedido-lines-heading">
                    <h4>Conceptos del pedido</h4>
                    <span>{lines.length} {lines.length === 1 ? 'producto' : 'productos'}</span>
                  </div>

                  {lines.length > 0 ? (
                    <div className="pedido-lines-table-wrap">
                      <table className="pedido-lines-table">
                        <thead>
                          <tr>
                            <th scope="col">Producto</th>
                            <th scope="col">Cantidad</th>
                            <th scope="col">Precio</th>
                            <th scope="col">Subtotal</th>
                            <th scope="col"><span className="sr-only">Acciones</span></th>
                          </tr>
                        </thead>
                        <tbody>
                          {lines.map((line) => {
                            const subtotal = precioEnCentavos(line.precio_unitario) * line.cantidad

                            return (
                              <tr key={line.id_producto}>
                                <td>
                                  <span className="pedido-line-product">
                                    <strong>{line.nombre}</strong>
                                    <small>{line.codigo_barras || 'Sin código'} · {line.unidad_medida || 'unidad'}</small>
                                  </span>
                                </td>
                                <td>
                                  <label className="sr-only" htmlFor={`pedido-quantity-${line.id_producto}`}>
                                    Cantidad de {line.nombre}
                                  </label>
                                  <input
                                    id={`pedido-quantity-${line.id_producto}`}
                                    className="pedido-line-quantity"
                                    type="number"
                                    inputMode="numeric"
                                    min="1"
                                    max={line.stock_disponible}
                                    step="1"
                                    value={line.cantidad || ''}
                                    onChange={(event) => updateQuantity(line.id_producto, event.target.value)}
                                    disabled={submitting}
                                    aria-invalid={line.cantidad < 1 || line.cantidad > line.stock_disponible}
                                  />
                                </td>
                                <td>{formatCurrency(line.precio_unitario)}</td>
                                <td><strong>{formatCurrency(subtotal / 100)}</strong></td>
                                <td>
                                  <button
                                    type="button"
                                    className="pedido-remove-line"
                                    onClick={() => removeLine(line.id_producto)}
                                    disabled={submitting}
                                    aria-label={`Quitar ${line.nombre} del pedido`}
                                  >
                                    Quitar
                                  </button>
                                </td>
                              </tr>
                            )
                          })}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <EmptyState text="Aún no agregas productos. Busca un producto para comenzar." />
                  )}
                  {invalidLine && !formError && (
                    <p className="pedido-inline-error" role="status">
                      Ajusta las cantidades para que sean enteras y no superen el stock disponible.
                    </p>
                  )}

                  <label className="pedido-observations" htmlFor="pedido-observations">
                    Observaciones <span>Opcional</span>
                    <textarea
                      id="pedido-observations"
                      rows={3}
                      maxLength={5000}
                      value={observaciones}
                      onChange={(event) => setObservaciones(event.target.value)}
                      placeholder="Indicaciones para preparar o entregar el pedido"
                      disabled={submitting}
                    />
                  </label>
                </section>
              </div>

              <aside className="pedido-summary" aria-label="Resumen del pedido">
                <div className="pedido-summary-heading">
                  <div className="pedido-summary-icon" aria-hidden="true">≡</div>
                  <div>
                    <h3>Resumen</h3>
                    <p>Los precios corresponden al catálogo actual.</p>
                  </div>
                </div>

                <label className="pedido-date-field" htmlFor="pedido-date">
                  Fecha del pedido
                  <input
                    id="pedido-date"
                    type="date"
                    value={fechaPedido}
                    onChange={(event) => setFechaPedido(event.target.value)}
                    disabled={submitting}
                    required
                  />
                </label>

                <div className="pedido-summary-divider" />
                <div className="pedido-summary-count">
                  <span>Productos</span>
                  <strong>{lines.reduce((quantity, line) => quantity + (line.cantidad > 0 ? line.cantidad : 0), 0)}</strong>
                </div>
                <div className="pedido-summary-total">
                  <span>Total estimado</span>
                  <strong>{formatCurrency(orderTotalCentavos / 100)}</strong>
                </div>
                <p className="pedido-reservation-note">
                  Al guardar, el sistema comprobará el stock disponible y reservará las cantidades del pedido.
                </p>

                {formError && <p className="pedido-inline-error pedido-submit-error" role="alert">{formError}</p>}

                <div className="pedido-dialog-actions">
                  <button type="button" className="button-secondary" onClick={closeCreate} disabled={submitting}>
                    Cancelar
                  </button>
                  <button
                    type="button"
                    className="button-primary"
                    onClick={() => void submitOrder()}
                    disabled={submitting}
                  >
                    {submitting
                      ? (editingPedidoId ? 'Guardando cambios…' : 'Guardando pedido…')
                      : (editingPedidoId ? 'Guardar cambios' : 'Crear pedido')}
                  </button>
                </div>
              </aside>
                </>
              )}
            </div>
          </section>
        </div>
      )}

      <Modal
        open={!!pedidoParaCancelar}
        title={pedidoParaCancelar ? `Cancelar pedido #${pedidoParaCancelar.id_pedido}` : 'Cancelar pedido'}
        onClose={() => {
          if (busy !== pedidoParaCancelar?.id_pedido) {
            setPedidoParaCancelar(null)
          }
        }}
        busy={busy === pedidoParaCancelar?.id_pedido}
      >
        {busy === pedidoParaCancelar?.id_pedido ? (
          <p ref={cancelDialogStatusRef} className="confirm-copy" role="status" tabIndex={0}>
            Cancelando pedido y liberando la reserva…
          </p>
        ) : (
          <>
            <div ref={cancelDialogContentRef} className="confirm-copy">
              <p>¿Cancelar este pedido?</p>
              <small>Se liberará la reserva de existencias. Esta acción no se puede deshacer.</small>
            </div>
            <div className="modal-footer">
              <button
                type="button"
                className="button-secondary"
                onClick={() => setPedidoParaCancelar(null)}
              >
                Volver
              </button>
              <button
                type="button"
                className="button-danger"
                onClick={() => void confirmCancellation()}
              >
                Confirmar cancelación
              </button>
            </div>
          </>
        )}
      </Modal>

      <Modal
        open={showDetail}
        title={detailPedido ? `Pedido #${detailPedido.id_pedido}` : 'Detalle del pedido'}
        onClose={() => setShowDetail(false)}
        busy={detailLoading}
      >
        {detailLoading ? <LoadingState text="Cargando detalle del pedido…" /> : detailError ? (
          <ErrorState error={new Error(detailError)} />
        ) : detailPedido ? (
          <OrderDetail pedido={detailPedido} />
        ) : null}
      </Modal>
    </div>
  )
}

function OrderDetail({ pedido }: { pedido: Pedido }) {
  const products: PedidoDetalleVista[] = (pedido.detalles ?? []).map((detail) => ({
    nombre: detail.producto?.nombre ?? 'Producto no disponible',
    codigo: detail.producto?.codigo_barras ?? null,
    unidad_medida: detail.producto?.unidad_medida ?? null,
    cantidad: detail.cantidad,
    precio_unitario: Number(detail.precio_unitario),
    subtotal: Number(detail.subtotal),
  }))

  return (
    <div className="pedido-detail">
      <dl className="pedido-detail-facts">
        <div><dt>Cliente</dt><dd>{pedido.cliente?.nombre || `#${pedido.id_cliente}`}</dd></div>
        <div><dt>Fecha</dt><dd>{formatDate(pedido.fecha_pedido)}</dd></div>
        <div><dt>Estado</dt><dd><StatusBadge estado={pedido.estado} /></dd></div>
        <div><dt>Total</dt><dd>{formatCurrency(pedido.total)}</dd></div>
      </dl>
      <div className="pedido-detail-products">
        <h4>Productos incluidos</h4>
        {products.length === 0 ? <p>No hay productos registrados en este pedido.</p> : (
          <div className="pedido-detail-table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col">Código</th>
                  <th scope="col">Producto</th>
                  <th scope="col">Unidad</th>
                  <th scope="col">Cantidad</th>
                  <th scope="col">Precio unitario</th>
                  <th scope="col">Subtotal</th>
                </tr>
              </thead>
              <tbody>
                {products.map((product, index) => (
                  <tr key={`${product.codigo ?? product.nombre}-${index}`}>
                    <td>{product.codigo || '—'}</td>
                    <td>{product.nombre}</td>
                    <td>{product.unidad_medida || '—'}</td>
                    <td>{product.cantidad}</td>
                    <td>{formatCurrency(product.precio_unitario)}</td>
                    <td>{formatCurrency(product.subtotal)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
      {pedido.observaciones && (
        <div className="pedido-detail-observations">
          <strong>Observaciones</strong>
          <p>{pedido.observaciones}</p>
        </div>
      )}
    </div>
  )
}
