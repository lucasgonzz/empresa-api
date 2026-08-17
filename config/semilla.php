<?php

return [

    /*
        Cuantos meses hacia atras se siembra, contando el mes actual como 0.
        12 = los 11 meses cerrados anteriores mas el mes en curso hasta hoy. Es el largo de
        SembrarDatosDePrueba::CADENCIA_VENTAS_POR_MES: con este valor la rampa entra completa y el
        mes mas viejo arranca en las 4 ventas de la serie. Con un valor mas chico se toman las
        ULTIMAS entradas de la serie (el mes actual siempre es el pico de 149 ventas).
    */
    'meses_atras' => env('SEMILLA_MESES_ATRAS', 12),

    /*
        Ventas brutas de un mes suelto.

        🔴 handle() YA NO LO USA: la corrida completa saca las ventas de cada mes de
        SembrarDatosDePrueba::CADENCIA_VENTAS_POR_MES multiplicada por TICKET_PROMEDIO. Este valor
        sigue existiendo porque es el punto de entrada de UN mes suelto: es lo que le pasa
        `tests/Feature/Reportes/4_Semilla_Test.php:102` a `sembrar_mes()` para sembrar su unico mes
        de abril de 2018. Borrarlo de aca deja al test sembrando 0 y muriendo en la primera
        asercion, asi que no se saca.

        400.000 = 4 ventas del ticket promedio de 100.000, que es exactamente el mes mas viejo de
        la rampa. Antes eran 10.000.000, que con el ticket nuevo son 100 ventas y convierten ese
        test en una corrida de minutos.
    */
    'ventas_mes_base' => env('SEMILLA_VENTAS_MES_BASE', 400000),

    /*
        Semilla del generador aleatorio. FIJA a proposito: dos corridas del comando tienen que
        producir exactamente los mismos datos, si no es imposible comparar una corrida con otra
        cuando algo cambia.
    */
    'semilla_aleatoria' => env('SEMILLA_ALEATORIA', 20260803),

    /* Owner al que se le siembra. Por default el mismo de siempre. */
    'user_id' => env('SEMILLA_USER_ID', env('USER_ID')),
];
