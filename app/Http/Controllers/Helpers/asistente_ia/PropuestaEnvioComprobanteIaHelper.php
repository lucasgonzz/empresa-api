<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Exceptions\SaleWhatsappSendException;
use App\Http\Controllers\Helpers\ComercioCityMailHelper;
use App\Http\Controllers\Helpers\OfertaComunicacionHelper;
use App\Http\Controllers\Helpers\PdfLinkHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Budget;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\SaleWhatsappSenderService;
use Illuminate\Support\Facades\Log;

/**
 * "MANDASELO AL CLIENTE" — un comprobante (venta, presupuesto o recibo) por WhatsApp y/o por mail
 * (misión asistente-envios-a-clientes, 16/10/2026). Hasta esta misión el asistente solo sabía darle
 * al DUEÑO el link del PDF (consultar_link_de_pdf) y tenía prohibido mandarle nada a un tercero.
 *
 * 🔴 NO SE INVENTA NINGÚN CAMINO DE ENVÍO:
 *   - venta por WhatsApp → `SaleWhatsappSenderService::send_sale()` tal cual (el del botón del modal
 *     de Ventas);
 *   - presupuesto y recibo por WhatsApp → `send_comprobante()`, el método hermano (ventana abierta:
 *     documento con su epígrafe; cerrada: la plantilla de MENSAJE LIBRE con el link del PDF en el texto,
 *     porque `cc_cli_comprobante` dice "de tu compra… ¡Gracias por tu compra!", falso para un
 *     presupuesto — corrección 4 del chequeo del 16/10/2026);
 *   - venta por mail → `ComercioCityMailHelper::new_sale(..., force_send)` tal cual (el del botón
 *     "Enviar correo al cliente"), dejando `sales.send_mail = true` como lo deja ese botón;
 *   - presupuesto y recibo por mail → `nuevo_presupuesto()` / `nuevo_recibo()`, mismo molde que la
 *     venta.
 *
 * 🔴 SIEMPRE ESPERA EL SÍ, EN LOS TRES MODOS (HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES): un
 * comprobante que salió no se desmanda. La tarjeta dice a quién, a qué número y a qué mail, si por
 * WhatsApp sale como mensaje o como plantilla, el link del PDF y el texto exacto.
 *
 * 🔴 EL AISLAMIENTO ES POR DUEÑO, ANTES DE MIRAR NADA. El comprobante se busca SIEMPRE dentro del
 * negocio de esta conversación y por el número que ve el dueño: un número de otro comercio (en una
 * base compartida hay 51) se contesta como "no encontré", nunca se manda.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * EL RECIBO: CÓMO SE LO IDENTIFICA (decisión de esta misión)
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Un recibo es un movimiento de cuenta corriente `status = 'pago_from_client'` de un CLIENTE
 * (`client_id` cargado; los de proveedor llevan el mismo status y `provider_id`). El número que ve el
 * dueño es `num_receipt`: el alta lo escribe (`CurrentAcountPagoAltaHelper`, `detalle = 'Pago N°X'`),
 * la cuenta corriente lo muestra como "Pago N° X", el PDF (`NewPagoPdf`) lo imprime, y el asistente lo
 * anuncia al registrar un pago ("Pago N° 12 registrado"). Por eso se busca por `num_receipt` dentro del
 * dueño, y solo entre los de clientes. ⚠️ `num_receipt` NO es único garantizado (existe el comando
 * `num_receipt_repetidos` justamente por eso): si hay más de uno con ese número, no se elige, se
 * pregunta cuál, con el cliente, la fecha y el monto de cada uno. También se acepta el `id` de la
 * base si otra herramienta lo devolvió.
 *
 * PHP 7.4: sin match, sin str_contains, sin nullsafe, sin argumentos nombrados.
 */
class PropuestaEnvioComprobanteIaHelper
{
    /** Los tres comprobantes que sabe mandar. */
    const TIPO_VENTA = 'venta';

    const TIPO_PRESUPUESTO = 'presupuesto';

    const TIPO_RECIBO = 'recibo';

    /** Los dos canales. */
    const CANAL_WHATSAPP = 'whatsapp';

    const CANAL_MAIL = 'mail';

    /** La extensión del módulo de WhatsApp. */
    const EXTENSION_WHATSAPP = 'whatsapp';

    /** La extensión de los mails a clientes (la misma que exige el botón de la venta). */
    const EXTENSION_MAIL = 'enviar_mail_a_clientes';

    /**
     * El permiso para mandar cada comprobante: el de VER su pantalla, que es desde donde la pantalla lo
     * comparte (el mismo criterio que LinkDePdfIaHelper para el link). El recibo vive en la cuenta
     * corriente del cliente, que es la pantalla de Clientes.
     */
    const PERMISOS = [
        self::TIPO_VENTA       => 'sale.index',
        self::TIPO_PRESUPUESTO => 'budget.index',
        self::TIPO_RECIBO      => PermisosIaHelper::PAGOS_DE_CLIENTES,
    ];

