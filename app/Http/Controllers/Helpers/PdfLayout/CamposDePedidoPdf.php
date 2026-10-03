<?php

namespace App\Http\Controllers\Helpers\PdfLayout;

use App\Http\Controllers\Helpers\PdfDocument\OrderPdfDocument;
use App\Models\Address;
use App\Models\Seller;
use Carbon\Carbon;

/**
 * Valores de los campos del catálogo de PEDIDO ONLINE (`CatalogoDeCamposPdf::campos('order')`) para
 * un pedido de la tienda dibujado con un diseño de página (misión diseno-pdf-configurable,
 * 1/10/2026).
 *
 * El pedido no tiene `client`: tiene `buyer` (el comprador de la tienda), y sus campos salen con
 * los MISMOS criterios que el bloque del cliente del pedido de siempre
 * (`OrderPdfDocument::header_client()`): la dirección del pedido gana a la del comprador, el CUIT
 * sale del cliente del ERP vinculado.
 *
 * 🔴 La plata respeta la decisión D6 del pedido de siempre (ver `OrderPdfDocument`): `orders.total`
 * no incluye envío, cupón ni el ajuste del medio de pago, así que el "Total" solo sale cuando el
 * pedido no tiene ninguno de esos extras y el "Subtotal" solo cuando los tiene. Los dos salen de las
 * mismas piezas que `totals_rows()`.
 */
class CamposDePedidoPdf implements FuenteDeCamposPdf
{
    /** @var OrderPdfDocument */
    private $documento;

    /** @var \App\Models\Order */
    private $order;

    /** @var bool "Imprimir con fecha actual" del perfil. */
    private $use_current_date;

    /**
     * @param OrderPdfDocument $documento
     * @param bool             $use_current_date
     */
    public function __construct(OrderPdfDocument $documento, $use_current_date)
    {
        $this->documento = $documento;
        $this->order = $documento->order();
        $this->use_current_date = (bool) $use_current_date;
    }

    /** @return string */
    public function model_name()
    {
        return 'order';
    }

    /**
     * @param string $key
     * @param array  $campo
     * @return string|array|null
     */
    public function valor($key, $campo)
    {
        $buyer = $this->order->buyer;

        switch ($key) {
            case 'comprador_nombre':
                return $buyer ? CamposDeVentaPdf::texto(trim($buyer->name.' '.$buyer->surname)) : null;
            case 'comprador_telefono':
                return $buyer ? CamposDeVentaPdf::texto($buyer->phone) : null;
            case 'comprador_email':
                return $buyer ? CamposDeVentaPdf::texto($buyer->email) : null;
            case 'comprador_direccion':
                /** La dirección del pedido (la de entrega) gana; si no la trae, la del comprador. */
                $direccion = CamposDeVentaPdf::texto($this->order->address);
                if (! is_null($direccion)) {
                    return $direccion;
                }

                return $buyer ? CamposDeVentaPdf::texto($buyer->address) : null;
            case 'comprador_localidad':
                return $buyer ? CamposDeVentaPdf::texto($buyer->city ?: $buyer->ciudad) : null;
            case 'comprador_codigo_postal':
                return $this->codigo_postal($buyer);
            case 'comprador_cuit':
                /** `buyers` no tiene CUIT: sale del cliente del ERP vinculado (`comercio_city_client`). */
                if (is_null($buyer) || is_null($buyer->comercio_city_client)) {
                    return null;
                }

                return CamposDeVentaPdf::texto($buyer->comercio_city_client->cuit);

            case 'pedido_numero':
                return CamposDeVentaPdf::texto($this->order->num);
            case 'pedido_fecha':
                $fecha = ($this->use_current_date || is_null($this->order->created_at)) ? now() : Carbon::parse($this->order->created_at);

                return $fecha->format('d/m/Y');
            case 'pedido_estado':
                return $this->order->order_status ? CamposDeVentaPdf::texto($this->order->order_status->name) : null;
            case 'pedido_modalidad_de_entrega':
                return $this->order->deliver ? 'Envío a domicilio' : 'Retiro en el local';
            case 'pedido_direccion_de_envio':
                return $this->direccion_de_envio();
            case 'pedido_envio_elegido':
                return $this->envio_elegido();
            case 'pedido_metodo_de_pago':
                return $this->order->payment_method ? CamposDeVentaPdf::texto($this->order->payment_method->name) : null;
            case 'pedido_cupon':
                return ($this->order->cupon && ! empty($this->order->cupon->code)) ? CamposDeVentaPdf::texto($this->order->cupon->code) : null;
            case 'pedido_fecha_entrega':
                return CamposDeVentaPdf::fecha($this->order->fecha_entrega);
            case 'pedido_vendedor':
                /** `orders.seller_id` existe pero el modelo no declara la relación. */
                if (empty($this->order->seller_id)) {
                    return null;
                }
                $seller = Seller::find($this->order->seller_id);

                return $seller ? CamposDeVentaPdf::texto($seller->name) : null;
            case 'pedido_deposito':
                /** `orders.address_id` es el depósito; `orders.address` es el TEXTO de la dirección de entrega. */
                if (empty($this->order->address_id)) {
                    return null;
                }

                return CamposDeVentaPdf::sucursal(Address::find($this->order->address_id));
            case 'pedido_notas':
                return CamposDeVentaPdf::texto_largo($this->documento->observations());

            case 'tot_subtotal':
                return $this->documento->tiene_extras() ? $this->documento->texto_subtotal() : null;
            case 'tot_envio':
                return $this->documento->texto_envio();
            case 'tot_cupon':
                return $this->documento->texto_cupon();
            case 'tot_ajuste_medio_de_pago':
                return $this->documento->texto_ajuste_medio_de_pago();
            case 'tot_total':
                return $this->documento->tiene_extras() ? null : $this->documento->texto_subtotal();

            case CatalogoDeCamposPdf::KEY_TEXTO_LIBRE:
                return CamposDeVentaPdf::texto_libre($campo);
        }

        throw new \InvalidArgumentException('Campo del PDF de pedido sin resolver: '.$key);
    }

