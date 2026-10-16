<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\OfertaComunicacionHelper;
use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Http\Controllers\Helpers\WhatsappPhoneHelper;
use App\Jobs\SendPromocionWhatsappJob;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Client;
use App\Models\Combo;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTemplate;
use App\Services\RecordatorioCobroSenderService;
use App\Services\WhatsappBotSendService;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * "ARMÁ UN COMBO Y MANDALES UNA PROMO A TODOS LOS CLIENTES CON EL LINK A LA TIENDA" (misión
 * asistente-envios-a-clientes, 16/10/2026). Pedido textual de Lucas: *"que arme un combo con x
 * artículos y que les envíe un mensaje promocional a todos los clientes promocionando ese artículo
 * con el link a la tienda"*. El combo lo arma `proponer_combo` (como siempre); esto manda la promo.
 *
 * 🔴 ES LO MÁS DELICADO QUE HACE EL ASISTENTE: un mensaje de marketing a muchos clientes reales desde
 * el número de WhatsApp Business del negocio. Cada mensaje que un cliente marca como spam le baja la
 * calificación al número, y un número mal calificado queda limitado o dado de baja — es el activo que
 * ComercioCity le gestiona al cliente. Por eso:
 *
 *   1. SIEMPRE ESPERA EL SÍ, EN LOS TRES MODOS (HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES), después
 *      de mostrar a quiénes, cuántos, a quiénes no y por qué, y el texto exacto.
 *   2. DOS TOPES (decisión de Lucas, 16/10/2026): TOPE_POR_DIA destinatarios por negocio en 24 horas
 *      móviles (contando las filas `out` con `source = 'promocion'` de todos sus chats) y UNA promoción
 *      por cliente cada DIAS_ENTRE_PROMOCIONES días. Los que no entran se listan con el motivo.
 *   3. SOLO EL DUEÑO O UN ADMINISTRADOR: no hay permiso de empleado para hablarle a toda la cartera.
 *   4. NUNCA UN LINK INVENTADO: el del artículo exige que esté publicado en la tienda (`online = 1`) y
 *      con su `slug` (`{tienda}/articulos/{slug}/{user_id}`, la ruta de tienda-spa); un combo no tiene
 *      página propia y se ve en la portada (`Combos.vue` del home) si `combos.online = 1`, así que su
 *      link es la portada y, si estaba despublicado, se publica al confirmar (la tarjeta lo dice). Sin
 *      tienda cargada (`users.online` vacío) no se manda una promo de un artículo o un combo: el link
 *      estaría roto.
 *
 * Por cliente: con la ventana de 24 h abierta sale como texto libre ("Hola {nombre}! {texto}" y el
 * link); cerrada, con la plantilla de marketing `cc_cli_promocion` [Nombre, Negocio, Detalle de la
 * promo, Fecha límite] si está aprobada, y si no, ese cliente se saltea con el motivo. Meta rechaza
 * las variables con saltos de línea, tabulaciones o 4 espacios seguidos: se limpian.
 *
 * El envío es UN JOB POR CLIENTE (SendPromocionWhatsappJob, `$tries = 1`, molde de
 * SendRecordatorioCobroJob), que revalida los dos topes, la ventana y la plantilla justo antes de
 * mandar: entre la tarjeta y el job puede pasar un rato, y otra promo pudo salir en el medio.
 *
 * PHP 7.4: sin match, sin str_contains, sin nullsafe, sin argumentos nombrados.
 */
class PropuestaPromocionWhatsappIaHelper
{
    /** La extensión del módulo de WhatsApp. */
    const EXTENSION = 'whatsapp';

    /** El `source` de la fila del mensaje: los dos topes se cuentan con él. */
    const SOURCE = 'promocion';

    /** La plantilla estándar de marketing (WhatsappTemplateStandardHelper). */
    const TEMPLATE_META_NAME = 'cc_cli_promocion';

    /** Tope de destinatarios por negocio en 24 horas móviles (decisión de Lucas, 16/10/2026). */
    const TOPE_POR_DIA = 250;

    /** Un cliente no recibe más de una promoción cada tantos días (decisión de Lucas, 16/10/2026). */
    const DIAS_ENTRE_PROMOCIONES = 7;

    /** Largo máximo del texto de la promo: una variable de plantilla no puede ser un testamento. */
    const LARGO_MAXIMO_DEL_TEXTO = 700;

    /** Largo máximo de la vigencia (M4 del chequeo): es la "Fecha límite" de la plantilla, no un párrafo. */
    const LARGO_MAXIMO_DE_LA_VIGENCIA = 40;

    /**
     * Largo máximo del cuerpo de la plantilla ya armado (M4 del chequeo). Meta acepta hasta 1024
     * caracteres en el cuerpo de un mensaje de plantilla: con un texto largo, un link largo y un nombre
     * largo se puede pasar, y Meta lo rechazaría en el envío (envio_no_confirmado, cliente por cliente).
     * Se mide en la propuesta, con margen, y se le pide al dueño acortar ANTES del sí.
     */
    const LARGO_MAXIMO_DE_LA_PLANTILLA = 1000;

    /** Cuánto se espera el candado del negocio en el job antes de rendirse (segundos). */
    const ESPERA_DEL_CANDADO = 15;

    /** Cuánto dura el candado del negocio si el proceso muere adentro (segundos). */
    const VIDA_DEL_CANDADO = 30;

    /**
     * La "Fecha límite" cuando el dueño no dijo hasta cuándo vale.
     *
     * ⚠️ El plan decía "por tiempo limitado", pero la plantilla aprobada en Meta dice "Válida hasta el
     * {{4}}", y "Válida hasta el por tiempo limitado" no se puede mandar. "fin de stock" se lee bien
     * ahí ("Válida hasta el fin de stock") y no inventa una fecha. Si la promo tiene fecha, el modelo
     * la pasa en `vigencia`.
     */
    const VIGENCIA_POR_DEFECTO = 'fin de stock';