    /**
     * Herramienta proponer_envio_de_comprobante.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  tipo*, numero, id, canales, reemplaza_a
     * @return array
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input): array
    {
        $tipo = mb_strtolower(EntradaDeCargaIa::texto($input, 'tipo'));

        if (!isset(self::PERMISOS[$tipo])) {

            return RespuestaDeCargaIa::faltan(['si es una venta, un presupuesto o un recibo']);
        }

        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISOS[$tipo])) {

            return RespuestaDeCargaIa::error('No tenés permiso para ver ' . self::plural($tipo) . ' desde tu usuario, así que tampoco para mandarlos.');
        }

        $modelo = self::resolver($contexto, $tipo, EntradaDeCargaIa::valor($input, 'numero'), EntradaDeCargaIa::valor($input, 'id'));

        if (RespuestaDeCargaIa::es_negativa($modelo)) {

            return $modelo;
        }

        $client = self::cliente_de($contexto, $modelo);

        if (is_null($client)) {

            return RespuestaDeCargaIa::error(ucfirst(self::nombre($tipo, $modelo)) . ' no tiene un cliente cargado: no hay a quién mandárselo.');
        }

        $pdf_url = self::url_del_pdf($contexto->owner_id, $tipo, $modelo);

        if ($pdf_url === '') {

            return RespuestaDeCargaIa::error(
                'Este sistema no tiene cargada la dirección pública de su API, así que el link del PDF saldría sin dominio y no le abriría a nadie. '
                . 'Decile al dueño que lo avise a ComercioCity.'
            );
        }

        $pedidos = self::canales_pedidos(EntradaDeCargaIa::valor($input, 'canales'));

        $evaluados = self::evaluar_canales($contexto, $tipo, $modelo, $client);

        $van = [];
        $no_van = [];

        foreach ([self::CANAL_WHATSAPP, self::CANAL_MAIL] as $canal) {

            // Sin canales pedidos van todos los que se puedan, y la tarjeta dice por qué no los otros.
            if (count($pedidos) && !in_array($canal, $pedidos, true)) {
                continue;
            }

            if ($evaluados[$canal]['puede']) {
                $van[] = $canal;
            } else {
                $no_van[$canal] = $evaluados[$canal]['mensaje'];
            }
        }

        if (!count($van)) {

            $motivos = [];

            foreach ($no_van as $canal => $motivo) {
                $motivos[] = 'por ' . self::nombre_de_canal($canal) . ': ' . rtrim($motivo, '.');
            }

            return RespuestaDeCargaIa::error('No se lo puedo mandar a ' . $client->name . ' ' . implode('; ', $motivos) . '.');
        }

        $nombre = self::nombre($tipo, $modelo);
        $negocio = (new SaleWhatsappSenderService())->nombre_del_negocio($contexto->owner);

        $renglones = [
            ['etiqueta' => 'Cliente', 'valor' => (string) $client->name],
            ['etiqueta' => $tipo === self::TIPO_RECIBO ? 'Importe' : 'Total', 'valor' => FormatoIaHelper::monto(self::total_de($tipo, $modelo), self::moneda_de($tipo, $modelo))],
        ];

        // Las líneas de "a quién y por dónde" del bloque que se le muestra al dueño por WhatsApp.
        $destinos = ['A quién: ' . $client->name];

        $textos = [];

        if (in_array(self::CANAL_WHATSAPP, $van, true)) {

            $whatsapp = $evaluados[self::CANAL_WHATSAPP];

            $como = $whatsapp['camino'] === 'mensaje'
                ? ' (como mensaje con el PDF, porque escribió en las últimas 24 horas)'
                : ($tipo === self::TIPO_VENTA
                    ? ' (como plantilla de WhatsApp con el PDF adjunto)'
                    : ' (como plantilla de WhatsApp, con el link al PDF en el texto)');

            $renglones[] = ['etiqueta' => 'Por WhatsApp', 'valor' => $whatsapp['telefono'] . $como];
            $destinos[] = 'Por WhatsApp: ' . $whatsapp['telefono'] . $como;

            $textos['whatsapp'] = self::texto_por_whatsapp($contexto, $tipo, $modelo, $client, $whatsapp['camino'], $negocio, $pdf_url);
        }

        if (in_array(self::CANAL_MAIL, $van, true)) {

            $renglones[] = ['etiqueta' => 'Por mail', 'valor' => (string) $evaluados[self::CANAL_MAIL]['email']];
            $destinos[] = 'Por mail: ' . $evaluados[self::CANAL_MAIL]['email'];

            // 🔴 El asunto y el texto REALES, del mismo payload que se encola (no una descripción).
            $textos['mail'] = self::texto_del_mail(self::payload_del_mail($tipo, $modelo, $pdf_url));
        }

        foreach ($no_van as $canal => $motivo) {
            $renglones[] = ['etiqueta' => 'No va por ' . self::nombre_de_canal($canal), 'valor' => (string) $motivo];
            $destinos[] = 'No va por ' . self::nombre_de_canal($canal) . ': ' . rtrim((string) $motivo, '.');
        }

        /*
         * 🔴 EL PDF QUE SE MUESTRA ES EL QUE VA, EN CADA CANAL (I2 del segundo chequeo). La venta por
         * WhatsApp adjunta `/sale/pdf/{id}` (SaleWhatsappSenderService::url_del_pdf_que_adjunta()) y su
         * mail lleva el link con `pdf_column_profile_id`: son dos, y cada uno va en su renglón. El
         * presupuesto y el recibo llevan el mismo en los dos canales.
         */
        $pdf_por_whatsapp = $tipo === self::TIPO_VENTA
            ? (new SaleWhatsappSenderService())->url_del_pdf_que_adjunta($modelo)
            : $pdf_url;

        if (in_array(self::CANAL_WHATSAPP, $van, true) && in_array(self::CANAL_MAIL, $van, true) && $pdf_por_whatsapp !== $pdf_url) {
            $renglones[] = ['etiqueta' => 'PDF por WhatsApp', 'valor' => $pdf_por_whatsapp];
            $renglones[] = ['etiqueta' => 'PDF por mail', 'valor' => $pdf_url];
            $destinos[] = 'PDF por WhatsApp: ' . $pdf_por_whatsapp;
            $destinos[] = 'PDF por mail: ' . $pdf_url;
        } else {
            $el_pdf = in_array(self::CANAL_WHATSAPP, $van, true) ? $pdf_por_whatsapp : $pdf_url;
            $renglones[] = ['etiqueta' => 'PDF', 'valor' => $el_pdf];
            $destinos[] = 'PDF: ' . $el_pdf;
        }

        if (isset($textos['whatsapp'])) {
            $renglones[] = ['etiqueta' => 'Texto por WhatsApp', 'valor' => $textos['whatsapp']];
        }

