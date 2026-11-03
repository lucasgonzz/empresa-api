<?php

namespace App\Http\Controllers\Helpers;

use App\Jobs\RegistrarHitoDePruebaJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Los hitos de la prueba gratuita hacia el admin (C2, misión prueba-experiencia-empresa, 3/11/2026).
 *
 * Cada enganche del sistema (venta creada, artículo creado, ingreso, importación...) llama a `emitir()`, que
 * cuesta cero en un cliente real (la primera guarda no toca la base) y en una instancia de prueba encola
 * `RegistrarHitoDePruebaJob`: el job decide si es la primera vez, escribe la fila de `demo_eventos` y la
 * empuja al admin por el canal de siempre. NUNCA se hace nada de eso en el request: un hito es una métrica,
 * no puede sumarle tiempo ni fallas a la operación que lo produjo.
 *
 * `DemoEventoEmitter` no sirve acá a propósito: su guarda 1 descarta toda sesión sin el marcador de ingreso
 * por token, y en la prueba el negocio entra con su contraseña.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class PruebaHitosHelper
{
    /**
     * Encola el registro de un hito. Devuelve si se encoló (false fuera de una instancia de prueba, con un
     * tipo desconocido o sin dueño resoluble).
     *
     * @param string $tipo Uno de PruebaHelper::TIPOS_DE_HITO.
     * @param array $datos Carga útil chica (ids, origen). Nada sensible: viaja al admin.
     * @param int|null $owner_id El dueño; null = el de la sesión actual (o el de la instancia).
     * @return bool
     */
    public static function emitir($tipo, array $datos = [], $owner_id = null)
    {
        if (!PruebaHelper::puede_haber_prueba()) {
            return false;
        }

        try {
            if (!in_array($tipo, PruebaHelper::TIPOS_DE_HITO, true)) {
                Log::info('PruebaHitosHelper: hito fuera del catálogo, se descarta: ' . $tipo);

                return false;
            }

            $owner_id = is_null($owner_id) ? self::dueno_actual() : (int) $owner_id;

            if (empty($owner_id)) {
                return false;
            }

            dispatch(new RegistrarHitoDePruebaJob($owner_id, $tipo, $datos, Carbon::now()));

            return true;
        } catch (\Throwable $e) {
            // Un hito no puede romper la operación que lo produjo.
            Log::warning('PruebaHitosHelper: no se pudo encolar el hito "' . $tipo . '": ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Registra el hito en este mismo proceso (el barrido y los jobs, que ya están fuera del request).
     *
     * @param string $tipo
     * @param array $datos
     * @param int $owner_id
     * @return void
     */
    public static function emitir_ahora($tipo, array $datos, $owner_id)
    {
        if (!PruebaHelper::puede_haber_prueba() || !in_array($tipo, PruebaHelper::TIPOS_DE_HITO, true)) {
            return;
        }

        try {
            (new RegistrarHitoDePruebaJob((int) $owner_id, $tipo, $datos, Carbon::now()))->handle();
        } catch (\Throwable $e) {
            Log::warning('PruebaHitosHelper: no se pudo registrar el hito "' . $tipo . '": ' . $e->getMessage());
        }
    }

    /**
     * El dueño de la sesión actual (el usuario o su dueño), o el de la instancia si no hay sesión.
     *
     * @return int|null
     */
    private static function dueno_actual()
    {
        $user = Auth::user();

        if (!is_null($user)) {
            return PruebaHelper::owner_id_de($user);
        }

        $dueno = PruebaHelper::dueno();

        return is_null($dueno) ? null : (int) $dueno->id;
    }
}
