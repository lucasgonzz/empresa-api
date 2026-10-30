<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\ProviderOrderScanController;
use App\Models\AiAvisoDelAsistente;
use App\Models\AiConversation;
use App\Models\AiEnvioFallido;
use App\Models\AiMessage;
use App\Models\ProviderOrderScan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Los avisos que el asistente le manda al dueño POR WHATSAPP cuando termina algo que corría en
 * segundo plano (misión asistente-aviso-escaneo-factura, 16/10/2026). Hoy hay un solo tipo: el
 * escaneo de una factura de compra que el dueño mandó por WhatsApp.
 *
 * Pedido de Lucas, textual: «avisarle al usuario por whatsapp cuando termine de escanearse para que
 * revise el escaneo desde el sistema, tambien quiero que le pase el link para que el usuario precione
 * y se le abra ya el modulo de compras con la compra y el boton para que revise el escaneo».
 *
 * 🔴 EL AVISO SALE POR CONSULTA DEL ADMIN, NO POR EMPUJE DE ESTE API (decisión de Lucas en la Fase 2).
 * Es el mismo molde que los informes de la mañana (`informes-pendientes` + `informes/{id}/avisado`):
 * el admin pregunta cada minuto a los clientes cuyo dueño le habló al asistente en las últimas 24 h
 * (GET admin-sync/asistente/avisos-pendientes), manda el WhatsApp y recién DESPUÉS sella el aviso
 * (POST admin-sync/asistente/avisos/avisado). Sin claves nuevas en el `.env` de los clientes: un
 * callback desde acá necesitaría ADMIN_API_URL y una clave de salida que la mayoría no tiene.
 *
 * 🔴 Y SOLO SE PROMETE SI EL ADMIN AVISÓ QUE AVISA. Un admin viejo no consulta las rutas nuevas, así
 * que un escaneo que nace con `avisar_por_whatsapp = 1` contra ese admin sería un aviso que no llega
 * nunca, y el texto de la carga ya le habría dicho al dueño «te aviso por acá». Por eso el admin
 * nuevo manda `avisos = 1` (y su `spa_url`) en cada POST de mensajes y este helper lo guarda por
 * dueño (anunciar()): el escaneo pide el aviso y el texto lo promete solo con ese anuncio vigente.
 *
 * Contrato (admin consumidor → empresa proveedor), con `X-Admin-Api-Key`:
 *   GET  admin-sync/asistente/avisos-pendientes?spa_url=   → 200 {avisos:[{id, tipo, ai_conversation_id, texto, ruta, url|null}]}
 *        (`id` = "escaneo_factura:{uuid}", "envio_fallido:{uuid}" o, desde el 30/10/2026,
 *        "turno_sin_terminar:{uuid}": ver TIPO_TURNO_SIN_TERMINAR)
 *   POST admin-sync/asistente/avisos/avisado {id, url?, whatsapp_message_id?} → 200 {ok:true} · 404
 *
 * 🔴 SEGUNDO TIPO DESDE EL 19/10/2026: `envio_fallido` (misión asistente-arreglos-segunda-prueba, N3).
 * Un envío a clientes del asistente (comprobante, recordatorios, promo) que falló DESPUÉS del sí del
 * dueño —el mail que murió en el worker, el 550, el WhatsApp que Meta rechazó, el recordatorio que Kapso
 * no confirmó— vuelve al dueño por este mismo canal (EnviosFallidosDelAsistenteHelper). La forma del
 * contrato NO cambia: mismo GET, mismo POST, y el aviso nuevo viaja con `ruta: ""` y `url: null` (no hay
 * pantalla que abrir). El admin manda `texto` tal cual sin mirar el tipo y, sin `url` ni `ruta`, sale sin
 * link (verificado en admin-api `AsistenteAvisosService::link_del_aviso()` y `traer_avisos()` de master);
 * su `marcar_avisado()` da por cerrado un 404 y un 200. Un admin viejo recibe el tipo nuevo igual: no lo
 * mira. Un empresa viejo no tiene filas nuevas.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class AvisosDelAsistenteHelper
{
    /** El aviso del escaneo de una factura. El `id` del contrato es "{tipo}:{uuid}". */
    const TIPO_ESCANEO_FACTURA = 'escaneo_factura';

    /** El aviso de un envío a clientes que falló después del sí (`ai_envios_fallidos`, N3 del 19/10/2026). */
    const TIPO_ENVIO_FALLIDO = 'envio_fallido';

    /**
     * El aviso de un turno de WhatsApp que no terminó (`ai_avisos_del_asistente`, misión
     * agente-mejoras-uso-real, ronda de arreglos del 30/10/2026): ver AvisosDeTurnosIaHelper.
     */
    const TIPO_TURNO_SIN_TERMINAR = AiAvisoDelAsistente::TIPO_TURNO_SIN_TERMINAR;

    /** Cuánto dura el anuncio del admin ("yo consulto los avisos" y su spa_url): 30 días. */
    const DIAS_DEL_ANUNCIO = 30;

    /**
     * Horas desde que nació el escaneo (`created_at`) en las que el aviso todavía se manda. Coinciden con la
     * ventana de 24 h de WhatsApp: un aviso que el admin no pudo mandar en un día (ventana cerrada)
     * ya no le sirve al dueño como aviso, y sigue teniendo el botón rojo en Compras.
     */
    const HORAS_DEL_AVISO = 24;

    /** Cuántos avisos viajan por consulta como mucho. */
    const TOPE_AVISOS = 10;

    /** Claves del caché del anuncio del admin, por dueño. */
    const CLAVE_ADMIN_AVISA = 'asistente_ia:admin_avisa:';
    const CLAVE_SPA_URL     = 'asistente_ia:spa_url:';

    /** El texto que se le ofreció al admin para un aviso de envío fallido, por uuid (ver pendientes()). */
    const CLAVE_TEXTO_OFRECIDO = 'asistente_ia:envio_fallido_ofrecido:';

    /**
     * Guarda lo que el admin anunció en el POST de mensajes: si consulta los avisos y la URL del SPA
     * de este cliente que conoce él (`client_apis.spa_url`).
     *
     * 🔴 SIN EL CAMPO SE BORRA EL ANUNCIO, no se deja el anterior. El que manda cada mensaje es el
     * admin que está en producción AHORA: si se volvió a una versión vieja (rollback), sus mensajes
     * llegan sin `avisos` y desde ese mismo momento ningún escaneo promete un aviso que nadie va a
     * mandar. Dejar el anuncio viejo vivo 30 días sería exactamente esa promesa rota.
     *
     * @param  int  $owner_id
     * @param  mixed  $avisos  El campo `avisos` del request ("1" = consulto los avisos), o null.
     * @param  mixed  $spa_url  El campo `spa_url` del request, o null.
     * @return void
     */
    public static function anunciar($owner_id, $avisos, $spa_url)
    {
        $owner_id = (int) $owner_id;

        $avisa = !is_null($avisos) && in_array(strtolower(trim((string) $avisos)), ['1', 'true', 'si', 'sí'], true);

        $vence = Carbon::now()->addDays(self::DIAS_DEL_ANUNCIO);

        if ($avisa) {

            Cache::put(self::CLAVE_ADMIN_AVISA . $owner_id, 1, $vence);

        } else {

            Cache::forget(self::CLAVE_ADMIN_AVISA . $owner_id);
        }

        $url = self::url_valida($spa_url);

        if (!is_null($url)) {

            Cache::put(self::CLAVE_SPA_URL . $owner_id, $url, $vence);

        } elseif (!$avisa) {

            /*
             * Sin anuncio y sin URL, se olvida también la URL: es el admin viejo. Con anuncio pero
             * sin URL (un cliente sin `spa_url` en el admin) se conserva la anterior si la había,
             * que es lo mejor que se sabe de la base del link.
             */
            Cache::forget(self::CLAVE_SPA_URL . $owner_id);
        }
    }

    /**
     * true si el admin de este dueño anunció que consulta los avisos (ver anunciar()).
     *
     * @param  int  $owner_id
     * @return bool
     */
    public static function admin_avisa($owner_id): bool
    {
        return (bool) Cache::get(self::CLAVE_ADMIN_AVISA . (int) $owner_id, false);
    }

    /**
     * La base del link al SPA del cliente, sin barra final, o null si no se conoce ninguna.
     *
     * Por orden: la que mandó el admin en ESTA consulta → la última que anunció en un mensaje → la
     * `SPA_URL` del `.env` (`services.mostrador.spa_url`, que casi ningún cliente tiene). 🔴 Nunca
     * `app.url`: es la URL de la API y el link daría 404 en el teléfono del dueño (mismo criterio que
     * MostradorAccesoHelper::base_del_sistema).
     *
     * @param  int  $owner_id
     * @param  mixed  $spa_url_pedida  La `spa_url` de la query del admin, o null.
     * @return string|null
     */
    public static function base_del_link($owner_id, $spa_url_pedida = null)
    {
        $pedida = self::url_valida($spa_url_pedida);

        if (!is_null($pedida)) {

            return $pedida;
        }

        $anunciada = self::url_valida(Cache::get(self::CLAVE_SPA_URL . (int) $owner_id));

        if (!is_null($anunciada)) {

            return $anunciada;
        }

        return self::url_valida(config('services.mostrador.spa_url'));
    }

    /**
     * Los avisos pendientes del dueño, del más viejo al más nuevo y con tope.
     *
     * Un escaneo entra si: pidió el aviso (`avisar_por_whatsapp = 1`), no se avisó todavía, nació
     * hace menos de HORAS_DEL_AVISO, no lo gestionó ya el dueño desde la pantalla —si ya lo confirmó
     * o descartó, «revisalo» es un aviso viejo— y terminó: `listo`, `error` o COLGADO.
     *
     * 🔴 COLGADO = sigue en `pendiente`/`procesando` más allá de la ventana de vencimiento del
     * controlador (MINUTOS_ESCANEO_EN_CURSO). Pasa cuando la cola se queda sin consumidor: ningún
     * worker lo va a terminar y, sin esto, el aviso que se le prometió al dueño no salía nunca. Se
     * avisa como error («la lectura se cortó»): EscaneoDeFacturaIaHelper::estado_para_la_persona()
     * ya lo trata así.
     *
     * 🔴 LA VENTANA SE MIDE POR `created_at`, NO POR `updated_at` (chequeo independiente del
     * 16/10/2026): `updated_at` lo mueve cualquier escritura posterior —`visto_at` cuando el dueño
     * mira el aviso de la pantalla— y estiraba la ventana sin que el escaneo hubiera cambiado.
     * `created_at` es fijo: un aviso vale 24 h desde que se mandó la factura.
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $spa_url_pedida  La `spa_url` de la query, o null.
     * @return array<int, array<string, mixed>>
     */
    public static function pendientes(User $dueno, $spa_url_pedida = null): array
    {
        $base = self::base_del_link((int) $dueno->id, $spa_url_pedida);

        $scans = ProviderOrderScan::where('user_id', (int) $dueno->id)
                                    ->where('avisar_por_whatsapp', 1)
                                    ->whereNull('avisado_por_whatsapp_at')
                                    ->whereNull('gestionado_at')
                                    ->where('created_at', '>=', Carbon::now()->subHours(self::HORAS_DEL_AVISO))
                                    ->where(function ($query) {
                                        $query->whereIn('estado', [ProviderOrderScan::ESTADO_LISTO, 'error'])
                                                ->orWhere(function ($colgado) {
                                                    $colgado->whereIn('estado', ProviderOrderScanController::ESTADOS_EN_CURSO)
                                                            ->where('created_at', '<', Carbon::now()->subMinutes(ProviderOrderScanController::MINUTOS_ESCANEO_EN_CURSO));
                                                });
                                    })
                                    ->orderBy('created_at')
                                    ->orderBy('id')
                                    ->limit(self::TOPE_AVISOS)
                                    ->get();

        /*
         * Los avisos de los dos tipos van en UNA lista, del más viejo al más nuevo y con el mismo tope.
         * `$orden` lleva, en paralelo, con qué se ordena cada uno (cuándo nació la fila, el tipo y su id):
         * el aviso en sí no cambia de forma (el admin lo lee con las mismas seis claves de siempre).
         */
        $avisos = [];
        $orden = [];

        foreach ($scans as $scan) {

            $ruta = EscaneoDeFacturaIaHelper::ruta_de_revision($scan);

            $avisos[] = [
                /*
                 * 🔴 POR UUID, NO POR EL ID DE LA FILA (chequeo independiente del 16/10/2026). Las
                 * demos corren `migrate:fresh` en cada reseteo y el autoincremental vuelve a 1; el
                 * admin guarda el reclamo `asistente_aviso:{client}:{id}` siete días, así que el
                 * primer escaneo de la demo siguiente se daba por «ya avisado» y nunca salía. El
                 * uuid no se repite entre reseteos.
                 */
                'id'                 => self::TIPO_ESCANEO_FACTURA . ':' . (string) $scan->uuid,
                'tipo'               => self::TIPO_ESCANEO_FACTURA,
                'ai_conversation_id' => is_null($scan->ai_conversation_id) ? null : (int) $scan->ai_conversation_id,
                'texto'              => self::texto_del_aviso($scan),
                'ruta'               => $ruta,
                'url'                => is_null($base) ? null : $base . $ruta,
            ];

            $orden[] = [self::momento($scan->created_at), 0, (int) $scan->id];
        }

        /*
         * 🔴 LOS ENVÍOS A CLIENTES QUE FALLARON DESPUÉS DEL SÍ (N3, 19/10/2026). Sin link (`ruta` vacía y
         * `url` null: no hay pantalla que abrir, el aviso es el texto). Por uuid, por lo mismo que el
         * escaneo. Si la tabla todavía no existe (un upgrade a medio correr), no rompe los escaneos.
         */
        try {

            foreach (EnviosFallidosDelAsistenteHelper::pendientes_de_aviso($dueno, self::TOPE_AVISOS) as $fila) {

                /*
                 * 🔴 SE GUARDA LO QUE SE OFRECIÓ (revisión del 19/10/2026). El admin manda ESTE texto y
                 * recién después llama a avisos/avisado; si entre las dos cosas llega otro fallo de la
                 * misma tarjeta (el WhatsApp del mismo comprobante, por ejemplo), rearmar el texto al
                 * sellar dejaría en la conversación algo distinto de lo que el dueño recibió.
                 * marcar_envio_fallido_avisado() usa éste.
                 *
                 * 🔴 VALE LO PRIMERO QUE SE OFRECIÓ (segunda revisión): `Cache::add`, que no pisa, y las
                 * consultas siguientes ofrecen ese mismo texto. Con `put` cada consulta lo pisaba, y dos
                 * corridas del admin que se superponen podían dejar guardado un texto distinto del que
                 * mandó la que llegó a mandar. Así lo que se manda y lo que queda es siempre lo mismo.
                 */
                $clave_del_texto = self::CLAVE_TEXTO_OFRECIDO . (string) $fila->uuid;

                Cache::add($clave_del_texto, EnviosFallidosDelAsistenteHelper::texto_del_aviso($fila), Carbon::now()->addHours(self::HORAS_DEL_AVISO * 2));

                $ofrecido = Cache::get($clave_del_texto);

                $texto = is_string($ofrecido) && trim($ofrecido) !== '' ? $ofrecido : EnviosFallidosDelAsistenteHelper::texto_del_aviso($fila);

                $avisos[] = [
                    'id'                 => self::TIPO_ENVIO_FALLIDO . ':' . (string) $fila->uuid,
                    'tipo'               => self::TIPO_ENVIO_FALLIDO,
                    'ai_conversation_id' => empty($fila->ai_conversation_id) ? null : (int) $fila->ai_conversation_id,
                    'texto'              => $texto,
                    'ruta'               => '',
                    'url'                => null,
                ];

                $orden[] = [self::momento($fila->created_at), 1, (int) $fila->id];
            }

        } catch (\Throwable $e) {
            // Sin la tabla de envíos fallidos, siguen saliendo los avisos de los escaneos.
        }

        /*
         * 🔴 TERCER TIPO DESDE EL 30/10/2026: `turno_sin_terminar` (misión agente-mejoras-uso-real, ronda
         * de arreglos). El turno de WhatsApp que se cortó con fotos sin cargar, o que quedó colgado en
         * `pendiente` y cerró el vigía (AvisosDeTurnosIaHelper). Con el mensaje en `error` el admin manda
         * su disculpa fija y no lee el contenido; esto es lo que le dice al dueño qué pasó con su pedido.
         * Sin link, como el del envío fallido. Misma forma de contrato: el admin no mira el tipo.
         */
        try {

            foreach (AvisosDeTurnosIaHelper::pendientes_de_aviso($dueno, self::TOPE_AVISOS) as $fila) {

                $avisos[] = [
                    'id'                 => self::TIPO_TURNO_SIN_TERMINAR . ':' . (string) $fila->uuid,
                    'tipo'               => self::TIPO_TURNO_SIN_TERMINAR,
                    'ai_conversation_id' => empty($fila->ai_conversation_id) ? null : (int) $fila->ai_conversation_id,
                    'texto'              => (string) $fila->texto,
                    'ruta'               => '',
                    'url'                => null,
                ];

                $orden[] = [self::momento($fila->created_at), 2, (int) $fila->id];
            }

        } catch (\Throwable $e) {
            // Sin la tabla de los avisos de turnos, siguen saliendo los demás.
        }

        if (count($orden) > 1) {

            $posiciones = array_keys($avisos);

            usort($posiciones, function ($a, $b) use ($orden) {
                return $orden[$a] <=> $orden[$b];
            });

            $ordenados = [];

            foreach ($posiciones as $posicion) {
                $ordenados[] = $avisos[$posicion];
            }

            $avisos = $ordenados;
        }

        return array_slice($avisos, 0, self::TOPE_AVISOS);
    }

    /**
     * El momento de una fila en segundos, para ordenar avisos de tablas distintas.
     *
     * @param  mixed  $fecha
     * @return int
     */
    protected static function momento($fecha)
    {
        if (empty($fecha)) {

            return 0;
        }

        return Carbon::parse($fecha)->getTimestamp();
    }

    /**
     * Sella un aviso como mandado y deja el aviso en la conversación como un mensaje del asistente.
     *
     * 🔴 EL MENSAJE EN LA CONVERSACIÓN ES LO QUE HACE QUE «DALE, ¿QUÉ TRAE?» TENGA CONTEXTO. El admin
     * cita este aviso con el `ai_conversation_id`; sin el mensaje en el historial, el asistente
     * recibiría la pregunta sin saber de qué factura le hablan. Se crea UNA sola vez: la fila del
     * escaneo se bloquea y el sello se mira adentro de la transacción, así que dos llamadas casi
     * juntas (un reintento del admin) no dejan dos mensajes.
     *
     * 🔴 DESPACHA POR TIPO (desde el 19/10/2026, N3): el prefijo del `id` dice de qué tabla es el aviso.
     * Hasta acá todo lo que no fuera `escaneo_factura:` daba 404; un `envio_fallido:{uuid}` ahora sella
     * su fila de `ai_envios_fallidos` y deja el aviso en su conversación, con las mismas garantías (dentro
     * del dueño, una sola vez, idempotente).
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $id  El `id` del contrato ("escaneo_factura:{uuid}" o "envio_fallido:{uuid}").
     * @param  mixed  $url  El link que salió en el WhatsApp, o null.
     * @param  mixed  $whatsapp_message_id  El wamid del WhatsApp que salió, o null.
     * @return bool  false si el aviso no existe o no es de este dueño (el 404 del contrato).
     */
    public static function marcar_avisado(User $dueno, $id, $url = null, $whatsapp_message_id = null): bool
    {
        $uuid_de_envio = self::uuid_del_tipo($id, self::TIPO_ENVIO_FALLIDO);

        if (!is_null($uuid_de_envio)) {

            return self::marcar_envio_fallido_avisado($dueno, $uuid_de_envio, self::url_segura($url), $whatsapp_message_id);
        }

        /* El turno sin terminar (30/10/2026): mismo molde que el envío fallido. */
        $uuid_de_turno = self::uuid_del_tipo($id, self::TIPO_TURNO_SIN_TERMINAR);

        if (!is_null($uuid_de_turno)) {

            return self::marcar_turno_avisado($dueno, $uuid_de_turno, self::url_segura($url), $whatsapp_message_id);
        }

        $uuid = self::uuid_de_escaneo($id);

        if (is_null($uuid)) {

            return false;
        }

        /*
         * La `url` que manda el admin pasa por el mismo filtro que la base del link: lo que no es
         * https (o http de un host local) no se guarda en el historial, y el mensaje queda sin link.
         */
        $url = self::url_segura($url);

        return DB::transaction(function () use ($dueno, $uuid, $url, $whatsapp_message_id) {

            /* Siempre dentro del dueño: el uuid solo no alcanza para saber de quién es. */
            $scan = ProviderOrderScan::where('user_id', (int) $dueno->id)
                                        ->where('uuid', $uuid)
                                        ->where('avisar_por_whatsapp', 1)
                                        ->lockForUpdate()
                                        ->first();

            if (is_null($scan)) {

                return false;
            }

            /* Idempotente: un segundo aviso no pisa la marca ni duplica el mensaje. */
            if (!is_null($scan->avisado_por_whatsapp_at)) {

                return true;
            }

            $scan->avisado_por_whatsapp_at = Carbon::now();
            $scan->save();

            self::dejar_en_la_conversacion($dueno, $scan->ai_conversation_id, self::texto_del_aviso($scan), $url, $whatsapp_message_id);

            return true;
        });
    }

    /**
     * Sella el aviso de un envío que falló y lo deja en su conversación como un mensaje del asistente
     * (para que «¿y a quién no le llegó?» tenga contexto). Mismas garantías que el del escaneo: dentro
     * del dueño, la fila bloqueada y el sello mirado adentro de la transacción (un reintento del admin no
     * deja dos mensajes), idempotente.
     *
     * @param  \App\Models\User  $dueno
     * @param  string  $uuid
     * @param  string|null  $url  Este aviso no lleva link; si el admin mandara uno, pasa por url_segura().
     * @param  mixed  $whatsapp_message_id
     * @return bool  false si no existe, no es de este dueño o no pidió aviso (el 404 del contrato).
     */
    protected static function marcar_envio_fallido_avisado(User $dueno, $uuid, $url, $whatsapp_message_id): bool
    {
        return DB::transaction(function () use ($dueno, $uuid, $url, $whatsapp_message_id) {

            $fila = AiEnvioFallido::where('owner_id', (int) $dueno->id)
                                    ->where('uuid', $uuid)
                                    ->where('avisar_por_whatsapp', 1)
                                    ->lockForUpdate()
                                    ->first();

            if (is_null($fila)) {

                return false;
            }

            if (!is_null($fila->avisado_por_whatsapp_at)) {

                return true;
            }

            $fila->avisado_por_whatsapp_at = Carbon::now();
            $fila->save();

            /*
             * El texto que queda en la conversación es el que SE OFRECIÓ (y el admin mandó), no uno
             * rearmado con lo que pasó después del GET. Sin el ofrecido en el caché (venció, o el admin
             * marcó sin haber consultado), se arma de nuevo, que es lo mejor que se sabe.
             */
            $clave = self::CLAVE_TEXTO_OFRECIDO . (string) $fila->uuid;

            $ofrecido = Cache::get($clave);

            $texto = is_string($ofrecido) && trim($ofrecido) !== '' ? $ofrecido : EnviosFallidosDelAsistenteHelper::texto_del_aviso($fila);

            self::dejar_en_la_conversacion($dueno, $fila->ai_conversation_id, $texto, $url, $whatsapp_message_id);

            Cache::forget($clave);

            return true;
        });
    }

    /**
     * Sella el aviso de un turno que no terminó y lo deja en su conversación como un mensaje del
     * asistente: así «seguí» o «sí, retomalo» tienen contexto en el turno siguiente. Mismas garantías que
     * los otros dos tipos: dentro del dueño, la fila bloqueada y el sello mirado adentro de la
     * transacción (un reintento del admin no deja dos mensajes), idempotente.
     *
     * @param  \App\Models\User  $dueno
     * @param  string  $uuid
     * @param  string|null  $url  Este aviso no lleva link; si el admin mandara uno, ya pasó por url_segura().
     * @param  mixed  $whatsapp_message_id
     * @return bool  false si no existe o no es de este dueño (el 404 del contrato).
     */
    protected static function marcar_turno_avisado(User $dueno, $uuid, $url, $whatsapp_message_id): bool
    {
        if (!AvisosDeTurnosIaHelper::hay_tabla()) {

            return false;
        }

        return DB::transaction(function () use ($dueno, $uuid, $url, $whatsapp_message_id) {

            $fila = AiAvisoDelAsistente::where('owner_id', (int) $dueno->id)
                                        ->where('uuid', $uuid)
                                        ->lockForUpdate()
                                        ->first();

            if (is_null($fila)) {

                return false;
            }

            if (!is_null($fila->avisado_por_whatsapp_at)) {

                return true;
            }

            $fila->avisado_por_whatsapp_at = Carbon::now();
            $fila->whatsapp_message_id = is_null($whatsapp_message_id) ? null : mb_substr((string) $whatsapp_message_id, 0, 191);
            $fila->save();

            self::dejar_en_la_conversacion($dueno, $fila->ai_conversation_id, (string) $fila->texto, $url, $whatsapp_message_id);

            return true;
        });
    }

    /**
     * El texto del aviso, SIN el link (lo agrega el admin, que es quien sabe si lo tiene). Corto, en
     * la voz del asistente y sin markdown pesado: en WhatsApp, `*negrita*` como mucho.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return string
     */
    public static function texto_del_aviso(ProviderOrderScan $scan): string
    {
        $compra = $scan->provider_order;

        $proveedor = EscaneoDeFacturaIaHelper::nombre_del_proveedor($scan);

        $de_quien = is_null($proveedor) ? 'la factura' : 'la factura de *' . $proveedor . '*';

        $de_la_compra = is_null($compra) ? '' : ' (compra N° ' . $compra->num . ')';

        if (EscaneoDeFacturaIaHelper::estado_para_la_persona($scan) === EscaneoDeFacturaIaHelper::ESTADO_ERROR) {

            return 'No pude leer ' . $de_quien . $de_la_compra . ': ' . EscaneoDeFacturaIaHelper::motivo_del_error($scan)
                . '. Podés volver a escanearla desde la compra.';
        }

        $resultado = is_array($scan->resultado) ? $scan->resultado : [];

        $factura = isset($resultado['factura']) && is_array($resultado['factura']) ? $resultado['factura'] : [];

        $texto = 'Listo, ya leí ' . $de_quien . $de_la_compra
            . self::otra_razon_social($proveedor, $factura) . '.';

        $comprobante = EscaneoDeFacturaIaHelper::comprobante($factura);

        $total = isset($factura['total']) && is_numeric($factura['total']) ? (float) $factura['total'] : null;

        if (!is_null($comprobante) || !is_null($total)) {

            $partes = [];

            if (!is_null($comprobante)) {

                $partes[] = $comprobante;
            }

            if (!is_null($total)) {

                $partes[] = 'total ' . self::pesos($total);
            }

            $texto .= ' ' . self::primera_mayuscula(implode(', ', $partes)) . '.';
        }

        $texto .= ' ' . self::frase_de_renglones(EscaneoDeFacturaIaHelper::conteo($scan));

        $dudosos = self::campos_dudosos_legibles($factura);

        if ($dudosos !== '') {

            $texto .= ' Ojo: hay datos que no se leyeron claros (' . $dudosos . '), fijate al revisar.';
        }

        return $texto . ' Revisala y confirmala en Compras.';
    }

    /**
     * La frase de los renglones: cuántos, cuántos reconocidos y cuántos sin match.
     *
     * @param  array  $conteo  EscaneoDeFacturaIaHelper::conteo()
     * @return string
     */
    protected static function frase_de_renglones(array $conteo): string
    {
        $renglones = (int) $conteo['renglones'];
        $reconocidos = (int) $conteo['reconocidos'];
        $sin_match = (int) $conteo['sin_match'];

        if ($renglones === 0) {

            return 'No encontré renglones de artículos en la factura.';
        }

        $cuantos = $renglones === 1 ? '1 renglón' : $renglones . ' renglones';

        if ($sin_match === 0 && $reconocidos === $renglones) {

            return 'Trae ' . $cuantos . ($renglones === 1 ? ' y lo reconocí entre tus artículos.' : ' y los reconocí todos entre tus artículos.');
        }

        if ($reconocidos === 0 && $sin_match === $renglones) {

            return 'Trae ' . $cuantos . ' y ' . ($renglones === 1 ? 'no coincide' : 'ninguno coincide')
                . ' con tus artículos (' . ($renglones === 1 ? 'es nuevo' : 'son nuevos') . ' para dar de alta o asociar).';
        }

        $frase = 'Trae ' . $cuantos . ': ' . $reconocidos . ($reconocidos === 1 ? ' reconocido' : ' reconocidos');

        if ($sin_match > 0) {

            $frase .= ' y ' . $sin_match . ' sin match ('
                . ($sin_match === 1 ? 'artículo nuevo' : 'artículos nuevos') . ' para dar de alta o asociar)';
        }

        return $frase . '.';
    }

    /**
     * " (en la factura figura «X»)" cuando el emisor leído no es el proveedor de la compra, o "".
     *
     * El proveedor que manda es el de la compra (lo dijo el dueño); esto solo le avisa que el papel
     * dice otra cosa, por si se equivocó de proveedor. Se compara normalizado y en las dos
     * direcciones, igual que ProveedorDeLaFacturaIaHelper: «Distribuidora Sur S.R.L.» y
     * «Distribuidora Sur» son el mismo.
     *
     * @param  string|null  $proveedor
     * @param  array  $factura
     * @return string
     */
    protected static function otra_razon_social($proveedor, array $factura): string
    {
        $emisor = isset($factura['emisor_razon_social']) ? trim((string) $factura['emisor_razon_social']) : '';

        if ($emisor === '' || is_null($proveedor)) {

            return '';
        }

        $a = ProveedorDeLaFacturaIaHelper::normalizar($proveedor);
        $b = ProveedorDeLaFacturaIaHelper::normalizar($emisor);

        if ($a === '' || $b === '' || mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false) {

            return '';
        }

        return ' (en la factura figura «' . $emisor . '»)';
    }

    /**
     * Los campos dudosos de la factura en palabras, separados por coma, o "".
     *
     * @param  array  $factura
     * @return string
     */
    protected static function campos_dudosos_legibles(array $factura): string
    {
        if (!isset($factura['campos_dudosos']) || !is_array($factura['campos_dudosos'])) {

            return '';
        }

        $nombres = [
            'total'               => 'total',
            'neto_gravado'        => 'neto',
            'total_iva'           => 'IVA',
            'numero'              => 'número',
            'punto_venta'         => 'punto de venta',
            'issued_at'           => 'fecha',
            'emisor_cuit'         => 'CUIT',
            'emisor_razon_social' => 'razón social',
            'tipo_comprobante'    => 'tipo de comprobante',
        ];

        $legibles = [];

        foreach ($factura['campos_dudosos'] as $campo) {

            $campo = (string) $campo;

            $legible = isset($nombres[$campo]) ? $nombres[$campo] : str_replace('_', ' ', $campo);

            if ($legible !== '' && !in_array($legible, $legibles, true)) {

                $legibles[] = $legible;
            }
        }

        return implode(', ', $legibles);
    }

    /**
     * Crea el mensaje del asistente con el aviso en su conversación y la deja como la más reciente
     * de WhatsApp, para que una respuesta SIN cita también caiga ahí (AsistenteCanalHelper corta por
     * `last_message_at`). Sin conversación (borrada desde el sistema) el sello igual queda.
     *
     * Generalizado el 19/10/2026 (N3): recibe la conversación y el texto, así sirve para el aviso del
     * escaneo y para el de un envío que falló.
     *
     * @param  \App\Models\User  $dueno
     * @param  int|null  $ai_conversation_id
     * @param  string  $contenido  El texto del aviso, sin el link.
     * @param  mixed  $url
     * @param  mixed  $whatsapp_message_id
     * @return void
     */
    protected static function dejar_en_la_conversacion(User $dueno, $ai_conversation_id, $contenido, $url, $whatsapp_message_id)
    {
        if (empty($ai_conversation_id)) {

            return;
        }

        $conversacion = AiConversation::where('id', (int) $ai_conversation_id)
                                        ->where('user_id', (int) $dueno->id)
                                        ->first();

        if (is_null($conversacion)) {

            return;
        }

        $contenido = (string) $contenido;

        $url = trim((string) $url);

        if ($url !== '') {

            $contenido .= "\n\n" . $url;
        }

        $wamid = trim((string) $whatsapp_message_id);

        AiMessage::create([
            'ai_conversation_id'  => $conversacion->id,
            'rol'                 => 'assistant',
            'contenido'           => $contenido,
            'estado'              => 'listo',
            'canal'               => AiMessage::CANAL_WHATSAPP,
            'whatsapp_message_id' => $wamid === '' ? null : mb_substr($wamid, 0, 128),
        ]);

        $conversacion->last_message_at = Carbon::now();
        $conversacion->save();
    }

    /**
     * El uuid del escaneo a partir del `id` del contrato ("escaneo_factura:{uuid}"), o null si no es
     * de este tipo o no tiene forma de uuid. Ver el 🔴 de pendientes(): el id es el uuid y no el de
     * la fila para que un `migrate:fresh` de demo no repita ids que el admin ya reclamó.
     *
     * @param  mixed  $id
     * @return string|null
     */
    protected static function uuid_de_escaneo($id)
    {
        return self::uuid_del_tipo($id, self::TIPO_ESCANEO_FACTURA);
    }

    /**
     * El uuid de un aviso de un tipo a partir del `id` del contrato ("{tipo}:{uuid}"), o null si el id
     * no es de ese tipo o no tiene forma de uuid. Es la regla de uuid_de_escaneo() para cualquier tipo
     * (19/10/2026, N3: el segundo es `envio_fallido`).
     *
     * @param  mixed  $id
     * @param  string  $tipo
     * @return string|null
     */
    protected static function uuid_del_tipo($id, $tipo)
    {
        $id = trim((string) $id);

        $prefijo = $tipo . ':';

        if (strpos($id, $prefijo) !== 0) {

            return null;
        }

        $uuid = substr($id, strlen($prefijo));

        /* Los uuid de Str::uuid(): hex y guiones, 64 como mucho (la columna más larga de las dos). */
        if (!is_string($uuid) || !preg_match('/^[A-Za-z0-9\-]{1,64}$/', $uuid)) {

            return null;
        }

        return $uuid;
    }

    /**
     * Una URL completa en la que se puede confiar para mandarle al dueño, o null.
     *
     * 🔴 HTTPS SIEMPRE, salvo hosts locales de desarrollo (localhost, 127.0.0.1, *.test, *.local),
     * donde se acepta http (chequeo independiente del 16/10/2026). El link sale en un WhatsApp al
     * teléfono del dueño y abre su sistema con sesión: un http contra un dominio real es un link que
     * viaja en claro, y un esquema raro (`javascript:`) directamente no es un link. Sin credenciales
     * en la URL. La query se acepta (la `url` del aviso la trae); el fragmento no.
     *
     * @param  mixed  $url
     * @return string|null  La URL recortada, sin tocar su contenido.
     */
    public static function url_segura($url)
    {
        if (!is_string($url)) {

            return null;
        }

        $url = trim($url);

        if ($url === '' || mb_strlen($url) > 2000) {

            return null;
        }

        $partes = parse_url($url);

        if (!is_array($partes) || !isset($partes['scheme'], $partes['host']) || $partes['host'] === '') {

            return null;
        }

        if (isset($partes['fragment']) || isset($partes['user']) || isset($partes['pass'])) {

            return null;
        }

        $esquema = strtolower($partes['scheme']);

        if ($esquema === 'https') {

            return $url;
        }

        if ($esquema === 'http' && self::es_host_local($partes['host'])) {

            return $url;
        }

        return null;
    }

    /**
     * La BASE del link al SPA: una url_segura() de hasta 255 caracteres, sin query y sin barra
     * final (a la base se le pega la ruta con su propia query), o null.
     *
     * @param  mixed  $url
     * @return string|null
     */
    public static function url_valida($url)
    {
        $segura = self::url_segura($url);

        if (is_null($segura) || mb_strlen($segura) > 255) {

            return null;
        }

        $partes = parse_url($segura);

        if (isset($partes['query'])) {

            return null;
        }

        return rtrim($segura, '/');
    }

    /**
     * true si el host es de desarrollo local (el único caso donde se acepta http).
     *
     * @param  string  $host
     * @return bool
     */
    protected static function es_host_local($host)
    {
        $host = strtolower(trim((string) $host, '[]'));

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {

            return true;
        }

        foreach (['.test', '.local', '.localhost'] as $sufijo) {

            if (strlen($host) > strlen($sufijo) && substr($host, -strlen($sufijo)) === $sufijo) {

                return true;
            }
        }

        return false;
    }

    /**
     * Pesos con el formato argentino: $294.582,56.
     *
     * @param  float  $monto
     * @return string
     */
    protected static function pesos($monto): string
    {
        return '$' . number_format((float) $monto, 2, ',', '.');
    }

    /**
     * La primera letra en mayúscula (multibyte).
     *
     * @param  string  $texto
     * @return string
     */
    protected static function primera_mayuscula($texto): string
    {
        $texto = (string) $texto;

        if ($texto === '') {

            return $texto;
        }

        return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }
}
