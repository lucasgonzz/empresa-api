<?php

namespace Tests\Feature\SugerenciasStock;

use App\Models\Address;
use App\Models\Article;
use App\Models\DepositMovement;
use App\Models\ExtencionEmpresa;
use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión sugerencias-inteligentes-stock — P6: el endpoint paginado y la
 * limpieza del controller.
 *
 * La aserción del 403 no es decorativa: es la única que prueba que el gate por
 * extensión existe de verdad (la extensión ai_excel_import quedó con rutas sin
 * gatear y nadie lo notó — acá eso no puede repetirse).
 */
class Endpoint_paginado_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el flujo nuevo. */
    const SLUG = 'sugerencias_inteligentes';

    /** @var User */
    protected $comercio;

    /** @var Address */
    protected $origen;

    /** @var Address */
    protected $destino;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // Sin credencial de Anthropic el job del resumen no se despacha (degradación D28).
        // Con la cola sync de phpunit.xml y la clave real del .env.testing, store() y el
        // reproceso de update() saldrían a la API de verdad en cada corrida.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio sugerencias P6',
            'email'    => 'sugerencias-p6-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->origen = Address::create([
            'street'  => 'Deposito origen P6',
            'user_id' => $this->comercio->id,
        ]);

        $this->destino = Address::create([
            'street'  => 'Sucursal destino P6',
            'user_id' => $this->comercio->id,
        ]);

        $this->actingAs($this->comercio, 'web');
    }

    /**
     * Asigna la extensión al comercio del test (creando la fila del catálogo
     * si la base del slot todavía no la tiene sembrada).
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            // forceCreate: el modelo no declara $fillable (mismo motivo que en
            // el test de personalizar_nombre_en_vender).
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Sugerencias inteligentes de stock',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        // El middleware lee la colección del user: se refresca para que el
        // attach recién hecho se vea en este mismo request de test.
        $this->comercio->load('extencions');
    }

    /**
     * Sugerencia terminada del comercio del test.
     *
     * @param string $status
     * @return StockSuggestion
     */
    protected function sugerencia($status = 'terminado')
    {
        return StockSuggestion::create([
            'modo'          => 'minimo',
            'origen'        => 'absoluto',
            'limite_origen' => 'minimo',
            'status'        => $status,
            'user_id'       => $this->comercio->id,
        ]);
    }

    /**
     * Línea directa de la sugerencia (sin correr el pipeline: acá se prueba el
     * endpoint, no el cálculo).
     *
     * @param StockSuggestion $suggestion
     * @param array $extra
     * @return StockSuggestionArticle
     */
    protected function linea($suggestion, array $extra = [])
    {
        $article = Article::create([
            'name'    => isset($extra['article_name']) ? $extra['article_name'] : 'Articulo linea P6',
            'user_id' => $this->comercio->id,
        ]);
        unset($extra['article_name']);

        return StockSuggestionArticle::create(array_merge([
            'stock_suggestion_id' => $suggestion->id,
            'article_id'          => $article->id,
            'from_address_id'     => $this->origen->id,
            'to_address_id'       => $this->destino->id,
            'suggested_amount'    => 5,
        ], $extra));
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function sin_la_extension_el_endpoint_devuelve_403()
    {
        $suggestion = $this->sugerencia();

        $response = $this->getJson('api/stock-suggestion/' . $suggestion->id . '/articles');

        $response->assertStatus(403);
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function con_la_extension_el_endpoint_responde_paginado()
    {
        $this->dar_extension();
        $suggestion = $this->sugerencia();
        $this->linea($suggestion);

        $response = $this->getJson('api/stock-suggestion/' . $suggestion->id . '/articles');

        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 50);
        $this->assertCount(1, $response->json('models.data'));
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function el_per_page_respeta_el_pedido_el_techo_y_el_default()
    {
        $this->dar_extension();
        $suggestion = $this->sugerencia();
        for ($i = 0; $i < 12; $i++) {
            $this->linea($suggestion, ['prioridad' => $i + 1]);
        }

        $base = 'api/stock-suggestion/' . $suggestion->id . '/articles';

        // Pedido explícito.
        $response = $this->getJson($base . '?per_page=10');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 10);
        $this->assertCount(10, $response->json('models.data'));

        // Techo.
        $response = $this->getJson($base . '?per_page=9999');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 500);

        // Inválidos caen al default.
        foreach (['0', '-5', 'no-es-un-numero'] as $valor_invalido) {
            $response = $this->getJson($base . '?per_page=' . $valor_invalido);
            $response->assertStatus(200);
            $response->assertJsonPath('models.per_page', 50);
        }
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function filtra_por_origen_y_por_destino()
    {
        $this->dar_extension();
        $suggestion = $this->sugerencia();

        $otra = Address::create([
            'street'  => 'Sucursal alternativa P6',
            'user_id' => $this->comercio->id,
        ]);

        $this->linea($suggestion, ['prioridad' => 1]);
        $this->linea($suggestion, ['prioridad' => 2, 'from_address_id' => $otra->id]);
        $this->linea($suggestion, ['prioridad' => 3, 'to_address_id' => $otra->id]);

        $base = 'api/stock-suggestion/' . $suggestion->id . '/articles';

        $response = $this->getJson($base . '?from_address_id=' . $otra->id);
        $response->assertStatus(200);
        $data = $response->json('models.data');
        $this->assertCount(1, $data);
        $this->assertEquals($otra->id, $data[0]['from_address_id']);

        $response = $this->getJson($base . '?to_address_id=' . $otra->id);
        $response->assertStatus(200);
        $data = $response->json('models.data');
        $this->assertCount(1, $data);
        $this->assertEquals($otra->id, $data[0]['to_address_id']);
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function ordena_por_prioridad_ascendente_con_nulls_al_final()
    {
        $this->dar_extension();
        $suggestion = $this->sugerencia();

        // Se insertan desordenadas a propósito; la null simula una línea de
        // una sugerencia anterior a la v2 (sin backfill).
        $this->linea($suggestion, ['prioridad' => 2]);
        $this->linea($suggestion, ['prioridad' => null]);
        $this->linea($suggestion, ['prioridad' => 1]);

        $response = $this->getJson('api/stock-suggestion/' . $suggestion->id . '/articles');

        $response->assertStatus(200);
        $prioridades = array_column($response->json('models.data'), 'prioridad');

        $this->assertEquals([1, 2, null], $prioridades);
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function cada_linea_trae_el_nombre_del_articulo_en_la_clave_name()
    {
        $this->dar_extension();
        $suggestion = $this->sugerencia();
        $this->linea($suggestion, ['article_name' => 'Martillo con nombre P6']);

        $response = $this->getJson('api/stock-suggestion/' . $suggestion->id . '/articles');

        $response->assertStatus(200);
        $data = $response->json('models.data');

        $this->assertArrayHasKey('name', $data[0], 'El contrato nuevo tiene que exponer la clave name.');
        $this->assertEquals(
            'Martillo con nombre P6',
            $data[0]['name'],
            'La clave name tiene que traer el nombre real del artículo (el bug histórico de la columna vacía).'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function destroy_borra_tambien_las_lineas()
    {
        $suggestion = $this->sugerencia();
        $this->linea($suggestion);
        $this->linea($suggestion);

        $this->assertEquals(2, StockSuggestionArticle::where('stock_suggestion_id', $suggestion->id)->count());

        $response = $this->deleteJson('api/stock-suggestion/' . $suggestion->id);

        $response->assertSuccessful();
        $this->assertNull(StockSuggestion::find($suggestion->id));
        $this->assertEquals(
            0,
            StockSuggestionArticle::where('stock_suggestion_id', $suggestion->id)->count(),
            'destroy() tiene que borrar las líneas: antes quedaban huérfanas engordando la tabla.'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function update_reprocesa_en_vez_de_dejar_lineas_viejas()
    {
        // Artículo real con déficit: el reproceso tiene que generar SU línea.
        $con_deficit = Article::create([
            'name'    => 'Articulo con deficit P6',
            'user_id' => $this->comercio->id,
        ]);
        $con_deficit->addresses()->attach($this->origen->id, [
            'amount' => 100, 'stock_min' => 10, 'stock_max' => 20,
        ]);
        $con_deficit->addresses()->attach($this->destino->id, [
            'amount' => 2, 'stock_min' => 10, 'stock_max' => 20,
        ]);

        $suggestion = $this->sugerencia('terminado');
        $suggestion->resumen_ia = 'Resumen viejo que tiene que desaparecer';
        $suggestion->resumen_ia_estado = 'listo';
        $suggestion->save();

        // Línea "vieja" que no corresponde a ningún cálculo real.
        $linea_vieja = $this->linea($suggestion, ['article_name' => 'Articulo fantasma P6']);

        $response = $this->putJson('api/stock-suggestion/' . $suggestion->id, [
            'modo'          => 'minimo',
            'origen'        => 'absoluto',
            'limite_origen' => 'minimo',
        ]);

        $response->assertStatus(200);

        $suggestion->refresh();

        // El catálogo del test es chico: el reproceso corre sync y termina acá mismo.
        $this->assertEquals('terminado', $suggestion->status);
        $this->assertNull($suggestion->resumen_ia, 'El resumen viejo no puede sobrevivir a un reproceso.');

        $this->assertNull(
            StockSuggestionArticle::find($linea_vieja->id),
            'update() tiene que borrar las líneas del cálculo anterior.'
        );

        $this->assertEquals(
            1,
            StockSuggestionArticle::where('stock_suggestion_id', $suggestion->id)
                ->where('article_id', $con_deficit->id)
                ->count(),
            'El reproceso tiene que generar la línea del déficit real.'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function create_deposit_movement_deja_el_employee_id()
    {
        $suggestion = $this->sugerencia();
        $linea = $this->linea($suggestion);

        $response = $this->postJson(
            'api/stock-suggestion/' . $suggestion->id . '/create-deposit-movement',
            ['stock_suggestion_article_ids' => [$linea->id]]
        );

        $response->assertStatus(201);

        $movimiento = DepositMovement::where('stock_suggestion_id', $suggestion->id)->first();

        $this->assertNotNull($movimiento);
        $this->assertEquals(
            $this->comercio->id,
            $movimiento->employee_id,
            'Sin employee_id, el empleado no ve en su listado el movimiento que él mismo generó.'
        );
    }
}
