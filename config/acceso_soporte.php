<?php

/*
    El acceso de soporte de un solo uso (misión acceso-soporte-firmado, 30/10/2026): el admin le pide a este
    sistema un token con la clave compartida y con él se abre una sesión del dueño sin escribir contraseña.
    Ver App\Http\Controllers\Helpers\AccesoSoporteHelper.

    🔴 Las variables de entorno se leen SOLO acá. El resto del código pregunta por config('acceso_soporte.*'):
    con `php artisan config:cache` (producción) un env() suelto fuera de los archivos de config devuelve null.
*/

return [

    /*
        Interruptor de apagado de este cliente. Con false:
          - la emisión (POST api/admin-sync/acceso-soporte) responde 403 `acceso_soporte_desactivado`;
          - el canje (POST api/acceso-soporte/canjear) responde el 401 genérico de siempre, el mismo que un
            token inventado, así que desde afuera no se distingue «apagado» de «token malo».
        Default true: la ruta de emisión ya exige la clave compartida del admin, no es una puerta abierta.

        Variable sin definir o vacía = true (el default). Variable definida con un valor que no se entiende
        («falso», «banana») = false: es un interruptor de APAGADO, y ante la duda se queda apagado en vez de
        prenderse por un error de tipeo.
    */
    'habilitado' => in_array(env('ACCESO_SOPORTE_HABILITADO', true), [null, ''], true)
        ? true
        : filter_var(env('ACCESO_SOPORTE_HABILITADO', true), FILTER_VALIDATE_BOOLEAN),

    /*
        Segundos que dura un token desde que se emite hasta que se puede canjear. Default 120. Se acota a
        30–600 en AccesoSoporteHelper::ttl_segundos() (un valor absurdo en el .env no deja ni un token que
        vence antes de llegar al navegador ni uno que vive horas).
    */
    'ttl_segundos' => (int) env('ACCESO_SOPORTE_TTL_SEGUNDOS', 120),

];
