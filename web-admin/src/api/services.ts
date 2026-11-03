import { api } from './client'

export type DashboardSummary = {
  kpis: Record<string, number>
  pedidos_por_estado: Record<string, number>
  movimientos_recientes: any[]
}

export type PageResponse<T> = {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

type ListParams = {
  page?: number
  per_page?: number
  search?: string
  estado?: string
  tipo?: string
}

function listQuery(params: ListParams = {}) {
  const query = new URLSearchParams({
    paginate: '1',
    per_page: String(params.per_page || 25),
    page: String(params.page || 1),
  })

  Object.entries(params).forEach(([key, value]) => {
    if (value && key !== 'page' && key !== 'per_page') query.set(key, String(value))
  })

  return `?${query.toString()}`
}

export const dashboardService = {
  summary: () => api<DashboardSummary>('/dashboard'),
}

export const productService = {
  list: (params: ListParams = {}) => api<PageResponse<any>>(`/productos${listQuery(params)}`),
  get: (id: number) => api(`/productos/${id}`),
  byCode: (code: string) => api(`/productos/codigo/${code}`),
  create: (data: any) => api('/productos', { method: 'POST', body: JSON.stringify(data) }),
  update: (id: number, data: any) => api(`/productos/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  remove: (id: number) => api(`/productos/${id}`, { method: 'DELETE' }),
}

export const inventoryService = {
  movements: (params: ListParams = {}) => api<PageResponse<any>>(`/movimientos${listQuery(params)}`),
  entrada: (data: any) => api('/movimientos/entrada', { method: 'POST', body: JSON.stringify(data) }),
  salida: (data: any) => api('/movimientos/salida', { method: 'POST', body: JSON.stringify(data) }),
  ajuste: (data: any) => api('/movimientos/ajuste', { method: 'POST', body: JSON.stringify(data) }),
}

export const orderService = {
  list: (params: ListParams = {}) => api<PageResponse<any>>(`/pedidos${listQuery(params)}`),
  get: (id: number) => api(`/pedidos/${id}`),
  create: (data: any) => api('/pedidos', { method: 'POST', body: JSON.stringify(data) }),
  cancel: (id: number) => api(`/pedidos/${id}/cancelar`, { method: 'POST' }),
  surtir: (id: number) => api(`/pedidos/${id}/surtir`, { method: 'POST' }),
  entregar: (id: number, correo: string) => api(`/pedidos/${id}/entregar`, { method: 'POST', body: JSON.stringify({ correo }) }),
  recibir: (id: number) => api(`/pedidos/${id}/recibir`, { method: 'POST' }),
}

export const clientService = {
  list: (params: ListParams = {}) => api<PageResponse<any>>(`/clientes${listQuery(params)}`),
  create: (data: any) => api('/clientes', { method: 'POST', body: JSON.stringify(data) }),
  update: (id: number, data: any) => api(`/clientes/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  remove: (id: number) => api(`/clientes/${id}`, { method: 'DELETE' }),
}

export const supplierService = {
  list: (params: ListParams = {}) => api<PageResponse<any>>(`/proveedores${listQuery(params)}`),
  create: (data: any) => api('/proveedores', { method: 'POST', body: JSON.stringify(data) }),
  update: (id: number, data: any) => api(`/proveedores/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  remove: (id: number) => api(`/proveedores/${id}`, { method: 'DELETE' }),
}

export const userService = {
  list: (params: ListParams = {}) => api<PageResponse<any>>(`/usuarios${listQuery(params)}`),
  create: (data: any) => api('/usuarios', { method: 'POST', body: JSON.stringify(data) }),
  update: (id: number, data: any) => api(`/usuarios/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  remove: (id: number) => api(`/usuarios/${id}`, { method: 'DELETE' }),
  reset2fa: (id: number) => api(`/usuarios/${id}/reset-2fa`, { method: 'POST' }),
  setup2fa: (usuario: string, password: string) => api('/auth/2fa/setup', { method: 'POST', body: JSON.stringify({ usuario, password }) }),
  confirm2fa: (usuario: string, password: string, code: string) => api('/auth/2fa/confirm', { method: 'POST', body: JSON.stringify({ usuario, password, code }) }),
}

export const auditService = {
  list: (params: { page?: number; per_page?: number; modulo?: string; accion?: string } = {}) => {
    const query = new URLSearchParams({ per_page: String(params.per_page || 25), page: String(params.page || 1) })
    if (params.modulo) query.set('modulo', params.modulo)
    if (params.accion) query.set('accion', params.accion)
    return api<PageResponse<any>>(`/auditorias?${query.toString()}`)
  },
}

export interface RoleData {
  name: string
  permissions: string[]
  permission_count: number
}

export interface PermissionsData {
  roles: string[]
  permissions_by_module: Record<string, string[]>
  all_permissions: string[]
  roles_config: Record<string, string[]>
}

export const roleService = {
  getPermissions: () => api<PermissionsData>('/roles/permissions'),
  getRoles: () => api<RoleData[]>('/roles'),
}