    /** Motivos de salteo propios. */
    const MOTIVO_SIN_TELEFONO = 'sin_telefono';

    const MOTIVO_RECIBIO_HACE_POCO = 'recibio_promocion';

    const MOTIVO_TOPE = 'tope_diario';

    const MOTIVO_PLANTILLA = 'plantilla_no_aprobada';

    const MOTIVO_SIN_CONFIGURACION = 'sin_configuracion';

    /**
     * Herramienta proponer_promocion_por_whatsapp.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  texto*, article_id, articulo, combo_id, combo, vigencia, client_ids, reemplaza_a
     * @return array
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input): array
    {
        $corte = self::verificar_acceso($contexto);

        if (!is_null($corte)) {

            return RespuestaDeCargaIa::error($corte);
        }

        $texto = self::texto_limpio(EntradaDeCargaIa::texto($input, 'texto'));

        if ($texto === '') {

            return RespuestaDeCargaIa::faltan(['el texto de la promoción (qué se ofrece, a qué precio)']);
        }

        if (mb_strlen($texto) > self::LARGO_MAXIMO_DEL_TEXTO) {

            return RespuestaDeCargaIa::error('El texto de la promo es muy largo (' . mb_strlen($texto) . ' caracteres; el máximo es ' . self::LARGO_MAXIMO_DEL_TEXTO . '). Acortalo.');
        }

        $vigencia_dicha = self::vigencia_limpia(EntradaDeCargaIa::texto($input, 'vigencia'));

        if (mb_strlen($vigencia_dicha) > self::LARGO_MAXIMO_DE_LA_VIGENCIA) {

            return RespuestaDeCargaIa::error('La vigencia es muy larga: tiene que completar "Válida hasta el ..." en pocas palabras (máximo ' . self::LARGO_MAXIMO_DE_LA_VIGENCIA . ' caracteres), por ejemplo "domingo 20/10".');
        }

        /*
         * 🔴 S13 EN DEMO3 (sin el módulo de Combos): el modelo armó una promo que nombraba "el combo"
         * sin que existiera ningún combo. Si el texto habla de un combo y no vino cuál, no se arma: una
         * promo que nombra un combo que no existe manda al cliente a buscar algo que no está en la tienda.
         */
        if (self::habla_de_un_combo($texto) && EntradaDeCargaIa::vacio(EntradaDeCargaIa::valor($input, 'combo')) && EntradaDeCargaIa::vacio(EntradaDeCargaIa::valor($input, 'combo_id'))) {

            return RespuestaDeCargaIa::error(
                'El texto de la promo habla de un combo pero no me dijiste cuál (combo o combo_id). Si el combo existe, '
                . 'pasámelo; si el negocio no tiene Combos o no lo armaste, decíselo al dueño y ofrecé la promo del o de '
                . 'los artículos con su link, sin nombrar un combo que no existe.'
            );
        }

        $destino = self::resolver_destino($contexto, $input);

        if (RespuestaDeCargaIa::es_negativa($destino)) {

            return $destino;
        }

        $config = WhatsappBotConfig::getForUser($contexto->owner_id);

        if (is_null($config)) {

            return RespuestaDeCargaIa::error('El negocio no tiene WhatsApp conectado (no hay una configuración activa): no se puede mandar la promo.');
        }

        $client_ids = self::ids(EntradaDeCargaIa::valor($input, 'client_ids'));

        $clientes = self::clientes($contexto->owner_id, $client_ids);

        if (!count($clientes)) {

            return RespuestaDeCargaIa::error(count($client_ids)
                ? 'No encontré a esos clientes en este negocio.'
                : 'No hay ningún cliente con teléfono cargado: no hay a quién mandarle la promo.');
        }

        $evaluacion = self::evaluar($contexto->owner_id, $clientes);

        $grupos = DestinatariosIaHelper::agrupar_por_motivo($evaluacion['salteados']);

        if (!count($evaluacion['reciben'])) {

            return RespuestaDeCargaIa::error('No hay a quién mandarle la promo: ' . self::motivos_en_una_linea($grupos), ['no_les_llega' => $grupos]);
        }

        $negocio = self::nombre_del_negocio($contexto->owner);
        $vigencia = $vigencia_dicha === '' ? self::VIGENCIA_POR_DEFECTO : $vigencia_dicha;

        $nombres = [];
        $ids_que_reciben = [];
        $primero_por_canal = [];

        foreach ($evaluacion['reciben'] as $fila) {
            $nombres[] = (string) $fila['client']->name;
            $ids_que_reciben[] = (int) $fila['client']->id;

            if (!isset($primero_por_canal[$fila['canal']])) {
                $primero_por_canal[$fila['canal']] = $fila['client'];
            }
        }

        /*
         * 🔴 EL TEXTO EXACTO, armado por LAS MISMAS funciones que usa el envío (armar_mensaje() y
         * variables_de_la_plantilla()): lo que el dueño lee es lo que sale. Si hay de los dos caminos,
         * se muestran los dos (el del primero de cada uno).
         */
        $textos = [];

        if (isset($primero_por_canal['texto'])) {
            $textos[] = self::armar_mensaje(self::nombre_del_cliente($primero_por_canal['texto']), $texto, $vigencia_dicha, $destino['link']);
        }

        if (isset($primero_por_canal['plantilla'])) {
            $textos[] = self::plantilla_aprobada($contexto->owner_id)->render_body(
                self::variables_de_la_plantilla(self::nombre_del_cliente($primero_por_canal['plantilla']), $negocio, $texto, $destino['link'], $vigencia)
            );
        }

        $texto_que_sale = implode("\n\n— y a los que no escribieron en las últimas 24 horas, como plantilla de WhatsApp:\n", $textos);

