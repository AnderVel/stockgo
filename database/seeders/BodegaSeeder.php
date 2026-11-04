<?php

namespace Database\Seeders;

use App\Models\Bodega;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Seeder;

class BodegaSeeder extends Seeder
{
    /**
     * Crea las bodegas base y vincula productos/operadores existentes.
     * Idempotente: puede ejecutarse varias veces.
     */
    public function run(): void
    {
        $definiciones = [
            [
                'nombre' => 'Bodega 1',
                'descripcion' => 'Pasillos 1 al 5 — mercancía de alta rotación',
            ],
            [
                'nombre' => 'Bodega 2',
                'descripcion' => 'Bebidas y producto perecedero',
            ],
            [
                'nombre' => 'Bodega 3',
                'descripcion' => 'Devoluciones y excedentes',
            ],
        ];

        $bodegas = [];

        foreach ($definiciones as $def) {
            $bodegas[$def['nombre']] = Bodega::updateOrCreate(
                ['nombre' => $def['nombre']],
                ['descripcion' => $def['descripcion']]
            );
        }

        // Vincular productos existentes cuyo ubicacion menciona la bodega.
        foreach (Producto::whereNull('bodega_id')->get() as $producto) {
            $ubicacion = mb_strtolower($producto->ubicacion ?? '');

            $nombre = match (true) {
                str_contains($ubicacion, 'bodega 2') || str_contains($ubicacion, 'nave 2') => 'Bodega 2',
                str_contains($ubicacion, 'bodega 3') => 'Bodega 3',
                str_contains($ubicacion, 'bodega 1') || str_contains($ubicacion, 'nave 1') || str_contains($ubicacion, 'almacen') => 'Bodega 1',
                default => null,
            };

            if ($nombre) {
                $producto->update(['bodega_id' => $bodegas[$nombre]->id]);
            }
        }

        // Vincular operadores cuyo texto heredado coincide con una bodega.
        foreach (User::where('rol', 'Operador de Almacén')->whereNull('bodega_id')->get() as $usuario) {
            $texto = mb_strtolower(trim($usuario->bodega_asignada ?? ''));

            $coincidencia = collect($bodegas)
                ->first(fn (Bodega $b, string $nombre) => $texto !== '' && str_contains($texto, mb_strtolower($nombre)));

            if ($coincidencia) {
                $usuario->update(['bodega_id' => $coincidencia->id]);
            }
        }
    }
}
