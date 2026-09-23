<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Cliente::updateOrCreate(
            [
                'correo' => 'cliente@example.com',
            ],
            [
                'nombre' => 'Abarrotes La Esperanza',
                'telefono' => '9611234567',
                'direccion' => 'Tuxtla Gutiérrez, Chiapas',
                'estado' => 'ACTIVO',
            ]
        );

        Proveedor::updateOrCreate(
            [
                'correo' => 'ventas@example.com',
            ],
            [
                'nombre' => 'Distribuidora Chiapas',
                'contacto' => 'Carlos López',
                'telefono' => '9617654321',
                'direccion' => 'Tuxtla Gutiérrez, Chiapas',
                'estado' => 'ACTIVO',
            ]
        );

        Producto::updateOrCreate(
            [
                'codigo_barras' => '750123450001',
            ],
            [
                'nombre' => 'Caja de Galletas Surtidas',
                'unidad_medida' => 'Caja con 24 pzas',
                'precio' => 250.50,
                'stock_fisico' => 100,
                'stock_reservado' => 0,
                'stock_disponible' => 100,
                'estado' => 'ACTIVO',
                'ubicacion' => 'Pasillo 3 - Estante B',
            ]
        );

        User::updateOrCreate(
            [
                'username' => 'admin',
            ],
            [
                'name' => 'Kevin',
                'email' => 'admin@stockgo.test',
                'password' => 'password123',
                'rol' => 'Operador de Almacén',
                'bodega_asignada' => 'Central - Pasillos 1 al 5',
            ]
        );
    }
}