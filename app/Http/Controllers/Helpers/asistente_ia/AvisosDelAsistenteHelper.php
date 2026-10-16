<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
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
 *   POST admin-sync/asistente/avisos/avisado {id, url?, whatsapp_message_id?} → 200 {ok:true} · 404
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class AvisosDelAsistenteHelper
{
    /** El único tipo de aviso que existe hoy. El `id` del contrato es "{tipo}:{id de la fila}". */
    const TIPO_ESCANEO_FACTURA = 'escaneo_factura';

    /** Cuánto dura el anuncio del admin ("yo consulto los avisos" y su spa_url): 30 días. */
    const DIAS_DEL_ANUNCIO = 30;

    /**
     * Horas después de terminado el escaneo en las que el aviso todavía se manda. Coinciden con la
     * ventana de 24 h de WhatsApp: un aviso que el admin no pudo mandar en un día (ventana cerrada)
     * ya no le sirve al dueño como aviso, y sigue teniendo el botón rojo en Compras.
     */
    const HORAS_DEL_AVISO = 24;

    /** Cuántos avisos viajan por consulta como mucho. */
    const TOPE_AVISOS = 10;

    /** Claves del caché del anuncio del admin, por dueño. */
    const CLAVE_ADMIN_AVISA = 'asistente_ia:admin_avisa:';
    const CLAVE_SPA_URL     = 'asistente_ia:spa_url:';

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
     * Un escaneo entra si: pidió el aviso (`avisar_por_whatsapp = 1`), terminó (`listo` o `error`),
     * no se avisó todavía, terminó hace menos de HORAS_DEL_AVISO (por `updated_at`, que es la última
     * escritura del job) y no lo gestionó ya el dueño desde la pantalla — si ya lo confirmó o
     * descartó, «revisalo» es un aviso viejo.
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
                                    ->whereIn('estado', [ProviderOrderScan::ESTADO_LISTO, 'error'])
                                    ->whereNull('gestionado_at')
                                    ->where('updated_at', '>=', Carbon::now()->subHours(self::HORAS_DEL_AVISO))
                                    ->orderBy('updated_at')
                                    ->orderBy('id')
                                    ->limit(self::TOPE_AVISOS)
                                    ->get();

        $avisos = [];

        foreach ($scans as $scan) {

            $ruta = EscaneoDeFacturaIaHelper::ruta_de_revision($scan);

            $avisos[] = [
                'id'                 => self::TIPO_ESCANEO_FACTURA . ':' . (int) $scan->id,
                'tipo'               => self::TIPO_ESCANEO_FACTURA,
                'ai_conversation_id' => is_null($scan->ai_conversation_id) ? null : (int) $scan->ai_conversation_id,
                'texto'              => self::texto_del_aviso($scan),
                'ruta'               => $ruta,
                'url'                => is_null($base) ? null : $base . $ruta,
            ];
        }

        return $avisos;
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
     * @param  \App\Models\User  $dueno
     * @param  mixed  $id  El `id` del contrato ("escaneo_factura:{scan_id}").
     * @param  mixed  $url  El link que salió en el WhatsApp, o null.
     * @param  mixed  $whatsapp_message_id  El wamid del WhatsApp que salió, o null.
     * @return bool  false si el aviso no existe o no es de este dueño (el 404 del contrato).
     */
    public static function marcar_avisado(User $dueno, $id, $url = null, $whatsapp_message_id = null): bool
    {
        $scan_id = self::id_de_escaneo($id);

        if (is_null($scan_id)) {

            return false;
        }

        return DB::transaction(function () use ($dueno, $scan_id, $url, $whatsapp_message_id) {

            $scan = ProviderOrderScan::where('user_id', (int) $dueno->id)
                                        ->where('id', $scan_id)
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

            self::dejar_en_la_conversacion($dueno, $scan, $url, $whatsapp_message_id);

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
     * @param  \App\Models\User  $dueno
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  mixed  $url
     * @param  mixed  $whatsapp_message_id
     * @return void
     */
    protected static function dejar_en_la_conversacion(User $dueno, ProviderOrderScan $scan, $url, $whatsapp_message_id)
    {
        if (is_null($scan->ai_conversation_id)) {

            return;
        }

        $conversacion = AiConversation::where('id', (int) $scan->ai_conversation_id)
                                        ->where('user_id', (int) $dueno->id)
                                        ->first();

        if (is_null($conversacion)) {

            return;
        }

        $contenido = self::texto_del_aviso($scan);

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
     * El id de la fila del escaneo a partir del `id` del contrato, o null si no es de este tipo.
     *
     * @param  mixed  $id
     * @return int|null
     */
    protected static function id_de_escaneo($id)
    {
        $id = trim((string) $id);

        $prefijo = self::TIPO_ESCANEO_FACTURA . ':';

        if (strpos($id, $prefijo) !== 0) {

            return null;
        }

        $numero = substr($id, strlen($prefijo));

        if ($numero === '' || !ctype_digit($numero) || (int) $numero <= 0) {

            return null;
        }

        return (int) $numero;
    }

    /**
     * Una URL http/https con host, sin barra final, o null. Sin query ni fragmento: a la base se le
     * pega la ruta con su propia query.
     *
     * @param  mixed  $url
     * @return string|null
     */
    public static function url_valida($url)
    {
        if (!is_string($url)) {

            return null;
        }

        $url = trim($url);

        if ($url === '' || mb_strlen($url) > 255) {

            return null;
        }

        $partes = parse_url($url);

        if (!is_array($partes) || !isset($partes['scheme'], $partes['host']) || $partes['host'] === '') {

            return null;
        }

        if (!in_array(strtolower($partes['scheme']), ['http', 'https'], true)) {

            return null;
        }

        if (isset($partes['query']) || isset($partes['fragment']) || isset($partes['user'])) {

            return null;
        }

        return rtrim($url, '/');
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
