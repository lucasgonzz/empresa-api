<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\Budget\ComboEsquemaHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfDocument\BudgetPdfDocument;
use App\Http\Controllers\Helpers\PdfDocument\OrderPdfDocument;
use App\Models\Article;
use App\Models\Brand;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Order;
use App\Models\PdfColumnOption;
use App\Models\Provider;
use App\Models\SubCategory;
use App\Services\PdfColumnService;
use Database\Seeders\PdfColumnOptionSeeder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Tests\EmpresaTestCase;

/**
 * Catálogo de columnas de los diseños de PDF de PRESUPUESTO (`budget`) y PEDIDO ONLINE (`order`)
 * (misión pdf-presupuestos-y-pedidos-personalizables, 29/9/2026).
 *
 * Lo que protege, en orden de cuánto duele si se rompe:
 *
 *  - `nombres_unicos_por_modelo`: `PdfColumnProfileSeederHelper` asigna las columnas de un diseño
 *    por `name`. Si dos columnas del catálogo se llaman igual, una de las dos se PIERDE en silencio
 *    (le pasa a `item_discount_percentage` en `sale`, donde hay dos nombres para el mismo
 *    resolver). Sin este test el catálogo se rompe sin que nadie lo vea.
 *  - `todos_los_resolvers_se_resuelven`: un resolver nuevo en el catálogo que se olvidó en
 *    `resolve_document_value()` cae al `default: return ''` y la columna sale VACÍA en el PDF, sin
 *    error. Con datos de sobra en el renglón, todos tienen que devolver algo.
 *  - `el_seeder_del_catalogo_incluye_budget_y_order`: `PdfColumnOptionSeeder` es lo que corre en
 *    producción antes del seeder de diseños; si le falta un modelo, los diseños nacen sin columnas.
 *
 * @group pdf-documentos
 */
class Catalogo_de_columnas_de_presupuesto_y_pedido_Test extends EmpresaTestCase
{
    /**
     * Resolvers esperados por modelo, en el orden del catálogo. El presupuesto tiene bonificación
     * por renglón (`pivot->bonus`) y el pedido tiene notas por renglón (`pivot->notes`).
     */
    const COMUNES_ANTES = [
        'row_index',
        'document_item_image',
        'document_item_id',
        'document_item_bar_code',
        'document_item_provider_code',
        'document_item_name',
        'document_item_amount',
        'document_item_price',
    ];

    const COMUNES_DESPUES = [
        'document_item_subtotal',
        'document_item_brand_name',
        'document_item_category_name',
        'document_item_sub_category_name',
        'document_item_provider_name',
    ];

    /**
     * @return array<int, string>
     */
    protected function resolvers_esperados($model_name)
    {
        $propio = $model_name === 'budget' ? 'document_item_bonus' : 'document_item_notes';

        return array_merge(self::COMUNES_ANTES, [$propio], self::COMUNES_DESPUES);
    }

    /**
     * @test
     */
    public function el_catalogo_de_presupuesto_y_de_pedido_tiene_las_columnas_esperadas()
    {
        foreach (['budget', 'order'] as $model_name) {
            $resolvers = array_column(PdfColumnService::default_options($model_name), 'value_resolver');

            $this->assertSame(
                $this->resolvers_esperados($model_name),
                $resolvers,
                'El catalogo de "'.$model_name.'" no tiene las columnas (ni el orden) que define la mision.'
            );
        }

        /** La bonificación es solo del presupuesto y las notas del renglón solo del pedido. */
        $budget = array_column(PdfColumnService::default_options('budget'), 'value_resolver');
        $order = array_column(PdfColumnService::default_options('order'), 'value_resolver');

        $this->assertNotContains('document_item_notes', $budget);
        $this->assertNotContains('document_item_bonus', $order);
    }

