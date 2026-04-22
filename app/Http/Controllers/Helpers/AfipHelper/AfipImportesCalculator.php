<?php

namespace App\Http\Controllers\Helpers\AfipHelper;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\Afip\AfipSelectedPaymentMethodsHelper;
use App\Http\Controllers\Helpers\AfipHelper;
use Illuminate\Support\Facades\Log;

class AfipImportesCalculator
{
    /**
     * Calcula los importes AFIP de una venta.
     *
     * @param AfipHelper $afip_helper Instancia principal con contexto de venta y ticket.
     * @return array Resumen de importes con detalle de IVA por alicuota.
     */
    public function calculate(AfipHelper $afip_helper)
    {
        /** @var array $ivas Estructura base de alicuotas para AFIP. */
        $ivas = $this->default_ivas();
        /** @var float $gravado Total gravado acumulado. */
        $gravado = 0;
        /** @var float $neto_no_gravado Total neto no gravado acumulado. */
        $neto_no_gravado = 0;
        /** @var float $exento Total exento acumulado. */
        $exento = 0;
        /** @var float $iva Total de IVA acumulado. */
        $iva = 0;
        /** @var float $total Total final de comprobante. */
        $total = 0;

        /** @var bool $is_responsable_inscripto Indica si la condición IVA del emisor es RI. */
        $is_responsable_inscripto = $afip_helper->afip_ticket->afip_information->iva_condition->name == 'Responsable inscripto';

        if ($is_responsable_inscripto) {
            $result = $this->calculate_for_responsable_inscripto($afip_helper, $ivas, $gravado, $neto_no_gravado, $exento, $iva);
            $ivas = $result['ivas'];
            $gravado = $result['gravado'];
            $neto_no_gravado = $result['neto_no_gravado'];
            $exento = $result['exento'];
            $iva = $result['iva'];
        } else {
            $result = $this->calculate_for_no_responsable_inscripto($afip_helper);
            $total = $result['total'];

            if ($afip_helper->afip_ticket->afip_information->iva_condition->name == 'Exento') {
                $exento = 0;
            } 
            
            $gravado = $result['gravado'];
        }

        /** @var float $neto_no_gravado Redondeo para salida consistente con cálculos históricos. */
        $neto_no_gravado = Numbers::redondear($neto_no_gravado);
        /** @var float $exento Redondeo para salida consistente con cálculos históricos. */
        $exento = Numbers::redondear($exento);
        /** @var float $iva Redondeo para salida consistente con cálculos históricos. */
        $iva = Numbers::redondear($iva);

        /**
         * Cuando el total no fue seteado en flujos RI, se arma desde sus componentes.
         */
        if ($total == 0) {
            $gravado = Numbers::redondear($gravado);
            $total = Numbers::redondear($gravado + $neto_no_gravado + $exento + $iva);
        }

        return [
            'gravado' => $gravado,
            'neto_no_gravado' => $neto_no_gravado,
            'exento' => $exento,
            'iva' => $iva,
            'ivas' => $ivas,
            'total' => $total,
        ];
    }

