<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailVerificationToken extends Model
{
    protected $table = 'email_verification_tokens';

    protected $fillable = [
        'user_id',
        'token_hash',
        'tipo',
        'validation_attempts',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'validation_attempts' => 'integer',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