    /**
     * @test
     */
    public function los_nombres_y_los_resolvers_son_unicos_dentro_de_cada_modelo()
    {
        foreach (['budget', 'order'] as $model_name) {
            $opciones = PdfColumnService::default_options($model_name);

            $nombres = array_column($opciones, 'name');
            $resolvers = array_column($opciones, 'value_resolver');

            $this->assertSame(
                count($nombres),
                count(array_unique($nombres)),
                'Hay un nombre repetido en el catalogo de "'.$model_name.'": PdfColumnProfileSeederHelper asigna por name y una columna se pierde.'
            );
            $this->assertSame(
                count($resolvers),
                count(array_unique($resolvers)),
                'Hay un value_resolver repetido en el catalogo de "'.$model_name.'".'
            );
        }
    }

    /**
     * @test
     */
    public function sync_catalog_options_crea_las_filas_y_es_idempotente()
    {
        foreach (['budget', 'order'] as $model_name) {
            PdfColumnOption::where('model_name', $model_name)->delete();

            PdfColumnService::sync_catalog_options($model_name);
            $primera = PdfColumnOption::where('model_name', $model_name)->orderBy('order')->get();

            $this->assertSame(
                $this->resolvers_esperados($model_name),
                $primera->pluck('value_resolver')->all(),
                'La sincronizacion de "'.$model_name.'" no dejo las filas esperadas.'
            );

            PdfColumnService::sync_catalog_options($model_name);
            $segunda = PdfColumnOption::where('model_name', $model_name)->orderBy('order')->get();

            $this->assertSame(
                $primera->pluck('id')->all(),
                $segunda->pluck('id')->all(),
                'Sincronizar dos veces no puede crear ni borrar filas.'
            );

            /** Y los nombres quedan únicos también en la base, que es donde los lee el ABM. */
            $this->assertSame($segunda->count(), $segunda->pluck('name')->unique()->count());
        }
    }

    /**
     * Sin las filas de `budget` y `order`, `PdfColumnProfileSeederHelper::assign_profile_options()`
     * no encuentra ninguna opción y el diseño nace SIN columnas. Se borran antes para que el test
     * solo pase si el seeder las vuelve a crear.
     *
     * @test
     */
    public function el_seeder_del_catalogo_incluye_budget_y_order()
    {
        PdfColumnOption::whereIn('model_name', ['budget', 'order'])->delete();

        (new PdfColumnOptionSeeder())->run();

        foreach (['budget', 'order'] as $model_name) {
            $this->assertSame(
                count($this->resolvers_esperados($model_name)),
                PdfColumnOption::where('model_name', $model_name)->count(),
                'PdfColumnOptionSeeder no siembra el catalogo de "'.$model_name.'".'
            );
        }

        /** Y no le tocó nada a los dos catálogos de siempre. */
        $this->assertGreaterThan(0, PdfColumnOption::where('model_name', 'sale')->count());
        $this->assertGreaterThan(0, PdfColumnOption::where('model_name', 'article')->count());
    }

    /**
     * El test de los olvidos. Un renglón con TODOS los datos cargados: si algún resolver del
     * catálogo devuelve vacío, es que `resolve_document_value()` no lo conoce.
     *
     * La imagen es la única excepción, y a propósito: se dibuja, no se escribe (su valor es '' y
     * `is_document_image_column()` la reconoce).
     *
     * @test
     */
    public function todos_los_resolvers_del_catalogo_se_resuelven()
    {
        $articulo = $this->articulo_con_todos_los_datos();

        foreach (['budget', 'order'] as $model_name) {
            $documento = $model_name === 'budget'
                ? new BudgetPdfDocument(new Budget())
                : new OrderPdfDocument(new Order());

            foreach (PdfColumnService::default_options($model_name) as $opcion) {
                $resolver = $opcion['value_resolver'];

                $valor = PdfColumnService::resolve_value($resolver, [
                    'item'     => $articulo,
                    'index'    => 3,
                    'document' => $documento,
                    'numbers'  => Numbers::class,
                ]);

                if (PdfColumnService::is_document_image_column($resolver)) {
                    $this->assertSame('', $valor, 'La imagen se dibuja: su valor de texto es vacio.');
                    continue;
                }

                $this->assertNotSame(
                    '',
                    (string) $valor,
                    'El resolver "'.$resolver.'" de "'.$model_name.'" devolvio vacio con el renglon completo: '
                        .'falta su rama en PdfColumnService::resolve_document_value().'
                );
            }
        }
    }

