<?php

namespace App\Services;

use Illuminate\Support\Str;

class PasswordPolicyService
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 255;

    /**
     * Valida una contraseña según la política de seguridad.
     *
     * @return string[] Retorna array vacío si es válida, o errores si no lo es.
     */
    public function validar(string $password, ?string $username = null, ?string $email = null): array
    {
        $errores = [];

        // Longitud mínima
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $errores[] = 'La contraseña debe tener al menos '.self::MIN_LENGTH.' caracteres.';
        }

        // Longitud máxima
        if (mb_strlen($password) > self::MAX_LENGTH) {
            $errores[] = 'La contraseña no puede exceder '.self::MAX_LENGTH.' caracteres.';
        }

        // No debe contener el username
        if ($username && Str::contains(Str::lower($password), Str::lower($username))) {
            $errores[] = 'La contraseña no puede contener el nombre de usuario.';
        }

        // No debe contener parte del email (antes del @)
        if ($email) {
            $emailPart = Str::before($email, '@');
            if ($emailPart && Str::contains(Str::lower($password), Str::lower($emailPart))) {
                $errores[] = 'La contraseña no puede contener parte del correo electrónico.';
            }
        }

        // Contraseñas comunes (lista básica)
        $comunes = [
            'password', 'password123', '123456789012', '1234567890123',
            'qwertyuiopas', 'asdfghjklzxc', 'adminadmin123',
            'stockgo12345', 'almacen12345', 'seguridad123',
        ];

        $lowerPassword = Str::lower($password);
        foreach ($comunes as $comun) {
            if (Str::contains($lowerPassword, $comun)) {
                $errores[] = 'La contraseña es demasiado común y fácil de adivinar.';
                break;
            }
        }

        // Secuencias obvias
        if ($this->tieneSecuenciaObvia($password)) {
            $errores[] = 'La contraseña contiene secuencias obvias (ej. 123456, abcdef).';
        }

        return $errores;
    }

    private function tieneSecuenciaObvia(string $password): bool
    {
        $lower = Str::lower($password);

        // Secuencias numéricas
        for ($i = 0; $i <= 6; $i++) {
            $seq = '';
            for ($j = 0; $j < 6; $j++) {
                $seq .= (string) (($i + $j) % 10);
            }
            if (Str::contains($lower, $seq)) {
                return true;
            }
        }

        // Secuencias alfabéticas
        for ($i = 0; $i <= 20; $i++) {
            $seq = '';
            for ($j = 0; $j < 6; $j++) {
                $seq .= chr(ord('a') + (($i + $j) % 26));
            }
            if (Str::contains($lower, $seq)) {
                return true;
            }
        }

        // Secuencias en teclado (qwerty, asdfgh, etc.)
        $teclado = ['qwerty', 'asdfgh', 'zxcvbn', 'qazwsx', '1qaz2wsx'];
        foreach ($teclado as $seq) {
            if (Str::contains($lower, $seq)) {
                return true;
            }
        }

        return false;
    }
}
