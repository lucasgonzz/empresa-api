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
 *   - presupuesto y recibo por WhatsApp → `send_comprobante()`, el método hermano con el MISMO molde
 *     (ventana abierta: documento; cerrada: plantilla `cc_cli_comprobante` con el PDF);
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
        $negocio = self::nombre_del_negocio($contexto->owner);

        $renglones = [
            ['etiqueta' => 'Cliente', 'valor' => (string) $client->name],
            ['etiqueta' => $tipo === self::TIPO_RECIBO ? 'Importe' : 'Total', 'valor' => FormatoIaHelper::monto(self::total_de($tipo, $modelo), self::moneda_de($tipo, $modelo))],
        ];

        $textos = [];

        if (in_array(self::CANAL_WHATSAPP, $van, true)) {

            $whatsapp = $evaluados[self::CANAL_WHATSAPP];

            $renglones[] = [
                'etiqueta' => 'Por WhatsApp',
                'valor'    => $whatsapp['telefono'] . ($whatsapp['camino'] === 'mensaje'
                    ? ' (como mensaje con el PDF, porque escribió en las últimas 24 horas)'
                    : ' (como plantilla de WhatsApp con el PDF adjunto)'),
            ];

            $textos['whatsapp'] = $whatsapp['camino'] === 'mensaje'
                ? self::epigrafe($tipo, $modelo, $negocio)
                : (new SaleWhatsappSenderService())->texto_de_la_plantilla($contexto->owner_id, self::nombre_del_cliente($client), self::numero_de($tipo, $modelo), $negocio);
        }

        if (in_array(self::CANAL_MAIL, $van, true)) {

            $renglones[] = ['etiqueta' => 'Por mail', 'valor' => (string) $evaluados[self::CANAL_MAIL]['email']];

            $textos['mail'] = 'Mail "' . self::asunto_del_mail($tipo) . '" con el detalle (cliente, número, ' . ($tipo === self::TIPO_RECIBO ? 'importe' : 'total') . ' y fecha) y el link al PDF.';
        }

        foreach ($no_van as $canal => $motivo) {
            $renglones[] = ['etiqueta' => 'No va por ' . self::nombre_de_canal($canal), 'valor' => (string) $motivo];
        }

        $renglones[] = ['etiqueta' => 'PDF', 'valor' => $pdf_url];

        if (isset($textos['whatsapp'])) {
            $renglones[] = ['etiqueta' => 'Texto por WhatsApp', 'valor' => $textos['whatsapp'] . ' [PDF adjunto]'];
        }

        if (isset($textos['mail'])) {
            $renglones[] = ['etiqueta' => 'Mail', 'valor' => $textos['mail']];
        }

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
            ],
            [
                'titulo'          => 'Mandar ' . $nombre . ' a ' . $client->name,
                'renglones'       => $renglones,
                'aviso'           => null,
                'texto_confirmar' => DestinatariosIaHelper::TEXTO_CONFIRMAR,
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $texto_que_sale = [];

        foreach ($textos as $canal => $texto) {
            $texto_que_sale[] = ($canal === 'whatsapp' ? 'Por WhatsApp: ' : 'Por mail: ') . $texto;
        }

        $grupos = [];

        foreach ($no_van as $canal => $motivo) {
            $grupos[] = ['motivo' => 'no_va_por_' . $canal, 'mensaje' => 'No va por ' . self::nombre_de_canal($canal) . ': ' . $motivo, 'cantidad' => 1, 'clientes' => (string) $client->name];
        }

        $resumen = 'Mandar ' . $nombre . ' a ' . $client->name . ' por ' . self::canales_en_palabras($van);

        $para_mostrar = DestinatariosIaHelper::para_mostrar([(string) $client->name], $grupos, implode("\n", $texto_que_sale), 'PDF: ' . $pdf_url);

        // Por canal, para que el modelo pueda decir "por WhatsApp al 3444..." y "por mail a ...".
        $para_mostrar['por_whatsapp'] = in_array(self::CANAL_WHATSAPP, $van, true) ? $evaluados[self::CANAL_WHATSAPP]['telefono'] : null;
        $para_mostrar['por_mail'] = in_array(self::CANAL_MAIL, $van, true) ? $evaluados[self::CANAL_MAIL]['email'] : null;

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen, [
            'para_mostrar' => $para_mostrar,
            // Reemplaza a la nota de siempre: ver DestinatariosIaHelper::COMO_MOSTRARLO.
            'nota'         => DestinatariosIaHelper::COMO_MOSTRARLO,
        ]);
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
                    self::numero_de($tipo, $modelo),
                    self::epigrafe($tipo, $modelo, self::nombre_del_negocio($contexto->owner)),
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

        $mensaje->loadMissing('whatsapp_chat');

        $telefono = !is_null($mensaje->whatsapp_chat) ? (string) $mensaje->whatsapp_chat->phone : (string) $client->phone;

        return [
            'ok'    => true,
            'texto' => 'por WhatsApp al ' . $telefono . ((string) $mensaje->source === 'plantilla' ? ' (como plantilla, con el PDF)' : ' (como mensaje, con el PDF)'),
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

            $como = (new SaleWhatsappSenderService())->como_saldria($contexto->owner_id, $client);

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
     * El nombre del negocio como lo firma send_sale(): el `name` del dueño, o "nuestro negocio".
     *
     * @param  User|null  $owner
     * @return string
     */
    protected static function nombre_del_negocio($owner)
    {
        $nombre = is_null($owner) ? '' : trim((string) $owner->name);

        return $nombre === '' ? 'nuestro negocio' : $nombre;
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
     * El asunto del mail de cada comprobante (el mismo que arma ComercioCityMailHelper).
     *
     * @param  string  $tipo
     * @return string
     */
    protected static function asunto_del_mail($tipo)
    {
        if ($tipo === self::TIPO_VENTA) {
            return 'Nueva venta registrada';
        }

        return $tipo === self::TIPO_PRESUPUESTO ? 'Te enviamos un presupuesto' : 'Te enviamos el recibo de tu pago';
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
