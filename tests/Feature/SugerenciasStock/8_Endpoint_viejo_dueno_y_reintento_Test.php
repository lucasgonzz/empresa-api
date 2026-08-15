<?php

namespace Tests\Feature\SugerenciasStock;

use App\Models\Address;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión sugerencias-inteligentes-stock — arreglos post-chequeo.
 *
 * Cubre lo que los revisores marcaron sin test: el contrato del endpoint viejo
 * (clave name nueva SIN perder article, techo de 2000 filas con aviso de
 * truncado), el candado sobre sugerencias en curso (update/destroy → 422), el
 * filtro de dueño de todos los métodos por id (id ajeno → 404) y el reintento
 * del resumen IA desde la vista.
 */
class Endpoint_viejo_dueno_y_reintento_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el flujo nuevo (el reintento vive ahí). */
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

        // Sin credencial de Anthropic nada sale a la red por accidente; los
        // tests del reintento setean su clave fake y su Http::fake() propios.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio sugerencias arreglos',
            'email'    => 'sugerencias-arreglos-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->origen = Address::create([
            'street'  => 'Deposito origen arreglos',
            'user_id' => $this->comercio->id,
        ]);

        $this->destino = Address::create([
            'street'  => 'Sucursal destino arreglos',
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
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Sugerencias inteligentes de stock',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Sugerencia del comercio del test.
     *
     * @param string $status
     * @param int|null $user_id Dueño distinto para los casos de id ajeno
     * @return StockSuggestion
     */
    protected function sugerencia($status = 'terminado', $user_id = null)
    {
        return StockSuggestion::create([
            'modo'          => 'minimo',
            'origen'        => 'absoluto',
            'limite_origen' => 'minimo',
            'status'        => $status,
            'user_id'       => $user_id ? $user_id : $this->comercio->id,
        ]);
    }

    /**
     * Línea directa de la sugerencia (sin correr el pipeline).
     *
     * @param StockSuggestion $suggestion
     * @param array $extra
     * @return StockSuggestionArticle
     */
    protected function linea($suggestion, array $extra = [])
    {
        $article = Article::create([
            'name'    => isset($extra['article_name']) ? $extra['article_name'] : 'Articulo linea arreglos',
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
     * El endpoint viejo (POST stock-suggestion-article, el del modal sin la
     * extensión) manda la clave name con el nombre real Y conserva article:
     * name era el bug histórico de la columna vacía, article es lo que algún
     * consumidor viejo pudo haber quedado leyendo.
     *
     * @group sugerencias-stock
     * @test
     */
    public function el_endpoint_viejo_trae_name_con_nombre_y_conserva_article()
    {
        $suggestion = $this->sugerencia();
        $this->linea($suggestion, ['article_name' => 'Martillo del modal viejo']);
        $this->linea($suggestion, ['article_name' => 'Pinza del modal viejo']);

        $response = $this->postJson('api/stock-suggestion-article', [
            'stock_suggestion_id' => $suggestion->id,
            'modo_agrupacion'     => 'origen',
            'address_id'          => $this->origen->id,
        ]);

        $response->assertStatus(200);
        $this->assertFalse(
            $response->json('truncado'),
            'Con dos líneas no hay truncado posible.'
        );

        $models = $response->json('models');
        $this->assertCount(2, $models);

        foreach ($models as $model) {
            $this->assertArrayHasKey('name', $model, 'El contrato viejo tiene que sumar la clave name.');
            $this->assertNotEquals('', $model['name'], 'La columna Nombre del modal quedaba vacía sin esta clave.');
            $this->assertArrayHasKey('article', $model, 'La clave article se conserva: nadie que la lea puede romperse.');
            $this->assertEquals($model['article'], $model['name']);
        }
    }

    /**
     * El techo del ->get() histórico: más de 2000 líneas devuelven exactamente
     * 2000 con truncado=true, en vez de intentar serializar la tabla entera.
     *
     * @group sugerencias-stock
     * @test
     */
    public function el_endpoint_viejo_trunca_en_2000_filas_y_lo_avisa()
    {
        $suggestion = $this->sugerencia();

        $article = Article::create([
            'name'    => 'Articulo repetido del truncado',
            'user_id' => $this->comercio->id,
        ]);

        // 2001 líneas por insert() en lotes (el pipeline real también bulk-inserta).
        $ahora = now();
        $filas = [];
        for ($i = 0; $i < 2001; $i++) {
            $filas[] = [
                'stock_suggestion_id' => $suggestion->id,
                'article_id'          => $article->id,
                'from_address_id'     => $this->origen->id,
                'to_address_id'       => $this->destino->id,
                'suggested_amount'    => 1,
                'created_at'          => $ahora,
                'updated_at'          => $ahora,
            ];
        }
        foreach (array_chunk($filas, 500) as $lote) {
            StockSuggestionArticle::insert($lote);
        }

        $response = $this->postJson('api/stock-suggestion-article', [
            'stock_suggestion_id' => $suggestion->id,
            'modo_agrupacion'     => 'origen',
            'address_id'          => $this->origen->id,
        ]);

        $response->assertStatus(200);
        $this->assertTrue(
            $response->json('truncado'),
            'Con 2001 líneas el endpoint tiene que avisar que truncó.'
        );
        $this->assertCount(2000, $response->json('models'));
    }

    /**
     * Una sugerencia en curso no se edita ni se borra: editar encolaría una
     * segunda corrida pisándose con la primera, y borrar le saca el modelo de
     * abajo a los chunks que la están escribiendo.
     *
     * @group sugerencias-stock
     * @test
     */
    public function una_sugerencia_en_curso_no_se_edita_ni_se_borra()
    {
        $pendiente = $this->sugerencia('pendiente');

        $response = $this->putJson('api/stock-suggestion/' . $pendiente->id, [
            'modo'          => 'maximo',
            'origen'        => 'relativo',
            'limite_origen' => 'minimo',
        ]);
        $response->assertStatus(422);

        $response = $this->deleteJson('api/stock-suggestion/' . $pendiente->id);
        $response->assertStatus(422);

        $pendiente->refresh();
        $this->assertEquals('pendiente', $pendiente->status);
        $this->assertEquals('minimo', $pendiente->modo, 'El 422 no puede haber guardado los parámetros nuevos.');
    }

    /**
     * Filtro de dueño: show/update/destroy/create_deposit_movement con una
     * sugerencia de OTRO comercio devuelven 404 y no la tocan. El update
     * reprocesador agrandó el radio de daño de un id ajeno: sin este filtro
     * borraba y recalculaba la sugerencia de otro.
     *
     * @group sugerencias-stock
     * @test
     */
    public function los_metodos_por_id_devuelven_404_para_una_sugerencia_ajena()
    {
        $otro = User::create([
            'name'     => 'Otro comercio ajeno',
            'email'    => 'sugerencias-ajeno-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $ajena = $this->sugerencia('terminado', $otro->id);
        $linea_ajena = StockSuggestionArticle::create([
            'stock_suggestion_id' => $ajena->id,
            'article_id'          => 1,
            'suggested_amount'    => 3,
        ]);

        $this->getJson('api/stock-suggestion/' . $ajena->id)->assertStatus(404);

        $this->putJson('api/stock-suggestion/' . $ajena->id, [
            'modo'          => 'maximo',
            'origen'        => 'relativo',
            'limite_origen' => 'minimo',
        ])->assertStatus(404);

        $this->deleteJson('api/stock-suggestion/' . $ajena->id)->assertStatus(404);

        $this->postJson('api/stock-suggestion/' . $ajena->id . '/create-deposit-movement', [
            'stock_suggestion_article_ids' => [$linea_ajena->id],
        ])->assertStatus(404);

        // Nada de lo anterior puede haber tocado la sugerencia ajena.
        $ajena->refresh();
        $this->assertEquals('terminado', $ajena->status);
        $this->assertEquals('minimo', $ajena->modo);
        $this->assertNotNull(StockSuggestionArticle::find($linea_ajena->id));
    }

    /**
     * Reintento del resumen: sobre una terminada, con credenciales, el POST
     * resetea el estado, despacha el job (sync en tests) y el resumen queda
     * listo con el texto nuevo.
     *
     * @group sugerencias-stock
     * @test
     */
    public function el_reintento_del_resumen_lo_vuelve_a_pedir_y_queda_listo()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $suggestion = $this->sugerencia('terminado');
        $suggestion->resumen_ia_estado = 'error';
        $suggestion->resumen_ia_error  = 'Overloaded';
        $suggestion->save();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Segundo intento: mové primero lo urgente al depósito de destino.'],
                ],
            ], 200),
        ]);

        // La cola de phpunit es sync: el dispatch corre el job acá mismo contra el fake.
        $response = $this->postJson('api/stock-suggestion/' . $suggestion->id . '/resumen');
        $response->assertStatus(200);

        $suggestion->refresh();

        $this->assertEquals('listo', $suggestion->resumen_ia_estado);
        $this->assertEquals(
            'Segundo intento: mové primero lo urgente al depósito de destino.',
            $suggestion->resumen_ia
        );
        $this->assertNull($suggestion->resumen_ia_error, 'El error del intento anterior no puede sobrevivir al reintento exitoso.');
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function el_reintento_sobre_una_no_terminada_devuelve_422()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $pendiente = $this->sugerencia('pendiente');

        $this->postJson('api/stock-suggestion/' . $pendiente->id . '/resumen')->assertStatus(422);

        $pendiente->refresh();
        $this->assertNull($pendiente->resumen_ia_estado, 'El 422 no puede haber dejado el resumen en pendiente.');
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function el_reintento_sin_credenciales_devuelve_422()
    {
        $this->dar_extension();
        // La clave quedó null desde setUp: no hay IA contratada.

        $terminada = $this->sugerencia('terminado');

        $this->postJson('api/stock-suggestion/' . $terminada->id . '/resumen')->assertStatus(422);

        $terminada->refresh();
        $this->assertNull(
            $terminada->resumen_ia_estado,
            'Sin credenciales no puede quedar un pendiente eterno que la vista pollee sin fin.'
        );
    }

    /**
     * El guard del PUT de sucursales exige clave CON valor: un payload que
     * traiga es_deposito_origen en null (un cliente API viejo reenviando el
     * modelo entero) no puede des-designar el depósito en silencio.
     *
     * @group sugerencias-stock
     * @test
     */
    public function el_put_de_sucursal_con_null_no_desdesigna_el_deposito()
    {
        $this->origen->es_deposito_origen = true;
        $this->origen->save();

        $payload_base = [
            'street'        => $this->origen->street,
            'street_number' => $this->origen->street_number,
            'city'          => $this->origen->city,
            'province'      => $this->origen->province,
        ];

        // Clave en null: la designación queda como estaba.
        $this->putJson('api/address/' . $this->origen->id, $payload_base + [
            'es_deposito_origen' => null,
        ])->assertStatus(200);
        $this->assertTrue((bool) $this->origen->fresh()->es_deposito_origen);

        // Clave ausente (ABM sin la extensión): tampoco cambia.
        $this->putJson('api/address/' . $this->origen->id, $payload_base)->assertStatus(200);
        $this->assertTrue((bool) $this->origen->fresh()->es_deposito_origen);

        // Clave con valor explícito: sí cambia, en los dos sentidos.
        $this->putJson('api/address/' . $this->origen->id, $payload_base + [
            'es_deposito_origen' => false,
        ])->assertStatus(200);
        $this->assertFalse((bool) $this->origen->fresh()->es_deposito_origen);

        $this->putJson('api/address/' . $this->origen->id, $payload_base + [
            'es_deposito_origen' => true,
        ])->assertStatus(200);
        $this->assertTrue((bool) $this->origen->fresh()->es_deposito_origen);
    }
}
