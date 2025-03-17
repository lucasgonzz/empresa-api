<?php

namespace App\Http\Controllers\Helpers;
use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\Afip\AfipSelectedPaymentMethodsHelper;
use App\Models\AfipSelectedPaymentMethod;
use Illuminate\Support\Facades\Log;

class AfipHelper extends Controller {

    public $sale;
    public $article;
    public $factura_solo_algunos_metodos_de_pago;
    public $afip_selected_payment_methods;

    function __construct($sale, $articles = null, $user = null) {

        if (is_null($user)) {
           $this->user = $this->user();
        } else {
           $this->user = $user;
        }
        $this->sale = $sale;
        if (is_null($articles)) {
            $articles = $this->sale->articles;
        }
        $this->articles = $articles;

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

    function getImportes() {
        $items = [];
        $gravado            = 0;
        $neto_no_gravado    = 0;
        $exento             = 0;
        $iva                = 0;
        $ivas = [
            '27' => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 6],
            '21' => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 5],
            '10' => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 4],
            '5'  => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 8],
            '2'  => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 9],
            '0'  => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 3],
        ];
        $subtotal           = 0;
        $total              = 0;
        
        if ($this->sale->afip_information->iva_condition->name == 'Responsable inscripto') {

            if ($this->factura_solo_algunos_metodos_de_pago) {

                Log::info('factura_solo_algunos_metodos_de_pago');

                $helper = new AfipSelectedPaymentMethodsHelper($this->sale, $this->afip_selected_payment_methods);

                $gravado                += $helper->get_gravado();
                $iva                    += $helper->get_importe_iva();
                $ivas['21']['Importe']  += $helper->get_importe_iva();
                $ivas['21']['BaseImp']  += $gravado;

            } else {

                foreach ($this->articles as $article) {
                    $this->article = $article;
                    $gravado                += $this->getImporteGravado();
                    $exento                 += $this->getImporteIva('Exento')['BaseImp'];
                    $neto_no_gravado        += $this->getImporteIva('No Gravado')['BaseImp'];
                    $iva                    += $this->getImporteIva();
                    
                    $res                    = $this->getImporteIva('27');
                    $ivas['27']['Importe']  += $res['Importe'];
                    $ivas['27']['BaseImp']  += $res['BaseImp'];

                    $res                    = $this->getImporteIva('21');
                    $ivas['21']['Importe']  += $res['Importe'];
                    $ivas['21']['BaseImp']  += $res['BaseImp'];

                    $res                    = $this->getImporteIva('10.5');
                    $ivas['10']['Importe']  += $res['Importe'];
                    $ivas['10']['BaseImp']  += $res['BaseImp'];

                    $res                    = $this->getImporteIva('5');
                    $ivas['5']['Importe']  += $res['Importe'];
                    $ivas['5']['BaseImp']  += $res['BaseImp'];

                    $res                    = $this->getImporteIva('2.5');
                    $ivas['2']['Importe']  += $res['Importe'];
                    $ivas['2']['BaseImp']  += $res['BaseImp'];

                    $res                    = $this->getImporteIva('0');
                    $ivas['0']['Importe']  += $res['Importe'];
                    $ivas['0']['BaseImp']  += $res['BaseImp'];
                } 

                foreach ($this->sale->combos as $combo) {

                    Log::info('Pidiendo iva de '.$combo->name);
                    
                    $res                    = $this->get_combo_iva($combo);
                    Log::info($res);
                    $ivas['21']['Importe']  += $res['Importe'];
                    $ivas['21']['BaseImp']  += $res['BaseImp'];
                    
                    $gravado                += $res['BaseImp'];
                    $iva                    += $res['Importe'];
                }
            }

        }

        $gravado = Numbers::redondear($gravado);
        $neto_no_gravado = Numbers::redondear($neto_no_gravado);
        $exento = Numbers::redondear($exento);
        $iva = Numbers::redondear($iva);
        $total = Numbers::redondear($gravado + $neto_no_gravado + $exento + $iva);
        return [
            'gravado'           => $gravado,
            'neto_no_gravado'   => $neto_no_gravado,
            'exento'            => $exento,
            'iva'               => $iva,
            'ivas'              => $ivas,
            'total'             => $total,
        ];
    }

    function get_combo_iva($combo) {
        
        $total_combo = $combo->pivot->price * $combo->pivot->amount;
        $iva = 21;

        $precio_sin_iva = $total_combo / (($iva / 100) + 1);
        $monto_iva = $total_combo - $precio_sin_iva; 
        
        return [
            'Importe'   => round($monto_iva, 2),
            'BaseImp'   => round($precio_sin_iva, 2)
        ];
    }

    function getImporteIva($iva = null) {
        if (is_null($iva)) {
            return $this->montoIvaDelPrecio() * $this->article->pivot->amount;
        }
        $importe = 0;
        $base_imp = 0;
        if (
            (
                is_null($this->article->iva) 
                && $iva == 21
            ) 
            || (
                !is_null($this->article->iva) 
                && $this->article->iva->percentage == $iva
            )
        ) {
            $importe = $this->montoIvaDelPrecio() * $this->article->pivot->amount;
            $base_imp = $this->getPriceWithoutIva() * $this->article->pivot->amount;
        }
        return ['Importe' => round($importe, 2), 'BaseImp' => round($base_imp, 2)];
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
        if ($with_discount) {
            $price = $this->getArticlePriceWithDiscounts();
        } else {
            $price = $this->article->pivot->price;
        }
        if (is_null($this->article->iva) || (!is_null($this->article->iva && $this->article->iva->percentage != 'No Gravado' && $this->article->iva->percentage != 'Exento' && $this->article->iva->percentage != 0))) {
        // if ($this->user->iva_included || (!is_null($this->article->iva) && $this->article->iva->percentage != 'No Gravado' && $this->article->iva->percentage != 'Exento' && $this->article->iva->percentage != 0)) {
            $article_iva = 21;
            if (!is_null($this->article->iva)) {
                $article_iva = $this->article->iva->percentage;
            }
            return $price / (((float)$article_iva / 100) + 1); 
        } 
        return $price;
    }

    function getArticlePriceWithDiscounts() {
        $price = $this->article->pivot->price;
        if (!is_null($this->article->pivot->discount)) {
            Log::info('restando descouneto de articulo del '.$this->article->pivot->discount.' a '.$price);
            $price -= $price * $this->article->pivot->discount / 100;
            Log::info('quedo en '.$price);
        }
        foreach ($this->sale->discounts as $discount) {
            Log::info('restando descouneto de venta de '.$discount->pivot->percentage.' a '.$price);
            $price -= $price * $discount->pivot->percentage / 100;
            Log::info('quedo en '.$price);
        }
        foreach ($this->sale->surchages as $surchage) {
            Log::info('aumentando recargo de venta de '.$surchage->pivot->percentage.' a '.$price);
            $price += $price * $surchage->pivot->percentage / 100;
            Log::info('quedo en '.$price);
        }

        if ($this->sale->descuento > 0) {
            $price -= $price * $this->sale->descuento / 100;
        }
        
        return $price;
    }

    function getArticlePrice($sale, $article, $precio_neto_sin_iva = false) {
        $this->article = $article;
        $price = $this->article->pivot->price;
        // if ($precio_neto_sin_iva || $this->isBoletaA()) {
            if (!is_null($article->iva) && $article->iva->percentage != 'No Gravado' && $article->iva->percentage != 'Exento' && $article->iva->percentage != 0) {
                return $this->getPriceWithoutIva();
            } 
        // } 
        foreach ($sale->discounts as $discount) {
            $price -= $price * $discount->pivot->percentage / 100;
        }
        foreach ($sale->surchages as $surchage) {
            $price += $price * $surchage->pivot->percentage / 100;
        }
        return $price;
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
        if (
            is_null($this->article->iva) 
            || (
                !is_null($this->article->iva) 
                && (
                    $this->article->iva->percentage != 'No Gravado' 
                    || $this->article->iva->percentage != 'Exento' 
                    || $this->article->iva->percentage != 0
                )
            )
        ) {

            $iva = 21;
            if (!is_null($this->article->iva)) {
                $iva = (float)$this->article->iva->percentage;
            }
            return $this->getPriceWithoutIva() * $iva / 100;
            // return Numbers::redondear($this->getPriceWithoutIva() * $iva / 100);
        } 
        return 0;
    }

    static function getImporteItem($article) {
        return $article->pivot->price * $article->pivot->amount;
    }

    function getImporteGravado() {
        if (
            is_null($this->article->iva) 
            || (
                !is_null($this->article->iva) 
                && $this->article->iva->percentage != 'No Gravado' 
                && $this->article->iva->percentage != 'Exento'
            )
        ) {
            return $this->getPriceWithoutIva() * $this->article->pivot->amount;
        }
        return 0;
    }

    function subTotal($article) {
        $this->article = $article;
        // if ($this->isBoletaA()) {
            return $this->getPriceWithoutIva() * $article->pivot->amount;
        // }
        // return $this->getArticlePriceWithDiscounts() * $article->pivot->amount;
    }

    function isBoletaA() {
        return $this->sale->afip_information->iva_condition->name == 'Responsable inscripto' && !is_null($this->sale->client) && !is_null($this->sale->client->iva_condition) && $this->sale->client->iva_condition->name == 'Responsable inscripto';
    }

    static function getDocType($slug) {
        $doc_type = [
            'Cuit' => 80,
            'Cuil' => 86,
            'CDI' => 87,
            'LE' => 89,
            'LC' => 90,
            'CI Extranjera' => 91,
            'en trámite' => 92,
            'Acta Nacimiento' => 93,
            'CI Bs. As. RNP' => 95,
            'DNI' => 96,
        ];
        return $doc_type[$slug];
    }

    static function getNumeroComprobante($wsfe, $punto_venta, $cbte_tipo) {
        $pto_vta = [
            'PtoVta'    => $punto_venta,
            'CbteTipo'  => $cbte_tipo
        ];
        $result = $wsfe->FECompUltimoAutorizado($pto_vta);
        Log::info('getNumeroComprobante');
        Log::info((array)$result);
        if (!$result['hubo_un_error']) {
            return [
                'hubo_un_error'         => false,
                'numero_comprobante'    => $result['result']->FECompUltimoAutorizadoResult->CbteNro + 1,
            ];
            // return $result['result']->FECompUltimoAutorizadoResult->CbteNro + 1;
        } else {
            return [
                'hubo_un_error'         => true,
                'error'                 => $result['error'],
            ];
        }
    }

}