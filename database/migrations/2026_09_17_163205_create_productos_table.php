<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id('id_producto');
            $table->string('codigo_barras')->unique();
            $table->string('nombre');
            $table->string('unidad_medida');
            $table->decimal('precio', 10, 2);
            $table->integer('stock_fisico')->default(0);
            $table->integer('stock_reservado')->default(0);
            $table->integer('stock_disponible')->default(0);
            $table->string('estado')->default('ACTIVO');
            $table->string('ubicacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};