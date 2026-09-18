<?php

/*
    El mostrador del módulo IA (misión modulo-ia-mostrador): los hechos que el API calcula
    para la skill /mostrador (admin-sync/mostrador/hechos).
*/

return [

    /*
        Umbral de artículos a partir del cual el cálculo de los hechos de `compras` y `stock`
        NO corre adentro del request: se despacha CalcularHechosMostradorJob a la cola, la
        fila queda en estado 'calculando' y POST hechos responde 202 para que la skill haga
        polling con GET admin-sync/mostrador/reportes/{id}.

        Los dos tipos recorren el catálogo con los motores de sugerencias (el cron viejo,
        sugerencias:generar y compras:generar, los corría en jobs por eso mismo): en un
        comercio de decenas de miles de artículos son minutos, y el proxy corta el request
        mucho antes. Por debajo del umbral el cálculo es sincrónico y la respuesta es 200 con
        los hechos, como para `dia` y `tienda` (que nunca recorren catálogo).

        Los tests lo bajan a 0 para forzar el camino asincrónico.
    */
    'umbral_async' => (int) env('MOSTRADOR_UMBRAL_ASYNC', 2000),

    /*
        Segundos que puede tardar CalcularHechosMostradorJob antes de que el worker lo
        mate ($timeout del job). Es también lo que POST hechos espera para dar por
        muerta una fila que quedó en 'calculando' sin que ningún job la cierre (worker
        caído a mitad) y volver a despacharla.
    */
    'timeout_job' => (int) env('MOSTRADOR_TIMEOUT_JOB', 1800),

];
