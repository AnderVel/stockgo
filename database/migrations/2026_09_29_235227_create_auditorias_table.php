<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditorias', function (Blueprint $table) {
            $table->id('id_auditoria');

            $table->foreignId('usuario_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('accion', 50);
            $table->string('modulo', 50);
            $table->text('descripcion');

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->json('detalles')->nullable();

            $table->timestamps();

            $table->index(['modulo', 'accion']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditorias');
    }
};