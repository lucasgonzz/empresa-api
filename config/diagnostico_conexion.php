<?php

return [
    // IDs de las PERSONAS autenticadas, no del dueño: habilitar uno no habilita empleados.
    'usuarios' => array_values(array_filter(array_map('trim', explode(',', (string) env('DIAGNOSTICO_CONEXION_USUARIOS', ''))), function ($id) {
        return preg_match('/^[1-9][0-9]*$/D', $id) === 1;
    })),
];
