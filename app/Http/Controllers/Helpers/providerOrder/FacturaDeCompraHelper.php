<?php

namespace App\Http\Controllers\Helpers\providerOrder;

use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;

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
     * Calcula y guarda los dos totales de la factura. Los DOS salen del servidor: lo que mande el
     * cliente en `total` o en `total_iva` se ignora.
     *
     *     total_iva = Σ(iva_importe de sus alícuotas)
     *     total     = Σ(neto + iva_importe de sus alícuotas) + percepcion_iibb + percepcion_iva
     *
     * 🔴 POR QUÉ EL TOTAL NO SE TOMA DEL REQUEST. Hasta hoy `ProviderOrderAfipTicketController`
     * guardaba `$request->total` a ciegas. Dejar el campo de solo lectura en la pantalla y seguir
     * aceptando el número del request es una promesa a medias: un cliente viejo que todavía manda
     * el total calculado a su manera, o un POST directo, lo pisan igual y nadie se entera. El total
     * de una factura es una cuenta, no un dato que se carga — sus dos sumandos (las alícuotas y las
     * percepciones) ya están en la base.
     *
     * Las percepciones SUMAN al total porque son plata que el proveedor te cobra en su factura y
     * que le tenés que pagar: aumentan la deuda con él. No entran en `total_iva`, que es el crédito
     * fiscal de IVA — una percepción de IVA se computa aparte en la posición fiscal, no como IVA
     * compras.
     *
     * @param  \App\Models\ProviderOrderAfipTicket  $ticket
     * @return \App\Models\ProviderOrderAfipTicket
     */
    public static function guardar_totales($ticket)
    {
        // Load explícito (no lazy): al crear la factura, las alícuotas recién se le engancharon en
        // `updateRelationsCreated()`, y al editarla el modelo puede traer la relación cargada de
        // antes. Sin esto, la suma puede salir de una colección vieja.
        $ticket->load('provider_order_afip_ticket_ivas');

        $total_iva = 0;
        $total     = 0;

        foreach ($ticket->provider_order_afip_ticket_ivas as $iva) {

            $total_iva += (float) $iva->iva_importe;

            $total     += (float) $iva->neto + (float) $iva->iva_importe;
        }

        $total += self::percepciones($ticket);

        // Redondeo a 2 decimales, que es la precisión real de las columnas (`decimal(x,2)`): sin
        // esto el modelo en memoria puede quedar con más decimales que la fila, y el que compara
        // los dos números ve una diferencia que no existe.
        $ticket->total_iva = round($total_iva, 2);
        $ticket->total     = round($total, 2);
        $ticket->save();

        return $ticket;
    }

    /**
     * Lo que el proveedor te percibió en esta factura: IIBB + IVA.
     *
     * Vive en un método propio porque son DOS los caminos que arman el total de una factura y los
     * dos tienen que sumar exactamente esto: el manual (`guardar_totales()`, acá arriba) y el
     * automático (`ModoFacturacionHelper`, que calcula el comprobante desde los artículos de la
     * compra). Que cada uno hiciera su propia cuenta es lo que dejaba a la factura automática
     * perdiendo la percepción en cada guardado de la compra.
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

        // Re-suma los totales de la compra. Con `total_from_provider_order_afip_tickets` prendido,
        // acá es donde entra el `total` nuevo de la factura, percepciones incluidas.
        $helper->set_totales();

        // Y lleva ese total a la deuda con el proveedor (`current_acounts.debe`). Es un no-op si la
        // compra tiene `generate_current_acount` apagado.
        $helper->set_current_acount();

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

        return $provider_order->modo_facturacion == 'automatico';
    }
}
