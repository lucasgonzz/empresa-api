<?php

namespace App\Http\Controllers\Helpers\AfipHelper;

use Illuminate\Support\Facades\Log;

class AfipWsfeHelper
{
    /**
     * Devuelve código de tipo de documento esperado por AFIP.
     *
     * @param string $slug Nombre del documento.
     * @return int
     */
    public static function get_doc_type($slug)
    {
        /** @var array $doc_type Mapa de documento a código AFIP. */
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

    /**
     * Consulta el último comprobante autorizado y calcula el próximo número.
     *
     * @param object $wsfe Cliente WSFE.
     * @param int $punto_venta Punto de venta AFIP.
     * @param int $cbte_tipo Tipo de comprobante.
     * @return array
     */
    public static function get_numero_comprobante($wsfe, $punto_venta, $cbte_tipo)
    {
        /** @var array $pto_vta Payload requerido por FECompUltimoAutorizado. */
        $pto_vta = [
            'PtoVta' => $punto_venta,
            'CbteTipo' => $cbte_tipo,
        ];
        /** @var array $result Resultado de la consulta WSFE. */
        $result = $wsfe->FECompUltimoAutorizado($pto_vta);

        Log::info('getNumeroComprobante');
        Log::info((array) $result);

        if (!$result['hubo_un_error']) {
            return [
                'hubo_un_error' => false,
                'numero_comprobante' => $result['result']->FECompUltimoAutorizadoResult->CbteNro + 1,
            ];
        }

        return [
            'hubo_un_error' => true,
            'error' => $result['error'],
        ];
    }
}
