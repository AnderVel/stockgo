<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $query = Cliente::query()->orderBy('id_cliente');

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($builder) use ($search): void {
                $term = '%'.mb_strtolower($search).'%';
                $builder->whereRaw('LOWER(nombre) LIKE ?', [$term])
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
            'telefono' => 'nullable|string|max:30',
            'correo' => 'nullable|email|max:255',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'required|string|max:50',
        ]);

        $cliente = Cliente::create($datos);

        app(AuditoriaService::class)->registrar(
            'CREAR',
            'CLIENTES',
            'Cliente creado.',
            ['id_cliente' => $cliente->id_cliente],
            $request
        );

        return response()->json($cliente, 201);
    }

    public function show(Cliente $cliente)
    {
        return response()->json(
            $cliente->load('pedidos')
        );
    }

    public function update(Request $request, Cliente $cliente)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255',
            'telefono' => 'nullable|string|max:30',
            'correo' => 'nullable|email|max:255',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'required|string|max:50',
        ]);

        $cliente->update($datos);

        app(AuditoriaService::class)->registrar(
            'ACTUALIZAR',
            'CLIENTES',
            'Cliente actualizado.',
            ['id_cliente' => $cliente->id_cliente],
            $request
        );

        return response()->json($cliente);
    }

    public function destroy(Cliente $cliente)
    {
        if ($cliente->pedidos()->exists()) {
            return response()->json([
                'mensaje' => 'No se puede eliminar un cliente que tiene pedidos.',
            ], 409);
        }

        $cliente->delete();

        app(AuditoriaService::class)->registrar(
            'ELIMINAR',
            'CLIENTES',
            'Cliente eliminado.',
            ['id_cliente' => $cliente->id_cliente],
            request()
        );

        return response()->json([
            'mensaje' => 'Cliente eliminado correctamente.',
        ]);
    }
}
