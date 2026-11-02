<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\JwtService;
use App\Models\JwtRevocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class JwtSecretRotate extends Command
{
    protected $signature = 'app:jwt-secret-rotate';

    protected $description = 'Rotación controlada del JWT_SECRET. Solo para uso en mantenimiento autorizado.';

    public function handle(): int
    {
        $this->warn('=== PELIGRO ===');
        $this->warn('Este comando debe ejecutarse SOLO durante una ventana de mantenimiento autorizada.');
        $this->warn('Ejecutarlo en producción sin autorización cerrará sesiones de forma masiva.');
        $this->newLine();

        $currentSecret = config('jwt.secret');
        $this->info('Secret JWT actual: ' . str_repeat('*', min(20, strlen($currentSecret))) . '...' . substr($currentSecret, -4));
        $this->newLine();

        $this->info('PASOS NECESARIOS PARA LA ROTACIÓN:');
        $this->newLine();
        $this->line('1. Generar un nuevo secreto seguro (fuera del repositorio, ej. php artisan tinker -> bin2hex(random_bytes(32)))');
        $this->line('2. Ejecutar: php artisan config:clear');
        $this->line('3. Actualizar el archivo .env con el nuevo JWT_SECRET');
        $this->line('4. Ejecutar: php artisan jwt:revoke-active-tokens (para revocar refresh tokens afectados)');
        $this->line('5. Ejecutar: php artisan config:cache (para recargar configuración)');
        $this->line('6. Reiniciar los procesos de la aplicación (PHP-FPM, workers, etc.)');
        $this->line('7. Forzar nuevo inicio de sesión en Android y Web Admin');
        $this->newLine();

        $this->info('MODO DRY-RUN (solo simula, no ejecuta cambios reales):');
        $this->line('Ejecutar con bandera --dry-run para verificar los pasos sin aplicar cambios');

        $this->newLine();
        $this->error('IMPORTANTE: No ejecutar en producción sin ventana de mantenimiento y autorización específica.');
        $this->newLine();
        $this->warn('Si procede con la rotación, se cerrarán sesiones activas de Web Admin y podría afectar la sesión de Android.');
        $this->newLine();

        return 0;
    }
}