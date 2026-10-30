<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\providerOrder\FacturaDeCompraHelper;
use App\Http\Controllers\Helpers\providerOrder\ModoFacturacionHelper;
use App\Http\Controllers\Helpers\providerOrder\ProviderOrderAltaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\CreditAccount;
use App\Models\Iva;
use App\Models\Provider;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use Carbon\Carbon;

/**
 * LA COMPRA SIN ARTÍCULOS: una factura de un proveedor cargada como deuda, sin renglones (misión
 * agente-mejoras-uso-real, E1 punto 6, 29/10/2026).
 *
 * 🔴 EL CASO REAL (Ferre Total, CONV 19 del 29/10/2026). Pablo escribió «me cargás una compra sin
 * artículos con una factura sin IVA de 198.522 al proveedor ferretotal, con movimientos en cuenta
 * corriente». El agente no tenía cómo y le pidió la FOTO de la factura: la única compra que sabía
 * armar era la compra con factura escaneada (PropuestaCompraConFacturaIaHelper). Pablo mandó la foto,
 * se creó una compra con escaneo que no era lo que había pedido, y en el mensaje siguiente quiso
 * cambiarle el proveedor. Lo que pidió existe en la pantalla de Compras: una compra sin artículos con
 * «Total desde facturas» prendido y la factura cargada a mano, con su alícuota (o sin ella).
 *
 * 🔴 EL CAMINO ES EL DE LA PANTALLA, PIEZA POR PIEZA, Y NO UNO PROPIO:
 *   1. ProviderOrderAltaHelper::crear() con `total_from_provider_order_afip_tickets = 1` y sin
 *      artículos: el mismo alta que usa ProviderOrderController::store() (y la compra con factura).
 *   2. La factura como una fila de `provider_order_afip_tickets` y, si discrimina IVA, su alícuota en
 *      `provider_order_afip_ticket_ivas`, con las mismas columnas que escriben
 *      ProviderOrderAfipTicketController::store() y ProviderOrderAfipTicketIvaController::store().
 *   3. FacturaDeCompraHelper::guardar_totales() + FacturaDeCompraHelper::recalcular_compra(): la cuenta
 *      del total de la factura, el total de la compra y el movimiento de la cuenta corriente del
 *      proveedor, exactamente lo que corre la pantalla después de guardar una factura.
 *
 * 🔴 `total_with_iva` NACE EN 0, Y NO ES UN DESCUIDO. Con «Total desde facturas» el total de la compra
 * es la suma de `afip_ticket->total`, que YA trae el IVA adentro (neto + IVA). Si además se prendiera
 * `total_with_iva`, NewProviderOrderHelper::set_totales() le volvería a sumar `total_iva` por encima
 * (suma_iva_al_total()) y la deuda con el proveedor quedaría con el IVA dos veces. Es como la arma la
 * pantalla (y como la fija tests/Feature/Compras/11_Factura_De_Compra_Total_Y_Percepciones_Test).
 *
 * 🔴 NUNCA SE AUTO-CONFIRMA, en ningún modo (HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES): mueve la
 * deuda con el proveedor por un número que dictó la persona (por audio, muchas veces), y un 198.522
 * entendido como 198.552 no se nota hasta que se le paga de más. La persona ve el total, el IVA y la
 * cuenta corriente en la tarjeta, y recién ahí confirma.
 *
 * QUÉ SIGNIFICA «SIN IVA». La factura NO discrimina IVA (una C, una de un Monotributista, o el dueño
 * que la carga sin desglose): el total es el que dijo la persona y la factura queda sin alícuotas, que
 * es el caso «sin desglose» de guardar_totales() (`total_iva` queda en null: el IVA "no aplica"). Con
 * 21 / 10,5 / 27 el total que dijo la persona es el FINAL de la factura y se desarma en neto + IVA.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class PropuestaCompraSinArticulosIaHelper
{
    /** El valor de `iva` para una factura que no discrimina IVA. */
    const SIN_IVA = 'sin_iva';

    /**
     * Las alícuotas que acepta la herramienta, como las escribe IvaSeeder en `ivas.percentage`. Son
     * las tres de una factura de compra normal; las raras (5, 2,5, exento) se cargan desde la pantalla.
     */
    const ALICUOTAS = ['21', '10.5', '27'];

    /** Largo máximo del número de comprobante (la columna `code` es un string de 255; esto es un N° de factura). */
    const LARGO_NUMERO = 60;

    /**
     * Herramienta proponer_compra_sin_articulos.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  proveedor*, total*, iva* ("sin_iva" | 21 | 10.5 | 27), numero_comprobante,
     *                        fecha (AAAA-MM-DD), sucursal, cuenta_corriente (default true), reemplaza_a
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::COMPRAS)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('compras'));
        }

        /* El total: sin él no hay compra, y nunca se supone. */
        $valor_total = EntradaDeCargaIa::valor($input, 'total');

        if (EntradaDeCargaIa::vacio($valor_total)) {

            return RespuestaDeCargaIa::faltan(['el total de la factura']);
        }

        $total = EntradaDeCargaIa::monto_positivo($valor_total, 'El total de la factura tiene que ser un número mayor a 0.');

        if (RespuestaDeCargaIa::es_negativa($total)) {

            return $total;
        }

        /* El IVA: «sin IVA» o la alícuota. Tampoco se supone: cambia el crédito fiscal del dueño. */
        $iva = self::iva_del_input(EntradaDeCargaIa::valor($input, 'iva'));

        if (RespuestaDeCargaIa::es_negativa($iva)) {

            return $iva;
        }

        /*
         * El proveedor, con el MISMO reconocimiento que la compra con factura (normalizado y en las dos
         * direcciones contra `name` y `razon_social`): «ferretotal» encuentra a «FERRETOTAL».
         */
        $proveedor = ProveedorDeLaFacturaIaHelper::resolver(
            $contexto,
            EntradaDeCargaIa::texto($input, 'proveedor'),
            '',
            $mensaje
        );

        if (RespuestaDeCargaIa::es_negativa($proveedor)) {

            return $proveedor;
        }

        /* Un proveedor que no existe se da de alta en la misma carga, como en la compra con factura. */
        $proveedor_nuevo = null;

        if (is_array($proveedor)) {

            $proveedor_nuevo = PropuestaCompraConFacturaIaHelper::alta_del_proveedor($contexto, $proveedor['nuevo']);

            if (RespuestaDeCargaIa::es_negativa($proveedor_nuevo)) {

                return $proveedor_nuevo;
            }
        }

        $nombre_proveedor = is_null($proveedor_nuevo) ? (string) $proveedor->name : $proveedor_nuevo['nombre'];

        /* La fecha de la factura: la dicha, o hoy. Una factura del futuro es un error de tipeo. */
        $fecha = EntradaDeCargaIa::fecha($contexto, EntradaDeCargaIa::valor($input, 'fecha'));

        if (RespuestaDeCargaIa::es_negativa($fecha)) {

            return $fecha;
        }

        if ($fecha->copy()->startOfDay()->gt($contexto->hoy)) {

            return RespuestaDeCargaIa::error('La fecha de la factura no puede ser futura. Decime de qué día es.');
        }

        $numero = mb_substr(EntradaDeCargaIa::texto($input, 'numero_comprobante'), 0, self::LARGO_NUMERO);

        /* Nunca se pregunta: la nombrada, la de la persona, la única, o ninguna (ver resolver_sucursal()). */
        $sucursal = PropuestaCompraConFacturaIaHelper::resolver_sucursal($contexto, EntradaDeCargaIa::texto($input, 'sucursal'));

        $cuenta_corriente = self::cuenta_corriente_del_input(EntradaDeCargaIa::valor($input, 'cuenta_corriente'));

        $importes = self::importes($total, $iva);

        $renglones = [
            ['etiqueta' => 'Proveedor', 'valor' => $nombre_proveedor . (is_null($proveedor_nuevo) ? '' : ' (nuevo: lo doy de alta)')],
            ['etiqueta' => 'Factura', 'valor' => ($numero === '' ? 'Sin número' : 'N° ' . $numero) . ', del ' . $fecha->format('d/m/Y')],
            ['etiqueta' => 'Total', 'valor' => self::descripcion_del_total($importes, $iva)],
        ];

        if (!is_null($sucursal)) {

            $renglones[] = ['etiqueta' => 'Sucursal', 'valor' => PropuestaCompraConFacturaIaHelper::nombre_de_sucursal($sucursal)];
        }

        $renglones[] = [
            'etiqueta' => 'Cuenta corriente',
            'valor'    => $cuenta_corriente
                ? 'Se suma ' . FormatoIaHelper::monto($importes['total']) . ' a lo que le debés a ' . $nombre_proveedor
                : 'No genera movimiento en la cuenta corriente',
        ];

        $renglones[] = ['etiqueta' => 'Qué se hace', 'valor' => 'Se crea una compra sin artículos, con la factura cargada a mano'];

        $datos = [
            'provider_id'      => is_null($proveedor_nuevo) ? (int) $proveedor->id : null,
            'proveedor'        => $nombre_proveedor,
            /* El alta del proveedor tal como la ejecuta EjecutorGenericoIaHelper, o null si ya existía. */
            'proveedor_nuevo'  => is_null($proveedor_nuevo) ? null : $proveedor_nuevo['alta'],
            'total'            => $importes['total'],
            'iva'              => $iva,
            'neto'             => $importes['neto'],
            'iva_importe'      => $importes['iva_importe'],
            'numero_comprobante' => $numero === '' ? null : $numero,
            'fecha'            => $fecha->format('Y-m-d'),
            'address_id'       => is_null($sucursal) ? null : (int) $sucursal->id,
            'sucursal'         => is_null($sucursal) ? null : PropuestaCompraConFacturaIaHelper::nombre_de_sucursal($sucursal),
            'cuenta_corriente' => $cuenta_corriente,
        ];

        /*
         * El aviso dice lo que esta tarjeta mueve: la deuda con el proveedor. Y si el proveedor es
         * nuevo y la persona no lo nombró, que lo confirme antes de darlo de alta (mismo criterio que
         * la compra con factura).
         */
        $avisos = [];

        if ($cuenta_corriente) {

            $avisos[] = 'Mueve la deuda con el proveedor: confirmá que el total sea el de la factura.';
        }

        if (!is_null($proveedor_nuevo) && !ProveedorDeLaFacturaIaHelper::lo_dijo_la_persona($contexto, $mensaje, $nombre_proveedor)) {

            $avisos[] = 'No tenés ningún proveedor "' . $nombre_proveedor . '": confirmá que sea ése antes de darlo de alta.';
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_COMPRA_SIN_ARTICULOS,
            self::clave(is_null($proveedor_nuevo) ? (int) $proveedor->id : null, $nombre_proveedor),
            $datos,
            [
                'titulo'    => 'Compra sin artículos',
                'renglones' => $renglones,
                'aviso'     => count($avisos) ? implode(' ', $avisos) : null,
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $resumen = 'Compra sin artículos de ' . $nombre_proveedor . ' · ' . self::descripcion_del_total($importes, $iva)
            . ($cuenta_corriente ? ' · a cuenta corriente' : ' · sin cuenta corriente');

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            $resumen,
            is_null($proveedor_nuevo) ? [] : ['proveedor_nuevo' => $nombre_proveedor]
        );
    }

    /**
     * Identidad de la carga para el reemplazo: la corrección de la compra sin artículos del mismo
     * proveedor ("no, eran 200.000") reemplaza la tarjeta, y la de otro proveedor no la pisa.
     *
     * @param  int|null  $provider_id  Null si el proveedor todavía no existe.
     * @param  string  $nombre  El nombre del proveedor (para el que todavía no existe).
     * @return string
     */
    public static function clave($provider_id, $nombre = '')
    {
        if (!is_null($provider_id) && (int) $provider_id > 0) {

            return 'compra_sin_articulos:' . (int) $provider_id;
        }

        return 'compra_sin_articulos:nuevo:' . mb_strtolower(trim((string) $nombre));
    }

    /**
     * Crea la compra sin artículos con su factura y la lleva a la cuenta corriente del proveedor. Corre
     * adentro de la transacción de EjecutorAccionesIaHelper, con la persona ya autenticada (lo que
     * necesita NewProviderOrderHelper para el `user_id` del movimiento de cuenta corriente).
     *
     * 🔴 TODO SE RE-VERIFICA ACÁ: el permiso, que el proveedor siga existiendo (o se crea si la tarjeta
     * lo traía nuevo y nadie lo dio de alta en el medio) y que la alícuota exista en esta base.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta, provider_id, provider_order_id, provider_order_afip_ticket_id}
     *
     * @throws AccionIaException  422 si la carga ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::puede($contexto->persona, PermisosIaHelper::COMPRAS)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('compras'));
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $es_nuevo = empty($datos['provider_id']) && !empty($datos['proveedor_nuevo']) && is_array($datos['proveedor_nuevo']);

        $creado = false;

        if ($es_nuevo) {

            list($proveedor, $creado) = PropuestaCompraConFacturaIaHelper::proveedor_nuevo($contexto, $datos);

        } else {

            $proveedor = Provider::where('user_id', $contexto->owner_id)
                                    ->where('id', isset($datos['provider_id']) ? (int) $datos['provider_id'] : 0)
                                    ->first();
        }

        if (is_null($proveedor)) {

            throw new AccionIaException(422, 'Ese proveedor ya no existe entre los tuyos. Pedímelo de nuevo.');
        }

        $cuenta_corriente = !array_key_exists('cuenta_corriente', $datos) || !empty($datos['cuenta_corriente']);

        /*
         * 🔴 La cuenta corriente del proveedor tiene que existir ANTES de crear la compra:
         * NewProviderOrderHelper::set_current_acount() lee `credit_account->id` sin guarda, y un
         * proveedor sin cuentas (una carga vieja, un import a medias) revienta el alta con un error
         * técnico que el dueño no entiende. Acá se dice qué pasa.
         */
        if ($cuenta_corriente && !self::tiene_cuenta_en_pesos($proveedor)) {

            throw new AccionIaException(422, 'El proveedor ' . $proveedor->name . ' no tiene cuenta corriente en pesos. Abrilo desde Proveedores y guardalo de nuevo, y después pedímelo otra vez.');
        }

        $iva = isset($datos['iva']) ? (string) $datos['iva'] : self::SIN_IVA;

        $importes = self::importes((float) (isset($datos['total']) ? $datos['total'] : 0), $iva);

        if ($importes['total'] <= 0) {

            throw new AccionIaException(422, 'El total de esa factura no es válido. Pedímela de nuevo.');
        }

        /* La alícuota de esta base, por su porcentaje (el id depende del orden en que corrió IvaSeeder). */
        $iva_id = null;

        if ($iva !== self::SIN_IVA) {

            $iva_id = Iva::where('percentage', $iva)->value('id');

            if (is_null($iva_id)) {

                throw new AccionIaException(422, 'No encontré la alícuota de IVA del ' . str_replace('.', ',', $iva) . ' % en el sistema. Cargá la compra desde Compras.');
            }
        }

        $fecha = !empty($datos['fecha']) ? Carbon::parse((string) $datos['fecha']) : Carbon::now();

        /*
         * 1) La compra, por el alta de la pantalla. «Total desde facturas» prendido, el IVA NO por
         * encima (ver el 🔴 del docblock de la clase), sin tocar stock ni precios, y con o sin cuenta
         * corriente según lo que pidió la persona. Nace con su movimiento de cuenta corriente en 0: lo
         * actualiza el recálculo del paso 3.
         */
        $orden = ProviderOrderAltaHelper::crear([
            'user_id'                                => $contexto->owner_id,
            'provider_id'                            => (int) $proveedor->id,
            'provider_order_status_id'               => PropuestaCompraConFacturaIaHelper::estado_en_proceso_id(),
            'address_id'                             => isset($datos['address_id']) ? $datos['address_id'] : null,
            'modo_facturacion'                       => ModoFacturacionHelper::MANUAL,
            'update_prices'                          => 0,
            'update_stock'                           => 0,
            'generate_current_acount'                => $cuenta_corriente ? 1 : 0,
            'total_with_iva'                         => 0,
            'total_from_provider_order_afip_tickets' => 1,
            'precios_incluyen_iva'                   => 0,
            'moneda_id'                              => 1,
            'articles'                               => [],
        ]);

        /* 2) La factura, con las columnas de ProviderOrderAfipTicketController::store(). */
        $ticket = ProviderOrderAfipTicket::create([
            'code'              => empty($datos['numero_comprobante']) ? null : (string) $datos['numero_comprobante'],
            'issued_at'         => $fecha,
            'percepcion_iibb'   => null,
            'percepcion_iva'    => null,
            'provider_order_id' => (int) $orden->id,
            'temporal_id'       => null,
            'user_id'           => $contexto->owner_id,
        ]);

        /* Y su alícuota, si discrimina IVA (columnas de ProviderOrderAfipTicketIvaController::store()). */
        if (!is_null($iva_id)) {

            ProviderOrderAfipTicketIva::create([
                'user_id'                       => $contexto->owner_id,
                'provider_order_afip_ticket_id' => (int) $ticket->id,
                'iva_id'                        => (int) $iva_id,
                'neto'                          => $importes['neto'],
                'iva_importe'                   => $importes['iva_importe'],
                'temporal_id'                   => null,
            ]);
        }

        /*
         * 3) Los totales de la factura y el recálculo de la compra con su cuenta corriente, como después
         * de guardar una factura en la pantalla. Sin alícuotas, el total de la factura NO es derivable y
         * se le pasa el que dijo la persona como base (el caso «sin desglose» de guardar_totales()).
         */
        FacturaDeCompraHelper::guardar_totales($ticket, is_null($iva_id) ? $importes['total'] : null);

        FacturaDeCompraHelper::recalcular_compra($orden->id);

        $orden->refresh();

        $texto = 'Creé la Compra N° ' . $orden->num . ' de ' . $proveedor->name
            . ($creado ? ' (proveedor nuevo, lo di de alta)' : '')
            . ', sin artículos y con la factura ' . (empty($datos['numero_comprobante']) ? '' : 'N° ' . $datos['numero_comprobante'] . ' ')
            . 'por ' . self::descripcion_del_total($importes, $iva) . '.'
            . ($cuenta_corriente
                ? ' Quedó en la cuenta corriente de ' . $proveedor->name . '.'
                : ' No generé movimiento en la cuenta corriente.');

        return [
            'texto'                         => $texto,
            /* Compras es la solapa 'compras' de la ruta 'provider' (ver PropuestaCompraConFacturaIaHelper). */
            'ruta'                          => [
                'name'   => 'provider',
                'params' => ['view' => 'compras'],
                'texto'  => 'Ver en Compras',
                'fecha'  => $orden->created_at->format('Y-m-d'),
            ],
            /*
             * No son para la persona: CambioDeProveedorDeCompraIaHelper reconoce por acá que la compra
             * la creó el agente (y qué factura le cargó), para poder cambiarle el proveedor.
             */
            'provider_id'                   => (int) $proveedor->id,
            'provider_order_id'             => (int) $orden->id,
            'provider_order_afip_ticket_id' => (int) $ticket->id,
        ];
    }

    /**
     * El neto, el IVA y el total de la factura a partir del total que dijo la persona.
     *
     * Con «sin IVA», el total es todo neto y no hay importe de IVA. Con una alícuota, el total es el
     * FINAL (lo que dice la factura abajo de todo) y se desarma: neto = total / (1 + alícuota), y el
     * IVA es la diferencia, para que neto + IVA dé EXACTAMENTE el total dicho, sin un centavo de
     * redondeo de más en la deuda.
     *
     * @param  float  $total
     * @param  string  $iva  SIN_IVA o una de ALICUOTAS.
     * @return array  ['total' => float, 'neto' => float, 'iva_importe' => float]
     */
    public static function importes($total, $iva)
    {
        $total = round((float) $total, 2);

        if ((string) $iva === self::SIN_IVA || !in_array((string) $iva, self::ALICUOTAS, true)) {

            return ['total' => $total, 'neto' => $total, 'iva_importe' => 0.0];
        }

        $neto = round($total / (1 + ((float) $iva) / 100), 2);

        return ['total' => $total, 'neto' => $neto, 'iva_importe' => round($total - $neto, 2)];
    }

    /**
     * «$ 198.522 sin IVA» o «$ 121.000 (neto $ 100.000 + IVA 21 % $ 21.000)»: cómo se lee el total en la
     * tarjeta y en el resultado.
     *
     * @param  array  $importes  importes()
     * @param  string  $iva
     * @return string
     */
    public static function descripcion_del_total(array $importes, $iva)
    {
        if ((string) $iva === self::SIN_IVA) {

            return FormatoIaHelper::monto($importes['total']) . ' sin IVA';
        }

        return FormatoIaHelper::monto($importes['total'])
            . ' (neto ' . FormatoIaHelper::monto($importes['neto'])
            . ' + IVA ' . str_replace('.', ',', (string) $iva) . ' % ' . FormatoIaHelper::monto($importes['iva_importe']) . ')';
    }

    /**
     * El `iva` del input normalizado: SIN_IVA, '21', '10.5' o '27'; `faltan` si no vino; error si no es
     * ninguno. Acepta lo que mandan los modelos: "sin_iva", "sin iva", "no", 0, "21", 21, 10.5, "10,5",
     * "21%".
     *
     * 🔴 El 0 cuenta como «sin IVA» y no como «no vino»: DeepSeek manda el 0 cuando la persona dijo
     * «sin IVA», y tratarlo como faltante le volvería a preguntar algo que ya contestó.
     *
     * @param  mixed  $valor
     * @return string|array
     */
    protected static function iva_del_input($valor)
    {
        if (is_null($valor) || (is_string($valor) && trim($valor) === '')) {

            return RespuestaDeCargaIa::faltan(['si la factura es sin IVA o con qué alícuota (21 %, 10,5 % o 27 %)']);
        }

        if (is_bool($valor)) {

            return $valor
                ? RespuestaDeCargaIa::faltan(['con qué alícuota de IVA es la factura (21 %, 10,5 % o 27 %)'])
                : self::SIN_IVA;
        }

        $texto = mb_strtolower(trim((string) $valor));

        if (in_array($texto, ['sin_iva', 'sin iva', 'siniva', 'no', 'ninguno', 'exento', '0', '0.0', '0,0'], true)) {

            return self::SIN_IVA;
        }

        $texto = str_replace([',', '%', ' '], ['.', '', ''], $texto);

        if (is_numeric($texto)) {

            $porcentaje = (float) $texto;

            foreach (self::ALICUOTAS as $alicuota) {

                if (abs($porcentaje - (float) $alicuota) < 0.001) {

                    return $alicuota;
                }
            }
        }

        return RespuestaDeCargaIa::error('El IVA de la factura tiene que ser "sin_iva", 21, 10.5 o 27. Si es otra alícuota, la compra se carga desde Compras.');
    }

    /**
     * `cuenta_corriente` del input como sí/no, con TRUE por defecto: lo normal de una compra a un
     * proveedor es que vaya a su cuenta corriente (el formulario la crea prendida). Solo un no
     * explícito (false, 0, "false", "no") la apaga.
     *
     * @param  mixed  $valor
     * @return bool
     */
    protected static function cuenta_corriente_del_input($valor)
    {
        if (is_null($valor)) {

            return true;
        }

        if (is_bool($valor)) {

            return $valor;
        }

        if (is_int($valor) || is_float($valor)) {

            return (int) $valor !== 0;
        }

        return !in_array(mb_strtolower(trim((string) $valor)), ['false', '0', 'no'], true);
    }

    /**
     * true si el proveedor tiene su cuenta corriente en pesos (la que usa la compra, moneda 1).
     *
     * @param  \App\Models\Provider  $proveedor
     * @return bool
     */
    protected static function tiene_cuenta_en_pesos(Provider $proveedor)
    {
        return CreditAccount::where('model_name', 'provider')
                            ->where('model_id', (int) $proveedor->id)
                            ->where('moneda_id', 1)
                            ->exists();
    }
}
