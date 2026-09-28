<?php

namespace App\Http\Controllers\Helpers;

use App\Models\BackgroundProcess;
use App\Models\GeocoderCounter;
use App\Models\ImageAssignmentRun;
use App\Models\User;
use Carbon\Carbon;

/**
 * Encolar la búsqueda automática de imágenes para artículos (misión
 * asistente-masivas-imagenes-y-remito, 19/9/2026). Es la lógica que vivía en
 * GoogleController::batch_assign_images(), sacada del controller para que la compartan la pantalla
 * (el botón del listado) y el asistente (proponer_imagenes_para_articulos): un solo lugar decide la
 * clave de Google, el cx, la cuota diaria y el uuid de la corrida.
 *
 * Desde la misión imagenes-catalogo-completo (27/9/2026) encolar() crea una ASIGNACIÓN
 * (image_assignment_runs + un item por artículo, ImageAssignmentRunHelper::crear()) y despacha
 * ProcessImageAssignmentRunJob, que busca la MEJOR imagen (tamaño, fondo blanco, IA comparando
 * candidatas) y deja el diagnóstico en Alertas → Imágenes. Por fuera nada cambia: misma firma, el
 * mismo retorno y el uuid de la corrida (ahora el de la asignación) para el aviso de Pusher.
 * ProcessArticleBatchImagesJob ya no se despacha, pero se queda: un job encolado antes del deploy
 * se tiene que poder deserializar y correr.
 *
 * 🔴 EL REGISTRO VISIBLE NACE AL ENCOLAR, `pendiente` (ahora con referencia a la asignación). En el
 * shared hosting el worker pasa una vez por minuto: hasta entonces la persona que acababa de mandar
 * la búsqueda no vería ningún proceso (mismo motivo por el que la masiva nace pendiente en
 * MasiveUpdateHelper::create_pending_update).
 *
 * cuota_de() y credenciales() las usan también las imágenes de categorías, que consumen la MISMA
 * cuota diaria de Google que las de artículos, y las asignaciones por selección (el tope diario).
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
     * Encola la búsqueda de imágenes para esos artículos: crea la asignación (en el orden recibido),
     * abre el registro visible en `pendiente` y despacha el job. Ver ImageAssignmentRunHelper::crear().
     *
     * El uuid de la corrida (el de la asignación) se genera ACÁ y no adentro del job, para poder
     * devolvérselo a quien disparó el lote: el canal `article_batch_images.{owner_id}` es PÚBLICO y
     * sin un uuid conocido de antemano la pestaña no sabe cuál de los eventos que llegan es el suyo
     * (medido el 28/8/2026 entre dos instancias de demo).
     *
     * Las asignaciones por selección y del asistente aplican el tope diario del dueño (su cupo de
     * búsquedas de Google de siempre), aunque busquen con Serper: el dueño sigue con su tope.
     *
     * 🔴 El despacho va con afterCommit() explícito (adentro de ImageAssignmentRunHelper::crear()):
     * desde el asistente la cadena es EjecutorAccionesIaHelper → DB::transaction →
     * PropuestaImagenesArticulosIaHelper::ejecutar → acá, y en redis `after_commit` está en false.
     *
     * @param  \App\Models\User  $owner
     * @param  array  $article_ids
     * @param  int|null  $auth_user_id  Quién lo pidió (para el registro visible).
     * @param  string  $origen  'seleccion' (el botón del listado, por defecto) o 'asistente'. Va último
     *                          y con default para no romper a ningún llamador.
     * @return array  ['batch_uuid' => string, 'proceso' => \App\Models\BackgroundProcess|null]
     */
    public static function encolar(User $owner, array $article_ids, $auth_user_id = null, $origen = ImageAssignmentRun::ORIGEN_SELECCION)
    {
        $origen = $origen === ImageAssignmentRun::ORIGEN_ASISTENTE
            ? ImageAssignmentRun::ORIGEN_ASISTENTE
            : ImageAssignmentRun::ORIGEN_SELECCION;

        $run = ImageAssignmentRunHelper::crear($owner, $article_ids, $origen, $auth_user_id, [
            'aplica_tope_diario' => true,
        ]);

        $proceso = is_null($run->background_process_id) ? null : BackgroundProcess::find($run->background_process_id);

        return [
            'batch_uuid' => (string) $run->uuid,
            'proceso'    => $proceso,
        ];
    }
}
