<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Auditoria extends Model
{
    protected $table = 'auditorias';

    protected $primaryKey = 'id_auditoria';

    protected $fillable = [
        'usuario_id',
        'accion',
        'modulo',
        'descripcion',
        'ip_address',
        'user_agent',
        'detalles',
    ];

    protected $casts = [
        'detalles' => 'array',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'usuario_id',
            'id'
        );
    }
}