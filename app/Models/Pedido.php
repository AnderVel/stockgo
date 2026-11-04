<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    protected $table = 'pedidos';

    protected $primaryKey = 'id_pedido';

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
}