        // M4: el cuerpo de la plantilla tiene que entrar en lo que acepta Meta (ver LARGO_MAXIMO_DE_LA_PLANTILLA).
        if (isset($primero_por_canal['plantilla'])) {

            $largo = mb_strlen(end($textos));

            if ($largo > self::LARGO_MAXIMO_DE_LA_PLANTILLA) {

                return RespuestaDeCargaIa::error(
                    'Con ese texto la plantilla de WhatsApp queda de ' . $largo . ' caracteres y Meta no la acepta (el máximo '
                    . 'es ' . self::LARGO_MAXIMO_DE_LA_PLANTILLA . '). Pedile al dueño un texto más corto.'
                );
            }
        }

        $renglones = [
            ['etiqueta' => 'Le llega a ' . count($nombres), 'valor' => DestinatariosIaHelper::lista_de_nombres($nombres)],
            ['etiqueta' => 'Cómo sale', 'valor' => self::descripcion_de_canales($evaluacion['por_canal'])],
            ['etiqueta' => 'Link', 'valor' => is_null($destino['link']) ? 'Sin link (no se nombró un artículo ni un combo, y el negocio no tiene tienda online cargada)' : $destino['link']],
        ];

        foreach (DestinatariosIaHelper::renglones_de_salteados($grupos) as $renglon) {
            $renglones[] = $renglon;
        }

        $renglones[] = ['etiqueta' => 'Texto', 'valor' => $texto_que_sale];

        $avisos = [];

        if (!empty($destino['publicar_combo'])) {
            $avisos[] = 'El combo "' . $destino['nombre'] . '" no está publicado en la tienda: se publica al mandar la promo, para que el link lo muestre.';
        }

        $avisos[] = 'A cada cliente le llega con su nombre. Un mismo cliente no recibe más de una promoción cada '
            . self::DIAS_ENTRE_PROMOCIONES . ' días, y el negocio no manda más de ' . self::TOPE_POR_DIA . ' por día.';

        sort($ids_que_reciben);

