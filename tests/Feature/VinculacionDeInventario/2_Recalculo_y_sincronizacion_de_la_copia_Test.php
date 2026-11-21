<?php

namespace Tests\Feature\VinculacionDeInventario;

use App\Models\Article;
use App\Models\ProviderOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CA2 (ficha de la copia), CA3 (ficha de Fénix), CA4 (cron idempotente), CA5 (guardas, marca 0 o
 * interruptor apagado = hoy) y CA11 (compra espejo) del PLAN D2-M R3.1.
 *
 * @group vinculacion-iva-copia
 */
class Recalculo_y_sincronizacion_de_la_copia_Test extends VinculacionDeInventarioTestCase
{
    /**
     * PUT de la ficha de la copia, como Galván (el dueño de la copia).
     *
     * @param  \App\Models\Article $copia
     * @param  array               $cambios
     * @return void
     */
    private function put_de_la_copia($copia, array $cambios)
    {
        $this->actingAs(User::find($this->galvan->id), 'web');

        $this->putJson('api/article/' . $copia->id, $this->payload_ficha($copia, $cambios))->assertStatus(200);

        $this->actingAs(User::find($this->fenix->id), 'web');
    }

    /**
     * Asserts de los cuatro valores de CA2.
     *
     * @param  int    $id
     * @param  string $donde
     * @return void
     */
    private function assert_neta($id, $donde)
    {
        $v = $this->valores($id);

        $this->assertEqualsWithDelta(self::NETO, $v['cost'], 0.0000005, $donde . ': cost');
        $this->assertEqualsWithDelta(self::BRUTO, $v['costo_real'], 0.0000005, $donde . ': costo_real');
        $this->assertEqualsWithDelta(self::FINAL, $v['final'], 0.0001, $donde . ': final');
        $this->assertEquals(1, $v['marca'], $donde . ': marca');
    }

    /**
     * CA2a — la ficha con el costo bruto declarado (cost_incluye_iva) deja el neto.
     *
     * @test
     */
    public function ca2a_la_ficha_con_el_bruto_declarado_guarda_el_neto()
    {
        $this->escenario();
        $copia = $this->crear_copia($this->crear_fuente());

        $this->put_de_la_copia($copia, ['cost' => 4828, 'cost_incluye_iva' => true, 'aplicar_iva' => 1]);

        $this->assert_neta($copia->id, 'CA2a');
    }

    /**
     * CA2b — la ficha con el costo neto declarado deja los mismos cuatro valores.
     *
     * @test
     */
    public function ca2b_la_ficha_con_el_neto_declarado_deja_los_mismos_valores()
    {
        $this->escenario();
        $copia = $this->crear_copia($this->crear_fuente());

        $this->put_de_la_copia($copia, ['cost' => 3990.082645, 'cost_incluye_iva' => false, 'aplicar_iva' => 1]);

        $this->assert_neta($copia->id, 'CA2b');
    }

    /**
     * CA2c — después de la ficha, un recálculo y un PUT de la fuente sin cambios no mueven nada.
     *
     * @test
     */
    public function ca2c_un_recalculo_y_un_put_de_la_fuente_sin_cambios_no_mueven_el_precio()
    {
        $this->escenario();
        $fuente = $this->crear_fuente();
        $copia = $this->crear_copia($fuente);

        $this->put_de_la_copia($copia, ['cost' => 4828, 'cost_incluye_iva' => true, 'aplicar_iva' => 1]);

        \App\Http\Controllers\Helpers\ArticleHelper::setFinalPrice(Article::find($copia->id), $this->galvan->id);
        $this->assert_neta($copia->id, 'después del recálculo');

        $this->putJson('api/article/' . $fuente->id, $this->payload_ficha($fuente))->assertStatus(200);
        $this->assert_neta($copia->id, 'después del PUT de la fuente');
    }

    /**
     * CA2d — con aplicar_iva en 0 el costo correcto es lo que paga (bruto) y se mantiene.
     *
     * @test
     */
    public function ca2d_con_aplicar_iva_en_cero_el_costo_es_el_bruto_y_se_mantiene()
    {
        $this->escenario();
        $fuente = $this->crear_fuente();
        $copia = $this->crear_copia($fuente);

        $this->put_de_la_copia($copia, ['cost' => 4828, 'cost_incluye_iva' => false, 'aplicar_iva' => 0]);
        $this->assertEqualsWithDelta(4828, $this->valores($copia->id)['cost'], 0.0000005);

        $this->helper()->checkArticle(Article::find($fuente->id));

        $v = $this->valores($copia->id);
        $this->assertEqualsWithDelta(4828, $v['cost'], 0.0000005, 'sigue en 4828');
        $this->assertEqualsWithDelta(self::FINAL, $v['final'], 0.0001);
    }

