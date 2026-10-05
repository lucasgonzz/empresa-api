<?php

namespace App\Http\Controllers\Helpers\PdfLayout;

use App\Http\Controllers\Helpers\PdfDocument\BudgetPdfDocument;
use Carbon\Carbon;

/**
 * Valores de los campos del catálogo de PRESUPUESTO (`CatalogoDeCamposPdf::campos('budget')`) para
 * un presupuesto dibujado con un diseño de página (misión diseno-pdf-configurable, 1/10/2026).
 *
 * Se apoya en el adaptador de siempre (`BudgetPdfDocument`) y en su modelo. La plata NO se calcula
 * acá: sale de las MISMAS piezas con que el adaptador arma `totals_rows()` (el pie del presupuesto
 * de siempre), así que con la etiqueta del catálogo cada renglón dice exactamente lo mismo
 * ("Sub Total sin descuentos: $2.300", "- 10% Descuento por volumen", "Total: $2.173,50").
 *
 * En el diseño con cajas el flag "Mostrar Sub Total" no existe: si la caja tiene el campo Sub
 * Total, sale cuando hay algo que lo separe del Total (la condición de siempre).
 */
class CamposDePresupuestoPdf implements FuenteDeCamposPdf
{
    /** @var BudgetPdfDocument */
    private $documento;

    /** @var \App\Models\Budget */
    private $budget;

    /** @var bool "Imprimir con fecha actual" del perfil. */
    private $use_current_date;

    /**
     * @param BudgetPdfDocument $documento
     * @param bool              $use_current_date
     */
    public function __construct(BudgetPdfDocument $documento, $use_current_date)
    {
        $this->documento = $documento;
        $this->budget = $documento->budget();
        $this->use_current_date = (bool) $use_current_date;
    }

    /** @return string */
    public function model_name()
    {
        return 'budget';
    }

    /**
     * @param string $key
     * @param array  $campo
     * @return string|array|null
     */
    public function valor($key, $campo)
    {
        if (strpos($key, 'cliente_') === 0) {
            return CamposDeVentaPdf::valor_de_cliente($key, $this->budget->client);
        }

        switch ($key) {
            case 'presupuesto_numero':
                return CamposDeVentaPdf::texto($this->budget->num);
            case 'presupuesto_fecha':
                /** Misma regla que el encabezado (`AfipPdfHelper::header_comercial()`). */
                $fecha = ($this->use_current_date || is_null($this->budget->created_at)) ? now() : Carbon::parse($this->budget->created_at);

                return $fecha->format('d/m/Y');
            case 'presupuesto_vendedor':
                /** El empleado que lo cargó: es lo que el presupuesto de siempre rotula "Vendedor". */
                return $this->budget->employee ? CamposDeVentaPdf::texto($this->budget->employee->name) : null;
            case 'presupuesto_sucursal':
                return CamposDeVentaPdf::sucursal($this->budget->address);
            case 'presupuesto_lista_de_precios':
                return $this->budget->price_type ? CamposDeVentaPdf::texto($this->budget->price_type->name) : null;
            case 'presupuesto_estado':
                return $this->budget->budget_status ? CamposDeVentaPdf::texto($this->budget->budget_status->name) : null;
            case 'presupuesto_moneda':
                return CamposDeVentaPdf::moneda($this->budget->moneda_id);
            case 'presupuesto_cotizacion':
                return CamposDeVentaPdf::cotizacion($this->budget->moneda_id, $this->budget->valor_dolar);
            case 'presupuesto_observaciones':
                return CamposDeVentaPdf::texto_largo($this->documento->observations());

            case 'tot_subtotal':
                return $this->documento->hay_diferencia() ? $this->documento->texto_sub_total() : null;
            case 'tot_descuentos':
                return CamposDeVentaPdf::lista($this->documento->renglones_de_descuentos());
            case 'tot_recargos':
                return CamposDeVentaPdf::lista($this->documento->renglones_de_recargos());
            case 'tot_ajuste_metodo_de_pago':
                /** El mismo renglón que totals_rows() ("- $50 Descuento por método de pago"). */
                return $this->documento->renglon_ajuste_por_metodo_de_pago();
            case 'tot_ajuste_del_total':
                return $this->documento->renglon_ajuste_del_total();
            case 'tot_total':
                return $this->documento->texto_total();

            case CatalogoDeCamposPdf::KEY_TEXTO_LIBRE:
                return CamposDeVentaPdf::texto_libre($campo);
        }

        throw new \InvalidArgumentException('Campo del PDF de presupuesto sin resolver: '.$key);
    }
}
