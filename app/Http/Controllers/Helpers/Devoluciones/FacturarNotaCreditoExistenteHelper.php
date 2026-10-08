<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Models\AfipTicket;
use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;

/**
 * Factura ante ARCA una nota de crédito de cliente que YA estaba guardada sin facturar.
 *
 * 🔴 Existe por CF (7/10/2026): la devolución de la venta N° 1488 se guardó el 1/10 sin pasar por
 * ARCA (la nota de crédito N° 128 quedó con su stock repuesto y su crédito en la cuenta corriente,
 * pero sin ningún comprobante). Hasta ahora `AfipNotaCreditoHelper` solo se invocaba desde
 * `DevolucionesController::store`, en el mismo request que crea la NC, así que una NC guardada sin
 * facturar no tenía forma de facturarse: había que eliminarla y rehacerla, y mientras existía el
 * tope de ValidarDevolucionHelper rechazaba la segunda devolución ("1 vendidas y 1 ya devueltas").
 *
 * Acá se reutiliza el mismo emisor que usa la devolución, sobre la NC existente y la factura de su
 * venta. No toca stock ni cuenta corriente: eso ya se hizo cuando se creó la nota.
 */
class FacturarNotaCreditoExistenteHelper {

    /**
     * Tipos de comprobante de factura sobre los que AfipNotaCreditoHelper sabe emitir una NC
     * (A, B, C, sus equivalentes de crédito electrónico y exportación E).
     *
     * @var array
     */
    const TIPOS_DE_FACTURA = ['1', '6', '11', '201', '206', '211', '19'];

    /**
     * Tolerancia en pesos entre el total de las NC y el de la factura (redondeos). Es la misma que
     * usa la pantalla de Devoluciones (BtnGuardar::check_venta).
     *
     * @var int
     */
    const TOLERANCIA_SOBRE_LA_FACTURA = 2;

