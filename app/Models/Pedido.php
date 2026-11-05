<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Pedido extends Model
{
    protected $table = 'pedidos';

    protected $primaryKey = 'id_pedido';

    /** Se calculan con el usuario autenticado (lineas propias, avance, etc.). */
    protected $appends = [
        'lineas_mias',
        'lineas_pendientes_mias',
        'puedo_tomar',
        'avance_surtido',
    ];

    protected $fillable = [
        'id_cliente',
        'operador_id',
        'fecha_pedido',
        'estado',
        'total',
        'observaciones',
    ];

    protected $casts = [
        'fecha_pedido' => 'date',
        'total' => 'decimal:2',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(
            Cliente::class,
            'id_cliente',
            'id_cliente'
        );
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'operador_id',
            'id'
        );
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(
            DetallePedido::class,
            'id_pedido',
            'id_pedido'
        );
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(
            Movimiento::class,
            'id_pedido',
            'id_pedido'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Vistas calculadas para el usuario autenticado
    |--------------------------------------------------------------------------
    | El backend las agrega a cada pedido para que la app sepa que lineas le
    | corresponden al operador que hizo la peticion, sin que la app tenga que
    | adivinar de que bodega es cada producto.
    */

    /** @return list<int> Lineas (productos) de este pedido asignadas al usuario actual. */
    public function getLineasMiasAttribute(): array
    {
        $usuarioId = Auth::id();

        if ($usuarioId === null || ! $this->relationLoaded('detalles')) {
            return [];
        }

        return $this->detalles
            ->where('operador_id', $usuarioId)
            ->pluck('id_detalle_pedido')
            ->values()
            ->all();
    }

    /** @return list<int> Lineas asignadas al usuario actual que aun no surte. */
    public function getLineasPendientesMiasAttribute(): array
    {
        $usuarioId = Auth::id();

        if ($usuarioId === null || ! $this->relationLoaded('detalles')) {
            return [];
        }

        return $this->detalles
            ->where('operador_id', $usuarioId)
            ->where('surtido', false)
            ->pluck('id_detalle_pedido')
            ->values()
            ->all();
    }

    /**
     * Indica si al usuario actual le quedan lineas libres de su bodega en
     * este pedido (es decir, si todavia puede tomarlo).
     */
    public function getPuedoTomarAttribute(): bool
    {
        $usuario = Auth::user();

        if (! $usuario || $this->estado !== 'PENDIENTE') {
            return false;
        }

        $libres = $this->detalles()
            ->whereNull('operador_id')
            ->where('surtido', false);

        if ($usuario->bodega_id) {
            $libres->whereHas('producto', function ($producto) use ($usuario): void {
                $producto->where('bodega_id', $usuario->bodega_id);
            });
        }

        return $libres->exists();
    }

    /** @return array{surtidos: int, total: int, pendientes: int} */
    public function getAvanceSurtidoAttribute(): array
    {
        if (! $this->relationLoaded('detalles')) {
            $total = $this->detalles()->count();
            $surtidos = $this->detalles()->where('surtido', true)->count();
        } else {
            $total = $this->detalles->count();
            $surtidos = $this->detalles->where('surtido', true)->count();
        }

        return [
            'surtidos' => $surtidos,
            'total' => $total,
            'pendientes' => $total - $surtidos,
        ];
    }
}
