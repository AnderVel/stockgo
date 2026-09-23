<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')
                ->nullable()
                ->unique()
                ->after('name');

            $table->string('rol')
                ->default('Operador de Almacén')
                ->after('password');

            $table->string('bodega_asignada')
                ->default('Central - Pasillos 1 al 5')
                ->after('rol');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');

            $table->dropColumn([
                'username',
                'rol',
                'bodega_asignada',
            ]);
        });
    }
};