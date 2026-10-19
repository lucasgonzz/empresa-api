<?php

namespace App\Http\Controllers\Helpers\PdfLayout;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use Carbon\Carbon;

/**
 * Valores de los campos del catálogo de TICKET DE COMANDERA (`CatalogoDeCamposPdf::campos('sale',
 * true)`) para una venta (misión diseno-ticket-comandera, 9/10/2026, contrato §3.4 del plan).
 *
 * Es el catálogo de venta de siempre más la categoría "Negocio" (`negocio_*`): esta fuente resuelve
 * esos campos y le deja TODOS los demás a `CamposDeVentaPdf`, que es la misma fuente del PDF con
 * cajas. Así "todas las propiedades de la venta del A4" salen en el ticket con los mismos valores y
 * el mismo formato (decisión D3 del plan).
 *
 * Los datos del negocio son los MISMOS que imprime el encabezado del PDF de siempre
 * (`AfipPdfHelper::build_emisor_field_values()`):
 * - la configuración de ARCA del comprobante que se imprime (una factura), o la de la sucursal de
 *   la venta, o la del dueño (`AfipPdfHelper::resolve_emisor_afip_information()`);
 * - el logo de la sucursal de la venta o el del dueño (`AfipPdfHelper::resolve_logo_url()`);
 * - el teléfono y el email del dueño, y la web de su tienda (`users.online`, como PdfHelper).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class CamposDeTicketPdf implements FuenteDeCamposPdf
{
    /** @var \App\Models\Sale */
    private $sale;

    /** @var \App\Models\User|null Dueño de la venta (`sales.user_id`). */
    private $user;

    /** @var \App\Models\AfipTicket|null La factura que se imprime, o null en un ticket remito. */
    private $ticket_impreso;

    /** @var CamposDeVentaPdf La fuente de siempre, para todo lo que no es del negocio. */
    private $venta;

    /** @var bool Ya se resolvió la configuración de ARCA del emisor (memo). */
    private $afip_information_resuelta;

    /** @var \App\Models\AfipInformation|null La configuración de ARCA del emisor. */
    private $afip_information;

    /**
     * @param \App\Models\Sale            $sale
     * @param \App\Models\User|null       $user                  Dueño de la venta.
     * @param bool                        $use_current_date      "Imprimir con fecha actual" del perfil.
     * @param string                      $discount_display_mode 'descriptivo' | 'simple'.
     * @param bool                        $es_factura            Se imprime como factura de ARCA.
     * @param \App\Models\AfipTicket|null $ticket_impreso        La factura que se imprime, o null.
     */
    public function __construct($sale, $user, $use_current_date, $discount_display_mode, $es_factura = false, $ticket_impreso = null)
    {
        $this->sale = $sale;
        $this->user = $user;
        $this->ticket_impreso = $ticket_impreso;
        $this->venta = new CamposDeVentaPdf($sale, $user, $use_current_date, $discount_display_mode, $es_factura, $ticket_impreso);
        $this->afip_information_resuelta = false;
        $this->afip_information = null;
    }

    /** @return string */
    public function model_name()
    {
        return 'sale';
    }

    /**
     * La plata del pie y los renglones de la tabla (los mismos de la fuente de venta).
     *
     * @return TotalesDeVentaPdf
     */
    public function totales()
    {
        return $this->venta->totales();
    }

    /**
     * @param string $key
     * @param array  $campo
     * @return string|array|null
     */
    public function valor($key, $campo)
    {
        if (strpos($key, 'negocio_') !== 0) {
            return $this->venta->valor($key, $campo);
        }

        switch ($key) {
            case CatalogoDeCamposPdf::KEY_NEGOCIO_LOGO:
                /** La URL del logo: el motor del ticket la pasa a bits de impresora. */
                return CamposDeVentaPdf::texto(AfipPdfHelper::resolve_logo_url($this->sale->address, $this->user));
            case 'negocio_nombre':
                return $this->user ? CamposDeVentaPdf::texto($this->user->company_name) : null;
            case 'negocio_razon_social':
                return $this->dato_de_arca('razon_social');
            case 'negocio_cuit':
                return $this->dato_de_arca('cuit');
            case 'negocio_condicion_iva':
                return $this->condicion_iva();
            case 'negocio_domicilio':
                return $this->domicilio();
            case 'negocio_ingresos_brutos':
                return $this->dato_de_arca('ingresos_brutos');
            case 'negocio_inicio_actividades':
                return $this->inicio_de_actividades();
            case 'negocio_telefono':
                return $this->user ? CamposDeVentaPdf::texto($this->user->phone) : null;
            case 'negocio_email':
                return $this->user ? CamposDeVentaPdf::texto($this->user->email) : null;
            case 'negocio_web':
                return ($this->user && ! is_null($this->user->online))
                    ? CamposDeVentaPdf::texto(PdfHelper::getWebUrl((string) $this->user->online))
                    : null;
        }

        throw new \InvalidArgumentException('Campo del ticket de comandera sin resolver: '.$key);
    }

    /**
     * La configuración de ARCA del emisor, con la regla del encabezado del PDF (memoizada: la piden
     * varios campos).
     *
     * @return \App\Models\AfipInformation|null
     */
    private function afip_information()
    {
        if (! $this->afip_information_resuelta) {
            $this->afip_information_resuelta = true;

            /** resolve_emisor_afip_information() lee $user->afip_information al final: sin dueño no hay. */
            if (! is_null($this->ticket_impreso) || ! is_null($this->user)) {
                $this->afip_information = AfipPdfHelper::resolve_emisor_afip_information($this->ticket_impreso, $this->sale, $this->user);
            }
        }

        return $this->afip_information;
    }

    /**
     * Una columna de texto de la configuración de ARCA, o null si no hay configuración o está vacía.
     *
     * @param string $columna
     * @return string|null
     */
    private function dato_de_arca($columna)
    {
        $afip_information = $this->afip_information();

        return is_null($afip_information) ? null : CamposDeVentaPdf::texto($afip_information->{$columna});
    }

    /**
     * Condición frente al IVA del emisor: en una factura, la MISMA que usa el calculador de la
     * factura (`AfipTicket::condicion_iva_del_emisor()`, como el ticket PDF de siempre), así el papel
     * no se contradice; en un remito, la de la configuración de ARCA.
     *
     * @return string|null
     */
    private function condicion_iva()
    {
        if (! is_null($this->ticket_impreso)) {
            return CamposDeVentaPdf::texto($this->ticket_impreso->condicion_iva_del_emisor());
        }

        $afip_information = $this->afip_information();

        return (! is_null($afip_information) && $afip_information->iva_condition)
            ? CamposDeVentaPdf::texto($afip_information->iva_condition->name)
            : null;
    }

    /**
     * El domicilio comercial de ARCA; si no está cargado, la sucursal de la venta.
     *
     * @return string|null
     */
    private function domicilio()
    {
        $domicilio = $this->dato_de_arca('domicilio_comercial');

        return is_null($domicilio) ? CamposDeVentaPdf::sucursal($this->sale->address) : $domicilio;
    }

    /**
     * Inicio de actividades (d/m/Y), solo si está cargado (como el encabezado de siempre).
     *
     * @return string|null
     */
    private function inicio_de_actividades()
    {
        $afip_information = $this->afip_information();

        if (is_null($afip_information) || is_null($afip_information->inicio_actividades) || $afip_information->inicio_actividades === '') {
            return null;
        }

        return $afip_information->inicio_actividades instanceof \DateTimeInterface
            ? $afip_information->inicio_actividades->format('d/m/Y')
            : Carbon::parse($afip_information->inicio_actividades)->format('d/m/Y');
    }
}
