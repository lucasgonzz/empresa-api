<?php

namespace Tests\Feature\Vender;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\sale\OfflineUuidEsquemaHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\EmpresaTestCase;

/**
 * Misión clientes-sin-internet (26/10/2026): VENDER sin conexión con los clientes descargados.
 *
 * Protege tres cosas:
 *
 *  - `GET api/client/index/offline` (`ClientController::index_offline()`): los clientes VIVOS del
 *    comercio, con `withAll()`, paginados por `id` ASC (default 500, techo 1000), con
 *    `sincronizado_hasta` (hora del servidor tomada antes de consultar) y, con `updated_after`,
 *    solo los modificados después de la marca. Nunca los de otro comercio.
 *  - `GET api/client/index/offline-eliminados` (`ClientController::index_offline_eliminados()`):
 *    los clientes BORRADOS del comercio (`deleted_at >= updated_after` con marca), solo
 *    `id` y `deleted_at`.
 *  - `LimiteCreditoHelper::validar_venta_nueva()`: una venta a cuenta corriente por encima del
 *    límite sigue dando 422 en el POST online, y la SUBIDA de una venta offline (`offline_uuid` +
 *    `venta_offline`) se guarda igual.
 *
 * Mismo molde que `SincronizacionOfflineDeArticulosTest` (los `updated_at` / `deleted_at` se fijan
 * por DB para no depender del reloj) y que `LimiteCredito\Limite_de_credito_en_venta_Test` (payload
 * de venta, límite fijado y restaurado en tearDown(), saldos leídos con `getSaldo()` en vez de
 * asumir 0). Fixture de TestingFerreteriaSeeder; los clientes propios `zz` se crean adentro de la
 * transacción del test.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Clientes_offline_Test extends EmpresaTestCase
{
    /**
     * Snapshots de `limite_credito` originales por `credit_account_id`, para restaurarlos en
     * `tearDown()`. Red de seguridad extra, además de `DatabaseTransactions`.
     *
     * @var array<int,array{id:int, limite_credito:float|null}>
     */
    protected $limites_a_restaurar = [];

    protected function setUp(): void
    {
        parent::setUp();

        OfflineUuidEsquemaHelper::olvidar();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        foreach ($this->limites_a_restaurar as $item) {
            CreditAccount::where('id', $item['id'])->update(['limite_credito' => $item['limite_credito']]);
        }
        $this->limites_a_restaurar = [];

        OfflineUuidEsquemaHelper::olvidar();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Helpers del fixture
    // ---------------------------------------------------------------------

    /**
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Cliente propio con `updated_at` fijado a mano (y `deleted_at`, si se pide).
     *
     * @param  string               $nombre
     * @param  int                  $user_id
     * @param  \Carbon\Carbon       $updated_at
     * @param  \Carbon\Carbon|null  $deleted_at
     * @return \App\Models\Client
     */
    protected function cliente_con_fechas($nombre, $user_id, $updated_at, $deleted_at = null)
    {
        $client = Client::create([
            'name'    => $nombre,
            'user_id' => $user_id,
        ]);

        $cambios = ['updated_at' => $updated_at->format('Y-m-d H:i:s')];

        if (!is_null($deleted_at)) {
            $cambios['deleted_at'] = $deleted_at->format('Y-m-d H:i:s');
        }

        DB::table('clients')->where('id', $client->id)->update($cambios);

        return $client;
    }

    /**
     * Un comercio que NO es el del fixture: el primer otro usuario de la base, o un id que no usa
     * nadie (clients.user_id no tiene clave foránea que lo impida).
     *
     * @return int
     */
    protected function otro_comercio_id()
    {
        $propio = $this->usuario_de_testing()->id;

        $otro = User::where('id', '!=', $propio)->orderBy('id')->first();

        if (!is_null($otro)) {
            return (int) $otro->id;
        }

        return (int) User::max('id') + 1000;
    }

    /**
     * Recorre TODAS las páginas de un endpoint, como hace la SPA, y devuelve las filas.
     *
     * @param  string  $url
     * @param  array   $params
     * @return array
     */
    protected function todas_las_filas($url, $params)
    {
        $filas = [];
        $page  = 1;

        do {
            $res = $this->getJson($url.'?'.http_build_query(array_merge(['per_page' => 1000], $params, [
                'page' => $page,
            ])))->assertStatus(200);

            foreach ($res->json('models.data') as $fila) {
                $filas[] = $fila;
            }

            $last_page = (int) $res->json('models.last_page');
            $page++;

        } while ($page <= $last_page);

        return $filas;
    }

    /**
     * @param  array  $filas
     * @return array<int>
     */
    protected function ids($filas)
    {
        return array_map(function ($fila) {
            return (int) $fila['id'];
        }, $filas);
    }

    /**
     * @param  \App\Models\Client  $client
     * @param  int                 $moneda_id
     * @return \App\Models\CreditAccount
     */
    protected function credit_account($client, $moneda_id)
    {
        $cuenta = CreditAccount::where('model_name', 'client')
            ->where('model_id', $client->id)
            ->where('moneda_id', $moneda_id)
            ->first();

        if (is_null($cuenta)) {
            CreditAccountHelper::crear_credit_accounts('client', $client->id);

            $cuenta = CreditAccount::where('model_name', 'client')
                ->where('model_id', $client->id)
                ->where('moneda_id', $moneda_id)
                ->first();
        }

        if (is_null($cuenta)) {
            $this->fail('No se pudo resolver/crear la credit_account de moneda '.$moneda_id.' del cliente "'.$client->name.'".');
        }

        return $cuenta;
    }

    /**
     * Fija el `limite_credito` de la cuenta en pesos del cliente y guarda el original para
     * restaurarlo en tearDown().
     *
     * @param  \App\Models\Client  $client
     * @param  float|null          $limite
     * @return \App\Models\CreditAccount
     */
    protected function fijar_limite($client, $limite)
    {
        $cuenta = $this->credit_account($client, 1);

        if (!isset($this->limites_a_restaurar[$cuenta->id])) {
            $this->limites_a_restaurar[$cuenta->id] = [
                'id'             => $cuenta->id,
                'limite_credito' => $cuenta->limite_credito,
            ];
        }

        $cuenta->limite_credito = $limite;
        $cuenta->save();

        return $cuenta->fresh();
    }

    /**
     * Payload de POST api/sale a cuenta corriente con el artículo centinela, calcado del de
     * `Limite_de_credito_en_venta_Test::payload_venta()`.
     *
     * @param  int    $client_id
     * @param  float  $total
     * @param  array  $overrides
     * @return array
     */
    protected function payload_venta($client_id, $total, $overrides = [])
    {
        $articulo = Article::where('name', TestingFerreteriaSeeder::ARTICULO_CENTINELA)
            ->where('user_id', $this->usuario_de_testing()->id)
            ->first();

        if (is_null($articulo)) {
            $this->fail('No existe el articulo centinela "'.TestingFerreteriaSeeder::ARTICULO_CENTINELA.'" en el fixture.');
        }

        return array_merge([
            'client_id'                  => $client_id,
            'address_id'                 => null,
            'save_current_acount'        => 1,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'employee_id'                => null,
            'sub_total'                  => $total,
            'total'                      => $total,
            'terminada'                  => 1,
            'seller_id'                  => null,
            'cantidad_cuotas'            => null,
            'cuota_descuento'            => 0,
            'cuota_recargo'              => 0,
            'caja_id'                    => null,
            'afip_tipo_comprobante_id'   => null,
            'descuento'                  => null,
            'moneda_id'                  => 1,
            'discounts'                  => [],
            'surchages'                  => [],
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => $total,
                    'amount'       => 1,
                ],
            ],
        ], $overrides);
    }

    /**
     * Un uuid nuevo por llamada, con la forma de `crypto.randomUUID()`.
     *
     * @return string
     */
    protected function uuid()
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    /**
     * El CLIENTE_CC con un límite que la venta de 50.000 supera: saldo de partida + 100.
     *
     * @return \App\Models\Client
     */
    protected function cliente_al_tope()
    {
        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CC)
            ->where('user_id', $this->usuario_de_testing()->id)
            ->first();

        if (is_null($client)) {
            $this->fail('No existe el cliente "'.TestingFerreteriaSeeder::CLIENTE_CC.'" en el fixture de testing.');
        }

        $baseline = (float) CurrentAcountHelper::getSaldo($this->credit_account($client, 1)->id);

        $this->fijar_limite($client, round($baseline + 100, 2));

        return $client;
    }

    // ---------------------------------------------------------------------
    // client/index/offline
    // ---------------------------------------------------------------------

    /**
     * Sin marca trae TODOS los clientes vivos del comercio (recorriendo las páginas), ninguno de
     * otro comercio ni borrado, cada uno con las relaciones de `withAll()`, y `sincronizado_hasta`
     * con la hora del servidor de este request.
     *
     * @test
     */
    public function sin_marca_trae_todos_los_clientes_del_comercio_con_withall_y_la_marca()
    {
        $propio_id = $this->usuario_de_testing()->id;
        $ahora     = Carbon::now(config('app.timezone'));

        $vivo    = $this->cliente_con_fechas('zz Offline vivo', $propio_id, $ahora->copy()->subDays(30));
        $borrado = $this->cliente_con_fechas('zz Offline borrado', $propio_id, $ahora->copy()->subDays(30), $ahora->copy()->subDay());
        $ajeno   = $this->cliente_con_fechas('zz Offline de otro comercio', $this->otro_comercio_id(), $ahora->copy()->subDays(30));

        $antes = Carbon::now(config('app.timezone'))->subSeconds(2);

        $res = $this->getJson('api/client/index/offline')->assertStatus(200);

        $despues = Carbon::now(config('app.timezone'));

        // La marca: string `Y-m-d H:i:s`, la hora del servidor de este request (un segundo para atrás).
        $marca = $res->json('sincronizado_hasta');
        $this->assertIsString($marca);
        $this->assertMatchesRegularExpression('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $marca);
        $leida = Carbon::createFromFormat('Y-m-d H:i:s', $marca, config('app.timezone'));
        $this->assertTrue($leida->between($antes, $despues), 'La marca tiene que ser la hora del servidor de este request (quedó '.$marca.').');

        // `models` es un paginador.
        $this->assertNotNull($res->json('models.current_page'));
        $this->assertNotNull($res->json('models.last_page'));
        $this->assertIsArray($res->json('models.data'));
        $this->assertEquals(500, (int) $res->json('models.per_page'), 'El per_page por defecto es 500.');

        $filas = $this->todas_las_filas('api/client/index/offline', []);
        $ids   = $this->ids($filas);

        $esperados = Client::where('user_id', $propio_id)->orderBy('id')->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();

        $this->assertEquals($esperados, $ids, 'Sin marca tienen que venir exactamente los clientes vivos del comercio, ordenados por id.');
        $this->assertContains((int) $vivo->id, $ids);
        $this->assertNotContains((int) $borrado->id, $ids, 'Un cliente borrado no viene en el listado de vivos.');
        $this->assertNotContains((int) $ajeno->id, $ids, 'Nunca vienen clientes de otro comercio.');

        // Las relaciones de withAll() vienen en cada fila.
        $fila_vivo = null;
        foreach ($filas as $fila) {
            if ((int) $fila['id'] === (int) $vivo->id) {
                $fila_vivo = $fila;
            }
        }
        foreach (['iva_condition', 'price_type', 'location', 'comercio_city_user', 'buyer', 'credit_accounts'] as $relacion) {
            $this->assertArrayHasKey($relacion, $fila_vivo, 'Falta la relación "'.$relacion.'" de withAll().');
        }
    }

    /**
     * Con marca trae solo los clientes modificados DESPUÉS de la marca (estricto), y nunca los de
     * otro comercio aunque estén modificados después.
     *
     * @test
     */
    public function con_marca_trae_solo_los_modificados_despues()
    {
        $propio_id = $this->usuario_de_testing()->id;
        $marca     = Carbon::now(config('app.timezone'))->subMinutes(10);

        $nuevo       = $this->cliente_con_fechas('zz Offline tocado despues', $propio_id, $marca->copy()->addMinutes(5));
        $viejo       = $this->cliente_con_fechas('zz Offline tocado antes', $propio_id, $marca->copy()->subHour());
        $en_la_marca = $this->cliente_con_fechas('zz Offline tocado en la marca', $propio_id, $marca->copy());
        $ajeno       = $this->cliente_con_fechas('zz Offline ajeno tocado despues', $this->otro_comercio_id(), $marca->copy()->addMinutes(5));

        $filas = $this->todas_las_filas('api/client/index/offline', ['updated_after' => $marca->format('Y-m-d H:i:s')]);
        $ids   = $this->ids($filas);

        $this->assertContains((int) $nuevo->id, $ids, 'El cliente modificado después de la marca tiene que venir.');
        $this->assertNotContains((int) $viejo->id, $ids, 'El cliente modificado antes de la marca no viene.');
        $this->assertNotContains((int) $en_la_marca->id, $ids, 'El filtro es estricto (updated_at > marca), como en artículos.');
        $this->assertNotContains((int) $ajeno->id, $ids, 'Nunca vienen clientes de otro comercio.');

        // Lo que vino, leído de la base (no del JSON serializado): todo modificado después de la marca.
        foreach ($filas as $fila) {
            $updated_at = DB::table('clients')->where('id', $fila['id'])->value('updated_at');

            $this->assertGreaterThan(
                $marca->format('Y-m-d H:i:s'),
                $updated_at,
                'Con marca solo vienen clientes modificados después de ella (vino el '.$fila['id'].').'
            );
        }
    }

    /**
     * Paginación: `per_page` se respeta, el orden es por `id` ASC dentro y entre páginas, ninguna
     * fila se repite ni se pierde; `per_page` arriba del techo queda en 1000 y 0 cae al default.
     *
     * @test
     */
    public function pagina_por_id_ascendente_con_techo_de_mil()
    {
        $propio_id = $this->usuario_de_testing()->id;
        $hace_rato = Carbon::now(config('app.timezone'))->subDays(2);

        for ($i = 1; $i <= 3; $i++) {
            $this->cliente_con_fechas('zz Offline pagina '.$i, $propio_id, $hace_rato);
        }

        $total = Client::where('user_id', $propio_id)->count();

        $ids  = [];
        $page = 1;

        do {
            $res = $this->getJson('api/client/index/offline?per_page=2&page='.$page)->assertStatus(200);

            $this->assertEquals(2, (int) $res->json('models.per_page'));
            $this->assertLessThanOrEqual(2, count($res->json('models.data')));

            foreach ($res->json('models.data') as $fila) {
                $ids[] = (int) $fila['id'];
            }

            $last_page = (int) $res->json('models.last_page');
            $page++;

        } while ($page <= $last_page);

        $ordenados = $ids;
        sort($ordenados);

        $this->assertEquals($ordenados, $ids, 'Las filas tienen que venir por id ascendente, también entre páginas.');
        $this->assertCount($total, array_unique($ids), 'Recorriendo las páginas no se repite ni se pierde ningún cliente.');
        $this->assertCount($total, $ids);

        $this->getJson('api/client/index/offline?per_page=5000')
            ->assertStatus(200)
            ->assertJsonPath('models.per_page', 1000);

        $this->getJson('api/client/index/offline?per_page=0')
            ->assertStatus(200)
            ->assertJsonPath('models.per_page', 500);
    }

    // ---------------------------------------------------------------------
    // client/index/offline-eliminados
    // ---------------------------------------------------------------------

    /**
     * Trae el borrado y no el vivo; con marca, solo los borrados desde la marca; nunca los de otro
     * comercio. Cada fila trae solo `id` y `deleted_at`.
     *
     * @test
     */
    public function eliminados_trae_el_borrado_y_no_el_vivo()
    {
        $propio_id = $this->usuario_de_testing()->id;
        $marca     = Carbon::now(config('app.timezone'))->subMinutes(10);
        $antes     = $marca->copy()->subHour();
        $despues   = $marca->copy()->addMinutes(5);

        $vivo          = $this->cliente_con_fechas('zz Offline vivo tocado', $propio_id, $despues);
        $borrado       = $this->cliente_con_fechas('zz Offline borrado despues', $propio_id, $antes, $despues);
        $borrado_viejo = $this->cliente_con_fechas('zz Offline borrado antes', $propio_id, $antes, $antes);
        $ajeno         = $this->cliente_con_fechas('zz Offline ajeno borrado', $this->otro_comercio_id(), $antes, $despues);

        // Sin marca: todos los borrados del comercio.
        $filas = $this->todas_las_filas('api/client/index/offline-eliminados', []);
        $ids   = $this->ids($filas);

        $this->assertContains((int) $borrado->id, $ids);
        $this->assertContains((int) $borrado_viejo->id, $ids, 'Sin marca vienen todos los borrados.');
        $this->assertNotContains((int) $vivo->id, $ids, 'Un cliente vivo nunca viene en eliminados.');
        $this->assertNotContains((int) $ajeno->id, $ids, 'Nunca vienen clientes de otro comercio.');

        foreach ($filas as $fila) {
            $this->assertEquals(['deleted_at', 'id'], $this->claves_ordenadas($fila), 'Cada fila trae solo id y deleted_at.');
            $this->assertNotNull($fila['deleted_at']);
        }

        // Con marca: solo los borrados desde la marca.
        $ids_con_marca = $this->ids($this->todas_las_filas('api/client/index/offline-eliminados', [
            'updated_after' => $marca->format('Y-m-d H:i:s'),
        ]));

        $this->assertContains((int) $borrado->id, $ids_con_marca, 'El borrado después de la marca tiene que venir.');
        $this->assertNotContains((int) $borrado_viejo->id, $ids_con_marca, 'El borrado antes de la marca no viene.');
        $this->assertNotContains((int) $vivo->id, $ids_con_marca);
        $this->assertNotContains((int) $ajeno->id, $ids_con_marca);

        // Misma forma de respuesta que eliminados de artículos: solo `models`.
        $res = $this->getJson('api/client/index/offline-eliminados')->assertStatus(200);
        $this->assertEquals(['models'], array_keys($res->json()));
    }

    /**
     * @param  array  $fila
     * @return array
     */
    protected function claves_ordenadas($fila)
    {
        $claves = array_keys($fila);
        sort($claves);

        return $claves;
    }

    // ---------------------------------------------------------------------
    // Límite de crédito en la subida de una venta offline
    // ---------------------------------------------------------------------

    /**
     * POST online (sin `venta_offline`) por encima del límite: 422 como hoy, sin crear la venta.
     *
     * @test
     */
    public function venta_online_por_encima_del_limite_sigue_dando_422()
    {
        $client = $this->cliente_al_tope();

        $ventas_antes = Sale::where('client_id', $client->id)->count();

        // Con uuid y sin `venta_offline` (el POST online de VENDER manda uuid): el límite rige igual.
        $res = $this->postJson('api/sale', $this->payload_venta($client->id, 50000, ['offline_uuid' => $this->uuid()]));

        $res->assertStatus(422);
        $this->assertTrue($res->json('error_limite_credito'));
        $this->assertEquals($ventas_antes, Sale::where('client_id', $client->id)->count(), 'El rechazo no crea ninguna venta.');

        // Y con `venta_offline` pero sin uuid tampoco alcanza: no es una subida offline.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));

        $this->postJson('api/sale', $this->payload_venta($client->id, 50000, ['venta_offline' => 1]))
            ->assertStatus(422);
    }

    /**
     * La subida de una venta offline (`offline_uuid` + `venta_offline`) por encima del límite se
     * guarda: 201 con el modelo, el uuid guardado y su movimiento de cuenta corriente.
     *
     * @test
     */
    public function venta_offline_por_encima_del_limite_se_guarda()
    {
        $this->assertTrue(
            Schema::hasColumn('sales', 'offline_uuid'),
            'La base de testing no tiene sales.offline_uuid: correr la migración 2026_10_23_100000 contra la base del slot.'
        );

        $client = $this->cliente_al_tope();
        $uuid   = $this->uuid();

        $res = $this->postJson('api/sale', $this->payload_venta($client->id, 50000, [
            'offline_uuid'  => $uuid,
            'venta_offline' => 1,
        ]));

        $res->assertStatus(201);

        $sale_id = $res->json('model.id');
        $this->assertNotNull($sale_id, 'La respuesta tiene que traer la venta creada.');

        $sale = Sale::find($sale_id);
        $this->assertEquals($uuid, $sale->offline_uuid);
        $this->assertEquals((int) $client->id, (int) $sale->client_id);

        $movimiento = CurrentAcount::where('sale_id', $sale_id)->whereNull('haber')->first();
        $this->assertNotNull($movimiento, 'La venta offline entra a la cuenta corriente aunque supere el límite.');
        $this->assertEqualsWithDelta(50000, (float) $movimiento->debe, 0.01);
    }
}
