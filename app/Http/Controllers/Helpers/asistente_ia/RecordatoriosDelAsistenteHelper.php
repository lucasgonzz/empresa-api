<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\agenda\AgendaHelper;
use App\Http\Controllers\Helpers\agenda\AgendaTareaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Pending;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Los recordatorios de la Agenda que el asistente le manda al dueño POR WHATSAPP a la hora de la
 * tarea (misión recordatorios-whatsapp, 16/10/2026).
 *
 * Pedido de Lucas: que «recordame el viernes a las 10 que…» le llegue al dueño por WhatsApp a esa
 * hora. Hasta hoy la hora quedaba escrita adelante del detalle («10:00 · Llamar a…») y el asistente
 * le avisaba al modelo que NO había aviso (PropuestaTareaIaHelper::AVISO_SIN_RECORDATORIO).
 *
 * 🔴 EL RECORDATORIO SALE POR CONSULTA DEL ADMIN, NO POR EMPUJE DE ESTE API. Mismo molde que los
 * avisos de «terminó» (AvisosDelAsistenteHelper) y los informes de la mañana: el admin pregunta cada
 * 5 minutos a los clientes con el asistente por WhatsApp prendido
 * (GET admin-sync/asistente/recordatorios-pendientes), manda el WhatsApp por el número del asistente
 * y recién DESPUÉS sella el recordatorio (POST admin-sync/asistente/recordatorios/avisado). Si el
 * envío falla, el recordatorio sigue pendiente y sale en la vuelta siguiente, mientras siga adentro
 * de HORAS_DE_GRACIA.
 *
 * 🔴 Y SOLO SE PROMETE SI EL ADMIN ANUNCIÓ QUE CONSULTA. Un admin viejo no pega en estas rutas, así
 * que una tarea que nace con `avisar_por_whatsapp = 1` contra ese admin es un recordatorio que no
 * llega nunca. Por eso cada consulta deja un anuncio por dueño (anunciar()) que dura HORAS_DEL_ANUNCIO
 * y dice si el admin tiene la plantilla de Meta para mandarlo con la ventana de 24 h cerrada: el texto
 * que lee el modelo al confirmar la tarea (PropuestaTareaIaHelper::aviso_del_recordatorio()) promete el
 * aviso solo con ese anuncio vigente, y de qué forma según la plantilla.
 *
 * Contrato (admin consumidor → empresa proveedor), con `X-Admin-Api-Key`:
 *   GET  admin-sync/asistente/recordatorios-pendientes?plantilla=1|0
 *        → 200 {recordatorios:[{id, tarea_id, fecha, hora, detalle, texto, ai_conversation_id, ruta}]}
 *        (`id` = "recordatorio:{pending_id}-{created_at unix}:{Y-m-d}:{HHMM}")
 *   POST admin-sync/asistente/recordatorios/avisado {id, whatsapp_message_id?} → 200 {ok:true} · 404
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class RecordatoriosDelAsistenteHelper
{
    /** El prefijo del `id` del contrato. */
    const PREFIJO_ID = 'recordatorio';

    /**
     * Horas después de la hora de la tarea en las que el recordatorio todavía se manda.
     *
     * El admin consulta cada 5 minutos, así que en el caso normal sale a la hora. La gracia cubre lo
     * que no es normal: el admin caído un rato, la ventana de 24 h cerrada sin plantilla (sale si el
     * dueño escribe en ese rato), un cliente pausado por un 5xx. Más de tres horas tarde, «te recuerdo
     * que a las 10 tenías que llamar a Juan» ya no es un recordatorio: la tarea sigue en la Agenda.
     */
    const HORAS_DE_GRACIA = 3;

    /** Cuántos recordatorios viajan por consulta como mucho. */
    const TOPE_RECORDATORIOS = 20;

    /**
     * Cuánto dura el anuncio del admin. Consulta cada 5 minutos: dos horas sin consultas es un admin
     * que se volvió a una versión vieja (o está caído), y desde ahí no se promete ningún aviso.
     */
    const HORAS_DEL_ANUNCIO = 2;

    /** Clave del caché del anuncio, por dueño: `['plantilla' => bool]`. */
    const CLAVE_ANUNCIO = 'asistente_ia:admin_recordatorios:';

    /** La Agenda en la SPA (`/agenda/:view?`, ruta `pending`). */
    const RUTA_DE_LA_AGENDA = '/agenda';

    /** Largo máximo de las notas en el texto del recordatorio. */
    const LARGO_NOTAS = 300;

    /**
     * Guarda lo que el admin anunció en la consulta: que consulta los recordatorios y si tiene la
     * plantilla de Meta para mandarlos con la ventana de 24 h cerrada.
     *
     * A diferencia de AvisosDelAsistenteHelper::anunciar(), acá el anuncio no se borra nunca a mano:
     * lo deja la consulta misma (que solo hace un admin nuevo) y se vence solo a las HORAS_DEL_ANUNCIO.
     *
     * @param  int  $owner_id
     * @param  mixed  $plantilla  El `plantilla` de la query ("1" = tiene la plantilla), o null.
     * @return void
     */
    public static function anunciar($owner_id, $plantilla)
    {
        $con_plantilla = !is_null($plantilla) && in_array(strtolower(trim((string) $plantilla)), ['1', 'true', 'si', 'sí'], true);

        Cache::put(
            self::CLAVE_ANUNCIO . (int) $owner_id,
            ['plantilla' => $con_plantilla],
            Carbon::now()->addHours(self::HORAS_DEL_ANUNCIO)
        );
    }

    /**
     * true si el admin de este dueño consultó los recordatorios en las últimas HORAS_DEL_ANUNCIO.
     *
     * @param  int  $owner_id
     * @return bool
     */
    public static function admin_avisa($owner_id): bool
    {
        return is_array(Cache::get(self::CLAVE_ANUNCIO . (int) $owner_id));
    }

    /**
     * true si además el admin tiene la plantilla: el recordatorio sale aunque el dueño no haya
     * hablado con el asistente en las últimas 24 h.
     *
     * @param  int  $owner_id
     * @return bool
     */
    public static function admin_avisa_con_plantilla($owner_id): bool
    {
        $anuncio = Cache::get(self::CLAVE_ANUNCIO . (int) $owner_id);

        return is_array($anuncio) && !empty($anuncio['plantilla']);
    }

    /**
     * Los recordatorios que vencieron y todavía no salieron, por hora y con tope.
     *
     * Una tarea entra si es del dueño, pide el aviso (`avisar_por_whatsapp = 1`), tiene hora y ese día
     * le toca: la puntual, si su fecha es ese día y no está hecha; la recurrente, si ese día es una de
     * sus ocurrencias (AgendaHelper::es_ocurrencia, que respeta el fin de la recurrencia) y esa
     * ocurrencia no tiene su PendingCompleted. Además el momento —ese día a la hora de la tarea— tiene
     * que estar en [ahora − HORAS_DE_GRACIA, ahora] y ser posterior a la creación de la tarea, y la
     * tarea no tiene que haberse avisado ese día.
     *
     * 🔴 LA GRACIA CRUZA LA MEDIANOCHE. Entre las 00:00 y las 02:59 el margen arranca ayer, así que
     * también entra la ocurrencia de AYER (un recordatorio de las 23:58 que el admin vio recién a las
     * 00:00), con su fecha en el `id` y en el texto («ayer 23:58»). Sin esto, todo recordatorio de las
     * últimas horas del día que no saliera antes de la medianoche se perdía.
     *
     * 🔴 «POSTERIOR A LA CREACIÓN» NO ES UN DETALLE. Una tarea creada a las 15 «para hoy a las 10» ya
     * venció cuando nace: sin esta guarda, el dueño recibía un «te recuerdo» de algo que acababa de
     * pedir, que es exactamente lo contrario de un recordatorio.
     *
     * 🔴 LA MARCA ES POR DÍA (`avisado_at` anterior al día de la ocurrencia), NO «AVISADO SÍ/NO». Con
     * una sola columna sirve para la recurrente (cada ocurrencia se avisa su día) y para una puntual
     * que se movió de día después de avisada. Un cambio de HORA el mismo día lo resuelve la tarea al
     * guardarse (AgendaTareaHelper::recalcular_marca_del_recordatorio()): «mejor a las 11» después del
     * aviso de las 10 vuelve a salir a las 11, y con otro `id` (lleva la hora), así que el reclamo que
     * el admin guardó para las 10 no lo tapa.
     *
     * Todo en la zona de la app (America/Argentina/Buenos_Aires), que es la que define qué es "hoy"
     * para el comercio, igual que la Agenda.
     *
     * @param  \App\Models\User  $dueno
     * @return array<int, array<string, mixed>>
     */
    public static function pendientes(User $dueno): array
    {
        $ahora = Carbon::now();
        $hoy = $ahora->copy()->startOfDay();
        $desde = $ahora->copy()->subHours(self::HORAS_DE_GRACIA);

        /* Los días cuyas ocurrencias pueden caer en el margen: hoy, y ayer si el margen cruza la medianoche. */
        $dias = [$hoy];

        if ($desde->lt($hoy)) {

            array_unshift($dias, $hoy->copy()->subDay());
        }

        $primero = $dias[0];

        $query = Pending::where('user_id', (int) $dueno->id)
                        ->where('avisar_por_whatsapp', 1)
                        ->whereNotNull('hora_aviso')
                        /* Una tarea cuya primera fecha es posterior a hoy no puede tocar en el margen. */
                        ->where('fecha_realizacion', '<=', $hoy->format('Y-m-d 23:59:59'))
                        /* Avisada hoy no entra en ningún día del margen; el resto se mira por día abajo. */
                        ->where(function ($q) use ($hoy) {
                            $q->whereNull('avisado_at')
                                ->orWhere('avisado_at', '<', $hoy->format('Y-m-d H:i:s'));
                        })
                        ->where(function ($q) use ($primero) {
                            $q->whereNull('fecha_fin_recurrencia')
                                ->orWhere('fecha_fin_recurrencia', '>=', $primero->format('Y-m-d'));
                        });

        /* Sin cruzar la medianoche, la hora se acota en la consulta; cruzándola, va en memoria. */
        if (count($dias) === 1) {

            $query->where('hora_aviso', '<=', $ahora->format('H:i:s'))
                    ->where('hora_aviso', '>=', $desde->format('H:i:s'));
        }

        $tareas = $query->with('unidad_frecuencia')->orderBy('id')->get();

        // Una sola query para las completadas del margen, indexadas en memoria (como la Agenda).
        $completadas = AgendaHelper::indice_de_completadas((int) $dueno->id, $primero, $hoy);

        $vencidos = [];

        foreach ($tareas as $tarea) {

            foreach ($dias as $dia) {

                $momento = self::momento($tarea, $dia);

                if ($momento->lt($desde) || $momento->gt($ahora)) {

                    continue;
                }

                if (!is_null($tarea->created_at) && !$momento->gt($tarea->created_at)) {

                    continue;
                }

                if (!is_null($tarea->avisado_at) && Carbon::parse($tarea->avisado_at)->gte($dia)) {

                    continue;
                }

                if (!self::toca_el($tarea, $dia, $completadas)) {

                    continue;
                }

                $vencidos[] = ['momento' => $momento, 'tarea' => $tarea, 'dia' => $dia];
            }
        }

        usort($vencidos, function ($a, $b) {

            if ($a['momento']->eq($b['momento'])) {

                return (int) $a['tarea']->id - (int) $b['tarea']->id;
            }

            return $a['momento']->lt($b['momento']) ? -1 : 1;
        });

        $vencidos = array_slice($vencidos, 0, self::TOPE_RECORDATORIOS);

        if (!count($vencidos)) {

            return [];
        }

        $conversacion = AsistenteCanalHelper::conversacion_vigente($dueno);

        $recordatorios = [];

        foreach ($vencidos as $vencido) {

            $tarea = $vencido['tarea'];
            $dia = $vencido['dia'];

            $recordatorios[] = [
                /*
                 * 🔴 CON EL created_at ADENTRO, NO SOLO EL ID DE LA FILA. Las demos corren
                 * `migrate:fresh` en cada reseteo y el autoincremental vuelve a 1; el admin guarda el
                 * reclamo por `id`, así que la primera tarea de la demo siguiente se daba por «ya
                 * avisada». Misma razón que el uuid de los avisos de escaneo.
                 */
                'id'                 => self::id_del_recordatorio($tarea, $dia),
                'tarea_id'           => (int) $tarea->id,
                'fecha'              => $dia->format('Y-m-d'),
                'hora'               => self::hora($tarea),
                'detalle'            => AgendaTareaHelper::detalle_sin_hora($tarea->detalle),
                'texto'              => self::texto_del_recordatorio($tarea, $dia),
                'ai_conversation_id' => is_null($conversacion) ? null : (int) $conversacion->id,
                'ruta'               => self::RUTA_DE_LA_AGENDA,
            ];
        }

        return $recordatorios;
    }

    /**
     * Sella el recordatorio de ese día como mandado y lo deja como un mensaje del asistente en la
     * conversación de WhatsApp del dueño.
     *
     * 🔴 EL MENSAJE EN LA CONVERSACIÓN ES LO QUE HACE QUE «LISTO, YA LO HICE» TENGA CONTEXTO. Sin él,
     * el asistente recibe «listo» sin saber de qué tarea le hablan. Va a la conversación de WhatsApp
     * vigente (la misma que viajó en `ai_conversation_id`) y, si no hay ninguna, a una nueva: el
     * mensaje la deja como la más reciente, así que la respuesta del dueño —citando o no— cae ahí.
     *
     * Se sella UNA sola vez: la fila se bloquea y la marca se mira adentro de la transacción, así que
     * un reintento del admin no deja dos mensajes.
     *
     * 🔴 SIN TOCAR `updated_at`. La tarjeta de cambios del asistente guarda el `updated_at` de la tarea
     * para detectar ediciones en el medio (PropuestaTareaIaHelper::ejecutar_cambios): si el sello lo
     * moviera, «pasala a mañana» confirmado justo después del recordatorio daría «la tarea cambió».
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $id  El `id` del contrato.
     * @param  mixed  $whatsapp_message_id  El wamid del WhatsApp que salió, o null.
     * @return bool  false si el id no parsea o la tarea no es de este dueño (el 404 del contrato).
     */
    public static function marcar_avisado(User $dueno, $id, $whatsapp_message_id = null): bool
    {
        $partes = self::partes_del_id($id);

        if (is_null($partes)) {

            return false;
        }

        if ($partes['fecha']->gt(Carbon::today())) {

            return false;
        }

        return DB::transaction(function () use ($dueno, $partes, $whatsapp_message_id) {

            /* Siempre dentro del dueño: el número de tarea solo no alcanza para saber de quién es. */
            $tarea = Pending::where('user_id', (int) $dueno->id)
                            ->where('id', $partes['pending_id'])
                            ->where('avisar_por_whatsapp', 1)
                            ->lockForUpdate()
                            ->first();

            if (is_null($tarea) || self::unix_de_creacion($tarea) !== $partes['creada']) {

                return false;
            }

            $dia = $partes['fecha'];

            /*
             * El WhatsApp salió para una hora que la tarea ya no tiene (la cambiaron entre la consulta y
             * el sello): se contesta 200 —salió, el admin no tiene nada que reintentar— pero no se sella
             * ni se deja en la conversación. Sellarlo taparía el recordatorio de la hora nueva, que es
             * otro `id` y sale cuando le toque.
             */
            if (str_replace(':', '', self::hora($tarea)) !== $partes['hora']) {

                return true;
            }

            /* Idempotente: un segundo sello del mismo día no pisa la marca ni duplica el mensaje. */
            if (!is_null($tarea->avisado_at) && Carbon::parse($tarea->avisado_at)->gte($dia)) {

                return true;
            }

            /*
             * La marca es "cuándo salió", salvo que el sello llegue al día siguiente (un reintento de
             * la marca que cruzó la medianoche): ahí queda en el último segundo del día del
             * recordatorio. Con `now()` quedaría con fecha de hoy y taparía la ocurrencia de hoy de
             * una recurrente, que es otro recordatorio.
             */
            $marca = Carbon::now();
            $fin_del_dia = $dia->copy()->endOfDay();

            if ($marca->gt($fin_del_dia)) {

                $marca = $fin_del_dia;
            }

            $tarea->avisado_at = $marca;
            $tarea->timestamps = false;
            $tarea->save();

            self::dejar_en_la_conversacion($dueno, self::texto_del_recordatorio($tarea, $dia), $tarea, $whatsapp_message_id);

            return true;
        });
    }

    /**
     * El texto del recordatorio, en la voz del asistente, corto y sin markdown pesado: en WhatsApp,
     * `*negrita*` como mucho. Sin link: la Agenda viaja aparte en `ruta`.
     *
     * @param  \App\Models\Pending  $tarea
     * @param  \Carbon\Carbon  $fecha  El día del recordatorio.
     * @return string
     */
    public static function texto_del_recordatorio(Pending $tarea, Carbon $fecha): string
    {
        /* Un asterisco adentro del detalle cortaría la negrita a la mitad. */
        $detalle = trim(str_replace('*', '', AgendaTareaHelper::detalle_sin_hora($tarea->detalle)));

        $cuando = 'el ' . $fecha->format('d/m');

        if ($fecha->isSameDay(Carbon::today())) {

            $cuando = 'hoy';

        } elseif ($fecha->isSameDay(Carbon::yesterday())) {

            $cuando = 'ayer';
        }

        $texto = '⏰ Te recuerdo: *' . $detalle . '* (' . $cuando . ' ' . self::hora($tarea) . ').';

        $notas = trim((string) preg_replace('/\s+/u', ' ', (string) $tarea->notas));

        if ($notas !== '') {

            if (mb_strlen($notas) > self::LARGO_NOTAS) {

                $notas = rtrim(mb_substr($notas, 0, self::LARGO_NOTAS - 1)) . '…';
            }

            $texto .= "\nNotas: " . $notas;
        }

        return $texto . "\nCuando lo hagas, avisame y lo marco como hecho.";
    }

    /**
     * ¿Ese día es un día de la tarea y está sin hacer? Mismo criterio que la Agenda: la puntual está
     * hecha con su PendingCompleted o con el flag `completado`; la recurrente, solo con el
     * PendingCompleted de esa ocurrencia (ver AgendaHelper::armar_ocurrencia()).
     *
     * @param  \App\Models\Pending  $tarea
     * @param  \Carbon\Carbon  $dia
     * @param  array  $completadas  AgendaHelper::indice_de_completadas() del margen.
     * @return bool
     */
    protected static function toca_el(Pending $tarea, Carbon $dia, array $completadas): bool
    {
        if (!AgendaHelper::es_ocurrencia($tarea, $dia)) {

            return false;
        }

        if (!is_null(AgendaHelper::completada_de($completadas, $tarea, $dia))) {

            return false;
        }

        return $tarea->es_recurrente || !$tarea->completado;
    }

    /**
     * El momento del recordatorio: ese día a la hora de la tarea.
     *
     * @param  \App\Models\Pending  $tarea
     * @param  \Carbon\Carbon  $dia
     * @return \Carbon\Carbon
     */
    protected static function momento(Pending $tarea, Carbon $dia): Carbon
    {
        return $dia->copy()->startOfDay()->setTimeFromTimeString((string) $tarea->hora_aviso);
    }

    /**
     * "HH:MM" de la hora del recordatorio (la columna es TIME y vuelve como "HH:MM:SS").
     *
     * @param  \App\Models\Pending  $tarea
     * @return string
     */
    protected static function hora(Pending $tarea): string
    {
        return substr((string) $tarea->hora_aviso, 0, 5);
    }

    /**
     * El `id` del contrato: "recordatorio:{pending_id}-{created_at unix}:{Y-m-d}:{HHMM}".
     *
     * 🔴 LA HORA VA ADENTRO. El admin reclama cada recordatorio por su `id` y no lo vuelve a mandar:
     * sin la hora, «mejor a las 11» después del aviso de las 10 tenía el mismo `id` que el de las 10 y
     * el reclamo viejo lo tapaba. Para el admin el `id` es opaco: lo guarda y lo devuelve tal cual.
     *
     * @param  \App\Models\Pending  $tarea
     * @param  \Carbon\Carbon  $dia
     * @return string
     */
    protected static function id_del_recordatorio(Pending $tarea, Carbon $dia): string
    {
        return self::PREFIJO_ID . ':' . (int) $tarea->id . '-' . self::unix_de_creacion($tarea) . ':' . $dia->format('Y-m-d')
            . ':' . str_replace(':', '', self::hora($tarea));
    }

    /**
     * El `created_at` de la tarea en segundos, o 0 si no tiene (las filas de 2024 lo tienen; es defensa).
     *
     * @param  \App\Models\Pending  $tarea
     * @return int
     */
    protected static function unix_de_creacion(Pending $tarea): int
    {
        return is_null($tarea->created_at) ? 0 : (int) Carbon::parse($tarea->created_at)->timestamp;
    }

    /**
     * Las partes del `id` del contrato, o null si no tiene esa forma, la fecha no es real o la hora no
     * es del día.
     *
     * @param  mixed  $id
     * @return array|null  {pending_id:int, creada:int, fecha:Carbon, hora:"HHMM"}
     */
    protected static function partes_del_id($id)
    {
        if (!is_scalar($id)) {

            return null;
        }

        $id = trim((string) $id);

        if (!preg_match('/^' . self::PREFIJO_ID . ':(\d{1,10})-(\d{1,12}):(\d{4}-\d{2}-\d{2}):(\d{2})(\d{2})$/', $id, $partes)) {

            return null;
        }

        $fecha = AgendaTareaHelper::parsear_fecha($partes[3]);

        if (is_null($fecha) || (int) $partes[1] <= 0 || (int) $partes[4] > 23 || (int) $partes[5] > 59) {

            return null;
        }

        return [
            'pending_id' => (int) $partes[1],
            'creada'     => (int) $partes[2],
            'fecha'      => $fecha,
            'hora'       => $partes[4] . $partes[5],
        ];
    }

    /**
     * Deja el recordatorio como mensaje del asistente en la conversación de WhatsApp vigente del dueño
     * (o en una nueva) y la deja como la más reciente, para que la respuesta del dueño caiga ahí
     * (AsistenteCanalHelper corta por `last_message_at`).
     *
     * La conversación nueva nace con título: sin él la pantalla la pinta como «infiriendo el título»
     * hasta que el dueño conteste, y quizás no conteste nunca.
     *
     * @param  \App\Models\User  $dueno
     * @param  string  $texto
     * @param  \App\Models\Pending  $tarea
     * @param  mixed  $whatsapp_message_id
     * @return void
     */
    protected static function dejar_en_la_conversacion(User $dueno, $texto, Pending $tarea, $whatsapp_message_id)
    {
        $conversacion = AsistenteCanalHelper::conversacion_vigente($dueno);

        if (is_null($conversacion)) {

            $conversacion = AiConversation::create([
                'user_id'      => $dueno->id,
                'auth_user_id' => $dueno->id,
                'origen'       => AiConversation::ORIGEN_WHATSAPP,
                'titulo'       => mb_substr('Recordatorio: ' . AgendaTareaHelper::detalle_sin_hora($tarea->detalle), 0, 150),
            ]);
        }

        $wamid = trim((string) $whatsapp_message_id);

        AiMessage::create([
            'ai_conversation_id'  => $conversacion->id,
            'rol'                 => 'assistant',
            'contenido'           => $texto,
            'estado'              => 'listo',
            'canal'               => AiMessage::CANAL_WHATSAPP,
            'whatsapp_message_id' => $wamid === '' ? null : mb_substr($wamid, 0, 128),
        ]);

        $conversacion->last_message_at = Carbon::now();
        $conversacion->save();
    }
}
