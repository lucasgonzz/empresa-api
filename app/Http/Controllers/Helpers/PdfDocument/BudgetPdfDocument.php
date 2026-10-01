<?php

namespace App\Http\Controllers\Helpers\PdfDocument;

use App\Http\Controllers\Helpers\Budget\BudgetCobroHelper;
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
     * @param \App\Models\Budget $budget
     */
    public function __construct($budget)
    {
        $this->budget = $budget;
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

        /** Suma de los renglones, con la bonificación por línea aplicada. */
        $total_original = 0;
        foreach ($this->items() as $item) {
            $total_original += $this->item_subtotal($item);
        }

        /** Monto con signo del total forzado (0 = no se forzó). */
        $monto_forzado = SaleHelper::get_forzar_total_monto($this->budget);

        /**
         * Descuento o recargo por método de pago de un presupuesto de contado (misión
         * presupuesto-contado-o-cuenta-corriente, 1/10/2026): signo + = recargo, - = descuento,
         * 0 = no hay. El Total de abajo sale de `BudgetHelper::getTotal()`, que ya lo incluye;
         * sin un renglón que lo nombre, el pie no suma contra los renglones y el cliente lee un
         * Total que nadie le explica. Es la misma cuenta que escribe el PDF de siempre
         * (`BudgetPdf::discountsSurchages()`).
         */
        $ajuste_por_metodo_de_pago = BudgetCobroHelper::ajuste_por_metodos_de_pago($this->budget);

        /**
         * El Sub Total se imprime solo si hay algo que lo separe del Total. El forzado hacia
         * arriba deja el subtotal MENOR que el total, por eso se nombra aparte. Se compara
         * redondeado a centavos: el ruido de coma flotante de una suma de renglones no puede
         * inventar un "Sub Total" idéntico al Total.
         */
        $hay_diferencia = round($total_original, 2) > round((float) $this->budget->total, 2)
            || $monto_forzado != 0
            || $ajuste_por_metodo_de_pago != 0;

        if (! empty($flags['show_subtotal_in_footer']) && $hay_diferencia) {
            $rows[] = [
                'text' => 'Sub Total sin descuentos: $'.Numbers::price($total_original),
                'bold' => true,
            ];
        }

        foreach ($this->budget->discounts as $discount) {
            $rows[] = [
                'text' => '- '.Numbers::price($discount->pivot->percentage).'% '.$discount->name,
                'bold' => false,
            ];
        }

        /**
         * Con `aplicar_recargos_directo_a_items` el recargo YA ESTÁ adentro del precio de cada
         * renglón: listarlo también acá se lee como que se suma dos veces (pedido de Lucas).
         * Los descuentos sí se listan siempre, porque nunca viajan adentro del precio.
         */
        if (! $this->budget->aplicar_recargos_directo_a_items) {
            foreach ($this->budget->surchages as $surchage) {
                $rows[] = [
                    'text' => '+ '.Numbers::price($surchage->pivot->percentage).'% '.$surchage->name,
                    'bold' => false,
                ];
            }
        }

        /** El ajuste por método de pago va ANTES del forzado, que es el orden en que se aplican en getTotal(). */
        if ($ajuste_por_metodo_de_pago != 0) {
            $rows[] = [
                'text' => ($ajuste_por_metodo_de_pago < 0 ? '- ' : '+ ')
                    .'$'.Numbers::price(abs($ajuste_por_metodo_de_pago))
                    .($ajuste_por_metodo_de_pago < 0 ? ' Descuento por método de pago' : ' Recargo por método de pago'),
                'bold' => false,
            ];
        }

        /** El ajuste del total forzado va ÚLTIMO, que es el orden en que se aplica en getTotal(). */
        if ($monto_forzado != 0) {
            $signo = $monto_forzado < 0 ? '- ' : '+ ';
            $rows[] = [
                'text' => $signo.'$'.Numbers::price(abs($monto_forzado)).' Ajuste del total',
                'bold' => false,
            ];
        }

        /** El Total sale de getTotal() (fuente de verdad), nunca de un acumulado propio. */
        $rows[] = [
            'text' => 'Total: $'.Numbers::price(BudgetHelper::getTotal($this->budget)),
            'bold' => true,
        ];

        return $rows;
    }
}
