<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bodega;
use App\Services\AuditoriaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BodegaController extends Controller
{
    /**
     * GET /api/bodegas — catálogo de bodegas para selects e inventario.
     */
    public function index(): JsonResponse
    {
        $bodegas = Bodega::query()
            ->withCount('productos')
            ->orderBy('nombre')
            ->get();

        return response()->json($bodegas);
    }

    /**
     * POST /api/bodegas — alta de bodega (administración).
     */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100', 'unique:bodegas,nombre'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ]);

        $bodega = Bodega::create($datos);

        app(AuditoriaService::class)->registrar(
            'CREAR',
            'BODEGAS',
            'Bodega creada: '.$bodega->nombre,
            ['id' => $bodega->id],
            $request
        );

        return response()->json($bodega, 201);
    }

    /**
     * PUT /api/bodegas/{bodega} — edición.
     */
    public function update(Request $request, Bodega $bodega): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100', 'unique:bodegas,nombre,'.$bodega->id],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ]);

        $bodega->update($datos);

        app(AuditoriaService::class)->registrar(
            'ACTUALIZAR',
            'BODEGAS',
            'Bodega actualizada: '.$bodega->nombre,
            ['id' => $bodega->id],
            $request
        );

        return response()->json($bodega);
    }

    /**
     * DELETE /api/bodegas/{bodega} — elimina; los productos y usuarios
     * asociados quedan sin bodega (nullOnDelete).
     */
    public function destroy(Request $request, Bodega $bodega): JsonResponse
    {
        if ($bodega->usuarios()->where('rol', 'Operador de Almacén')->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Hay operadores asignados a esta bodega. Reasígnalos antes de eliminarla.',
            ], 409);
        }

        $nombre = $bodega->nombre;
        $bodega->delete();

        app(AuditoriaService::class)->registrar(
            'ELIMINAR',
            'BODEGAS',
            'Bodega eliminada: '.$nombre,
            [],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Bodega eliminada.',
        ]);
    }
}