        // Lo que el sistema le agrega al mensaje por WhatsApp antes del sí (DestinatariosIaHelper::bloque()).
        $bloque = DestinatariosIaHelper::bloque(
            'Promoción por WhatsApp',
            [
                DestinatariosIaHelper::linea_de_destinatarios($nombres),
                'Cómo sale: ' . self::descripcion_de_canales($evaluacion['por_canal']),
                'Link: ' . (is_null($destino['link']) ? 'sin link (el negocio no tiene tienda online cargada)' : $destino['link']),
            ],
            $grupos,
            'El texto que sale',
            $texto_que_sale,
            implode(' ', $avisos)
        );

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_PROMOCION_WHATSAPP,
            self::clave($texto, $ids_que_reciben),
            [
                // 🔴 Los clientes que el dueño VIO: al confirmar se manda a estos y a nadie más.
                'client_ids'        => $ids_que_reciben,
                'texto'             => $texto,
                'vigencia'          => $vigencia_dicha,
                'link'              => $destino['link'],
                'article_id'        => isset($destino['article_id']) ? $destino['article_id'] : null,
                'combo_id'          => isset($destino['combo_id']) ? $destino['combo_id'] : null,
                'bloque'            => $bloque,
            ],
            [
                'titulo'          => 'Promoción por WhatsApp a ' . count($nombres) . ' ' . (count($nombres) === 1 ? 'cliente' : 'clientes'),
                'renglones'       => $renglones,
                'aviso'           => implode(' ', $avisos),
                'texto_confirmar' => DestinatariosIaHelper::TEXTO_CONFIRMAR,
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $resumen = 'Promoción por WhatsApp a ' . count($nombres) . ' ' . (count($nombres) === 1 ? 'cliente' : 'clientes');

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen, [
            'para_mostrar' => DestinatariosIaHelper::para_mostrar($nombres, $grupos, $texto_que_sale, implode(' ', $avisos)),
            // Reemplaza a la nota de siempre: ver DestinatariosIaHelper::COMO_MOSTRARLO.
            'nota'         => DestinatariosIaHelper::COMO_MOSTRARLO,
        ]);
    }

    /**
     * Encola la promo de los clientes de la tarjeta, revalidados.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, encolados, no_salieron}
     *
     * @throws AccionIaException
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        $corte = self::verificar_acceso($contexto);

        if (!is_null($corte)) {

            throw new AccionIaException(422, $corte);
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $ids = self::ids(isset($datos['client_ids']) ? $datos['client_ids'] : []);

        $texto = isset($datos['texto']) ? (string) $datos['texto'] : '';

        if (!count($ids) || $texto === '') {

            throw new AccionIaException(422, 'Esta tarjeta no se puede confirmar. Pedímela de nuevo.');
        }

        // El destino, revalidado: el artículo pudo despublicarse y el combo borrarse en el medio.
        $destino = self::resolver_destino($contexto, [
            'article_id' => isset($datos['article_id']) ? $datos['article_id'] : null,
            'combo_id'   => isset($datos['combo_id']) ? $datos['combo_id'] : null,
        ]);

        if (RespuestaDeCargaIa::es_negativa($destino)) {

            throw new AccionIaException(422, (string) $destino['error'] . ' No mandé nada.');
        }

        if (WhatsappBotConfig::getForUser($contexto->owner_id) === null) {

            throw new AccionIaException(422, 'El negocio no tiene WhatsApp conectado (no hay una configuración activa): no se mandó nada.');
        }

        /*
         * 🔴 SOLO LOS QUE EL DUEÑO VIO, y otra vez por las guardas: los dos topes y la plantilla. El
         * que entre la tarjeta y el sí recibió otra promo, o el que ya no entra en el tope del día, se
         * informa y no se encola. El job vuelve a mirar todo justo antes de mandar.
         */
        // La propia tarjeta (que en dos etapas ya está "en_curso") no se cuenta como reserva de sí misma.
        $evaluacion = self::evaluar($contexto->owner_id, self::clientes($contexto->owner_id, $ids), (int) $accion->id);

        $no_salieron = [];

        foreach ($evaluacion['salteados'] as $salteado) {
            $no_salieron[] = ['cliente' => $salteado['nombre'], 'motivo' => $salteado['mensaje']];
        }

        if (!count($evaluacion['reciben'])) {

            throw new AccionIaException(422, 'No salió ninguna promo: ' . self::motivos_en_una_linea(DestinatariosIaHelper::agrupar_por_motivo($evaluacion['salteados'])));
        }

        // El combo despublicado se publica ANTES de encolar: si no, el link de la promo no lo muestra.
        if (!empty($destino['publicar_combo'])) {

            Combo::where('id', (int) $destino['combo_id'])->where('user_id', $contexto->owner_id)->update(['online' => 1]);
        }

        $persona_id = is_null($contexto->persona) ? null : (int) $contexto->persona->id;

        $vigencia = isset($datos['vigencia']) ? (string) $datos['vigencia'] : '';

        foreach ($evaluacion['reciben'] as $fila) {

            SendPromocionWhatsappJob::dispatch(
                $contexto->owner_id,
                (int) $fila['client']->id,
                $texto,
                $destino['link'],
                $vigencia,
                $persona_id,
                (int) $accion->id
            );
        }

        $encolados = count($evaluacion['reciben']);

        $texto_resultado = 'Promoción en camino a ' . $encolados . ' ' . ($encolados === 1 ? 'cliente' : 'clientes')
            . ': sale por WhatsApp en segundo plano, uno por cliente.'
            . (!empty($destino['publicar_combo']) ? ' El combo "' . $destino['nombre'] . '" quedó publicado en la tienda.' : '');

        return [
            'texto'       => $texto_resultado,
            'encolados'   => $encolados,
            'no_salieron' => $no_salieron,
        ];
    }

    /**
     * Manda la promo a UN cliente: lo que corre adentro de SendPromocionWhatsappJob.
     *
     * 🔴 ES LA AUTORIDAD: revalida TODO justo antes de mandar (configuración, teléfono, la promo de los
     * últimos 7 días, el tope del día, la ventana y la plantilla). Si no sale, devuelve el motivo y no
     * persiste nada; si `WhatsappBotSendService` devuelve null (Kapso no confirmó), tampoco: una fila
     * "enviada" que no salió contaría para los topes y mentiría en la conversación.
     *
     * @param  int  $owner_id
     * @param  int  $client_id
     * @param  string  $texto
     * @param  string|null  $link
     * @param  string  $vigencia  La que dijo el dueño ('' = no dijo).
     * @param  int|null  $sent_by_user_id
     * @param  int|null  $accion_id  La tarjeta que lo encoló: no cuenta como reserva de sí misma.
     * @return array{ok: bool, motivo: string|null, mensaje_id: int|null}
     */
    public static function enviar_a_cliente($owner_id, $client_id, $texto, $link, $vigencia, $sent_by_user_id = null, $accion_id = null)
    {
        $owner_id = (int) $owner_id;

        /*
         * 🔴 EL CANDADO DEL NEGOCIO (corrección 5 del chequeo). Revalidar los topes, mandar y persistir
         * tiene que ser UNA cosa: con dos workers (o dos promos confirmadas seguidas), dos jobs podían
         * leer "quedan 10 lugares" a la vez y mandar 20. Con el candado, el segundo espera a que el
         * primero haya persistido su fila, y la cuenta la ve.
         */
        try {

            return Cache::lock('promocion_whatsapp:' . $owner_id, self::VIDA_DEL_CANDADO)->block(
                self::ESPERA_DEL_CANDADO,
                function () use ($owner_id, $client_id, $texto, $link, $vigencia, $sent_by_user_id, $accion_id) {
                    return self::enviar_con_el_candado_tomado($owner_id, $client_id, $texto, $link, $vigencia, $sent_by_user_id, $accion_id);
                }
            );

        } catch (LockTimeoutException $e) {

            return ['ok' => false, 'motivo' => 'candado_ocupado', 'mensaje_id' => null];
        }
    }

    /**
     * Lo que hace enviar_a_cliente() adentro del candado: revalidar, mandar, persistir.
     *
     * @param  int  $owner_id
     * @param  int  $client_id
     * @param  string  $texto
     * @param  string|null  $link
     * @param  string  $vigencia
     * @param  int|null  $sent_by_user_id
     * @param  int|null  $accion_id
     * @return array{ok: bool, motivo: string|null, mensaje_id: int|null}
     */
    protected static function enviar_con_el_candado_tomado($owner_id, $client_id, $texto, $link, $vigencia, $sent_by_user_id, $accion_id)
    {
        $client = Client::where('id', (int) $client_id)->where('user_id', $owner_id)->first();

        if (is_null($client)) {
            return ['ok' => false, 'motivo' => 'cliente_no_encontrado', 'mensaje_id' => null];
        }

        $config = WhatsappBotConfig::getForUser($owner_id);

        if (is_null($config)) {
            return ['ok' => false, 'motivo' => self::MOTIVO_SIN_CONFIGURACION, 'mensaje_id' => null];
        }

        $evaluacion = self::evaluar($owner_id, [$client], $accion_id);

        if (!count($evaluacion['reciben'])) {
            return ['ok' => false, 'motivo' => $evaluacion['salteados'][0]['motivo'], 'mensaje_id' => null];
        }

        $canal = $evaluacion['reciben'][0]['canal'];

        // Crea el chat si no existe, EXACTAMENTE como el recordatorio de cobro.
        $chat = (new RecordatorioCobroSenderService())->resolve_chat($owner_id, $client, $config);

        $send_service = new WhatsappBotSendService();

        $nombre = self::nombre_del_cliente($client);

        if ($canal === 'texto') {

            $body = self::armar_mensaje($nombre, (string) $texto, (string) $vigencia, $link);

            $wa_message_id = $send_service->send_text($chat->phone, $body, $config);

            if (is_null($wa_message_id)) {
                return ['ok' => false, 'motivo' => 'envio_no_confirmado', 'mensaje_id' => null];
            }

            $guardado = WhatsappChatHelper::store_outbound_promocion_message($chat, $body, $wa_message_id, $sent_by_user_id);

            return ['ok' => true, 'motivo' => null, 'mensaje_id' => (int) $guardado->id];
        }

        $template = self::plantilla_aprobada($owner_id);

        $variables = self::variables_de_la_plantilla(
            $nombre,
            self::nombre_del_negocio(User::find($owner_id)),
            (string) $texto,
            $link,
            (string) $vigencia === '' ? self::VIGENCIA_POR_DEFECTO : (string) $vigencia
        );

        $wa_message_id = $send_service->send_template($chat->phone, $template, $variables, $config);

        if (is_null($wa_message_id)) {
            return ['ok' => false, 'motivo' => 'envio_no_confirmado', 'mensaje_id' => null];
        }

        $guardado = WhatsappChatHelper::store_outbound_promocion_message($chat, $template->render_body($variables), $wa_message_id, $sent_by_user_id, $template->meta_template_name);

        return ['ok' => true, 'motivo' => null, 'mensaje_id' => (int) $guardado->id];
    }

    /**
     * El mensaje de texto libre (ventana abierta): "Hola {nombre}! {texto}", la vigencia si el dueño
     * la dijo, y el link.
     *
     * @param  string  $nombre
     * @param  string  $texto
     * @param  string  $vigencia  '' = no la dijo (en el texto libre no se inventa una).
     * @param  string|null  $link
     * @return string
     */
    public static function armar_mensaje($nombre, $texto, $vigencia, $link)
    {
        $mensaje = 'Hola ' . $nombre . '! ' . $texto;

        if (trim((string) $vigencia) !== '') {
            $mensaje .= "\nVálida hasta el " . trim((string) $vigencia) . '.';
        }

        if (!is_null($link) && $link !== '') {
            $mensaje .= "\n" . $link;
        }

        return $mensaje;
    }

    /**
     * Las variables de `cc_cli_promocion` [Nombre, Negocio, Detalle de la promo, Fecha límite], con
     * el detalle = texto + link.
     *
     * 🔴 Meta rechaza un parámetro con saltos de línea, tabulaciones o cuatro espacios seguidos: el
     * envío volvería null (envio_no_confirmado). Por eso cada variable pasa por para_meta().
     *
     * @param  string  $nombre
     * @param  string  $negocio
     * @param  string  $texto
     * @param  string|null  $link
     * @param  string  $vigencia
     * @return array<int, string>
     */
    public static function variables_de_la_plantilla($nombre, $negocio, $texto, $link, $vigencia)
    {
        $detalle = rtrim(self::para_meta($texto), ' .');

        if (!is_null($link) && $link !== '') {
            $detalle .= '. Miralo en ' . $link;
        }

        return [self::para_meta($nombre), self::para_meta($negocio), $detalle, self::para_meta($vigencia)];
    }

    /**
     * Identidad de la promo para el reemplazo: el mismo texto a los mismos clientes.
     *
     * @param  string  $texto
     * @param  array<int, int>  $ids
     * @return string
     */
    public static function clave($texto, array $ids): string
    {
        return 'promocion_whatsapp:' . md5((string) $texto . '|' . implode(',', $ids));
    }

    /**
     * Cuántas promociones salieron de este negocio en las últimas 24 horas (para el tope del día).
     *
     * @param  int  $owner_id
     * @return int
     */
    public static function enviadas_en_24_horas($owner_id)
    {
        return WhatsappChatMessage::where('direction', 'out')
            ->where('source', self::SOURCE)
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', (int) $owner_id)->select('id'))
            ->count();
    }

    /**
     * Los clientes reservados por tarjetas de promoción del negocio ya CONFIRMADAS o EN CURSO: los de las
     * últimas 24 horas (para el tope del día) y los de los últimos DIAS_ENTRE_PROMOCIONES días (para el
     * freno por cliente). Salen de `datos.client_ids`, que son los que el dueño vio y confirmó.
     *
     * @param  int  $owner_id
     * @param  int|null  $excluir_accion_id
     * @return array{ultimas_24_horas: array<int, int>, ultimos_7_dias: array<int, int>}
     */
    public static function reservas($owner_id, $excluir_accion_id = null)
    {
        $query = AiMessageAction::where('user_id', (int) $owner_id)
            ->where('tipo', AiMessageAction::TIPO_PROMOCION_WHATSAPP)
            ->whereIn('estado', [AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::ESTADO_EN_CURSO])
            ->where('updated_at', '>=', now()->subDays(self::DIAS_ENTRE_PROMOCIONES));

        if (!is_null($excluir_accion_id)) {
            $query->where('id', '!=', (int) $excluir_accion_id);
        }

        $hace_un_dia = now()->subDay();

        $dia = [];
        $semana = [];

        foreach ($query->get() as $tarjeta) {

            $datos = is_array($tarjeta->datos) ? $tarjeta->datos : [];

            // Cuándo se confirmó; una en curso todavía no tiene resuelta_at.
            $cuando = !is_null($tarjeta->resuelta_at) ? $tarjeta->resuelta_at : $tarjeta->updated_at;

            foreach (self::ids(isset($datos['client_ids']) ? $datos['client_ids'] : []) as $id) {

                $semana[$id] = $id;

                if (!is_null($cuando) && $cuando->gte($hace_un_dia)) {
                    $dia[$id] = $id;
                }
            }
        }

        return ['ultimas_24_horas' => array_values($dia), 'ultimos_7_dias' => array_values($semana)];
    }

    /**
     * Cuántos lugares del tope del día están ocupados: las promociones que ya salieron en 24 horas
     * más los clientes reservados que todavía no recibieron la suya (para no contar dos veces al que
     * ya la recibió y además está en la tarjeta que la mandó).
     *
     * @param  int  $owner_id
     * @param  array<int, int>  $reservados
     * @return int
     */
    protected static function ocupados_en_24_horas($owner_id, array $reservados)
    {
        $enviadas = self::enviadas_en_24_horas($owner_id);

        if (!count($reservados)) {
            return $enviadas;
        }

        $ya_recibieron = WhatsappChat::where('user_id', (int) $owner_id)
            ->whereIn('client_id', $reservados)
            ->whereIn('id', WhatsappChatMessage::where('direction', 'out')
                ->where('source', self::SOURCE)
                ->where('created_at', '>=', now()->subDay())
                ->select('whatsapp_chat_id'))
            ->pluck('client_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();

        return $enviadas + count(array_diff($reservados, $ya_recibieron));
    }

    /**
     * true si el texto de la promo nombra un combo ("combo", "combos").
     *
     * @param  string  $texto
     * @return bool
     */
    protected static function habla_de_un_combo($texto)
    {
        return (bool) preg_match('/\bcombos?\b/u', mb_strtolower((string) $texto));
    }

    /**
     * La última promoción que recibió ese chat en los últimos DIAS_ENTRE_PROMOCIONES días, o null.
     *
     * @param  WhatsappChat  $chat
     * @return \Carbon\Carbon|null
     */
    public static function ultima_promocion_reciente(WhatsappChat $chat)
    {
        $ultima = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')
            ->where('source', self::SOURCE)
            ->where('created_at', '>=', now()->subDays(self::DIAS_ENTRE_PROMOCIONES))
            ->orderBy('created_at', 'DESC')
            ->value('created_at');

        return is_null($ultima) ? null : Carbon::parse($ultima);
    }

    /**
     * Quién puede: el comercio con el módulo de WhatsApp, y el dueño o un administrador.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @return string|null
     */
    protected static function verificar_acceso(ContextoDeCargaIa $contexto)
    {
        if (!PermisosIaHelper::tiene_extencion($contexto->owner, self::EXTENSION)) {

            return PermisosIaHelper::mensaje_sin_extencion('WhatsApp') . ' Sin él no se pueden mandar promociones.';
        }

        if (!PermisosIaHelper::es_admin($contexto->persona)) {

            return 'Las promociones a los clientes las manda el dueño del negocio o un administrador.';
        }

        return null;
    }

    /**
     * Qué se promociona y con qué link: un artículo, un combo, o nada (la portada de la tienda si hay).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $input
     * @return array  {link, article_id?, combo_id?, nombre?, publicar_combo?} o la respuesta negativa.
     */
    protected static function resolver_destino(ContextoDeCargaIa $contexto, array $input)
    {
        $tienda = OfertaComunicacionHelper::url_de_la_tienda($contexto->owner);

        $article_id = EntradaDeCargaIa::valor($input, 'article_id');
        $articulo = EntradaDeCargaIa::texto($input, 'articulo');
        $combo_id = EntradaDeCargaIa::valor($input, 'combo_id');
        $combo = EntradaDeCargaIa::texto($input, 'combo');

        $quiere_articulo = (is_numeric($article_id) && (int) $article_id > 0) || $articulo !== '';
        $quiere_combo = (is_numeric($combo_id) && (int) $combo_id > 0) || $combo !== '';

        if ($quiere_articulo && $quiere_combo) {

            return RespuestaDeCargaIa::faltan(['si la promo es del artículo o del combo (una sola cosa por promo)']);
        }

        if (!$quiere_articulo && !$quiere_combo) {

            // Una promo general ("20% en todo el local"): el link es la portada de la tienda, si hay.
            return ['link' => $tienda];
        }

        if (is_null($tienda)) {

            return RespuestaDeCargaIa::error('El negocio no tiene tienda online cargada, así que no hay link al que mandar a los clientes. No mando una promo con un link roto: si querés, la mando sin artículo ni combo, sin link.');
        }

        if ($quiere_articulo) {

            $modelo = self::resolver_articulo($contexto->owner_id, $article_id, $articulo);

            if (RespuestaDeCargaIa::es_negativa($modelo)) {
                return $modelo;
            }

            if (!(int) $modelo->online || trim((string) $modelo->slug) === '') {

                return RespuestaDeCargaIa::error('El artículo "' . $modelo->name . '" no está publicado en la tienda, así que su link no le abriría a nadie. Publicalo en la tienda primero, o mandá la promo sin el artículo.');
            }

            return [
                'link'       => rtrim($tienda, '/') . '/articulos/' . rawurlencode((string) $modelo->slug) . '/' . (int) $contexto->owner_id,
                'article_id' => (int) $modelo->id,
                'nombre'     => (string) $modelo->name,
            ];
        }

        $modelo = self::resolver_combo($contexto->owner_id, $combo_id, $combo);

        if (RespuestaDeCargaIa::es_negativa($modelo)) {
            return $modelo;
        }

        $publicar = !(int) $modelo->online;

        // Publicar un combo con precio cero es regalarlo (la guarda F3 de ComboAltaHelper): no se publica.
        if ($publicar && (is_null($modelo->price) || (float) $modelo->price <= 0)) {

            return RespuestaDeCargaIa::error('El combo "' . $modelo->name . '" no tiene precio cargado, así que no lo publico en la tienda (sería regalarlo). Cargale el precio primero.');
        }

        return [
            // Un combo no tiene página propia: se ve en la portada de la tienda.
            'link'           => $tienda,
            'combo_id'       => (int) $modelo->id,
            'nombre'         => (string) $modelo->name,
            'publicar_combo' => $publicar,
        ];
    }

    /**
     * El artículo, dentro del dueño: por id, o por su nombre o código si es uno solo.
     *
     * @param  int  $owner_id
     * @param  mixed  $id
     * @param  string  $texto
     * @return mixed  Article o la respuesta negativa.
     */
    protected static function resolver_articulo($owner_id, $id, $texto)
    {
        if (is_numeric($id) && (int) $id > 0) {

            $articulo = Article::where('user_id', (int) $owner_id)->where('id', (int) $id)->first();

            return is_null($articulo) ? RespuestaDeCargaIa::error('No encontré ese artículo en este negocio.') : $articulo;
        }

        $exacto = Article::where('user_id', (int) $owner_id)
            ->where(function ($q) use ($texto) {
                $q->where('name', $texto)->orWhere('bar_code', $texto)->orWhere('provider_code', $texto);
            })
            ->limit(2)
            ->get();

        if (count($exacto) === 1) {
            return $exacto[0];
        }

        $parecidos = Article::where('user_id', (int) $owner_id)->where('name', 'like', '%' . $texto . '%')->orderBy('name')->limit(6)->get(['id', 'name', 'online']);

        if (count($parecidos) === 1) {
            return Article::find($parecidos[0]->id);
        }

        if (!count($parecidos)) {
            return RespuestaDeCargaIa::error('No encontré ningún artículo "' . $texto . '" en este negocio.');
        }

        $opciones = [];

        foreach ($parecidos as $parecido) {
            $opciones[] = ['article_id' => (int) $parecido->id, 'nombre' => (string) $parecido->name];
        }

        return RespuestaDeCargaIa::faltan(['cuál de estos artículos (mandá su article_id)'], ['articulos' => $opciones]);
    }

    /**
     * El combo, dentro del dueño: por id, o por su nombre (el que devolvió proponer_combo).
     *
     * @param  int  $owner_id
     * @param  mixed  $id
     * @param  string  $texto
     * @return mixed  Combo o la respuesta negativa.
     */
    protected static function resolver_combo($owner_id, $id, $texto)
    {
        if (is_numeric($id) && (int) $id > 0) {

            $combo = Combo::where('user_id', (int) $owner_id)->where('id', (int) $id)->first();

            return is_null($combo) ? RespuestaDeCargaIa::error('No encontré ese combo en este negocio.') : $combo;
        }

        $exacto = Combo::where('user_id', (int) $owner_id)->where('name', $texto)->orderBy('id', 'DESC')->first();

        if (!is_null($exacto)) {
            return $exacto;
        }

        $parecidos = Combo::where('user_id', (int) $owner_id)->where('name', 'like', '%' . $texto . '%')->orderBy('id', 'DESC')->limit(6)->get();

        if (count($parecidos) === 1) {
            return $parecidos[0];
        }

        if (!count($parecidos)) {
            return RespuestaDeCargaIa::error('No encontré ningún combo "' . $texto . '" en este negocio. Si lo acabás de pedir, primero tiene que quedar creado.');
        }

        $opciones = [];

        foreach ($parecidos as $parecido) {
            $opciones[] = ['combo_id' => (int) $parecido->id, 'nombre' => (string) $parecido->name];
        }

        return RespuestaDeCargaIa::faltan(['cuál de estos combos (mandá su combo_id)'], ['combos' => $opciones]);
    }

    /**
     * Los clientes del dueño a los que se les puede proponer: los nombrados, o todos los que tienen
     * teléfono (en orden alfabético, que es como el dueño los busca en la lista).
     *
     * @param  int  $owner_id
     * @param  array<int, int>  $ids
     * @return array<int, Client>
     */
    protected static function clientes($owner_id, array $ids)
    {
        $query = Client::where('user_id', (int) $owner_id);

        if (count($ids)) {

            $query->whereIn('id', $ids);

        } else {

            $query->whereNotNull('phone')->where('phone', '!=', '');
        }

        return $query->orderBy('name')->orderBy('id')->get()->all();
    }

    /**
     * Quién recibe y por qué canal, y quién no y por qué, con los DOS TOPES. Lo usan la propuesta, la
     * ejecución y el job: una sola regla.
     *
     * Orden de las guardas por cliente: teléfono → tope del día → promo de los últimos 7 días →
     * ventana abierta (texto) o plantilla aprobada. Un lugar del día se gasta SOLO con un cliente que
     * pasa todas las guardas: el que no puede recibir por la regla de 7 días (o por la plantilla) no
     * lo consume. Y una vez agotado el cupo, el resto se saltea por el tope sin mirar su chat: con
     * miles de clientes, no se hace una consulta por cada uno que igual no va a recibir.
     *
     * 🔴 LOS TOPES CUENTAN TAMBIÉN LO RESERVADO (corrección 5 del chequeo): los destinatarios de las
     * tarjetas de promoción ya confirmadas (o en curso) del mismo negocio, aunque sus jobs todavía no
     * hayan mandado nada. Sin eso, dos promos confirmadas seguidas veían el mismo cupo y la segunda le
     * volvía a escribir al cliente de la primera.
     *
     * @param  int  $owner_id
     * @param  array<int, Client>  $clientes
     * @param  int|null  $excluir_accion_id  La tarjeta que se está ejecutando (no es reserva de sí misma).
     * @return array{reciben: array, salteados: array, por_canal: array{texto: int, plantilla: int}}
     */
    protected static function evaluar($owner_id, array $clientes, $excluir_accion_id = null)
    {
        $config = WhatsappBotConfig::getForUser((int) $owner_id);

        $plantilla = self::plantilla_aprobada($owner_id);

        $reservas = self::reservas($owner_id, $excluir_accion_id);

        $cupo = self::TOPE_POR_DIA - self::ocupados_en_24_horas($owner_id, $reservas['ultimas_24_horas']);

        $reciben = [];
        $salteados = [];
        $por_canal = ['texto' => 0, 'plantilla' => 0];

        foreach ($clientes as $client) {

            $nombre = (string) $client->name;

            if (is_null($config)) {
                $salteados[] = ['nombre' => $nombre, 'motivo' => self::MOTIVO_SIN_CONFIGURACION, 'mensaje' => 'El negocio no tiene WhatsApp conectado.'];
                continue;
            }

            if (WhatsappPhoneHelper::normalize($client->phone) === '') {
                $salteados[] = ['nombre' => $nombre, 'motivo' => self::MOTIVO_SIN_TELEFONO, 'mensaje' => 'No tiene teléfono cargado.'];
                continue;
            }

            // Una vez agotado el cupo ya no hace falta mirar el chat de nadie: el resto no entra.
            if ($cupo <= 0) {
                $salteados[] = ['nombre' => $nombre, 'motivo' => self::MOTIVO_TOPE, 'mensaje' => 'Se llegó al tope de ' . self::TOPE_POR_DIA . ' promociones por día del negocio.'];
                continue;
            }

            $chat = RecordatorioCobroSenderService::buscar_chat((int) $owner_id, $client);

            $ultima = is_null($chat) ? null : self::ultima_promocion_reciente($chat);

            // Recibió una en los últimos 7 días, o está en una promo ya confirmada que todavía sale.
            if (!is_null($ultima) || in_array((int) $client->id, $reservas['ultimos_7_dias'], true)) {
                $salteados[] = [
                    'nombre'  => $nombre,
                    'motivo'  => self::MOTIVO_RECIBIO_HACE_POCO,
                    'mensaje' => 'Ya recibió (o está por recibir) una promoción en los últimos ' . self::DIAS_ENTRE_PROMOCIONES . ' días (una cada ' . self::DIAS_ENTRE_PROMOCIONES . ').',
                ];
                continue;
            }

            if (!is_null($chat) && $chat->is_within_service_window()) {
                $canal = 'texto';
            } elseif (!is_null($plantilla)) {
                $canal = 'plantilla';
            } else {
                $salteados[] = [
                    'nombre'  => $nombre,
                    'motivo'  => self::MOTIVO_PLANTILLA,
                    'mensaje' => 'No escribió en las últimas 24 horas y la plantilla de promoción todavía no está aprobada en Meta.',
                ];
                continue;
            }

            $reciben[] = ['client' => $client, 'canal' => $canal];
            $por_canal[$canal]++;
            $cupo--;
        }

        return ['reciben' => $reciben, 'salteados' => $salteados, 'por_canal' => $por_canal];
    }

    /**
     * La plantilla `cc_cli_promocion` del dueño, solo si está aprobada en Meta.
     *
     * @param  int  $owner_id
     * @return WhatsappTemplate|null
     */
    protected static function plantilla_aprobada($owner_id)
    {
        $template = WhatsappTemplate::where('user_id', (int) $owner_id)
            ->where('meta_template_name', self::TEMPLATE_META_NAME)
            ->first();

        if (is_null($template) || $template->status !== 'aprobada') {
            return null;
        }

        return $template;
    }

    /**
     * "3 como mensaje y 40 como plantilla de WhatsApp".
     *
     * @param  array{texto: int, plantilla: int}  $por_canal
     * @return string
     */
    protected static function descripcion_de_canales(array $por_canal)
    {
        $partes = [];

        if ($por_canal['texto'] > 0) {
            $partes[] = $por_canal['texto'] . ' como mensaje (escribieron en las últimas 24 horas)';
        }

        if ($por_canal['plantilla'] > 0) {
            $partes[] = $por_canal['plantilla'] . ' como plantilla de WhatsApp de promoción';
        }

        return implode(' y ', $partes);
    }

    /**
     * Los motivos agrupados en una línea.
     *
     * @param  array  $grupos
     * @return string
     */
    protected static function motivos_en_una_linea(array $grupos)
    {
        $partes = [];

        foreach ($grupos as $grupo) {
            $partes[] = rtrim($grupo['mensaje'], '.') . ' (' . $grupo['clientes'] . ')';
        }

        return implode('; ', $partes) . '.';
    }

    /**
     * El nombre del negocio que firma la plantilla: el comercial si está, si no el del usuario (la
     * misma regla que el recordatorio de cobro).
     *
     * @param  User|null  $owner
     * @return string
     */
    protected static function nombre_del_negocio($owner)
    {
        if (is_null($owner)) {
            return 'nuestro negocio';
        }

        $nombre = trim((string) ($owner->company_name ? $owner->company_name : $owner->name));

        return $nombre === '' ? 'nuestro negocio' : $nombre;
    }

    /**
     * El nombre del cliente para el saludo, con relleno.
     *
     * @param  Client  $client
     * @return string
     */
    protected static function nombre_del_cliente(Client $client)
    {
        $nombre = trim((string) $client->name);

        return $nombre === '' ? 'Cliente' : $nombre;
    }

    /**
     * El texto de la promo con los espacios ordenados (sin espacios al borde).
     *
     * @param  string  $texto
     * @return string
     */
    protected static function texto_limpio($texto)
    {
        return trim((string) $texto);
    }

    /**
     * La vigencia sin el "hasta el" del principio: la plantilla ya dice "Válida hasta el {{4}}".
     *
     * @param  string  $vigencia
     * @return string
     */
    protected static function vigencia_limpia($vigencia)
    {
        $vigencia = trim((string) $vigencia);

        foreach (['válida hasta el ', 'valida hasta el ', 'hasta el ', 'hasta '] as $prefijo) {

            if (mb_strtolower(mb_substr($vigencia, 0, mb_strlen($prefijo))) === $prefijo) {
                $vigencia = trim(mb_substr($vigencia, mb_strlen($prefijo)));
                break;
            }
        }

        return rtrim($vigencia, ' .');
    }

    /**
     * Un texto apto para variable de plantilla de Meta: sin saltos de línea ni tabulaciones, y sin
     * espacios repetidos (Meta rechaza cuatro seguidos; se colapsa cualquier racha).
     *
     * @param  string  $texto
     * @return string
     */
    protected static function para_meta($texto)
    {
        $texto = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string) $texto);

        return trim(preg_replace('/ {2,}/', ' ', $texto));
    }

    /**
     * Una lista de ids enteros positivos, sin repetidos.
     *
     * @param  mixed  $valor
     * @return array<int, int>
     */
    protected static function ids($valor)
    {
        if (!is_array($valor)) {
            return [];
        }

        $ids = [];

        foreach ($valor as $id) {
            if (is_numeric($id) && (int) $id > 0 && !in_array((int) $id, $ids, true)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
