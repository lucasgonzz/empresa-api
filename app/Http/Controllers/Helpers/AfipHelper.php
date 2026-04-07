<?php

namespace App\Http\Controllers\Helpers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\AfipHelper\AfipImportesCalculator;
use App\Http\Controllers\Helpers\AfipHelper\AfipItemCalculator;
use App\Http\Controllers\Helpers\AfipHelper\AfipWsfeHelper;
use App\Models\AfipSelectedPaymentMethod;

class AfipHelper extends Controller {

    public $user;
    public $afip_ticket;
    public $sale;
    public $articles;
    public $services;
    public $descriptions;
    public $article;
    public $nota_credito_model;
    public $factura_solo_algunos_metodos_de_pago;
    public $afip_selected_payment_methods;
    public $item_calculator;

    function __construct($afip_ticket, $articles = null, $services = null, $user = null, $sale = null, $descriptions = [], $nota_credito_model = null) {

        if (is_null($user)) {
           $this->user = $this->user();
        } else {
           $this->user = $user;
        }
        
        $this->afip_ticket = $afip_ticket;

        $this->sale = $afip_ticket->sale;
        if (!is_null($sale)) {
            $this->sale = $sale;
        }


        // Seteo articulos
        if (is_null($articles)) {
            $articles = $this->sale->articles;
        }
        $this->articles = $articles;


        $this->nota_credito_model = $nota_credito_model;


        // Seteo servicios
        if (is_null($services)) {
            $services = $this->sale->services;
        }
        $this->services = $services;
        
        $this->descriptions = $descriptions;

        $this->set_afip_selected_payment_methods();
    }

    function set_afip_selected_payment_methods() {
        $models = AfipSelectedPaymentMethod::where('user_id', $this->user->id)
                                            ->get();
        if (count($models) >= 1) {
            
            $this->factura_solo_algunos_metodos_de_pago = true;
            $this->afip_selected_payment_methods = $models;

        } else {
            $this->factura_solo_algunos_metodos_de_pago = false;
        }
    }

    /**
     * Calcula importes AFIP delegando en un calculador dedicado.
     *
     * @return array
     */
    function getImportes() {
        /* Delegación para mantener este helper enfocado en orquestación y compatibilidad. */
        $importes_calculator = new AfipImportesCalculator();
        return $importes_calculator->calculate($this);
    }

    function get_combo_iva($combo) {
        return $this->get_item_calculator()->get_combo_iva($combo);
    }

    function get_description_iva($description, $iva) {
        return $this->get_item_calculator()->get_description_iva($description, $iva);
    }

    function getImporteIva($iva = null) {
        return $this->get_item_calculator()->get_importe_iva($iva);
    }


    /*
    |--------------------------------------------------------------------------
    | Retorna el Precio sin el iva
    |--------------------------------------------------------------------------
    |
    | Si un articulo cuesta $100 y tiene el iva del 21
    | - El precio sin iva seria $82,64 
    | - Si with_discount = true, se restan los descuentos del articulo y de la venta
    |
    */
    function getPriceWithoutIva($with_discount = true) {
        return $this->get_item_calculator()->get_price_without_iva($with_discount);
    }

    function getArticlePriceWithDiscounts() {
        return $this->get_item_calculator()->get_article_price_with_discounts();
    }

    function getArticlePrice($sale, $article, $precio_neto_sin_iva = false) {
        return $this->get_item_calculator()->get_article_price($sale, $article, $precio_neto_sin_iva);
    }

    function get_article_price() {
        return $this->get_item_calculator()->get_article_price_raw();
    }

    function get_article_amount() {
        return $this->get_item_calculator()->get_article_amount();
    }


    /*
    |--------------------------------------------------------------------------
    | Retorna el Monto del iva
    |--------------------------------------------------------------------------
    |
    | Si un articulo cuesta $100 y tiene el iva del 21
    | - El precio sin iva seria $82,64 
    | - Y el montoIvaDelPrecio seria de $17.36
    |
    */
    function montoIvaDelPrecio() {
        return $this->get_item_calculator()->monto_iva_del_precio();
    }

    static function getImporteItem($article) {
        return $article->pivot->price * $article->pivot->amount;
    }

    function getImporteGravado() {
        return $this->get_item_calculator()->get_importe_gravado();
    }

    function subTotal($article) {
        return $this->get_item_calculator()->sub_total($article);
    }

    function exportacion() {
        return $this->get_item_calculator()->exportacion();
    }

    /**
     * Retorna instancia del calculador de ítems para reutilizar estado y evitar recreaciones.
     *
     * @return AfipItemCalculator
     */
    private function get_item_calculator() {
        if (is_null($this->item_calculator)) {
            $this->item_calculator = new AfipItemCalculator($this);
        }
        return $this->item_calculator;
    }

    function isBoletaA() {
        return $this->sale->afip_information->iva_condition->name == 'Responsable inscripto' && !is_null($this->sale->client) && !is_null($this->sale->client->iva_condition) && $this->sale->client->iva_condition->name == 'Responsable inscripto';
    }

    /**
    |--------------------------------------------------------------------------
    | Retorna el tipo de documento AFIP
    |--------------------------------------------------------------------------
    */
    static function getDocType($slug) {
        return AfipWsfeHelper::get_doc_type($slug);
    }

    /**
    |--------------------------------------------------------------------------
    | Retorna el próximo número de comprobante AFIP
    |--------------------------------------------------------------------------
    */
    static function getNumeroComprobante($wsfe, $punto_venta, $cbte_tipo) {
        return AfipWsfeHelper::get_numero_comprobante($wsfe, $punto_venta, $cbte_tipo);
    }

}