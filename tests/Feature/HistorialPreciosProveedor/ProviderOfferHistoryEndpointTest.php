<?php

namespace Tests\Feature\HistorialPreciosProveedor;

use App\Models\Article;
use App\Models\PriceChange;
use App\Models\ProviderOrder;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\EmpresaTestCase;

/**
 * Misión historial-precios-proveedor: `GET api/provider-price-offer/article/{article_id}`, el
 * historial de precios por proveedor de un artículo (CA10 del plan).
 *
 * Contrato (plan §10): `200 {models: [...], can_see_cost: bool}`, filas por `fecha DESC, id DESC`
 * con las claves de la lista CLAVES_DE_LA_OFERTA, 404 si el artículo es de otro dueño y, sin el
 * permiso `article.cost`, sin `cost` ni `previous_article_cost` (y `can_see_cost = false`). La
 * cantidad de consultas no depende de la cantidad de filas.
 *
 * Las filas se insertan directo en `provider_price_offers` (incluidas las columnas de uso de la
 * migración de la misión) para medir sólo el endpoint, sin depender de la importación.
 *
 * 🔴 Rojo esperado ANTES de construir la misión: el endpoint no existe (404 de ruta) y las columnas de
 * uso tampoco. Los tests entran por el helper de inserción, que falla con un mensaje claro si la
 * migración falta, y por una primera aserción 200 sobre el artículo propio, para que el 404 de
 * "artículo ajeno" no pase de casualidad contra una ruta inexistente.
 *
 * IMPORTANTE: PHP 7.4 estricto (ver las restricciones de la misión: nada de sintaxis de PHP 8).
 */
class ProviderOfferHistoryEndpointTest extends EmpresaTestCase
{
    /** Columnas que agrega la migración de la misión a provider_price_offers. */
    const COLUMNAS_DE_USO = [
        'updated_article_cost',
        'previous_article_cost',
        'price_change_id',
        'changed_article_provider',
        'previous_article_provider_id',
        'last_seen_at',
        'last_seen_reference_id',
    ];

    /** Claves que trae cada fila del historial (contrato api-spa del plan, §10). */
    const CLAVES_DE_LA_OFERTA = [
        'id', 'provider_id', 'provider_name', 'provider_code', 'cost', 'moneda_id', 'origen', 'fecha',
        'referencia_id', 'reference_label', 'last_seen_at', 'last_seen_reference_id',
        'updated_article_cost', 'previous_article_cost', 'price_change_id', 'price_change_created_at',
        'changed_article_provider', 'previous_article_provider_id', 'previous_article_provider_name',
    ];

    /** @var \App\Models\User Dueño del fixture de testing. */
    protected $owner;

    /** @var \App\Models\Provider Titular del Taladro ("Buenos Aires"). */
    protected $ba;

    /** @var \App\Models\Provider Proveedor que ofrece el Taladro ("Rosario"). */
    protected $rosario;

    /** @var \App\Models\Article */
    protected $taladro;

