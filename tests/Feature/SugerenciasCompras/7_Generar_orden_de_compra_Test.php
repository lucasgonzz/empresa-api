<?php

namespace Tests\Feature\SugerenciasCompras;

use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\PurchaseSuggestion;
use App\Models\PurchaseSuggestionArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión sugerencias-compra-proveedores — archivo 7: POST
 * .../create-provider-order (plan §8).
 *
 * 🔴 El test que más importa de este archivo es el caveat de IVA
 * (caveat_de_iva_el_literal_tipeado_queda_neto_y_nada_mas_se_contamina):
 * costo_estimado viene NETO; la orden tiene que nacer con
 * precios_incluyen_iva = false para que el literal tipeado se interprete
 * como neto y no se le vuelva a sacar IVA. Es el punto donde un error se
 * convierte directamente en plata mal calculada.
 */
class Generar_orden_de_compra_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio orden P7',
            'email'    => 'sugerencias-compras-p7-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->dar_extension();
        $this->actingAs($this->comercio, 'web');
    }

    /**
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', 'sugerencias_compras')->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => 'sugerencias_compras',
                'name' => 'Sugerencias de compra a proveedores',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * @param array $overrides
     * @return PurchaseSuggestion
     */
    protected function crear_suggestion(array $overrides = [])
    {
        return PurchaseSuggestion::create(array_merge([
            'user_id'           => $this->comercio->id,
            'status'            => 'terminado',
            'origen_generacion' => 'manual',
        ], $overrides));
    }

    /**
     * Línea directa de la sugerencia (sin correr el pipeline: acá se prueba
     * el endpoint de la orden, no el cálculo).
     *
     * @param PurchaseSuggestion $suggestion
     * @param Provider|null $provider
     * @param array $overrides
     * @return PurchaseSuggestionArticle
     */
    protected function crear_linea($suggestion, $provider, array $overrides = [])
    {
        $nombre_articulo = isset($overrides['article_name']) ? $overrides['article_name'] : 'Articulo orden P7';
        unset($overrides['article_name']);

        $article = Article::create([
            'name'    => $nombre_articulo,
            'user_id' => $this->comercio->id,
        ]);

        return PurchaseSuggestionArticle::create(array_merge([
            'purchase_suggestion_id' => $suggestion->id,
            'article_id'             => $article->id,
            'provider_id'            => $provider ? $provider->id : null,
            'provider_id_titular'    => $provider ? $provider->id : null,
            'cantidad_sugerida'      => 10,
            'costo_estimado'         => 100,
        ], $overrides));
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function dos_proveedores_generan_dos_ordenes_agrupadas_con_sus_lineas_y_quedan_ligadas_a_la_sugerencia()
    {
        $suggestion = $this->crear_suggestion();
        $provider1 = Provider::create(['name' => 'Proveedor 1 P7', 'user_id' => $this->comercio->id]);
        $provider2 = Provider::create(['name' => 'Proveedor 2 P7', 'user_id' => $this->comercio->id]);

        $linea1 = $this->crear_linea($suggestion, $provider1, ['article_name' => 'Articulo A P7']);
        $linea2 = $this->crear_linea($suggestion, $provider1, ['article_name' => 'Articulo B P7']);
        $linea3 = $this->crear_linea($suggestion, $provider2, ['article_name' => 'Articulo C P7']);

        $response = $this->postJson('api/purchase-suggestion/' . $suggestion->id . '/create-provider-order', [
            'purchase_suggestion_article_ids' => [$linea1->id, $linea2->id, $linea3->id],
        ]);

        $response->assertStatus(201);
        $ordenes = $response->json('provider_orders');
        $this->assertCount(2, $ordenes, 'Dos proveedores distintos tienen que generar dos órdenes.');

        $orden_provider1 = null;
        $orden_provider2 = null;
        foreach ($ordenes as $orden) {
            if ((int) $orden['provider_id'] === $provider1->id) {
                $orden_provider1 = $orden;
            }
            if ((int) $orden['provider_id'] === $provider2->id) {
                $orden_provider2 = $orden;
            }
        }

        $this->assertNotNull($orden_provider1);
        $this->assertNotNull($orden_provider2);
        $this->assertCount(2, $orden_provider1['articles'], 'Las dos líneas del proveedor 1 tienen que ir en la MISMA orden.');
        $this->assertCount(1, $orden_provider2['articles']);

        $this->assertEquals($suggestion->id, (int) $orden_provider1['purchase_suggestion_id']);
        $this->assertEquals($suggestion->id, (int) $orden_provider2['purchase_suggestion_id']);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function una_linea_sin_proveedor_asignado_frena_todo_con_422_y_no_crea_nada()
    {
        $suggestion = $this->crear_suggestion();
        $provider = Provider::create(['name' => 'Proveedor con dueno P7', 'user_id' => $this->comercio->id]);

        $linea_con_proveedor = $this->crear_linea($suggestion, $provider);
        $linea_sin_proveedor = $this->crear_linea($suggestion, null);

        $antes = ProviderOrder::count();

        $response = $this->postJson('api/purchase-suggestion/' . $suggestion->id . '/create-provider-order', [
            'purchase_suggestion_article_ids' => [$linea_con_proveedor->id, $linea_sin_proveedor->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Hay líneas sin proveedor asignado: asignales un proveedor al artículo o sacalas de la selección.',
        ]);

        $this->assertEquals(
            $antes,
            ProviderOrder::count(),
            'Con UNA sola línea sin proveedor, no se puede crear NINGUNA orden — ni la del proveedor que sí estaba completo.'
        );
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function la_orden_nace_con_status_1_y_las_tres_banderas_en_cero()
    {
        $suggestion = $this->crear_suggestion();
        $provider = Provider::create(['name' => 'Proveedor banderas P7', 'user_id' => $this->comercio->id]);
        $linea = $this->crear_linea($suggestion, $provider);

        $response = $this->postJson('api/purchase-suggestion/' . $suggestion->id . '/create-provider-order', [
            'purchase_suggestion_article_ids' => [$linea->id],
        ]);

        $response->assertStatus(201);
        $order = ProviderOrder::find($response->json('provider_orders.0.id'));
        $this->assertNotNull($order);

        $this->assertEquals(1, (int) $order->provider_order_status_id, "'En proceso': la orden nace pendiente, no confirmada.");
        $this->assertEquals(0, (int) $order->update_stock, 'No es una compra recibida.');
        $this->assertEquals(0, (int) $order->update_prices, 'El costo es SUGERIDO, no puede pisar article_provider como si fuera real.');
        $this->assertEquals(0, (int) $order->generate_current_acount, 'No genera deuda hasta que se confirme.');
        $this->assertEquals(1, (int) $order->moneda_id);
        $this->assertEquals($suggestion->id, (int) $order->purchase_suggestion_id);
    }

    /**
     * 🔴 EL TEST QUE MÁS IMPORTA DE ESTE ARCHIVO. costo_estimado viene NETO;
     * la orden tiene que nacer con precios_incluyen_iva = false para que el
     * literal tipeado en article_provider_order.cost se interprete como neto
     * (correcto) y no como bruto (le sacaría IVA a un valor que ya era neto).
     *
     * Y, con update_prices/update_stock en 0, nada de lo que YA EXISTÍA en el
     * artículo puede tocarse: ni articles.cost, ni articles.provider_id, ni
     * el pivot article_provider del proveedor elegido.
     *
     * @group sugerencias-compras
     * @test
     */
    public function caveat_de_iva_el_literal_tipeado_queda_neto_y_nada_mas_se_contamina()
    {
        $suggestion = $this->crear_suggestion();
        $provider_original = Provider::create(['name' => 'Proveedor original P7', 'user_id' => $this->comercio->id]);
        $provider_elegido = Provider::create(['name' => 'Proveedor elegido P7', 'user_id' => $this->comercio->id]);

        $articulo = Article::create([
            'name'        => 'Articulo caveat iva P7',
            'user_id'     => $this->comercio->id,
            'cost'        => 999,
            'provider_id' => $provider_original->id,
        ]);

        // Pivot PRE-EXISTENTE con el proveedor elegido, a un costo BIEN
        // distinto de costo_estimado (654.321): si catalogar_costo_proveedor()
        // corriera acá (no debería: update_prices = 0), este valor pisaría a 654.
        $articulo->providers()->attach($provider_elegido->id, ['cost' => 777]);

        $linea = PurchaseSuggestionArticle::create([
            'purchase_suggestion_id' => $suggestion->id,
            'article_id'             => $articulo->id,
            'provider_id'            => $provider_elegido->id,
            'provider_id_titular'    => $provider_original->id,
            'cantidad_sugerida'      => 10,
            'costo_estimado'         => 654.321,
        ]);

        $response = $this->postJson('api/purchase-suggestion/' . $suggestion->id . '/create-provider-order', [
            'purchase_suggestion_article_ids' => [$linea->id],
        ]);

        $response->assertStatus(201);
        $order = ProviderOrder::find($response->json('provider_orders.0.id'));
        $this->assertNotNull($order);

        // El caveat en sí.
        $this->assertFalse($order->precios_incluyen_iva, 'costo_estimado ya viene NETO: la orden tiene que nacer con precios_incluyen_iva = false.');

        // El literal tipeado en la línea de la compra es EXACTAMENTE costo_estimado.
        $pivot = DB::table('article_provider_order')
            ->where('provider_order_id', $order->id)
            ->where('article_id', $articulo->id)
            ->first();
        $this->assertNotNull($pivot);
        $this->assertEqualsWithDelta(654.321, (float) $pivot->cost, 0.0001, 'article_provider_order.cost tiene que ser el costo_estimado literal.');

        // Y nada de lo que ya existía se contamina.
        $articulo->refresh();
        $this->assertEqualsWithDelta(999.0, (float) $articulo->cost, 0.0001, 'articles.cost no puede cambiar: update_prices = 0.');
        $this->assertEquals($provider_original->id, (int) $articulo->provider_id, 'articles.provider_id no puede cambiar: update_prices = 0 y sin update_provider tildado.');

        $fila_article_provider = DB::table('article_provider')
            ->where('article_id', $articulo->id)
            ->where('provider_id', $provider_elegido->id)
            ->first();
        $this->assertEquals(777, (int) $fila_article_provider->cost, 'article_provider no puede tocarse: sigue con el costo de ANTES de generar la orden.');
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function la_seleccion_parcial_deja_afuera_las_lineas_no_elegidas()
    {
        $suggestion = $this->crear_suggestion();
        $provider = Provider::create(['name' => 'Proveedor parcial P7', 'user_id' => $this->comercio->id]);

        $linea1 = $this->crear_linea($suggestion, $provider, ['article_name' => 'Elegido 1 P7']);
        $linea2 = $this->crear_linea($suggestion, $provider, ['article_name' => 'Elegido 2 P7']);
        $linea3 = $this->crear_linea($suggestion, $provider, ['article_name' => 'No elegido P7']);

        $response = $this->postJson('api/purchase-suggestion/' . $suggestion->id . '/create-provider-order', [
            'purchase_suggestion_article_ids' => [$linea1->id, $linea2->id],
        ]);

        $response->assertStatus(201);
        $order_id = $response->json('provider_orders.0.id');

        $articulos_de_la_orden = DB::table('article_provider_order')
            ->where('provider_order_id', $order_id)
            ->pluck('article_id')
            ->toArray();

        $this->assertCount(2, $articulos_de_la_orden);
        $this->assertContains($linea1->article_id, $articulos_de_la_orden);
        $this->assertContains($linea2->article_id, $articulos_de_la_orden);
        $this->assertNotContains($linea3->article_id, $articulos_de_la_orden, 'La línea no seleccionada no puede terminar en la orden.');
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function sin_lineas_seleccionadas_o_sin_coincidencias_devuelve_422()
    {
        $suggestion = $this->crear_suggestion();
        $provider = Provider::create(['name' => 'Proveedor vacio P7', 'user_id' => $this->comercio->id]);
        $this->crear_linea($suggestion, $provider);

        // Caso 1: array vacío.
        $response = $this->postJson('api/purchase-suggestion/' . $suggestion->id . '/create-provider-order', [
            'purchase_suggestion_article_ids' => [],
        ]);
        $response->assertStatus(422);

        // Caso 2: ids que no pertenecen a esta sugerencia (0 coincidencias).
        $response = $this->postJson('api/purchase-suggestion/' . $suggestion->id . '/create-provider-order', [
            'purchase_suggestion_article_ids' => [999999],
        ]);
        $response->assertStatus(422);

        $this->assertEquals(0, ProviderOrder::where('purchase_suggestion_id', $suggestion->id)->count());
    }
}
