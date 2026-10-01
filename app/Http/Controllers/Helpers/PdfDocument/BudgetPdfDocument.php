<?php

namespace App\Http\Controllers\Helpers\PdfDocument;

use App\Http\Controllers\Helpers\Budget\ComboEsquemaHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Article;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Adaptador de un `Budget` (presupuesto) para `ProfileDocumentPdf`.
 *
 * La plata del presupuesto se copia FIEL de `BudgetPdf::discountsSurchages()` y `total()`, que
 * son el PDF de siempre y el que ya ven los clientes: Sub Total (solo si hay algo que lo separe
 * del total), descuentos, recargos (salvo `aplicar_recargos_directo_a_items`), ajuste del total
 * forzado y, al final, el Total que sale de `BudgetHelper::getTotal()`. Cualquier cambio en esa
 * cuenta se hace en `BudgetHelper`, no acá: este archivo solo la escribe en renglones de texto.
 */
class BudgetPdfDocument implements PdfDocumentSource
{
    /**
     * Presupuesto que se imprime.
     *
     * @var \App\Models\Budget
     */
    private $budget;

    /**
     * Renglones ya armados (memoizados: `items()` se llama varias veces por PDF).
     *
     * @var array|null
     */
    private $items = null;

    /**
     * Suma de los renglones con la bonificación aplicada (memoizada: la piden el Sub Total y la
     * condición que decide si se imprime).
     *
     * @var float|null
     */
    private $total_original = null;

    /**
     * @param \App\Models\Budget $budget
     */
    public function __construct($budget)
    {
        $this->budget = $budget;
    }

    /**
     * El presupuesto adaptado. Lo lee el diseño con cajas (`CamposDePresupuestoPdf`) para los datos
     * que el PDF de siempre no imprime (estado, lista de precios, sucursal, moneda).
     *
     * @return \App\Models\Budget
     */
    public function budget()
    {
        return $this->budget;
    }

    /** @return string */
    public function model_name()
    {
        return 'budget';
    }

    /** @return int */
    public function owner_id()
    {
        return (int) $this->budget->user_id;
    }

    /** @return string */
    public function title()
    {
        return 'Presupuesto';
    }

    /**
     * El "documento" que lee el encabezado del remito (`AfipPdfHelper::header_comercial()`):
     * `num`, `created_at`, `address`, `client`, `seller` y `employee`.
     *
     * Es un objeto adaptador, como el del pedido, y no el `Budget` a secas, por el rótulo del
     * vendedor: el `BudgetPdf` de siempre imprimía "Vendedor: <empleado que lo cargó>", pero el
     * encabezado del remito rotula el campo `empleado` como "Empleado:" y el campo `vendedor`
     * como "Vendedor:" (y lo lee de `seller`, que un presupuesto no tiene). Poniendo el empleado
     * también en `seller`, el diseño por defecto usa el campo `vendedor` y el cliente final sigue
     * leyendo "Vendedor:" como antes. No simplificar devolviendo `$this->budget`.
     *
     * @return \stdClass
     */
    public function header_document()
    {
        $document = new \stdClass();
        $document->num = $this->budget->num;
        $document->created_at = $this->budget->created_at;
        $document->address = $this->budget->address;
        $document->client = $this->budget->client;
        $document->seller = $this->budget->employee;
        $document->employee = $this->budget->employee;

        return $document;
    }

    /**
     * Artículos, promociones, combos y servicios, en ese orden (el mismo de `BudgetPdf::items()`).
     *
     * Los combos salen por `ComboEsquemaHelper` y no por `$budget->combos`: en un cliente que
     * todavía no corrió la migración de `budget_combo`, tocar la relación tumba el PDF entero.
     *
     * @return array
     */
    public function items()
    {
        if (! is_null($this->items)) {
            return $this->items;
        }

        $items = [];

        foreach ($this->budget->articles as $item) {
            $items[] = $item;
        }
        foreach ($this->budget->promocion_vinotecas as $item) {
            $items[] = $item;
        }
        foreach (ComboEsquemaHelper::combos_del_presupuesto($this->budget) as $item) {
            $items[] = $item;
        }
        foreach ($this->budget->services as $item) {
            $items[] = $item;
        }

        $this->items = $items;

        return $this->items;
    }

    /**
     * Precarga imágenes (ordenadas por id: la "primera" tiene que ser siempre la misma) y
     * marca/categoría/subcategoría/proveedor de los artículos. Solo los artículos: los demás
     * renglones no declaran esas relaciones.
     *
     * @param bool $with_images
     * @param bool $with_relations
     * @return void
     */
    public function preload_item_relations($with_images, $with_relations)
    {
        /**
         * Se trabaja sobre los renglones YA armados y no sobre `$budget->articles`:
         * `BudgetHelper::getTotal()` recarga esa relación con instancias nuevas, y una precarga
         * hecha sobre las nuevas no llegaría a los renglones que el PDF realmente imprime.
         */
        $articles = new EloquentCollection();
        foreach ($this->items() as $item) {
            if ($item instanceof Article) {
                $articles->push($item);
            }
        }

        if ($articles->count() < 1) {
            return;
        }

        if ($with_relations) {
            $articles->loadMissing(['brand', 'category', 'sub_category', 'provider']);
        }

        if ($with_images) {
            $articles->load(['images' => function ($query) {
                $query->orderBy('id', 'asc');
            }]);
        }
    }

    /**
     * Mismo criterio que `BudgetPdf::printArticle()`: el nombre personalizado del renglón (o el
     * del artículo con su variante).
     *
     * @param object $item
     * @return string
     */
    public function item_name($item)
    {
        return (string) GeneralHelper::article_name($item);
    }

