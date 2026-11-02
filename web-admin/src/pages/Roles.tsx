
import { useEffect, useState } from 'react'
import { roleService } from '../api/services'
import { PageHeader, LoadingState, ErrorState } from '../components/ui'

type RoleData = {
  name: string
  permissions: string[]
  permission_count: number
}

type PermissionsData = {
  roles: string[]
  permissions_by_module: Record<string, string[]>
  all_permissions: string[]
  roles_config: Record<string, string[]>
}

const MODULE_NAMES: Record<string, string> = {
  products: 'Productos',
  inventory: 'Inventario',
  orders: 'Pedidos',
  dashboard: 'Dashboard',
  clients: 'Clientes',
  suppliers: 'Proveedores',
  users: 'Usuarios',
  audit: 'Auditoría',
}

const ROLE_DESCRIPTIONS: Record<string, string> = {
  'Operador de Almacén':
    'Puede consultar productos e inventario y realizar operaciones de surtido y recepción.',
  Administrador:
    'Cuenta con acceso administrativo a los módulos de productos, inventario, pedidos, clientes, proveedores, usuarios y auditoría.',
  'Administrador Regular':
    'Cuenta con acceso restringido a las operaciones autorizadas, incluyendo la consulta y aceptación de pedidos.',
}

const PERMISSION_NAMES: Record<string, string> = {
  view: 'Consultar',
  create: 'Crear',
  update: 'Actualizar',
  delete: 'Eliminar',
  picking: 'Preparar surtido',
  move: 'Mover inventario',
  adjust: 'Ajustar inventario',
  receive: 'Recibir',
  surtir: 'Surtir pedido',
  entregar: 'Entregar pedido',
  cancel: 'Cancelar',
  aceptar: 'Aceptar pedido',
  reset_2fa: 'Restablecer 2FA',
}

