<?php

namespace App\Console\Commands;

use App\Models\WebRefreshToken;
use Illuminate\Console\Command;

class PruneWebRefreshTokens extends Command
{
    protected $signature = 'tokens:purge';

    protected $description = 'Elimina refresh tokens web expirados o revocados';

    public function handle(): int
    {
        $count = WebRefreshToken::where(function ($q) {
            $q->where('expires_at', '<', now())
              ->orWhereNotNull('revoked_at');
        })->delete();

        $this->info("Tokens purgados: {$count}");

        return self::SUCCESS;
    }
}