    /**
     * Los valores concretos, para que "no vacío" no alcance para pasar con un valor equivocado.
     *
     * @test
     */
    public function los_resolvers_devuelven_lo_que_dice_el_plan()
    {
        $articulo = $this->articulo_con_todos_los_datos();
        $budget = new BudgetPdfDocument(new Budget());

        $resolver = function ($nombre) use ($articulo, $budget) {
            return PdfColumnService::resolve_value($nombre, [
                'item'     => $articulo,
                'index'    => 3,
                'document' => $budget,
                'numbers'  => Numbers::class,
            ]);
        };

        $this->assertSame(3, $resolver('row_index'));
        $this->assertSame(77, $resolver('document_item_id'));
        $this->assertSame('7791234567890', $resolver('document_item_bar_code'));
        $this->assertSame('PROV-9', $resolver('document_item_provider_code'));
        $this->assertSame('Taladro percutor', $resolver('document_item_name'));
        $this->assertSame('2', $resolver('document_item_amount'));
        $this->assertSame('$1.500', $resolver('document_item_price'));
        $this->assertSame('10%', $resolver('document_item_bonus'));
        $this->assertSame('Sin caja', $resolver('document_item_notes'));
        /** 1500 x 2 con 10% de bonificación = 2700 (BudgetHelper::totalArticle). */
        $this->assertSame('$2.700', $resolver('document_item_subtotal'));
        $this->assertSame('Marca X', $resolver('document_item_brand_name'));
        $this->assertSame('Categoria Y', $resolver('document_item_category_name'));
        $this->assertSame('Subcategoria Z', $resolver('document_item_sub_category_name'));
        $this->assertSame('Proveedor W', $resolver('document_item_provider_name'));
    }

    /**
     * Sin bonificación (null o 0) la celda va vacía: "0%" en cada renglón es puro ruido.
     *
     * @test
     */
    public function la_bonificacion_en_cero_o_nula_se_imprime_vacia()
    {
        $budget = new BudgetPdfDocument(new Budget());

        foreach ([null, 0, '0.00'] as $bonus) {
            $articulo = $this->articulo_con_todos_los_datos();
            $articulo->pivot->bonus = $bonus;

            $valor = PdfColumnService::resolve_value('document_item_bonus', [
                'item'     => $articulo,
                'index'    => 1,
                'document' => $budget,
                'numbers'  => Numbers::class,
            ]);

            $this->assertSame('', $valor, 'Una bonificacion nula o en cero no se imprime.');
        }
    }

    /**
     * Artículo en memoria (sin tocar la base) con `pivot` y todas las relaciones que usan las
     * columnas del catálogo.
     *
     * @return \App\Models\Article
     */
    protected function articulo_con_todos_los_datos()
    {
        $articulo = new Article();
        $articulo->forceFill([
            'id'            => 77,
            'name'          => 'Taladro percutor',
            'bar_code'      => '7791234567890',
            'provider_code' => 'PROV-9',
        ]);

        $pivot = new Pivot();
        $pivot->forceFill([
            'amount' => '2.00',
            'price'  => '1500.00',
            'bonus'  => '10.00',
            'notes'  => 'Sin caja',
        ]);
        $articulo->setRelation('pivot', $pivot);

        $articulo->setRelation('brand', (new Brand())->forceFill(['name' => 'Marca X']));
        $articulo->setRelation('category', (new Category())->forceFill(['name' => 'Categoria Y']));
        $articulo->setRelation('sub_category', (new SubCategory())->forceFill(['name' => 'Subcategoria Z']));
        $articulo->setRelation('provider', (new Provider())->forceFill(['name' => 'Proveedor W']));

        return $articulo;
    }
}
