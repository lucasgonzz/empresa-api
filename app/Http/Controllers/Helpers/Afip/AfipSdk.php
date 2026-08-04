<?php

namespace App\Http\Controllers\Helpers\Afip;

use Illuminate\Support\Facades\Log;
use afipsdk\afip\src\Afip;

class AfipSdk
{

    public function __construct($sale) {
        $this->sale = $sale;
        $this->testing = !$this->sale->afip_information->afip_ticket_production;

        // CUIT y access_token de app.afipsdk.com: se leen de config/services.php (.env del
        // servidor). El repositorio es publico, prohibido volver a escribir el valor real aca.
        $afip = new Afip([
            'CUIT' => config('services.afip_sdk.cuit'),
            'access_token' => config('services.afip_sdk.access_token') // Obtenido de https://app.afipsdk.com
        ]);

        $ws = $afip->WebService('wsfex');

        // Obtenemos el TA
        $ta = $ws->GetTokenAuthorization();
            
        // Preparamos los datos
        $data = array(
            'Auth' => array( 
                'Token' => $ta->token,
                'Sign' => $ta->sign,
                'Cuit' => $afip->CUIT
            )
        );


        $res = $ws->ExecuteRequest('FEXGetLast_ID', $data);
    }
}