        if (isset($textos['mail'])) {
            $renglones[] = ['etiqueta' => 'Mail', 'valor' => $textos['mail']];
        }

        // M3: el mismo comprobante ya salió hace un rato (desde acá, otra conversación o la pantalla).
        $aviso = self::aviso_de_envio_reciente($contexto, $tipo, $modelo);

        $texto_que_sale = [];

        foreach ($textos as $canal => $texto) {
            $texto_que_sale[] = ($canal === 'whatsapp' ? 'Por WhatsApp: ' : 'Por mail: ') . $texto;
        }

        $bloque = DestinatariosIaHelper::bloque(
            'Mandar ' . $nombre . ' a ' . $client->name,
            $destinos,
            [],
            'El texto que sale',
            implode("\n\n", $texto_que_sale),
            $aviso
        );

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_ENVIO_COMPROBANTE,
            self::clave($tipo, $modelo->id),
            [
                'tipo'      => $tipo,
                'modelo_id' => (int) $modelo->id,
                'client_id' => (int) $client->id,
                'canales'   => $van,
                'pdf_url'   => $pdf_url,
                // I2: cómo sale por WhatsApp (mensaje | plantilla); si cambia antes del sí, no se manda.
                'caminos'   => in_array(self::CANAL_WHATSAPP, $van, true) ? [self::CANAL_WHATSAPP => $evaluados[self::CANAL_WHATSAPP]['camino']] : [],
                // B2: a qué número y a qué mail se le mostró que va; si cambian antes del sí, no se manda.
                'destinos'  => [
                    self::CANAL_WHATSAPP => in_array(self::CANAL_WHATSAPP, $van, true) ? (string) $evaluados[self::CANAL_WHATSAPP]['telefono'] : null,
                    self::CANAL_MAIL     => in_array(self::CANAL_MAIL, $van, true) ? (string) $evaluados[self::CANAL_MAIL]['email'] : null,
                ],
                // Lo que el sistema le agrega al mensaje por WhatsApp (ver DestinatariosIaHelper::bloque()).
                'bloque'    => $bloque,
            ],
            [
                'titulo'          => 'Mandar ' . $nombre . ' a ' . $client->name,
                'renglones'       => $renglones,
                'aviso'           => $aviso,
                'texto_confirmar' => DestinatariosIaHelper::TEXTO_CONFIRMAR,
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $grupos = [];

        foreach ($no_van as $canal => $motivo) {
            $grupos[] = ['motivo' => 'no_va_por_' . $canal, 'mensaje' => 'No va por ' . self::nombre_de_canal($canal) . ': ' . $motivo, 'cantidad' => 1, 'clientes' => (string) $client->name];
        }

        $resumen = 'Mandar ' . $nombre . ' a ' . $client->name . ' por ' . self::canales_en_palabras($van);

        $para_mostrar = DestinatariosIaHelper::para_mostrar([(string) $client->name], $grupos, implode("\n", $texto_que_sale), 'PDF: ' . $pdf_url);

        // Por canal: lo que dice "por WhatsApp al 3444..." y "por mail a ...".
        $para_mostrar['por_whatsapp'] = in_array(self::CANAL_WHATSAPP, $van, true) ? $evaluados[self::CANAL_WHATSAPP]['telefono'] : null;
        $para_mostrar['por_mail'] = in_array(self::CANAL_MAIL, $van, true) ? $evaluados[self::CANAL_MAIL]['email'] : null;

        if (!is_null($aviso)) {
            $para_mostrar['aviso'] = $aviso;
        }

        // Las partes del bloque, para la tarjeta y los tests: al modelo no le llegan (M4).
        self::guardar_para_mostrar($creada['accion'], $para_mostrar);

        // B1: un solo envío esperando el sí por conversación.
        $reemplazadas = DestinatariosIaHelper::reemplazar_otros_envios($contexto, $creada['accion']->id);

        return DestinatariosIaHelper::respuesta_al_modelo($creada, $resumen, 1, $resumen . '.', $reemplazadas);
    }

    /**
     * Guarda en `datos.para_mostrar` las partes de lo que se le muestra al dueño (no viajan al modelo).
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @param  array  $para_mostrar
     * @return void
     */
    protected static function guardar_para_mostrar(AiMessageAction $accion, array $para_mostrar)
    {
        $datos = is_array($accion->datos) ? $accion->datos : [];
        $datos['para_mostrar'] = $para_mostrar;
        $accion->datos = $datos;
        $accion->save();
    }

    /**
     * El texto EXACTO que sale por WhatsApp: el epígrafe del documento con la ventana abierta; con la
     * ventana cerrada, la plantilla `cc_cli_comprobante` para la venta (como send_sale) y la de mensaje
     * libre para el presupuesto y el recibo (ver SaleWhatsappSenderService::TEMPLATE_MENSAJE_LIBRE).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @param  Client  $client
     * @param  string  $camino  'mensaje' | 'plantilla'
     * @param  string  $negocio
     * @param  string  $pdf_url
     * @return string
     */
    protected static function texto_por_whatsapp(ContextoDeCargaIa $contexto, $tipo, $modelo, Client $client, $camino, $negocio, $pdf_url)
    {
        $service = new SaleWhatsappSenderService();

        if ($camino === 'mensaje') {

            return self::epigrafe($tipo, $modelo, $negocio) . ' [PDF adjunto]';
        }

        if ($tipo === self::TIPO_VENTA) {

            return $service->texto_de_la_plantilla($contexto->owner_id, self::nombre_del_cliente($client), self::numero_de($tipo, $modelo), $negocio) . ' [PDF adjunto]';
        }

        return $service->texto_del_mensaje_libre($contexto->owner_id, self::nombre_del_cliente($client), $negocio, self::mensaje_libre($tipo, $modelo, $pdf_url));
    }

