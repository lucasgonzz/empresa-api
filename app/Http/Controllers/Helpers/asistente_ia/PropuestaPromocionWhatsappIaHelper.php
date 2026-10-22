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
 *   5. VARIOS ARTÍCULOS, CADA UNO CON SU LINK (N5 de la misión asistente-arreglos-segunda-prueba,
 *      19/10/2026): hasta MAXIMO_DE_ARTICULOS en `article_ids`, cada uno con las guardas de arriba. El
 *      texto libre lleva un renglón "<nombre>: <link>" por artículo; la plantilla, que tiene un solo
 *      lugar para el link, lleva la portada de la tienda (la tarjeta y el bloque lo dicen). Al confirmar
 *      se revalida CADA link. Y el texto lo REDACTA EL MODELO (sin `texto` es un error para él, no un
 *      "faltan" para el dueño), sin links adentro: los pone el sistema.
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
    const EXTENSION = 'agentes_ia';

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

    /** Cuánto dura el candado del negocio si el proceso muere adentro (segundos; I4: al menos 60). */
    const VIDA_DEL_CANDADO = 60;

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
     * Cuántos artículos puede llevar una promo (N5 de la misión asistente-arreglos-segunda-prueba,
     * 19/10/2026). Cada uno suma un renglón con su link al mensaje: con más de cinco el WhatsApp deja de
     * ser una promo y pasa a ser un catálogo, que es otra cosa.
     */
    const MAXIMO_DE_ARTICULOS = 5;

    /**
     * Lo que se le contesta AL MODELO cuando no mandó el texto (N5): que lo redacte él. Hasta el 19/10/2026
     * era un `faltan` y el modelo le pedía al dueño que le dictara la promo; el dueño la ve tal cual sale
     * en el bloque y la corrige antes del sí, así que no hace falta que la escriba él.
     */
    const MENSAJE_SIN_TEXTO = 'Falta el texto de la promo. Redactalo vos con los artículos y el precio de cada uno como lo '
        . 'devolvió consultar_stock_de_articulos (sin saludo y sin links: los pone el sistema) y volvé a llamar; el dueño '
        . 'lo ve en el bloque y lo corrige antes del sí. No le pidas que te lo dicte.';

    /** Lo que se le contesta al modelo cuando el texto trae un link escrito (N5). */
    const MENSAJE_TEXTO_CON_LINK = 'El texto de la promo no puede traer links: los arma el sistema (uno por artículo, o el de '
        . 'la tienda) con la dirección real, y nunca se inventan. Sacá el link del texto y volvé a llamar.';

    /**
     * Herramienta proponer_promocion_por_whatsapp.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  texto*, article_id, articulo, combo_id, combo, vigencia, client_ids, reemplaza_a,
     *                        article_ids (hasta MAXIMO_DE_ARTICULOS, N5)
     * @return array
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input): array
    {
        $corte = self::verificar_acceso($contexto);

        if (!is_null($corte)) {

            return RespuestaDeCargaIa::error($corte);
        }

        $texto = self::texto_limpio(EntradaDeCargaIa::texto($input, 'texto'));

        /*
         * 🔴 EL TEXTO LO REDACTA EL MODELO, NO SE LO PIDE AL DUEÑO (N5 de la misión
         * asistente-arreglos-segunda-prueba, 19/10/2026). Hasta acá, sin `texto` esto devolvía `faltan` y
         * el modelo le pedía al dueño que le dictara la promo palabra por palabra. Ahora es un error PARA
         * EL MODELO: que la redacte él con los artículos y sus precios; el dueño la ve tal cual sale en el
         * bloque y la corrige antes del sí.
         */
        if ($texto === '') {

            return RespuestaDeCargaIa::error(self::MENSAJE_SIN_TEXTO);
        }

        /*
         * 🔴 Y SIN LINKS ADENTRO: los pone el sistema (uno por artículo, o la portada), armados con el slug
         * real. Un link que el modelo escribió en el texto es un link inventado, y el cliente lo abre.
         */
        if (self::trae_un_link($texto)) {

            return RespuestaDeCargaIa::error(self::MENSAJE_TEXTO_CON_LINK);
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
        $vino_un_articulo = !EntradaDeCargaIa::vacio(EntradaDeCargaIa::valor($input, 'article_id'))
            || !EntradaDeCargaIa::vacio(EntradaDeCargaIa::valor($input, 'articulo'))
            || count(self::ids(EntradaDeCargaIa::valor($input, 'article_ids'))) > 0;

        // I5: con un artículo no aplica ("Combo desayuno" puede ser el nombre de un artículo).
        if (!$vino_un_articulo && self::habla_de_un_combo($texto) && EntradaDeCargaIa::vacio(EntradaDeCargaIa::valor($input, 'combo')) && EntradaDeCargaIa::vacio(EntradaDeCargaIa::valor($input, 'combo_id'))) {

            return RespuestaDeCargaIa::error(
                'El texto de la promo habla de un combo pero no me dijiste cuál (combo o combo_id). Si el combo existe, '
                . 'pasámelo; si el negocio no tiene Combos o no lo armaste, decíselo al dueño y ofrecé la promo del o de '
                . 'los artículos (en article_ids, hasta ' . self::MAXIMO_DE_ARTICULOS . ': cada uno sale con su link), sin nombrar un combo que no existe.'
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
        $caminos = [];
        $el_nombre_mas_largo = '';

        foreach ($evaluacion['reciben'] as $fila) {
            $nombres[] = (string) $fila['client']->name;
            $ids_que_reciben[] = (int) $fila['client']->id;
            $caminos[(int) $fila['client']->id] = $fila['canal'];

            $nombre = self::nombre_del_cliente($fila['client']);

            if (mb_strlen($nombre) > mb_strlen($el_nombre_mas_largo)) {
                $el_nombre_mas_largo = $nombre;
            }
        }

        $primero = self::nombre_del_cliente($evaluacion['reciben'][0]['client']);

        $plantilla = self::plantilla_aprobada($contexto->owner_id);

        /*
         * 🔴 EL TEXTO EXACTO, armado por LAS MISMAS funciones que usa el envío (armar_mensaje() y
         * variables_de_la_plantilla()): lo que el dueño lee es lo que sale.
         *
         * 🔴 Y LOS DOS CAMINOS, SIEMPRE QUE LA PLANTILLA ESTÉ APROBADA (I2 del segundo chequeo): entre el
         * bloque y el job la ventana de un cliente se puede abrir o cerrar, y el job manda por el camino
         * que corresponda en ese momento. Sin plantilla aprobada, los que no escribieron en 24 horas ya
         * quedaron salteados y sale solo el mensaje.
         */
        /*
         * Con VARIOS artículos (N5, 19/10/2026) el mensaje lleva un renglón "<nombre>: <link>" por artículo;
         * la plantilla tiene un solo lugar para el link y va con la portada de la tienda ($destino['link']).
         */
        $links = self::links_de_los_articulos($destino);

        $como_mensaje = self::armar_mensaje($primero, $texto, $vigencia_dicha, $destino['link'], $links);

        $como_plantilla = is_null($plantilla)
            ? null
            : $plantilla->render_body(self::variables_de_la_plantilla($primero, $negocio, $texto, $destino['link'], $vigencia));

        $texto_que_sale = is_null($como_plantilla)
            ? $como_mensaje
            : "Como mensaje (a los que escribieron en las últimas 24 horas):\n" . $como_mensaje
                . "\n\nComo plantilla de WhatsApp (al resto):\n" . $como_plantilla;

        /*
         * M4 y M2: el cuerpo de la plantilla tiene que entrar en lo que acepta Meta, y se mide con el
         * nombre MÁS LARGO de los destinatarios (cualquiera puede terminar saliendo por plantilla).
         */
        if (!is_null($plantilla)) {

            $largo = mb_strlen($plantilla->render_body(self::variables_de_la_plantilla($el_nombre_mas_largo, $negocio, $texto, $destino['link'], $vigencia)));

            if ($largo > self::LARGO_MAXIMO_DE_LA_PLANTILLA) {

                return RespuestaDeCargaIa::error(
                    'Con ese texto la plantilla de WhatsApp queda de ' . $largo . ' caracteres y Meta no la acepta (el máximo '
                    . 'es ' . self::LARGO_MAXIMO_DE_LA_PLANTILLA . '). Pedile al dueño un texto más corto.'
                );
            }
        }

        // M3: sin link hay un solo motivo posible (sin artículo ni combo, el link es la portada de la tienda).
        $link = is_null($destino['link']) ? 'sin link: el negocio no tiene tienda online cargada' : $destino['link'];

        $renglones = [
            ['etiqueta' => 'Le llega a ' . count($nombres), 'valor' => DestinatariosIaHelper::lista_de_nombres($nombres)],
            ['etiqueta' => 'Cómo sale', 'valor' => self::descripcion_de_canales($evaluacion['por_canal'])],
        ];

        // Las líneas de "a dónde lleva" del bloque: un link, o uno por artículo y la portada para la plantilla.
        $lineas_de_links = [];

        if (count($links)) {

            $renglones[] = ['etiqueta' => 'Links (uno por artículo)', 'valor' => self::links_en_una_linea($links)];
            $lineas_de_links[] = 'Links (uno por artículo): ' . self::links_en_una_linea($links);

            if (!is_null($plantilla)) {
                // La plantilla tiene UN solo lugar para el link: va la portada, y el dueño lo tiene que saber.
                $renglones[] = ['etiqueta' => 'Por plantilla', 'valor' => 'va el link de la tienda: ' . $link];
                $lineas_de_links[] = 'Por plantilla va el link de la tienda: ' . $link;
            }

        } else {

            $renglones[] = ['etiqueta' => 'Link', 'valor' => $link];
            $lineas_de_links[] = 'Link: ' . $link;
        }

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

        // Lo que el sistema le agrega al mensaje por WhatsApp antes del sí, con las listas que entren (M1).
        $bloque = DestinatariosIaHelper::bloque_que_entra(
            'Promoción por WhatsApp',
            $nombres,
            array_merge(
                ['Cómo sale: ' . self::descripcion_de_canales($evaluacion['por_canal'])],
                $lineas_de_links
            ),
            $evaluacion['salteados'],
            'El texto que sale',
            $texto_que_sale,
            implode(' ', $avisos)
        );

        if (is_null($bloque)) {

            return RespuestaDeCargaIa::error(DestinatariosIaHelper::MENSAJE_BLOQUE_LARGO);
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_PROMOCION_WHATSAPP,
            self::clave($texto, $ids_que_reciben, isset($destino['article_ids']) ? $destino['article_ids'] : []),
            [
                // 🔴 Los clientes que el dueño VIO: al confirmar se manda a estos y a nadie más.
                'client_ids'        => $ids_que_reciben,
                'texto'             => $texto,
                'vigencia'          => $vigencia_dicha,
                'link'              => $destino['link'],
                'article_id'        => isset($destino['article_id']) ? $destino['article_id'] : null,
                /*
                 * Con varios artículos (N5): cada uno con el link que el dueño VIO. La confirmación revalida
                 * CADA link (no solo `link`, que acá es la portada de la plantilla) y el job los recibe.
                 */
                'articulos'         => isset($destino['articulos']) ? $destino['articulos'] : [],
                'combo_id'          => isset($destino['combo_id']) ? $destino['combo_id'] : null,
                // Cómo salía cada uno al proponer (el bloque muestra los dos caminos: ver arriba).
                'caminos'           => $caminos,
                'bloque'            => $bloque,
                // Las partes del bloque, para la tarjeta y los tests: al modelo no le llegan (M4).
                'para_mostrar'      => DestinatariosIaHelper::para_mostrar($nombres, $grupos, $texto_que_sale, implode(' ', $avisos)),
            ],
            [
                'titulo'          => 'Promoción por WhatsApp a ' . count($nombres) . ' ' . (count($nombres) === 1 ? 'cliente' : 'clientes'),
                'renglones'       => $renglones,
                'aviso'           => implode(' ', $avisos),
                'texto_confirmar' => DestinatariosIaHelper::TEXTO_CONFIRMAR,
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        // B1: un solo envío esperando el sí por conversación.
        $reemplazadas = DestinatariosIaHelper::reemplazar_otros_envios($contexto, $creada['accion']->id);

        $resumen = 'Promoción por WhatsApp a ' . count($nombres) . ' ' . (count($nombres) === 1 ? 'cliente' : 'clientes');

        $no_reciben = count($evaluacion['salteados']);

        return DestinatariosIaHelper::respuesta_al_modelo(
            $creada,
            $resumen,
            count($nombres),
            $resumen . ($no_reciben ? '; a ' . $no_reciben . ' no les llega.' : '.'),
            $reemplazadas
        );
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

        // Los artículos que el dueño vio con su link (varios, N5); una tarjeta de antes no los tiene.
        $articulos_mostrados = isset($datos['articulos']) && is_array($datos['articulos']) ? array_values($datos['articulos']) : [];

        $ids_mostrados = [];

        foreach ($articulos_mostrados as $articulo) {
            if (isset($articulo['article_id'])) {
                $ids_mostrados[] = (int) $articulo['article_id'];
            }
        }

        // El destino, revalidado: el artículo pudo despublicarse y el combo borrarse en el medio.
        $destino = self::resolver_destino($contexto, [
            'article_id'  => isset($datos['article_id']) ? $datos['article_id'] : null,
            'article_ids' => $ids_mostrados,
            'combo_id'    => isset($datos['combo_id']) ? $datos['combo_id'] : null,
        ]);

        if (RespuestaDeCargaIa::es_negativa($destino)) {

            throw new AccionIaException(422, (string) $destino['error'] . ' No mandé nada.');
        }

        /*
         * 🔴 LO QUE SALE VA A DONDE SE MOSTRÓ (B1 de la revisión de merge): si el link recalculado no es
         * el que el dueño vio (cambió la dirección de la tienda o el slug del artículo), no se manda.
         */
        $link_mostrado = array_key_exists('link', $datos) ? $datos['link'] : null;

        if ($destino['link'] !== $link_mostrado) {

            throw new AccionIaException(422, 'No mandé nada: cambió el link de la promo desde que se la mostré al dueño '
                . '(ahora sería ' . (is_null($destino['link']) ? 'sin link' : $destino['link']) . '). Volvé a mostrársela y pedile el sí de nuevo.');
        }

        /*
         * 🔴 Y CON VARIOS ARTÍCULOS, CADA LINK (N5, 19/10/2026): `link` es la portada que lleva la plantilla;
         * el texto libre lleva uno por artículo, y cualquiera que haya cambiado (el slug, la tienda) sería
         * un link que el dueño no vio.
         */
        $links = self::links_de_los_articulos($destino);

        if (count($articulos_mostrados) || count($links)) {

            $antes = [];

            foreach ($articulos_mostrados as $articulo) {
                $antes[] = isset($articulo['link']) ? (string) $articulo['link'] : '';
            }

            $ahora = [];

            foreach ($links as $articulo) {
                $ahora[] = (string) $articulo['link'];
            }

            if ($antes !== $ahora) {

                throw new AccionIaException(422, 'No mandé nada: cambió el link de alguno de los artículos de la promo desde que se la mostré al '
                    . 'dueño (ahora serían ' . implode(', ', $ahora) . '). Volvé a mostrársela y pedile el sí de nuevo.');
            }

            /*
             * Y EL NOMBRE (revisión del 19/10/2026): el renglón que sale es "<nombre>: <link>", así que un
             * artículo renombrado entre la tarjeta y el sí saldría con un renglón que el dueño no leyó.
             */
            $nombres_antes = [];

            foreach ($articulos_mostrados as $articulo) {
                $nombres_antes[] = isset($articulo['nombre']) ? (string) $articulo['nombre'] : '';
            }

            $nombres_ahora = [];

            foreach ($links as $articulo) {
                $nombres_ahora[] = (string) $articulo['nombre'];
            }

            if ($nombres_antes !== $nombres_ahora) {

                throw new AccionIaException(422, 'No mandé nada: cambió el nombre de alguno de los artículos de la promo desde que se la mostré al '
                    . 'dueño (ahora serían ' . implode(', ', $nombres_ahora) . '). Volvé a mostrársela y pedile el sí de nuevo.');
            }
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

        /*
         * 🔴 I3 del segundo chequeo: la reserva son los que DE VERDAD se encolan, no todos los que vio el
         * dueño. Se guarda ANTES de despachar (con la cola sync el job corre adentro del dispatch, y la
         * tarjeta está "en_curso" hasta que esto termina): un salteado no queda reservado.
         */
        $encolados = [];

        foreach ($evaluacion['reciben'] as $fila) {
            $encolados[] = (int) $fila['client']->id;
        }

        $datos['client_ids_encolados'] = $encolados;
        $accion->datos = $datos;
        $accion->save();

        // Para ver, después de despachar, qué falló ADENTRO de este turno (cola sync).
        $desde = Carbon::now()->subSecond();

        foreach ($evaluacion['reciben'] as $fila) {

            SendPromocionWhatsappJob::dispatch(
                $contexto->owner_id,
                (int) $fila['client']->id,
                $texto,
                $destino['link'],
                $vigencia,
                $persona_id,
                (int) $accion->id,
                // Con varios artículos, un renglón por artículo en el texto libre (N5).
                $links
            );
        }

        $encolados = count($evaluacion['reciben']);

        $texto_resultado = 'Promoción en camino a ' . $encolados . ' ' . ($encolados === 1 ? 'cliente' : 'clientes')
            . ': sale por WhatsApp en segundo plano, uno por cliente.'
            . (!empty($destino['publicar_combo']) ? ' El combo "' . $destino['nombre'] . '" quedó publicado en la tienda.' : '');

        $texto_resultado .= self::lo_que_ya_fallo_en_el_turno((int) $accion->id, $desde, $no_salieron);

        return [
            'texto'       => $texto_resultado,
            'encolados'   => $encolados,
            'no_salieron' => $no_salieron,
            /*
             * Para el modelo (`detalle.aviso`, N3 de la misión asistente-arreglos-segunda-prueba,
             * 19/10/2026): sale en segundo plano, así que no se da por recibida; y el "te aviso" se
             * promete solo si el aviso va a salir.
             */
            'aviso'       => 'No digas que les llegó: sale en segundo plano.'
                . (EnviosFallidosDelAsistenteHelper::avisa_por_whatsapp($accion) ? ' Si alguna no sale, le aviso por acá.' : ''),
        ];
    }

    /**
     * Lo que ya falló adentro del turno de la confirmación (cola sync: los jobs corren adentro del
     * dispatch), dicho en el resultado y sellado para que el admin no lo vuelva a avisar. Con la cola de
     * verdad todavía no hay nada y devuelve ''. Mismo criterio que el recordatorio de cobro.
     *
     * @param  int  $accion_id
     * @param  \Carbon\Carbon  $desde
     * @param  array  $no_salieron  Se le suman los que fallaron (por referencia).
     * @return string
     */
    protected static function lo_que_ya_fallo_en_el_turno($accion_id, Carbon $desde, array &$no_salieron)
    {
        $fallidas = EnviosFallidosDelAsistenteHelper::de_la_tarjeta_desde($accion_id, null, $desde);

        if (!count($fallidas)) {
            return '';
        }

        EnviosFallidosDelAsistenteHelper::sellar_dichas_en_el_turno($fallidas);

        $cantidad = 0;
        $nombres = [];

        foreach ($fallidas as $fila) {

            $cantidad += (int) $fila->cantidad;

            foreach ($fila->lista_de_nombres() as $nombre) {
                $nombres[] = $nombre;
                $no_salieron[] = ['cliente' => (string) $nombre, 'motivo' => (string) $fila->motivo];
            }
        }

        return ' De esos, a ' . $cantidad . ' no ' . ($cantidad === 1 ? 'le' : 'les') . ' salió ('
            . trim((string) $fallidas->first()->motivo) . '): ' . DestinatariosIaHelper::lista_de_nombres($nombres) . '.';
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
     * @param  array  $links  Con varios artículos (N5, 19/10/2026): [{article_id, nombre, link}], un renglón
     *                        por artículo en el texto libre. Al final y con default: un job encolado
     *                        antes de este cambio llega sin él y sale como siempre, con `$link`.
     * @return array{ok: bool, motivo: string|null, mensaje_id: int|null}
     */
    public static function enviar_a_cliente($owner_id, $client_id, $texto, $link, $vigencia, $sent_by_user_id = null, $accion_id = null, $links = [])
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
                function () use ($owner_id, $client_id, $texto, $link, $vigencia, $sent_by_user_id, $accion_id, $links) {
                    return self::enviar_con_el_candado_tomado($owner_id, $client_id, $texto, $link, $vigencia, $sent_by_user_id, $accion_id, $links);
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
     * @param  array  $links  Ver enviar_a_cliente().
     * @return array{ok: bool, motivo: string|null, mensaje_id: int|null}
     */
    protected static function enviar_con_el_candado_tomado($owner_id, $client_id, $texto, $link, $vigencia, $sent_by_user_id, $accion_id, $links = [])
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

            $body = self::armar_mensaje($nombre, (string) $texto, (string) $vigencia, $link, is_array($links) ? $links : []);

            $wa_message_id = $send_service->send_text($chat->phone, $body, $config);

            if (is_null($wa_message_id)) {
                return ['ok' => false, 'motivo' => 'envio_no_confirmado', 'mensaje_id' => null];
            }

            $guardado = WhatsappChatHelper::store_outbound_promocion_message($chat, $body, $wa_message_id, $sent_by_user_id);

            self::anotar_la_tarjeta($guardado, $accion_id);

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

        self::anotar_la_tarjeta($guardado, $accion_id);

        return ['ok' => true, 'motivo' => null, 'mensaje_id' => (int) $guardado->id];
    }

    /**
     * Anota la tarjeta en la fila de la promo que salió (N3 de la misión asistente-arreglos-segunda-prueba,
     * 19/10/2026): si Meta la rechaza después (webhook `failed`), WhatsappChatHelper la encuentra y el
     * fallo vuelve al dueño. Por query y sin poder lanzar: la promo ya está en el teléfono del cliente, y
     * una excepción acá la dejaría sin contar para los topes.
     *
     * @param  \App\Models\WhatsappChatMessage  $guardado
     * @param  int|null  $accion_id
     * @return void
     */
    protected static function anotar_la_tarjeta($guardado, $accion_id)
    {
        if (empty($accion_id) || !is_object($guardado) || empty($guardado->id)) {
            return;
        }

        try {

            WhatsappChatMessage::where('id', (int) $guardado->id)->update(['ai_message_action_id' => (int) $accion_id]);

        } catch (\Throwable $e) {
            // Sin la marca, un rechazo posterior de Meta no vuelve al dueño; la promo salió igual.
        }
    }

    /**
     * El mensaje de texto libre (ventana abierta): "Hola {nombre}! {texto}", la vigencia si el dueño
     * la dijo, y el link.
     *
     * Con VARIOS artículos (N5 de la misión asistente-arreglos-segunda-prueba, 19/10/2026), en vez del link
     * suelto va un renglón "<nombre>: <link>" por artículo: el prompt y el error del combo prometían
     * "cada uno con su link" y la herramienta solo aceptaba uno.
     *
     * @param  string  $nombre
     * @param  string  $texto
     * @param  string  $vigencia  '' = no la dijo (en el texto libre no se inventa una).
     * @param  string|null  $link
     * @param  array  $links  [{article_id, nombre, link}] con 2+ artículos; vacío = el link de siempre.
     * @return string
     */
    public static function armar_mensaje($nombre, $texto, $vigencia, $link, $links = [])
    {
        $mensaje = 'Hola ' . $nombre . '! ' . $texto;

        if (trim((string) $vigencia) !== '') {
            $mensaje .= "\nVálida hasta el " . trim((string) $vigencia) . '.';
        }

        $renglones = [];

        foreach ((array) $links as $articulo) {
            if (is_array($articulo) && isset($articulo['link']) && trim((string) $articulo['link']) !== '') {
                $renglones[] = (isset($articulo['nombre']) && trim((string) $articulo['nombre']) !== '' ? trim((string) $articulo['nombre']) . ': ' : '')
                    . (string) $articulo['link'];
            }
        }

        if (count($renglones)) {

            $mensaje .= "\n" . implode("\n", $renglones);

        } elseif (!is_null($link) && $link !== '') {

            $mensaje .= "\n" . $link;
        }

        return $mensaje;
    }

    /**
     * Los artículos de una promo de VARIOS, con su link, tal como los resolvió resolver_destino(); vacío si
     * la promo es de uno, de un combo o general.
     *
     * @param  array  $destino
     * @return array<int, array{article_id: int, nombre: string, link: string}>
     */
    protected static function links_de_los_articulos(array $destino)
    {
        return isset($destino['articulos']) && is_array($destino['articulos']) ? array_values($destino['articulos']) : [];
    }

    /**
     * "Taladro: https://…; Amoladora: https://…", para la tarjeta y el bloque.
     *
     * @param  array  $links
     * @return string
     */
    protected static function links_en_una_linea(array $links)
    {
        $partes = [];

        foreach ($links as $articulo) {
            $partes[] = (string) $articulo['nombre'] . ': ' . (string) $articulo['link'];
        }

        return implode('; ', $partes);
    }

    /**
     * true si el texto de la promo trae un link escrito (N5): los links los arma el sistema, y uno que
     * escribió el modelo es inventado.
     *
     * Qué se considera link:
     *   - "http" (con o sin s) y "/articulos/" (la ruta de un artículo de la tienda);
     *   - 🔴 desde la revisión del 19/10/2026, lo que pasaba sin http: "www.", "wa.me/" y un DOMINIO
     *     escrito suelto ("mitienda.com/ofertas", "ferreteria-falsa.com.ar", "tienda.online"): una
     *     palabra pegada a un punto y a una terminación de dominio conocida, con o sin ruta.
     *
     * 🔴 Segunda revisión (19/10/2026): también los acortadores y los links de perfiles ("bit.ly/…",
     * "t.me/…", "linktr.ee/…", "goo.gl/…"): las terminaciones ly, me, ee, co y gl, y cualquier dominio
     * seguido de una ruta ("algo.xyz/promo").
     *
     * 🔴 Y LO QUE NO TIENE QUE FRENAR: los números con punto ("1.5 L", "$ 3.000", "20.10") no son dominio
     * porque la terminación tiene que ser de letras; una abreviatura con espacio después ("20 L. Oferta")
     * tampoco. Un MAIL ("ventas@ferreteria.com.ar", "info@hipermax.net.ar") no es un link: las
     * direcciones de mail se sacan enteras ANTES de buscar dominios (si no, la parte de después de la
     * arroba, ".com.ar", se leía como dominio).
     *
     * @param  string  $texto
     * @return bool
     */
    protected static function trae_un_link($texto)
    {
        $texto = mb_strtolower((string) $texto);

        foreach (['http', '/articulos/', 'www.', 'wa.me/'] as $marca) {

            if (mb_strpos($texto, $marca) !== false) {
                return true;
            }
        }

        // Los mails, afuera enteros: no son links y su dominio no tiene que frenar la promo.
        $texto = (string) preg_replace('/[^\s@]+@[^\s@]+/u', ' ', $texto);

        // Un dominio suelto con una terminación conocida, con o sin ruta.
        if (preg_match(
            '/(?<![a-z0-9\-])[a-z0-9][a-z0-9\-]*\.(?:com|ar|net|org|store|shop|online|site|info|io|ly|me|ee|co|gl)(?![a-z0-9])/u',
            $texto
        ) === 1) {
            return true;
        }

        // Cualquier dominio seguido de una ruta ("algo.xyz/promo"): eso es una dirección, no un texto.
        return preg_match('/(?<![a-z0-9\-])[a-z0-9][a-z0-9\-]*\.[a-z]{2,}\/[^\s]/u', $texto) === 1;
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
     * Identidad de la promo para el reemplazo: el mismo texto a los mismos clientes (y, con varios
     * artículos, de los mismos artículos: N5). Sin artículos la clave es la de siempre.
     *
     * @param  string  $texto
     * @param  array<int, int>  $ids
     * @param  array<int, int>  $article_ids
     * @return string
     */
    public static function clave($texto, array $ids, array $article_ids = []): string
    {
        $base = (string) $texto . '|' . implode(',', $ids);

        if (count($article_ids)) {
            $base .= '|articulos:' . implode(',', $article_ids);
        }

        return 'promocion_whatsapp:' . md5($base);
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

    /** Cuánto dura la reserva de un envío encolado sin mandar (I3 del segundo chequeo), en minutos. */
    const MINUTOS_DE_LA_RESERVA = 120;

    /**
     * Los clientes RESERVADOS por promos del negocio que se encolaron y TODAVÍA NO salieron (I3 del
     * segundo chequeo).
     *
     * Una reserva es un cliente de `datos.client_ids_encolados` (los que de verdad se encolaron, no
     * todos los que el dueño vio) de una tarjeta de promoción confirmada o en curso de hace menos de
     * MINUTOS_DE_LA_RESERVA, que todavía no tiene la fila de su promo persistida. Cuando la promo sale,
     * cuenta la fila real (para el tope del día y para el freno de 7 días); si el job falló, la reserva
     * vence sola y el cliente no queda "reservado" una semana por algo que nunca le llegó.
     *
     * @param  int  $owner_id
     * @param  int|null  $excluir_accion_id  La tarjeta que se está ejecutando (no es reserva de sí misma).
     * @return array<int, int>
     */
    public static function reservas($owner_id, $excluir_accion_id = null)
    {
        $query = AiMessageAction::where('user_id', (int) $owner_id)
            ->where('tipo', AiMessageAction::TIPO_PROMOCION_WHATSAPP)
            ->whereIn('estado', [AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::ESTADO_EN_CURSO])
            ->where('updated_at', '>=', now()->subMinutes(self::MINUTOS_DE_LA_RESERVA));

        if (!is_null($excluir_accion_id)) {
            $query->where('id', '!=', (int) $excluir_accion_id);
        }

        $reservados = [];

        foreach ($query->get() as $tarjeta) {

            $datos = is_array($tarjeta->datos) ? $tarjeta->datos : [];

            $ids = self::ids(isset($datos['client_ids_encolados']) ? $datos['client_ids_encolados'] : []);

            if (!count($ids)) {
                continue;
            }

            // Los que ya recibieron una promo DESPUÉS de proponerse esta tarjeta: ésos cuentan por su fila.
            $ya_salieron = WhatsappChat::where('user_id', (int) $owner_id)
                ->whereIn('client_id', $ids)
                ->whereIn('id', WhatsappChatMessage::where('direction', 'out')
                    ->where('source', self::SOURCE)
                    ->where('created_at', '>=', $tarjeta->created_at)
                    ->select('whatsapp_chat_id'))
                ->pluck('client_id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->all();

            foreach (array_diff($ids, $ya_salieron) as $id) {
                $reservados[$id] = (int) $id;
            }
        }

        return array_values($reservados);
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
     * Con VARIOS artículos (`article_ids`, N5 de la misión asistente-arreglos-segunda-prueba, 19/10/2026):
     * cada uno pasa por las MISMAS guardas que uno solo (del negocio, publicado en la tienda, con slug) y
     * lleva su link; `link` es la portada de la tienda, que es lo que lleva la plantilla (tiene un solo
     * lugar para el link). Uno que no pasa corta la promo nombrándolo. Con un solo id, todo como siempre.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $input
     * @return array  {link, article_id?, article_ids?, articulos?, combo_id?, nombre?, publicar_combo?} o la respuesta negativa.
     */
    protected static function resolver_destino(ContextoDeCargaIa $contexto, array $input)
    {
        $tienda = OfertaComunicacionHelper::url_de_la_tienda($contexto->owner);

        $article_id = EntradaDeCargaIa::valor($input, 'article_id');
        $articulo = EntradaDeCargaIa::texto($input, 'articulo');
        $combo_id = EntradaDeCargaIa::valor($input, 'combo_id');
        $combo = EntradaDeCargaIa::texto($input, 'combo');

        // Los ids de artículos: los de `article_ids` y, si vino, el `article_id` suelto adelante (sin repetir).
        $article_ids = self::ids(EntradaDeCargaIa::valor($input, 'article_ids'));

        if (is_numeric($article_id) && (int) $article_id > 0 && !in_array((int) $article_id, $article_ids, true)) {
            array_unshift($article_ids, (int) $article_id);
        }

        $quiere_articulo = count($article_ids) > 0 || $articulo !== '';
        $quiere_combo = (is_numeric($combo_id) && (int) $combo_id > 0) || $combo !== '';

        if (count($article_ids) > self::MAXIMO_DE_ARTICULOS) {

            return RespuestaDeCargaIa::error('Una promo lleva hasta ' . self::MAXIMO_DE_ARTICULOS . ' artículos (cada uno sale con su link) y '
                . 'me pasaste ' . count($article_ids) . '. Proponé los ' . self::MAXIMO_DE_ARTICULOS . ' que más le importan al dueño, o armá dos promos.');
        }

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

        if ($quiere_articulo && count($article_ids) > 1) {

            return self::resolver_varios_articulos($contexto, $tienda, $article_ids);
        }

        if ($quiere_articulo) {

            $modelo = self::resolver_articulo($contexto->owner_id, count($article_ids) ? $article_ids[0] : null, $articulo);

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
     * Una promo de VARIOS artículos (N5, 19/10/2026): cada uno con las mismas guardas que uno solo y su
     * link a la tienda. 🔴 Uno que no pasa (de otro negocio, despublicado, sin slug) corta la promo entera
     * nombrándolo: mandar la promo sin él cambiaría lo que el modelo le propuso al dueño, y mandarla con
     * un link que no abre es mandar un link roto.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $tienda  La URL de la tienda (ya validada: no es null).
     * @param  array<int, int>  $article_ids  De 2 a MAXIMO_DE_ARTICULOS.
     * @return array  {link (la portada), article_ids, articulos: [{article_id, nombre, link}]} o la respuesta negativa.
     */
    protected static function resolver_varios_articulos(ContextoDeCargaIa $contexto, $tienda, array $article_ids)
    {
        $articulos = [];

        foreach ($article_ids as $id) {

            $modelo = self::resolver_articulo($contexto->owner_id, $id, '');

            if (RespuestaDeCargaIa::es_negativa($modelo)) {

                return RespuestaDeCargaIa::error('No encontré el artículo ' . (int) $id . ' en este negocio: sacalo de article_ids o buscá el id correcto con consultar_stock_de_articulos.');
            }

            if (!(int) $modelo->online || trim((string) $modelo->slug) === '') {

                return RespuestaDeCargaIa::error('El artículo "' . $modelo->name . '" no está publicado en la tienda, así que su link no le abriría a nadie. Publicalo en la tienda primero, o sacalo de la promo.');
            }

            $articulos[] = [
                'article_id' => (int) $modelo->id,
                'nombre'     => (string) $modelo->name,
                'link'       => rtrim($tienda, '/') . '/articulos/' . rawurlencode((string) $modelo->slug) . '/' . (int) $contexto->owner_id,
            ];
        }

        $ids = [];

        foreach ($articulos as $articulo) {
            $ids[] = $articulo['article_id'];
        }

        return [
            // La plantilla tiene un solo lugar para el link: la portada de la tienda.
            'link'        => $tienda,
            'article_ids' => $ids,
            'articulos'   => $articulos,
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

        $reservados = self::reservas($owner_id, $excluir_accion_id);

        // Lo que ya salió en 24 horas más lo encolado que todavía no salió (no tiene fila: no se cuenta dos veces).
        $cupo = self::TOPE_POR_DIA - self::enviadas_en_24_horas($owner_id) - count($reservados);

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
            if (!is_null($ultima) || in_array((int) $client->id, $reservados, true)) {
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
