<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_pedidos', function (Blueprint $table) {
            $table->id('id_detalle_pedido');

            $table->unsignedBigInteger('id_pedido');
            $table->unsignedBigInteger('id_producto');

            $table->unsignedInteger('cantidad');
            $table->decimal('precio_unitario', 10, 2);
            $table->decimal('subtotal', 10, 2);

            $table->timestamps();

            $table->foreign('id_pedido')
                ->references('id_pedido')
                ->on('pedidos')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('id_producto')
                ->references('id_producto')
                ->on('productos')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['id_pedido', 'id_producto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_pedidos');
    }
};