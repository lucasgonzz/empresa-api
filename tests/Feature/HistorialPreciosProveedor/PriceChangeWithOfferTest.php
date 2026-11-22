<?php

namespace Tests\Feature\HistorialPreciosProveedor;

use App\Models\Article;
use App\Models\PriceChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\EmpresaTestCase;

/**
 * Misión historial-precios-proveedor: el vínculo inverso. `GET api/price-change/{article_id}` (el
 * modal "Cambios de precios") trae, en cada cambio, las ofertas de proveedor que lo originaron
 * (`provider_offers`), para saltar de un historial al otro (CA11 del plan).
 *
 * Contrato (plan §10): cada `models[i]` suma `provider_offers` (SIEMPRE un array, vacío si no hay
 * ofertas vinculadas) con las claves de CLAVES_DE_LA_OFERTA; sin el permiso `article.cost` las ofertas
 * no traen `cost`. Todas las claves que el endpoint devuelve hoy se mantienen.
 *
 * 🔴 Rojo esperado ANTES de construir la misión: `models[*].provider_offers` no existe y las columnas
 * de uso (entre ellas `price_change_id`) tampoco. Las claves de hoy se verifican primero, contra el
 * sistema actual, para que el rojo sea sólo por lo nuevo.
 *
 * IMPORTANTE: PHP 7.4 estricto (ver las restricciones de la misión: nada de sintaxis de PHP 8).
 */
