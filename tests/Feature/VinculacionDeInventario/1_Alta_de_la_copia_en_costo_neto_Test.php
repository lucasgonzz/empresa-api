<?php

namespace Tests\Feature\VinculacionDeInventario;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\Category;
use App\Models\Client;
use App\Models\InventoryLinkage;
use App\Models\Provider;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CA1 (alta en las tres vías, objeto devuelto y releído), CA10 (redondeo determinista) y CA12
 * (vinculación sin contexto) del PLAN D2-M R3.1.
 *
 * @group vinculacion-iva-copia
 */
class Alta_de_la_copia_en_costo_neto_Test extends VinculacionDeInventarioTestCase
{
    /**
     * Comprueba el estado de una copia recién creada en costo neto (CA1).
     *
     * @param  \App\Models\Article $copia Objeto o fila releída.
     * @param  string              $donde Etiqueta para el mensaje.
     * @return void
     */
    private function assert_copia_neta($copia, $donde)
    {
        $this->assertEquals(1, $copia->aplicar_iva, $donde . ': aplicar_iva');
        $this->assertEquals(1, $copia->costo_neto_de_vinculacion, $donde . ': marca');
        $this->assertEqualsWithDelta(self::NETO, (float) $copia->cost, 0.0000005, $donde . ': cost');
        $this->assertEqualsWithDelta(self::BRUTO, (float) $copia->costo_real, 0.0000005, $donde . ': costo_real');
        $this->assertEqualsWithDelta(self::FINAL, (float) $copia->final_price, 0.0001, $donde . ': final_price (no 4389,09)');
    }

    /**
     * CA1, vía `ensure_client_article_for_linkage`: el objeto que devuelve, ANTES de releerlo.
     *
     * @test
     */
    public function ca1_alta_por_ensure_devuelve_el_objeto_en_neto()
    {
        $this->escenario();
        $fuente = $this->crear_fuente();

        $objeto = $this->helper()->ensure_client_article_for_linkage($this->vinculacion, $fuente);

        $this->assert_copia_neta($objeto, 'objeto devuelto');
        $this->assert_copia_neta(Article::find($objeto->id), 'releído');

        ArticleHelper::setFinalPrice(Article::find($objeto->id), $this->galvan->id);
        $this->assertEqualsWithDelta(self::FINAL, (float) Article::find($objeto->id)->final_price, 0.0001, 'recalculado sigue en 5310,80 (hoy da 6426,07)');
    }

    /**
     * CA1, vía `checkArticle` con copia inexistente (el alta que ejecuta es `createClientArticle`,
     * que es lo que devuelve el objeto) y vía `setClientArticles` (no devuelve nada: se comprueba la
     * fila recién creada antes de cualquier recálculo).
     *
     * @test
     */
    public function ca1_alta_por_check_article_y_por_set_client_articles()
    {
        $this->escenario();
        $fuente = $this->crear_fuente();

        $objeto = $this->helper()->createClientArticle($fuente);
        $this->assert_copia_neta($objeto, 'createClientArticle (objeto devuelto)');
        Article::where('id', $objeto->id)->forceDelete();

        $this->helper()->checkArticle($fuente);
        $this->assert_copia_neta($this->copia_de($fuente), 'checkArticle (fila)');
        Article::where('provider_article_id', $fuente->id)->forceDelete();

        $this->helper()->setClientArticles();
        $this->assert_copia_neta($this->copia_de($fuente), 'setClientArticles (fila)');
    }

    /**
     * CA10 — redondeo determinista: una copia nueva con la fuente en 1532,95 da el mismo final_price
     * en el objeto devuelto, releída y recalculada: 1686,24 (reglas existentes, PHP 7.4).
     *
     * @test
     */
    public function ca10_el_redondeo_es_el_mismo_en_el_objeto_releido_y_recalculado()
    {
        $this->escenario();
        $fuente = $this->crear_fuente(1532.95);

        $objeto = $this->helper()->createClientArticle($fuente);

        $this->assertEqualsWithDelta(1686.24, (float) $objeto->final_price, 0.0001, 'objeto devuelto');
        $this->assertEqualsWithDelta(1686.24, (float) Article::find($objeto->id)->final_price, 0.0001, 'releído');

        ArticleHelper::setFinalPrice(Article::find($objeto->id), $this->galvan->id);
        $this->assertEqualsWithDelta(1686.24, (float) Article::find($objeto->id)->final_price, 0.0001, 'recalculado');
    }

