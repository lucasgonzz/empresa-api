<?php

namespace App\Http\Controllers\Helpers;

use App\Jobs\ProcessArticleBatchImagesJob;
use App\Models\GeocoderCounter;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Encolar la búsqueda automática de imágenes para artículos (misión
 * asistente-masivas-imagenes-y-remito, 19/9/2026). Es la lógica que vivía en
 * GoogleController::batch_assign_images(), sacada del controller para que la compartan la pantalla
 * (el botón del listado) y el asistente (proponer_imagenes_para_articulos): un solo lugar decide la
 * clave de Google, el cx, la cuota diaria y el uuid de la corrida.
 *
 * 🔴 EL REGISTRO VISIBLE NACE ACÁ, `pendiente`, AL ENCOLAR. Antes lo abría el job al arrancar, y en
 * el shared hosting el worker pasa una vez por minuto: hasta entonces la persona que acababa de
 * mandar la búsqueda no veía ningún proceso (mismo motivo por el que la masiva nace pendiente en
 * MasiveUpdateHelper::create_pending_update). El job lo retoma por
 * BackgroundProcessHelper::ultimo_activo($owner_id, 'imagenes_automaticas') y lo pasa a en_proceso
 * (constructor B de la misión); si no lo encuentra, lo abre como siempre.
 *
 * cuota_de() y credenciales() las usa también el constructor B para las imágenes de categorías
 * (contrato §6), que consumen la MISMA cuota diaria de Google que las de artículos.
 */
class ImagenesAutomaticasHelper
{
    /** El motor de búsqueda personalizado (cx) de ComercioCity: el mismo que usaba el controller. */
    const CX = 'c442e5f346f314951';

    /** Búsquedas por día cuando el dueño no tiene `users.google_cuota` cargada. */
    const CUOTA_POR_DEFECTO = 10;

    /** Tipo del registro visible (BackgroundProcess.tipo), el mismo que el job ya usaba. */
    const TIPO_DE_PROCESO = 'imagenes_automaticas';

    /**
     * La clave, el cx y la cuota con que se busca para este dueño.
     *
     * Se usa la key propia del owner si la tiene configurada; si no, la key de fallback global que
     * viene de config/services.php (.env del servidor). El repositorio es público: prohibido volver
     * a escribir el valor real como default. Si tampoco está configurada en el .env queda vacía y
     * el error lo termina devolviendo la propia llamada a la API de Google (por eso el cast a
     * string: el job la declara `string` y un null lo tiraría antes de encolar).
     *
     * @param  \App\Models\User  $owner
     * @return array  ['api_key' => string, 'cx' => string, 'cuota' => int]
     */
    public static function credenciales(User $owner)
    {
        $api_key = $owner->google_custom_search_api_key
            ? $owner->google_custom_search_api_key
            : config('services.google_search.api_key');

        $cuota = $owner->google_cuota ? (int) $owner->google_cuota : self::CUOTA_POR_DEFECTO;

        return [
            'api_key' => (string) $api_key,
            'cx'      => self::CX,
            'cuota'   => $cuota,
        ];
    }

    /**
     * Cuánto de la cuota diaria de Google queda para hoy. Lee el contador del día
     * (GeocoderCounter, el mismo que incrementa el job en cada búsqueda) sin crearlo: consultar no
     * es buscar.
     *
     * @param  \App\Models\User  $owner
     * @return array  ['cuota' => int, 'usadas_hoy' => int, 'disponibles' => int]
     */
    public static function cuota_de(User $owner)
    {
        $cuota = self::credenciales($owner)['cuota'];

        $contador = GeocoderCounter::where('user_id', $owner->id)
                                    ->whereDate('created_at', Carbon::today())
                                    ->first();

        $usadas_hoy = is_null($contador) ? 0 : (int) $contador->counter;

        return [
            'cuota'       => $cuota,
            'usadas_hoy'  => $usadas_hoy,
            'disponibles' => max(0, $cuota - $usadas_hoy),
        ];
    }

    /**
     * Encola la búsqueda de imágenes para esos artículos y abre el registro visible en `pendiente`.
     *
     * El uuid de la corrida se genera ACÁ y no adentro del job, para poder devolvérselo a quien
     * disparó el lote: el canal `article_batch_images.{owner_id}` es PÚBLICO y sin un uuid conocido
     * de antemano la pestaña no sabe cuál de los eventos que llegan es el suyo (medido el 28/8/2026
     * entre dos instancias de demo).
     *
     * @param  \App\Models\User  $owner
     * @param  array  $article_ids
     * @param  int|null  $auth_user_id  Quién lo pidió (para el registro visible).
     * @return array  ['batch_uuid' => string, 'proceso' => \App\Models\BackgroundProcess|null]
     */
    public static function encolar(User $owner, array $article_ids, $auth_user_id = null)
    {
        $credenciales = self::credenciales($owner);

        $ids = [];

        foreach ($article_ids as $article_id) {

            $ids[] = (int) $article_id;
        }

        $batch_uuid = (string) Str::uuid();

        $proceso = BackgroundProcessHelper::iniciar($owner->id, self::TIPO_DE_PROCESO, 'Imágenes automáticas', [
            'auth_user_id' => $auth_user_id,
            'total'        => count($ids),
            'unidad'       => 'artículos',
            'detalle'      => count($ids) . ' artículos',
            'status'       => 'pendiente',
            'etapa'        => 'En espera del procesador',
        ]);

        /*
         * 🔴 `afterCommit()` EXPLÍCITO, mismo criterio que RunProviderOrderScanJob en
         * ProviderOrderScanAltaHelper. Desde la pantalla esto corre sin transacción y despacha de
         * inmediato, igual que antes. Desde el asistente la cadena es EjecutorAccionesIaHelper →
         * DB::transaction → PropuestaImagenesArticulosIaHelper::ejecutar → acá: sin esto, un worker
         * libre (redis, en el VPS) puede tomar el job ANTES del commit, no encontrar el registro
         * `pendiente` que se acaba de abrir y abrir otro — el pendiente quedaría colgado tres horas
         * hasta que el listado lo dé por muerto. El default de config no alcanza: `after_commit`
         * es true solo en la conexión `database`, en `redis` está en false.
         */
        ProcessArticleBatchImagesJob::dispatch(
            $ids,
            (int) $owner->id,
            $credenciales['api_key'],
            $credenciales['cx'],
            $credenciales['cuota'],
            $batch_uuid,
            is_null($proceso) ? null : (int) $proceso->id
        )->afterCommit();

        return [
            'batch_uuid' => $batch_uuid,
            'proceso'    => $proceso,
        ];
    }
}
