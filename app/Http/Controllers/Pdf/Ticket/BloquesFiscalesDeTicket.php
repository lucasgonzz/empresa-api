<?php

namespace App\Http\Controllers\Pdf\Ticket;

use App\Http\Controllers\Helpers\Afip\AfipImportesResolver;
use App\Http\Controllers\Helpers\Afip\LeyendaIsibCabaHelper;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use Carbon\Carbon;

/**
 * Los tres bloques fijos de una factura de ARCA en un ticket de comandera (misión
 * diseno-ticket-comandera, 9/10/2026, decisión D8 y §4 del plan). Se mueven en el diseño pero no
 * se sacan, y van siempre a lo ancho del rollo:
 *
 * - `afip_emisor`: razón social, domicilio comercial, CUIT, IIBB, inicio de actividades, condición
 *   frente al IVA, el tipo de comprobante ("FACTURA B"), su código, punto de venta y número, y la
 *   fecha. Es lo que en la hoja imprime el encabezado (`AfipPdfHelper::header()`).
 * - `afip_receptor`: lo del cliente que pide ARCA, con la regla del receptor de la hoja
 *   (`AfipPdfHelper::print_receptor_block()`): nombre, CUIT (o DNI si no tiene), condición frente
 *   al IVA, domicilio y condición de venta.
 * - `afip_pie`: el IVA según la letra (discriminado en una A, contenido en una B), la leyenda de
 *   ingresos brutos de CABA si corresponde (`LeyendaIsibCabaHelper`), el CAE con su vencimiento y
 *   el QR, con el mismo `GS ( k` del Ticket 2.0 de siempre.
 *
 * Los importes salen de `AfipImportesResolver::resolve()`, la MISMA fuente que el cuadro de
 * importes de la factura A4 (prioriza lo que se informó al autorizar; si no, recalcula en modo
 * tolerante: es una reimpresión).
 *
 * 🔴 DIFERENCIA DECLARADA CON EL TICKET 2.0 DE SIEMPRE. Aquél imprime "TRANSPARENCIA FISCAL AL
 * CONSUMIDOR LEY 27743 / Iva contenido" en TODA letra y con el NETO GRAVADO en lugar del IVA
 * (hallazgo 1 de la misión leyenda-isib-caba, 24/9/2026). Acá el IVA contenido es el IVA
 * (`importes.iva`) y sale solo en la factura B (la que recibe el consumidor final); la A lo lleva
 * discriminado por alícuota, como la hoja. El Ticket 2.0 de siempre no se toca (decisión D1).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class BloquesFiscalesDeTicket
{
    /** @var \App\Models\Sale */
    private $sale;

    /** @var \App\Models\AfipTicket La factura (con CAE) que se imprime. */
    private $afip_ticket;

    /** @var AfipHelper|null Para recalcular los importes de un comprobante sin lo informado guardado. */
    private $afip_helper;

    /** @var int Caracteres por renglón del rollo. */
    private $ancho;

    /** @var bool "Imprimir con fecha actual" del perfil. */
    private $use_current_date;

    /**
     * @param \App\Models\Sale       $sale
     * @param \App\Models\AfipTicket $afip_ticket
     * @param AfipHelper|null        $afip_helper
     * @param int                    $ancho            caracteres por renglón.
     * @param bool                   $use_current_date
     */
    public function __construct($sale, $afip_ticket, $afip_helper, $ancho, $use_current_date = false)
    {
        $this->sale = $sale;
        $this->afip_ticket = $afip_ticket;
        $this->afip_helper = $afip_helper;
        $this->ancho = (int) $ancho;
        $this->use_current_date = (bool) $use_current_date;
    }

    /**
     * Las piezas de un bloque fijo, o [] si la key no es de uno de los tres.
     *
     * @param array $item el fijo del diseño ({tipo: fijo, key, importes?}).
     * @return array<int, array>
     */
    public function piezas($item)
    {
        $key = isset($item['key']) ? $item['key'] : null;

        if ($key === 'afip_emisor') {
            return $this->emisor();
        }

        if ($key === 'afip_receptor') {
            return $this->receptor();
        }

        if ($key === 'afip_pie') {
            return $this->pie(! array_key_exists('importes', $item) || (bool) $item['importes']);
        }

        return [];
    }

    /**
     * El emisor de la factura (lo que la hoja pone en el encabezado).
     *
     * @return array<int, array>
     */
    public function emisor()
    {
        $afip_information = $this->afip_ticket->afip_information;

        /**
         * Un ticket viejo sin la configuración vinculada igual guardó, en sus columnas, el CUIT y el
         * punto de venta (como el ticket PDF de siempre, SaleTicketPdf::afipInformation()).
         */
        $cuit = ! is_null($afip_information) ? (string) $afip_information->cuit : (string) $this->afip_ticket->cuit_negocio;

        $renglones = [];

        if (! is_null($afip_information)) {
            $this->agregar($renglones, $afip_information->razon_social);
            $this->agregar($renglones, $afip_information->domicilio_comercial);
        }

        $this->agregar($renglones, $cuit, 'CUIT');

        if (! is_null($afip_information)) {
            $this->agregar($renglones, $afip_information->ingresos_brutos, 'IIBB');
            $this->agregar($renglones, $this->fecha($afip_information->inicio_actividades), 'Inicio de actividades');
        }

        $this->agregar($renglones, $this->afip_ticket->condicion_iva_del_emisor());

        /** "FACTURA B" en negrita: el tipo de comprobante y su letra, como el Ticket 2.0. */
        $this->agregar($renglones, $this->titulo_del_comprobante(), null, true);
        $this->agregar($renglones, str_pad((string) (int) $this->afip_ticket->cbte_tipo, 3, '0', STR_PAD_LEFT), 'Código');
        $this->agregar(
            $renglones,
            str_pad((string) $this->afip_ticket->punto_venta, 5, '0', STR_PAD_LEFT).'-'.str_pad((string) $this->afip_ticket->cbte_numero, 8, '0', STR_PAD_LEFT),
            'Comprobante N°'
        );
        $this->agregar($renglones, $this->fecha_de_emision(), 'Fecha');

        $renglones[] = PiezasDeTicket::guiones($this->ancho);

        return PiezasDeTicket::renglones($renglones);
    }

    /**
     * El cliente que pide ARCA (regla de AfipPdfHelper::print_receptor_block()). Sin cliente, la
     * factura es a consumidor final.
     *
     * @return array<int, array>
     */
    public function receptor()
    {
        $client = $this->sale->client;
        $renglones = [];

        if (is_null($client)) {
            $this->agregar($renglones, 'Consumidor final', 'Cliente');
        } else {
            $this->agregar($renglones, $client->name, 'Cliente');

            /** El CUIT y, si no tiene, el DNI (como el receptor de la factura A4). */
            $cuit = trim((string) $client->cuit);
            if ($cuit !== '') {
                $this->agregar($renglones, $cuit, 'CUIT');
            } else {
                $this->agregar($renglones, $client->dni, 'DNI');
            }
        }

        $this->agregar($renglones, $this->condicion_iva_del_cliente(), 'Condición IVA');

        if (! is_null($client)) {
            $this->agregar($renglones, $client->address, 'Domicilio');
        }

        $this->agregar($renglones, $this->sale->current_acount ? 'Cuenta corriente' : 'Contado', 'Condición de venta');

        $renglones[] = PiezasDeTicket::guiones($this->ancho);

        return PiezasDeTicket::renglones($renglones);
    }

    /**
     * El pie fiscal: el IVA según la letra (si el diseño lo muestra), la leyenda ISIB CABA, el CAE y
     * el QR.
     *
     * @param bool $con_importes el `importes` del fijo (el cuadro de IVA se puede apagar; el CAE y el
     *                           QR no).
     * @return array<int, array>
     */
    public function pie($con_importes = true)
    {
        $renglones = [];

        if ($con_importes) {
            foreach ($this->renglones_de_iva() as $texto) {
                $this->agregar($renglones, $texto);
            }
        }

        foreach (LeyendaIsibCabaHelper::partes($this->afip_ticket) as $parte) {
            $this->agregar($renglones, $parte, null, true);
        }

        $this->agregar($renglones, $this->afip_ticket->cae, 'CAE');
        $this->agregar($renglones, $this->vencimiento_del_cae(), 'Vto. CAE');

        $piezas = PiezasDeTicket::renglones($renglones);
        $piezas[] = PiezasDeTicket::qr(self::link_del_qr($this->afip_ticket));

        return $piezas;
    }

    /**
     * El link del QR de ARCA de un comprobante: el MISMO que arman AfipTicketController::get_importes()
     * (el del Ticket 2.0 de siempre) y AfipPdfHelper::print_afip_qr_image() (la factura A4).
     *
     * @param \App\Models\AfipTicket $afip_ticket
     * @return string
     */
    public static function link_del_qr($afip_ticket)
    {
        $datos = [
            'ver' => 1,
            'fecha' => date_format($afip_ticket->created_at, 'Y-m-d'),
            'cuit' => $afip_ticket->cuit_negocio,
            'ptoVta' => $afip_ticket->punto_venta,
            'tipoCmp' => $afip_ticket->cbte_tipo,
            'nroCmp' => $afip_ticket->cbte_numero,
            'importe' => $afip_ticket->importe_total,
            'moneda' => $afip_ticket->moneda_id,
            'ctz' => 1,
            'tipoDocRec' => AfipHelper::getDocType('Cuit'),
            'nroDocRec' => $afip_ticket->cuit_cliente,
            'codAut' => $afip_ticket->cae,
        ];

        return 'https://www.afip.gob.ar/fe/qr/?'.base64_encode(json_encode($datos));
    }

    /**
     * Los renglones del IVA según la letra: en la A, neto gravado y una línea por alícuota (como el
     * cuadro de importes de la hoja); en la B, el IVA contenido del régimen de transparencia fiscal
     * (Ley 27.743); en las demás (C, exportación), nada.
     *
     * @return array<int, string>
     */
    private function renglones_de_iva()
    {
        $letra = strtoupper(trim((string) $this->afip_ticket->cbte_letra));

        if ($letra !== 'A' && $letra !== 'B') {
            return [];
        }

        $importes = AfipImportesResolver::resolve($this->afip_ticket, $this->afip_helper);
        $moneda_id = $this->sale->moneda_id;

        if ($letra === 'A') {
            $renglones = ['Neto gravado: '.Numbers::price($importes['gravado'], true, $moneda_id)];

            foreach (AfipImportesResolver::renglones_de_iva($importes) as $renglon) {
                $renglones[] = 'IVA '.$renglon['etiqueta'].'%: '.Numbers::price($renglon['importe'], true, $moneda_id);
            }

            return $renglones;
        }

        return [
            'Régimen de Transparencia Fiscal al Consumidor (Ley 27.743)',
            'IVA contenido: '.Numbers::price(isset($importes['iva']) ? $importes['iva'] : 0, true, $moneda_id),
        ];
    }

    /**
     * Condición frente al IVA del cliente: la que guardó la factura al emitirse; si no, la de su
     * ficha; si no, consumidor final (como "Iva Cliente" del Ticket 2.0).
     *
     * @return string
     */
    private function condicion_iva_del_cliente()
    {
        $guardada = trim((string) $this->afip_ticket->iva_cliente);
        if ($guardada !== '') {
            return $guardada;
        }

        $client = $this->sale->client;
        if (! is_null($client) && $client->iva_condition) {
            return (string) $client->iva_condition->name;
        }

        return 'Consumidor final';
    }

    /**
     * "FACTURA B": el nombre del tipo de comprobante (que ya trae la letra) en mayúsculas o, sin él,
     * el rótulo inferido por el código más la letra.
     *
     * @return string
     */
    private function titulo_del_comprobante()
    {
        $tipo = $this->afip_ticket->afip_tipo_comprobante;

        if ($tipo && trim((string) $tipo->name) !== '') {
            return mb_strtoupper(trim((string) $tipo->name), 'UTF-8');
        }

        return trim(AfipPdfHelper::get_tipo_comprobante_label($this->afip_ticket, $this->sale).' '.$this->afip_ticket->cbte_letra);
    }

    /**
     * Fecha de emisión: la del comprobante, o la de hoy con "Imprimir con fecha actual" (la regla de
     * AfipPdfHelper::header()).
     *
     * @return string
     */
    private function fecha_de_emision()
    {
        if ($this->use_current_date || is_null($this->afip_ticket->created_at)) {
            return now()->format('d/m/Y');
        }

        return Carbon::parse($this->afip_ticket->created_at)->format('d/m/Y');
    }

    /**
     * Vencimiento del CAE (d/m/Y), con el formato viejo guardado como texto tal cual (la regla de
     * AfipPdfHelper::cae_expired_at_label()).
     *
     * @return string|null
     */
    private function vencimiento_del_cae()
    {
        $vencimiento = $this->afip_ticket->cae_expired_at;

        if ($vencimiento instanceof \DateTimeInterface) {
            return $vencimiento->format('d/m/Y');
        }

        $texto = trim((string) $vencimiento);
        if ($texto === '') {
            return null;
        }

        try {
            return Carbon::parse($texto)->format('d/m/Y');
        } catch (\Exception $e) {
            return substr($texto, 0, 10);
        }
    }

    /**
     * Una fecha (d/m/Y) o null.
     *
     * @param mixed $fecha
     * @return string|null
     */
    private function fecha($fecha)
    {
        if (is_null($fecha) || $fecha === '') {
            return null;
        }

        return $fecha instanceof \DateTimeInterface ? $fecha->format('d/m/Y') : Carbon::parse($fecha)->format('d/m/Y');
    }

    /**
     * Agrega los renglones de un dato ("Rótulo: valor", partido a lo ancho del rollo). Un dato vacío
     * no agrega nada (ni el rótulo).
     *
     * @param array       $renglones por referencia.
     * @param mixed       $valor
     * @param string|null $rotulo
     * @param bool        $negrita
     * @return void
     */
    private function agregar(&$renglones, $valor, $rotulo = null, $negrita = false)
    {
        $texto = trim(TextoDeTicket::limpiar($valor));

        if ($texto === '') {
            return;
        }

        if (! is_null($rotulo)) {
            $texto = TextoDeTicket::limpiar($rotulo).': '.$texto;
        }

        foreach (PiezasDeTicket::texto_partido($texto, $this->ancho, $negrita) as $renglon) {
            $renglones[] = PiezasDeTicket::sin_relleno_al_final($renglon);
        }
    }
}
