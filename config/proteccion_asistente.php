<?php

return [
    'store' => env('ASISTENTE_CUPOS_STORE'),
    // Mayor que el timeout FPM de 300 s; nunca se sostiene durante una llamada al modelo.
    'lock_ttl' => 360,
    'herramientas' => ['persona_minuto' => 30, 'persona_hora' => 300, 'negocio_minuto' => 60, 'negocio_hora' => 600],
    'escrituras' => ['persona_minuto' => 6, 'persona_hora' => 60, 'negocio_minuto' => 12, 'negocio_hora' => 120],
    'concurrencia_persona' => 1,
    'concurrencia_negocio' => 2,
    'mcp_batch' => 20,
    'mcp_bytes' => 262144,
    'masivas' => [
        'tamanio' => 50,
        'pausa' => 5,
        'pendientes' => 2,
        'deadline_segundos' => 7200,
        'reconciliar_cada' => 120,
        'reconciliar_maximo' => 10,
    ],
];
