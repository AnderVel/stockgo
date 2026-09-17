<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Cliente::create([
            'nombre' => 'Abarrotes La Esperanza',
            'telefono' => '9611234567',
            'correo' => 'cliente@example.com',
            'direccion' => 'Tuxtla Gutiérrez, Chiapas',
            'estado' => 'ACTIVO',
        ]);

        Proveedor::create([
            'nombre' => 'Distribuidora Chiapas',
            'contacto' => 'Carlos López',
            'telefono' => '9617654321',
            'correo' => 'ventas@example.com',
            'direccion' => 'Tuxtla Gutiérrez, Chiapas',
            'estado' => 'ACTIVO',
        ]);

        Producto::create([
            'codigo_barras' => '750123450001',
            'nombre' => 'Caja de Galletas Surtidas',
            'unidad_medida' => 'Caja con 24 pzas',
            'precio' => 250.50,
            'stock_fisico' => 100,
            'stock_reservado' => 0,
            'stock_disponible' => 100,
            'estado' => 'ACTIVO',
            'ubicacion' => 'Pasillo 3 - Estante B',
        ]);
    }
}