    /**
     * El {{3}} de `cc_cli_mensaje_libre` para un presupuesto o un recibo: qué es y dónde se ve. La
     * plantilla dice "Hola {{1}}, te escribimos de {{2}}: {{3}}. Podés responder este mensaje…", así
     * que el conjunto queda "…te escribimos de X: te mandamos el presupuesto N° 12. Lo ves acá: link."
     *
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @param  string  $pdf_url
     * @return string
     */
    protected static function mensaje_libre($tipo, $modelo, $pdf_url)
    {
        $que = $tipo === self::TIPO_PRESUPUESTO
            ? 'te mandamos el presupuesto N° ' . self::numero_de($tipo, $modelo)
            : 'te mandamos el recibo de tu pago N° ' . self::numero_de($tipo, $modelo);

        return $que . '. Lo ves acá: ' . $pdf_url;
    }

    /**
     * El contenido del mail de cada comprobante, del MISMO armador que usa el envío.
     *
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @param  string  $pdf_url
     * @return \App\Mail\ComercioCityMailPayload
     */
    protected static function payload_del_mail($tipo, $modelo, $pdf_url)
    {
        if ($tipo === self::TIPO_VENTA) {
            return ComercioCityMailHelper::payload_de_venta($modelo, false);
        }

        if ($tipo === self::TIPO_PRESUPUESTO) {
            return ComercioCityMailHelper::payload_de_presupuesto($modelo, $pdf_url);
        }

        return ComercioCityMailHelper::payload_de_recibo($modelo, $pdf_url);
    }

    /**
     * Asunto y texto principal del mail, como los lee el cliente: 'Asunto: "X". Título. Párrafos.'
     *
     * @param  \App\Mail\ComercioCityMailPayload  $payload
     * @return string
     */
    protected static function texto_del_mail($payload)
    {
        $partes = [];

        if (!empty($payload->title)) {
            $partes[] = rtrim((string) $payload->title, '.') . '.';
        }

        foreach ((array) $payload->paragraphs as $parrafo) {
            $partes[] = (string) $parrafo;
        }

        // Los renglones de detalle (cliente, número, total, fecha), como los ve el cliente.
        $detalle = [];

        foreach ((array) $payload->detail_lines as $renglon) {
            if (isset($renglon['label'], $renglon['value'])) {
                $detalle[] = $renglon['label'] . ': ' . $renglon['value'];
            }
        }

        return 'Asunto: "' . $payload->subject . '". ' . implode(' ', $partes)
            . (count($detalle) ? ' ' . implode('. ', $detalle) . '.' : '');
    }

