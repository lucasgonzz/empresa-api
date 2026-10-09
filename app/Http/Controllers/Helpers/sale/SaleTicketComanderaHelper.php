<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\PdfLayout\CatalogoDeCamposPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDerivadoPdf;
use App\Http\Controllers\Pdf\Ticket\TicketComanderaEscPos;
use App\Models\AfipTicket;
use App\Models\PdfColumnProfile;
use App\Models\User;

/**
 * `GET api/sale/{sale_id}/ticket-comandera` (misión diseno-ticket-comandera, 9/10/2026, contrato
 * §3.6 del plan): el ticket de comandera de una venta armado con un diseño de ticket, en bytes
 * ESC/POS (base64) para que el Ticket 2.0 lo mande por QZ o por el agente, o en texto para la
 * vista previa del diseñador.
 *
 * Resolución del perfil:
 * - con `pdf_column_profile_id`: tiene que ser un ticket de comandera del dueño, de venta; si no,
 *   422 {message};
 * - sin él: los tickets del dueño de la clase que corresponde (factura si vino `afip_ticket_id` o
 *   la venta tiene un comprobante con CAE; remito si no), primero el "por defecto" y si no el de
 *   menor id; ninguno → sin perfil.
 *
 * Comprobante fiscal: el de `afip_ticket_id` (de esa venta y con CAE) o, si no, el más nuevo con
 * CAE de la venta (el criterio del PDF, decisión 12 de diseno-pdf-configurable).
 *
 * Respuesta 200: {disenado, perfil_id, es_factura, ancho_mm, caracteres_por_renglon,
 * payload_base64, lineas}.
 * - `disenado:false` sin perfil o con un perfil sin diseño (`page_layout` NULL): el SPA imprime el
 *   Ticket 2.0 de siempre (D1). `payload_base64` null. `es_factura` es el is_afip_ticket del perfil,
 *   o null sin perfil.
 * - `formato=texto`: suma `lineas` (el ticket en texto, con [LOGO] y [QR]). Con un perfil sin diseño
 *   se arma con el DISEÑO DERIVADO (lo que el diseñador muestra) y `disenado` sigue en false.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class SaleTicketComanderaHelper
{
    /** El valor de `formato` que pide la vista en texto. */
    const FORMATO_TEXTO = 'texto';

    /** El 422 de un `pdf_column_profile_id` que no es un ticket de venta del dueño. */
    const MENSAJE_NO_ES_TICKET = 'Ese diseño no es un ticket de comandera de venta de este negocio.';

    /**
     * Arma la respuesta del endpoint (la venta ya es del dueño: el controller da 404 si no).
     *
     * @param \App\Models\Sale $sale
     * @param int              $owner_id
     * @param mixed            $pdf_column_profile_id `?pdf_column_profile_id=`
     * @param mixed            $afip_ticket_id        `?afip_ticket_id=`
     * @param mixed            $formato               `?formato=` ('texto' o nada)
     * @return array{status: int, body: array}
     */
    public static function responder($sale, $owner_id, $pdf_column_profile_id, $afip_ticket_id, $formato)
    {
        $con_texto = $formato === self::FORMATO_TEXTO;
        $factura = self::factura_con_cae($sale, $afip_ticket_id);

        if (! is_null($pdf_column_profile_id) && $pdf_column_profile_id !== '') {
            $perfil = self::ticket_pedido($owner_id, $pdf_column_profile_id);

            if (is_null($perfil)) {
                return ['status' => 422, 'body' => ['message' => self::MENSAJE_NO_ES_TICKET]];
            }
        } else {
            $es_factura = ! empty($afip_ticket_id) || ! is_null($factura);
            $perfil = self::ticket_por_defecto($owner_id, $es_factura);
        }

        if (is_null($perfil)) {
            return ['status' => 200, 'body' => self::cuerpo(false, null, null, null, null, null)];
        }

        $disenado = DisenoDePaginaPdf::tiene_diseno($perfil);
        $es_factura = (bool) $perfil->is_afip_ticket;

        /** Sin diseño y sin pedido de texto: no hay nada que armar (imprime el de siempre). */
        if (! $disenado && ! $con_texto) {
            $ancho_mm = self::ancho_del_rollo($perfil);

            return ['status' => 200, 'body' => self::cuerpo(false, $perfil->id, $es_factura, $ancho_mm, CatalogoDeCamposPdf::caracteres_por_renglon($ancho_mm), null)];
        }

        /** La vista previa de un perfil sin diseño: el derivado, lo que el diseñador le muestra. */
        $diseno = $disenado
            ? null
            : DisenoDerivadoPdf::para('sale', $perfil, $es_factura, User::find($owner_id), true);

        try {
            $ticket = self::motor($sale, $perfil, $factura, $diseno);

            $cuerpo = self::cuerpo(
                $disenado,
                $perfil->id,
                $es_factura,
                $ticket->ancho_mm(),
                $ticket->caracteres_por_renglon(),
                $disenado ? base64_encode($ticket->bytes()) : null
            );

            if ($con_texto) {
                $cuerpo['lineas'] = $ticket->lineas();
            }

            return ['status' => 200, 'body' => $cuerpo];
        } catch (\Throwable $e) {
            /**
             * Si el diseño no se puede armar (un dato raro), el ticket tiene que salir igual: el SPA
             * imprime el Ticket 2.0 de siempre con disenado:false. El error no se traga: va al log.
             */
            report($e);

            $ancho_mm = self::ancho_del_rollo($perfil);

            return ['status' => 200, 'body' => self::cuerpo(false, $perfil->id, $es_factura, $ancho_mm, CatalogoDeCamposPdf::caracteres_por_renglon($ancho_mm), null)];
        }
    }

    /**
     * El motor del ticket (aparte, para que una subclase de prueba pueda reemplazarlo).
     *
     * @param \App\Models\Sale            $sale
     * @param PdfColumnProfile            $perfil
     * @param \App\Models\AfipTicket|null $factura
     * @param array|null                  $diseno
     * @return TicketComanderaEscPos
     */
    protected static function motor($sale, $perfil, $factura, $diseno)
    {
        return new TicketComanderaEscPos($sale, $perfil, $factura, $diseno);
    }

    /**
     * El ticket que se pidió por id: del dueño, de venta y de comandera.
     *
     * @param int   $owner_id
     * @param mixed $profile_id
     * @return PdfColumnProfile|null
     */
    public static function ticket_pedido($owner_id, $profile_id)
    {
        if (! is_numeric($profile_id) || (int) $profile_id <= 0) {
            return null;
        }

        return PdfColumnProfile::where('user_id', $owner_id)
            ->where('id', (int) $profile_id)
            ->where('model_name', CatalogoDeCamposPdf::MODELO_DE_TICKET)
            ->deTicket()
            ->with('sheet_type')
            ->first();
    }

    /**
     * El ticket por defecto de una clase: el marcado "por defecto" y, si no hay, el de menor id.
     *
     * @param int  $owner_id
     * @param bool $es_factura
     * @return PdfColumnProfile|null
     */
    public static function ticket_por_defecto($owner_id, $es_factura)
    {
        return PdfColumnProfile::where('user_id', $owner_id)
            ->where('model_name', CatalogoDeCamposPdf::MODELO_DE_TICKET)
            ->where('is_afip_ticket', (bool) $es_factura)
            ->deTicket()
            ->orderBy('is_default', 'desc')
            ->orderBy('id', 'asc')
            ->with('sheet_type')
            ->first();
    }

    /**
     * La factura con CAE de la venta: la pedida (si es de esa venta y tiene CAE) o la más nueva.
     *
     * @param \App\Models\Sale $sale
     * @param mixed            $afip_ticket_id
     * @return \App\Models\AfipTicket|null
     */
    public static function factura_con_cae($sale, $afip_ticket_id)
    {
        $con_cae = function () use ($sale) {
            return AfipTicket::where('sale_id', $sale->id)
                ->whereNotNull('cae')
                ->where('cae', '!=', '')
                ->with('afip_information', 'afip_tipo_comprobante');
        };

        if (is_numeric($afip_ticket_id) && (int) $afip_ticket_id > 0) {
            $pedida = $con_cae()->where('id', (int) $afip_ticket_id)->first();

            if (! is_null($pedida)) {
                return $pedida;
            }
        }

        return $con_cae()->orderBy('id', 'desc')->first();
    }

    /**
     * El ancho del rollo del perfil (su tipo de hoja; si no, su papel; si no, 80).
     *
     * @param PdfColumnProfile $perfil
     * @return int
     */
    private static function ancho_del_rollo($perfil)
    {
        if ($perfil->sheet_type && (int) $perfil->sheet_type->width > 0) {
            return (int) $perfil->sheet_type->width;
        }

        return (int) $perfil->paper_width_mm > 0 ? (int) $perfil->paper_width_mm : TicketComanderaEscPos::ANCHO_POR_DEFECTO_MM;
    }

    /**
     * El cuerpo de la respuesta, siempre con las mismas claves (contrato §3.6).
     *
     * @param bool        $disenado
     * @param int|null    $perfil_id
     * @param bool|null   $es_factura
     * @param int|null    $ancho_mm
     * @param int|null    $caracteres
     * @param string|null $payload_base64
     * @return array
     */
    private static function cuerpo($disenado, $perfil_id, $es_factura, $ancho_mm, $caracteres, $payload_base64)
    {
        return [
            'disenado' => (bool) $disenado,
            'perfil_id' => is_null($perfil_id) ? null : (int) $perfil_id,
            'es_factura' => is_null($es_factura) ? null : (bool) $es_factura,
            'ancho_mm' => is_null($ancho_mm) ? null : (int) $ancho_mm,
            'caracteres_por_renglon' => is_null($caracteres) ? null : (int) $caracteres,
            'payload_base64' => $payload_base64,
            'lineas' => null,
        ];
    }
}
