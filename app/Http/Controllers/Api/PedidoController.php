<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PedidoEnviadoMail;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\DetallePedido;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\AuditoriaService;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PedidoController extends Controller
{
    private const MAX_NUMERIC_10_2_CENTAVOS = 9_999_999_999;

    private const ROL_OPERADOR = 'Operador de Almacén';

    public function __construct(
        private StockService $stockService
    ) {}

    public function index(Request $request)
    {
        $usuario = $request->user();

        $query = Pedido::with([
            'cliente',
            'detalles.producto.bodega',
            'detalles.operador:id,name,username',
            'operador:id,name,username',
        ])->orderByDesc('id_pedido');

        // Un pedido ya no se asigna entero: cada linea (producto) lleva su
        // operador, porque el pedido puede mezclar bodegas. El operario ve
        // los pedidos donde ya tiene lineas asignadas y los pendientes que
        // todavia tengan lineas libres de su propia bodega.
        if ($usuario && $usuario->rol === self::ROL_OPERADOR) {
            $query->where(function ($builder) use ($usuario): void {
                $builder->whereHas('detalles', function ($detalles) use ($usuario): void {
                    $detalles->where('detalle_pedidos.operador_id', $usuario->id);
                })->orWhere(function ($pendientes) use ($usuario): void {
                    $pendientes->where('estado', 'PENDIENTE')
                        ->whereHas('detalles', function ($detalles) use ($usuario): void {
                            $detalles->whereNull('detalle_pedidos.operador_id')
                                ->where('detalle_pedidos.surtido', false)
                                ->whereHas('producto', function ($producto) use ($usuario): void {
                                    if ($usuario->bodega_id) {
                                        $producto->where('bodega_id', $usuario->bodega_id);
                                    }
                                });
                        });
                });
            });
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->query('estado'));
        }

        // Filtros de administracion por linea: un mismo pedido puede tener
        // varios operadores (uno por bodega), asi que ya no se filtra por la
        // cabecera.
        if ($request->filled('operador_id')) {
            $idOperador = $request->integer('operador_id');

            $query->whereHas('detalles', function ($detalles) use ($idOperador): void {
                $detalles->where('detalle_pedidos.operador_id', $idOperador);
            });
        }

        if ($request->boolean('sin_asignar')) {
            $query->whereDoesntHave('detalles', function ($detalles): void {
                $detalles->whereNotNull('detalle_pedidos.operador_id');
            });
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($builder) use ($search): void {
                $term = '%'.mb_strtolower($search).'%';
                $builder->whereRaw('CAST(id_pedido AS TEXT) LIKE ?', [$term])
                    ->orWhereHas('cliente', function ($clientQuery) use ($search): void {
                        $clientQuery->whereRaw('LOWER(nombre) LIKE ?', ['%'.mb_strtolower($search).'%']);
                    });
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
        $operacion = 'validar_solicitud';

        try {
            $datos = $request->validate([
                'id_cliente' => [
                    'required',
                    'integer',
                    'exists:clientes,id_cliente',
                ],
                'fecha_pedido' => [
                    'required',
                    'date',
                ],
                'operador_id' => [
                    'nullable',
                    'integer',
                    'exists:users,id',
                ],
                'observaciones' => [
                    'nullable',
                    'string',
                    'max:5000',
                ],
                'detalles' => [
                    'required',
                    'array',
                    'min:1',
                ],
                'detalles.*.id_producto' => [
                    'required',
                    'integer',
                    'distinct',
                    'exists:productos,id_producto',
                ],
                'detalles.*.cantidad' => [
                    'required',
                    'integer',
                    'min:1',
                ],
            ]);

            // Un pedido puede mezclar bodegas: si se entrega un operador al
            // crear, solo quedan asignadas las lineas de SU bodega. Las de
            // otras bodegas quedan libres para el operador que les corresponda.
            $operadorDestino = null;

            if (! empty($datos['operador_id'])) {
                $operadorDestino = $this->validarOperador((int) $datos['operador_id']);
            }

            $operacion = 'abrir_transaccion';

            $pedido = DB::transaction(function () use ($datos, $request, $operadorDestino, &$operacion) {
                $operacion = 'bloquear_cliente';
                $cliente = Cliente::where('id_cliente', $datos['id_cliente'])
                    ->lockForUpdate()
                    ->first();

                if (! $cliente) {
                    throw new \InvalidArgumentException('El cliente seleccionado ya no existe.');
                }

                $detallesEntrada = collect($datos['detalles'])
                    ->sortBy('id_producto')
                    ->values();

                $productos = [];
                $importes = [];
                $totalCentavos = 0;

                foreach ($detallesEntrada as $detalle) {
                    $operacion = 'bloquear_producto';
                    $producto = Producto::where(
                        'id_producto',
                        $detalle['id_producto']
                    )
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($producto->estado !== 'ACTIVO') {
                        throw new \InvalidArgumentException(
                            'El producto "'.$producto->nombre.'" no está disponible.'
                        );
                    }

                    if ((int) $detalle['cantidad'] > (int) $producto->stock_disponible) {
                        throw new \InvalidArgumentException(
                            'Stock disponible insuficiente para "'.
                            $producto->nombre.
                            '". Disponible: '.
                            $producto->stock_disponible.
                            '.'
                        );
                    }

                    $productos[$producto->id_producto] = $producto;

                    $operacion = 'validar_importes';
                    $importe = $this->calcularImporteDetalle($producto, (int) $detalle['cantidad']);

                    if ($importe['subtotal_centavos'] > self::MAX_NUMERIC_10_2_CENTAVOS - $totalCentavos) {
                        throw new \InvalidArgumentException('El total del pedido excede el máximo permitido.');
                    }

                    $importes[$producto->id_producto] = $importe;
                    $totalCentavos += $importe['subtotal_centavos'];
                }

                // Si se pidio un operador, al menos una linea debe pertenecer
                // a su bodega; si no, el pedido no podria surtirse.
                if ($operadorDestino) {
                    $sinLineasPropias = ! collect($productos)->contains(
                        fn (Producto $producto): bool => $this->lineaCorrespondeAlOperador($operadorDestino, $producto)
                    );

                    if ($sinLineasPropias) {
                        throw new \InvalidArgumentException(
                            'Ningun producto de este pedido pertenece a la bodega del operador seleccionado.'
                        );
                    }
                }

                $operacion = 'insertar_encabezado';
                $pedido = Pedido::create([
                    'id_cliente' => $datos['id_cliente'],
                    'operador_id' => $datos['operador_id'] ?? null,
                    'fecha_pedido' => $datos['fecha_pedido'],
                    'estado' => 'PENDIENTE',
                    'total' => 0,
                    'observaciones' => $datos['observaciones'] ?? null,
                ]);

                foreach ($detallesEntrada as $detalle) {
                    $producto = $productos[$detalle['id_producto']];
                    $cantidad = (int) $detalle['cantidad'];
                    $importe = $importes[$producto->id_producto];

                    $operacion = 'insertar_detalle';
                    $pedido->detalles()->create([
                        'id_producto' => $producto->id_producto,
                        'operador_id' => $this->lineaCorrespondeAlOperador($operadorDestino, $producto)
                            ? $operadorDestino->id
                            : null,
                        'cantidad' => $cantidad,
                        'precio_unitario' => $importe['precio_unitario'],
                        'subtotal' => $importe['subtotal'],
                    ]);

                    $operacion = 'reservar_existencias';
                    $this->stockService->reservarPorId(
                        (int) $producto->id_producto,
                        $cantidad
                    );

                }

                $operacion = 'actualizar_total';
                $pedido->update([
                    'total' => $totalCentavos / 100,
                ]);

                $operacion = 'registrar_auditoria';
                app(AuditoriaService::class)->registrar(
                    'CREAR',
                    'PEDIDOS',
                    'Pedido creado.',
                    ['id_pedido' => $pedido->id_pedido],
                    $request
                );

                return $pedido->load([
                    'cliente',
                    'detalles.producto.bodega',
                    'detalles.operador:id,name,username',
                ]);
            });

            return response()->json($pedido, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (QueryException $e) {
            $this->registrarFalloSqlPedido($e, $operacion);

            return response()->json([
                'status' => 'error',
                'message' => 'Error de base de datos al crear el pedido. Inténtalo nuevamente.',
            ], 503)->header('Retry-After', '2');
        }
    }

    /**
     * @return array{precio_unitario: float, subtotal_centavos: int, subtotal: float}
     */
    private function calcularImporteDetalle(Producto $producto, int $cantidad): array
    {
        $precioUnitario = (float) $producto->precio;

        if (! is_finite($precioUnitario) || $precioUnitario < 0) {
            throw new \InvalidArgumentException(
                'El precio del producto "'.$producto->nombre.'" no es válido.'
            );
        }

        $precioCentavos = (int) round($precioUnitario * 100);

        if (
            $precioCentavos > 0 &&
            $cantidad > intdiv(self::MAX_NUMERIC_10_2_CENTAVOS, $precioCentavos)
        ) {
            throw new \InvalidArgumentException(
                'El subtotal del producto "'.$producto->nombre.'" excede el máximo permitido.'
            );
        }

        $subtotalCentavos = $precioCentavos * $cantidad;

        return [
            'precio_unitario' => $precioUnitario,
            'subtotal_centavos' => $subtotalCentavos,
            'subtotal' => $subtotalCentavos / 100,
        ];
    }

    private function registrarFalloSqlPedido(QueryException $exception, string $operacion): void
    {
        $errorInfo = is_array($exception->errorInfo) ? $exception->errorInfo : [];
        $mensajeDriver = (string) ($errorInfo[2] ?? '');
        $sqlState = (string) ($errorInfo[0] ?? $exception->getCode());

        preg_match('/constraint "([^"]+)"/i', $mensajeDriver, $constraintMatch);
        preg_match('/column "([^"]+)"/i', $mensajeDriver, $columnMatch);
        preg_match('/relation "([^"]+)"/i', $mensajeDriver, $relationMatch);
        $clasificacion = match ($sqlState) {
            '23502' => 'not_null_violation',
            '23503' => 'foreign_key_violation',
            '23505' => 'unique_violation',
            '22001' => 'string_data_right_truncation',
            '22003' => 'numeric_value_out_of_range',
            '22P02' => 'invalid_text_representation',
            '42703' => 'undefined_column',
            '42P01' => 'undefined_table',
            '25P02' => 'transaction_aborted',
            default => 'postgres_query_error',
        };

        $mensajeSanitizado = $clasificacion;

        Log::error('Error SQL al crear pedido.', [
            'operation' => $operacion,
            'sql_state' => $sqlState,
            'driver_code' => $errorInfo[1] ?? null,
            'error_class' => $clasificacion,
            'driver_message' => $mensajeSanitizado,
            'constraint' => $constraintMatch[1] ?? null,
            'column' => $columnMatch[1] ?? null,
            'relation' => $relationMatch[1] ?? null,
            'sql_template' => $exception->getSql(),
            'connection' => $exception->getConnectionName(),
            'transaction_level' => DB::connection($exception->getConnectionName())->transactionLevel(),
        ]);
    }

    public function show(Request $request, Pedido $pedido)
    {
        $usuario = $request->user();

        // El operario solo puede ver un pedido si ya tiene lineas propias en
        // el o si todavia quedan lineas libres de su bodega para tomarlas.
        if ($usuario && $usuario->rol === self::ROL_OPERADOR) {
            $tieneLineasPropias = $pedido->detalles()
                ->where('operador_id', $usuario->id)
                ->exists();

            if (! $tieneLineasPropias) {
                $lineasLibres = $pedido->detalles()
                    ->whereNull('operador_id')
                    ->where('surtido', false);

                if ($usuario->bodega_id) {
                    $lineasLibres->whereHas('producto', function ($producto) use ($usuario): void {
                        $producto->where('bodega_id', $usuario->bodega_id);
                    });
                }

                if (! $lineasLibres->exists()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Este pedido no tiene productos para tu bodega.',
                    ], 403);
                }
            }
        }

        return response()->json(
            $pedido->load([
                'cliente',
                'detalles.producto.bodega',
                'detalles.operador:id,name,username',
                'operador:id,name,username',
                'movimientos',
            ])
        );
    }

    /**
     * Asigna un operador a las lineas del pedido que le corresponden
     * (solo administracion). Como un pedido puede mezclar bodegas, al elegir
     * un operador se asignan unicamente los productos de SU bodega y los de
     * las demas bodegas quedan libres para el operador que les corresponda.
     *
     * Con "bodega_id" la operacion se limita a esa bodega, para asignar o
     * liberar solo un grupo del pedido. Enviar "operador_id": null libera
     * las lineas que toquen y aun no se han surtido.
     */
    public function asignar(Request $request, Pedido $pedido)
    {
        $datos = $request->validate([
            'operador_id' => [
                'present',
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'bodega_id' => [
                'sometimes',
                'nullable',
                'integer',
            ],
        ]);

        $operadorDestino = null;

        if (! empty($datos['operador_id'])) {
            try {
                $operadorDestino = $this->validarOperador((int) $datos['operador_id']);
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        // "bodega_id" ausente o null = todo el pedido; 0 = solo los productos
        // que aun no tienen bodega; N = solo los productos de esa bodega.
        $filtraBodega = array_key_exists('bodega_id', $datos)
            && $datos['bodega_id'] !== null;

        $idBodega = $filtraBodega ? (int) $datos['bodega_id'] : null;

        if (
            $idBodega &&
            $operadorDestino &&
            $operadorDestino->bodega_id &&
            (int) $operadorDestino->bodega_id !== $idBodega
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ese operador no pertenece a '.
                    (Bodega::find($idBodega)?->nombre ?? 'esa bodega').'.',
            ], 422);
        }

        $resultado = DB::transaction(function () use ($pedido, $operadorDestino, $idBodega, $filtraBodega) {
            $pedido = Pedido::where('id_pedido', $pedido->id_pedido)
                ->lockForUpdate()
                ->firstOrFail();

            if ($pedido->estado !== 'PENDIENTE') {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' => 'Solo se pueden asignar pedidos pendientes.',
                ];
            }

            $lineas = $pedido->detalles()
                ->with('producto')
                ->where('surtido', false)
                ->get();

            if ($lineas->isEmpty()) {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' => 'Este pedido ya tiene todos sus productos surtidos.',
                ];
            }

            // Con bodega_id la operacion se limita a los productos de esa bodega.
            if ($filtraBodega) {
                $deLaBodega = $lineas->filter(function (DetallePedido $detalle) use ($idBodega): bool {
                    if (! $detalle->producto) {
                        return false;
                    }

                    $bodegaProducto = $detalle->producto->bodega_id;

                    return $idBodega === 0
                        ? $bodegaProducto === null
                        : $bodegaProducto !== null && (int) $bodegaProducto === $idBodega;
                });

                if ($deLaBodega->isEmpty()) {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => $idBodega === 0
                            ? 'Este pedido no tiene productos sin bodega asignada.'
                            : 'Este pedido no tiene productos en '.
                                (Bodega::find($idBodega)?->nombre ?? 'esa bodega').'.',
                    ];
                }

                $lineas = $deLaBodega;
            }

            if ($operadorDestino) {
                $tocadas = $lineas->filter(
                    fn (DetallePedido $detalle): bool => $this->lineaCorrespondeAlOperador($operadorDestino, $detalle->producto)
                );

                if ($tocadas->isEmpty()) {
                    $nombreBodega = $operadorDestino->bodega_id
                        ? Bodega::find($operadorDestino->bodega_id)?->nombre
                        : null;

                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => 'Este pedido no tiene productos'
                            . ($nombreBodega ? ' de '.$nombreBodega : '')
                            . ' para asignarle a ese operador.',
                    ];
                }

                $pedido->detalles()
                    ->whereIn('id_detalle_pedido', $tocadas->pluck('id_detalle_pedido'))
                    ->update(['operador_id' => $operadorDestino->id]);
            } else {
                $pedido->detalles()
                    ->whereIn('id_detalle_pedido', $lineas->pluck('id_detalle_pedido'))
                    ->update(['operador_id' => null]);
            }

            // La cabecera queda con el operador de la primera linea asignada:
            // es solo informativa, la asignacion real vive en cada linea.
            $operadorPrincipal = $pedido->detalles()
                ->whereNotNull('operador_id')
                ->orderBy('id_detalle_pedido')
                ->value('operador_id');

            $pedido->update(['operador_id' => $operadorPrincipal]);

            $asignadas = $operadorDestino
                ? $pedido->detalles()
                    ->where('operador_id', $operadorDestino->id)
                    ->where('surtido', false)
                    ->count()
                : 0;

            $libres = $pedido->detalles()
                ->whereNull('operador_id')
                ->where('surtido', false)
                ->count();

            $sufijo = '';

            if ($filtraBodega) {
                $sufijo = $idBodega === 0
                    ? ' (sin bodega)'
                    : ' ('.(Bodega::find($idBodega)?->nombre ?? 'bodega '.$idBodega).')';
            }

            $mensaje = $operadorDestino
                ? "Se asignaron {$asignadas} productos a {$operadorDestino->username}{$sufijo}."
                : ($filtraBodega
                    ? "Asignacion{$sufijo} liberada."
                    : 'Asignacion liberada.');

            if ($libres > 0) {
                $mensaje .= " Quedan {$libres} productos sin asignar en el pedido.";
            }

            app(AuditoriaService::class)->registrar(
                'ASIGNAR',
                'PEDIDOS',
                $mensaje,
                [
                    'id_pedido' => $pedido->id_pedido,
                    'operador_id' => $pedido->operador_id,
                    'bodega_id' => $idBodega,
                    'lineas_asignadas' => $asignadas,
                    'lineas_libres' => $libres,
                ],
                request()
            );

            return [
                'error' => false,
                'message' => $mensaje,
                'pedido' => $pedido->refresh()->load([
                    'cliente',
                    'detalles.producto.bodega',
                    'detalles.operador:id,name,username',
                    'operador:id,name,username',
                ]),
            ];
        });

        if ($resultado['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $resultado['message'],
            ], $resultado['status']);
        }

        return response()->json([
            'status' => 'success',
            'message' => $resultado['message'],
            'pedido' => $resultado['pedido'],
        ]);
    }

    /**
     * El operario toma para si las lineas del pedido que le corresponden
     * (las de su bodega) y que todavia esten libres. Un pedido puede mezclar
     * bodegas, por eso no se toma entero: cada operador se queda con las suyas.
     */
    public function tomar(Request $request, Pedido $pedido)
    {
        $usuario = $request->user();

        if (! $usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Debes iniciar sesión para tomar un pedido.',
            ], 401);
        }

        $resultado = DB::transaction(function () use ($pedido, $usuario) {
            $pedido = Pedido::where('id_pedido', $pedido->id_pedido)
                ->lockForUpdate()
                ->firstOrFail();

            if ($pedido->estado !== 'PENDIENTE') {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' => 'Solo se pueden tomar pedidos pendientes.',
                ];
            }

            // Lineas todavia libres que le tocan a este operador.
            $libres = $pedido->detalles()
                ->with('producto')
                ->whereNull('operador_id')
                ->where('surtido', false)
                ->get()
                ->filter(fn (DetallePedido $detalle): bool => $this->lineaCorrespondeAlOperador($usuario, $detalle->producto));

            if ($libres->isEmpty()) {
                $yaTiene = $pedido->detalles()
                    ->where('operador_id', $usuario->id)
                    ->where('surtido', false)
                    ->exists();

                if ($yaTiene) {
                    return [
                        'error' => false,
                        'message' => 'Ya tienes productos asignados en este pedido.',
                        'pedido' => $pedido->load([
                            'cliente',
                            'detalles.producto.bodega',
                            'detalles.operador:id,name,username',
                            'operador:id,name,username',
                        ]),
                    ];
                }

                return [
                    'error' => true,
                    'status' => 409,
                    'message' => 'No quedan productos de tu bodega en este pedido.',
                ];
            }

            $pedido->detalles()
                ->whereIn('id_detalle_pedido', $libres->pluck('id_detalle_pedido'))
                ->update(['operador_id' => $usuario->id]);

            // La cabecera queda con el operador de la primera linea asignada.
            $operadorPrincipal = $pedido->detalles()
                ->whereNotNull('operador_id')
                ->orderBy('id_detalle_pedido')
                ->value('operador_id');

            $pedido->update(['operador_id' => $operadorPrincipal]);

            app(AuditoriaService::class)->registrar(
                'TOMAR',
                'PEDIDOS',
                "Operador tomó {$libres->count()} productos del pedido.",
                [
                    'id_pedido' => $pedido->id_pedido,
                    'operador_id' => $usuario->id,
                    'lineas' => $libres->pluck('id_detalle_pedido')->all(),
                ],
                request()
            );

            return [
                'error' => false,
                'message' => "Tomaste {$libres->count()} productos. Ya puedes surtirlos.",
                'pedido' => $pedido->refresh()->load([
                    'cliente',
                    'detalles.producto.bodega',
                    'detalles.operador:id,name,username',
                    'operador:id,name,username',
                ]),
            ];
        });

        if ($resultado['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $resultado['message'],
            ], $resultado['status']);
        }

        return response()->json([
            'status' => 'success',
            'message' => $resultado['message'] ?? 'Pedido asignado a tu usuario. Ya puedes surtirlo.',
            'pedido' => $resultado['pedido'],
        ]);
    }

    private function validarOperador(int $operadorId): User
    {
        $operador = User::where('id', $operadorId)->first();

        if (! $operador || $operador->rol !== self::ROL_OPERADOR) {
            throw new \InvalidArgumentException(
                'El usuario seleccionado no es un operador de almacén.'
            );
        }

        if (! $operador->activo) {
            throw new \InvalidArgumentException(
                'El operador seleccionado está inactivo.'
            );
        }

        return $operador;
    }

    /**
     * Una linea (producto) le corresponde al operador si el producto esta en
     * su bodega. Si el operador no tiene bodega asignada puede con cualquier
     * producto. Un producto sin bodega no le corresponde a nadie: hay que
     * ubicarlo antes de poder surtirlo.
     */
    private function lineaCorrespondeAlOperador(?User $operador, ?Producto $producto): bool
    {
        if (! $operador || ! $producto) {
            return false;
        }

        if (! $operador->bodega_id) {
            return true;
        }

        return $producto->bodega_id !== null
            && (int) $producto->bodega_id === (int) $operador->bodega_id;
    }

    /**
     * Un operador con bodega asignada solo puede surtir productos que
     * pertenezcan a su propia bodega (o sin bodega asignada, que no puede
     * ubicarse y por tanto no surtirse).
     *
     * @param  array<int>  $idProductos
     *
     * @throws \InvalidArgumentException
     */
    private function validarBodegaDelOperador(User $operador, array $idProductos): void
    {
        if (! $operador->bodega_id || empty($idProductos)) {
            return;
        }

        $fuera = Producto::query()
            ->whereIn('id_producto', $idProductos)
            ->where(function ($query) use ($operador): void {
                $query->where(function ($bodega) use ($operador): void {
                    $bodega->where('bodega_id', '!=', $operador->bodega_id)
                        ->orWhereNull('bodega_id');
                });
            })
            ->orderBy('nombre')
            ->pluck('nombre');

        if ($fuera->isNotEmpty()) {
            $bodega = Bodega::find($operador->bodega_id)?->nombre ?? 'su bodega';

            throw new \InvalidArgumentException(
                'El operador trabaja en '.$bodega.' y este pedido incluye '.
                'productos de otra bodega (o sin ubicar): '.$fuera->implode(', ').
                '. Asígnalo a un operador de la bodega correcta.'
            );
        }
    }

    public function update(
        Request $request,
        Pedido $pedido
    ) {
        $datos = $request->validate([
            'id_cliente' => [
                'required',
                'integer',
                'exists:clientes,id_cliente',
            ],

            'fecha_pedido' => [
                'required',
                'date',
            ],

            'observaciones' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'detalles' => [
                'required',
                'array',
                'min:1',
            ],

            'detalles.*.id_producto' => [
                'required',
                'integer',
                'distinct',
                'exists:productos,id_producto',
            ],

            'detalles.*.cantidad' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        try {
            $resultado = DB::transaction(function () use (
                $datos,
                $pedido,
                $request
            ) {
                $pedido = Pedido::where(
                    'id_pedido',
                    $pedido->id_pedido
                )
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($pedido->estado !== 'PENDIENTE') {
                    return [
                        'error' => true,
                        'status' => 409,
                        'message' => 'Solo se pueden modificar pedidos pendientes.',
                    ];
                }

                $detallesActuales = $pedido->detalles()
                    ->lockForUpdate()
                    ->get();

                $actuales = $detallesActuales->keyBy(
                    'id_producto'
                );

                $nuevos = collect($datos['detalles'])
                    ->sortBy('id_producto')
                    ->values()
                    ->keyBy('id_producto');

                $idsProductos = collect(
                    $actuales->keys()
                )
                    ->merge($nuevos->keys())
                    ->unique()
                    ->sort()
                    ->values();

                $productos = [];

                foreach ($idsProductos as $idProducto) {
                    $productos[$idProducto] =
                        Producto::where(
                            'id_producto',
                            $idProducto
                        )
                            ->lockForUpdate()
                            ->firstOrFail();
                }

                foreach ($idsProductos as $idProducto) {
                    $cantidadActual =
                        isset($actuales[$idProducto])
                            ? (int) $actuales[$idProducto]->cantidad
                            : 0;

                    $cantidadNueva =
                        isset($nuevos[$idProducto])
                            ? (int) $nuevos[$idProducto]['cantidad']
                            : 0;

                    $diferencia =
                        $cantidadNueva -
                        $cantidadActual;

                    $producto =
                        $productos[$idProducto];

                    if (
                        $cantidadNueva > 0 &&
                        $producto->estado !== 'ACTIVO'
                    ) {
                        return [
                            'error' => true,
                            'status' => 422,
                            'message' => 'El producto "'.
                                $producto->nombre.
                                '" no está disponible.',
                        ];
                    }

                    if (
                        $diferencia > 0 &&
                        $diferencia >
                        $producto->stock_disponible
                    ) {
                        return [
                            'error' => true,
                            'status' => 422,
                            'message' => 'No hay stock suficiente para aumentar "'.
                                $producto->nombre.
                                '". Disponible: '.
                                $producto->stock_disponible.
                                '.',
                        ];
                    }

                    if (
                        $diferencia < 0 &&
                        abs($diferencia) >
                        $producto->stock_reservado
                    ) {
                        return [
                            'error' => true,
                            'status' => 409,
                            'message' => 'La reserva de "'.
                                $producto->nombre.
                                '" presenta una inconsistencia.',
                        ];
                    }
                }

                $importes = [];
                $totalCentavos = 0;

                foreach ($nuevos as $detalle) {
                    $idProducto = $detalle['id_producto'];
                    $importe = $this->calcularImporteDetalle(
                        $productos[$idProducto],
                        (int) $detalle['cantidad']
                    );

                    if ($importe['subtotal_centavos'] > self::MAX_NUMERIC_10_2_CENTAVOS - $totalCentavos) {
                        return [
                            'error' => true,
                            'status' => 422,
                            'message' => 'El total del pedido excede el máximo permitido.',
                        ];
                    }

                    $importes[$idProducto] = $importe;
                    $totalCentavos += $importe['subtotal_centavos'];
                }

                foreach ($idsProductos as $idProducto) {
                    $cantidadActual =
                        isset($actuales[$idProducto])
                            ? (int) $actuales[$idProducto]->cantidad
                            : 0;

                    $cantidadNueva =
                        isset($nuevos[$idProducto])
                            ? (int) $nuevos[$idProducto]['cantidad']
                            : 0;

                    $diferencia =
                        $cantidadNueva -
                        $cantidadActual;

                    $producto =
                        $productos[$idProducto];

                    if ($diferencia > 0) {
                        $this->stockService->reservarPorId($producto->id_producto, $diferencia);
                    } elseif ($diferencia < 0) {
                        $this->stockService->liberarReservaPorId($producto->id_producto, abs($diferencia));
                    }
                }

                // Se conserva la asignacion y el surtido de cada producto
                // mientras el producto siga en el pedido.
                $conservar = $detallesActuales->mapWithKeys(fn ($detalle) => [
                    $detalle->id_producto => [
                        'operador_id' => $detalle->operador_id,
                        'surtido' => $detalle->surtido,
                    ],
                ]);

                $pedido->detalles()->delete();

                foreach ($nuevos as $detalle) {
                    $producto = $productos[$detalle['id_producto']];
                    $importe = $importes[$detalle['id_producto']];
                    $anterior = $conservar[$detalle['id_producto']] ?? null;

                    $pedido->detalles()->create([
                        'id_producto' => $producto->id_producto,

                        'operador_id' => $anterior['operador_id'] ?? null,

                        'cantidad' => $detalle['cantidad'],

                        'precio_unitario' => $importe['precio_unitario'],

                        'subtotal' => $importe['subtotal'],

                        'surtido' => $anterior['surtido'] ?? false,
                    ]);
                }

                $operadorPrincipal = $pedido->detalles()
                    ->whereNotNull('operador_id')
                    ->orderBy('id_detalle_pedido')
                    ->value('operador_id');

                $pedido->update([
                    'id_cliente' => $datos['id_cliente'],
                    'fecha_pedido' => $datos['fecha_pedido'],
                    'operador_id' => $operadorPrincipal,
                    'total' => $totalCentavos / 100,
                    'observaciones' => $datos['observaciones'] ?? null,
                ]);

                app(AuditoriaService::class)->registrar(
                    'ACTUALIZAR',
                    'PEDIDOS',
                    'Pedido actualizado.',
                    ['id_pedido' => $pedido->id_pedido],
                    $request
                );

                return [
                    'error' => false,
                    'pedido' => $pedido->load([
                        'cliente',
                        'detalles.producto.bodega',
                        'detalles.operador:id,name,username',
                    ]),
                ];
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        if ($resultado['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $resultado['message'],
            ], $resultado['status']);
        }

        return response()->json(
            $resultado['pedido']
        );
    }

    public function surtir(Request $request, Pedido $pedido)
    {
        // Confirmación de identidad (step-up): evita que alguien con la
        // tablet desbloqueada surta pedidos ajenos.
        $datos = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $usuario = $request->user();

        if (! $usuario || ! Hash::check($datos['password'], $usuario->password)) {
            app(AuditoriaService::class)->registrar(
                'SURTIM_DENEGADO',
                'PEDIDOS',
                'Intento de surtido sin confirmación de contraseña.',
                ['id_pedido' => $pedido->id_pedido],
                $request
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Confirma tu contraseña para autorizar el surtido.',
            ], 422);
        }

        try {
            $resultado = DB::transaction(function () use ($request, $pedido) {
                $pedido = Pedido::where('id_pedido', $pedido->id_pedido)
                    ->lockForUpdate()
                    ->firstOrFail();

                $usuario = $request->user();

                if (! $usuario) {
                    throw new \InvalidArgumentException(
                        'Debes iniciar sesión para surtir un pedido.'
                    );
                }

                // Cada operador solo surte SUS lineas: las asignadas a el que
                // todavia no se han surtido. Las de otras bodegas quedan para
                // el operador que les corresponda.
                $lineasPropias = $pedido->detalles()
                    ->with('producto')
                    ->where('operador_id', $usuario->id)
                    ->where('surtido', false)
                    ->get();

                if ($lineasPropias->isEmpty()) {
                    throw new \InvalidArgumentException(
                        'Este pedido no tiene productos asignados a tu usuario (o ya los surtiste).'
                    );
                }

                // Blindaje: sus lineas deben ser de su propia bodega.
                $this->validarBodegaDelOperador(
                    $usuario,
                    $lineasPropias->pluck('id_producto')->all()
                );

                if ($pedido->operador_id === null) {
                    $pedido->update([
                        'operador_id' => $usuario->id,
                    ]);
                }

                $this->stockService->surtir($pedido, $usuario->id);

                $pedido = $pedido->refresh()->load([
                    'cliente',
                    'detalles.producto.bodega',
                    'detalles.operador:id,name,username',
                    'operador:id,name,username',
                    'movimientos',
                ]);

                $avance = $pedido->avance_surtido;

                $mensaje = $avance['pendientes'] > 0
                    ? "Surtiste tus productos. Quedan {$avance['pendientes']} de otra bodega por surtir."
                    : 'Pedido surtido correctamente. El inventario fue actualizado.';

                app(AuditoriaService::class)->registrar(
                    'SURTIR',
                    'PEDIDOS',
                    $mensaje,
                    [
                        'id_pedido' => $pedido->id_pedido,
                        'operador_id' => $usuario->id,
                        'avance' => $avance,
                    ],
                    request()
                );

                return [
                    'pedido' => $pedido,
                    'mensaje' => $mensaje,
                ];
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'status' => 'success',
            'message' => $resultado['mensaje'],
            'pedido' => $resultado['pedido'],
        ]);
    }

    public function entregar(Request $request, Pedido $pedido)
    {
        $datos = $request->validate([
            'correo' => ['nullable', 'email', 'max:255'],
        ]);

        $pedido->loadMissing(['cliente', 'detalles.producto.bodega', 'detalles.operador:id,name,username']);

        $destinatario = $datos['correo'] ?? $pedido->cliente?->correo ?? null;

        if (! $destinatario) {
            return response()->json([
                'status' => 'error',
                'message' => 'El cliente no tiene correo configurado. Escribe una dirección de correo y vuelve a intentarlo.',
            ], 422);
        }

        if ($pedido->estado !== 'SURTIDO') {
            return response()->json([
                'status' => 'error',
                'message' => 'El pedido debe estar surtido antes de marcarlo como enviado.',
            ], 409);
        }

        try {
            Mail::to($destinatario)->send(new PedidoEnviadoMail($pedido, $destinatario));
        } catch (\Throwable $e) {
            Log::error('Error al enviar correo de pedido enviado.', [
                'id_pedido' => $pedido->id_pedido,
                'destinatario' => $destinatario,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo enviar el correo al destinatario. El pedido sigue marcado como surtido.',
            ], 503);
        }

        $pedido = DB::transaction(function () use ($pedido, $destinatario) {
            $pedido = Pedido::where('id_pedido', $pedido->id_pedido)
                ->lockForUpdate()
                ->firstOrFail();

            if ($pedido->estado !== 'SURTIDO') {
                return null;
            }

            $pedido->update([
                'estado' => 'ENVIADO',
            ]);

            app(AuditoriaService::class)->registrar(
                'ENTREGAR',
                'PEDIDOS',
                'Pedido enviado al cliente por correo.',
                ['id_pedido' => $pedido->id_pedido, 'destinatario' => $destinatario],
                request()
            );

            return $pedido->load([
                'cliente',
                'detalles.producto.bodega',
                'detalles.operador:id,name,username',
            ]);
        });

        if (! $pedido) {
            return response()->json([
                'status' => 'error',
                'message' => 'El pedido debe estar surtido antes de marcarlo como enviado.',
            ], 409);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pedido enviado. Correo enviado a ' . $destinatario . '.',
            'destinatario' => $destinatario,
            'pedido' => $pedido,
        ]);
    }

    public function recibir(Pedido $pedido)
    {
        $pedido = DB::transaction(function () use ($pedido) {
            $pedido = Pedido::where('id_pedido', $pedido->id_pedido)
                ->lockForUpdate()
                ->firstOrFail();

            if ($pedido->estado !== 'ENVIADO') {
                return null;
            }

            $pedido->update([
                'estado' => 'ENTREGADO',
            ]);

            app(AuditoriaService::class)->registrar(
                'RECIBIR',
                'PEDIDOS',
                'Pedido entregado.',
                ['id_pedido' => $pedido->id_pedido],
                request()
            );

            return $pedido->load([
                'cliente',
                'detalles.producto.bodega',
                'detalles.operador:id,name,username',
            ]);
        });

        if (! $pedido) {
            return response()->json([
                'status' => 'error',
                'message' => 'El pedido debe estar enviado antes de marcarlo como entregado.',
            ], 409);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pedido entregado correctamente.',
            'pedido' => $pedido->load([
                'cliente',
                'detalles.producto.bodega',
                'detalles.operador:id,name,username',
            ]),
        ]);
    }

    public function cancelar(Pedido $pedido)
    {
        try {
            $pedido = DB::transaction(function () use ($pedido) {
                $pedido = Pedido::where('id_pedido', $pedido->id_pedido)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->stockService->cancelar($pedido);

                $pedido = $pedido->refresh()->load([
                    'cliente',
                    'detalles.producto.bodega',
                    'detalles.operador:id,name,username',
                ]);

                app(AuditoriaService::class)->registrar(
                    'CANCELAR',
                    'PEDIDOS',
                    'Pedido cancelado.',
                    ['id_pedido' => $pedido->id_pedido],
                    request()
                );

                return $pedido;
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pedido cancelado y reserva liberada correctamente.',
            'pedido' => $pedido,
        ]);
    }

    public function aceptar(Pedido $pedido)
    {
        if ($pedido->estado !== 'PENDIENTE') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden aceptar pedidos pendientes.',
            ], 409);
        }

        $pedido->update([
            'estado' => 'ACEPTADO',
        ]);

        app(AuditoriaService::class)->registrar(
            'ACEPTAR',
            'PEDIDOS',
            'Pedido aceptado.',
            ['id_pedido' => $pedido->id_pedido],
            request()
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Pedido aceptado correctamente.',
            'pedido' => $pedido->load([
                'cliente',
                'detalles.producto.bodega',
                'detalles.operador:id,name,username',
            ]),
        ]);
    }

    public function destroy(Pedido $pedido)
    {
        $resultado = DB::transaction(function () use ($pedido) {
            $pedido = Pedido::where('id_pedido', $pedido->id_pedido)
                ->lockForUpdate()
                ->firstOrFail();

            if ($pedido->estado !== 'CANCELADO') {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' => 'Primero debes cancelar el pedido.',
                ];
            }

            if ($pedido->movimientos()->exists()) {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' => 'No se puede eliminar un pedido con movimientos asociados.',
                ];
            }

            $idPedido = $pedido->id_pedido;
            $pedido->detalles()->delete();
            $pedido->delete();

            app(AuditoriaService::class)->registrar(
                'ELIMINAR',
                'PEDIDOS',
                'Pedido eliminado.',
                ['id_pedido' => $idPedido],
                request()
            );

            return [
                'error' => false,
            ];
        });

        if ($resultado['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $resultado['message'],
            ], $resultado['status']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pedido eliminado correctamente.',
        ]);
    }
}