    /**
     * Las cuatro variantes de vinculación sin contexto de CA12, cada una con una segunda vinculación
     * válida del mismo proveedor. Devuelve [vinculación sin contexto, id del usuario de la variante].
     *
     * @param  string $variante v1|v2|v3|v4
     * @return array
     */
    private function variante_sin_contexto($variante)
    {
        $usuario_id = null;
        $cliente_datos = ['user_id' => $this->fenix->id, 'name' => 'zz cliente ' . $variante];

        if ($variante == 'v1') {
            // Cliente sin comercio_city_user_id.
            $cliente_datos['comercio_city_user_id'] = null;
        } elseif ($variante == 'v2') {
            // Usuario inexistente, con un Provider de ese id.
            $usuario_id = 99999000 + rand(1, 999);
            $cliente_datos['comercio_city_user_id'] = $usuario_id;
            Provider::create(['name' => 'zz prov inexistente', 'user_id' => $usuario_id, 'comercio_city_user_id' => $this->fenix->id]);
        } elseif ($variante == 'v3') {
            // Cliente en la papelera (con contexto completo, pero borrado).
            $otro = $this->crear_dueno();
            $usuario_id = $otro->id;
            $cliente_datos['comercio_city_user_id'] = $otro->id;
            $this->crear_proveedor($otro, ['comercio_city_user_id' => $this->fenix->id]);
        } else {
            // v4: cliente válido, sin Provider hacia el proveedor.
            $otro = $this->crear_dueno();
            $usuario_id = $otro->id;
            $cliente_datos['comercio_city_user_id'] = $otro->id;
        }

        $cliente = Client::create($cliente_datos);
        $vinculacion = $this->crear_vinculacion($cliente, false);

        if ($variante == 'v3') {
            $cliente->delete();
            $vinculacion = InventoryLinkage::find($vinculacion->id);
        }

        $this->actingAs(User::find($this->fenix->id), 'web');

        return [$vinculacion, $usuario_id];
    }

    /**
     * Estado observable de CA12: nada huérfano.
     *
     * @param  array    $fuentes_ids
     * @param  int|null $usuario_id
     * @return array
     */
    private function foto_huerfanos(array $fuentes_ids, $usuario_id)
    {
        return [
            'copias_de_fenix'        => Article::withTrashed()->whereIn('provider_article_id', $fuentes_ids)->count(),
            'categorias_huerfanas'   => DB::table('categories')->whereNull('user_id')->count(),
            'subcategorias_huerfanas' => DB::table('sub_categories')->whereNull('user_id')->count(),
            'del_usuario'            => is_null($usuario_id) ? 0 : DB::table('articles')->where('user_id', $usuario_id)->count(),
            'categorias_del_usuario' => is_null($usuario_id) ? 0 : DB::table('categories')->where('user_id', $usuario_id)->count(),
            'subcat_del_usuario'     => is_null($usuario_id) ? 0 : DB::table('sub_categories')->where('user_id', $usuario_id)->count(),
        ];
    }

    /**
     * @return array Variantes de CA12 × interruptor de la vinculación válida (apagado y prendido).
     */
    public function variantes()
    {
        $casos = [];

        foreach (['v1', 'v2', 'v3', 'v4'] as $variante) {
            $casos[$variante . ' interruptor apagado'] = [$variante, false];
            $casos[$variante . ' interruptor prendido'] = [$variante, true];
        }

        return $casos;
    }

    /**
     * CA12a — `--activar-vinculacion` se niega, con un mensaje claro, sin excepción y sin escribir el
     * interruptor, en las cuatro variantes.
     *
     * @dataProvider variantes
     * @test
     */
    public function ca12a_activar_una_vinculacion_sin_contexto_se_niega($variante, $prendido)
    {
        $this->escenario([], $prendido);
        list($sin_contexto, $usuario_id) = $this->variante_sin_contexto($variante);

        list($codigo, $salida) = $this->comando(['--activar-vinculacion' => $sin_contexto->id]);

        $this->assertNotEquals(0, $codigo, 'activar sin contexto tiene que fallar: ' . $salida);
        $this->assertEquals(0, (int) DB::table('inventory_linkages')->where('id', $sin_contexto->id)->value('copia_en_costo_neto'));
    }

