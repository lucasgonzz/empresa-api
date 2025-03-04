<?php

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Models\Afip\WSFE;
use App\Models\Afip\WSSRConstanciaInscripcion;
use Illuminate\Support\Facades\Log;

class CondicionIvaReceptorHelper {

    function get_data() {

        define ('TA_file', public_path().'/afip/wsaa/wsfe/TA.xml'); 

        $testing = true;

        $afip_wsaa = new AfipWSAAHelper($testing, 'wsfe');
        
        $afip_wsaa->checkWsaa();


        // $ws = new WSSRConstanciaInscripcion(['testing'=> $testing, 'cuit_representada' => '20423548984', 'for_constancia_de_inscripcion' => false]);


        $ws = new WSFE([
            'testing'=> $testing, 
            'cuit_representada' => '20381712010'
        ]);

        $ws->setXmlTa(file_get_contents(TA_file));



        $xmlString = file_get_contents(TA_file); // Aquí coloca el XML completo como cadena

        // Convertir el XML en un objeto SimpleXMLElement
        $xml = simplexml_load_string($xmlString);

        // Acceder a los valores de <token> y <sign>
        $token = (string) $xml->credentials->token;
        $sign = (string) $xml->credentials->sign;


        $res = $ws->FEParamGetCondicionIvaReceptor([
            // 'Auth' => [
            //     'Token' => $token,
            //     'Sign'  => $sign,
            //     'cuit'  => '20423548984', 
            // ],
        ]);

        Log::info('FEParamGetCondicionIvaReceptor:');
        Log::info($res);
    }
	
    static function get_iva_receptor($sale) {

    	$iva_receptor = 5; //consumidor final

        if ($sale->client) {
            
            $iva_condition = $sale->client->iva_condition;

            if (!is_null($iva_condition)) {

                if ($iva_condition->name == 'Responsable inscripto') {
                    
                    $iva_receptor = 1; 
                } else if ($iva_condition->name == 'Monotributista') {

                    $iva_receptor = 6; 
                } else if ($iva_condition->name == 'Consumidor final') {

                } else if ($iva_condition->name == 'Exento') {

                    $iva_receptor = 4; 
                }
            } 
        }

        return $iva_receptor;

        $ivas = [
            [
                "Id" => 1,
                "Desc" => "IVA Responsable Inscripto",
                "Cmp_Clase" => "A/M/C"
            ],
            [
                "Id" => 6,
                "Desc" => "Responsable Monotributo",
                "Cmp_Clase" => "A/M/C"
            ],
            [
                "Id" => 13,
                "Desc" => "Monotributista Social",
                "Cmp_Clase" => "A/M/C"
            ],
            [
                "Id" => 16,
                "Desc" => "Monotributo Trabajador Independiente Promovido",
                "Cmp_Clase" => "A/M/C"
            ],
            [
                "Id" => 4,
                "Desc" => "IVA Sujeto Exento",
                "Cmp_Clase" => "B/C"
            ],
            [
                "Id" => 5,
                "Desc" => "Consumidor Final",
                "Cmp_Clase" => "B/C"
            ],
            [
                "Id" => 7,
                "Desc" => "Sujeto No Categorizado",
                "Cmp_Clase" => "B/C"
            ],
            [
                "Id" => 8,
                "Desc" => "Proveedor del Exterior",
                "Cmp_Clase" => "B/C"
            ],
            [
                "Id" => 9,
                "Desc" => "Cliente del Exterior",
                "Cmp_Clase" => "B/C"
            ],
            [
                "Id" => 10,
                "Desc" => "IVA Liberado – Ley N° 19.640",
                "Cmp_Clase" => "B/C"
            ],
            [
                "Id" => 15,
                "Desc" => "IVA No Alcanzado",
                "Cmp_Clase" => "B/C"
            ],
        ];
    }


}