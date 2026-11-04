<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PedidoEnviadoMail;
use App\Models\Cliente;
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
            'detalles.producto',
            'operador:id,name,username',
        ])->orderByDesc('id_pedido');

        // El operario solo ve los pedidos que tiene asignados y los que
        // todavía no tienen dueño (para poder tomarlos). Así un pedido no
        // aparece en la lista de todos los operarios a la vez.
        if ($usuario && $usuario->rol === self::ROL_OPERADOR) {
            $query->where(function ($builder) use ($usuario): void {
                $builder->where('operador_id', $usuario->id)
                    ->orWhere(function ($sinAsignar): void {
                        $sinAsignar->whereNull('operador_id')
                            ->where('estado', 'PENDIENTE');
                    });
            });
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->query('estado'));
        }

        if ($request->filled('operador_id')) {
            $query->where('operador_id', $request->integer('operador_id'));
        }

        if ($request->boolean('sin_asignar')) {
            $query->whereNull('operador_id');
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

            if (! empty($datos['operador_id'])) {
                $this->validarOperador((int) $datos['operador_id']);
            }

            $operacion = 'abrir_transaccion';

            $pedido = DB::transaction(function () use ($datos, $request, &$operacion) {
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
                    'detalles.producto',
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

        if (
            $usuario &&
            $usuario->rol === self::ROL_OPERADOR &&
            $pedido->operador_id !== null &&
            (int) $pedido->operador_id !== (int) $usuario->id
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Este pedido está asignado a otro operador.',
            ], 403);
        }

        return response()->json(
            $pedido->load([
                'cliente',
                'detalles.producto',
                'operador:id,name,username',
                'movimientos',
            ])
        );
    }

    /**
     * Asigna el pedido a un operador de almacén (solo administración).
     * Enviar "operador_id": null libera la asignación.
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
        ]);

        if (! empty($datos['operador_id'])) {
            try {
                $this->validarOperador((int) $datos['operador_id']);
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        $resultado = DB::transaction(function () use ($datos, $pedido) {
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

            $pedido->update([
                'operador_id' => $datos['operador_id'] ?? null,
            ]);

            app(AuditoriaService::class)->registrar(
                'ASIGNAR',
                'PEDIDOS',
                'Pedido asignado a un operador.',
                [
                    'id_pedido' => $pedido->id_pedido,
                    'operador_id' => $pedido->operador_id,
                ],
                request()
            );

            return [
                'error' => false,
                'pedido' => $pedido->refresh()->load([
                    'cliente',
                    'detalles.producto',
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
            'message' => $resultado['pedido']->operador_id
                ? 'Pedido asignado correctamente.'
                : 'Asignación del pedido liberada.',
            'pedido' => $resultado['pedido'],
        ]);
    }

    /**
     * El operario toma un pedido que todavía no tiene dueño. La actualización
     * es condicional (operador_id sigue nulo) para que dos operarios no puedan
     * quedarse con el mismo pedido.
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

            if ($pedido->operador_id !== null) {
                if ((int) $pedido->operador_id !== (int) $usuario->id) {
                    return [
                        'error' => true,
                        'status' => 409,
                        'message' => 'Otro operador ya tomó este pedido.',
                    ];
                }

                return [
                    'error' => false,
                    'pedido' => $pedido->load([
                        'cliente',
                        'detalles.producto',
                        'operador:id,name,username',
                    ]),
                ];
            }

            $pedido->update([
                'operador_id' => $usuario->id,
            ]);

            app(AuditoriaService::class)->registrar(
                'TOMAR',
                'PEDIDOS',
                'Pedido tomado por un operador.',
                [
                    'id_pedido' => $pedido->id_pedido,
                    'operador_id' => $usuario->id,
                ],
                request()
            );

            return [
                'error' => false,
                'pedido' => $pedido->refresh()->load([
                    'cliente',
                    'detalles.producto',
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
            'message' => 'Pedido asignado a tu usuario. Ya puedes surtirlo.',
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

                $pedido->detalles()->delete();

                foreach ($nuevos as $detalle) {
                    $producto = $productos[$detalle['id_producto']];
                    $importe = $importes[$detalle['id_producto']];

                    $pedido->detalles()->create([
                        'id_producto' => $producto->id_producto,

                        'cantidad' => $detalle['cantidad'],

                        'precio_unitario' => $importe['precio_unitario'],

                        'subtotal' => $importe['subtotal'],
                    ]);
                }

                $pedido->update([
                    'id_cliente' => $datos['id_cliente'],
                    'fecha_pedido' => $datos['fecha_pedido'],
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
                        'detalles.producto',
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
            $pedido = DB::transaction(function () use ($request, $pedido) {
                $pedido = Pedido::where('id_pedido', $pedido->id_pedido)
                    ->lockForUpdate()
                    ->firstOrFail();

                $usuario = $request->user();

                if (
                    $pedido->operador_id !== null &&
                    (int) $pedido->operador_id !== (int) ($usuario?->id ?? 0)
                ) {
                    throw new \InvalidArgumentException(
                        'Este pedido está asignado a otro operador. No puedes surtirlo.'
                    );
                }

                if ($pedido->operador_id === null && $usuario) {
                    $pedido->update([
                        'operador_id' => $usuario->id,
                    ]);
                }

                $this->stockService->surtir($pedido);

                $pedido = $pedido->refresh()->load([
                    'cliente',
                    'detalles.producto',
                    'operador:id,name,username',
                    'movimientos',
                ]);

                app(AuditoriaService::class)->registrar(
                    'SURTIR',
                    'PEDIDOS',
                    'Pedido surtido.',
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
            'message' => 'Pedido surtido correctamente. El inventario fue actualizado.',
            'pedido' => $pedido,
        ]);
    }

    public function entregar(Request $request, Pedido $pedido)
    {
        $datos = $request->validate([
            'correo' => ['nullable', 'email', 'max:255'],
        ]);

        $pedido->loadMissing(['cliente', 'detalles.producto']);

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
                'detalles.producto',
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
                'detalles.producto',
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
                'detalles.producto',
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
                    'detalles.producto',
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
                'detalles.producto',
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
