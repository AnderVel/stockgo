<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La tabla ya existe en bases de datos antiguas (fue creada
        // manualmente en Neon); este guard permite migraciones limpias
        // en instalaciones nuevas sin romper las existentes.
        if (Schema::hasTable('email_verification_tokens')) {
            return;
        }

        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('token_hash', 64)->unique();
            $table->string('tipo', 40);
            $table->unsignedSmallInteger('validation_attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tipo', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_tokens');
    }
};