    /**
     * Precio por cantidad menos la bonificación del renglón (`pivot->bonus`), que es lo que
     * `BudgetHelper::getTotal()` suma.
     *
     * @param object $item
     * @return float
     */
    public function item_subtotal($item)
    {
        return (float) BudgetHelper::totalArticle($item);
    }

    /**
     * @return string|null
     */
    public function observations()
    {
        $observations = $this->budget->observations;

        return trim((string) $observations) === '' ? null : (string) $observations;
    }

    /** @return string */
    public function observations_title()
    {
        return 'OBSERVACIONES';
    }

    /**
     * Renglones de la caja de totales.
     *
     * 🔴 Con `show_total_in_footer` apagado no se imprime NINGÚN renglón (ni el Sub Total ni los
     * descuentos): es el diseño "sin precios", y un descuento suelto sin importe no dice nada.
     *
     * @param array $flags
     * @return array
     */
    public function totals_rows($flags)
    {
        if (empty($flags['show_total_in_footer'])) {
            return [];
        }

        $rows = [];

        /**
         * Los renglones salen de las mismas piezas que usa el diseño con cajas
         * (`CamposDePresupuestoPdf`): así los dos PDF dicen lo mismo. Lo que devuelve este método
         * no cambió al partirlo (lo cuidan los tests 10 y 12 de tests/Feature/Pdf).
         */
        if (! empty($flags['show_subtotal_in_footer']) && $this->hay_diferencia()) {
            $rows[] = [
                'text' => 'Sub Total sin descuentos: '.$this->texto_sub_total(),
                'bold' => true,
            ];
        }

        foreach ($this->renglones_de_descuentos() as $renglon) {
            $rows[] = [
                'text' => $renglon,
                'bold' => false,
            ];
        }

        foreach ($this->renglones_de_recargos() as $renglon) {
            $rows[] = [
                'text' => $renglon,
                'bold' => false,
            ];
        }

        /** El ajuste del total forzado va ÚLTIMO, que es el orden en que se aplica en getTotal(). */
        $ajuste = $this->renglon_ajuste_del_total();
        if (! is_null($ajuste)) {
            $rows[] = [
                'text' => $ajuste,
                'bold' => false,
            ];
        }

        /** El Total sale de getTotal() (fuente de verdad), nunca de un acumulado propio. */
        $rows[] = [
            'text' => 'Total: '.$this->texto_total(),
            'bold' => true,
        ];

        return $rows;
    }

    // ── Piezas de la caja de totales (las usan totals_rows() y el diseño con cajas) ──────────

    /**
     * Suma de los renglones, con la bonificación por línea aplicada.
     *
     * @return float
     */
    public function total_original()
    {
        if (is_null($this->total_original)) {
            $total_original = 0;
            foreach ($this->items() as $item) {
                $total_original += $this->item_subtotal($item);
            }
            $this->total_original = $total_original;
        }

        return $this->total_original;
    }

    /**
     * Monto con signo del total forzado (0 = no se forzó).
     *
     * @return float
     */
    public function monto_forzado()
    {
        return SaleHelper::get_forzar_total_monto($this->budget);
    }

    /**
     * ¿Hay algo que separe el Sub Total del Total? Es lo que decide si se imprime el Sub Total.
     *
     * El forzado hacia arriba deja el subtotal MENOR que el total, por eso se nombra aparte. Se
     * compara redondeado a centavos: el ruido de coma flotante de una suma de renglones no puede
     * inventar un "Sub Total" idéntico al Total.
     *
     * @return bool
     */
    public function hay_diferencia()
    {
        return round($this->total_original(), 2) > round((float) $this->budget->total, 2)
            || $this->monto_forzado() != 0;
    }

    /**
     * Valor del Sub Total, sin el rótulo ("$2.300").
     *
     * @return string
     */
    public function texto_sub_total()
    {
        return '$'.Numbers::price($this->total_original());
    }

    /**
     * Un renglón por descuento ("- 10% Descuento por volumen").
     *
     * @return array<int, string>
     */
    public function renglones_de_descuentos()
    {
        $renglones = [];

        foreach ($this->budget->discounts as $discount) {
            $renglones[] = '- '.Numbers::price($discount->pivot->percentage).'% '.$discount->name;
        }

        return $renglones;
    }

    /**
     * Un renglón por recargo ("+ 5% Recargo financiero").
     *
     * Con `aplicar_recargos_directo_a_items` el recargo YA ESTÁ adentro del precio de cada
     * renglón: listarlo también acá se lee como que se suma dos veces (pedido de Lucas). Los
     * descuentos sí se listan siempre, porque nunca viajan adentro del precio.
     *
     * @return array<int, string>
     */
    public function renglones_de_recargos()
    {
        $renglones = [];

        if (! $this->budget->aplicar_recargos_directo_a_items) {
            foreach ($this->budget->surchages as $surchage) {
                $renglones[] = '+ '.Numbers::price($surchage->pivot->percentage).'% '.$surchage->name;
            }
        }

        return $renglones;
    }

    /**
     * El renglón del ajuste del total forzado ("- $173,50 Ajuste del total"), o null si no se forzó.
     *
     * @return string|null
     */
    public function renglon_ajuste_del_total()
    {
        $monto_forzado = $this->monto_forzado();

        if ($monto_forzado == 0) {
            return null;
        }

        $signo = $monto_forzado < 0 ? '- ' : '+ ';

        return $signo.'$'.Numbers::price(abs($monto_forzado)).' Ajuste del total';
    }

    /**
     * Valor del Total, sin el rótulo ("$2.173,50"): sale de `BudgetHelper::getTotal()`, la fuente
     * de verdad, nunca de `budgets.total` ni de un acumulado propio.
     *
     * @return string
     */
    public function texto_total()
    {
        return '$'.Numbers::price(BudgetHelper::getTotal($this->budget));
    }
}
