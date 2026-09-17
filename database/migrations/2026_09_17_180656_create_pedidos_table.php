<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id('id_pedido');

            $table->unsignedBigInteger('id_cliente');

            $table->date('fecha_pedido');

            $table->string('estado')->default('PENDIENTE');

            $table->decimal('total', 10, 2)->default(0);

            $table->text('observaciones')->nullable();

            $table->timestamps();

            $table->foreign('id_cliente')
                ->references('id_cliente')
                ->on('clientes')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};