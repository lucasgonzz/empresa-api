<?php

namespace App\Http\Controllers\Helpers\providerOrder;

use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use Illuminate\Support\Facades\DB;

/**
 * Lo que pasa alrededor de una factura de compra (`provider_order_afip_tickets`) cuando se la
 * guarda: sus dos totales, y el recálculo de la compra a la que cuelga.
 *
 * Misión `compras-factura-manual-alicuotas` (17/9/2026). Vive acá y no adentro de un controller
 * porque los DOS controllers de este circuito lo necesitan igual: `ProviderOrderAfipTicketController`
 * (la factura) y `ProviderOrderAfipTicketIvaController` (sus alícuotas, que son los sumandos del
 * total de la factura). Copiarlo en los dos era la clase de error de "dos altas que se van
 * separando solas" que ya está documentada en `ProviderOrderAltaHelper`.
 */
class FacturaDeCompraHelper
{
    /**
     * Calcula y guarda los totales de la factura. Salen del servidor: lo que mande el cliente en
     * `total` o en `total_iva` se ignora.
     *
     * CON desglose de IVA (el caso normal, el de una factura A):
     *
     *     total_iva = Σ(iva_importe de sus alícuotas)
     *     total     = Σ(neto + iva_importe de sus alícuotas) + percepcion_iibb + percepcion_iva
     *
     * 🔴 SIN desglose, el total NO ES DERIVABLE y NO SE PISA — solo se le mueven las percepciones:
     *
     *     total_iva = no se toca   (para un Monotributista vale `null`: el IVA "no aplica", que es
     *                               distinto de "el IVA da 0")
     *     total     = $total_base_sin_percepciones + percepcion_iibb + percepcion_iva
     *
     * Y hay comprobantes que por diseño no tienen esas filas:
     *
     *   · La factura automática de una cuenta **Monotributista**: un MT no discrimina IVA, así que
     *     `ModoFacturacionHelper::calcular_ticket_monotributista()` no crea ninguna
     *     `provider_order_afip_ticket_iva` y el total es un dato propio del comprobante.
     *   · El comprobante **aparte de un costo extra** sin `iva_id` (o de una cuenta MT), que guarda
     *     el bruto directo y borra sus filas — pasa también en una cuenta Responsable Inscripto.
     *   · Una **Factura C** escaneada, o cualquier comprobante donde la IA no llegó a leer el
     *     desglose.
     *
     * Sin esta rama, el caso concreto era: cuenta MT, compra automática de $1.000, el usuario
     * carga una percepción de $500 —de lo poco que en ese modo puede tocar—, Σ sobre cero filas da
     * 0, y la factura queda en **$500 en vez de $1.500**. `recalcular_compra()` bajaba detrás el
     * total de la compra y la deuda con el proveedor: mil pesos perdonados sin un error ni una
     * línea de log. Y el usuario ni siquiera tenía salida, porque crear la alícuota que salvaría la
     * cuenta se la rechaza el 422 del modo automático.
     *
     * ⚠️ La contracara, asumida a propósito: si una factura manual se queda sin su última alícuota,
     * conserva el total que tenía en vez de bajar a 0. Es lo correcto — un comprobante sin desglose
     * no tiene de dónde derivar su total, y bajarlo a 0 es exactamente la pérdida silenciosa de
     * plata que este invariante evita. Apenas se carga una alícuota, el desglose vuelve a mandar.
     *
     * 🔴 POR QUÉ EL TOTAL NO SE TOMA DEL REQUEST. Hasta esta misión `ProviderOrderAfipTicketController`
     * guardaba `$request->total` a ciegas. Dejar el campo de solo lectura en la pantalla y seguir
     * aceptando el número del request es una promesa a medias: un cliente viejo que todavía manda
     * el total calculado a su manera, o un POST directo, lo pisan igual y nadie se entera. Cuando
     * el total ES derivable, es una cuenta y no un dato que se carga.
     *
     * Las percepciones SUMAN al total porque son plata que el proveedor te cobra en su factura y
     * que le tenés que pagar: aumentan la deuda con él. No entran en `total_iva`, que es el crédito
     * fiscal de IVA — una percepción de IVA se computa aparte en la posición fiscal, no como IVA
     * compras.
     *
     * @param  \App\Models\ProviderOrderAfipTicket  $ticket
     * @param  float|null  $total_base_sin_percepciones  Cuánto vale el comprobante SIN sus
     *         percepciones, para el caso sin desglose. `null` lo deduce del propio ticket
     *         (`total - percepciones`), que es lo correcto siempre que las percepciones guardadas
     *         sigan siendo las que están incluidas en ese total. El llamador que acaba de pisarlas
     *         —o que tiene el total impreso del papel— lo calcula él y lo pasa.
     * @return \App\Models\ProviderOrderAfipTicket
     */
    public static function guardar_totales($ticket, $total_base_sin_percepciones = null)
    {
        // Load explícito (no lazy): al crear la factura, las alícuotas recién se le engancharon en
        // `updateRelationsCreated()`, y al editarla el modelo puede traer la relación cargada de
        // antes. Sin esto, la suma puede salir de una colección vieja.
        $ticket->load('provider_order_afip_ticket_ivas');

        $percepciones = self::percepciones($ticket);

        if (count($ticket->provider_order_afip_ticket_ivas) === 0) {

            $base = is_null($total_base_sin_percepciones)
                        ? (float) $ticket->total - $percepciones
                        : (float) $total_base_sin_percepciones;

            // `total_iva` NO se toca a propósito: sin filas no hay nada que sumar, y pisarlo con 0
            // le borraría el `null` del Monotributista, que es justamente lo que deja al frontend
            // distinguir "el IVA no aplica" de "el IVA dio 0" (que sí puede pasar en una cuenta
            // RRII con artículos exentos).
            $ticket->total = round($base + $percepciones, 2);
            $ticket->save();

            return $ticket;
        }

        $total_iva = 0;
        $total     = 0;

        foreach ($ticket->provider_order_afip_ticket_ivas as $iva) {

            $total_iva += (float) $iva->iva_importe;

            $total     += (float) $iva->neto + (float) $iva->iva_importe;
        }

        $total += $percepciones;

        // Redondeo a 2 decimales, que es la precisión real de las columnas (`decimal(x,2)`): sin
        // esto el modelo en memoria puede quedar con más decimales que la fila, y el que compara
        // los dos números ve una diferencia que no existe.
        $ticket->total_iva = round($total_iva, 2);
        $ticket->total     = round($total, 2);
        $ticket->save();

        return $ticket;
    }

