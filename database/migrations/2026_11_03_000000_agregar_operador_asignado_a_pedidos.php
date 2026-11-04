<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->unsignedBigInteger('operador_id')
                ->nullable()
                ->after('id_cliente');

            $table->index('operador_id');

            $table->foreign('operador_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropForeign(['operador_id']);
            $table->dropIndex(['operador_id']);
            $table->dropColumn('operador_id');
        });
    }
};
