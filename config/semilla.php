<?php

return [

    /*
        Cuantos meses hacia atras se siembra, contando el mes actual como 0.
        6 = los 5 meses cerrados anteriores mas el mes en curso hasta hoy.
    */
    'meses_atras' => env('SEMILLA_MESES_ATRAS', 6),

    /* Ventas brutas del mes mas viejo. Los meses siguientes crecen sobre este. */
    'ventas_mes_base' => env('SEMILLA_VENTAS_MES_BASE', 10000000),

    /* Crecimiento porcentual mes a mes, para que los graficos tengan pendiente. */
    'crecimiento_mensual' => env('SEMILLA_CRECIMIENTO_MENSUAL', 10),

    /*
        Semilla del generador aleatorio. FIJA a proposito: dos corridas del comando tienen que
        producir exactamente los mismos datos, si no es imposible comparar una corrida con otra
        cuando algo cambia.
    */
    'semilla_aleatoria' => env('SEMILLA_ALEATORIA', 20260803),

    /* Owner al que se le siembra. Por default el mismo de siempre. */
    'user_id' => env('SEMILLA_USER_ID', env('USER_ID')),
];
