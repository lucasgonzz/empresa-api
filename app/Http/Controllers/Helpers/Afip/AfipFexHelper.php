<?php

namespace App\Http\Controllers\Helpers\Afip;

use App\Models\AfipError;
use App\Models\AfipTicket;
use App\Models\Afip\WSFEX;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;

class AfipFexHelper
{

    public function __construct($sale) {
        $this->sale = $sale;
        $this->testing = !$this->sale->afip_information->afip_ticket_production;

        $this->numero_comprobante = 0;

        $this->wsfex = new WSFEX([
            'testing'           => $this->testing,
            'cuit_representada' => $this->sale->afip_information->cuit,
        ]);

        $this->wsfex->setXmlTa(file_get_contents(TA_file));

    }

    public function procesar()
    {

        $this->create_afip_ticket();

        $res_numero_comprobante = Self::set_numero_comprobante($this->wsfex, $this->sale->afip_information->punto_venta, $this->sale->afip_tipo_comprobante->codigo);

        if (!$res_numero_comprobante['hubo_un_error']) {
            $this->numero_comprobante = $res_numero_comprobante['numero_comprobante'];
        } else {
            return false;
        }
        

        $this->update_afip_ticket_numero_comprobante();


        // $pais_destino = 242; // código país destino (por ejemplo, Uruguay)
        $pais_destino = $this->sale->client->pais_exportacion->codigo_afip;
        $idioma_cbte = 1;     // Español
        $moneda = 'DOL';
        $moneda_cotiz = $this->sale->valor_dolar;


        $data = [
            'Id'                    => $this->sale->id.rand(0,99999),
            'Fecha_cbte'            => date('Ymd'),
            'Cbte_Tipo'             => 19,
            'Punto_vta'             => $this->sale->afip_information->punto_venta,
            'Cbte_nro'              => $this->numero_comprobante,
            'Tipo_expo'             => 1,
            'pais_destino'          => $pais_destino,
            'Cliente'               => mb_convert_encoding($this->sale->client->name ?? 'Consumidor Final', 'UTF-8'),
            'Domicilio_cliente'     => mb_convert_encoding($this->sale->client->address ?? '', 'UTF-8'),
            'Id_impositivo'         => $this->sale->client->cuit ?? 'CF',
            'Moneda_Id'             => $moneda,
            'Moneda_cotiz'          => $moneda_cotiz,
            'Imp_total'             => (float)$this->sale->total,
            'Idioma_cbte'           => $idioma_cbte,
            'Incoterms'             => $this->sale->incoterms && $this->sale->incoterms != 0 ? $this->sale->incoterms : 'FOB',
            'Permiso_existente'     => 'N',
        ];


        foreach ($this->sale->articles as $article) {
            
            $amount = (float)$article->pivot->amount;
            $price = (float)$article->pivot->price;

            $item = [];
            $item['Pro_codigo']         = $article->id;
            $item['Pro_ds']             = $article->name;
            $item['Pro_qty']            = $amount;
            $item['Pro_umed']           = 1; // FEXGetPARAM_UMed 
            $item['Pro_precio_uni']     = $price;
            $item['Pro_bonificacion']   = 0;
            $item['Pro_total_item']     = $price * $amount;

            $data['items'][] = $item;
        }

        $params = Self::get_fex_params($data);

        // Log::info('Se va a enviar params:');
        // Log::info((array)$params);

        $result = $this->wsfex->FEXAuthorize($params);

        Log::info((array)$result);


        if ($result['hubo_un_error']) {
            Log::info('Hubo errores al facturar wsfex:');
        } else {
            $this->update_afip_ticket($result['result']);
        }


        return ['success' => $result['result']];
    }


    function update_afip_ticket($result) {

        if (
            isset($result->FEXAuthorizeResult) 
            && isset($result->FEXAuthorizeResult->FEXResultAuth)
        ) {

            $this->created_afip_ticket->update([
                'cae'   => $result->FEXAuthorizeResult->FEXResultAuth->Cae,
                'cae_expired_at'    => $result->FEXAuthorizeResult->FEXResultAuth->Fch_venc_Cae,
                'resultado' => $result->FEXAuthorizeResult->FEXResultAuth->Resultado,
                'importe_total' => $this->sale->total,
            ]);

        } else if (
            isset($result->FEXAuthorizeResult) 
            && isset($result->FEXAuthorizeResult->FEXErr)
        ) {

            $message = mb_convert_encoding(
                $result->FEXAuthorizeResult->FEXErr->ErrMsg,
                'UTF-8',
                'ISO-8859-1'
            );

            AfipError::create([
                'message'   => $message,
                'code'      => $result->FEXAuthorizeResult->FEXErr->ErrCode,
                'sale_id'   => $this->sale->id,
                // 'afip_ticket_id'   => $this->created_afip_ticket->id,
            ]);
        }
    }


