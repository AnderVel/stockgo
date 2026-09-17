<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    protected $table = 'proveedores';

    protected $primaryKey = 'id_proveedor';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'nombre',
        'contacto',
        'telefono',
        'correo',
        'direccion',
        'estado',
    ];

    public function movimientos(): HasMany
    {
        return $this->hasMany(
            Movimiento::class,
            'id_proveedor',
            'id_proveedor'
        );
    }
}