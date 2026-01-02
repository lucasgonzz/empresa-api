<?php 

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipFexHelper;
use App\Http\Controllers\Helpers\Afip\AfipSolicitarCaeHelper;
use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Http\Controllers\Helpers\Afip\CondicionIvaReceptorHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\Utf8Helper;
use App\Models\AfipError;
use App\Models\AfipObservation;
use App\Models\AfipTicket;
use App\Models\Afip\WSAA;
use App\Models\Afip\WSFE;
use App\Models\Afip\WSSRConstanciaInscripcion;
use App\Models\Article;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AfipWsController extends Controller
{
    public $afip_fecha_emision;
    public $sale;
    public $errors;
    public $observations;
    public $monto_minimo_para_factura_de_credito = 1357480;
    // public $monto_minimo_para_factura_de_credito = 546737;

    function __construct($afip_ticket) {

        $this->afip_ticket = $afip_ticket;
        
        $this->testing = true;

        if ($this->afip_ticket->afip_information->afip_ticket_production) {
            $this->testing = false;
        } 


        
        if ($this->afip_ticket->sale) {
            Log::info('AfipWsController para sale id: '.$this->afip_ticket->sale->id);
        } else {
            Log::info('AfipWsController sale NULL');
        }

        Log::info('AfipWsController sale id: '.$this->afip_ticket->sale->id.' testing: '.$this->testing);
    }

    function init() {

        $afip_tipo_comprobante = $this->afip_ticket->afip_tipo_comprobante;

        // Comprobantes de exportación (si tipo es 19, 20, etc.)
        if (
            $afip_tipo_comprobante->codigo == 19
        ) {

            $afip_wsaa = new AfipWSAAHelper($this->testing, 'wsfex');
            $afip_wsaa->checkWsaa();


            $helper = new AfipFexHelper($this->afip_ticket, $this->testing);
            
        } else {
            
            $afip_wsaa = new AfipWSAAHelper($this->testing, 'wsfe');
            $afip_wsaa->checkWsaa();

            $helper = new AfipWsfeHelper($this->afip_ticket, $this->testing);
        }

        $helper->procesar();
    }

}
