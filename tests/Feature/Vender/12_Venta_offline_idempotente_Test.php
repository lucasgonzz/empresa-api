<?php

namespace Tests\Feature\Vender;

use App\Http\Controllers\Helpers\sale\OfflineUuidEsquemaHelper;
use App\Http\Controllers\Helpers\sale\VentaOfflineHelper;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Stock\AuditoriaStockTestCase;

/**
 * Misión ingreso-sin-internet (23/10/2026): el alta de venta (`POST api/sale`) es idempotente por
 * `offline_uuid`, y la subida de una venta guardada en el equipo conserva quién la cargó y a qué
 * hora.
 *
 * EL CASO REAL. Sin internet VENDER guarda la venta en IndexedDB y, al volver la red,
 * `offline/sync_sales.js` las sube una por una y borra del equipo cada una que el servidor aceptó.
 * Se midieron dos pérdidas: la guarda de 5 segundos (`SaleController::venta_ya_cread()`)
 * descartaba con un 200 vacío la segunda de dos ventas iguales subidas seguidas (la SPA la borraba:
 * venta perdida), y una respuesta cortada hacía que la SPA la subiera otra vez (venta duplicada,
 * stock y caja dos veces). Ver `VentaOfflineHelper`.
 *
 * QUÉ FIJA:
 *  - mismo uuid dos veces → una sola venta, la segunda respuesta trae la MISMA venta con el mismo
 *    201, y el stock se descuenta una vez; también si la venta se borró en el medio;
 *  - dos uuid distintos, mismo contenido, seguidos: CON `venta_offline` → dos ventas; SIN
 *    `venta_offline` (POST online) → la guarda de 5 segundos frena la segunda, como hoy;
 *  - sin uuid, la guarda de 5 segundos igual que hoy;
 *  - la subida de una venta que cargó el dueño la deja a nombre del dueño aunque suba un empleado;
 *  - la hora de la subida sale de `capturada_at` (y el día del `created_at` elegido si vino), y un
 *    `capturada_at` basura o del futuro se ignora;
 *  - la última red: el índice único rechaza el uuid repetido y el helper lo reconoce.
 *
 * Sobre el fixture de TestingFerreteriaSeeder con la forma de payload de VENDER que arma
 * `AuditoriaStockTestCase` (cliente de cuenta corriente, sucursal principal). Todo adentro de la
 * transacción del test, con artículos propios `zz`. El stock se lee de la base.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Venta_offline_idempotente_Test extends AuditoriaStockTestCase
{
    /** @var float Precio del renglón. */
    const PRECIO = 150;

    /** @var int Cantidad del renglón. */
    const CANTIDAD = 3;

    /** @var int Stock inicial del artículo propio. */
    const STOCK_INICIAL = 20;

    protected function setUp(): void
    {
        parent::setUp();

        OfflineUuidEsquemaHelper::olvidar();

        $this->assertTrue(
            Schema::hasColumn('sales', 'offline_uuid'),
            'La base de testing no tiene sales.offline_uuid: correr la migración 2026_10_23_100000 contra la base del slot.'
        );
    }

    protected function tearDown(): void
    {
        OfflineUuidEsquemaHelper::olvidar();

        parent::tearDown();
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
     * Payload de VENDER con un renglón del artículo y las claves extra que pida el test.
     *
     * @param  \App\Models\Article  $articulo
     * @param  array                $extra
     * @return array
     */
    protected function payload($articulo, $extra = [])
    {
        return $this->payload_venta([
            ['article' => $articulo, 'amount' => self::CANTIDAD, 'price' => self::PRECIO],
        ], $extra);
    }

    /**
     * Cantidad de ventas (borradas incluidas) del comercio que tienen el artículo.
     *
     * @param  \App\Models\Article  $articulo
     * @return int
     */
    protected function ventas_del_articulo($articulo)
    {
        return DB::table('article_sale')->where('article_id', $articulo->id)->distinct()->count('sale_id');
    }

    /**
     * El mismo uuid subido dos veces (la respuesta de la primera "se cortó"): una sola venta, la
     * segunda respuesta es la misma venta con el mismo 201 y la misma forma, y el stock bajó una vez.
     *
     * @test
     */
    public function el_mismo_uuid_dos_veces_deja_una_sola_venta_y_descuenta_el_stock_una_vez()
    {
        $articulo = $this->crear_articulo('zz Offline mismo uuid', ['stock' => self::STOCK_INICIAL]);

        $payload = $this->payload($articulo, ['offline_uuid' => $this->uuid(), 'venta_offline' => 1]);

        $primera = $this->postJson('api/sale', $payload)->assertStatus(201);

        $segunda = $this->postJson('api/sale', $payload)->assertStatus(201);

        $id = $primera->json('model.id');

        $this->assertNotNull($id, 'El alta tenía que devolver la venta en model.');
        $this->assertEquals($id, $segunda->json('model.id'), 'El reintento tenía que devolver LA MISMA venta.');
        $this->assertEquals($primera->json('model.num'), $segunda->json('model.num'));
        $this->assertEquals(array_keys($primera->json('model')), array_keys($segunda->json('model')), 'El reintento tiene que tener la misma forma que el alta.');

        $this->assertEquals(1, $this->ventas_del_articulo($articulo), 'Dos POST con el mismo uuid no pueden dejar dos ventas.');
        $this->assertEquals(self::STOCK_INICIAL - self::CANTIDAD, $this->stock($articulo), 'El stock se tenía que descontar UNA sola vez.');
        $this->assertEquals($payload['offline_uuid'], Sale::find($id)->offline_uuid, 'La venta tiene que guardar su uuid.');
    }

    /**
     * El POST ONLINE también manda uuid (sin `venta_offline`): un reintento con el mismo uuid —el
     * caso de "la respuesta no llegó y cayó al guardado en el equipo"— también devuelve la venta
     * existente, porque la búsqueda por uuid corre antes de la guarda de 5 segundos.
     *
     * @test
     */
    public function el_mismo_uuid_sin_venta_offline_tambien_devuelve_la_venta_existente()
    {
        $articulo = $this->crear_articulo('zz Offline mismo uuid online', ['stock' => self::STOCK_INICIAL]);

        $payload = $this->payload($articulo, ['offline_uuid' => $this->uuid()]);

        $id = $this->postJson('api/sale', $payload)->assertStatus(201)->json('model.id');

        $this->postJson('api/sale', $payload)->assertStatus(201)->assertJsonPath('model.id', $id);

        $this->assertEquals(1, $this->ventas_del_articulo($articulo));
        $this->assertEquals(self::STOCK_INICIAL - self::CANTIDAD, $this->stock($articulo));
    }

    /**
     * Una venta que ya subió y después se borró a mano NO se recrea porque el equipo la reintente:
     * se devuelve la borrada.
     *
     * @test
     */
    public function una_venta_subida_y_borrada_no_se_vuelve_a_crear()
    {
        $articulo = $this->crear_articulo('zz Offline uuid borrada', ['stock' => self::STOCK_INICIAL]);

        $payload = $this->payload($articulo, ['offline_uuid' => $this->uuid(), 'venta_offline' => 1]);

        $id = $this->postJson('api/sale', $payload)->assertStatus(201)->json('model.id');

        DB::table('sales')->where('id', $id)->update(['deleted_at' => Carbon::now()]);

        $this->postJson('api/sale', $payload)->assertStatus(201)->assertJsonPath('model.id', $id);

        $this->assertEquals(1, $this->ventas_del_articulo($articulo), 'Una venta borrada con ese uuid no se puede recrear.');
    }

    /**
     * Dos ventas offline IGUALES (mismo cliente, empleado y total) con uuid distintos subidas
     * seguidas: son dos ventas de verdad y quedan las dos. Hasta hoy la segunda se descartaba con
     * un 200 vacío y la SPA la borraba del equipo.
     *
     * @test
     */
    public function dos_uuid_distintos_con_venta_offline_dejan_dos_ventas()
    {
        $articulo = $this->crear_articulo('zz Offline dos uuid', ['stock' => self::STOCK_INICIAL]);

        $primera = $this->postJson('api/sale', $this->payload($articulo, ['offline_uuid' => $this->uuid(), 'venta_offline' => 1]))->assertStatus(201);

        $segunda = $this->postJson('api/sale', $this->payload($articulo, ['offline_uuid' => $this->uuid(), 'venta_offline' => true]))->assertStatus(201);

        $this->assertNotEquals($primera->json('model.id'), $segunda->json('model.id'));
        $this->assertEquals(2, $this->ventas_del_articulo($articulo), 'Las dos ventas offline iguales tenían que quedar guardadas.');
        $this->assertEquals(self::STOCK_INICIAL - 2 * self::CANTIDAD, $this->stock($articulo));
    }

    /**
     * Lo mismo SIN `venta_offline` (el POST online de VENDER, que manda uuid desde esta misión): un
     * doble clic genera dos uuid distintos para la misma venta, y la guarda de 5 segundos tiene que
     * seguir frenando la segunda con el 200 vacío de siempre.
     *
     * @test
     */
    public function dos_uuid_distintos_sin_venta_offline_la_guarda_de_cinco_segundos_frena_la_segunda()
    {
        $articulo = $this->crear_articulo('zz Online dos uuid', ['stock' => self::STOCK_INICIAL]);

        $this->postJson('api/sale', $this->payload($articulo, ['offline_uuid' => $this->uuid()]))->assertStatus(201);

        $segunda = $this->postJson('api/sale', $this->payload($articulo, ['offline_uuid' => $this->uuid()]));

        $segunda->assertStatus(200);
        $this->assertSame('', $segunda->getContent(), 'La guarda de 5 segundos contesta 200 con el cuerpo vacío, como hoy.');
        $this->assertEquals(1, $this->ventas_del_articulo($articulo));
        $this->assertEquals(self::STOCK_INICIAL - self::CANTIDAD, $this->stock($articulo));
    }

    /**
     * Sin `offline_uuid` todo es como hoy: la guarda de 5 segundos frena la segunda venta igual,
     * aunque venga `venta_offline` (sin uuid no hay con qué reconocer un reintento).
     *
     * @test
     */
    public function sin_uuid_la_guarda_de_cinco_segundos_sigue_como_hoy()
    {
        $articulo = $this->crear_articulo('zz Sin uuid', ['stock' => self::STOCK_INICIAL]);

        $this->postJson('api/sale', $this->payload($articulo))->assertStatus(201);

        $segunda = $this->postJson('api/sale', $this->payload($articulo, ['venta_offline' => 1]));

        $segunda->assertStatus(200);
        $this->assertSame('', $segunda->getContent());
        $this->assertEquals(1, $this->ventas_del_articulo($articulo));

        $venta = Sale::whereIn('id', DB::table('article_sale')->where('article_id', $articulo->id)->pluck('sale_id'))->first();

        $this->assertNull($venta->offline_uuid, 'Una venta sin uuid lo deja en null.');
    }

    /**
     * Un uuid que no sirve (vacío o más largo que la columna) se ignora como si no viniera: la
     * venta se crea sin uuid y la guarda de 5 segundos corre.
     *
     * @test
     */
    public function un_uuid_invalido_se_ignora()
    {
        $articulo = $this->crear_articulo('zz Uuid invalido', ['stock' => self::STOCK_INICIAL]);

        $id = $this->postJson('api/sale', $this->payload($articulo, ['offline_uuid' => str_repeat('a', 37), 'venta_offline' => 1]))
                    ->assertStatus(201)
                    ->json('model.id');

        $this->assertNull(Sale::find($id)->offline_uuid);

        $this->postJson('api/sale', $this->payload($articulo, ['offline_uuid' => '   ', 'venta_offline' => 1]))->assertStatus(200);

        $this->assertEquals(1, $this->ventas_del_articulo($articulo), 'Sin uuid válido la guarda de 5 segundos tenía que frenar la segunda.');
    }

    /**
     * La venta la cargó el DUEÑO en el equipo (`employee_id` 0 + `cargada_por_duenio`) y la sube
     * un EMPLEADO logueado en ese equipo: queda a nombre del dueño (employee_id null). Y de
     * control: la misma subida sin `cargada_por_duenio`, o sin `venta_offline`, queda a nombre del
     * empleado logueado, como siempre.
     *
     * @test
     */
    public function la_venta_del_duenio_subida_por_un_empleado_queda_a_nombre_del_duenio()
    {
        $dueno = $this->usuario();

        $empleado = User::create([
            'name'         => 'zz Empleado que sube',
            'company_name' => $dueno->company_name,
            'email'        => 'zz-empleado-offline-'.uniqid().'@test.local',
            'doc_number'   => 'ZZ-OFF-'.uniqid(),
            'password'     => bcrypt('no-importa'),
            'owner_id'     => $dueno->id,
        ]);

        $this->actingAs($empleado, 'web');

        $articulo = $this->crear_articulo('zz Offline cargada por el duenio', ['stock' => self::STOCK_INICIAL]);

        $del_duenio = $this->postJson('api/sale', $this->payload($articulo, [
            'employee_id'        => 0,
            'offline_uuid'       => $this->uuid(),
            'venta_offline'      => 1,
            'cargada_por_duenio' => 1,
        ]))->assertStatus(201);

        $this->assertNull(Sale::find($del_duenio->json('model.id'))->employee_id, 'La venta que cargó el dueño tiene que quedar a nombre del dueño.');
        $this->assertEquals($dueno->id, Sale::find($del_duenio->json('model.id'))->user_id, 'La venta es del comercio del dueño.');

        $del_empleado = $this->postJson('api/sale', $this->payload($articulo, [
            'employee_id'   => 0,
            'offline_uuid'  => $this->uuid(),
            'venta_offline' => 1,
        ]))->assertStatus(201);

        $this->assertEquals($empleado->id, Sale::find($del_empleado->json('model.id'))->employee_id, 'Sin cargada_por_duenio la venta es del empleado logueado.');

        $online = $this->postJson('api/sale', $this->payload($articulo, [
            'employee_id'        => 0,
            'offline_uuid'       => $this->uuid(),
            'cargada_por_duenio' => 1,
            // Total distinto para que la guarda de 5 segundos (que corre online) no la confunda.
            'total'              => self::PRECIO * self::CANTIDAD + 1,
        ]))->assertStatus(201);

        $this->assertEquals($empleado->id, Sale::find($online->json('model.id'))->employee_id, 'Sin venta_offline, cargada_por_duenio no cambia nada.');
    }

    /**
     * La subida guarda la HORA en que la venta se guardó en el equipo (`capturada_at`), no la del
     * momento en que volvió la red. Sin `created_at` elegido, el día también sale de la captura.
     *
     * @test
     */
    public function la_hora_de_la_subida_sale_de_capturada_at()
    {
        $articulo = $this->crear_articulo('zz Offline capturada', ['stock' => self::STOCK_INICIAL]);

        $capturada_utc = Carbon::now('UTC')->subHours(3)->subMinutes(17);

        $id = $this->postJson('api/sale', $this->payload($articulo, [
            'offline_uuid'  => $this->uuid(),
            'venta_offline' => 1,
            'capturada_at'  => $capturada_utc->format('Y-m-d\TH:i:s.v\Z'),
        ]))->assertStatus(201)->json('model.id');

        $esperada = $capturada_utc->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        $this->assertEquals($esperada, DB::table('sales')->where('id', $id)->value('created_at'), 'La hora guardada tiene que ser la de captura, en la zona de la app.');
    }

    /**
     * Con `created_at` elegido (`YYYY-MM-DD`), el DÍA sale de ahí y la HORA de la captura.
     *
     * @test
     */
    public function con_dia_elegido_el_dia_sale_del_created_at_y_la_hora_de_la_captura()
    {
        $articulo = $this->crear_articulo('zz Offline capturada con dia', ['stock' => self::STOCK_INICIAL]);

        $dia_elegido = Carbon::now(config('app.timezone'))->subDays(4)->format('Y-m-d');

        $capturada = Carbon::now(config('app.timezone'))->subHours(2);

        $id = $this->postJson('api/sale', $this->payload($articulo, [
            'offline_uuid'  => $this->uuid(),
            'venta_offline' => 1,
            'created_at'    => $dia_elegido,
            'capturada_at'  => $capturada->format('Y-m-d\TH:i:sP'),
        ]))->assertStatus(201)->json('model.id');

        $this->assertEquals($dia_elegido.' '.$capturada->format('H:i:s'), DB::table('sales')->where('id', $id)->value('created_at'));
    }

    /**
     * Un `capturada_at` basura, sin zona, más de un día en el futuro, o en un POST online (sin
     * `venta_offline`) se ignora: la hora es la del servidor, como hoy.
     *
     * @test
     */
    public function un_capturada_at_que_no_sirve_se_ignora()
    {
        $articulo = $this->crear_articulo('zz Offline capturada basura', ['stock' => self::STOCK_INICIAL]);

        $casos = [
            'basura'            => ['capturada_at' => 'ayer a la tarde', 'venta_offline' => 1],
            'sin zona'          => ['capturada_at' => Carbon::now('UTC')->subHours(5)->format('Y-m-d\TH:i:s'), 'venta_offline' => 1],
            'fecha inexistente' => ['capturada_at' => '2026-02-31T10:00:00Z', 'venta_offline' => 1],
            'del futuro'        => ['capturada_at' => Carbon::now('UTC')->addDays(2)->format('Y-m-d\TH:i:s\Z'), 'venta_offline' => 1],
            'online'            => ['capturada_at' => Carbon::now('UTC')->subHours(5)->format('Y-m-d\TH:i:s\Z')],
        ];

        $total = self::PRECIO * self::CANTIDAD;

        foreach ($casos as $caso => $extra) {

            $antes = Carbon::now(config('app.timezone'))->subSecond();

            // Total distinto en cada caso para que la guarda de 5 segundos (en el caso online) no los mezcle.
            $total++;

            $id = $this->postJson('api/sale', $this->payload($articulo, array_merge([
                'offline_uuid' => $this->uuid(),
                'total'        => $total,
            ], $extra)))->assertStatus(201)->json('model.id');

            $despues = Carbon::now(config('app.timezone'))->addSecond();

            $guardada = Carbon::parse(DB::table('sales')->where('id', $id)->value('created_at'), config('app.timezone'));

            $this->assertTrue(
                $guardada->between($antes, $despues),
                'Caso "'.$caso.'": tenía que quedar la hora del servidor y quedó '.$guardada->format('Y-m-d H:i:s').'.'
            );
        }
    }

    /**
     * La última red: el índice único `(user_id, offline_uuid)` rechaza un segundo INSERT con el
     * mismo uuid, y `VentaOfflineHelper` reconoce ESE rechazo (y no otro) y devuelve la venta.
     *
     * @test
     */
    public function el_indice_unico_rechaza_el_uuid_repetido_y_el_helper_lo_reconoce()
    {
        $articulo = $this->crear_articulo('zz Offline indice unico', ['stock' => self::STOCK_INICIAL]);

        $uuid = $this->uuid();

        $id = $this->postJson('api/sale', $this->payload($articulo, ['offline_uuid' => $uuid, 'venta_offline' => 1]))
                    ->assertStatus(201)
                    ->json('model.id');

        $user_id = $this->usuario()->id;

        $rechazo = null;

        /*
         * Copia exacta de la fila (sin el id) para que el único motivo de rechazo posible sea el
         * índice: un INSERT con columnas sueltas podría morir antes por un NOT NULL sin default.
         */
        $fila = (array) DB::table('sales')->where('id', $id)->first();
        unset($fila['id']);
        $fila['num'] = 999999;

        try {
            DB::table('sales')->insert($fila);
        } catch (QueryException $e) {
            $rechazo = $e;
        }

        $this->assertNotNull($rechazo, 'El índice único tenía que rechazar el mismo uuid para el mismo comercio.');
        $this->assertTrue(VentaOfflineHelper::es_duplicado_de_offline_uuid($rechazo));

        $venta = VentaOfflineHelper::venta_ya_subida_si_choco_el_indice($rechazo, $user_id, $uuid);

        $this->assertNotNull($venta);
        $this->assertEquals($id, $venta->id);

        // Otra excepción (no es el índice del uuid) no se confunde con un reintento.
        $this->assertFalse(VentaOfflineHelper::es_duplicado_de_offline_uuid(new \Exception('Duplicate entry sales_user_offline_uuid_uq')));
        $this->assertNull(VentaOfflineHelper::venta_ya_subida_si_choco_el_indice($rechazo, $user_id, null));
    }
}
