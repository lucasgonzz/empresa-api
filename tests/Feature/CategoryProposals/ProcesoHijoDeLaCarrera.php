<?php

/**
 * Proceso HIJO de las pruebas de carrera de la categorización con IA (misión categorizacion-tres-modelos,
 * 5/10/2026). No es un test (no termina en `Test.php`, PHPUnit no lo carga): es un script de línea de
 * comandos que un test lanza con `proc_open`, dos a la vez, para reproducir lo que un solo proceso no puede:
 * dos personas (o la skill y el dueño) tocando la misma corrida al mismo tiempo.
 *
 * Uso:  php ProcesoHijoDeLaCarrera.php <accion> '<json con los argumentos>'
 *
 * Acciones:
 *  - `elegir`:       {"user":N, "run":N, "propuesta":N, "demora":segundos}
 *                    Elige el sistema. Con `demora`, después de tomar el candado de la corrida se queda
 *                    esperando esos segundos (con el candado puesto) para darle tiempo al otro proceso a entrar.
 *  - `crear`:        {"user":N, "demora":segundos}
 *                    Es `crear` de la ingesta con `reemplazar: true` (lo que hace la skill cuando vuelve a
 *                    correr). Con `demora`, después de tomar el candado de la fila de `users` se queda esperando.
 *  - `volver_atras`: {"user":N, "run":N, "demora":segundos}
 *
 * Hereda el entorno del test: APP_ENV=testing y la MISMA base de datos (`DB_DATABASE`). Imprime una sola
 * línea `RESULTADO:{json}` con el `status` del helper, su código de error, si fue `ya_estaba` y, si el
 * helper tiró una excepción (por ejemplo el deadlock 1213), la clase y el mensaje.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */

// tests/Feature/CategoryProposals -> empresa-api son tres niveles.
require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Helpers\category_proposal\CategoryProposalAplicarHelper;
use App\Http\Controllers\Helpers\category_proposal\CategoryProposalIngestaHelper;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Qué hacer y con qué datos.
$accion = isset($argv[1]) ? $argv[1] : '';
$args   = isset($argv[2]) ? json_decode($argv[2], true) : [];

// Cuántos segundos esperar con el candado puesto (0 = no esperar).
$demora = isset($args['demora']) ? (float) $args['demora'] : 0;

// Para esperar UNA sola vez, con el primer candado que se toma.
$ya_espero = false;

if ($demora > 0) {
    DB::listen(function ($consulta) use ($accion, $demora, &$ya_espero) {
        if ($ya_espero || substr(trim($consulta->sql), -10) !== 'for update') {
            return;
        }

        // `elegir` y `volver_atras` esperan con el candado de la CORRIDA tomado (el del dueño ya está puesto);
        // `crear` espera con el candado de la fila de `users` del dueño.
        $tabla = ($accion === 'crear') ? 'from `users`' : 'from `category_proposal_runs`';

        if (strpos($consulta->sql, $tabla) !== false) {
            $ya_espero = true;
            usleep((int) ($demora * 1000000));
        }
    });
}

// Lo que se informa al test.
$salida = ['status' => null, 'error' => null, 'ya_estaba' => false, 'excepcion' => null];

try {
    if ($accion === 'elegir') {
        $r = CategoryProposalAplicarHelper::elegir((int) $args['user'], (int) $args['run'], (int) $args['propuesta'], false, (int) $args['user'], false);
    } elseif ($accion === 'volver_atras') {
        $r = CategoryProposalAplicarHelper::volver_atras((int) $args['user'], (int) $args['run']);
    } elseif ($accion === 'crear') {
        // Una propuesta nueva mínima: lo que importa es el `reemplazar` y el candado, no el contenido.
        $cuerpo = [
            'reemplazar' => true,
            'propuestas' => [[
                'clave'  => 'A',
                'tipo'   => 'nueva',
                'nombre' => 'Sistema que manda la skill al volver a correr',
                'arbol'  => [['nombre' => 'Muebles', 'subcategorias' => ['Cocina']], ['nombre' => 'Puertas']],
            ]],
        ];

        $r = CategoryProposalIngestaHelper::crear(User::find((int) $args['user']), $cuerpo);
    } else {
        $r = ['status' => 'accion_desconocida'];
    }

    // `elegir` y `volver_atras` devuelven el error al lado del `status`; `crear` (la ingesta) lo devuelve
    // adentro de `body`.
    $salida['status']    = isset($r['status']) ? $r['status'] : null;
    $salida['error']     = isset($r['error']) ? $r['error'] : (isset($r['body']['error']) ? $r['body']['error'] : null);
    $salida['ya_estaba'] = !empty($r['ya_estaba']);
} catch (\Throwable $e) {
    $salida['excepcion'] = get_class($e).': '.substr($e->getMessage(), 0, 200);
}

echo 'RESULTADO:'.json_encode($salida)."\n";