    /**
     * Cuánto vale hoy este comprobante sin contar sus percepciones, para pasárselo después a
     * `guardar_totales()` como base.
     *
     * Se llama ANTES de pisarle las percepciones con las del request: una vez pisadas, el total
     * guardado y las percepciones del modelo ya no corresponden entre sí y la resta da cualquier
     * cosa.
     *
     * @param  \App\Models\ProviderOrderAfipTicket  $ticket
     * @return float
     */
    public static function total_base_sin_percepciones($ticket)
    {
        return (float) $ticket->total - self::percepciones($ticket);
    }

    /**
     * Lo que el proveedor te percibió en esta factura: IIBB + IVA.
     *
     * Vive en un método propio porque son TRES los caminos que arman el total de una factura y los
     * tres tienen que sumar exactamente esto: el manual (`guardar_totales()`, acá arriba), el
     * automático de un Responsable Inscripto y el de un Monotributista (los dos en
     * `ModoFacturacionHelper`, que calcula el comprobante desde los artículos de la compra). Que
     * cada uno hiciera su propia cuenta es lo que dejaba a la factura automática perdiendo la
     * percepción en cada guardado de la compra.
     *
     * Una percepción es plata que el proveedor te cobra a cuenta de un impuesto tuyo y que le
     * tenés que pagar a él: suma al total de la factura y a la deuda. No es crédito fiscal de IVA,
     * así que nunca entra en `total_iva`.
     *
     * @param  \App\Models\ProviderOrderAfipTicket  $ticket
     * @return float
     */
    public static function percepciones($ticket)
    {
        return (float) $ticket->percepcion_iibb + (float) $ticket->percepcion_iva;
    }

