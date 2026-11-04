<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bodega extends Model
{
    protected $table = 'bodegas';

    protected $fillable = [
        'nombre',
        'descripcion',
    ];

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'bodega_id');
    }
}
