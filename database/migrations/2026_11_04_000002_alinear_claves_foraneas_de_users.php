<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Alinea las claves foráneas hacia `users` para poder eliminar
     * usuarios sin romper la trazabilidad:
     *  - auditorias.usuario_id, movimientos.usuario_id y
     *    pedidos.operador_id  → ON DELETE SET NULL (la historia se conserva)
     *  - web_refresh_tokens y email_verification_tokens → CASCADE
     */

    /** @var array<int, array{tabla: string, columna: string, del: string}> */
    private array $reglas = [
        ['tabla' => 'auditorias', 'columna' => 'usuario_id', 'del' => 'SET NULL'],
        ['tabla' => 'movimientos', 'columna' => 'usuario_id', 'del' => 'SET NULL'],
        ['tabla' => 'pedidos', 'columna' => 'operador_id', 'del' => 'SET NULL'],
        ['tabla' => 'web_refresh_tokens', 'columna' => 'user_id', 'del' => 'CASCADE'],
        ['tabla' => 'email_verification_tokens', 'columna' => 'user_id', 'del' => 'CASCADE'],
    ];

    public function up(): void
    {
        foreach ($this->reglas as $regla) {
            $this->alinear($regla['tabla'], $regla['columna'], $regla['del']);
        }
    }

    public function down(): void
    {
        foreach ($this->reglas as $regla) {
            $this->alinear($regla['tabla'], $regla['columna'], 'RESTRICT');
        }
    }

    private function alinear(string $tabla, string $columna, string $onDelete): void
    {
        // Nombres de tabla/columna provienen de esta clase, nunca de entrada
        // del usuario; los valores se fijan a la lista de reglas de arriba.
        $fk = DB::selectOne(
            "select conname, confdeltype from pg_constraint
             where contype = 'f'
               and conrelid = ?::regclass
               and confrelid = 'users'::regclass",
            [$tabla]
        );

        if (! $fk) {
            return;
        }

        $esperado = match ($onDelete) {
            'SET NULL' => 'n',
            'CASCADE' => 'c',
            default => 'a',
        };

        if ($fk->confdeltype === $esperado) {
            return;
        }

        DB::statement("alter table {$tabla} drop constraint {$fk->conname}");

        DB::statement(
            "alter table {$tabla} add constraint {$fk->conname}
             foreign key ({$columna}) references users (id)
             on delete {$onDelete}"
        );
    }
};