    /**
     * CA12b, c y e — ensure devuelve null, `checkArticle` no lanza y sólo la vinculación válida crea su
     * copia, y sync no escribe. Hoy falla por comportamiento (excepción o categoría con user_id NULL).
     *
     * @dataProvider variantes
     * @test
     */
    public function ca12_una_vinculacion_sin_contexto_se_salta_sin_efectos($variante, $prendido)
    {
        $this->escenario([], $prendido);

        $categoria = $this->crear_categoria($this->fenix);
        $subcategoria = $this->crear_subcategoria($this->fenix, $categoria);
        $fuente = $this->crear_fuente();
        DB::table('articles')->where('id', $fuente->id)->update(['category_id' => $categoria->id, 'sub_category_id' => $subcategoria->id]);
        $fuente = Article::find($fuente->id);

        list($sin_contexto, $usuario_id) = $this->variante_sin_contexto($variante);

        // (b) ensure_client_article_for_linkage devuelve null.
        $antes = $this->foto_huerfanos([$fuente->id], $usuario_id);
        $this->assertNull($this->helper($sin_contexto)->ensure_client_article_for_linkage($sin_contexto, $fuente));

        // (c) checkArticle no lanza; sólo la vinculación válida crea su copia.
        Log::spy();
        $this->helper()->checkArticle($fuente);
        $despues = $this->foto_huerfanos([$fuente->id], $usuario_id);

        $this->assertEquals($antes['copias_de_fenix'] + 1, $despues['copias_de_fenix'], 'sólo la copia de la vinculación válida');
        foreach (['categorias_huerfanas', 'subcategorias_huerfanas', 'del_usuario', 'categorias_del_usuario', 'subcat_del_usuario'] as $clave) {
            $this->assertEquals($antes[$clave], $despues[$clave], $clave . ' no cambia');
        }
        Log::shouldHaveReceived('warning')->atLeast()->once();

        // (e) sync con esa vinculación devuelve false sin escribir.
        $copia = $this->copia_de($fuente);
        $this->assertFalse($this->helper()->sync_client_article_price_and_bar_code($sin_contexto, $fuente, $copia));
    }

    /**
     * CA12c por el endpoint: el PUT de la fuente responde 200 aunque haya una vinculación sin contexto.
     *
     * @dataProvider variantes
     * @test
     */
    public function ca12c_el_put_de_la_fuente_responde_200($variante, $prendido)
    {
        $this->escenario([], $prendido);
        $fuente = $this->crear_fuente();
        $this->variante_sin_contexto($variante);

        $this->putJson('api/article/' . $fuente->id, $this->payload_ficha($fuente))->assertStatus(200);
    }

    /**
     * CA12d — constructor y store: ninguna entrada lanza y el POST responde 201 sin dejar artículos ni
     * categorías huérfanos. v3 (cliente en la papelera) fija el constructor.
     *
     * @dataProvider variantes
     * @test
     */
    public function ca12d_constructor_y_store_no_lanzan_ni_dejan_huerfanos($variante, $prendido)
    {
        $this->escenario([], $prendido);
        $categoria = $this->crear_categoria($this->fenix);
        $fuente = $this->crear_fuente();
        DB::table('articles')->where('id', $fuente->id)->update(['category_id' => $categoria->id]);

        list($sin_contexto, $usuario_id) = $this->variante_sin_contexto($variante);

        $antes = $this->foto_huerfanos([$fuente->id], $usuario_id);

        $helper = new \App\Http\Controllers\Helpers\InventoryLinkageHelper($sin_contexto, $this->fenix->id);
        $helper->setClientArticles();

        $this->assertEquals($antes, $this->foto_huerfanos([$fuente->id], $usuario_id), 'por el helper: nada cambia');

        $cuenta = InventoryLinkage::count();
        $cliente_id = $sin_contexto->client_id;

        $this->postJson('api/inventory-linkage', [
            'client_id'                  => $cliente_id,
            'inventory_linkage_scope_id' => $sin_contexto->inventory_linkage_scope_id,
            'categories'                 => [],
        ])->assertStatus(201);

        $this->assertEquals($cuenta + 1, InventoryLinkage::count(), 'el POST deja 1 fila nueva');
        $this->assertEquals($antes, $this->foto_huerfanos([$fuente->id], $usuario_id), 'por el POST: nada cambia');
    }
}