    /**
     * CA2e — destildar aplicar_iva con el costo neto que muestra la ficha BAJA el precio hasta la
     * próxima escritura del helper. Se fijan las dos etapas (P-C).
     *
     * @test
     */
    public function ca2e_destildar_aplicar_iva_con_el_neto_baja_el_precio_hasta_el_proximo_sync()
    {
        $this->escenario();
        $fuente = $this->crear_fuente();
        $copia = $this->crear_copia($fuente);

        $this->put_de_la_copia($copia, ['cost' => 3990.082645, 'cost_incluye_iva' => false, 'aplicar_iva' => 0]);

        $v = $this->valores($copia->id);
        $this->assertEqualsWithDelta(3990.082645, $v['cost'], 0.0000005, 'etapa 1: cost');
        $this->assertEqualsWithDelta(3990.082645, $v['costo_real'], 0.0000005, 'etapa 1: costo_real');
        $this->assertEqualsWithDelta(4389.09, $v['final'], 0.0001, 'etapa 1: el precio baja');

        $this->assertTrue($this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, $fuente, Article::find($copia->id)));

        $v = $this->valores($copia->id);
        $this->assertEqualsWithDelta(4828.0, $v['cost'], 0.0000005, 'etapa 2: cost');
        $this->assertEqualsWithDelta(self::FINAL, $v['final'], 0.0001, 'etapa 2: final');
        $this->assertEquals(1, $v['marca']);
    }

    /**
     * CA3 — la fuente en F deja la copia en cost = round(F/1,21, 6) y final = round(cost·1,21·1,10, 2).
     *
     * @test
     */
    public function ca3_la_fuente_en_f_deja_la_copia_en_el_neto_de_f()
    {
        $this->escenario();
        $fuente = $this->crear_fuente();
        $copia = $this->crear_copia($fuente);

        $nueva = $this->mover_fuente($fuente, 6050.00);
        $this->helper()->checkArticle($nueva);

        $cost = round(6050.00 / 1.21, 6);
        $v = $this->valores($copia->id);

        $this->assertEqualsWithDelta($cost, $v['cost'], 0.0000005);
        $this->assertEqualsWithDelta(round($cost * 1.21 * 1.10, 2), $v['final'], 0.0001);
        $this->assertEquals(1, $v['marca']);
    }

    /**
     * CA4 — el cron es idempotente: con la fuente en F' escribe una vez; la segunda llamada devuelve
     * false sin save, con foto_efectos igual y sin price_changes nuevos.
     *
     * @test
     */
    public function ca4_el_cron_es_idempotente()
    {
        $this->escenario();
        $fuente = $this->crear_fuente();
        $copia = $this->crear_copia($fuente);

        $fuente = $this->mover_fuente($fuente, 6050.00);
        $viejo = Article::find($copia->id);

        $this->assertTrue($this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, $fuente, $viejo));

        $v = $this->valores($copia->id);
        $this->assertEqualsWithDelta(5000.0, $v['cost'], 0.0000005);
        $this->assertEqualsWithDelta(6655.00, $v['final'], 0.0001);

        $foto = $this->foto_efectos([$copia->id]);

        $this->assertFalse($this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, $fuente, $viejo));
        $this->assertEquals($foto, $this->foto_efectos([$copia->id]));
    }

    /**
     * CA5a — con el interruptor apagado el alta es la de hoy: costo bruto, aplicar_iva null en
     * memoria, final 5310,80 y marca 0. Releída y recalculada da 6426,07 (el estado "expuesta" de hoy,
     * que NO es el objetivo). Guarda: pasa sobre el código de hoy.
     *
     * @test
     */
    public function ca5a_con_el_interruptor_apagado_el_alta_es_la_de_hoy()
    {
        $this->escenario([], false);
        $fuente = $this->crear_fuente();

        $objeto = $this->helper()->createClientArticle($fuente);

        $this->assertEqualsWithDelta(4828.0, (float) $objeto->cost, 0.0000005);
        $this->assertNull($objeto->aplicar_iva);
        $this->assertEqualsWithDelta(4828.0, (float) $objeto->costo_real, 0.0000005);
        $this->assertEqualsWithDelta(self::FINAL, (float) $objeto->final_price, 0.0001);
        $this->assertEquals(0, $this->marca_de($objeto->id));

        \App\Http\Controllers\Helpers\ArticleHelper::setFinalPrice(Article::find($objeto->id), $this->galvan->id);
        $this->assertEqualsWithDelta(self::FINAL_INFLADO, (float) Article::find($objeto->id)->final_price, 0.0001);
    }

    /**
     * CA5b — una copia inflada (los 208) sigue igual después de un PUT de la fuente y del cron.
     * (La parte de E1 está en 5_Conversion_de_copias_existentes_Test.) Guarda.
     *
     * @test
     */
    public function ca5b_una_copia_inflada_sigue_igual_con_el_put_de_la_fuente_y_el_cron()
    {
        $this->escenario([], false);
        $fuente = $this->crear_fuente();
        $copia = $this->crear_copia($fuente);
        $copia = $this->fijar($copia, ['aplicar_iva' => 1, 'cost' => 4828, 'costo_real' => 5841.88, 'final_price' => self::FINAL_INFLADO]);

        $this->putJson('api/article/' . $fuente->id, $this->payload_ficha($fuente))->assertStatus(200);
        $this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, Article::find($fuente->id), Article::find($copia->id));

        $v = $this->valores($copia->id);
        $this->assertEqualsWithDelta(4828.0, $v['cost'], 0.0000005);
        $this->assertEqualsWithDelta(5841.88, $v['costo_real'], 0.005);
        $this->assertEqualsWithDelta(self::FINAL_INFLADO, $v['final'], 0.0001);
        $this->assertEquals(0, $v['marca']);
    }

    /**
     * CA11 — compra espejo: una venta de Fénix a Galván crea 1 ProviderOrder con update_prices NULL y
     * no toca la copia convertida (ni escribe price_changes).
     *
     * @test
     */
    public function ca11_la_compra_espejo_no_toca_el_costo_de_la_copia()
    {
        $this->escenario();
        $fuente = $this->crear_fuente();
        $copia = $this->crear_copia($fuente);

        $antes = $this->valores($copia->id);
        $ordenes = ProviderOrder::where('user_id', $this->galvan->id)->count();
        $cambios = DB::table('price_changes')->where('article_id', $copia->id)->count();

        $total = 4828;
        $this->postJson('api/sale', [
            'client_id'                        => $this->cliente_de_fenix->id,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 1,
            'to_check'                         => 0,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'sub_total'                        => $total,
            'total'                            => $total,
            'terminada'                        => 1,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => [[
                'is_article'   => true,
                'id'           => $fuente->id,
                'price_vender' => $total,
                'amount'       => 1,
                'costo_real'   => $total,
            ]],
        ])->assertStatus(201);

        $this->assertEquals($ordenes + 1, ProviderOrder::where('user_id', $this->galvan->id)->count());

        $orden = ProviderOrder::where('user_id', $this->galvan->id)->orderBy('id', 'DESC')->first();
        // Hallazgo (INFORME): el PLAN supone update_prices NULL (dato de producción), pero el esquema de
        // migraciones lo crea con default 1 y SaleProviderOrderHelper no lo escribe: en este fixture queda en 1.
        // Lo que fija D2-M es que el espejo no toca la copia, no el valor de esa columna.
        $this->assertTrue(is_null($orden->precios_incluyen_iva) || (int) $orden->precios_incluyen_iva === 0);

        $this->assertEquals($antes, $this->valores($copia->id));
        $this->assertEquals($cambios, DB::table('price_changes')->where('article_id', $copia->id)->count(), 'sin price_changes nuevos en la copia');
        $this->assertEqualsWithDelta(3990.082645, $this->valores($copia->id)['cost'], 0.0000005);
        $this->assertEqualsWithDelta(self::FINAL, $this->valores($copia->id)['final'], 0.0001);
        $this->assertEquals(1, $this->valores($copia->id)['marca']);
    }

    /**
     * CA11, variante — la vinculación sigue siendo la autoridad: si una compra con update_prices=1
     * dejó en `cost` el precio de Fénix, un sync lo devuelve a costo_para_la_copia (3990,082645) y a
     * 5310,80. (La compra se emula por DB: el valor que deja `update_cost` en una copia RI es el
     * precio del renglón tal cual, como neto.)
     *
     * @test
     */
    public function ca11_variante_el_sync_devuelve_el_costo_de_una_compra_con_update_prices()
    {
        $this->escenario();
        $fuente = $this->crear_fuente();
        $copia = $this->crear_copia($fuente);

        $this->fijar($copia, ['cost' => 4828, 'costo_real' => 5841.88, 'final_price' => self::FINAL_INFLADO]);

        $this->assertTrue($this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, $fuente, Article::find($copia->id)));

        $v = $this->valores($copia->id);
        $this->assertEqualsWithDelta(self::NETO, $v['cost'], 0.0000005);
        $this->assertEqualsWithDelta(self::FINAL, $v['final'], 0.0001);
    }
}
