<?php

return [
    'secret' => env('JWT_SECRET'),
    'ttl_minutes' => (int) env('JWT_TTL_MINUTES', 30),
    'refresh_ttl_days' => (int) env('JWT_REFRESH_TTL_DAYS', 7),
];
