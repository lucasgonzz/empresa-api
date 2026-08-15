<?php

namespace Tests\Feature\SugerenciasCompras;

use App\Jobs\GeneratePurchaseSuggestionChunksJob;
use App\Models\Address;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\Provider;
use App\Models\PurchaseSuggestion;
use App\Models\PurchaseSuggestionArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión sugerencias-compra-proveedores — arreglos post-chequeo (archivo 9):
 * validación de los cuatro parámetros del motor (A4), el camino sincrónico
 * de store() (A8), el reset de la plata al re-correr (A5), el guard de
 * "pendiente" en destroy() (A6) y el criterio del filtro
 * solo_cambio_de_proveedor alineado entre back y front (A7).
 *
 * Los arreglos A9 (guard de cabecera null en ProcessPurchaseSuggestionChunkJob)
 * y A13 (comentario de andamiaje sacado) no tienen una aserción de negocio
 * propia: A9 es puramente defensivo (nada que observar desde afuera cuando la
 * cabecera existe, que es el camino feliz que ya cubren los archivos 4 y 5,
 * y el guard evita un fatal que no deja rastro de negocio) y A13 es un
 * comentario. Quedan cubiertos por correr la suite completa en verde.
 */
class Validaciones_y_reprocesos_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea todo el módulo. */
    const SLUG = 'sugerencias_compras';

    /** @var User */
    protected $comercio;

    /** @var Address */
    protected $deposito;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Si no, el pipeline sale a la API real de Anthropic.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio validaciones P9',
            'email'    => 'sugerencias-compras-p9-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->deposito = Address::create([
            'street'  => 'Deposito validaciones P9',
            'user_id' => $this->comercio->id,
        ]);

        $this->dar_extension();
        $this->actingAs($this->comercio, 'web');
    }

    /**
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            // forceCreate: el modelo no declara $fillable.
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
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
            'status'            => 'pendiente',
            'origen_generacion' => 'manual',
        ], $overrides));
    }

    /**
     * Línea directa de la sugerencia (sin correr el pipeline).
     *
     * @param PurchaseSuggestion $suggestion
     * @param array $overrides
     * @return PurchaseSuggestionArticle
     */
    protected function crear_linea($suggestion, array $overrides = [])
    {
        $nombre_articulo = isset($overrides['article_name']) ? $overrides['article_name'] : 'Articulo P9';
        unset($overrides['article_name']);

        $article = Article::create(['name' => $nombre_articulo, 'user_id' => $this->comercio->id]);

        return PurchaseSuggestionArticle::create(array_merge([
            'purchase_suggestion_id' => $suggestion->id,
            'article_id'             => $article->id,
            'cantidad_sugerida'      => 5,
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // A4 — validación de los cuatro parámetros del motor
    // ------------------------------------------------------------------

    /**
     * @group sugerencias-compras
     * @test
     */
    public function cada_parametro_fuera_de_rango_devuelve_422_con_mensaje_y_no_crea_nada()
    {
        $base = [
            'dias_punto_pedido'       => 15,
            'dias_cobertura_objetivo' => 30,
            'dias_lead_time'          => 7,
            'dias_vigencia_oferta'    => 120,
        ];

        $valores_invalidos_por_campo = [
            'dias_punto_pedido'       => [0, -5, 999999],
            'dias_cobertura_objetivo' => [0, -1, 999999],
            'dias_lead_time'          => [0, -3, 999999],
            'dias_vigencia_oferta'    => [0, -10, 999999],
        ];

        foreach ($valores_invalidos_por_campo as $campo => $valores) {
            foreach ($valores as $valor) {
                $antes = PurchaseSuggestion::count();

                $response = $this->postJson('api/purchase-suggestion', array_merge($base, [$campo => $valor]));

                $response->assertStatus(422, "Se esperaba 422 para {$campo} = {$valor}.");
                $mensaje = $response->json('message');
                $this->assertNotEmpty($mensaje, "Tiene que venir un mensaje entendible para {$campo} = {$valor}.");

                $this->assertEquals(
                    $antes,
                    PurchaseSuggestion::count(),
                    "Con {$campo} = {$valor} (fuera de rango) no se puede crear la cabecera."
                );
            }
        }
    }

    /**
     * Fija el texto exacto de UN caso (no todos, para no volver el test
     * frágil): protege que el mensaje sea específico y en criollo, no el
     * genérico de Laravel.
     *
     * @group sugerencias-compras
     * @test
     */
    public function dias_cobertura_objetivo_en_cero_explica_por_que_en_el_mensaje()
    {
        $response = $this->postJson('api/purchase-suggestion', [
            'dias_punto_pedido'       => 15,
            'dias_cobertura_objetivo' => 0,
            'dias_lead_time'          => 7,
            'dias_vigencia_oferta'    => 120,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Los días de cobertura objetivo tienen que ser un número entero de al menos 1: en 0 la cantidad sugerida se cae a 1 en cada línea disparada, sin ningún aviso.',
        ]);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function los_cuatro_parametros_en_rango_crean_la_sugerencia_201()
    {
        $response = $this->postJson('api/purchase-suggestion', [
            'dias_punto_pedido'       => 20,
            'dias_cobertura_objetivo' => 45,
            'dias_lead_time'          => 10,
            'dias_vigencia_oferta'    => 90,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(20, $response->json('model.dias_punto_pedido'));
        $this->assertEquals(45, $response->json('model.dias_cobertura_objetivo'));
        $this->assertEquals(10, $response->json('model.dias_lead_time'));
        $this->assertEquals(90, $response->json('model.dias_vigencia_oferta'));
    }

    /**
     * Sin mandar ningún parámetro, store() sigue usando los defaults del
     * motor (retrocompatibilidad: la validación se hace SOBRE el valor ya
     * casteado con su default, nunca reemplaza el default por "campo
     * ausente = error").
     *
     * @group sugerencias-compras
     * @test
     */
    public function sin_mandar_los_parametros_se_usan_los_defaults_del_motor()
    {
        $response = $this->postJson('api/purchase-suggestion', []);

        $response->assertStatus(201);
        $this->assertEquals(15, $response->json('model.dias_punto_pedido'));
        $this->assertEquals(30, $response->json('model.dias_cobertura_objetivo'));
        $this->assertEquals(7, $response->json('model.dias_lead_time'));
        $this->assertEquals(120, $response->json('model.dias_vigencia_oferta'));
    }

    // ------------------------------------------------------------------
    // A8 — camino sincrónico de store()
    // ------------------------------------------------------------------

    /**
     * A8: sin esto, store() siempre hacía dispatch() y en WAMP local (sin
     * queue:work corriendo) la sugerencia quedaba 'pendiente' para siempre.
     * Queue::fake() prueba esto de verdad: si el código hiciera dispatch(),
     * el job fake NUNCA correría y el status seguiría 'pendiente' al volver
     * del POST, sin importar qué QUEUE_CONNECTION tenga el entorno de test.
     *
     * @group sugerencias-compras
     * @test
     */
    public function catalogo_chico_sin_worker_termina_sincronico_en_el_mismo_request()
    {
        Queue::fake();

        Article::create(['name' => 'Articulo camino sincronico P9', 'user_id' => $this->comercio->id]);

        $response = $this->postJson('api/purchase-suggestion', [
            'dias_punto_pedido'       => 15,
            'dias_cobertura_objetivo' => 30,
            'dias_lead_time'          => 7,
            'dias_vigencia_oferta'    => 120,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('model.status', 'terminado');

        $suggestion = PurchaseSuggestion::find($response->json('model.id'));
        $this->assertEquals('terminado', $suggestion->status);

        Queue::assertNotPushed(GeneratePurchaseSuggestionChunksJob::class);
    }

    // ------------------------------------------------------------------
    // A5 — reset de la plata al re-correr
    // ------------------------------------------------------------------

    /**
     * A5: re-correr una sugerencia que ya había cerrado con total_estimado y
     * contexto_financiero tiene que dejarlos consistentes con las líneas
     * NUEVAS. El caso más nítido para probarlo sin depender de timings
     * intermedios es el catálogo vacío en la segunda pasada (chunk_count ===
     * 0): antes, ese camino marcaba 'terminado' y salía sin recalcular nada,
     * así que la plata de la corrida ANTERIOR quedaba mostrada sobre CERO
     * líneas nuevas.
     *
     * @group sugerencias-compras
     * @test
     */
    public function re_correr_con_catalogo_vacio_resetea_total_estimado_y_contexto_financiero()
    {
        $proveedor = Provider::create(['name' => 'Proveedor P9 re-run', 'user_id' => $this->comercio->id]);

        $articulo = Article::create(['name' => 'Articulo P9 re-run', 'user_id' => $this->comercio->id]);
        $articulo->addresses()->attach($this->deposito->id, ['amount' => 1, 'stock_min' => 10]);
        $articulo->provider_id = $proveedor->id;
        $articulo->save();
        $articulo->providers()->attach($proveedor->id, ['cost' => 500]);

        $suggestion = $this->crear_suggestion();

        (new GeneratePurchaseSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();

        // Precondiciones: la primera corrida tiene que haber dejado plata de
        // verdad, si no la aserción de abajo no prueba nada.
        $this->assertEquals('terminado', $suggestion->status);
        $this->assertGreaterThan(0, (float) $suggestion->total_estimado, 'Precondición: la primera corrida tiene que dejar un total > 0.');
        $this->assertNotNull($suggestion->contexto_financiero, 'Precondición: la primera corrida tiene que dejar contexto financiero.');

        // El comercio se queda sin catálogo: la segunda corrida no tiene
        // NINGÚN artículo (chunk_count = 0). Delete crudo: no hay FK física
        // que lo impida (regla del schema de esta misión).
        DB::table('articles')->where('id', $articulo->id)->delete();

        (new GeneratePurchaseSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();

        $this->assertEquals('terminado', $suggestion->status);
        $this->assertEquals(
            0,
            PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion->id)->count(),
            'Precondición: la segunda corrida no puede haber insertado ninguna línea.'
        );
        $this->assertNull(
            $suggestion->total_estimado,
            'Sin líneas nuevas, total_estimado no puede seguir mostrando la plata de la corrida anterior.'
        );
        $this->assertNull(
            $suggestion->contexto_financiero,
            'Sin líneas nuevas, contexto_financiero no puede seguir mostrando la plata de la corrida anterior.'
        );
    }

    // ------------------------------------------------------------------
    // A6 — destroy() respeta una corrida en curso
    // ------------------------------------------------------------------

    /**
     * @group sugerencias-compras
     * @test
     */
    public function destroy_sobre_una_pendiente_devuelve_422_y_no_borra_nada()
    {
        $suggestion = $this->crear_suggestion(['status' => 'pendiente']);
        $this->crear_linea($suggestion);

        $response = $this->deleteJson('api/purchase-suggestion/' . $suggestion->id);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'La sugerencia se está generando. Esperá a que termine para borrarla.',
        ]);

        $this->assertNotNull(PurchaseSuggestion::find($suggestion->id), 'No se puede borrar una sugerencia pendiente.');
        $this->assertEquals(
            1,
            PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion->id)->count(),
            'Las líneas tampoco se pueden borrar mientras la corrida sigue pendiente.'
        );
    }

    /**
     * Contracara: una sugerencia YA terminada sí se puede borrar (el guard
     * es específico de 'pendiente', no un candado general).
     *
     * @group sugerencias-compras
     * @test
     */
    public function destroy_sobre_una_terminada_borra_la_cabecera_y_sus_lineas()
    {
        $suggestion = $this->crear_suggestion(['status' => 'terminado']);
        $this->crear_linea($suggestion);

        $response = $this->deleteJson('api/purchase-suggestion/' . $suggestion->id);

        $response->assertStatus(204);
        $this->assertNull(PurchaseSuggestion::find($suggestion->id));
        $this->assertEquals(0, PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion->id)->count());
    }

    // ------------------------------------------------------------------
    // A7 — filtro solo_cambio_de_proveedor
    // ------------------------------------------------------------------

    /**
     * A7: el filtro tiene que exigir DOS proveedores conocidos y distintos
     * — el mismo criterio que el ícono del front
     * (TablaPriorizada.vue::es_cambio_de_proveedor). Antes usaba <=>
     * (comparación null-safe), que contaba como "cambio" cualquier línea con
     * provider_id = null.
     *
     * @group sugerencias-compras
     * @test
     */
    public function el_filtro_solo_cambio_de_proveedor_no_trae_lineas_sin_proveedor()
    {
        $suggestion = $this->crear_suggestion(['status' => 'terminado']);

        $titular = Provider::create(['name' => 'Titular P9 filtro', 'user_id' => $this->comercio->id]);
        $otro    = Provider::create(['name' => 'Otro P9 filtro', 'user_id' => $this->comercio->id]);

        $con_cambio_real = $this->crear_linea($suggestion, [
            'article_name'        => 'Cambio real P9',
            'provider_id'         => $otro->id,
            'provider_id_titular' => $titular->id,
        ]);
        $sin_proveedor = $this->crear_linea($suggestion, [
            'article_name'        => 'Sin proveedor P9',
            'provider_id'         => null,
            'provider_id_titular' => $titular->id,
        ]);
        $sin_cambio = $this->crear_linea($suggestion, [
            'article_name'        => 'Sin cambio P9',
            'provider_id'         => $titular->id,
            'provider_id_titular' => $titular->id,
        ]);

        $response = $this->getJson('api/purchase-suggestion/' . $suggestion->id . '/articles?solo_cambio_de_proveedor=1');

        $response->assertStatus(200);
        $ids = array_column($response->json('models.data'), 'purchase_suggestion_article_id');

        $this->assertContains($con_cambio_real->id, $ids, 'Un cambio real entre dos proveedores conocidos tiene que aparecer.');
        $this->assertNotContains($sin_proveedor->id, $ids, 'Una línea sin proveedor asignado NO es un cambio de proveedor real.');
        $this->assertNotContains($sin_cambio->id, $ids, 'El mismo proveedor de los dos lados no es un cambio.');
    }
}
