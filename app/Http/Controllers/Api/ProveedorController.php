<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proveedor;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;

class ProveedorController extends Controller
{
    public function index(Request $request)
    {
        $query = Proveedor::query()->orderBy('id_proveedor');

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($builder) use ($search): void {
                $term = '%'.mb_strtolower($search).'%';
                $builder->whereRaw('LOWER(nombre) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(contacto) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(correo) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(telefono) LIKE ?', [$term]);
            });
        }

        if ($request->boolean('paginate')) {
            $perPage = min(max($request->integer('per_page', 25), 1), 50);

            return response()->json($query->paginate($perPage));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255',
            'contacto' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:30',
            'correo' => 'nullable|email|max:255',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'required|string|max:50',
        ]);

        $proveedor = Proveedor::create($datos);

        app(AuditoriaService::class)->registrar(
            'CREAR',
            'PROVEEDORES',
            'Proveedor creado.',
            ['id_proveedor' => $proveedor->id_proveedor],
            $request
        );

        return response()->json($proveedor, 201);
    }

    public function show(Proveedor $proveedor)
    {
        return response()->json($proveedor);
    }

    public function update(Request $request, Proveedor $proveedor)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255',
            'contacto' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:30',
            'correo' => [
                'nullable',
                'email',
                'max:255',
            ],
            'direccion' => 'nullable|string|max:255',
            'estado' => 'required|string|max:50',
        ]);

        $proveedor->update($datos);

        app(AuditoriaService::class)->registrar(
            'ACTUALIZAR',
            'PROVEEDORES',
            'Proveedor actualizado.',
            ['id_proveedor' => $proveedor->id_proveedor],
            $request
        );

        return response()->json($proveedor);
    }

    public function destroy(Proveedor $proveedor)
    {
        if ($proveedor->movimientos()->exists()) {
            return response()->json([
                'mensaje' => 'No se puede eliminar un proveedor con movimientos registrados.',
            ], 409);
        }

        $proveedor->delete();

        app(AuditoriaService::class)->registrar(
            'ELIMINAR',
            'PROVEEDORES',
            'Proveedor eliminado.',
            ['id_proveedor' => $proveedor->id_proveedor],
            request()
        );

        return response()->json([
            'mensaje' => 'Proveedor eliminado correctamente.',
        ]);
    }
}
