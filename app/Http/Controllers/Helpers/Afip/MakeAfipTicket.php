<?php

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\AfipWsController;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;

class MakeAfipTicket {
	
	function make_afip_ticket($data) {

        $sale 								= Sale::find($data['sale_id']);

        $afip_information 					= AfipInformation::find($data['afip_information_id']);
        $afip_tipo_comprobante 				= AfipTipoComprobante::find($data['afip_tipo_comprobante_id']);
        $afip_fecha_emision 				= isset($data['afip_fecha_emision']) && $data['afip_fecha_emision'] != '' && !is_null($data['afip_fecha_emision']) ? $data['afip_fecha_emision'] : date('Y-m-d');
        $facturar_importe_personalizado     = isset($data['facturar_importe_personalizado']) && $data['facturar_importe_personalizado'] > 0 ? $data['facturar_importe_personalizado'] : null;
        $forma_de_pago                      = isset($data['forma_de_pago']) && $data['forma_de_pago'] != '' ? $data['forma_de_pago'] : null;
        $permiso_existente 	                = isset($data['permiso_existente']) && $data['permiso_existente'] != '' ? $data['permiso_existente'] : 'N';

		$afip_ticket = AfipTicket::create([
            'cuit_negocio'      				=> $afip_information->cuit,
            'iva_negocio'       				=> $afip_information->iva_condition->name,
            'punto_venta'       				=> $afip_information->punto_venta,

            'iva_cliente'       				=> !is_null($sale->client) && !is_null($sale->client->iva_condition) ? $sale->client->iva_condition->name : '',
            'sale_id'           				=> $sale->id,
            'afip_information_id'       		=> $afip_information->id,
            'afip_tipo_comprobante_id'  		=> $afip_tipo_comprobante->id,
            'afip_fecha_emision'        		=> $afip_fecha_emision,
            'facturar_importe_personalizado'    => $facturar_importe_personalizado,
            'importe_personalizado_ivas_json'   => $this->normalizar_importe_personalizado_ivas($data, $facturar_importe_personalizado),
            'forma_de_pago'                     => $forma_de_pago,
            'permiso_existente'                 => $permiso_existente,
        ]);

        Log::info('Incoterms: '.$data['incoterms']);
        if ($data['incoterms']) {
            $sale->incoterms = $data['incoterms'];
            $sale->timestamps = false;
            $sale->save();
            Log::info('Se puso incoterms a sale: '.$sale->incoterms);
        }

            
        $ct = new AfipWsController($afip_ticket);
        $result = $ct->init();
	}

    /**
     * Normaliza el reparto por alicuota que viene del front para guardarlo en el ticket.
     *
     * Se guarda con `json_encode()` a mano (y se lee con `json_decode()` en
     * `AfipImportesCalculator`) para no tener que agregarle un cast a `App\Models\AfipTicket`.
     * `$guarded = []` hace que el mass-assign funcione igual.
     *
     * Devuelve null si no hay importe personalizado, si no vino reparto, o si despues de
     * filtrar no quedo ninguna fila util. Con null, el calculador liquida todo al 21 %,
     * que es el comportamiento historico.
     *
     * @param array $data Payload crudo de make_afip_ticket().
     * @param float|null $importe Importe personalizado ya normalizado (null si no hay).
     * @return string|null JSON con las filas validas, o null.
     */
    private function normalizar_importe_personalizado_ivas($data, $importe)
    {
        if (is_null($importe)) {
            return null;
        }

        if (!isset($data['importe_personalizado_ivas']) || !is_array($data['importe_personalizado_ivas'])) {
            return null;
        }

        /** @var array $keys_validas Claves internas de alicuota que entiende AfipImportesCalculator. */
        $keys_validas = ['27', '21', '10', '5', '2', '0'];
        /** @var array $filas Filas que sobreviven al filtro. */
        $filas = [];

        foreach ($data['importe_personalizado_ivas'] as $fila) {

            if (!is_array($fila) || !isset($fila['key'])) {
                continue;
            }

            if (!in_array((string) $fila['key'], $keys_validas, true)) {
                continue;
            }

            /** @var float $importe_fila Total de esa alicuota CON IVA. */
            $importe_fila = isset($fila['importe']) ? (float) $fila['importe'] : 0;

            if ($importe_fila <= 0) {
                continue;
            }

            $filas[] = [
                'key'     => (string) $fila['key'],
                'importe' => $importe_fila,
            ];
        }

        if (count($filas) == 0) {
            return null;
        }

        return json_encode(array_values($filas));
    }

    /**
     * Tope en PESOS del importe personalizado: es el total que el propio AfipHelper
     * facturaria para esa venta SIN importe personalizado.
     *
     * Se recalcula en el momento y NO se lee `sales.total_a_facturar`: esa columna la
     * escribe `SaleHelper::set_total_a_facturar()` unicamente en `SaleController::store()`,
     * asi que queda vieja apenas se edita la venta, y queda en null si al crear la venta
     * no habia afip_information_id.
     *
     * `facturar_importe_personalizado` va en null a proposito: si se dejara seteado, el
     * tope seria el propio importe personalizado y la validacion pasaria siempre.
     *
     * El total ya viene en pesos: `AfipItemCalculator::get_article_price_raw()` cotiza
     * cada item cuando la venta esta en dolares.
     *
     * @param \App\Models\Sale $sale Venta sobre la que se va a facturar.
     * @param int $afip_information_id Configuracion fiscal elegida en el modal.
     * @param int $afip_tipo_comprobante_id Tipo de comprobante elegido en el modal.
     * @return float Total en pesos que se facturaria sin importe personalizado.
     */
    public static function get_tope_en_pesos($sale, $afip_information_id, $afip_tipo_comprobante_id)
    {
        $afip_ticket = new AfipTicket();
        $afip_ticket->afip_information_id = $afip_information_id;
        $afip_ticket->afip_tipo_comprobante_id = $afip_tipo_comprobante_id;
        $afip_ticket->facturar_importe_personalizado = null;
        $afip_ticket->sale = $sale;

        $afip_helper = new AfipHelper($afip_ticket);
        $importes = $afip_helper->getImportes();

        return (float) $importes['total'];
    }
}
