<?php

namespace Tests\Feature\PresupuestosConVariantes;

use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\Feature\VariantesEnVenta\VariantesEnVentaTestCase;

/**
 * Base de los tests de "un presupuesto con el mismo artículo en variantes distintas" (misión
 * presupuestos-con-variantes, 8/10/2026).
 *
 * Reusa el escenario de `VariantesEnVentaTestCase`: UN artículo con tres variantes (M, L y S) que
 * reparten su stock en DOS sucursales con números distintos, para que un cruce M↔L falle, más un
 * artículo testigo sin variantes con stock global:
 *
 *              suc1   suc2   total
 *      M         6      4      10
 *      L         5      5      10
 *      S         3      2       5
 *   artículo    14     11      25
 *   testigo     — stock global 10 —
 *
 * Todo por los endpoints reales (`POST api/budget`, `PUT api/budget/{id}`, `GET api/budget/{id}`,
 * `POST api/budget/{id}/confirmar|anular|duplicate`), con los renglones armados con la MISMA forma
 * que manda la SPA (`empresa-spa/src/mixins/vender_presupuestos.js::get_articles()`): plana en el
 * alta y en los renglones nuevos de una edición, y con `pivot` (más la raíz) en los renglones que
 * ya estaban cargados al actualizar.
 *
 * Lo que se mide se lee de la base (`DB::table()`), nunca del modelo en memoria.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
abstract class PresupuestosConVariantesTestCase extends VariantesEnVentaTestCase
{
    /** Ids de `budget_statuses`, tabla global sembrada por `BudgetStatusSeeder`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /** Slug de la extensión que gatea `BudgetController::duplicate()`. */
    const EXTENCION_DUPLICAR = 'duplicar_presupuestos';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_estados();
    }

    /**
     * ⚠️ `budget_statuses` puede venir vacía en la base del slot (medido el 21/8/2026). Se siembra
     * con ids explícitos para no depender del auto-increment. Sin estas filas
     * `BudgetHelper::checkStatus()` revienta en vez de fallar.
     *
     * @return void
     */
    protected function sembrar_estados()
    {
        $estados = [
            Self::ESTADO_SIN_CONFIRMAR => 'Sin confirmar',
            Self::ESTADO_CONFIRMADO    => 'Confirmado',
        ];

        foreach ($estados as $id => $name) {

            if (is_null(BudgetStatus::find($id))) {

                $estado = new BudgetStatus();
                $estado->id = $id;
                $estado->name = $name;
                $estado->save();
            }
        }
    }

    /**
     * Un renglón del presupuesto con la forma PLANA de la SPA (alta, o renglón agregado en la
     * edición): las claves de `article_to_add` más las de `pivot_info`, en la raíz.
     *
     * @param  \App\Models\Article               $articulo
     * @param  \App\Models\ArticleVariant|null   $variante  null = sin variante (viaja 0, como la SPA).
     * @param  float                             $amount
     * @param  float                             $price
     * @param  array                             $extra     Claves a pisar o a sacar (valor `'__sacar__'`).
     * @return array
     */
    protected function renglon_plano($articulo, $variante, $amount, $price = self::PRECIO, $extra = [])
    {
        $renglon = [
            'id'                          => $articulo->id,
            'status'                      => $articulo->status,
            'cost_in_dollars'             => $articulo->cost_in_dollars,
            'name'                        => $articulo->name,
            'name_vender_personalizado'   => null,
            'article_variant_id'          => is_null($variante) ? 0 : $variante->id,
            'amount'                      => $amount,
            'price'                       => $price,
            'cost'                        => (float) $articulo->cost,
            'costo_real'                  => $articulo->costo_real,
            'unidades_individuales'       => $articulo->unidades_individuales,
            'presentacion'                => null,
            'price_type_personalizado_id' => null,
            'bonus'                       => null,
            'location'                    => null,
            'price_vender_sin_recargos'   => null,
        ];

        return $this->aplicar_extra($renglon, $extra);
    }

    /**
     * Un renglón que YA estaba cargado, con la forma `for_update` de la SPA: la raíz de
     * `article_to_add` (con la variante) y `pivot` con `pivot_info`.
     *
     * @param  \App\Models\Article               $articulo
     * @param  \App\Models\ArticleVariant|null   $variante
     * @param  float                             $amount
     * @param  float                             $price
     * @param  array                             $extra        Claves de la raíz a pisar o sacar.
     * @param  array                             $extra_pivot  Claves del pivot a pisar o sacar.
     * @return array
     */
    protected function renglon_cargado($articulo, $variante, $amount, $price = self::PRECIO, $extra = [], $extra_pivot = [])
    {
        $renglon = [
            'id'                        => $articulo->id,
            'status'                    => $articulo->status,
            'cost_in_dollars'           => $articulo->cost_in_dollars,
            'name'                      => $articulo->name,
            'name_vender_personalizado' => null,
            'article_variant_id'        => is_null($variante) ? 0 : $variante->id,
            'pivot'                     => $this->aplicar_extra([
                'amount'                      => $amount,
                'price'                       => $price,
                'cost'                        => (float) $articulo->cost,
                'costo_real'                  => $articulo->costo_real,
                'unidades_individuales'       => $articulo->unidades_individuales,
                'presentacion'                => null,
                'price_type_personalizado_id' => null,
                'bonus'                       => null,
                'location'                    => null,
                'price_vender_sin_recargos'   => null,
            ], $extra_pivot),
        ];

        return $this->aplicar_extra($renglon, $extra);
    }

    /**
     * Pisa claves de un renglón; el valor `'__sacar__'` saca la clave (para armar la forma de la
     * SPA vieja, que no manda la variante).
     *
     * @param  array  $renglon
     * @param  array  $extra
     * @return array
     */
    protected function aplicar_extra($renglon, $extra)
    {
        foreach ($extra as $clave => $valor) {

            if ($valor === '__sacar__') {
                unset($renglon[$clave]);
            } else {
                $renglon[$clave] = $valor;
            }
        }

        return $renglon;
    }

    /**
     * El total de los renglones (precio × cantidad), leyendo el precio y la cantidad donde estén.
     *
     * @param  array  $renglones
     * @return float
     */
    protected function total_de($renglones)
    {
        $total = 0;

        foreach ($renglones as $renglon) {

            $fuente = isset($renglon['pivot']) ? $renglon['pivot'] : $renglon;

            $total += (float) $fuente['price'] * (float) $fuente['amount'];
        }

        return $total;
    }

    /**
     * La primera lista de precios del usuario (null si no tiene): con listas cargadas, el alta sin
     * lista se rechaza con 422 (`PriceTypeHelper::requiere_lista_de_precios`).
     *
     * @return int|null
     */
    protected function lista_de_precios()
    {
        $lista = PriceType::where('user_id', $this->usuario()->id)->orderBy('id')->first();

        return is_null($lista) ? null : $lista->id;
    }

    /**
     * El payload de `POST api/budget` con la forma de `vender_presupuestos.js::crear()`.
     *
     * @param  array  $renglones
     * @param  int    $address_id
     * @param  array  $extra
     * @return array
     */
    protected function payload_presupuesto($renglones, $address_id, $extra = [])
    {
        return array_merge([
            'client_id'                        => $this->cliente_cc()->id,
            'price_type_id'                    => $this->lista_de_precios(),
            'start_at'                         => null,
            'finish_at'                        => null,
            'observations'                     => 'Presupuesto con variantes',
            'total'                            => $this->total_de($renglones),
            'address_id'                       => $address_id,
            'moneda_id'                        => 1,
            'surchages_in_services'            => 1,
            'discounts_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => 0,
            'forzar_total_monto'               => null,
            'valor_dolar'                      => null,
            'omitir_en_cuenta_corriente'       => 0,
            'selected_payment_methods'         => [],
            'budget_status_id'                 => Self::ESTADO_SIN_CONFIRMAR,
            'discounts'                        => [],
            'surchages'                        => [],
            'articles'                         => $renglones,
            'services'                         => [],
            'promocion_vinotecas'              => [],
            'combos'                           => [],
            'discount_stock'                   => 1,
            'sale_status_id'                   => null,
            'iva_aplicado'                     => 1,
            'iva_en_articulos_sin_iva'         => 0,
        ], $extra);
    }

    /**
     * Crea el presupuesto por el endpoint real y lo devuelve.
     *
     * @param  array  $renglones
     * @param  int    $address_id
     * @param  array  $extra
     * @return \App\Models\Budget
     */
    protected function crear_presupuesto($renglones, $address_id, $extra = [])
    {
        $respuesta = $this->postJson('api/budget', $this->payload_presupuesto($renglones, $address_id, $extra));

        $respuesta->assertStatus(201);

        $budget = Budget::find($respuesta->json('model.id'));

        $this->assertNotNull($budget, 'El POST no dejó ningún presupuesto.');

        return $budget;
    }

    /**
     * Actualiza el presupuesto por el endpoint real (forma de `vender_presupuestos.js::actualizar()`).
     *
     * @param  \App\Models\Budget  $budget
     * @param  array               $renglones
     * @param  array               $extra
     * @return \Illuminate\Testing\TestResponse
     */
    protected function actualizar_presupuesto($budget, $renglones, $extra = [])
    {
        return $this->putJson('api/budget/'.$budget->id, $this->payload_presupuesto($renglones, $budget->address_id, array_merge([
            'id'               => $budget->id,
            'budget_status_id' => $budget->fresh()->budget_status_id,
        ], $extra)));
    }

    /**
     * Las filas de `article_budget` del presupuesto para un artículo, en orden de id.
     *
     * @param  \App\Models\Budget   $budget
     * @param  \App\Models\Article  $articulo
     * @return array
     */
    protected function filas_presupuesto($budget, $articulo)
    {
        return DB::table('article_budget')
                    ->where('budget_id', $budget->id)
                    ->where('article_id', $articulo->id)
                    ->orderBy('id')
                    ->get()
                    ->all();
    }

    /**
     * La fila del presupuesto de una variante (null = sin variante). Falla si hay más de una.
     *
     * @param  \App\Models\Budget                $budget
     * @param  \App\Models\Article               $articulo
     * @param  \App\Models\ArticleVariant|null   $variante
     * @return object|null
     */
    protected function fila_presupuesto_de($budget, $articulo, $variante)
    {
        $query = DB::table('article_budget')
                    ->where('budget_id', $budget->id)
                    ->where('article_id', $articulo->id);

        if (is_null($variante)) {
            $query->whereNull('article_variant_id');
        } else {
            $query->where('article_variant_id', $variante->id);
        }

        $filas = $query->get();

        $this->assertLessThanOrEqual(1, $filas->count(), 'Hay más de una fila de la misma variante en el presupuesto.');

        return $filas->first();
    }

    /**
     * Confirma el presupuesto por el endpoint real y devuelve la venta que nació.
     *
     * @param  \App\Models\Budget  $budget
     * @return \App\Models\Sale
     */
    protected function confirmar($budget)
    {
        $this->postJson('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $venta = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($venta, 'Confirmar no creó la venta.');

        return $venta;
    }

    /**
     * Le da al dueño la extensión que gatea `duplicate()`, creando la fila del catálogo si la base
     * del slot no la tiene. Sin ella `duplicate()` corta en 403 y el test no mediría nada.
     *
     * @return void
     */
    protected function dar_extension_duplicar()
    {
        $extencion = ExtencionEmpresa::where('slug', Self::EXTENCION_DUPLICAR)->first();

        if (is_null($extencion)) {

            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => Self::EXTENCION_DUPLICAR,
                'name' => 'Duplicar presupuestos',
            ]);
        }

        $user = $this->usuario();

        if (!$user->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $user->extencions()->attach($extencion->id);
        }
    }
}
