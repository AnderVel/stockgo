<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use App\Models\Producto;
use App\Services\AuditoriaService;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MovimientoController extends Controller
{
    public function __construct(
        private StockService $stockService
    ) {}

    public function index(Request $request)
    {
        $query = Movimiento::with([
            'producto',
            'pedido',
            'proveedor',
            'usuario:id,name,username',
        ])->orderByDesc('id_movimiento');

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->query('tipo'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->whereHas('producto', function ($builder) use ($search): void {
                $term = '%'.mb_strtolower($search).'%';
                $builder->whereRaw('LOWER(nombre) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(codigo_barras) LIKE ?', [$term]);
            });
        }

        if ($request->boolean('paginate')) {
            $perPage = min(max($request->integer('per_page', 25), 1), 50);

            return response()->json($query->paginate($perPage));
        }

        return response()->json($query->get());
    }

    public function show(Movimiento $movimiento)
    {
        return response()->json(
            $movimiento->load([
                'producto',
                'pedido',
                'proveedor',
            ])
        );
    }

    public function entrada(Request $request)
    {
        $datos = $request->validate([
            'id_producto' => 'required|exists:productos,id_producto',
            'cantidad' => 'required|integer|min:1',
            'id_proveedor' => 'nullable|exists:proveedores,id_proveedor',
            'motivo' => 'nullable|string|max:255',
        ]);

        try {
            $movimiento = DB::transaction(function () use ($datos) {
                $producto = Producto::where('id_producto', $datos['id_producto'])
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->stockService->entrada(
                    $producto,
                    $datos['cantidad'],
                    $datos['id_proveedor'] ?? null,
                    $datos['motivo'] ?? null
                );
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (QueryException $e) {
            \Log::error('QueryException en movimiento', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql' => $e->getSQL(),
                'bindings' => $e->getBindings(),
                'method' => $request->method(),
                'route' => $request->route()?->uri(),
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(10)->toArray(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error de base de datos al registrar el movimiento.',
            ], 500);
        }

        $productoNombre = $movimiento->producto?->nombre ?? ('#'.$datos['id_producto']);

        app(AuditoriaService::class)->registrar(
            'ENTRADA',
            'INVENTARIO',
            "Recepción de {$productoNombre}: +{$datos['cantidad']} uds.",
            [
                'id_producto' => $datos['id_producto'],
                'producto' => $productoNombre,
                'cantidad' => $datos['cantidad'],
                'id_proveedor' => $datos['id_proveedor'] ?? null,
            ],
            $request
        );

        return response()->json(
            $movimiento->load('producto', 'proveedor'),
            201
        );
    }

    public function salida(Request $request)
    {
        $datos = $request->validate([
            'id_producto' => 'required|exists:productos,id_producto',
            'cantidad' => 'required|integer|min:1',
            'id_pedido' => 'nullable|exists:pedidos,id_pedido',
            'motivo' => 'nullable|string|max:255',
        ]);

        try {
            $movimiento = DB::transaction(function () use ($datos) {
                $producto = Producto::where('id_producto', $datos['id_producto'])
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->stockService->salida(
                    $producto,
                    $datos['cantidad'],
                    $datos['id_pedido'] ?? null,
                    $datos['motivo'] ?? null
                );
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (QueryException $e) {
            \Log::error('QueryException en movimiento', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql' => $e->getSQL(),
                'bindings' => $e->getBindings(),
                'method' => $request->method(),
                'route' => $request->route()?->uri(),
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(10)->toArray(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error de base de datos al registrar el movimiento.',
            ], 500);
        }

        $productoNombre = $movimiento->producto?->nombre ?? ('#'.$datos['id_producto']);

        app(AuditoriaService::class)->registrar(
            'SALIDA',
            'INVENTARIO',
            "Salida de {$productoNombre}: -{$datos['cantidad']} uds.",
            [
                'id_producto' => $datos['id_producto'],
                'producto' => $productoNombre,
                'cantidad' => $datos['cantidad'],
                'id_pedido' => $datos['id_pedido'] ?? null,
            ],
            $request
        );

        return response()->json(
            $movimiento->load('producto', 'pedido'),
            201
        );
    }

    public function ajuste(Request $request)
    {
        $datos = $request->validate([
            'id_producto' => 'required|exists:productos,id_producto',
            'cantidad' => 'required|integer|not_in:0',
            'motivo' => 'required|string|max:255',
        ]);

        try {
            $movimiento = DB::transaction(function () use ($datos) {
                $producto = Producto::where('id_producto', $datos['id_producto'])
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->stockService->ajuste(
                    $producto,
                    $datos['cantidad'],
                    $datos['motivo']
                );
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (QueryException $e) {
            \Log::error('QueryException en movimiento', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql' => $e->getSQL(),
                'bindings' => $e->getBindings(),
                'method' => $request->method(),
                'route' => $request->route()?->uri(),
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(10)->toArray(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error de base de datos al registrar el movimiento.',
            ], 500);
        }

        app(AuditoriaService::class)->registrar(
            'AJUSTE',
            'INVENTARIO',
            'Ajuste de inventario: '.($movimiento->producto?->nombre ?? ('#'.$datos['id_producto']))." ({$datos['cantidad']} uds) — {$datos['motivo']}",
            [
                'id_producto' => $datos['id_producto'],
                'cantidad' => $datos['cantidad'],
                'motivo' => $datos['motivo'],
            ],
            $request
        );

        return response()->json(
            $movimiento->load('producto'),
            201
        );
    }

    public function movimiento(Request $request)
    {
        $datos = $request->validate([
            'codigo_barras' => 'required|string',
            'tipo_movimiento' => 'required|in:recepcion,picking',
            'cantidad' => 'required|integer|min:1',
            'id_pedido' => 'nullable|integer|min:1',
            // Se acepta por compatibilidad con Android pero ya NO se confía en él:
            // la identidad del operador siempre es la del token autenticado.
            'usuario_id' => 'nullable|integer|min:1',
        ]);

        try {
            $resultado = DB::transaction(function () use ($datos) {
                return $this->stockService->ejecutarMovimientoAndroid(
                    $datos['codigo_barras'],
                    $datos['tipo_movimiento'],
                    $datos['cantidad'],
                    $datos['id_pedido'] ?? null
                );
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (QueryException $e) {
            \Log::error('QueryException en movimiento', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql' => $e->getSQL(),
                'bindings' => $e->getBindings(),
                'method' => $request->method(),
                'route' => $request->route()?->uri(),
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(10)->toArray(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error de base de datos al registrar el movimiento.',
            ], 500);
        }

        if ($resultado['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $resultado['mensaje'],
            ], 422);
        }

        $esRecepcion = $datos['tipo_movimiento'] === 'recepcion';

        app(AuditoriaService::class)->registrar(
            $esRecepcion ? 'RECEPCION' : 'PICKING',
            'INVENTARIO',
            ($esRecepcion ? 'Recepción' : 'Picking').' desde dispositivo: '
                .$datos['codigo_barras']." ({$datos['cantidad']} uds)",
            [
                'codigo_barras' => $datos['codigo_barras'],
                'tipo_movimiento' => $datos['tipo_movimiento'],
                'cantidad' => $datos['cantidad'],
            ],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Movimiento registrado',
        ], 200);
    }

    /**
     * Endpoint compatible con el contrato solicitado por Android:
     * POST /api/productos/{id}/movimiento
     * { "tipo": "entrada"|"salida", "cantidad": int, "pedido_id": int|null }
     */
    public function movimientoProducto(Request $request, Producto $producto)
    {
        $datos = $request->validate([
            'tipo' => 'required|in:entrada,salida',
            'cantidad' => 'required|integer|min:1',
            'pedido_id' => 'nullable|integer|exists:pedidos,id_pedido',
        ]);

        try {
            $resultado = DB::transaction(function () use ($producto, $datos) {
                return $this->stockService->ejecutarMovimientoProducto(
                    $producto,
                    $datos['tipo'],
                    $datos['cantidad'],
                    $datos['pedido_id'] ?? null
                );
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (QueryException $e) {
            \Log::error('QueryException en movimiento', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql' => $e->getSQL(),
                'bindings' => $e->getBindings(),
                'method' => $request->method(),
                'route' => $request->route()?->uri(),
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(10)->toArray(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error de base de datos al registrar el movimiento.',
            ], 500);
        }

        if ($resultado['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $resultado['mensaje'],
            ], 422);
        }

        $esEntrada = $datos['tipo'] === 'entrada';

        app(AuditoriaService::class)->registrar(
            $esEntrada ? 'ENTRADA' : 'SALIDA',
            'INVENTARIO',
            ($esEntrada ? 'Recepción' : 'Salida')." de {$producto->nombre}: "
                .($esEntrada ? '+' : '-').$datos['cantidad'].' uds'
                .(isset($datos['pedido_id']) ? " (pedido #{$datos['pedido_id']})" : ''),
            [
                'id_producto' => $producto->id_producto,
                'producto' => $producto->nombre,
                'tipo' => $datos['tipo'],
                'cantidad' => $datos['cantidad'],
                'pedido_id' => $datos['pedido_id'] ?? null,
            ],
            $request
        );

        $producto->refresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Movimiento registrado',
            'producto' => [
                'id_producto' => $producto->id_producto,
                'stock_fisico' => $producto->stock_fisico,
                'stock_reservado' => $producto->stock_reservado,
                'stock_disponible' => $producto->stock_disponible,
            ],
        ], 200);
    }

    /**
     * Registra una entrada de mercancía (recepción de proveedor) para un producto específico.
     * POST /api/productos/{producto}/entrada
     * Body: { "cantidad": int, "motivo": string|null, "id_proveedor": int|null }
     */
    public function entradaProducto(Request $request, Producto $producto)
    {
        $datos = $request->validate([
            'cantidad' => 'required|integer|min:1',
            'motivo' => 'nullable|string|max:255',
            'id_proveedor' => 'nullable|exists:proveedores,id_proveedor',
        ]);

        try {
            $movimiento = DB::transaction(function () use ($producto, $datos) {
                // Recargar el producto con lock para evitar condiciones de carrera
                $producto = Producto::where('id_producto', $producto->id_producto)
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->stockService->entrada(
                    $producto,
                    $datos['cantidad'],
                    $datos['id_proveedor'] ?? null,
                    $datos['motivo'] ?? 'Recepción de mercancía'
                );
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (QueryException $e) {
            \Log::error('QueryException en movimiento', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql' => $e->getSQL(),
                'bindings' => $e->getBindings(),
                'method' => $request->method(),
                'route' => $request->route()?->uri(),
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(10)->toArray(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error de base de datos al registrar el movimiento.',
            ], 500);
        }

        $producto->refresh();

        app(AuditoriaService::class)->registrar(
            'ENTRADA',
            'INVENTARIO',
            "Recepción de {$producto->nombre}: +{$movimiento->cantidad} uds.",
            [
                'id_producto' => $producto->id_producto,
                'producto' => $producto->nombre,
                'cantidad' => $movimiento->cantidad,
                'id_proveedor' => $movimiento->id_proveedor ?? null,
            ],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Entrada registrada',
            'producto' => [
                'id_producto' => $producto->id_producto,
                'stock_fisico' => $producto->stock_fisico,
                'stock_reservado' => $producto->stock_reservado,
                'stock_disponible' => $producto->stock_disponible,
            ],
        ], 201);
    }
}
