<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropForeign(['id_cliente']);
            $table->renameColumn('id_cliente', 'id_proveedor');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreign('id_proveedor')
                ->references('id_proveedor')
                ->on('proveedores')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropForeign(['id_proveedor']);
            $table->renameColumn('id_proveedor', 'id_cliente');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreign('id_cliente')
                ->references('id_cliente')
                ->on('clientes')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }
};