    /**
     * Valida y emite. Adentro de una transacción con candado sobre la NC: un doble clic espera al
     * primero y recién ahí ve su comprobante (con CAE o con número) y se rechaza.
     *
     * @param  int       $nota_credito_id   id del movimiento de cuenta corriente (status nota_credito)
     * @param  int       $user_id           dueño (userId() del request)
     * @param  int|null  $afip_ticket_id    factura elegida; null = la única autorizada de la venta
     * @return \App\Models\CurrentAcount    la NC recargada con su comprobante y los errores de ARCA
     * @throws NotaCreditoNoFacturableException  si la regla de negocio no deja facturarla
     * @throws \Throwable                        si falla el emisor (el controlador responde 500)
     */
    static function facturar($nota_credito_id, $user_id, $afip_ticket_id = null) {

        DB::beginTransaction();

        try {

            $nota_credito = CurrentAcount::where('id', $nota_credito_id)
                                ->where('user_id', $user_id)
                                ->where('status', 'nota_credito')
                                ->lockForUpdate()
                                ->first();

            if (is_null($nota_credito)) {
                throw new NotaCreditoNoFacturableException('No se encontró la nota de crédito.');
            }

            /*
             * Con o sin cliente (pedido de Lucas, 8/10/2026): una venta a consumidor final, o una
             * devolución con "Generar movimiento en C/C" destildado, deja la nota con `client_id`
             * NULL y igual se factura (el emisor ya sabe declarar un receptor "NR"). Lo que no se
             * factura acá es una nota de proveedor ni una nota libre sin venta.
             */
            if (is_null($nota_credito->sale_id) || !is_null($nota_credito->provider_id)) {
                throw new NotaCreditoNoFacturableException('Esta nota de crédito no está atada a una venta: solo se factura sobre la factura de una venta.');
            }

            Self::exigir_que_no_este_facturada($nota_credito);

            $factura = Self::elegir_factura($nota_credito, $afip_ticket_id);

            /*
             * La nota de exportación (factura E, tipo 19) declara el país de destino del cliente
             * (`pais_exportacion`): sin cliente, o con un cliente sin país, el emisor no tiene de
             * dónde sacarlo y reventaría con un 500 sin explicación.
             */
            if (
                (string) $factura->cbte_tipo === '19'
                && (
                    is_null($nota_credito->sale)
                    || is_null($nota_credito->sale->client)
                    || is_null($nota_credito->sale->client->pais_exportacion)
                )
            ) {
                throw new NotaCreditoNoFacturableException('La factura es de exportación: la nota de crédito necesita que la venta tenga cliente (con su país de destino).');
            }

            Self::exigir_que_el_total_cierre($nota_credito, $factura);

            // makeWith y no `new`: los tests lo reemplazan por un doble que no sale a la red.
            $emisor = app()->makeWith(AfipNotaCreditoHelper::class, [
                'afip_ticket'   => $factura,
                'nota_credito'  => $nota_credito,
            ]);

            try {

                $emisor->init();

            } catch (\Throwable $e) {

                /*
                 * 🔴 Si ARCA ya autorizó la nota (el comprobante tiene CAE) y lo que falló es algo
                 * posterior (por ejemplo restar el total facturado de la venta), revertir la
                 * transacción borraría el rastro de una nota que YA existe en ARCA, y el "volvé a
                 * intentar" empujaría a emitir otra encima. Se conserva lo que ya quedó escrito y el
                 * fallo se reporta aparte. Sin CAE no hay nada que conservar: se relanza.
                 */
                $con_cae = AfipTicket::where('nota_credito_id', $nota_credito->id)
                                ->whereNotNull('cae')
                                ->where('cae', '<>', '')
                                ->exists();

                if (!$con_cae) {
                    throw $e;
                }

                report($e);
            }

            DB::commit();

        } catch (\Throwable $e) {

            DB::rollBack();

            throw $e;
        }

        return CurrentAcount::where('id', $nota_credito->id)
                    ->with('afip_ticket.afip_errors', 'afip_ticket.afip_observations', 'sale.afip_tickets', 'articles', 'discounts', 'surchages', 'nota_credito_descriptions', 'client', 'provider')
                    ->first();
    }

    /**
     * Rechaza si la NC ya tiene un comprobante que no sea un intento fallido sin número.
     *
     * Un comprobante con CAE ya está facturado. Uno con número pero sin CAE ya llegó a ARCA y está
     * pendiente de confirmación: se consulta (botón Consultar), no se emite otro encima, porque
     * saldrían dos notas de crédito por la misma devolución.
     *
     * @param  \App\Models\CurrentAcount  $nota_credito
     * @return void
     * @throws NotaCreditoNoFacturableException
     */
    static function exigir_que_no_este_facturada($nota_credito) {

        $existentes = AfipTicket::where('nota_credito_id', $nota_credito->id)->get();

        foreach ($existentes as $existente) {

            if (!is_null($existente->cae) && $existente->cae !== '') {
                throw new NotaCreditoNoFacturableException('Esta nota de crédito ya está facturada ante ARCA (N° '.$existente->cbte_numero.').');
            }

            if (!is_null($existente->cbte_numero) && $existente->cbte_numero !== '') {
                throw new NotaCreditoNoFacturableException('Esta nota de crédito ya se envió a ARCA con el N° '.$existente->cbte_numero.' y está pendiente de confirmación: usá el botón Consultar en lugar de facturarla de nuevo.');
            }
        }
    }

    /**
     * Facturas de la venta sobre las que se puede emitir una NC: autorizadas (con CAE), de un tipo
     * soportado y que no sean ellas mismas una nota de crédito.
     *
     * @param  int  $sale_id
     * @return \Illuminate\Support\Collection
     */
    static function facturas_de_la_venta($sale_id) {

        return AfipTicket::where('sale_id', $sale_id)
                    ->whereNull('nota_credito_id')
                    ->whereNotNull('cae')
                    ->where('cae', '<>', '')
                    ->whereIn('cbte_tipo', Self::TIPOS_DE_FACTURA)
                    ->orderBy('id')
                    ->get();
    }

