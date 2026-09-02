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
     * Claves internas de alicuota que entiende `AfipImportesCalculator::default_ivas()`.
     *
     * 🔴 Son claves INTERNAS, no porcentajes: '10' significa 10,5 % y '2' significa 2,5 %.
     * Un cliente de la API que mande '10.5' o '2.5' esta mandando una clave INVALIDA y tiene
     * que recibir un 422, nunca un descarte silencioso (ver validar_filas_importe_personalizado).
     *
     * 🔴 La lista NO se escribe aca: sale de `AfipImportesResolver::alicuotas()`, que es la fuente
     * unica de la tabla de alicuotas. Este metodo sigue existiendo porque es el CONTRATO de API
     * con `empresa-spa` y el 422 cuelga de el: `['27','21','10','5','2','0']`, en ese orden.
     *
     * @return array
     */
    public static function keys_de_alicuota_validas()
    {
        return AfipImportesResolver::keys();
    }

    /**
     * Criterio UNICO de validacion y normalizacion del reparto por alicuota.
     *
     * 🔴 Por que vive en un solo lugar: antes `SaleController` validaba que la suma de TODAS
     * las filas del request diera el importe, y despues el normalizador DESCARTABA en silencio
     * las filas con clave desconocida o importe <= 0. Lo que quedaba guardado ya no sumaba, y el
     * calculador le encajaba toda la diferencia a la ultima fila del orden fijo — que es la
     * alicuota MAS BAJA viva. Con `[{'10.5', 90000}, {'0', 10000}]` sobre 100000, se guardaba
     * solo la fila del 0 %, y a ARCA le salia una factura de 100.000 con IVA CERO.
     * Ahora las dos capas llaman a este metodo y no pueden volver a divergir.
     *
     * Reglas: todo o nada. Cualquier fila con clave fuera de `keys_de_alicuota_validas()` o con
     * importe no positivo hace fallar la validacion entera, en vez de descartarse.
     *
     * Ademas fuerza el redondeo a 2 decimales de cada importe. `facturar_importe_personalizado`
     * es `decimal(30,2)` en base, pero el reparto viaja en un `longText`: sin esta cuantizacion,
     * una fila de 3 decimales dejaba `ImpTotal != facturar_importe_personalizado` y podia generar
     * un `BaseImp` NEGATIVO al absorber el descuadre.
     *
     * @param mixed $filas Valor crudo de `importe_personalizado_ivas` (puede ser null o cualquier cosa).
     * @return array ['error' => string|null, 'filas' => array] con los importes ya redondeados.
     */
    public static function validar_filas_importe_personalizado($filas)
    {
        // Sin reparto no hay nada que validar: el calculador liquida todo al 21 %, como siempre.
        if (is_null($filas) || !is_array($filas) || count($filas) == 0) {
            return ['error' => null, 'filas' => []];
        }

        /** @var array $keys_validas Claves internas de alicuota aceptadas. */
        $keys_validas = self::keys_de_alicuota_validas();
        /** @var array $normalizadas Filas ya validadas y redondeadas a 2 decimales. */
        $normalizadas = [];

        foreach ($filas as $fila) {

            if (!is_array($fila) || !isset($fila['key'])) {
                return [
                    'error' => 'El reparto por alicuota tiene una fila sin alicuota indicada.',
                    'filas' => [],
                ];
            }

            /** @var string $key Clave interna de la alicuota. */
            $key = (string) $fila['key'];

            if (!in_array($key, $keys_validas, true)) {
                return [
                    'error' => 'La alicuota "'.$key.'" no es valida. Las alicuotas validas son: '.implode(', ', $keys_validas).'. Ojo: la clave 10 es 10,5 % y la clave 2 es 2,5 %.',
                    'filas' => [],
                ];
            }

            if (!isset($fila['importe']) || !is_numeric($fila['importe'])) {
                return [
                    'error' => 'La alicuota "'.$key.'" del reparto no tiene un importe valido.',
                    'filas' => [],
                ];
            }

            /** @var float $importe_fila Total de esa alicuota CON IVA, ya cuantizado a 2 decimales. */
            $importe_fila = round((float) $fila['importe'], 2);

            if ($importe_fila <= 0) {
                return [
                    'error' => 'La alicuota "'.$key.'" del reparto tiene un importe de '.$importe_fila.'. Sacala del reparto en vez de mandarla en cero.',
                    'filas' => [],
                ];
            }

            $normalizadas[] = [
                'key'     => $key,
                'importe' => $importe_fila,
            ];
        }

        return ['error' => null, 'filas' => $normalizadas];
    }

    /**
     * Normaliza el reparto por alicuota que viene del front para guardarlo en el ticket.
     *
     * Se guarda con `json_encode()` a mano (y se lee con `json_decode()` en
     * `AfipImportesCalculator`) para no tener que agregarle un cast a `App\Models\AfipTicket`.
     * `$guarded = []` hace que el mass-assign funcione igual.
     *
     * 🔴 NO descarta filas: usa el mismo criterio que `SaleController::makeAfipTicket()`
     * (`validar_filas_importe_personalizado()`), asi que lo que ya paso el 422 del controller
     * llega entero. Si igual llegara un reparto invalido — solo posible desde un llamador que
     * saltee el controller — se loguea como error y se guarda null, o sea que el importe entero
     * se liquida al 21 %. Es la caida CONSERVADORA: declara el maximo debito fiscal posible,
     * nunca menos.
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

        if (!isset($data['importe_personalizado_ivas'])) {
            return null;
        }

        /** @var array $validacion Resultado del criterio unico de validacion. */
        $validacion = self::validar_filas_importe_personalizado($data['importe_personalizado_ivas']);

        if (!is_null($validacion['error'])) {
            Log::error(
                'MakeAfipTicket: llego un reparto por alicuota invalido que no paso por la validacion '.
                'del controller ('.$validacion['error'].'). Se ignora el reparto y el importe se '.
                'liquida entero al 21 %.'
            );

            return null;
        }

        if (count($validacion['filas']) == 0) {
            return null;
        }

        return json_encode(array_values($validacion['filas']));
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
     * Devuelve null cuando el tope NO se puede determinar (hoy: configuracion fiscal
     * inexistente). Con null, el llamador tiene que SALTEAR la validacion, no rechazar:
     * antes de este guard, un `ventas_afip_information_id` invalido reventaba con
     * "Trying to get property 'iva_condition' of non-object" — un 500 — en vez de dejar
     * seguir al flujo, que mas adelante falla con su propio error.
     *
     * @return float|null Total en pesos que se facturaria sin importe personalizado, o null.
     */
    public static function get_tope_en_pesos($sale, $afip_information_id, $afip_tipo_comprobante_id)
    {
        if (is_null(AfipInformation::find($afip_information_id))) {
            Log::warning(
                'MakeAfipTicket::get_tope_en_pesos: no existe la afip_information id='.$afip_information_id.'. '.
                'No se puede calcular el tope, se saltea la validacion del importe personalizado.'
            );

            return null;
        }

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