    /**
     * Retorna estructura base de alícuotas utilizada por AFIP.
     *
     * @return array
     */
    private function default_ivas()
    {
        return [
            '27' => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 6],
            '21' => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 5],
            '10' => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 4],
            '5' => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 8],
            '2' => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 9],
            '0' => ['BaseImp' => 0, 'Importe' => 0, 'Id' => 3],
        ];
    }

    /**
     * Calcula importes cuando la condición IVA del emisor es RI.
     *
     * @param AfipHelper $afip_helper Contexto principal.
     * @param array $ivas Acumulador de alicuotas.
     * @param float $gravado Acumulador gravado.
     * @param float $neto_no_gravado Acumulador neto no gravado.
     * @param float $exento Acumulador exento.
     * @param float $iva Acumulador iva.
     * @return array
     */
    private function calculate_for_responsable_inscripto(AfipHelper $afip_helper, $ivas, $gravado, $neto_no_gravado, $exento, $iva)
    {
        if ($afip_helper->factura_solo_algunos_metodos_de_pago) {
            Log::info('factura_solo_algunos_metodos_de_pago');

            /** @var AfipSelectedPaymentMethodsHelper $helper Calculador específico para medios seleccionados. */
            $helper = new AfipSelectedPaymentMethodsHelper($afip_helper->sale, $afip_helper->afip_selected_payment_methods);

            $gravado += $helper->get_gravado();
            $iva += $helper->get_importe_iva();
            $ivas['21']['Importe'] += $helper->get_importe_iva();
            $ivas['21']['BaseImp'] += $gravado;

            return [
                'ivas' => $ivas,
                'gravado' => $gravado,
                'neto_no_gravado' => $neto_no_gravado,
                'exento' => $exento,
                'iva' => $iva,
            ];
        }

        if ($afip_helper->afip_ticket->facturar_importe_personalizado) {
            /** @var float $importe_personalizado Importe final informado manualmente. */
            $importe_personalizado = (float) $afip_helper->afip_ticket->facturar_importe_personalizado;
            /** @var float $base_imponible Base imponible para alícuota 21%. */
            $base_imponible = round($importe_personalizado / 1.21, 2);
            /** @var float $importe_iva Diferencia correspondiente al IVA 21%. */
            $importe_iva = round($importe_personalizado - $base_imponible, 2);

            $gravado += $base_imponible;
            $iva += $importe_iva;
            $ivas['21']['Importe'] += $importe_iva;
            $ivas['21']['BaseImp'] += $base_imponible;

            return [
                'ivas' => $ivas,
                'gravado' => $gravado,
                'neto_no_gravado' => $neto_no_gravado,
                'exento' => $exento,
                'iva' => $iva,
            ];
        }

        return $this->calculate_from_sale_items($afip_helper, $ivas, $gravado, $neto_no_gravado, $exento, $iva);
    }

    /**
     * Recorre todos los ítems de venta para acumular importes en RI.
     *
     * @param AfipHelper $afip_helper Contexto principal.
     * @param array $ivas Acumulador de alícuotas.
     * @param float $gravado Acumulador gravado.
     * @param float $neto_no_gravado Acumulador neto no gravado.
     * @param float $exento Acumulador exento.
     * @param float $iva Acumulador iva.
     * @return array
     */
    private function calculate_from_sale_items(AfipHelper $afip_helper, $ivas, $gravado, $neto_no_gravado, $exento, $iva)
    {
        foreach ($afip_helper->articles as $article) {
            $afip_helper->article = $article;
            $afip_helper->article->is_article = true;

            $gravado += $afip_helper->getImporteGravado();
            $exento += $afip_helper->getImporteIva('Exento')['BaseImp'];
            $neto_no_gravado += $afip_helper->getImporteIva('No Gravado')['BaseImp'];
            $iva += $afip_helper->getImporteIva();

            $ivas = $this->add_iva_bucket($ivas, '27', $afip_helper->getImporteIva('27'));
            $ivas = $this->add_iva_bucket($ivas, '21', $afip_helper->getImporteIva('21'));
            $ivas = $this->add_iva_bucket($ivas, '10', $afip_helper->getImporteIva('10.5'));
            $ivas = $this->add_iva_bucket($ivas, '5', $afip_helper->getImporteIva('5'));
            $ivas = $this->add_iva_bucket($ivas, '2', $afip_helper->getImporteIva('2.5'));
            $ivas = $this->add_iva_bucket($ivas, '0', $afip_helper->getImporteIva('0'));
        }

        foreach ($afip_helper->sale->combos as $combo) {
            $combo_iva = $afip_helper->get_combo_iva($combo);
            $ivas = $this->add_iva_bucket($ivas, '21', $combo_iva);
            $gravado += $combo_iva['BaseImp'];
            $iva += $combo_iva['Importe'];
        }

        foreach ($afip_helper->services as $service) {
            $afip_helper->article = $service;
            $afip_helper->article->is_service = true;

            $service_iva = $afip_helper->get_combo_iva($service);
            $ivas = $this->add_iva_bucket($ivas, '21', $service_iva);
            $gravado += $service_iva['BaseImp'];
            $iva += $service_iva['Importe'];
        }

        foreach ($afip_helper->sale->promocion_vinotecas as $promo) {
            Log::info('Pidiendo iva de ' . $promo->name);
            $promo_iva = $afip_helper->get_combo_iva($promo);
            Log::info($promo_iva);

            $ivas = $this->add_iva_bucket($ivas, '21', $promo_iva);
            $gravado += $promo_iva['BaseImp'];
            $iva += $promo_iva['Importe'];
        }

        foreach ($afip_helper->descriptions as $description) {
            Log::info('Pidiendo iva de ' . $description->notes);
            /** @var float $iva_percentage Si no hay IVA explícito se asume 21. */
            $iva_percentage = $description->iva ? (float) $description->iva->percentage : 21;
            $description_iva = $afip_helper->get_description_iva($description, $iva_percentage);
            $ivas = $this->add_iva_bucket($ivas, (string) $iva_percentage, $description_iva);
            $gravado += $description_iva['BaseImp'];
            $iva += $description_iva['Importe'];
        }

        return [
            'ivas' => $ivas,
            'gravado' => $gravado,
            'neto_no_gravado' => $neto_no_gravado,
            'exento' => $exento,
            'iva' => $iva,
        ];
    }

    /**
     * Calcula importes cuando no aplica lógica RI detallada.
     *
     * @param AfipHelper $afip_helper Contexto principal.
     * @return array
     */
    private function calculate_for_no_responsable_inscripto(AfipHelper $afip_helper)
    {
        /** @var float $total_a_facturar Total bruto sujeto a facturación. */
        $total_a_facturar = $afip_helper->sale->total;

        if (!is_null($afip_helper->afip_ticket->facturar_importe_personalizado)) {
            $total_a_facturar = $afip_helper->afip_ticket->facturar_importe_personalizado;
        }

        /**
         * Si viene desde nota de crédito parcial, se calcula únicamente con artículos recibidos.
         */
        if ($afip_helper->nota_credito_model && count($afip_helper->articles) >= 1) {
            $total_a_facturar = 0;
            foreach ($afip_helper->articles as $article) {
                $total_article = (float) $article->pivot->price * (float) $article->pivot->amount;
                if ($article->pivot->discount) {
                    $total_article -= $total_article * (float)$article->pivot->discount / 100;
                }
                $total_a_facturar += $total_article;
            }
        }

        /**
         * Si la venta está en USD y tiene cotización, se pasa a moneda local.
         */
        if ($afip_helper->sale->moneda_id == 2 && !is_null($afip_helper->sale->valor_dolar)) {
            $total_a_facturar *= (float) $afip_helper->sale->valor_dolar;
        }

        return [
            'total' => $total_a_facturar,
            'gravado' => $total_a_facturar,
        ];
    }

    /**
     * Acumula importes en el bucket de alícuota correspondiente.
     *
     * @param array $ivas Acumulador principal.
     * @param string $bucket_key Clave interna del bucket.
     * @param array $result Resultado con BaseImp e Importe.
     * @return array
     */
    private function add_iva_bucket($ivas, $bucket_key, $result)
    {
        if (!isset($ivas[$bucket_key])) {
            $ivas[$bucket_key] = ['BaseImp' => 0, 'Importe' => 0, 'Id' => 0];
        }

        $ivas[$bucket_key]['Importe'] += $result['Importe'];
        $ivas[$bucket_key]['BaseImp'] += $result['BaseImp'];

        return $ivas;
    }
}
