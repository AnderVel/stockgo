<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Verificación de correo electrónico
    |--------------------------------------------------------------------------
    |
    | Tiempo de expiración (minutos) del código de verificación enviado al
    | correo del usuario y número máximo de reintentos antes de invalidarlo.
    |
    */

    'expiration_minutes' => (int) env('EMAIL_VERIFICATION_EXPIRATION_MINUTES', 15),

    'max_attempts' => (int) env('EMAIL_VERIFICATION_MAX_ATTEMPTS', 5),

];
