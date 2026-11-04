<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bodegas')) {
            Schema::create('bodegas', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 100)->unique();
                $table->string('descripcion')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('productos', 'bodega_id')) {
            Schema::table('productos', function (Blueprint $table) {
                $table->foreignId('bodega_id')
                    ->nullable()
                    ->after('ubicacion')
                    ->constrained('bodegas')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('users', 'bodega_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('bodega_id')
                    ->nullable()
                    ->after('bodega_asignada')
                    ->constrained('bodegas')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'bodega_id')) {
                $table->dropForeign(['bodega_id']);
                $table->dropColumn('bodega_id');
            }
        });

        Schema::table('productos', function (Blueprint $table) {
            if (Schema::hasColumn('productos', 'bodega_id')) {
                $table->dropForeign(['bodega_id']);
                $table->dropColumn('bodega_id');
            }
        });

        Schema::dropIfExists('bodegas');
    }
};
