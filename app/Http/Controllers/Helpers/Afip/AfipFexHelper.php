<?php

namespace App\Http\Controllers\Helpers\Afip;

use App\Models\Sale;
use App\Models\Afip\WSFEX;
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


        $this->set_numero_comprobante();

        // $pais_destino = 242; // código país destino (por ejemplo, Uruguay)
        $pais_destino = $this->sale->client->pais_exportacion->codigo_afip;
        $idioma_cbte = 1;     // Español
        $moneda = 'USD';
        $moneda_cotiz = $this->sale->valor_dolar;

        $fex_request = [
            // 'Cmp'   => [
                'Id' => 1,
                'Fecha_cbte' => date('Ymd'),
                'Cbte_Tipo' => 19,
                'Punto_vta' => (int) $this->sale->afip_information->punto_venta,
                'Cbte_nro'  => $this->numero_comprobante,
                'Tipo_expo' => 1,
                'Permiso_existente' => 'N',
                'Imp_total' => (float)$this->sale->total,
                'Idioma_cbte' => $idioma_cbte,
                'Moneda_Id' => $moneda,
                'Moneda_ctz' => $moneda_cotiz,
                'Dst_cmp' => (int) $pais_destino,
                'Cliente' => mb_convert_encoding($this->sale->client->name ?? 'Consumidor Final', 'UTF-8'),
                'Cuit_pais_cliente' => (string) $this->sale->client->cuit_pais ?? '0',
                'Domicilio_cliente' => mb_convert_encoding($this->sale->client->address ?? '', 'UTF-8'),
                'Id_impositivo' => $this->sale->client->cuit ?? 'CF',
                'Incoterms' => $this->sale->incoterms ?? 'FOB',
                'Incoterms_Ds' => mb_convert_encoding($this->sale->incoterms_ds ?? 'Free on Board', 'UTF-8'),
                'Forma_pago' => 'Transferencia',
                'Obs' => 'Exportacion',
                'Items' => [
                    'Item' => [
                        [
                            'Pro_codigo' => '001',
                            'Pro_ds' => mb_convert_encoding('Exportacion de bienes', 'UTF-8'),
                            'Pro_qty' => 1,
                            'Pro_umed' => 1,
                            'Pro_precio_uni' => (float) $this->sale->total,
                            'Pro_bonificacion' => 0,
                            'Pro_total_item' => (float) $this->sale->total,
                        ]
                    ]
                ]
            // ]
        ];


        Log::info('Se va a enviar fex_request:');
        Log::info((array)$fex_request);

        $result = $this->wsfex->FEXAuthorize($fex_request);

        Log::info((array)$result);

        return ['success' => $result['result']];
    }


    function set_numero_comprobante() {

        $data = [
            'Pto_venta' => $this->sale->afip_information->punto_venta,
            'Cbte_Tipo' => 19,
        ];

        $res = $this->wsfex->FEXGetLast_CMP($data, true);

        if ($res['result']->FEXGetLast_CMPResult) {
            $ultimo_comprobante = $res['result']->FEXGetLast_CMPResult->FEXResult_LastCMP->Cbte_nro;
            $this->numero_comprobante = $ultimo_comprobante + 1;
        }

        Log::info('res set_numero_comprobante:');
        Log::info((array)$res);
    }
}
