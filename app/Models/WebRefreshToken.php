<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebRefreshToken extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'revoked_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function esValido(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