export default function Roles() {
  const [roles, setRoles] = useState<RoleData[]>([])
  const [permissions, setPermissions] = useState<PermissionsData | null>(null)
  const [orphanPermissions, setOrphanPermissions] = useState<string[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<any>(null)
  const [activeTab, setActiveTab] = useState<'roles' | 'permissions'>('roles')

  const loadData = async () => {
    setLoading(true)
    setError(null)

    try {
      const [rolesResult, permissionsResult] = await Promise.all([
        roleService.getRoles(),
        roleService.getPermissions(),
      ])

      const rolesRaw =
        (rolesResult as any)?.data ?? rolesResult

      const permissionsRaw =
        (permissionsResult as any)?.data ?? permissionsResult

      if (!Array.isArray(rolesRaw)) {
        throw new Error('La API no devolvió una lista válida de roles.')
      }

      const validRoles: RoleData[] = rolesRaw
        .filter(
          (role: any) =>
            role &&
            typeof role.name === 'string' &&
            Array.isArray(role.permissions)
        )
        .map((role: any) => ({
          name: role.name,
          permissions: role.permissions.filter(
            (permission: unknown) => typeof permission === 'string'
          ),
          permission_count:
            typeof role.permission_count === 'number'
              ? role.permission_count
              : role.permissions.length,
        }))

      setRoles(validRoles)

      if (
        !permissionsRaw ||
        typeof permissionsRaw !== 'object' ||
        !permissionsRaw.permissions_by_module ||
        typeof permissionsRaw.permissions_by_module !== 'object' ||
        Array.isArray(permissionsRaw.permissions_by_module)
      ) {
        throw new Error('La API no devolvió una estructura válida de permisos.')
      }

      const normalizedPermissions: PermissionsData = {
        roles: Array.isArray(permissionsRaw.roles)
          ? permissionsRaw.roles
          : [],
        permissions_by_module: permissionsRaw.permissions_by_module,
        all_permissions: Array.isArray(permissionsRaw.all_permissions)
          ? permissionsRaw.all_permissions
          : [],
        roles_config:
          permissionsRaw.roles_config &&
          typeof permissionsRaw.roles_config === 'object' &&
          !Array.isArray(permissionsRaw.roles_config)
            ? permissionsRaw.roles_config
            : {},
      }

      setPermissions(normalizedPermissions)

      // Detectar permisos configurados sin una ruta HTTP confirmada.
      const detected: string[] = []
      const orderPermissions =
        normalizedPermissions.permissions_by_module.orders

      if (
        Array.isArray(orderPermissions) &&
        orderPermissions.some(
          (permission) =>
            permission === 'aceptar' ||
            permission === 'orders.aceptar'
        )
      ) {
        detected.push('orders.aceptar')
      }

      setOrphanPermissions(detected)
    } catch (caught) {
      console.error('Error al cargar roles y permisos:', caught)
      setError(caught)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadData()
  }, [])

  const getModuleName = (moduleKey: string): string => {
    return (
      MODULE_NAMES[moduleKey] ||
      moduleKey.charAt(0).toUpperCase() + moduleKey.slice(1)
    )
  }

  // Convierte permisos cortos de la API en identificadores completos.
  // Ejemplo: módulo "products" + permiso "view" = "products.view".
  const getFullPermission = (
    moduleKey: string,
    permission: string
  ): string => {
    if (permission.startsWith(`${moduleKey}.`)) {
      return permission
    }

    return `${moduleKey}.${permission}`
  }

  const getPermissionName = (permission: string): string => {
    const shortName = permission.split('.').pop() || permission
    return PERMISSION_NAMES[shortName] || shortName
  }

  const getPermissionRoles = (
    moduleKey: string,
    permission: string
  ): string[] => {
    if (!roles.length) return []

    const fullPermission = getFullPermission(moduleKey, permission)

    return roles
      .filter(
        (role) =>
          Array.isArray(role.permissions) &&
          role.permissions.includes(fullPermission)
      )
      .map((role) => role.name)
  }

  const getRoleModules = (rolePermissions: string[]): string[] => {
    const modules: string[] = []

    for (const [moduleKey, modulePermissions] of Object.entries(
      permissions?.permissions_by_module || {}
    )) {
      const hasPermission = modulePermissions.some((permission) =>
        rolePermissions.includes(
          getFullPermission(moduleKey, permission)
        )
      )

      if (hasPermission) {
        modules.push(getModuleName(moduleKey))
      }
    }

    return modules
  }

  const getRoleBadgeClass = (roleName: string): string => {
    if (roleName === 'Administrador') {
      return 'bg-violet-100 text-violet-800 border-violet-200'
    }

    if (roleName === 'Operador de Almacén') {
      return 'bg-emerald-100 text-emerald-800 border-emerald-200'
    }

    if (roleName === 'Administrador Regular') {
      return 'bg-blue-100 text-blue-800 border-blue-200'
    }

    return 'bg-gray-100 text-gray-700 border-gray-200'
  }

  const getPermissionBadgeClass = (permission: string): string => {
    const shortName = permission.split('.').pop()

    if (shortName === 'view') {
      return 'bg-sky-50 text-sky-700 border-sky-200'
    }

    if (shortName === 'delete' || shortName === 'cancel') {
      return 'bg-red-50 text-red-700 border-red-200'
    }

    if (shortName === 'create' || shortName === 'aceptar') {
      return 'bg-emerald-50 text-emerald-700 border-emerald-200'
    }

    return 'bg-violet-50 text-violet-700 border-violet-200'
  }

  const rolesContent = roles.map((role) => {
    const modules = getRoleModules(role.permissions)

    return (
      <article
        key={role.name}
        className="surface-panel rounded-xl border p-5 shadow-sm"
      >
        <div className="flex items-start justify-between gap-3 mb-3">
          <div>
            <h3 className="text-lg font-semibold">
              {role.name}
            </h3>
            <p className="text-sm text-muted-foreground mt-1">
              {ROLE_DESCRIPTIONS[role.name] ||
                'Rol configurado en el sistema.'}
            </p>
          </div>

          <span className="shrink-0 rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-700">
            {role.permission_count} permisos
          </span>
        </div>

        <div className="mt-5">
          <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Módulos habilitados
          </h4>

          {modules.length > 0 ? (
            <div
              style={{
                display: 'flex',
                flexWrap: 'wrap',
                gap: 14,
                alignItems: 'center',
                justifyContent: 'flex-start',
                marginTop: 20,
              }}
            >
              {modules.map((module) => (
                <span
                  key={module}
                  style={{
                    flexShrink: 0,
                    display: 'inline-flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    padding: '8px 14px',
                    margin: 0,
                    minWidth: '80px',
                    borderRadius: '10px',
                    border: '1px solid #cbd5e0',
                    backgroundColor: '#edf2f7',
                    color: '#2d3748',
                    fontSize: '12px',
                    fontWeight: 500,
                    lineHeight: 1.4,
                    whiteSpace: 'normal',
                  }}
                >
                  {module}
                </span>
              ))}
            </div>
          ) : (
            <p
              style={{
                marginTop: 16,
                fontSize: '13px',
                color: '#718096',
              }}
            >
              Sin módulos asignados.
            </p>
          )}
        </div>

        <div className="mt-5 border-t pt-4">
          <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Permisos asignados
          </h4>

          {role.permissions.length > 0 ? (
            <div
              style={{
                display: 'flex',
                flexWrap: 'wrap',
                gap: 14,
              }}
            >
              {role.permissions.map((permission) => (
                <span
                  key={permission}
                  style={{
                    flexShrink: 0,
                    display: 'inline-flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    padding: '8px 14px',
                    margin: 0,
                    minWidth: '80px',
                    borderRadius: '10px',
                    border: '1px solid #cbd5e0',
                    backgroundColor: '#edf2f7',
                    color: '#2d3748',
                    fontSize: '12px',
                    fontWeight: 500,
                    lineHeight: 1.4,
                    whiteSpace: 'normal',
                  }}
                >
                  {getPermissionName(permission)}
                </span>
              ))}
            </div>
          ) : (
            <p
              style={{
                marginTop: 16,
                fontSize: '13px',
                color: '#718096',
              }}
            >
              Sin permisos asignados.
            </p>
          )}
        </div>
      </article>
    )
  })

  const permissionsContent = permissions
    ? Object.entries(permissions.permissions_by_module).map(
        ([moduleKey, modulePermissions]) => (
          <article
            key={moduleKey}
            className="surface-panel rounded-xl border p-5 shadow-sm"
          >
            <div className="mb-4 flex items-center justify-between border-b pb-3">
              <h3 className="text-sm font-bold uppercase tracking-wider text-violet">
                {getModuleName(moduleKey)}
              </h3>

              <span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">
                {modulePermissions.length} permisos
              </span>
            </div>

            <div className="space-y-4">
              {modulePermissions.map((permission) => {
                const fullPermission = getFullPermission(
                  moduleKey,
                  permission
                )

                const roleNames = getPermissionRoles(
                  moduleKey,
                  permission
                )

                return (
<div
                  key={fullPermission}
                  style={{
                    borderBottom: '1px solid #e2def5',
                    paddingBottom: 3,
                    marginBottom: 3,
                  }}
                >
                  <div
                    style={{
                      display: 'flex',
                      flexWrap: 'wrap',
                      alignItems: 'center',
                      gap: 10,
                    }}
                  >
                    <span
                      style={{
                        fontSize: '12px',
                        fontWeight: 'bold',
                        color: '#5141a5',
                      }}
                    >
                      {getPermissionName(fullPermission)}
                    </span>

                    <span
                      style={{
                        fontSize: '10px',
                        color: '#a0aec0',
                      }}
                    >
                      {fullPermission}
                    </span>
                  </div>

{roleNames.length > 0 ? (
                      <div
                        style={{
                          display: 'flex',
                          flexWrap: 'wrap',
                          gap: 14,
                        }}
                      >
                        {roleNames.map((roleName) => (
                          <span
                            key={roleName}
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              padding: '8px 14px',
                              margin: 0,
                              borderRadius: '10px',
                              border: '1px solid #cbd5e0',
                              backgroundColor: '#edf2f7',
                              color: '#2d3748',
                              fontSize: '12px',
                              fontWeight: 500,
                              lineHeight: 1.4,
                              whiteSpace: 'normal',
                            }}
                          >
                            {roleName}
                          </span>
                        ))}
                      </div>
                    ) : (
                      <span
                        style={{
                          display: 'inline-flex',
                          alignItems: 'center',
                          padding: '8px 14px',
                          margin: 0,
                          borderRadius: '10px',
                          border: '1px solid #cbd5e0',
                          backgroundColor: '#edf2f7',
                          color: '#2d3748',
                          fontSize: '12px',
                          lineHeight: 1.4,
                        }}
                      >
                        Sin roles asignados
                      </span>
                    )}
                  </div>
                )
              })}
            </div>
          </article>
        )
      )
    : null

  return (
    <div className="page-stack">
      <PageHeader
        title="Roles y Permisos"
        description="Consulta los roles del sistema y revisa los permisos asignados a cada módulo."
      />

      {orphanPermissions.length > 0 && (
        <div className="rounded-lg border border-yellow-500/30 bg-yellow-500/10 p-3">
          <p className="text-sm font-medium text-yellow-700">
            Advertencia de configuración
          </p>

          {orphanPermissions.map((permission) => (
            <p
              key={permission}
              className="mt-1 text-sm text-yellow-700"
            >
              {permission}: permiso configurado sin ruta HTTP confirmada.
            </p>
          ))}
        </div>
      )}

      <div className="flex flex-wrap gap-2 border-b pb-3">
        <button
          type="button"
          className={
            activeTab === 'roles'
              ? 'button-primary'
              : 'button-quiet'
          }
          onClick={() => setActiveTab('roles')}
        >
          Roles ({roles.length})
        </button>

        <button
          type="button"
          className={
            activeTab === 'permissions'
              ? 'button-primary'
              : 'button-quiet'
          }
          onClick={() => setActiveTab('permissions')}
        >
          Permisos por módulo
        </button>
      </div>

      {error && !loading ? (
        <ErrorState error={error} />
      ) : loading ? (
        <LoadingState text="Cargando roles y permisos…" />
      ) : activeTab === 'roles' ? (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {rolesContent}
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {permissionsContent}
        </div>
      )}
    </div>
  )
}