    /**
     * M3 del chequeo: si este mismo comprobante ya salió en los últimos 10 minutos, la tarjeta lo
     * avisa ("ya se lo mandaste a las HH:MM"). Mira dos cosas: una tarjeta de envío del mismo
     * comprobante confirmada (desde cualquier conversación del negocio) y un WhatsApp saliente del
     * negocio con el link de ese PDF (el botón de la pantalla o este asistente). El mail de la
     * pantalla no deja registro con hora, así que ese no se ve.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @return string|null
     */
    protected static function aviso_de_envio_reciente(ContextoDeCargaIa $contexto, $tipo, $modelo)
    {
        $desde = now()->subMinutes(10);

        $tarjeta = AiMessageAction::where('user_id', $contexto->owner_id)
            ->where('tipo', AiMessageAction::TIPO_ENVIO_COMPROBANTE)
            ->where('clave', self::clave($tipo, $modelo->id))
            ->where('estado', AiMessageAction::ESTADO_CONFIRMADA)
            ->where('resuelta_at', '>=', $desde)
            ->orderBy('resuelta_at', 'DESC')
            ->first();

        $ruta = self::ruta_del_pdf($tipo, $modelo);

        $whatsapp = WhatsappChatMessage::where('direction', 'out')
            ->where('created_at', '>=', $desde)
            ->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $contexto->owner_id)->select('id'))
            ->where(function ($q) use ($ruta) {
                $q->where('media_url', 'like', '%' . $ruta . '%')->orWhere('body', 'like', '%' . $ruta . '%');
            })
            ->orderBy('created_at', 'DESC')
            ->first();

        $cuando = null;

        if (!is_null($tarjeta) && !is_null($tarjeta->resuelta_at)) {
            $cuando = $tarjeta->resuelta_at;
        }

        if (!is_null($whatsapp) && (is_null($cuando) || $whatsapp->created_at->gt($cuando))) {
            $cuando = $whatsapp->created_at;
        }

        if (is_null($cuando)) {
            return null;
        }

        return 'Ojo: ' . self::singular($tipo) . ' ya se lo mandaste a las ' . $cuando->copy()->timezone(config('app.timezone'))->format('H:i')
            . '. Mandalo de nuevo solo si de verdad hace falta.';
    }

    /**
     * El pedazo de la ruta del PDF que identifica al comprobante ('/budget/pdf/12/').
     *
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @return string
     */
    protected static function ruta_del_pdf($tipo, $modelo)
    {
        if ($tipo === self::TIPO_VENTA) {
            return '/sale/pdf/' . (int) $modelo->id . '?';
        }

        if ($tipo === self::TIPO_PRESUPUESTO) {
            return '/budget/pdf/' . (int) $modelo->id . '/';
        }

        return '/current-acount/pdf/' . (int) $modelo->id . '?';
    }

    /**
     * Manda el comprobante por los canales de la tarjeta y dice qué salió por cada uno.
     *
     * 🔴 UN CANAL QUE FALLA NO VOLTEA AL OTRO. Si el WhatsApp salió y el mail no, el WhatsApp ya está
     * en el teléfono del cliente: lanzar acá dejaría la tarjeta "sin confirmar" y un segundo sí lo
     * mandaría dos veces. Se devuelve lo que salió y lo que no, con su motivo. Solo si NO salió nada se
     * lanza (y ahí volver a intentarlo no duplica nada).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, enviados, no_salieron}
     *
     * @throws AccionIaException
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        $datos = is_array($accion->datos) ? $accion->datos : [];

        $tipo = isset($datos['tipo']) ? (string) $datos['tipo'] : '';

        if (!isset(self::PERMISOS[$tipo])) {

            throw new AccionIaException(422, 'Esta tarjeta no se puede confirmar.');
        }

        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISOS[$tipo])) {

            throw new AccionIaException(422, 'No tenés permiso para ver ' . self::plural($tipo) . ' desde tu usuario, así que tampoco para mandarlos.');
        }

        // Por id y SIEMPRE dentro del dueño: lo que la tarjeta guardó, revalidado.
        $modelo = self::resolver($contexto, $tipo, null, isset($datos['modelo_id']) ? $datos['modelo_id'] : null);

        if (RespuestaDeCargaIa::es_negativa($modelo)) {

            throw new AccionIaException(422, ucfirst(self::singular($tipo)) . ' ya no está en este negocio. Pedímelo de nuevo.');
        }

        $client = self::cliente_de($contexto, $modelo);

        if (is_null($client) || (int) $client->id !== (int) (isset($datos['client_id']) ? $datos['client_id'] : 0)) {

            throw new AccionIaException(422, 'El cliente de ' . self::nombre($tipo, $modelo) . ' cambió desde que te lo mostré. Pedímelo de nuevo.');
        }

        $canales = isset($datos['canales']) && is_array($datos['canales']) ? $datos['canales'] : [];

        /*
         * 🔴 LO QUE SALE ES LO QUE SE MOSTRÓ (I2 del segundo chequeo). Si entre la tarjeta y el sí la
         * ventana de 24 h se abrió o se cerró, el WhatsApp saldría con OTRO texto (el epígrafe o la
         * plantilla) que el dueño no leyó. No se manda nada: el modelo vuelve a mostrárselo.
         */
        $hoy_por_canal = self::evaluar_canales($contexto, $tipo, $modelo, $client);

        /*
         * 🔴 Y VA A DONDE SE MOSTRÓ (B2 de la revisión de merge): si el teléfono o el mail del cliente
         * cambiaron desde la tarjeta, el comprobante le llegaría a otro número o a otra casilla que la
         * que el dueño vio. No se manda nada.
         */
        $destinos = isset($datos['destinos']) && is_array($datos['destinos']) ? $datos['destinos'] : [];

        foreach ([self::CANAL_WHATSAPP => 'telefono', self::CANAL_MAIL => 'email'] as $canal => $clave) {

            if (!in_array($canal, $canales, true) || !isset($destinos[$canal]) || empty($hoy_por_canal[$canal]['puede'])) {
                continue;
            }

            if ((string) $hoy_por_canal[$canal][$clave] !== (string) $destinos[$canal]) {

                throw new AccionIaException(422, 'No lo mandé: cambió ' . ($canal === self::CANAL_WHATSAPP ? 'el teléfono' : 'el mail')
                    . ' del cliente desde que se lo mostré al dueño (ahora sería ' . $hoy_por_canal[$canal][$clave] . '). '
                    . 'Volvé a mostrárselo y pedile el sí de nuevo.');
            }
        }

        if (in_array(self::CANAL_WHATSAPP, $canales, true) && isset($datos['caminos'][self::CANAL_WHATSAPP])) {

            $hoy = $hoy_por_canal[self::CANAL_WHATSAPP];

            if (!empty($hoy['puede']) && $hoy['camino'] !== $datos['caminos'][self::CANAL_WHATSAPP]) {

                throw new AccionIaException(422, 'No lo mandé: cambió cómo sale por WhatsApp (ahora saldría como '
                    . ($hoy['camino'] === 'mensaje' ? 'mensaje' : 'plantilla') . ', y el dueño vio el otro texto). '
                    . 'Volvé a mostrárselo y pedile el sí de nuevo.');
            }
        }

        $pdf_url = isset($datos['pdf_url']) ? (string) $datos['pdf_url'] : '';

        $enviados = [];
        $no_salieron = [];

        foreach ($canales as $canal) {

            if ($canal === self::CANAL_WHATSAPP) {

                $salida = self::mandar_por_whatsapp($contexto, $tipo, $modelo, $client, $pdf_url);

            } elseif ($canal === self::CANAL_MAIL) {

                $salida = self::mandar_por_mail($contexto, $tipo, $modelo, $client, $pdf_url);

            } else {

                continue;
            }

            if ($salida['ok']) {
                $enviados[] = $salida['texto'];
            } else {
                $no_salieron[] = ['canal' => self::nombre_de_canal($canal), 'motivo' => $salida['texto']];
            }
        }

        $nombre = self::nombre($tipo, $modelo);

        if (!count($enviados)) {

            $motivos = [];

            foreach ($no_salieron as $fila) {
                $motivos[] = 'por ' . $fila['canal'] . ': ' . rtrim($fila['motivo'], '.');
            }

            throw new AccionIaException(422, 'No salió ' . $nombre . ' ' . implode('; ', $motivos) . '.');
        }

        $texto = ucfirst($nombre) . ' mandado a ' . $client->name . ': ' . implode('; ', $enviados) . '.';

        if (count($no_salieron)) {

            $partes = [];

            foreach ($no_salieron as $fila) {
                $partes[] = 'por ' . $fila['canal'] . ' NO salió (' . rtrim($fila['motivo'], '.') . ')';
            }

            $texto .= ' Ojo: ' . implode('; ', $partes) . '.';
        }

        return [
            'texto'       => $texto,
            'enviados'    => $enviados,
            'no_salieron' => $no_salieron,
        ];
    }

    /**
     * Identidad del envío: el mismo comprobante. Una corrección ("no, mandáselo solo por mail") pisa
     * la tarjeta anterior.
     *
     * @param  string  $tipo
     * @param  int  $modelo_id
     * @return string
     */
    public static function clave($tipo, $modelo_id): string
    {
        return 'envio_comprobante:' . (string) $tipo . ':' . (int) $modelo_id;
    }

    /**
     * El WhatsApp, por el camino de cada comprobante. Nunca lanza: devuelve qué pasó.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @param  Client  $client
     * @param  string  $pdf_url
     * @return array{ok: bool, texto: string}
     */
    protected static function mandar_por_whatsapp(ContextoDeCargaIa $contexto, $tipo, $modelo, Client $client, $pdf_url)
    {
        if (!PermisosIaHelper::tiene_extencion($contexto->owner, self::EXTENSION_WHATSAPP)) {

            return ['ok' => false, 'texto' => 'el negocio no tiene el módulo de WhatsApp'];
        }

        $persona_id = is_null($contexto->persona) ? null : (int) $contexto->persona->id;

        $service = new SaleWhatsappSenderService();

        try {

            if ($tipo === self::TIPO_VENTA) {

                // 🔴 El de la venta, TAL CUAL: el mismo que el botón del modal de Ventas.
                $mensaje = $service->send_sale($modelo, $persona_id);

            } else {

                $mensaje = $service->send_comprobante(
                    $client,
                    $contexto->owner_id,
                    $pdf_url,
                    ($tipo === self::TIPO_PRESUPUESTO ? 'presupuesto-' : 'recibo-') . self::numero_de($tipo, $modelo) . '.pdf',
                    self::epigrafe($tipo, $modelo, $service->nombre_del_negocio($contexto->owner)),
                    self::mensaje_libre($tipo, $modelo, $pdf_url),
                    $persona_id,
                    ucfirst(self::nombre($tipo, $modelo)) . ' no tiene un cliente con teléfono cargado.'
                );
            }

        } catch (SaleWhatsappSendException $e) {

            return ['ok' => false, 'texto' => $e->getMessage()];

        } catch (\Throwable $e) {

            Log::error('PropuestaEnvioComprobanteIaHelper: falló el WhatsApp del comprobante', [
                'tipo'      => $tipo,
                'modelo_id' => (int) $modelo->id,
                'error'     => $e->getMessage(),
            ]);

            return ['ok' => false, 'texto' => 'una falla del sistema'];
        }

        /*
         * 🔴 DE ACÁ PARA ABAJO NADA PUEDE LANZAR (M2 del chequeo): Kapso ya aceptó el mensaje, y una
         * excepción haría que la tarjeta quedara "sin confirmar" con el WhatsApp ya en el teléfono del
         * cliente. Leer el chat es solo para decir a qué número fue: si falla, va el de la ficha.
         */
        $telefono = (string) $client->phone;

        try {

            $mensaje->loadMissing('whatsapp_chat');

            if (!is_null($mensaje->whatsapp_chat)) {
                $telefono = (string) $mensaje->whatsapp_chat->phone;
            }

        } catch (\Throwable $e) {

            Log::warning('PropuestaEnvioComprobanteIaHelper: el WhatsApp salió pero no se pudo leer su chat', ['error' => $e->getMessage()]);
        }

        if ((string) $mensaje->source !== 'plantilla') {
            $como = ' (como mensaje, con el PDF)';
        } elseif ($tipo === self::TIPO_VENTA) {
            $como = ' (como plantilla, con el PDF)';
        } else {
            $como = ' (como plantilla, con el link al PDF)';
        }

        return [
            'ok'    => true,
            'texto' => 'por WhatsApp al ' . $telefono . $como,
        ];
    }

    /**
     * El mail, por el camino de cada comprobante. Nunca lanza: devuelve qué pasó.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @param  Client  $client
     * @param  string  $pdf_url
     * @return array{ok: bool, texto: string}
     */
    protected static function mandar_por_mail(ContextoDeCargaIa $contexto, $tipo, $modelo, Client $client, $pdf_url)
    {
        if (!PermisosIaHelper::tiene_extencion($contexto->owner, self::EXTENSION_MAIL)) {

            return ['ok' => false, 'texto' => 'el negocio no tiene el módulo de mails a clientes'];
        }

        $email = OfertaComunicacionHelper::email_valido($client->email);

        if (is_null($email)) {

            return ['ok' => false, 'texto' => 'el cliente no tiene un mail válido cargado'];
        }

        try {

            if ($tipo === self::TIPO_VENTA) {

                // 🔴 El de la venta, TAL CUAL, y con la misma marca que deja el botón "Enviar correo al
                // cliente" (SaleController::try_queue_sale_client_mail), para que el listado diga lo mismo.
                ComercioCityMailHelper::new_sale($modelo, false, true);

                $modelo->send_mail = true;
                $modelo->save();

            } elseif ($tipo === self::TIPO_PRESUPUESTO) {

                $email = ComercioCityMailHelper::nuevo_presupuesto($modelo, $pdf_url);

            } else {

                $email = ComercioCityMailHelper::nuevo_recibo($modelo, $pdf_url);
            }

        } catch (\Throwable $e) {

            Log::error('PropuestaEnvioComprobanteIaHelper: falló el mail del comprobante', [
                'tipo'      => $tipo,
                'modelo_id' => (int) $modelo->id,
                'error'     => $e->getMessage(),
            ]);

            return ['ok' => false, 'texto' => 'una falla del sistema'];
        }

        if (is_null($email)) {

            return ['ok' => false, 'texto' => 'el cliente no tiene un mail válido cargado'];
        }

        // "Encolado": el mail sale en segundo plano y el rechazo del servidor (si lo hay) pasa después.
        return ['ok' => true, 'texto' => 'por mail a ' . $email . ' (sale en unos minutos)'];
    }

    /**
     * Si se puede por cada canal, con el dato que lo dice (teléfono y camino; mail) o el motivo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @param  Client  $client
     * @return array<string, array<string, mixed>>
     */
    protected static function evaluar_canales(ContextoDeCargaIa $contexto, $tipo, $modelo, Client $client)
    {
        $whatsapp = ['puede' => false, 'mensaje' => 'el negocio no tiene el módulo de WhatsApp.'];

        if (PermisosIaHelper::tiene_extencion($contexto->owner, self::EXTENSION_WHATSAPP)) {

            // La plantilla de la ventana cerrada: la de comprobante para la venta, mensaje libre para el resto.
            $como = (new SaleWhatsappSenderService())->como_saldria(
                $contexto->owner_id,
                $client,
                $tipo === self::TIPO_VENTA ? SaleWhatsappSenderService::TEMPLATE_META_NAME : SaleWhatsappSenderService::TEMPLATE_MENSAJE_LIBRE
            );

            $whatsapp = $como['puede']
                ? ['puede' => true, 'camino' => $como['camino'], 'telefono' => $como['telefono']]
                : ['puede' => false, 'mensaje' => $como['mensaje']];
        }

        $mail = ['puede' => false, 'mensaje' => 'el negocio no tiene el módulo de mails a clientes.'];

        if (PermisosIaHelper::tiene_extencion($contexto->owner, self::EXTENSION_MAIL)) {

            $email = OfertaComunicacionHelper::email_valido($client->email);

            $mail = is_null($email)
                ? ['puede' => false, 'mensaje' => 'el cliente no tiene un mail válido cargado.']
                : ['puede' => true, 'email' => $email];
        }

        return [self::CANAL_WHATSAPP => $whatsapp, self::CANAL_MAIL => $mail];
    }

    /**
     * El comprobante, SIEMPRE dentro del dueño: por el número que ve la persona, o por el id si otra
     * herramienta lo devolvió.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $tipo
     * @param  mixed  $numero
     * @param  mixed  $id
     * @return mixed  El modelo, o la respuesta negativa.
     */
    protected static function resolver(ContextoDeCargaIa $contexto, $tipo, $numero, $id)
    {
        $tiene_numero = is_numeric($numero) && (int) $numero > 0;
        $tiene_id = is_numeric($id) && (int) $id > 0;

        if (!$tiene_numero && !$tiene_id) {

            return RespuestaDeCargaIa::faltan(['el número ' . self::de_tipo($tipo)]);
        }

        if ($tipo === self::TIPO_VENTA) {

            $query = Sale::where('user_id', $contexto->owner_id);
            $columna = 'num';

        } elseif ($tipo === self::TIPO_PRESUPUESTO) {

            $query = Budget::where('user_id', $contexto->owner_id);
            $columna = 'num';

        } else {

            // Ver "EL RECIBO" en el docblock de la clase.
            $query = CurrentAcount::where('user_id', $contexto->owner_id)
                ->where('status', 'pago_from_client')
                ->whereNotNull('client_id');
            $columna = 'num_receipt';
        }

        if ($tiene_id) {

            $query->where('id', (int) $id);

        } else {

            $query->where($columna, (int) $numero);
        }

        $encontrados = $query->orderBy('id', 'DESC')->limit(6)->get();

        if (!count($encontrados)) {

            return RespuestaDeCargaIa::error(
                'No encontré ' . self::ninguno($tipo) . ' N° ' . ($tiene_numero ? (int) $numero : (int) $id) . ' en este negocio.'
            );
        }

        if (count($encontrados) > 1) {

            // Solo puede pasar con recibos de número repetido: se pregunta, no se elige.
            $opciones = [];

            foreach ($encontrados as $encontrado) {
                $encontrado->loadMissing('client');

                $opciones[] = [
                    'id'      => (int) $encontrado->id,
                    'cliente' => is_null($encontrado->client) ? null : (string) $encontrado->client->name,
                    'fecha'   => is_null($encontrado->created_at) ? null : $encontrado->created_at->format('d/m/Y'),
                    'importe' => FormatoIaHelper::monto(self::total_de($tipo, $encontrado), self::moneda_de($tipo, $encontrado)),
                ];
            }

            return RespuestaDeCargaIa::faltan(['cuál de estos ' . self::plural($tipo) . ' con ese número (mandá su id)'], ['comprobantes' => $opciones]);
        }

        return $encontrados[0];
    }

    /**
     * El cliente del comprobante, solo si es de este negocio.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $modelo
     * @return Client|null
     */
    protected static function cliente_de(ContextoDeCargaIa $contexto, $modelo)
    {
        if (empty($modelo->client_id)) {

            return null;
        }

        return Client::where('id', (int) $modelo->client_id)->where('user_id', $contexto->owner_id)->first();
    }

    /**
     * La URL pública del PDF, con su token. Las rutas son las MISMAS que comparte la pantalla:
     * `sale/pdf/{id}`, `budget/pdf/{id}/1/0` (con el diseño de presupuesto por defecto, como el botón
     * de WhatsApp) y `current-acount/pdf/{id}` (el recibo, `NewPagoPdf`). '' si no hay dirección
     * pública: nunca un link sin dominio.
     *
     * @param  int  $owner_id
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @return string
     */
    protected static function url_del_pdf($owner_id, $tipo, $modelo)
    {
        $base = LinkDePdfIaHelper::base_publica($owner_id);

        if ($base === '') {

            return '';
        }

        if ($tipo === self::TIPO_VENTA) {

            /*
             * 🔴 El link de la venta es el MISMO que lleva su mail (ComercioCityMailHelper::payload_de_venta():
             * con `pdf_column_profile_id` y su token), así el dueño ve el que le llega al cliente. Si el
             * mail no arma ese link (no hay ninguna opción de PDF cargada), el de siempre de la venta.
             */
            foreach ((array) ComercioCityMailHelper::payload_de_venta($modelo, false)->links as $link) {

                if (isset($link['url']) && strpos((string) $link['url'], '/sale/pdf/' . (int) $modelo->id . '?') !== false) {
                    return (string) $link['url'];
                }
            }

            return PdfLinkHelper::con_token($base . '/sale/pdf/' . (int) $modelo->id, 'sale', (int) $modelo->id, (int) $modelo->user_id);
        }

        if ($tipo === self::TIPO_PRESUPUESTO) {

            return PdfLinkHelper::con_token(
                $base . '/budget/pdf/' . (int) $modelo->id . '/' . LinkDePdfIaHelper::FLAGS_PRESUPUESTO . LinkDePdfIaHelper::query_del_diseno_del_presupuesto($owner_id),
                'budget',
                (int) $modelo->id,
                (int) $modelo->user_id
            );
        }

        return PdfLinkHelper::con_token($base . '/current-acount/pdf/' . (int) $modelo->id, 'current_acount', (int) $modelo->id, (int) $modelo->user_id);
    }

    /**
     * Los canales que pidió la persona, limpios. Vacío = no dijo: van todos los que se puedan.
     *
     * @param  mixed  $valor
     * @return array<int, string>
     */
    protected static function canales_pedidos($valor)
    {
        if (is_string($valor)) {
            $valor = [$valor];
        }

        if (!is_array($valor)) {
            return [];
        }

        $canales = [];

        foreach ($valor as $canal) {

            $canal = mb_strtolower(trim((string) $canal));

            if ($canal === 'correo' || $canal === 'email' || $canal === 'e-mail') {
                $canal = self::CANAL_MAIL;
            }

            if (in_array($canal, [self::CANAL_WHATSAPP, self::CANAL_MAIL], true) && !in_array($canal, $canales, true)) {
                $canales[] = $canal;
            }
        }

        return $canales;
    }

    /**
     * El epígrafe del documento cuando la ventana está abierta. El de la venta es el de send_sale()
     * (no se cambia); el del presupuesto y el recibo dicen qué son.
     *
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @param  string  $negocio
     * @return string
     */
    protected static function epigrafe($tipo, $modelo, $negocio)
    {
        if ($tipo === self::TIPO_VENTA) {

            return 'Comprobante de tu compra en ' . $negocio;
        }

        return ($tipo === self::TIPO_PRESUPUESTO ? 'Presupuesto' : 'Recibo') . ' N° ' . self::numero_de($tipo, $modelo) . ' de ' . $negocio;
    }

    /**
     * El nombre del cliente para la plantilla, como lo arma send_sale().
     *
     * @param  Client  $client
     * @return string
     */
    protected static function nombre_del_cliente(Client $client)
    {
        return !empty($client->name) ? (string) $client->name : 'Cliente';
    }

    /**
     * El número que ve el dueño (o el id si no tiene).
     *
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @return string
     */
    protected static function numero_de($tipo, $modelo)
    {
        $numero = $tipo === self::TIPO_RECIBO ? $modelo->num_receipt : $modelo->num;

        return ($numero !== null && $numero !== '') ? (string) $numero : (string) $modelo->id;
    }

    /**
     * "la venta N° 12", "el presupuesto N° 900004", "el recibo N° 3".
     *
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @return string
     */
    protected static function nombre($tipo, $modelo)
    {
        return self::singular($tipo) . ' N° ' . self::numero_de($tipo, $modelo);
    }

    /**
     * @param  string  $tipo
     * @return string
     */
    protected static function singular($tipo)
    {
        if ($tipo === self::TIPO_VENTA) {
            return 'la venta';
        }

        return $tipo === self::TIPO_PRESUPUESTO ? 'el presupuesto' : 'el recibo';
    }

    /**
     * @param  string  $tipo
     * @return string
     */
    protected static function plural($tipo)
    {
        if ($tipo === self::TIPO_VENTA) {
            return 'las ventas';
        }

        return $tipo === self::TIPO_PRESUPUESTO ? 'los presupuestos' : 'los recibos de la cuenta corriente';
    }

    /**
     * @param  string  $tipo
     * @return string
     */
    protected static function ninguno($tipo)
    {
        if ($tipo === self::TIPO_VENTA) {
            return 'ninguna venta';
        }

        return $tipo === self::TIPO_PRESUPUESTO ? 'ningún presupuesto' : 'ningún recibo de un cliente';
    }

    /**
     * @param  string  $tipo
     * @return string
     */
    protected static function de_tipo($tipo)
    {
        if ($tipo === self::TIPO_VENTA) {
            return 'de la venta';
        }

        return $tipo === self::TIPO_PRESUPUESTO ? 'del presupuesto' : 'del recibo';
    }

    /**
     * El total de una venta o un presupuesto; el importe (haber) de un recibo.
     *
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @return float
     */
    protected static function total_de($tipo, $modelo)
    {
        $total = $tipo === self::TIPO_RECIBO ? $modelo->haber : $modelo->total;

        return is_null($total) ? 0.0 : (float) $total;
    }

    /**
     * La moneda del comprobante (null = pesos).
     *
     * @param  string  $tipo
     * @param  mixed  $modelo
     * @return int|null
     */
    protected static function moneda_de($tipo, $modelo)
    {
        return is_null($modelo->moneda_id) ? null : (int) $modelo->moneda_id;
    }

    /**
     * @param  string  $canal
     * @return string
     */
    protected static function nombre_de_canal($canal)
    {
        return $canal === self::CANAL_WHATSAPP ? 'WhatsApp' : 'mail';
    }

    /**
     * "WhatsApp y mail".
     *
     * @param  array<int, string>  $canales
     * @return string
     */
    protected static function canales_en_palabras(array $canales)
    {
        $nombres = array_map(function ($canal) {
            return self::nombre_de_canal($canal);
        }, $canales);

        return implode(' y ', $nombres);
    }
}
