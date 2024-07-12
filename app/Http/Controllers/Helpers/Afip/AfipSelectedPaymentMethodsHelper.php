<?php 

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use Illuminate\Support\Facades\Log;


class AfipSelectedPaymentMethodsHelper
{

    public $alicuota_iva = 21;
    public $gravado;
    public $importe_iva;

    function __construct($sale, $afip_selected_payment_methods) {
        $this->sale = $sale;
        $this->afip_selected_payment_methods = $afip_selected_payment_methods;

        $this->set_total_a_facturar();

        $this->set_importes();

    }

    function set_total_a_facturar() {

        $this->total_a_facturar = 0;

        foreach ($this->afip_selected_payment_methods as $afip_selected_payment_method) {
            
            foreach ($this->sale->current_acount_payment_methods as $sale_payment_method) {
                
                if ($sale_payment_method->id == $afip_selected_payment_method->current_acount_payment_method_id) {

                    Log::info('Se van a facturar '.$sale_payment_method->pivot->amount.' de '.$sale_payment_method->name);

                    $this->total_a_facturar += $sale_payment_method->pivot->amount;

                    if (!is_null($sale_payment_method->pivot->discount_amount)) {
                        
                        Log::info('Tienen descuento de '.$sale_payment_method->pivot->discount_amount);

                        $this->total_a_facturar -= $sale_payment_method->pivot->discount_amount;
                    } 
                }
            }

        }
    }

    function set_importes() {

        /*
            $gravado: la parte del $total_a_facturar sin el impuesto
                Ej:  Si $total_a_facturar = 100, $gravado = 82,64
        */
        
        $this->gravado = $this->total_a_facturar / (($this->alicuota_iva / 100) + 1); 
        $this->importe_iva = $this->total_a_facturar - $this->gravado;
    }

    function get_gravado() {
        return Numbers::redondear($this->gravado);
    }

    function get_importe_iva() {
        return Numbers::redondear($this->importe_iva);
    }


}
