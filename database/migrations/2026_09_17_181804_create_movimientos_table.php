<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos', function (Blueprint $table) {
            $table->id('id_movimiento');

            $table->unsignedBigInteger('id_producto');

            $table->string('tipo');

            $table->integer('cantidad');

            $table->string('motivo')->nullable();

            $table->unsignedBigInteger('id_pedido')->nullable();

            $table->unsignedBigInteger('id_proveedor')->nullable();

            $table->integer('stock_anterior')->nullable();

            $table->integer('stock_nuevo')->nullable();

            $table->timestamps();

            $table->foreign('id_producto')
                ->references('id_producto')
                ->on('productos')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('id_pedido')
                ->references('id_pedido')
                ->on('pedidos')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('id_proveedor')
                ->references('id_proveedor')
                ->on('proveedores')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos');
    }
};