    /**
     * Código postal: el del destino del envío por correo y, si no hay, el que el comprador dejó
     * en su cuenta.
     *
     * @param \App\Models\Buyer|null $buyer
     * @return string|null
     */
    private function codigo_postal($buyer)
    {
        $destino = is_array($this->order->envio_destino) ? $this->order->envio_destino : [];

        if (! empty($destino['codigo_postal'])) {
            return CamposDeVentaPdf::texto($destino['codigo_postal']);
        }

        return $buyer ? CamposDeVentaPdf::texto($buyer->envio_zipcode) : null;
    }

    /**
     * Dirección de envío, solo si el pedido se envía: la del pedido y, si no la trae, la del
     * destino del envío por correo (calle, número y localidad).
     *
     * @return string|null
     */
    private function direccion_de_envio()
    {
        if (! $this->order->deliver && empty($this->order->envio_opcion)) {
            return null;
        }

        $direccion = CamposDeVentaPdf::texto($this->order->address);
        if (! is_null($direccion)) {
            return $direccion;
        }

        $destino = is_array($this->order->envio_destino) ? $this->order->envio_destino : [];
        $calle = trim((isset($destino['calle']) ? $destino['calle'] : '').' '.(isset($destino['numero']) ? $destino['numero'] : ''));
        $partes = [];
        if ($calle !== '') {
            $partes[] = $calle;
        }
        if (! empty($destino['localidad'])) {
            $partes[] = trim((string) $destino['localidad']);
        }

        return count($partes) > 0 ? implode(', ', $partes) : null;
    }

    /**
     * El envío que eligió el comprador: correo y servicio del envío por correo
     * ("Andreani · Estándar") o, si no hay, la zona de entrega propia del comercio.
     *
     * @return string|null
     */
    private function envio_elegido()
    {
        $opcion = is_array($this->order->envio_opcion) ? $this->order->envio_opcion : [];

        $partes = [];
        foreach (['carrier_name', 'service_name'] as $clave) {
            if (! empty($opcion[$clave]) && is_string($opcion[$clave])) {
                $partes[] = trim($opcion[$clave]);
            }
        }

        if (count($partes) === 0 && ! empty($opcion['correo']) && is_string($opcion['correo'])) {
            $partes[] = trim($opcion['correo']);
        }

        if (count($partes) > 0) {
            return implode(' · ', $partes);
        }

        return $this->order->delivery_zone ? CamposDeVentaPdf::texto($this->order->delivery_zone->name) : null;
    }
}
