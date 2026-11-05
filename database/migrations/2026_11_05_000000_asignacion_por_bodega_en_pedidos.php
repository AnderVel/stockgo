<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un mismo pedido puede mezclar bodegas, por lo que la asignacion ya no
 * vive solo en `pedidos.operador_id`: cada linea (producto) lleva su propio
 * operador y su bandera de surtido, de modo que los productos de la bodega 1
 * los surte un operador y los de la bodega 2 otro, dentro del mismo pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('detalle_pedidos', 'operador_id')) {
            Schema::table('detalle_pedidos', function (Blueprint $table): void {
                $table->foreignId('operador_id')
                    ->nullable()
                    ->after('id_producto')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('detalle_pedidos', 'surtido')) {
            Schema::table('detalle_pedidos', function (Blueprint $table): void {
                $table->boolean('surtido')
                    ->default(false)
                    ->after('subtotal');
            });
        }

        // Los pedidos ya asignados conservan su operador en todas sus lineas
        // para que el flujo existente no se rompa.
        DB::statement(<<<'SQL'
            UPDATE detalle_pedidos d
            SET operador_id = p.operador_id
            FROM pedidos p
            WHERE p.id_pedido = d.id_pedido
              AND p.operador_id IS NOT NULL
              AND d.operador_id IS NULL
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasColumn('detalle_pedidos', 'operador_id')) {
            Schema::table('detalle_pedidos', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('operador_id');
            });
        }

        if (Schema::hasColumn('detalle_pedidos', 'surtido')) {
            Schema::table('detalle_pedidos', function (Blueprint $table): void {
                $table->dropColumn('surtido');
            });
        }
    }
};
