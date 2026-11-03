<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Retorna la lista de roles con sus permisos y conteo.
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $config = config('permissions.roles', []);

        $roles = [];
        foreach ($config as $roleName => $permissions) {
            $roles[] = [
                'name' => $roleName,
                'permissions' => $permissions,
                'permission_count' => count($permissions),
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $roles,
        ]);
    }

    /**
     * Retorna la estructura completa de permisos organizada por módulo.
     *
     * @return JsonResponse
     */
    public function permissions(Request $request)
    {
        $config = config('permissions.roles', []);

        // Extraer todos los permisos únicos
        $allPermissions = [];
        foreach ($config as $permissions) {
            foreach ($permissions as $permission) {
                $allPermissions[$permission] = true;
            }
        }
        $allPermissions = array_keys($allPermissions);

        // Organizar por módulo (prefijo antes del punto)
        $permissionsByModule = [];
        foreach ($allPermissions as $permission) {
            $parts = explode('.', $permission, 2);
            $module = $parts[0] ?? 'general';
            $action = $parts[1] ?? $permission;

            if (! isset($permissionsByModule[$module])) {
                $permissionsByModule[$module] = [];
            }
            if (! in_array($action, $permissionsByModule[$module])) {
                $permissionsByModule[$module][] = $action;
            }
        }

        // Ordenar módulos y acciones alfabéticamente
        ksort($permissionsByModule);
        foreach ($permissionsByModule as $module => $actions) {
            sort($actions);
            $permissionsByModule[$module] = $actions;
        }

        $roles = array_keys($config);

        return response()->json([
            'status' => 'success',
            'data' => [
                'roles' => $roles,
                'permissions_by_module' => $permissionsByModule,
                'all_permissions' => $allPermissions,
                'roles_config' => $config,
            ],
        ]);
    }
}
