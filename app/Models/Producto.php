<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    protected $table = 'productos';

    protected $primaryKey = 'id_producto';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'codigo_barras',
        'nombre',
        'unidad_medida',
        'precio',
        'stock_fisico',
        'stock_reservado',
        'stock_disponible',
        'estado',
        'ubicacion',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'stock_fisico' => 'integer',
        'stock_reservado' => 'integer',
        'stock_disponible' => 'integer',
    ];

    public function detallesPedido(): HasMany
    {
        return $this->hasMany(
            DetallePedido::class,
            'id_producto',
            'id_producto'
        );
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(
            Movimiento::class,
            'id_producto',
            'id_producto'
        );
    }
}