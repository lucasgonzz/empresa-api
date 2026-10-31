<?php

namespace App\Services\ProductFacts;

use App\Models\SerperUsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tope duro de búsquedas de Serper por dueño en las DEMOS (misión tope-serper-demos, 23/10/2026).
 *
 * Por qué existe: la cuenta de Serper se quedó sin créditos el 22/10/2026 y la clave es la misma para
 * demo, demo2, demo3 y Doblep. Lucas pidió «que cada usuario tenga solo 25 búsquedas con Serper en las
 * demos». Fuera de una demo este archivo no hace nada: ni candado, ni consultas, ni tope.
 *
 * Qué es una demo: `config('app.FOR_USER') === 'demo'`, estricto. 🔴 NO es
 * DemoSetupHelper::es_instancia_de_demo(): esa da true también en `local` y `testing`, y ni la
 * máquina de Lucas ni los slots tienen que quedar con tope.
 *
 * Qué es «un usuario»: el DUEÑO (`serper_usage_events.user_id`). Cada demo se rearma por lead
 * (DemoSetupHelper::run, base nueva) y el lead entra como el dueño de la instancia, así que un lead =
 * el dueño durante su turno. Contar por dueño cubre además las corridas de fondo (Imágenes
 * inteligentes corre en un job sin persona: `auth_user_id` null) y los empleados que el lead cree
 * adentro de su demo, que comparten su cupo. Defensa extra: solo cuentan los eventos posteriores al
 * alta del dueño (`users.created_at`; el dueño se recrea en cada setup), por si la tabla sobreviviera a
 * un rearmado.
 *
 * Qué cuenta: toda llamada REAL registrada por SerperUsageService::record(), haya salido bien o no
 * (Serper la cobra o no, pero salió a la red), excepto /resolve: resuelve una búsqueda ya contada, es gratuito
 * y tiene su propio proceso. Un cache hit de la ficha compartida no llama y no cuenta.
 *
 * Por qué un candado: chequeo, llamada y registro van juntos bajo `GET_LOCK` por dueño (el patrón de
 * EliminarSucursalHelper y de la creación de ventas). Sin él, la ficha por barras y una corrida de
 * imágenes podían leer las dos «quedan 1» y pasar juntas de 24 a 26. `GET_LOCK` se libera solo si el
 * proceso muere (se cierra la conexión), y además se libera en el `finally`.
 *
 * ⚠️ El conteo lee lo committeado: quien llama no tiene que estar adentro de una transacción que ya
 * leyó `serper_usage_events` (en REPEATABLE READ vería la foto vieja). Hoy ninguno de los dos caminos
 * (el proveedor de imágenes y la ficha) llama adentro de una transacción.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class TopeSerperDemo
{
    /** Búsquedas de Serper por dueño en una demo. */
    const TOPE = 25;

    /** Lo que ve el lead al llegar al tope (la ficha, los 422, el aviso de la SPA). */
    const MENSAJE = 'Llegaste al límite de búsquedas de la demo ('.self::TOPE.'). Podés seguir cargando a mano.';

    /** El detalle de un artículo de una asignación de imágenes que no llegó a buscarse por el tope. */
    const DETALLE_SIN_BUSCAR = 'No se llegó a buscar: se llegó al límite de búsquedas de la demo ('.self::TOPE.'). Podés cargarle la foto a mano.';

    /** El diagnóstico de un criterio (código o nombre) que no se buscó por el tope. */
    const CRITERIO_NO_BUSCADO = 'No se buscó: se llegó al límite de búsquedas de la demo ('.self::TOPE.').';

    /** Si otra búsqueda del mismo dueño tiene el candado más de esto, no se busca. */
    const SEGUNDOS_DE_ESPERA_DEL_CANDADO = 20;

    /** Cuando no se consiguió el candado: no se llamó a Serper. */
    const MENSAJE_OCUPADO = 'El buscador está ocupado con otra búsqueda de la demo. Probá de nuevo en un momento.';

    /**
     * ¿Esta instancia es una demo? Solo ahí hay tope.
     *
     * @return bool
     */
    public static function aplica()
    {
        return config('app.FOR_USER') === 'demo';
    }

    /**
     * Búsquedas de Serper que ya usó el dueño en esta demo (las posteriores a su alta).
     *
     * @param  \App\Models\User|int|null $dueno  El dueño, un empleado suyo o su id. Null = el dueño
     *                                           de la instancia (config app.USER_ID).
     * @return int
     */
    public static function usadas($dueno = null)
    {
        $dueno_id = self::dueno_id($dueno);

        if ($dueno_id <= 0) {
            return 0;
        }

        $query = SerperUsageEvent::where('user_id', $dueno_id)
            ->where('process', '<>', SerperLinkResolver::PROCESO);

        // El dueño se recrea en cada setup de la demo: lo de antes de su alta es de otro lead.
        $alta = DB::table('users')->where('id', $dueno_id)->value('created_at');

        if (!is_null($alta)) {
            $query->where('occurred_at', '>=', $alta);
        }

        return (int) $query->count();
    }

    /**
     * Búsquedas que le quedan al dueño en la demo. Fuera de una demo, null (no hay tope).
     *
     * @param  \App\Models\User|int|null $dueno
     * @return int|null
     */
    public static function restantes($dueno = null)
    {
        if (!self::aplica()) {
            return null;
        }

        return max(0, self::TOPE - self::usadas($dueno));
    }

    /**
     * ¿El dueño ya usó todas sus búsquedas de la demo? Fuera de una demo, siempre false (y sin
     * consultar la base).
     *
     * @param  \App\Models\User|int|null $dueno
     * @return bool
     */
    public static function agotado($dueno = null)
    {
        return self::aplica() && self::restantes($dueno) <= 0;
    }

    /**
     * El objeto del contrato con la SPA (GET image-assignment-runs/tope-demo, el campo `tope_demo` de
     * la previa del catálogo y de ImagenesAutomaticasHelper::cuota_de()).
     *
     * @param  \App\Models\User|int|null $dueno
     * @return array  {aplica: bool, tope: int|null, usadas: int|null, restantes: int|null}
     */
    public static function estado($dueno = null)
    {
        if (!self::aplica()) {
            return ['aplica' => false, 'tope' => null, 'usadas' => null, 'restantes' => null];
        }

        $usadas = self::usadas($dueno);

        return [
            'aplica'    => true,
            'tope'      => self::TOPE,
            'usadas'    => $usadas,
            'restantes' => max(0, self::TOPE - $usadas),
        ];
    }

    /**
     * Corre una llamada a Serper respetando el tope de la demo.
     *
     * Fuera de una demo llama directo. En una demo: toma el candado del dueño, mira cuántas quedan y,
     * si quedan, corre la llamada (que adentro registra el evento con SerperUsageService::record(): el
     * registro TIENE que quedar adentro del candado, o el siguiente no lo vería). Si no quedan, tira
     * TopeSerperDemoAgotado sin llamar. Si no consigue el candado en SEGUNDOS_DE_ESPERA_DEL_CANDADO,
     * tira ProductFactsException(MENSAJE_OCUPADO), también sin llamar.
     *
     * @param  \App\Models\User|int|null $dueno
     * @param  callable                  $llamada  La llamada a Serper con su registro.
     * @return mixed  Lo que devuelva la llamada.
     *
     * @throws \App\Services\ProductFacts\TopeSerperDemoAgotado
     * @throws \App\Services\ProductFacts\ProductFactsException
     */
    public static function ejecutar($dueno, callable $llamada)
    {
        if (!self::aplica()) {
            return $llamada();
        }

        $dueno_id = self::dueno_id($dueno);
        $candado  = 'tope_serper_demo_'.$dueno_id;

        $fila = DB::selectOne('SELECT GET_LOCK(?, ?) AS tomado', [$candado, self::SEGUNDOS_DE_ESPERA_DEL_CANDADO]);

        if (is_null($fila) || (int) $fila->tomado !== 1) {
            Log::warning('[TopeSerperDemo] No se consiguió el candado '.$candado.': no se llamó a Serper.');

            throw new ProductFactsException(self::MENSAJE_OCUPADO);
        }

        try {
            if (self::restantes($dueno_id) <= 0) {
                throw new TopeSerperDemoAgotado(self::MENSAJE);
            }

            return $llamada();
        } finally {
            try {
                DB::select('SELECT RELEASE_LOCK(?) AS liberado', [$candado]);
            } catch (\Throwable $e) {
                // Si la conexión se cayó, MySQL ya lo liberó al cerrarla.
                Log::warning('[TopeSerperDemo] No se pudo liberar el candado '.$candado.'.');
            }
        }
    }

    /**
     * El id del dueño: el de la fila si es dueño, el de su dueño si es un empleado, el entero que
     * llegó, o el dueño de la instancia (config app.USER_ID) si no llegó nada.
     *
     * @param  \App\Models\User|int|null $dueno
     * @return int
     */
    protected static function dueno_id($dueno)
    {
        if ($dueno instanceof User) {
            return (int) ($dueno->owner_id ?: $dueno->id);
        }

        $id = (int) $dueno;

        return $id > 0 ? $id : (int) config('app.USER_ID');
    }
}