    /**
     * Recalcula el total de la compra padre y su cuenta corriente con el proveedor.
     *
     * 🔴 EL HUECO QUE CIERRA. Hasta hoy, crear, editar o borrar una factura (o una de sus
     * alícuotas) NO tocaba la compra: `ProviderOrderAfipTicketController` no instanciaba
     * `NewProviderOrderHelper` en ningún método y no hay Observer registrado para ese modelo
     * (`AppServiceProvider`). Con `total_from_provider_order_afip_tickets` prendido,
     * `set_totales()` arma el total de la compra sumando `afip_ticket->total` — o sea que cargar
     * una percepción cambiaba el total de la FACTURA y dejaba `provider_orders.total` y
     * `current_acounts.debe` con el número viejo hasta que alguien volviera a guardar la compra
     * entera a mano. La percepción sumaba en la pantalla de la factura y no sumaba en la deuda con
     * el proveedor, que es justo lo que el cliente pidió.
     *
     * 🔴 POR QUÉ `set_totales()` + `set_current_acount()` Y NO `procesar_pedido()`.
     * `procesar_pedido()` hace esas dos cosas, sí, pero en el medio corre
     * `materializar_descuentos_proveedor_en_articulos()` y
     * `aplicar_costos_extra_a_recargos_articulos()`, que escriben `article_discounts` y
     * `article_surchages` de cada artículo de la compra. Guardar una factura no es motivo para
     * re-materializar descuentos ni recargos sobre el catálogo: sería un efecto colateral invisible
     * sobre precios de venta, disparado desde una pantalla que dice "factura". Las dos que quedan
     * son exactamente las que mueven la plata que hace falta —`provider_orders.total` y
     * `current_acounts.debe`— y ninguna toca un artículo: `set_totales()` solo lee los pivots
     * (`get_total_article()` es de lectura pura) y escribe en la fila de la compra.
     *
     * No es un camino nuevo ni raro: `ModoFacturacionHelper::calcular_iva()` ya llama a
     * `$helper->set_totales()` suelto, fuera de `procesar_pedido()`, por un motivo parecido.
     *
     * 🔴 GUARDA DE RETROACTIVIDAD (decisión de Lucas, 17/9/2026). Esto corre SOLO cuando se guarda
     * esa factura puntual, desde el request del usuario. No hay comando, barrido ni migración que
     * recorra compras ya cargadas: ninguna compra vieja cambia de total ni mueve un saldo sola.
     *
     * Silencioso si no hay compra: una factura con `temporal_id` (creada en el formulario antes de
     * que exista la compra) no tiene `provider_order_id` todavía.
     *
     * @param  int|string|null  $provider_order_id
     * @return \App\Models\ProviderOrder|null La compra recalculada, o null si no había ninguna.
     */
    public static function recalcular_compra($provider_order_id)
    {
        if (is_null($provider_order_id) || $provider_order_id === '') {
            return null;
        }

        $provider_order = ProviderOrder::find($provider_order_id);

        if (is_null($provider_order)) {
            return null;
        }

        // `$new_articles` vacío a propósito: en el request de una factura no viene ningún artículo,
        // y ninguno de los dos métodos de abajo lo usa (es de `attach_articles()`, que acá no
        // corre). `ya_se_actualizo_stock` queda en false, así que el constructor tampoco arma el
        // mapa de cantidades previas: sin `attach_articles()` no hay delta de stock que calcular.
        $helper = new NewProviderOrderHelper($provider_order, []);

        /*
         * 🔴 En una transacción corta con el candado de la cuenta del proveedor como primera
         * sentencia (misión cuenta-corriente-carrera-y-velocidad, 23/9/2026). Los controllers de
         * facturas de compra que llegan acá no abren transacción, así que el candado de
         * set_current_acount() no hacía nada y la deuda se escribía sin serializar con las otras
         * escrituras de la misma cuenta. Si el llamador ya tiene una, esto es un savepoint.
         */
        DB::transaction(function () use ($helper, $provider_order) {

            CuentaCorrienteLock::bloquear('provider', $provider_order->provider_id);

            // Re-suma los totales de la compra. Con `total_from_provider_order_afip_tickets`
            // prendido, acá es donde entra el `total` nuevo de la factura, percepciones incluidas.
            $helper->set_totales();

            // Y lleva ese total a la deuda con el proveedor (`current_acounts.debe`). Es un no-op si
            // la compra tiene `generate_current_acount` apagado.
            $helper->set_current_acount();
        });

        return $provider_order;
    }

