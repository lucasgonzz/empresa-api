<?php

return [
    'activo' => env('ARTICULOS_COSTOSOS_PROTECCION', true),
    'store' => env('ARTICULOS_COSTOSOS_CACHE_STORE'),
    // Superior al request_terminate_timeout de PHP-FPM (300 s); no esperar un lugar libre.
    'lock_ttl' => (int) env('ARTICULOS_COSTOSOS_LOCK_TTL', 360),
    'concurrencia_persona' => (int) env('ARTICULOS_COSTOSOS_CONCURRENCIA_PERSONA', 1),
    'concurrencia_negocio' => (int) env('ARTICULOS_COSTOSOS_CONCURRENCIA_NEGOCIO', 2),
    'lectura' => [
        'persona_minuto' => (int) env('ARTICULOS_COSTOSOS_LECTURA_PERSONA_MINUTO', 120),
        'persona_hora' => (int) env('ARTICULOS_COSTOSOS_LECTURA_PERSONA_HORA', 1200),
        'negocio_minuto' => (int) env('ARTICULOS_COSTOSOS_LECTURA_NEGOCIO_MINUTO', 240),
        'negocio_hora' => (int) env('ARTICULOS_COSTOSOS_LECTURA_NEGOCIO_HORA', 2400),
    ],
    'escritura' => [
        'persona_minuto' => (int) env('ARTICULOS_COSTOSOS_ESCRITURA_PERSONA_MINUTO', 30),
        'persona_hora' => (int) env('ARTICULOS_COSTOSOS_ESCRITURA_PERSONA_HORA', 300),
        'negocio_minuto' => (int) env('ARTICULOS_COSTOSOS_ESCRITURA_NEGOCIO_MINUTO', 60),
        'negocio_hora' => (int) env('ARTICULOS_COSTOSOS_ESCRITURA_NEGOCIO_HORA', 600),
    ],
];
