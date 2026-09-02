<?php

namespace App\Http\Controllers\Helpers\AfipHelper;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\Afip\AfipImportesResolver;
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
     * Estructura base de alicuotas (acumulador de buckets), armada desde la FUENTE UNICA.
     *
     * 🔴 Las claves NO son el porcentaje: `'10'` es 10,5 % y `'2'` es 2,5 %. La tabla —claves,
     * Id de ARCA y porcentajes reales— vive en `AfipImportesResolver::alicuotas()` y en ningun
     * otro lado. Volver a escribir el literal aca es lo que tenia al sistema con dos convenciones
     * de clave a la vez.
     *
     * El ORDEN sale de `alicuotas()` y tiene que seguir siendo '27','21','10','5','2','0': el
     * reparto del importe personalizado le encaja el descuadre de centavos a la ULTIMA fila viva.
     *
     * @return array
     */
    private function default_ivas()
    {
        /** @var array $ivas Acumulador vacio, una entrada por alicuota. */
        $ivas = [];

        foreach (AfipImportesResolver::alicuotas() as $key => $alicuota) {
            $ivas[$key] = ['BaseImp' => 0, 'Importe' => 0, 'Id' => (int) $alicuota['id']];
        }

        return $ivas;
    }

    /**
     * Lee el reparto por alicuota que cargo el usuario junto al importe personalizado.
     *
     * Se lee con `json_decode()` a mano (y no con un cast del modelo) para no tocar
     * `App\Models\AfipTicket`. `$guarded = []` hace que el mass-assign del `json_encode()`
     * que escribe `MakeAfipTicket` funcione igual.
     *
     * Las filas se recorren en el orden fijo de `default_ivas()` ('27','21','10','5','2','0')
     * y no en el orden en que las mando el front: asi el resultado es determinista, y sobre
     * todo la "ultima fila" que absorbe el descuadre es siempre la de alicuota MENOR.
     *
     * @param AfipHelper $afip_helper Contexto principal.
     * @return array|null Filas normalizadas con `key`, `porcentaje` e `importe`, o null si no hay reparto.
     */
    private function get_filas_importe_personalizado(AfipHelper $afip_helper)
    {
        /** @var string|null $json Reparto crudo tal como quedo persistido en el ticket. */
        $json = $afip_helper->afip_ticket->importe_personalizado_ivas_json;

        if (is_null($json) || $json === '') {
            return null;
        }

        /** @var mixed $data Reparto decodificado. */
        $data = json_decode($json, true);

        if (!is_array($data) || count($data) == 0) {
            return null;
        }

        /** @var array $filas Filas normalizadas, en el orden fijo de default_ivas(). */
        $filas = [];

        foreach (array_keys($this->default_ivas()) as $key) {

            foreach ($data as $fila) {

                if (!is_array($fila) || !isset($fila['key']) || (string) $fila['key'] !== (string) $key) {
                    continue;
                }

                /**
                 * @var float $importe Total de esa alicuota CON IVA, cuantizado a 2 decimales.
                 * El redondeo se re-fuerza aca y no se asume: el reparto viaja en un `longText`,
                 * asi que una fila con 3 decimales (de un llamador que saltee el controller, o de
                 * una fila vieja en base) rompe el invariante (a) y puede dejar un BaseImp negativo.
                 */
                $importe = isset($fila['importe']) && is_numeric($fila['importe']) ? round((float) $fila['importe'], 2) : 0;

                // Una fila en 0 (o negativa) no genera bucket: ensucia los logs y AfipWsfeHelper
                // la filtraria igual antes de mandarla.
                if ($importe <= 0) {
                    continue;
                }

                // 🔴 El porcentaje real sale de la fuente unica, NUNCA de castear la clave:
                // la clave '10' vale 10,5 % y la '2' vale 2,5 %.
                /** @var float|null $porcentaje Porcentaje real de la clave interna. */
                $porcentaje = AfipImportesResolver::porcentaje_de_clave($key);

                $filas[] = [
                    'key'        => (string) $key,
                    'porcentaje' => is_null($porcentaje) ? 0.0 : $porcentaje,
                    'importe'    => $importe,
                ];
            }
        }

        if (count($filas) == 0) {
            return null;
        }

        return $filas;
    }

    /**
     * Reparte el importe personalizado entre las alicuotas informadas, haciendo el back-out
     * del IVA fila por fila.
     *
     * Invariantes que este metodo garantiza (y que blindan los tests del grupo `facturacion`):
     *
     * (a) POR FILA: el IVA se calcula por RESTA (`importe_fila - base`), jamas como
     *     `base * pct / 100`. `importe_fila` viene cuantizado a 2 decimales — el redondeo se
     *     FUERZA en `MakeAfipTicket::validar_filas_importe_personalizado()` al guardar y se
     *     vuelve a forzar en `get_filas_importe_personalizado()` al leer, no se asume — y `base`
     *     sale de un `round(..., 2)`, asi que la resta tiene exactamente 2 decimales y
     *     `base + iva_fila == importe_fila` es EXACTO, sin residuo.
     *     Sin esa cuantizacion, una fila de 3 decimales dejaba `ImpTotal` distinto del importe
     *     autorizado y podia generar un `BaseImp` NEGATIVO al absorber el descuadre.
     *
     * (b) GLOBAL: el ajuste del descuadre corre ANTES del back-out, asi que
     *     `suma(importe_fila) == importe_personalizado` exacto. Con (a), eso da
     *     `suma(BaseImp) + suma(Importe) == importe_personalizado` exacto, y el ImpTotal que
     *     `calculate()` manda a ARCA es EXACTAMENTE el importe que autorizo el usuario.
     *
     * (c) QUIEN ABSORBE: la ULTIMA fila con importe > 0 en el orden fijo '27','21','10','5','2','0'.
     *     Ultima y no primera para que el centavo caiga en la alicuota MENOR, minimizando el
     *     impacto fiscal. Se ajusta el importe TOTAL de la fila (antes del back-out), no
     *     `Importe`/`BaseImp` por separado, para no romper la relacion `Importe ~= BaseImp x alicuota`
     *     que ARCA valida por fila.
     *
     * (d) Este metodo NUNCA lanza excepcion: su contrato es devolver siempre importes coherentes.
     *     Un descuadre grande (mas de un centavo) se loguea y se reparte igual.
     *
     *     🔴 Ojo con el 422 de `SaleController::makeAfipTicket()`: valida la suma del reparto
     *     contra el importe ANTES de guardar, pero NO puede impedir un descuadre aca. Si un
     *     descuadre grande llega a este metodo, el 422 ya paso — por un llamador que saltea el
     *     controller, o por un reparto viejo en base. Por eso el warning es la unica senal, y
     *     por eso las dos capas comparten el criterio de validacion (ver
     *     `MakeAfipTicket::validar_filas_importe_personalizado()`).
     *
     * @param array $ivas Acumulador de alicuotas (estructura de default_ivas()).
     * @param float $importe_personalizado Importe total a facturar, en pesos.
     * @param array $filas Filas normalizadas por get_filas_importe_personalizado(); vacio = todo al 21 %.
     * @return array Con `ivas`, `gravado` e `iva`.
     */
    private function repartir_importe_personalizado($ivas, $importe_personalizado, $filas)
    {
        /** @var float $gravado Base imponible total repartida. */
        $gravado = 0;
        /** @var float $iva IVA total repartido. */
        $iva = 0;

        // Sin reparto explicito se conserva el comportamiento historico: todo al 21 %.
        if (count($filas) == 0) {
            $filas = [
                [
                    'key'        => '21',
                    'porcentaje' => 21.0,
                    'importe'    => $importe_personalizado,
                ],
            ];
        }

        // Paso 1 — ajuste del descuadre, ANTES del back-out (invariante (b)).
        /** @var float $suma Suma de los importes de todas las filas. */
        $suma = 0;
        foreach ($filas as $fila) {
            $suma += (float) $fila['importe'];
        }

        /** @var float $diferencia Lo que le falta (o le sobra) al reparto para dar el importe. */
        $diferencia = round($importe_personalizado - $suma, 2);

        if ($diferencia != 0) {

            /** @var int $ultimo Indice de la ultima fila del orden fijo: la de alicuota menor (invariante (c)). */
            $ultimo = count($filas) - 1;
            $filas[$ultimo]['importe'] = round((float) $filas[$ultimo]['importe'] + $diferencia, 2);

            if (abs($diferencia) > 0.01) {
                Log::warning(
                    'AfipImportesCalculator: el reparto por alicuota del importe personalizado descuadraba en '.
                    $diferencia.' (importe: '.$importe_personalizado.', suma de filas: '.$suma.'). '.
                    'Se absorbio en la alicuota '.$filas[$ultimo]['key'].'.'
                );
            }
        }

        // Paso 2 — back-out del IVA fila por fila (invariante (a)).
        foreach ($filas as $fila) {

            /** @var float $porcentaje Porcentaje REAL de la alicuota (10,5 y 2,5 incluidos). */
            $porcentaje = (float) $fila['porcentaje'];
            /** @var float $importe_fila Total de la fila CON IVA. */
            $importe_fila = (float) $fila['importe'];

            // El divisor sale del porcentaje, igual que AfipItemCalculator::get_price_without_iva().
            // Nada de hardcodear 1.105.
            /** @var float $base Base imponible de la fila. */
            $base = round($importe_fila / (($porcentaje / 100) + 1), 2);
            /** @var float $iva_fila IVA de la fila, SIEMPRE por resta. */
            $iva_fila = round($importe_fila - $base, 2);

            if (!isset($ivas[$fila['key']])) {
                $ivas[$fila['key']] = ['BaseImp' => 0, 'Importe' => 0, 'Id' => 0];
            }

            $ivas[$fila['key']]['BaseImp'] += $base;
            $ivas[$fila['key']]['Importe'] += $iva_fila;

            $gravado += $base;
            $iva += $iva_fila;
        }

        return [
            'ivas'    => $ivas,
            'gravado' => $gravado,
            'iva'     => $iva,
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
        /**
         * Ojo con el orden: este bloque va ANTES del importe personalizado y le GANA. Si el
         * usuario tiene metodos de pago seleccionados para facturar, el importe personalizado
         * (y su reparto por alicuota) se ignoran por completo. Es comportamiento preexistente
         * y se preserva tal cual: no se toca.
         */
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
            /** @var float $importe_personalizado Importe final informado manualmente, SIEMPRE en pesos. */
            $importe_personalizado = (float) $afip_helper->afip_ticket->facturar_importe_personalizado;
            /** @var array|null $filas Reparto por alicuota que cargo el usuario, o null si no cargo ninguno. */
            $filas = $this->get_filas_importe_personalizado($afip_helper);
            /** @var array $resultado Reparto ya resuelto: buckets de IVA, gravado e iva. */
            $resultado = $this->repartir_importe_personalizado($ivas, $importe_personalizado, is_null($filas) ? [] : $filas);

            return [
                'ivas' => $resultado['ivas'],
                'gravado' => $gravado + $resultado['gravado'],
                'neto_no_gravado' => $neto_no_gravado,
                'exento' => $exento,
                'iva' => $iva + $resultado['iva'],
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
        /**
         * @var bool $es_importe_personalizado El importe personalizado ya viene en PESOS: no se cotiza.
         * Sin esta marca, una venta en dolares con importe personalizado se multiplicaba de nuevo
         * por `valor_dolar` y salia hacia ARCA con un total inflado por la cotizacion.
         */
        $es_importe_personalizado = false;

        if (!is_null($afip_helper->afip_ticket->facturar_importe_personalizado)) {
            $total_a_facturar = $afip_helper->afip_ticket->facturar_importe_personalizado;
            $es_importe_personalizado = true;
        }

        /**
         * Si viene desde nota de crédito parcial, se calcula únicamente con artículos recibidos.
         *
         * Esta rama PISA el importe personalizado (va despues a proposito, como hoy) y arma el
         * total desde `pivot->price`, que SI esta en la moneda de la venta: vuelve a necesitar
         * la cotizacion, asi que se baja la marca.
         */
        if ($afip_helper->nota_credito_model && count($afip_helper->articles) >= 1) {
            $es_importe_personalizado = false;
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
        if (
            !$es_importe_personalizado
            && $afip_helper->sale->moneda_id == 2
            && !is_null($afip_helper->sale->valor_dolar)
        ) {
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