    /**
     * Arma el Taladro (titular BA) sobre el fixture de testing.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->owner   = auth()->user();
        $this->ba      = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $this->rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);
        $this->taladro = $this->crear_articulo($this->owner->id, 'Taladro historial endpoint', '8803001');
    }

    /**
     * CA10 (orden y contenido): el dueño recibe el historial ordenado por fecha y luego por id,
     * ambos descendentes, con todas las claves del contrato y los nombres resueltos.
     *
     * @return void
     */
    public function test_owner_gets_ordered_history()
    {
        $this->assert_columnas_de_uso();

        $orden = ProviderOrder::create([
            'num'                      => 4321,
            'provider_id'              => $this->rosario->id,
            'provider_order_status_id' => 1,
            'update_stock'             => 0,
            'update_prices'            => 0,
            'generate_current_acount'  => 0,
            'precios_incluyen_iva'     => false,
            'moneda_id'                => 1,
            'user_id'                  => $this->owner->id,
        ]);

        $evento = PriceChange::create([
            'article_id'  => $this->taladro->id,
            'cost'        => 1200,
            'price'       => null,
            'final_price' => 1560,
            'employee_id' => $this->owner->id,
            'origen'      => 'importacion',
        ]);

        // Registro anterior a la misión (sin dato de uso): el más viejo.
        $vieja = $this->insertar_oferta($this->taladro->id, [
            'provider_id'   => $this->ba->id,
            'provider_code' => 'BA-1',
            'cost'          => 1000,
            'fecha'         => Carbon::today()->subDays(20)->toDateString(),
        ]);

        // Importación de Rosario que actualizó el costo del Taladro y quedó vinculada al evento.
        $usada = $this->insertar_oferta($this->taladro->id, [
            'provider_id'                  => $this->rosario->id,
            'provider_code'                => 'R-77',
            'cost'                         => 1200,
            'fecha'                        => Carbon::today()->subDays(5)->toDateString(),
            'referencia_id'                => 31,
            'updated_article_cost'         => 1,
            'previous_article_cost'        => 1000,
            'price_change_id'              => $evento->id,
            'changed_article_provider'     => 0,
            'previous_article_provider_id' => $this->ba->id,
            'last_seen_at'                 => Carbon::now()->subDay()->toDateTimeString(),
            'last_seen_reference_id'       => 32,
        ]);

        // Compra de BA el MISMO día que la anterior, cargada después: va primero (id DESC).
        $compra = $this->insertar_oferta($this->taladro->id, [
            'provider_id'                  => $this->ba->id,
            'provider_code'                => 'BA-1',
            'cost'                         => 1100,
            'origen'                       => 'compra',
            'fecha'                        => Carbon::today()->subDays(5)->toDateString(),
            'referencia_id'                => $orden->id,
            'updated_article_cost'         => 0,
            'changed_article_provider'     => 0,
            'previous_article_provider_id' => $this->ba->id,
        ]);

        $respuesta = $this->getJson('api/provider-price-offer/article/' . $this->taladro->id);

        $respuesta->assertStatus(200);

        $this->assertTrue($respuesta->json('can_see_cost'));

        $models = $respuesta->json('models');

        $this->assertSame([$compra, $usada, $vieja], array_column($models, 'id'), 'Orden: fecha DESC y, a igual fecha, id DESC.');

        foreach ($models as $model) {
            foreach (self::CLAVES_DE_LA_OFERTA as $clave) {
                $this->assertArrayHasKey($clave, $model, 'Falta la clave "' . $clave . '" en la fila del historial.');
            }
        }

        $fila_usada = $models[1];

        $this->assertSame($this->rosario->id, $fila_usada['provider_id']);
        $this->assertSame(TestingFerreteriaSeeder::PROVIDER_OTRO, $fila_usada['provider_name']);
        $this->assertSame('R-77', $fila_usada['provider_code']);
        $this->assertEquals(1200, $fila_usada['cost']);
        $this->assertSame('importacion', $fila_usada['origen']);
        $this->assertSame(Carbon::today()->subDays(5)->toDateString(), $fila_usada['fecha']);
        $this->assertSame(1, (int) $fila_usada['updated_article_cost']);
        $this->assertEquals(1000, $fila_usada['previous_article_cost']);
        $this->assertSame($evento->id, $fila_usada['price_change_id']);
        $this->assertNotNull($fila_usada['price_change_created_at'], 'Trae la fecha del cambio de precio vinculado.');
        $this->assertSame(0, (int) $fila_usada['changed_article_provider']);
        $this->assertSame($this->ba->id, $fila_usada['previous_article_provider_id']);
        $this->assertSame(TestingFerreteriaSeeder::PROVIDER_BSAS, $fila_usada['previous_article_provider_name']);
        $this->assertSame(32, $fila_usada['last_seen_reference_id']);
        $this->assertNotNull($fila_usada['last_seen_at']);

        $fila_compra = $models[0];

        $this->assertSame('compra', $fila_compra['origen']);
        $this->assertNotNull($fila_compra['reference_label'], 'La compra se identifica con su número.');
        $this->assertStringContainsString('4321', (string) $fila_compra['reference_label']);

        $fila_vieja = $models[2];

        $this->assertNull($fila_vieja['updated_article_cost'], 'Registro anterior a la misión: "sin dato" (null), no 0.');
        $this->assertNull($fila_vieja['price_change_id']);
    }

    /**
     * CA10 (tenencia): un artículo de otro dueño da 404. Antes se comprueba que el propio da 200, para
     * que el 404 no sea el de una ruta que todavía no existe.
     *
     * @return void
     */
    public function test_other_owner_gets_404()
    {
        $this->getJson('api/provider-price-offer/article/' . $this->taladro->id)->assertStatus(200);

        $otro_dueno = User::create([
            'name'     => 'Otro dueño historial',
            'email'    => uniqid('otro_dueno_') . '@test.local',
            'password' => Hash::make(uniqid()),
        ]);

        $ajeno = $this->crear_articulo($otro_dueno->id, 'Taladro ajeno historial', '8803999');

        $this->getJson('api/provider-price-offer/article/' . $ajeno->id)->assertStatus(404);
    }