    function update_afip_ticket_numero_comprobante() {
        $this->created_afip_ticket->cbte_numero = $this->numero_comprobante;
        $this->created_afip_ticket->save();
    }


  //   'hubo_un_error' => false,
  // 'result' => 
  // (object) array(
  //    'FEXAuthorizeResult' => 
  //   (object) array(
  //      'FEXResultAuth' => 
  //     (object) array(
  //        'Id' => 62,
  //        'Cuit' => 30716582899,
  //        'Cbte_tipo' => 19,
  //        'Punto_vta' => 4,
  //        'Cbte_nro' => 58,
  //        'Cae' => '71279049124261',
  //        'Fch_venc_Cae' => '20210708',
  //        'Fch_cbte' => '20210708',
  //        'Resultado' => 'A',
  //        'Reproceso' => 'S',
  //        'Motivos_Obs' => '',
  //     ),
  //      'FEXErr' => 
  //     (object) array(
  //        'ErrCode' => 0,
  //        'ErrMsg' => 'OK',
  //     ),
  //      'FEXEvents' => 
  //     (object) array(
  //        'EventCode' => 0,
  //        'EventMsg' => 'Ok',
  //     ),
  //   ),


    function create_afip_ticket() {
        $this->created_afip_ticket = AfipTicket::create([
            'cuit_negocio'      => $this->sale->afip_information->cuit,
            'iva_negocio'       => $this->sale->afip_information->iva_condition->name,
            'punto_venta'       => $this->sale->afip_information->punto_venta,
            'cbte_tipo'         => 19,

            'iva_negocio'       => $this->sale->afip_information->iva_condition->name,
            'iva_cliente'       => !is_null($this->sale->client) && !is_null($this->sale->client->iva_condition) ? $this->sale->client->iva_condition->name : '',
            'cbte_letra'        => 'E',
            'sale_id'           => $this->sale->id,
            // 'afip_information_id'        => $this->sale->afip_information_id,
            // 'afip_tipo_comprobante_id'   => $this->sale->afip_tipo_comprobante_id,
            // 'afip_fecha_emision'             => $this->afip_fecha_emision,
        ]);

        $this->sale->load('afip_ticket');
    }

    static function set_numero_comprobante($wsfex, $punto_venta, $Cbte_Tipo) {

        $data = [
            'Pto_venta' => $punto_venta,
            'Cbte_Tipo' => $Cbte_Tipo,
        ];

        $res = $wsfex->FEXGetLast_CMP($data, 'incrustar_en_auth');

        Log::info('result de set_numero_comprobante:');
        Log::info((array)$res);

        $numero_comprobante = 1;

        if (!$res['hubo_un_error']) {

            if ($res['result']->FEXGetLast_CMPResult) {
                $ultimo_comprobante = $res['result']->FEXGetLast_CMPResult->FEXResult_LastCMP->Cbte_nro;
                $numero_comprobante = $ultimo_comprobante + 1;
            }

            return [
                'hubo_un_error' => false,
                'numero_comprobante'    => $numero_comprobante,
            ];  
        }
        
        return [
            'hubo_un_error' => true,
        ];  

    }