class PriceChangeWithOfferTest extends EmpresaTestCase
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

    /** Claves de cada oferta dentro de `provider_offers` (contrato api-spa del plan, §10). */
    const CLAVES_DE_LA_OFERTA = [
        'id', 'provider_id', 'provider_name', 'provider_code', 'cost', 'moneda_id', 'fecha', 'origen',
        'referencia_id', 'changed_article_provider', 'previous_article_provider_id',
        'previous_article_provider_name',
    ];

    /** Claves que el endpoint ya devolvía en cada cambio de precio y no pueden desaparecer. */
    const CLAVES_DE_HOY = [
        'cost', 'price', 'final_price', 'cambios_listas', 'origen', 'empleado_nombre',
        'precio_final_anterior', 'price_types',
    ];

    /** @var \App\Models\User Dueño del fixture de testing. */
    protected $owner;

    /** @var \App\Models\Provider Titular del Taladro ("Buenos Aires"). */
    protected $ba;

    /** @var \App\Models\Provider Proveedor que ofrece el Taladro ("Rosario"). */
    protected $rosario;

    /** @var \App\Models\Article */
    protected $taladro;

    /** @var \App\Models\PriceChange Cambio originado por la oferta de Rosario. */
    protected $evento_con_oferta;

    /** @var \App\Models\PriceChange Cambio de la ficha, sin ofertas. */
    protected $evento_sin_oferta;

    /**
     * Arma el Taladro con dos cambios de precio: uno de importación (el de la oferta) y uno de ficha.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->owner   = auth()->user();
        $this->ba      = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $this->rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $article = new Article();

        $article->user_id       = $this->owner->id;
        $article->name          = 'Taladro historial cambios';
        $article->bar_code      = '8804001';
        $article->provider_id   = $this->ba->id;
        $article->provider_code = 'BA-1';
        $article->cost          = 1200;
        $article->iva_id        = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA)->iva_id;
        $article->status        = 'active';
        $article->save();

        $this->taladro = $article->fresh();

        $this->evento_con_oferta = PriceChange::create([
            'article_id'  => $this->taladro->id,
            'cost'        => 1200,
            'price'       => null,
            'final_price' => 1560,
            'employee_id' => $this->owner->id,
            'origen'      => 'importacion',
        ]);

        $this->evento_sin_oferta = PriceChange::create([
            'article_id'  => $this->taladro->id,
            'cost'        => 1300,
            'price'       => null,
            'final_price' => 1690,
            'employee_id' => $this->owner->id,
            'origen'      => 'ficha',
        ]);
    }

    /**
     * CA11: el cambio de la importación trae la oferta de Rosario que lo originó (con todas las claves
     * del contrato); el cambio de la ficha trae `provider_offers: []`.
     *
     * @return void
     */
    public function test_price_change_lists_linked_offer()
    {
        $respuesta = $this->getJson('api/price-change/' . $this->taladro->id);

        $respuesta->assertStatus(200);

        $por_id = $this->modelos_por_id($respuesta->json('models'));

        $this->assertCount(2, $por_id);

        foreach ($por_id as $model) {
            $this->assertArrayHasKey('provider_offers', $model, 'Cada cambio trae provider_offers (siempre un array).');
            $this->assertIsArray($model['provider_offers']);
        }

        $this->assertSame([], $por_id[$this->evento_sin_oferta->id]['provider_offers']);

        $this->assert_columnas_de_uso();

        $oferta_id = (int) DB::table('provider_price_offers')->insertGetId([
            'user_id'                      => $this->owner->id,
            'article_id'                   => $this->taladro->id,
            'provider_id'                  => $this->rosario->id,
            'provider_code'                => 'R-77',
            'cost'                         => 1200,
            'moneda_id'                    => 1,
            'origen'                       => 'importacion',
            'fecha'                        => Carbon::today()->toDateString(),
            'referencia_id'                => 31,
            'updated_article_cost'         => 1,
            'previous_article_cost'        => 1000,
            'price_change_id'              => $this->evento_con_oferta->id,
            'changed_article_provider'     => 0,
            'previous_article_provider_id' => $this->ba->id,
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);

        $por_id = $this->modelos_por_id($this->getJson('api/price-change/' . $this->taladro->id)->assertStatus(200)->json('models'));

        $ofertas = $por_id[$this->evento_con_oferta->id]['provider_offers'];

        $this->assertCount(1, $ofertas);

        foreach (self::CLAVES_DE_LA_OFERTA as $clave) {
            $this->assertArrayHasKey($clave, $ofertas[0], 'Falta la clave "' . $clave . '" en provider_offers.');
        }

        $this->assertSame($oferta_id, $ofertas[0]['id']);
        $this->assertSame($this->rosario->id, $ofertas[0]['provider_id']);
        $this->assertSame(TestingFerreteriaSeeder::PROVIDER_OTRO, $ofertas[0]['provider_name']);
        $this->assertSame('R-77', $ofertas[0]['provider_code']);
        $this->assertEquals(1200, $ofertas[0]['cost']);
        $this->assertSame('importacion', $ofertas[0]['origen']);
        $this->assertSame(31, $ofertas[0]['referencia_id']);
        $this->assertSame(0, (int) $ofertas[0]['changed_article_provider']);
        $this->assertSame($this->ba->id, $ofertas[0]['previous_article_provider_id']);
        $this->assertSame(TestingFerreteriaSeeder::PROVIDER_BSAS, $ofertas[0]['previous_article_provider_name']);

        $this->assertSame([], $por_id[$this->evento_sin_oferta->id]['provider_offers'], 'El cambio sin oferta sigue vacío.');
    }

    /**
     * CA11: las claves de hoy no cambian, y un empleado sin `article.cost` no ve el costo ni en el
     * cambio (como hoy) ni en las ofertas vinculadas.
     *
     * @return void
     */
    public function test_existing_keys_unchanged_and_costs_hidden_without_permission()
    {
        // Dueño: las claves de hoy siguen todas, con la clave nueva además.
        $models = $this->getJson('api/price-change/' . $this->taladro->id)->assertStatus(200)->json('models');

        foreach ($models as $model) {
            foreach (self::CLAVES_DE_HOY as $clave) {
                $this->assertArrayHasKey($clave, $model, 'Se perdió la clave "' . $clave . '" que el endpoint devuelve hoy.');
            }

            $this->assertArrayHasKey('provider_offers', $model, 'Falta la clave nueva provider_offers.');
        }

        $this->assert_columnas_de_uso();

        DB::table('provider_price_offers')->insert([
            'user_id'                      => $this->owner->id,
            'article_id'                   => $this->taladro->id,
            'provider_id'                  => $this->rosario->id,
            'provider_code'                => 'R-77',
            'cost'                         => 1200,
            'moneda_id'                    => 1,
            'origen'                       => 'importacion',
            'fecha'                        => Carbon::today()->toDateString(),
            'updated_article_cost'         => 1,
            'previous_article_cost'        => 1000,
            'price_change_id'              => $this->evento_con_oferta->id,
            'changed_article_provider'     => 0,
            'previous_article_provider_id' => $this->ba->id,
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);

        $empleado = User::create([
            'name'         => 'Empleado sin costos cambios',
            'email'        => uniqid('empleado_') . '@test.local',
            'owner_id'     => $this->owner->id,
            'admin_access' => 0,
            'password'     => Hash::make(uniqid()),
        ]);

        $this->actingAs($empleado, 'web');

        $por_id = $this->modelos_por_id($this->getJson('api/price-change/' . $this->taladro->id)->assertStatus(200)->json('models'));

        $this->assertArrayNotHasKey('cost', $por_id[$this->evento_con_oferta->id], 'Hoy el empleado sin permiso no ve el costo del cambio.');

        $ofertas = $por_id[$this->evento_con_oferta->id]['provider_offers'];

        $this->assertCount(1, $ofertas, 'El empleado igual ve que hubo una oferta vinculada.');
        $this->assertArrayNotHasKey('cost', $ofertas[0], 'Pero sin su costo.');
        $this->assertArrayNotHasKey('previous_article_cost', $ofertas[0]);
        $this->assertSame('R-77', $ofertas[0]['provider_code']);
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
     * Indexa los cambios de la respuesta por su id.
     *
     * @param  array $models
     * @return array [price_change_id => model]
     */
    protected function modelos_por_id(array $models)
    {
        $por_id = [];

        foreach ($models as $model) {
            $por_id[(int) $model['id']] = $model;
        }

        return $por_id;
    }
}