    /**
     * La factura sobre la que se emite: la elegida (tiene que ser de la venta de la NC) o, sin
     * elección, la única autorizada de la venta.
     *
     * @param  \App\Models\CurrentAcount  $nota_credito
     * @param  int|null                   $afip_ticket_id
     * @return \App\Models\AfipTicket
     * @throws NotaCreditoNoFacturableException
     */
    static function elegir_factura($nota_credito, $afip_ticket_id) {

        $facturas = Self::facturas_de_la_venta($nota_credito->sale_id);

        if ($facturas->isEmpty()) {
            throw new NotaCreditoNoFacturableException('La venta de esta nota de crédito no tiene una factura autorizada por ARCA sobre la cual emitirla.');
        }

        if (!is_null($afip_ticket_id) && $afip_ticket_id !== '') {

            $elegida = $facturas->firstWhere('id', (int) $afip_ticket_id);

            if (is_null($elegida)) {
                throw new NotaCreditoNoFacturableException('La factura elegida no corresponde a la venta de esta nota de crédito.');
            }

            return $elegida;
        }

        if ($facturas->count() > 1) {
            throw new NotaCreditoNoFacturableException('La venta tiene más de una factura: elegí sobre cuál emitir la nota de crédito.');
        }

        return $facturas->first();
    }

    /**
     * El `haber` de la NC está en la moneda de su cuenta; el `importe_total` de una factura interna
     * (A, B, C) está en PESOS aunque la venta sea en dólares (el calculador convierte con
     * `valor_dolar`). Solo la factura de exportación (tipo 19) queda en dólares. Sin cotización
     * (venta vieja) no se inventa una: queda el valor tal cual.
     *
     * @param  \App\Models\CurrentAcount  $nota_credito
     * @param  \App\Models\AfipTicket     $factura
     * @return float
     */
    static function total_en_la_moneda_de_la_factura($nota_credito, $factura) {

        $total = (float) $nota_credito->haber;

        $venta = $nota_credito->sale;

        if (
            !is_null($venta)
            && (int) $venta->moneda_id == 2
            && (string) $factura->cbte_tipo !== '19'
            && (float) $venta->valor_dolar > 0
        ) {
            return $total * (float) $venta->valor_dolar;
        }

        return $total;
    }

    /**
     * El total de la NC más lo ya facturado en notas de crédito sobre esa misma factura no puede
     * superar el total de la factura (más la tolerancia de redondeo).
     *
     * @param  \App\Models\CurrentAcount  $nota_credito
     * @param  \App\Models\AfipTicket     $factura
     * @return void
     * @throws NotaCreditoNoFacturableException
     */
    static function exigir_que_el_total_cierre($nota_credito, $factura) {

        $ya_facturado = (float) AfipTicket::where('sale_afip_ticket_id', $factura->id)
                                ->whereNotNull('cae')
                                ->where('cae', '<>', '')
                                ->sum('importe_total');

        $total_de_la_nota = Self::total_en_la_moneda_de_la_factura($nota_credito, $factura);

        $tope = (float) $factura->importe_total + Self::TOLERANCIA_SOBRE_LA_FACTURA;

        if ($total_de_la_nota + $ya_facturado > $tope) {

            throw new NotaCreditoNoFacturableException(
                'El total de la nota de crédito ($'.number_format($total_de_la_nota, 2, ',', '.').') sumado a lo ya acreditado sobre la Factura N° '.$factura->cbte_numero
                .' ($'.number_format($ya_facturado, 2, ',', '.').') supera el total de la factura ($'.number_format((float) $factura->importe_total, 2, ',', '.').').'
            );
        }
    }
}
