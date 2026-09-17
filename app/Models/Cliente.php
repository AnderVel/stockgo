<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $table = 'clientes';

    protected $primaryKey = 'id_cliente';

    protected $fillable = [
        'nombre',
        'telefono',
        'correo',
        'direccion',
        'estado',
    ];

    public function pedidos(): HasMany
    {
        return $this->hasMany(
            Pedido::class,
            'id_cliente',
            'id_cliente'
        );
    }
}