    /**
     * CA10 (permiso de costos): un empleado sin `article.cost` recibe 200, `can_see_cost = false` y
     * filas sin `cost` ni `previous_article_cost`.
     *
     * @return void
     */
    public function test_employee_without_cost_permission_gets_no_costs()
    {
        $this->assert_columnas_de_uso();

        $this->insertar_oferta($this->taladro->id, [
            'provider_id'                  => $this->rosario->id,
            'provider_code'                => 'R-77',
            'cost'                         => 1200,
            'updated_article_cost'         => 1,
            'previous_article_cost'        => 1000,
            'previous_article_provider_id' => $this->ba->id,
        ]);

        $empleado = User::create([
            'name'         => 'Empleado sin costos',
            'email'        => uniqid('empleado_') . '@test.local',
            'owner_id'     => $this->owner->id,
            'admin_access' => 0,
            'password'     => Hash::make(uniqid()),
        ]);

        $this->actingAs($empleado, 'web');

        $respuesta = $this->getJson('api/provider-price-offer/article/' . $this->taladro->id);

        $respuesta->assertStatus(200);

        $this->assertFalse($respuesta->json('can_see_cost'));

        $models = $respuesta->json('models');

        $this->assertNotEmpty($models);

        foreach ($models as $model) {
            $this->assertArrayNotHasKey('cost', $model);
            $this->assertArrayNotHasKey('previous_article_cost', $model);
            $this->assertArrayHasKey('provider_name', $model, 'El resto de los datos sí llega.');
        }
    }

    /**
     * CA10 (consultas): la cantidad de consultas no crece con la cantidad de filas (10 vs 50 filas).
     *
     * @return void
     */
    public function test_query_count_is_constant()
    {
        $this->assert_columnas_de_uso();

        $con_diez   = $this->taladro;
        $con_cincuenta = $this->crear_articulo($this->owner->id, 'Taladro cincuenta ofertas', '8803002');

        $this->cargar_ofertas($con_diez->id, 10);
        $this->cargar_ofertas($con_cincuenta->id, 50);

        // Calentamiento: la primera llamada puede traer consultas de arranque que no dependen de las filas.
        $this->getJson('api/provider-price-offer/article/' . $con_diez->id)->assertStatus(200);

        $consultas_con_diez = $this->contar_consultas('api/provider-price-offer/article/' . $con_diez->id, 10);
        $consultas_con_cincuenta = $this->contar_consultas('api/provider-price-offer/article/' . $con_cincuenta->id, 50);

        $this->assertSame(
            $consultas_con_diez,
            $consultas_con_cincuenta,
            'Las consultas no pueden depender de la cantidad de filas (nunca una por fila).'
        );
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    /**
     * Falla con un mensaje claro si la migración de la misión todavía no corrió.
     *
     * @return void
     */
    protected function assert_columnas_de_uso()
    {
        foreach (self::COLUMNAS_DE_USO as $columna) {
            $this->assertTrue(
                Schema::hasColumn('provider_price_offers', $columna),
                'Falta la columna provider_price_offers.' . $columna . ' (la migración de la misión no existe todavía).'
            );
        }
    }

    /**
     * Crea un artículo mínimo del dueño indicado, con proveedor BA.
     *
     * @param  int    $user_id
     * @param  string $name
     * @param  string $bar_code
     * @return \App\Models\Article
     */
    protected function crear_articulo($user_id, $name, $bar_code)
    {
        $article = new Article();

        $article->user_id       = $user_id;
        $article->name          = $name;
        $article->bar_code      = $bar_code;
        $article->provider_id   = $this->ba->id;
        $article->provider_code = 'BA-1';
        $article->cost          = 1000;
        $article->iva_id        = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA)->iva_id;
        $article->status        = 'active';
        $article->save();

        return $article->fresh();
    }

    /**
     * Inserta una oferta cruda y devuelve su id.
     *
     * @param  int   $article_id
     * @param  array $datos  overrides de las columnas
     * @return int
     */
    protected function insertar_oferta($article_id, array $datos)
    {
        return (int) DB::table('provider_price_offers')->insertGetId(array_merge([
            'user_id'       => $this->owner->id,
            'article_id'    => $article_id,
            'provider_id'   => $this->rosario->id,
            'provider_code' => 'R-77',
            'cost'          => 1200,
            'moneda_id'     => 1,
            'origen'        => 'importacion',
            'fecha'         => Carbon::today()->toDateString(),
            'referencia_id' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $datos));
    }

    /**
     * Carga $cantidad ofertas de un artículo, una por día hacia atrás, alternando proveedor.
     *
     * @param  int $article_id
     * @param  int $cantidad
     * @return void
     */
    protected function cargar_ofertas($article_id, $cantidad)
    {
        for ($i = 0; $i < $cantidad; $i++) {
            $this->insertar_oferta($article_id, [
                'provider_id'          => ($i % 2 === 0) ? $this->rosario->id : $this->ba->id,
                'cost'                 => 1000 + $i,
                'fecha'                => Carbon::today()->subDays($i)->toDateString(),
                'updated_article_cost' => $i % 2,
            ]);
        }
    }

    /**
     * Cuenta las consultas que dispara un GET y comprueba que devolvió la cantidad de filas esperada.
     *
     * @param  string $url
     * @param  int    $filas_esperadas
     * @return int
     */
    protected function contar_consultas($url, $filas_esperadas)
    {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $respuesta = $this->getJson($url);

        $consultas = count(DB::connection()->getQueryLog());

        DB::connection()->disableQueryLog();

        $respuesta->assertStatus(200);

        $this->assertCount($filas_esperadas, $respuesta->json('models'));

        return $consultas;
    }
}