    /**
     * ¿La compra a la que cuelga esta factura factura en modo automático?
     *
     * En ese modo la factura entera la calcula el sistema desde los artículos de la compra
     * (`ModoFacturacionHelper`), así que sus alícuotas de IVA no se editan a mano: lo que se
     * escribiera se perdería en el próximo guardado de la compra, sin aviso.
     *
     * Devuelve `false` cuando no se puede resolver el padre (factura todavía sin compra, factura
     * inexistente): no se bloquea lo que no se puede comprobar.
     *
     * @param  int|string|null  $provider_order_afip_ticket_id
     * @return bool
     */
    public static function factura_en_modo_automatico($provider_order_afip_ticket_id)
    {
        if (is_null($provider_order_afip_ticket_id) || $provider_order_afip_ticket_id === '') {
            return false;
        }

        $ticket = ProviderOrderAfipTicket::find($provider_order_afip_ticket_id);

        if (is_null($ticket) || is_null($ticket->provider_order_id)) {
            return false;
        }

        $provider_order = ProviderOrder::find($ticket->provider_order_id);

        if (is_null($provider_order)) {
            return false;
        }

        /*
         * 🔴 Comparación ESTRICTA, contra el modo ya normalizado (misión
         * `factura-compra-tres-defectos`, 9/10/2026). Con `==` en PHP 7.4, `0 == 'automatico'` es
         * verdadero: una compra que hubiera quedado guardada con un modo entero se trataba como
         * automática en un lugar y como manual en otro. El criterio de qué es un modo válido vive
         * en un solo lugar, `ModoFacturacionHelper::normalizar()`.
         */
        return ModoFacturacionHelper::normalizar($provider_order->modo_facturacion) === ModoFacturacionHelper::AUTOMATICO;
    }

    /**
     * Borra facturas de compra CON sus alícuotas de IVA. Es el ÚNICO lugar que da de baja una
     * `provider_order_afip_ticket` (misión `factura-compra-tres-defectos`, 9/10/2026).
     *
     * 🔴 Por qué existe. Ninguna de las dos tablas tiene clave foránea —ni la factura hacia la
     * compra, ni la alícuota hacia la factura—, así que la base no borra nada en cascada: cada
     * `->delete()` suelto sobre una factura dejaba vivas sus alícuotas, y borrar la compra dejaba
     * vivas las facturas. Y las facturas huérfanas no son basura inofensiva: la Posición Fiscal y
     * el tablero leen las facturas por `user_id` + `issued_at`, así que una compra borrada seguía
     * sumando su IVA crédito y sus percepciones. Lo medido el 9/10/2026: había CINCO bajas
     * distintas (el destroy de la compra, el de la factura, el modo "sin factura", las facturas
     * sobrantes del modo automático y los comprobantes aparte de costos extra) y solo la última
     * se acordaba de las alícuotas. Por eso pasan todas por acá.
     *
     * Las alícuotas van primero: si algo corta a mitad, lo que queda es una factura sin desglose
     * —que se ve y se puede volver a borrar— y no un desglose colgando de una factura que ya no
     * existe, que no lo ve nadie.
     *
     * Lo que NO toca, a propósito: `retenciones_sufridas.provider_order_afip_ticket_id`. Esa tabla
     * ya prevé que la factura de origen se haya borrado (el listado hace left join y muestra la
     * retención sin link, ver `ContabilidadRepository`): una retención es un certificado que el
     * comercio tiene en la mano, no un dato que dependa de la factura.
     *
     * No abre transacción propia: los llamadores que borran algo más en el mismo paso (el destroy
     * de la compra) ya la tienen, y una baja de facturas sola son dos sentencias.
     *
     * @param  array<int,int|string>  $ids  Ids de `provider_order_afip_tickets`.
     * @return int  Cuántas facturas se borraron.
     */
    public static function borrar_facturas(array $ids)
    {
        $ids = array_values(array_filter($ids, function ($id) {
            return !is_null($id) && $id !== '';
        }));

        if (count($ids) === 0) {
            return 0;
        }

        ProviderOrderAfipTicketIva::whereIn('provider_order_afip_ticket_id', $ids)->delete();

        return ProviderOrderAfipTicket::whereIn('id', $ids)->delete();
    }

    /**
     * Borra TODAS las facturas de una compra con sus alícuotas: la principal, las que se hayan
     * cargado a mano y los comprobantes "aparte" de sus costos extra (también cuelgan de la compra
     * por `provider_order_id`, ver `ModoFacturacionHelper::sincronizar_tickets_costos_extra_aparte()`).
     *
     * @param  int|string|null  $provider_order_id
     * @return int  Cuántas facturas se borraron.
     */
    public static function borrar_facturas_de_la_compra($provider_order_id)
    {
        if (is_null($provider_order_id) || $provider_order_id === '') {
            return 0;
        }

        $ids = ProviderOrderAfipTicket::where('provider_order_id', $provider_order_id)
                                        ->pluck('id')
                                        ->all();

        return self::borrar_facturas($ids);
    }
}
