import { api } from './client'

export const productService = {
  list: () => api<any[]>('/productos'),
  get: (id: number) => api(`/productos/${id}`),
  byCode: (code: string) => api(`/productos/codigo/${code}`),
  create: (data: any) => api('/productos', { method: 'POST', body: JSON.stringify(data) }),
  update: (id: number, data: any) => api(`/productos/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  remove: (id: number) => api(`/productos/${id}`, { method: 'DELETE' }),
}

export const inventoryService = {
  movements: () => api<any[]>('/movimientos'),
  entrada: (data: any) => api('/movimientos/entrada', { method: 'POST', body: JSON.stringify(data) }),
  salida: (data: any) => api('/movimientos/salida', { method: 'POST', body: JSON.stringify(data) }),
  ajuste: (data: any) => api('/movimientos/ajuste', { method: 'POST', body: JSON.stringify(data) }),
}

export const orderService = {
  list: () => api<any[]>('/pedidos'),
  get: (id: number) => api(`/pedidos/${id}`),
  create: (data: any) => api('/pedidos', { method: 'POST', body: JSON.stringify(data) }),
  cancel: (id: number) => api(`/pedidos/${id}/cancelar`, { method: 'POST' }),
  surtir: (id: number) => api(`/pedidos/${id}/surtir`, { method: 'POST' }),
  entregar: (id: number) => api(`/pedidos/${id}/entregar`, { method: 'POST' }),
  recibir: (id: number) => api(`/pedidos/${id}/recibir`, { method: 'POST' }),
}

export const clientService = {
  list: () => api<any[]>('/clientes'),
  create: (data: any) => api('/clientes', { method: 'POST', body: JSON.stringify(data) }),
  update: (id: number, data: any) => api(`/clientes/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  remove: (id: number) => api(`/clientes/${id}`, { method: 'DELETE' }),
}

export const supplierService = {
  list: () => api<any[]>('/proveedores'),
  create: (data: any) => api('/proveedores', { method: 'POST', body: JSON.stringify(data) }),
  update: (id: number, data: any) => api(`/proveedores/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  remove: (id: number) => api(`/proveedores/${id}`, { method: 'DELETE' }),
}

export const userService = {
  list: () => api<any[]>('/usuarios'),
  create: (data: any) => api('/usuarios', { method: 'POST', body: JSON.stringify(data) }),
  update: (id: number, data: any) => api(`/usuarios/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  reset2fa: (id: number) => api(`/usuarios/${id}/reset-2fa`, { method: 'POST' }),
}

export const auditService = {
  list: (params = '') => api<any>(`/auditorias${params}`),
}
