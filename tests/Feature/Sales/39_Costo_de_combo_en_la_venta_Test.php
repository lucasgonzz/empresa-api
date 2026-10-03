<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\combo\ComboCostoDeVentaHelper;
use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Http\Controllers\Helpers\sale\SaleTotalesHelper;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\Combo;
use App\Models\CreditAccount;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Concerns\PedidosDePrueba;
use Tests\Feature\Combos\ComboCalculadoTestCase;

/**
 * El costo de un combo en la venta (misión combos-calculados, Parte A2, 30/9/2026).
 *
 * EL DEFECTO QUE FIJAN ESTOS TESTS, que es de PLATA: `sales.total` incluía el precio del combo pero
 * `sales.total_cost` no incluía su costo, así que la ganancia de una venta con combo tomaba el
 * precio ENTERO del combo como ganancia. Lo que Lucas pidió: "si se vende un combo, que los costos
 * y precios se calculen en base a los artículos que lo componen, se debe calcular correctamente el
 * costo de ese combo para calcular las ganancias".
 *
 * La regla (ver `ComboCostoDeVentaHelper`): `combo_sale.cost` es el costo UNITARIO del combo,
 * decidido por el servidor y congelado al vender; `sales.total_cost` suma `cost x amount`.
 *
 *  1. combo calculado: Σ (costo de línea de cada componente x cantidad), y el `cost` que viaje en
 *     el payload (o el de `combos.cost`) se ignora;
 *  2. combo manual: `combos.cost`;
 *  3. unidades individuales: el componente cuenta por UNIDAD, no por bulto;
 *  4. dólares: el costo de línea de cada componente sigue la moneda y el `valor_dolar` de la venta,
 *     igual que si se vendiera suelto (invariante: un combo de UN componente cuesta lo mismo que
 *     ese artículo vendido suelto en la misma venta);
 *  5. lo vendido queda congelado aunque después cambie el combo o el artículo;
 *  6. la edición de la venta (la relación `combos` llega vieja a `set_total_cost`);
 *  7. la venta que nace de un presupuesto (el pivote no guarda costo: se calcula al confirmar) y
 *     de un pedido de la tienda (con `order_combo.cost` basura);
 *  8. `to_check`, combo sin costo, combo ajeno y la consolidación de facturas.
 *
 * Todo lo que crea lleva el prefijo `zz` y corre adentro de la transacción de `EmpresaTestCase`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Costo_de_combo_en_la_venta_Test extends ComboCalculadoTestCase
{
    use PedidosDePrueba;

    /** Cotización con la que se vende en estos tests. */
    const VALOR_DOLAR = 1000;

    // ─────────────────────────────────────────────────────────────────────────
    //  Andamiaje
    // ─────────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_estados_de_pedido();
    }

    /**
     * Cliente de cuenta corriente nuevo. Uno por venta: `SaleController::venta_ya_cread()` descarta
     * una venta igual (mismo cliente y total) a otra de los últimos 5 segundos.
     *
     * @return \App\Models\Client
     */
    protected function cliente_nuevo()
    {
        $client = Client::create([
            'name'    => 'zz Cliente costo de combo ' . uniqid(),
            'user_id' => self::DUENO,
        ]);

        /* Una cuenta por moneda: la venta en dólares también crea su movimiento de cuenta corriente. */
        foreach ([1, 2] as $moneda_id) {

            CreditAccount::create([
                'model_name' => 'client',
                'model_id'   => $client->id,
                'moneda_id'  => $moneda_id,
                'user_id'    => self::DUENO,
                'saldo'      => 0,
            ]);
        }

        return $client;
    }

    /**
     * Renglón de artículo suelto como lo arma VENDER: el artículo entero (con la ficha de costo).
     *
     * @param  \App\Models\Article  $article
     * @param  float                $amount
     * @param  float                $price
     * @return array
     */
    protected function renglon_articulo($article, $amount, $price)
    {
        return [
            'is_article'            => true,
            'id'                    => $article->id,
            'name'                  => $article->name,
            'cost'                  => $article->cost,
            'costo_real'            => $article->costo_real,
            'cost_in_dollars'       => $article->cost_in_dollars,
            'unidades_individuales' => $article->unidades_individuales,
            'price_vender'          => $price,
            'amount'                => $amount,
        ];
    }

    /**
     * Renglón de combo como lo arma VENDER: el modelo con `is_combo`, `price_vender`, `amount` y
     * `articles`. `$extra` permite colar claves basura (un `cost`) para probar que se ignoran.
     *
     * @param  \App\Models\Combo  $combo
     * @param  float              $amount
     * @param  float              $price
     * @param  array              $extra
     * @return array
     */
    protected function renglon_combo($combo, $amount, $price, array $extra = [])
    {
        $componentes = [];

        foreach ($combo->articles()->get() as $article) {
            $componentes[] = ['id' => $article->id, 'pivot' => ['amount' => $article->pivot->amount]];
        }

        return array_merge([
            'is_combo'     => true,
            'id'           => $combo->id,
            'name'         => $combo->name,
            'price'        => $combo->price,
            'price_vender' => $price,
            'amount'       => $amount,
            'articles'     => $componentes,
        ], $extra);
    }

    /**
     * El total que manda el SPA: Σ precio x cantidad.
     *
     * @param  array  $items
     * @return float
     */
    protected function total_de(array $items)
    {
        $total = 0;

        foreach ($items as $item) {
            $total += (float) $item['price_vender'] * (float) $item['amount'];
        }

        return $total;
    }

    /**
     * POST api/sale de una venta a cuenta corriente con los renglones dados.
     *
     * @param  array  $items
     * @param  array  $overrides
     * @return \App\Models\Sale  Releída de la base.
     */
    protected function vender(array $items, array $overrides = [])
    {
        $total = $this->total_de($items);

        $response = $this->postJson('api/sale', array_merge([
            'client_id'                        => $this->cliente_nuevo()->id,
            'address_id'                       => null,
            'save_current_acount'              => 1,
            'omitir_en_cuenta_corriente'       => 0,
            'to_check'                         => 0,
            'current_acount_payment_method_id' => null,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                      => null,
            'sub_total'                        => $total,
            'total'                            => $total,
            'terminada'                        => 1,
            'seller_id'                        => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'caja_id'                          => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'moneda_id'                        => 1,
            'valor_dolar'                      => self::VALOR_DOLAR,
            'discount_stock'                   => 0,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => $items,
        ], $overrides));

        $response->assertStatus(201);

        return Sale::find($response->json('model.id'));
    }

    /**
     * PUT api/sale/{id}: la edición de una venta, con los renglones nuevos.
     *
     * @param  \App\Models\Sale  $sale
     * @param  array             $items
     * @return \App\Models\Sale  Releída de la base.
     */
    protected function actualizar(Sale $sale, array $items, array $overrides = [])
    {
        $total = $this->total_de($items);

        $this->putJson('api/sale/' . $sale->id, array_merge([
            'client_id'                        => $sale->client_id,
            'save_current_acount'              => 1,
            'omitir_en_cuenta_corriente'       => 0,
            'to_check'                         => 0,
            'checked'                          => 0,
            'confirmed'                        => 0,
            'current_acount_payment_method_id' => null,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'discount_stock'                   => 0,
            'sub_total'                        => $total,
            'total'                            => $total,
            'moneda_id'                        => 1,
            'items'                            => $items,
            'discounts'                        => [],
            'surchages'                        => [],
            'returned_items'                   => [],
        ], $overrides))->assertStatus(200);

        return Sale::find($sale->id);
    }

    /**
     * Las filas de `combo_sale` de la venta, indexadas por combo_id (lo que quedó en la base, no
     * lo que devuelve Eloquent).
     *
     * @param  int  $sale_id
     * @return array
     */
    protected function filas_combo($sale_id)
    {
        $filas = [];

        foreach (DB::table('combo_sale')->where('sale_id', $sale_id)->get() as $fila) {
            $filas[(int) $fila->combo_id] = $fila;
        }

        return $filas;
    }

    /**
     * El `cost` de la línea del combo, o null.
     *
     * @param  int  $sale_id
     * @param  int  $combo_id
     * @return float|null
     */
    protected function costo_congelado($sale_id, $combo_id)
    {
        $filas = $this->filas_combo($sale_id);

        $this->assertArrayHasKey((int) $combo_id, $filas, 'El combo no quedó en combo_sale.');

        return is_null($filas[(int) $combo_id]->cost) ? null : (float) $filas[(int) $combo_id]->cost;
    }

    /**
     * Prende una opción de la cuenta del dueño por consulta directa.
     *
     * @param  string  $columna
     * @param  mixed   $valor
     * @return void
     */
    protected function opcion_de_cuenta($columna, $valor)
    {
        User::where('id', self::DUENO)->update([$columna => $valor]);
    }

    /**
     * Venta recién leída: total, total_cost y ganancia como números (o null).
     *
     * @param  \App\Models\Sale  $sale
     * @return \App\Models\Sale
     */
    protected function releer(Sale $sale)
    {
        return Sale::find($sale->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  1 y 2. La regla: calculado y manual
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 EL DEFECTO, medido: un combo calculado congela la suma del costo de sus componentes, y la
     * ganancia de la venta es precio menos ESE costo — no el precio entero.
     *
     * Las tres trampas del fixture: `combos.cost` (999) es distinto del costo real, así que el
     * test falla si se lo usa; y el `cost` de basura (77777) viaja en el renglón, así que falla si
     * se le cree al payload.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function un_combo_calculado_congela_la_suma_del_costo_de_sus_componentes()
    {
        $a = $this->nuevo_articulo(['costo_real' => 100]);
        $b = $this->nuevo_articulo(['costo_real' => 40]);

        // Costo real del combo: 100 x 2 + 40 x 1 = 240.
        $combo = $this->combo_calculado([[$a, 2], [$b, 1]], ['cost' => 999, 'price' => 600]);

        $sale = $this->vender([$this->renglon_combo($combo, 3, 600, ['cost' => 77777])]);

        $this->assertEqualsWithDelta(240, $this->costo_congelado($sale->id, $combo->id), 0.001, 'combo_sale.cost tiene que ser la suma del costo de los componentes.');

        $sale = $this->releer($sale);

        $this->assertEqualsWithDelta(1800, (float) $sale->total, 0.001);
        $this->assertEqualsWithDelta(720, (float) $sale->total_cost, 0.001, 'sales.total_cost = costo unitario del combo x cantidad.');
        $this->assertEqualsWithDelta(1080, (float) $sale->ganancia, 0.001, 'La ganancia es precio menos costo, no el precio entero del combo.');
    }

    /**
     * Combo manual: `combos.cost` tal cual.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function un_combo_manual_congela_su_costo_cargado()
    {
        $a = $this->nuevo_articulo(['costo_real' => 5000]);

        // El costo de los componentes (5000) es ruido: un combo manual manda su propio costo.
        $combo = $this->combo([[$a, 1]], ['cost' => 130, 'price' => 300]);

        $sale = $this->vender([$this->renglon_combo($combo, 2, 300)]);

        $this->assertEqualsWithDelta(130, $this->costo_congelado($sale->id, $combo->id), 0.001);

        $sale = $this->releer($sale);

        $this->assertEqualsWithDelta(260, (float) $sale->total_cost, 0.001);
        $this->assertEqualsWithDelta(600 - 260, (float) $sale->ganancia, 0.001);
    }

    /**
     * Una venta MIXTA suma el costo del artículo suelto y el del combo: ninguno de los dos pisa al
     * otro.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function una_venta_mixta_suma_el_costo_del_articulo_suelto_y_el_del_combo()
    {
        $suelto = $this->nuevo_articulo(['costo_real' => 70]);
        $a      = $this->nuevo_articulo(['costo_real' => 100]);

        $combo = $this->combo_calculado([[$a, 2]], ['price' => 500]);

        $sale = $this->vender([
            $this->renglon_articulo($suelto, 4, 150),
            $this->renglon_combo($combo, 1, 500),
        ]);

        $sale = $this->releer($sale);

        // 70 x 4 (suelto) + 200 x 1 (combo).
        $this->assertEqualsWithDelta(480, (float) $sale->total_cost, 0.001);
        $this->assertEqualsWithDelta(1100 - 480, (float) $sale->ganancia, 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  3 y 4. Unidades individuales y dólares: el costo de línea de un artículo suelto
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El componente cuenta por UNIDAD individual: un artículo de "caja x 12" a 1200 cuesta 100 por
     * unidad. Y el combo de UN componente cuesta lo mismo que ese artículo vendido suelto en la
     * misma venta.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function un_combo_con_unidades_individuales_cuesta_lo_mismo_que_el_articulo_suelto()
    {
        $caja = $this->nuevo_articulo(['costo_real' => 1200, 'unidades_individuales' => 12]);

        // Tres unidades sueltas adentro del combo: 300.
        $combo_de_tres = $this->combo_calculado([[$caja, 3]], ['price' => 500]);
        // Una unidad sola: el invariante contra el suelto.
        $combo_de_uno = $this->combo_calculado([[$caja, 1]], ['price' => 200]);

        $sale = $this->vender([
            $this->renglon_articulo($caja, 2, 150),
            $this->renglon_combo($combo_de_tres, 1, 500),
            $this->renglon_combo($combo_de_uno, 1, 200),
        ]);

        $linea_suelta = DB::table('article_sale')->where('sale_id', $sale->id)->where('article_id', $caja->id)->first();

        $this->assertEqualsWithDelta(100, (float) $linea_suelta->cost, 0.001, 'Precondición: el suelto divide por las unidades.');

        $this->assertEqualsWithDelta(300, $this->costo_congelado($sale->id, $combo_de_tres->id), 0.001, 'El bulto entero (1200 x 3) sería el error.');
        $this->assertEqualsWithDelta((float) $linea_suelta->cost, $this->costo_congelado($sale->id, $combo_de_uno->id), 0.001, 'Un combo de una unidad cuesta lo que ese artículo suelto.');
    }

    /**
     * Artículo con el costo en dólares: en una venta en PESOS se cotiza con el `valor_dolar` de la
     * venta. Y el invariante: lo mismo que el artículo suelto.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function un_componente_en_dolares_en_una_venta_en_pesos_se_cotiza_con_el_dolar_de_la_venta()
    {
        $usd   = $this->nuevo_articulo(['costo_real' => 10, 'cost_in_dollars' => 1]);
        $pesos = $this->nuevo_articulo(['costo_real' => 500]);

        $combo = $this->combo_calculado([[$usd, 1], [$pesos, 2]], ['price' => 20000]);
        $solo  = $this->combo_calculado([[$usd, 1]], ['price' => 15000]);

        $sale = $this->vender([
            $this->renglon_articulo($usd, 1, 15000),
            $this->renglon_combo($combo, 2, 20000),
            $this->renglon_combo($solo, 1, 15000),
        ], ['moneda_id' => 1, 'valor_dolar' => self::VALOR_DOLAR]);

        // 10 USD x 1000 = 10000 + 500 x 2 = 11000.
        $this->assertEqualsWithDelta(11000, $this->costo_congelado($sale->id, $combo->id), 0.001);

        $linea_suelta = DB::table('article_sale')->where('sale_id', $sale->id)->where('article_id', $usd->id)->first();

        $this->assertEqualsWithDelta(10000, (float) $linea_suelta->cost, 0.001, 'Precondición: el suelto se cotiza a pesos.');
        $this->assertEqualsWithDelta((float) $linea_suelta->cost, $this->costo_congelado($sale->id, $solo->id), 0.001);

        // total_cost = suelto 10000 + combo 11000 x 2 + solo 10000 x 1.
        $this->assertEqualsWithDelta(42000, (float) $this->releer($sale)->total_cost, 0.001);
    }

    /**
     * En una venta en DÓLARES, el componente en pesos se divide por el `valor_dolar` y el que ya
     * está en dólares queda como está.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function un_combo_en_una_venta_en_dolares_mide_el_costo_en_dolares()
    {
        $usd   = $this->nuevo_articulo(['costo_real' => 10, 'cost_in_dollars' => 1]);
        $pesos = $this->nuevo_articulo(['costo_real' => 500]);

        $combo = $this->combo_calculado([[$usd, 1], [$pesos, 2]], ['price' => 50000]);
        $solo  = $this->combo_calculado([[$pesos, 1]], ['price' => 900]);

        $sale = $this->vender([
            $this->renglon_articulo($pesos, 1, 40),
            $this->renglon_combo($combo, 2, 30),
            $this->renglon_combo($solo, 1, 2),
        ], ['moneda_id' => 2, 'valor_dolar' => self::VALOR_DOLAR]);

        // 10 USD + (500 pesos / 1000) x 2 = 11 USD.
        $this->assertEqualsWithDelta(11, $this->costo_congelado($sale->id, $combo->id), 0.001);

        $linea_suelta = DB::table('article_sale')->where('sale_id', $sale->id)->where('article_id', $pesos->id)->first();

        $this->assertEqualsWithDelta(0.5, (float) $linea_suelta->cost, 0.001, 'Precondición: el suelto en pesos se pasa a dólares.');
        $this->assertEqualsWithDelta((float) $linea_suelta->cost, $this->costo_congelado($sale->id, $solo->id), 0.001);

        // 0.5 (suelto) + 11 x 2 + 0.5 x 1.
        $this->assertEqualsWithDelta(23, (float) $this->releer($sale)->total_cost, 0.001);
    }

    /**
     * Un combo MANUAL (se carga en pesos) en una venta en dólares se pasa a dólares con el dólar de
     * la venta: el SPA hace lo mismo con su precio.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function un_combo_manual_en_una_venta_en_dolares_se_divide_por_el_dolar_de_la_venta()
    {
        $combo = $this->combo([], ['cost' => 5000, 'price' => 8000]);

        $sale = $this->vender(
            [$this->renglon_combo($combo, 1, 8)],
            ['moneda_id' => 2, 'valor_dolar' => self::VALOR_DOLAR]
        );

        $this->assertEqualsWithDelta(5, $this->costo_congelado($sale->id, $combo->id), 0.001);
    }

    /**
     * Venta en dólares SIN cotización propia (`valor_dolar` en 0/NULL) pero con el dólar del DUEÑO
     * cargado: `getCost()` usa el del dueño (`CotizacionDeVentaHelper::resolver_para_costo()`), y el
     * combo tiene que costar lo mismo que el mismo artículo vendido suelto. Antes la guarda del
     * combo devolvía NULL acá y la ganancia volvía a tomar el precio entero del combo.
     *
     * Es el camino de confirmar un presupuesto en USD sin cotización, del asistente de IA o de editar
     * una venta vieja en USD: los que no pasan por el 422 de `store()`.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function una_venta_en_dolares_sin_cotizacion_usa_el_dolar_del_dueno_igual_que_un_suelto()
    {
        $this->opcion_de_cuenta('dollar', 1000);

        $a     = $this->nuevo_articulo(['costo_real' => 500]);
        $combo = $this->combo_calculado([[$a, 2]], ['price' => 10]);

        $sale = new Sale(['user_id' => self::DUENO, 'moneda_id' => 2, 'valor_dolar' => 0]);

        $suelto = \App\Http\Controllers\Helpers\SaleHelper::getCost($sale, [
            'id'                    => $a->id,
            'costo_real'            => 500,
            'cost_in_dollars'       => 0,
            'unidades_individuales' => null,
        ]);

        $this->assertEqualsWithDelta(0.5, $suelto, 0.0001, 'Precondición: el suelto se convierte con el dólar del dueño.');

        $this->assertEqualsWithDelta(1.0, ComboCostoDeVentaHelper::costo_unitario($sale, $combo->id), 0.001, '2 unidades a 0,50: el mismo costo de línea que el suelto.');

        // Y el combo manual (se carga en pesos) también.
        $manual = $this->combo([], ['cost' => 5000, 'price' => 8]);

        $this->assertEqualsWithDelta(5.0, ComboCostoDeVentaHelper::costo_unitario($sale, $manual->id), 0.001);
    }

    /**
     * Venta en dólares sin cotización propia Y dueño sin dólar cargado: no hay con qué pasar un costo
     * en PESOS a dólares. NULL (no un número en pesos disfrazado de dólares, ni una división por cero).
     *
     * @group sales
     * @group combos
     * @test
     */
    public function una_venta_en_dolares_sin_cotizacion_y_sin_dolar_del_dueno_deja_el_costo_del_combo_en_null()
    {
        $this->opcion_de_cuenta('dollar', null);

        $a     = $this->nuevo_articulo(['costo_real' => 500]);
        $combo = $this->combo_calculado([[$a, 1]], ['price' => 10]);
        $manual = $this->combo([], ['cost' => 5000, 'price' => 8]);

        $sale = new Sale(['user_id' => self::DUENO, 'moneda_id' => 2, 'valor_dolar' => 0]);

        $this->assertNull(ComboCostoDeVentaHelper::costo_unitario($sale, $combo->id));
        $this->assertNull(ComboCostoDeVentaHelper::costo_unitario($sale, $manual->id));

        // Con cotización en la venta sí se resuelve (control).
        $con_dolar = new Sale(['user_id' => self::DUENO, 'moneda_id' => 2, 'valor_dolar' => 1000]);

        $this->assertEqualsWithDelta(0.5, ComboCostoDeVentaHelper::costo_unitario($con_dolar, $combo->id), 0.001);
    }

    /**
     * Venta en dólares sin ninguna cotización con un combo cuyos componentes están TODOS cargados en
     * dólares: no necesita cotización (`getCost()` no los divide), así que el costo es la suma sin
     * convertir y no NULL. Con uno solo en pesos, sí es NULL.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function un_combo_todo_en_dolares_no_necesita_cotizacion_en_una_venta_en_dolares()
    {
        $this->opcion_de_cuenta('dollar', null);

        $x = $this->nuevo_articulo(['costo_real' => 10, 'cost_in_dollars' => 1]);
        $y = $this->nuevo_articulo(['costo_real' => 4, 'cost_in_dollars' => 1]);
        $pesos = $this->nuevo_articulo(['costo_real' => 500]);

        $todo_en_dolares = $this->combo_calculado([[$x, 2], [$y, 1]], ['price' => 40]);
        $mezclado        = $this->combo_calculado([[$x, 1], [$pesos, 1]], ['price' => 40]);

        $sale = new Sale(['user_id' => self::DUENO, 'moneda_id' => 2, 'valor_dolar' => null]);

        $this->assertEqualsWithDelta(24.0, ComboCostoDeVentaHelper::costo_unitario($sale, $todo_en_dolares->id), 0.001, '10 x 2 + 4, sin convertir.');
        $this->assertNull(ComboCostoDeVentaHelper::costo_unitario($sale, $mezclado->id), 'Un componente en pesos sí necesita cotización.');
    }

    /**
     * Las opciones de la cuenta cuentan igual que para un suelto: con
     * `aplicar_descuentos_de_venta_a_costos`, el descuento de la venta baja el costo del combo
     * (y el invariante contra el suelto se mantiene).
     *
     * @group sales
     * @group combos
     * @test
     */
    public function el_descuento_de_la_venta_se_aplica_al_costo_del_combo_como_al_del_suelto()
    {
        $this->opcion_de_cuenta('aplicar_descuentos_de_venta_a_costos', 1);

        $descuento = Discount::create(['name' => 'zz Descuento 10', 'percentage' => 10, 'user_id' => self::DUENO]);

        $a     = $this->nuevo_articulo(['costo_real' => 100]);
        $combo = $this->combo_calculado([[$a, 2]], ['price' => 500]);

        $sale = $this->vender([
            $this->renglon_articulo($a, 1, 250),
            $this->renglon_combo($combo, 1, 500),
        ], ['discounts' => [['id' => $descuento->id, 'percentage' => 10]]]);

        $linea_suelta = DB::table('article_sale')->where('sale_id', $sale->id)->where('article_id', $a->id)->first();

        $this->assertEqualsWithDelta(90, (float) $linea_suelta->cost, 0.001, 'Precondición: el suelto lleva el descuento de la venta.');
        $this->assertEqualsWithDelta(180, $this->costo_congelado($sale->id, $combo->id), 0.001, 'Dos unidades a 90.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  5. Lo vendido queda congelado
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cambiar el costo del artículo o la composición del combo DESPUÉS de vender no mueve la venta
     * ya guardada, ni siquiera si algo recalcula su `total_cost`.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function cambiar_el_combo_o_el_articulo_despues_de_vender_no_cambia_la_venta()
    {
        $a = $this->nuevo_articulo(['costo_real' => 100]);
        $b = $this->nuevo_articulo(['costo_real' => 40]);

        $combo = $this->combo_calculado([[$a, 2], [$b, 1]], ['price' => 600]);

        $sale = $this->vender([$this->renglon_combo($combo, 3, 600)]);

        $this->escribir_crudo($a, ['costo_real' => 900]);
        $combo->articles()->detach($b->id);
        DB::table('combos')->where('id', $combo->id)->update(['cost' => 1, 'calcular_desde_articulos' => 0]);

        $this->assertEqualsWithDelta(240, $this->costo_congelado($sale->id, $combo->id), 0.001, 'La línea vendida no se recalcula.');

        $recalculada = SaleTotalesHelper::set_total_cost(Sale::find($sale->id));

        $this->assertEqualsWithDelta(720, (float) $recalculada->total_cost, 0.001, 'Recalcular el total de la venta lee el costo congelado.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  6. La edición de la venta
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 La trampa de la edición: `SaleController::update()` deja `$sale->combos` con los combos de
     * ANTES (`setRelation('combos', $previus_combos)`) y nadie la recarga. Si `set_total_cost`
     * sumara desde esa relación, el `total_cost` sería el de la venta vieja.
     *
     * Se edita tres veces: cambia una cantidad y se agrega otro combo; se saca uno; y se cambia el
     * combo por el otro. Y el `article_purchases` de los combos sigue al combo que QUEDÓ
     * (`ArticlePurchaseHelper::combos()` tenía el mismo defecto de la relación vieja).
     *
     * @group sales
     * @group combos
     * @test
     */
    public function editar_la_venta_recalcula_el_costo_con_los_combos_que_quedaron()
    {
        $a = $this->nuevo_articulo(['costo_real' => 100]);
        $b = $this->nuevo_articulo(['costo_real' => 40]);

        $x = $this->combo_calculado([[$a, 2]], ['price' => 600]); // costo 200, componente A
        $y = $this->combo_calculado([[$b, 5]], ['price' => 300]); // costo 200, componente B

        $sale = $this->vender([$this->renglon_combo($x, 2, 600)]);

        $this->assertEqualsWithDelta(400, (float) $this->releer($sale)->total_cost, 0.001, 'Precondición: 200 x 2.');

        // 1. X pasa de 2 a 3 y se agrega Y.
        $sale = $this->actualizar($sale, [$this->renglon_combo($x, 3, 600), $this->renglon_combo($y, 1, 300)]);

        $this->assertEqualsWithDelta(3 * 200 + 1 * 200, (float) $sale->total_cost, 0.001, 'La suma tiene que ser de los combos de DESPUÉS de editar.');
        $this->assertEquals(3, (int) $this->filas_combo($sale->id)[$x->id]->amount);
        $this->assertEqualsWithDelta(200, $this->costo_congelado($sale->id, $y->id), 0.001);

        // 2. Se saca X: queda solo Y.
        $sale = $this->actualizar($sale, [$this->renglon_combo($y, 1, 300)]);

        $this->assertEqualsWithDelta(200, (float) $sale->total_cost, 0.001, 'El combo sacado no puede seguir sumando.');
        $this->assertArrayNotHasKey($x->id, $this->filas_combo($sale->id));

        $articulos_comprados = DB::table('article_purchases')->where('sale_id', $sale->id)->pluck('article_id')->map(function ($id) {
            return (int) $id;
        })->all();

        $this->assertContains((int) $b->id, $articulos_comprados, 'article_purchases tiene que tener los componentes del combo que quedó.');
        $this->assertNotContains((int) $a->id, $articulos_comprados, 'article_purchases no puede conservar los componentes del combo que se sacó.');

        // 3. Se cambia Y por X.
        $sale = $this->actualizar($sale, [$this->renglon_combo($x, 1, 600)]);

        $this->assertEqualsWithDelta(200, (float) $sale->total_cost, 0.001);
        $this->assertArrayNotHasKey($y->id, $this->filas_combo($sale->id));
        $this->assertEqualsWithDelta(600 - 200, (float) $sale->ganancia, 0.001);
    }

    /**
     * 🔴 `set_total_cost()` no puede depender de la relación `combos` que trae el modelo: en la
     * edición (`SaleController::update()`) llega con los combos de ANTES. El test de arriba no lo
     * prueba solo, porque `ArticlePurchaseHelper` recarga la relación un paso antes; acá se le da
     * a `set_total_cost()` la relación VIEJA a mano (vacía, como una venta sin combos) y tiene que
     * sumar igual los combos que hay en la base.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function set_total_cost_suma_los_combos_de_la_base_aunque_la_relacion_cargada_este_vieja()
    {
        $a     = $this->nuevo_articulo(['costo_real' => 100]);
        $combo = $this->combo_calculado([[$a, 2]], ['price' => 600]);

        $sale = $this->vender([$this->renglon_combo($combo, 3, 600)]);

        $vieja = Sale::find($sale->id);
        $vieja->setRelation('combos', collect([]));

        $resultado = SaleTotalesHelper::set_total_cost($vieja);

        $this->assertEqualsWithDelta(600, (float) $resultado->total_cost, 0.001, 'Tiene que sumar combo_sale, no la relación cargada.');
        $this->assertCount(0, $vieja->combos, 'Y no le pisa la relación al modelo que recibió.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  7. Presupuestos y pedidos
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Presupuesto confirmado → venta. `budget_combo` no guarda costo, así que la venta nace con el
     * costo vigente del combo, calculado al confirmar.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function la_venta_que_nace_de_un_presupuesto_calcula_el_costo_del_combo_al_confirmar()
    {
        $a     = $this->nuevo_articulo(['costo_real' => 100]);
        $combo = $this->combo_calculado([[$a, 2]], ['price' => 600, 'cost' => 1]);

        $budget = $this->presupuesto_con_combo($combo, 3, 600);

        BudgetHelper::checkStatus($budget, []);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale, 'El presupuesto confirmado no generó la venta.');
        $this->assertEqualsWithDelta(200, $this->costo_congelado($sale->id, $combo->id), 0.001);
        $this->assertEqualsWithDelta(600, (float) $sale->total_cost, 0.001);
        $this->assertEqualsWithDelta(1800 - 600, (float) $sale->ganancia, 0.001);
    }

    /**
     * Y con `aplicar_descuentos_de_venta_a_costos`, el costo del combo de la venta nacida de un
     * presupuesto lleva el descuento del presupuesto: los descuentos se adjuntan ANTES de los
     * combos (`BudgetHelper::saveSale()`).
     *
     * @group sales
     * @group combos
     * @test
     */
    public function la_venta_de_un_presupuesto_con_descuento_aplica_el_descuento_al_costo_del_combo()
    {
        $this->opcion_de_cuenta('aplicar_descuentos_de_venta_a_costos', 1);

        $descuento = Discount::create(['name' => 'zz Descuento presupuesto', 'percentage' => 10, 'user_id' => self::DUENO]);

        $a     = $this->nuevo_articulo(['costo_real' => 100]);
        $combo = $this->combo_calculado([[$a, 2]], ['price' => 600]);

        $budget = $this->presupuesto_con_combo($combo, 1, 600);
        $budget->discounts()->attach($descuento->id, ['percentage' => 10]);

        BudgetHelper::checkStatus($budget->fresh(), []);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale);
        $this->assertEqualsWithDelta(180, $this->costo_congelado($sale->id, $combo->id), 0.001, '200 menos el 10 % del descuento.');
    }

    /**
     * Presupuesto con un combo sin costo resoluble: la venta se crea igual.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function la_venta_de_un_presupuesto_con_combo_sin_costo_no_se_rompe()
    {
        $combo = $this->combo([], ['price' => 600]); // manual y sin costo cargado

        $budget = $this->presupuesto_con_combo($combo, 1, 600);

        BudgetHelper::checkStatus($budget, []);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale);
        $this->assertNull($this->costo_congelado($sale->id, $combo->id));
        $this->assertEqualsWithDelta(0, (float) $sale->total_cost, 0.001);
    }

    /**
     * Pedido de la tienda → venta. `order_combo.cost` lo escribe tienda-api (hoy NULL): aunque
     * venga con basura, el costo lo decide el servidor.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function la_venta_de_un_pedido_ignora_el_cost_de_order_combo()
    {
        $a     = $this->nuevo_articulo(['costo_real' => 100]);
        $combo = $this->combo_calculado([[$a, 2]], ['price' => 500, 'cost' => 1]);

        $cliente = $this->cliente_cc();
        $pedido  = $this->pedido_con_combo($cliente->id, $combo, 3, 500, 77777);

        $this->putJson('api/order/' . $pedido->id, $this->payload_de_estado('Confirmado'))->assertStatus(200);

        $sale = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($sale, 'Confirmar el pedido no creó la venta.');
        $this->assertEqualsWithDelta(200, $this->costo_congelado($sale->id, $combo->id), 0.001, 'El costo de la tienda no entra: manda el del servidor.');
        $this->assertEqualsWithDelta(600, (float) $sale->total_cost, 0.001);
        $this->assertEqualsWithDelta(1500 - 600, (float) $sale->ganancia, 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  8. Bordes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Una venta a revisar (`to_check`) sigue dejando `total_cost` en NULL, como hoy. El costo del
     * combo igual queda congelado en su línea (es lo que se usa cuando se confirma).
     *
     * @group sales
     * @group combos
     * @test
     */
    public function una_venta_to_check_sigue_sin_total_cost()
    {
        $a     = $this->nuevo_articulo(['costo_real' => 100]);
        $combo = $this->combo_calculado([[$a, 2]], ['price' => 600]);

        $sale = $this->vender([$this->renglon_combo($combo, 1, 600)], ['to_check' => 1]);

        $this->assertNull($this->releer($sale)->total_cost, 'Una venta to_check no tiene costo total.');
        $this->assertEqualsWithDelta(200, $this->costo_congelado($sale->id, $combo->id), 0.001);
    }

    /**
     * Un combo sin costo (manual sin cargar, o calculado cuyos componentes no tienen costo) queda
     * con `combo_sale.cost` NULL y no rompe la venta: el total_cost es el de lo demás.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function un_combo_sin_costo_queda_en_null_y_no_rompe_la_venta()
    {
        $suelto = $this->nuevo_articulo(['costo_real' => 70]);
        $sin    = $this->nuevo_articulo(['costo_real' => null, 'cost' => null]);

        $manual     = $this->combo([], ['price' => 400]);
        $calculado  = $this->combo_calculado([[$sin, 1]], ['price' => 400]);

        $sale = $this->vender([
            $this->renglon_articulo($suelto, 1, 120),
            $this->renglon_combo($manual, 1, 400),
            $this->renglon_combo($calculado, 1, 400),
        ]);

        $this->assertNull($this->costo_congelado($sale->id, $manual->id));
        $this->assertNull($this->costo_congelado($sale->id, $calculado->id));

        $sale = $this->releer($sale);

        $this->assertEqualsWithDelta(70, (float) $sale->total_cost, 0.001, 'NULL cuenta como 0, igual que en las ventas viejas.');
        $this->assertNotNull($sale->ganancia);
    }

    /**
     * Un combo de otra cuenta, inexistente o con un id que no es un número: NULL. El id viene del
     * payload, y con solo mandar el de un combo ajeno no se le puede leer el costo a otro comercio.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function un_combo_ajeno_inexistente_o_con_id_invalido_queda_sin_costo()
    {
        $a    = $this->nuevo_articulo(['costo_real' => 100]);
        $ajeno = $this->combo_calculado([[$a, 1]], ['user_id' => 987654]);
        $propio = $this->combo_calculado([[$a, 1]]);

        $sale = new Sale(['user_id' => self::DUENO, 'moneda_id' => 1, 'valor_dolar' => self::VALOR_DOLAR]);

        $this->assertNull(ComboCostoDeVentaHelper::costo_unitario($sale, $ajeno->id), 'Un combo de otra cuenta no se lee.');
        $this->assertNull(ComboCostoDeVentaHelper::costo_unitario($sale, 99999999));
        $this->assertNull(ComboCostoDeVentaHelper::costo_unitario($sale, 'abc'));
        $this->assertNull(ComboCostoDeVentaHelper::costo_unitario($sale, 0));
        $this->assertNull(ComboCostoDeVentaHelper::costo_unitario($sale, null));
        $this->assertEqualsWithDelta(100, ComboCostoDeVentaHelper::costo_unitario($sale, $propio->id), 0.001, 'Control: el combo propio sí se resuelve.');
    }

    /**
     * Un componente borrado sigue contando con sus últimos valores (igual que en el cálculo del
     * ABM): sacarlo de la suma abarataría el combo y la ganancia saldría inflada.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function un_componente_borrado_sigue_contando_en_el_costo()
    {
        $a = $this->nuevo_articulo(['costo_real' => 100]);
        $b = $this->nuevo_articulo(['costo_real' => 40]);

        $combo = $this->combo_calculado([[$a, 1], [$b, 1]]);

        $b->delete();

        $sale = new Sale(['user_id' => self::DUENO, 'moneda_id' => 1, 'valor_dolar' => self::VALOR_DOLAR]);

        $this->assertEqualsWithDelta(140, ComboCostoDeVentaHelper::costo_unitario($sale, $combo->id), 0.001);
    }

    /**
     * La consolidación de facturas copia el costo del combo tal cual: NULL sigue siendo NULL (no
     * pasa a 0 con aspecto de dato medido).
     *
     * @group sales
     * @group combos
     * @test
     */
    public function consolidar_facturacion_conserva_el_null_del_costo_del_combo()
    {
        $con_costo = $this->combo([], ['cost' => 50, 'price' => 100]);
        $sin_costo = $this->combo([], ['price' => 100]);

        $origen = Sale::create(['user_id' => self::DUENO, 'moneda_id' => 1]);
        $origen->combos()->attach($con_costo->id, ['amount' => 1, 'price' => 100, 'cost' => 50]);
        $origen->combos()->attach($sin_costo->id, ['amount' => 1, 'price' => 100, 'cost' => null]);

        $consolidada = Sale::create(['user_id' => self::DUENO, 'moneda_id' => 1]);

        $copiar = new ReflectionMethod(ConsolidarFacturacionHelper::class, 'copiar_combos');
        $copiar->setAccessible(true);
        $copiar->invoke(null, $consolidada, collect([Sale::with('combos')->find($origen->id)]));

        $this->assertEqualsWithDelta(50, $this->costo_congelado($consolidada->id, $con_costo->id), 0.001);
        $this->assertNull($this->costo_congelado($consolidada->id, $sin_costo->id), 'NULL no se convierte en 0.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Fixtures de presupuesto y pedido
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Presupuesto en estado Confirmado con un combo en `budget_combo`, listo para
     * `BudgetHelper::checkStatus()`.
     *
     * @param  \App\Models\Combo  $combo
     * @param  float              $amount
     * @param  float              $price
     * @return \App\Models\Budget
     */
    protected function presupuesto_con_combo(Combo $combo, $amount, $price)
    {
        $estado = BudgetStatus::where('name', 'Confirmado')->first();

        if (is_null($estado)) {
            $estado = new BudgetStatus();
            $estado->name = 'Confirmado';
            $estado->save();
        }

        $client = $this->cliente_nuevo();

        $budget = Budget::create([
            'user_id'          => self::DUENO,
            'client_id'        => $client->id,
            'num'              => rand(900000, 999999),
            'total'            => $price * $amount,
            'budget_status_id' => $estado->id,
            'discount_stock'   => 0,
            'iva_aplicado'     => 1,
            'moneda_id'        => 1,
            'valor_dolar'      => self::VALOR_DOLAR,
        ]);

        $budget->combos()->attach($combo->id, ['amount' => $amount, 'price' => $price]);

        return $budget->fresh();
    }

    /**
     * Pedido "Sin confirmar" con un combo en `order_combo`, con el `cost` que se diga (la tienda
     * lo deja en NULL; acá se le pone basura a propósito).
     *
     * @param  int                $client_id
     * @param  \App\Models\Combo  $combo
     * @param  float              $amount
     * @param  float              $price
     * @param  float              $cost_de_la_tienda
     * @return \App\Models\Order
     */
    protected function pedido_con_combo($client_id, Combo $combo, $amount, $price, $cost_de_la_tienda)
    {
        $comprador = $this->crear_comprador($client_id);

        $pedido = Order::create([
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'buyer_id'        => $comprador->id,
            'order_status_id' => $this->estado('Sin confirmar')->id,
            'user_id'         => $this->user_id(),
            'address_id'      => $this->deposito()->id,
            'total'           => $price * $amount,
        ]);

        $pedido->combos()->attach($combo->id, [
            'amount' => $amount,
            'price'  => $price,
            'cost'   => $cost_de_la_tienda,
        ]);

        return $pedido->fresh();
    }

    /**
     * 🔴 F1 (DINERO): editar una venta NO re-congela el costo de los combos que ya tenía. Se vende un
     * combo, después sube el costo de un componente, y se edita la venta por cuatro motivos
     * distintos (observación, agregar un artículo, cambiar la cantidad del combo, cambiar el
     * cliente): `combo_sale.cost`, `total_cost` y la ganancia de ESE combo no se mueven. Los
     * artículos sueltos ya se comportaban así (`getCost()` devuelve el `pivot.cost` guardado).
     *
     * @group sales
     * @group combos
     * @test
     */
    public function editar_la_venta_no_recalcula_el_costo_de_un_combo_que_ya_tenia()
    {
        $a      = $this->nuevo_articulo(['costo_real' => 100]);
        $suelto = $this->nuevo_articulo(['costo_real' => 70]);
        $combo  = $this->combo_calculado([[$a, 2]], ['price' => 600]); // costo 200

        $sale = $this->vender([$this->renglon_combo($combo, 2, 600)]);

        $this->assertEqualsWithDelta(200, $this->costo_congelado($sale->id, $combo->id), 0.001, 'Precondición.');

        // Sube el costo del componente: hoy el combo costaría 600.
        $this->escribir_crudo($a, ['costo_real' => 300]);

        // 1. Observación.
        $sale = $this->actualizar($sale, [$this->renglon_combo($combo, 2, 600)], ['observations' => 'zz cambio de observacion']);
        $this->assertEqualsWithDelta(200, $this->costo_congelado($sale->id, $combo->id), 0.001, 'Editar la observación no toca el costo congelado.');
        $this->assertEqualsWithDelta(400, (float) $sale->total_cost, 0.001);
        $this->assertEqualsWithDelta(1200 - 400, (float) $sale->ganancia, 0.001);

        // 2. Se agrega un artículo suelto (su costo sí es el de hoy: es un renglón nuevo).
        $sale = $this->actualizar($sale, [$this->renglon_combo($combo, 2, 600), $this->renglon_articulo($suelto, 1, 150)]);
        $this->assertEqualsWithDelta(200, $this->costo_congelado($sale->id, $combo->id), 0.001);
        $this->assertEqualsWithDelta(400 + 70, (float) $sale->total_cost, 0.001);

        // 3. Cambia la cantidad del combo: el costo UNITARIO sigue siendo el congelado.
        $sale = $this->actualizar($sale, [$this->renglon_combo($combo, 5, 600)]);
        $this->assertEqualsWithDelta(200, $this->costo_congelado($sale->id, $combo->id), 0.001);
        $this->assertEqualsWithDelta(1000, (float) $sale->total_cost, 0.001, '5 x 200, no 5 x 600.');
        $this->assertEqualsWithDelta(3000 - 1000, (float) $sale->ganancia, 0.001);

        // 4. Otro cliente.
        $sale = $this->actualizar($sale, [$this->renglon_combo($combo, 5, 600)], ['client_id' => $this->cliente_nuevo()->id]);
        $this->assertEqualsWithDelta(200, $this->costo_congelado($sale->id, $combo->id), 0.001);
        $this->assertEqualsWithDelta(1000, (float) $sale->total_cost, 0.001);
    }

    /**
     * Un combo AGREGADO en la edición sí se calcula (con el costo de hoy, porque es nuevo en la
     * venta), y no le cambia nada al que ya estaba.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function un_combo_agregado_en_la_edicion_se_calcula_y_el_anterior_conserva_el_suyo()
    {
        $a = $this->nuevo_articulo(['costo_real' => 100]);
        $b = $this->nuevo_articulo(['costo_real' => 40]);

        $viejo = $this->combo_calculado([[$a, 2]], ['price' => 600]); // 200
        $nuevo = $this->combo_calculado([[$b, 5]], ['price' => 300]); // 200

        $sale = $this->vender([$this->renglon_combo($viejo, 1, 600)]);

        $this->escribir_crudo($a, ['costo_real' => 300]); // el viejo hoy costaría 600
        $this->escribir_crudo($b, ['costo_real' => 50]);  // el nuevo hoy cuesta 250

        $sale = $this->actualizar($sale, [$this->renglon_combo($viejo, 1, 600), $this->renglon_combo($nuevo, 2, 300)]);

        $this->assertEqualsWithDelta(200, $this->costo_congelado($sale->id, $viejo->id), 0.001, 'El que ya estaba conserva el suyo.');
        $this->assertEqualsWithDelta(250, $this->costo_congelado($sale->id, $nuevo->id), 0.001, 'El nuevo se calcula con el costo de hoy.');
        $this->assertEqualsWithDelta(200 + 2 * 250, (float) $sale->total_cost, 0.001);
    }

    /**
     * Una venta ANTERIOR a la misión (su `combo_sale.cost` es NULL) sigue con NULL después de
     * editarla, aunque hoy el combo sí se pueda costear: no le aparece un costo de golpe.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function una_venta_vieja_con_costo_null_sigue_en_null_tras_editarla()
    {
        $a     = $this->nuevo_articulo(['costo_real' => 100]);
        $combo = $this->combo_calculado([[$a, 2]], ['price' => 600]);

        $sale = $this->vender([$this->renglon_combo($combo, 1, 600)]);

        // La venta "vieja": sin costo congelado.
        DB::table('combo_sale')->where('sale_id', $sale->id)->update(['cost' => null]);

        $sale = $this->actualizar($sale, [$this->renglon_combo($combo, 3, 600)], ['observations' => 'zz edicion de venta vieja']);

        $this->assertNull($this->costo_congelado($sale->id, $combo->id), 'No pasa a tener costo por editarse.');
        $this->assertEqualsWithDelta(0, (float) $sale->total_cost, 0.001);
    }

    /**
     * El `cost` del payload tampoco se usa en la edición: ni para un combo nuevo ni para uno que ya
     * estaba (manda el congelado).
     *
     * @group sales
     * @group combos
     * @test
     */
    public function en_la_edicion_el_cost_del_payload_se_ignora()
    {
        $a     = $this->nuevo_articulo(['costo_real' => 100]);
        $b     = $this->nuevo_articulo(['costo_real' => 10]);
        $viejo = $this->combo_calculado([[$a, 2]], ['price' => 600]);
        $nuevo = $this->combo_calculado([[$b, 1]], ['price' => 50]);

        $sale = $this->vender([$this->renglon_combo($viejo, 1, 600)]);

        $sale = $this->actualizar($sale, [
            $this->renglon_combo($viejo, 1, 600, ['cost' => 99999, 'pivot' => ['cost' => 88888]]),
            $this->renglon_combo($nuevo, 1, 50, ['cost' => 77777]),
        ]);

        $this->assertEqualsWithDelta(200, $this->costo_congelado($sale->id, $viejo->id), 0.001);
        $this->assertEqualsWithDelta(10, $this->costo_congelado($sale->id, $nuevo->id), 0.001);
    }

    /**
     * F7, de punta a punta: un combo calculado CON descuento (porcentaje y monto) que se vende al
     * precio que el ABM le calculó. La ganancia es precio con descuento menos costo: el descuento
     * NUNCA toca el costo congelado.
     *
     * @group sales
     * @group combos
     * @test
     */
    public function vender_un_combo_calculado_con_descuento_da_ganancia_precio_con_descuento_menos_costo()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 250]);
        $b = $this->nuevo_articulo(['costo_real' => 40, 'final_price' => 90]);

        // Sin descuento: costo 240, precio 2 x 250 + 90 = 590.
        $porcentaje = $this->combo_calculado([[$a, 2], [$b, 1]], ['descuento_tipo' => 'porcentaje', 'descuento_valor' => 10]);
        $monto      = $this->combo_calculado([[$a, 2], [$b, 1]], ['descuento_tipo' => 'monto', 'descuento_valor' => 90]);

        \App\Http\Controllers\Helpers\combo\ComboCalculadoHelper::guardar($porcentaje);
        \App\Http\Controllers\Helpers\combo\ComboCalculadoHelper::guardar($monto);

        $this->assertSame(531.0, $this->precio_en_base($porcentaje), 'Precondición: 590 menos el 10 %.');
        $this->assertSame(500.0, $this->precio_en_base($monto), 'Precondición: 590 menos 90.');
        $this->assertSame(240.0, $this->costo_en_base($porcentaje), 'El descuento no toca el costo.');

        $sale = $this->vender([
            $this->renglon_combo($porcentaje, 2, $this->precio_en_base($porcentaje)),
            $this->renglon_combo($monto, 1, $this->precio_en_base($monto)),
        ]);

        $this->assertEqualsWithDelta(240, $this->costo_congelado($sale->id, $porcentaje->id), 0.001);
        $this->assertEqualsWithDelta(240, $this->costo_congelado($sale->id, $monto->id), 0.001);

        $sale = $this->releer($sale);

        $this->assertEqualsWithDelta(2 * 531 + 500, (float) $sale->total, 0.001);
        $this->assertEqualsWithDelta(3 * 240, (float) $sale->total_cost, 0.001);
        $this->assertEqualsWithDelta((2 * 531 + 500) - (3 * 240), (float) $sale->ganancia, 0.001, 'Ganancia = precio con descuento - costo.');
    }
}