    static function get_fex_params($data) {

        Log::info('get_fex_params data:');
        Log::info($data);
        $id = $data["Cbte_Tipo"] . $data["Punto_vta"] . $data["Cbte_nro"];
        Log::info('id: '.$id);

        $params = new \stdClass();

        //Enbezado *********************************************
        $params->Cmp = new \stdClass();
        $params->Cmp->Id = (float)$id;
        $params->Cmp->Fecha_cbte = $data["Fecha_cbte"]; // [N]
        $params->Cmp->Cbte_Tipo = $data["Cbte_Tipo"]; // FEXGetPARAM_Cbte_Tipo
        $params->Cmp->Punto_vta = $data["Punto_vta"];
        $params->Cmp->Cbte_nro = $data["Cbte_nro"];
        $params->Cmp->Tipo_expo = $data["Tipo_expo"]; // FEXGetPARAM_Tipo_Expo 
        $params->Cmp->Permiso_existente = $data["Permiso_existente"]; // Permiso de embarque - S, N, NULL (vacío)
        //$params->Cmp->Permisos = array(); // [N]
        $params->Cmp->Dst_cmp = $data["pais_destino"]; // FEXGetPARAM_DST_pais
        $params->Cmp->Cliente = $data["Cliente"];
        $params->Cmp->Cuit_pais_cliente = ""; // FEXGetPARAM_DST_CUIT (No es necesario si se ingresa ID_impositivo)
        $params->Cmp->Domicilio_cliente = $data["Domicilio_cliente"];
        $params->Cmp->Id_impositivo = $data["Id_impositivo"];
        $params->Cmp->Moneda_Id = $data["Moneda_Id"]; // FEXGetPARAM_MON
        $params->Cmp->Moneda_ctz = $data["Moneda_cotiz"]; // FEXGetPARAM_Ctz
        //$params->Cmp->Obs_comerciales = ""; // [N]
        $params->Cmp->Imp_total = $data["Imp_total"];
        //$params->Cmp->Obs = ""; // [N]
        
        //COMPROBANTES ASOCIADOS*****************************************
        if (array_key_exists("CbtesAsoc", $data) && count($data["CbtesAsoc"]) > 0) {
            $params->Cmp->Cmps_asoc = array(); // [N]
            foreach ($data["CbtesAsoc"] as $value) {
                $cbte = new \stdClass();
                $cbte->Cbte_tipo = $value["Tipo"];
                $cbte->Cbte_punto_vta = $value["PtoVta"];
                $cbte->Cbte_nro = $value["Nro"];
                $cbte->Cbte_cuit = $data['cuit_emisor'];
                $params->Cmp->Cmps_asoc[] = $cbte;
            }
        }

        //$params->Cmp->Forma_pago = $data["CondicionVenta"]; // [N]
        $params->Cmp->Incoterms = $data['Incoterms']; // Cláusula de venta - FEXGetPARAM_Incoterms [N]
        //$params->Cmp->Incoterms_Ds = ""; // [N]
        $params->Cmp->Idioma_cbte = $data["Idioma_cbte"]; // 2:Ingles - FEXGET_PARAM_IDIOMAS
        //$params->Cmp->Opcionales = array(); // [N]
        // Items
        if (array_key_exists("items", $data) && count($data["items"]) > 0) {
            $params->Cmp->Items = array();
            foreach ($data["items"] as $value) {
                $item = new \stdClass();
                $item->Pro_codigo = $value["Pro_codigo"];
                $item->Pro_ds = $value["Pro_ds"];
                $item->Pro_qty = $value["Pro_qty"];
                $item->Pro_umed = $value["Pro_umed"]; // FEXGetPARAM_UMed 
                $item->Pro_precio_uni = $value["Pro_precio_uni"];
                $item->Pro_bonificacion = $value["Pro_bonificacion"];
                $item->Pro_total_item = $value["Pro_total_item"];
                $params->Cmp->Items[] = $item;
            }
        }

        return $params;






        // $params = new \stdClass();

        // $params->Cmp = new \stdClass();

        // $params->Cmp->Id = $data['Id'];
        // $params->Cmp->Fecha_cbte = $data['Fecha_cbte'];
        // $params->Cmp->Cbte_Tipo = $data['Cbte_Tipo'];
        // $params->Cmp->Punto_vta = (int)$data['Punto_vta'];
        // $params->Cmp->Cbte_nro  = $data['Cbte_nro'];
        // $params->Cmp->Tipo_expo = 1;
        // $params->Cmp->Permiso_existente = 'N';
        // $params->Cmp->Imp_total = (float)$this->sale->total;
        // $params->Cmp->Idioma_cbte = $idioma_cbte;
        // $params->Cmp->Moneda_Id = $moneda;
        // $params->Cmp->Moneda_ctz = $moneda_cotiz;
        // $params->Cmp->Dst_cmp = (int) $pais_destino;
        // $params->Cmp->Cliente = ;
        // $params->Cmp->Cuit_pais_cliente = '0';
        // $params->Cmp->Cuit_pais_cliente = (string) $this->sale->client->cuit ?? '0';
        // $params->Cmp->Domicilio_cliente = mb_convert_encoding($this->sale->client->address ?? '', 'UTF-8');
        // $params->Cmp->Id_impositivo = $this->sale->client->cuit ?? 'CF';
        // $params->Cmp->Incoterms = $this->sale->incoterms && $this->sale->incoterms != 0 ? $this->sale->incoterms : 'FOB';
        // $params->Cmp->Incoterms_Ds = mb_convert_encoding($this->sale->incoterms_ds ?? 'Free on Board', 'UTF-8');
        // $params->Cmp->Forma_pago = 'Transferencia';
        // $params->Cmp->Obs = 'Exportacion';

        // $params->Cmp->Items = array();
        // foreach ($this->sale->articles as $article) {
            
        //     $amount = (float)$article->pivot->amount;
        //     $price = (float)$article->pivot->price;

        //     $item = new \stdClass();
        //     $item->Pro_codigo = $article->id;
        //     $item->Pro_ds = $article->name;
        //     $item->Pro_qty = $amount;
        //     $item->Pro_umed = 1; // FEXGetPARAM_UMed 
        //     $item->Pro_precio_uni = $price;
        //     $item->Pro_bonificacion = 0;
        //     $item->Pro_total_item = $price * $amount;

        //     $params->Cmp->Items[] = $item;
        // }
    }


}
