<?php

namespace App\Http\Controllers\Helpers\Afip;

class AfipSolicitarCaeHelper {
	
    static function get_doc_client($sale) {

    	$doc_client = null;
    	$doc_type = null;

        if ($sale->client) {
            if ($sale->client->cuit) {
                $doc_client = $sale->client->cuit;
                $doc_type = 80;
            } else if ($sale->client->cuil) {
                $doc_client = $sale->client->cuil;
                $doc_type = 86;
            } else if ($sale->client->dni) {
                $doc_client = $sale->client->dni;
                $doc_type = 96;
            } else {
                $doc_client = "NR";
                $doc_type = '99';
            }
        } else {
            $doc_client = "NR";
            $doc_type = '99';
        }

        return [
    		'doc_client' 	=> $doc_client,
    		'doc_type' 		=> $doc_type,
        ];

    }

}