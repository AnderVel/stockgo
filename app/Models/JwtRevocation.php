<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JwtRevocation extends Model
{
    protected $fillable = [
        'jti_hash',
        'expires_at',
        'scope',
    ];
}
