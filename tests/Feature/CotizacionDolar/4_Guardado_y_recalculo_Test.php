<?php

namespace Tests\Feature\CotizacionDolar;

use App\Jobs\ProcessSetFinalPrices;
use App\Models\DolarCotizacionRegistro;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\Dolar\CotizacionDolarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión cotizacion-dolar — archivo 4: EL GUARDADO Y EL RECÁLCULO.
 *
 * `POST api/dolar-cotizacion` es el único camino de escritura del dólar con el que se costea el
 * catálogo, así que acá se prueban las tres cosas que, si se rompen, le mueven los precios a un
 * comercio real:
 *
 *  1. 🔴 SE ESCRIBE EN LA FILA DEL OWNER, SIEMPRE. Un empleado con admin_access que guardara la
 *     cotización en su propia fila dejaría al costeo —que lee la del dueño— usando el dólar viejo,
 *     y nadie se enteraría.
 *  2. 🔴 NO SE CONFÍA EN EL NÚMERO DEL CLIENTE con un origen preestablecido: el valor sale de la
 *     API y de ningún otro lado.
 *  3. 🔴 CON EL PROVEEDOR CAÍDO Y UN ORIGEN PREESTABLECIDO NO SE ESCRIBE NADA: guardar "el blue
 *     venta" sin saber cuánto vale dejaría una selección apuntando a un número que nadie midió.
 *
 * PHP 7.4 también acá.
 */
class Guardado_y_recalculo_Test extends TestCase
{
    use DatabaseTransactions;

    /** El slug de la extensión que gatea el endpoint. */
    const SLUG = 'costo_en_dolares';

    /** @var \App\Models\User|null */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(CotizacionDolarService::CACHE_KEY);

        /* Antes de cualquier request: el POST puede despachar el recálculo de precios. */
        Queue::fake();

        $this->comercio = User::find(500);

        if (is_null($this->comercio)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            /* forceCreate: ExtencionEmpresa no declara $fillable y el create falla fuera de db:seed. */
            $extencion = ExtencionEmpresa::forceCreate(['name' => 'Costo en Dolares', 'slug' => self::SLUG]);
        }

        /* syncWithoutDetaching y no attach: el 500 puede ya tenerla y un attach duplica el pivot. */
        $this->comercio->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->comercio->load('extencions');

        $this->actingAs($this->comercio, 'web');
    }

    /**
     * Deja al proveedor devolviendo las tres casas, con el blue venta que se le pida.
     *
     * @param  float $blue_venta
     * @return void
     */
    protected function fakear_blue_venta($blue_venta)
    {
        Http::fake(['*' => Http::response([
            ['moneda' => 'USD', 'casa' => 'oficial', 'nombre' => 'Oficial', 'compra' => 1465, 'venta' => 1515, 'fechaActualizacion' => '2026-08-20T11:00:00.000Z'],
            ['moneda' => 'USD', 'casa' => 'blue', 'nombre' => 'Blue', 'compra' => 1540, 'venta' => $blue_venta, 'fechaActualizacion' => '2026-08-20T13:56:00.000Z'],
            ['moneda' => 'USD', 'casa' => 'bolsa', 'nombre' => 'Bolsa', 'compra' => 1523.6, 'venta' => 1523.6, 'fechaActualizacion' => '2026-08-20T13:56:00.000Z'],
        ], 200)]);
    }

    /**
     * Deja al proveedor caído.
     *
     * @return void
     */
    protected function fakear_proveedor_caido()
    {
        Http::fake(['*' => Http::response('', 502)]);
    }

    /**
     * Payload del POST, con lo que se quiera pisar encima del caso normal (blue / venta).
     *
     * @param  array $overrides
     * @return array
     */
    protected function payload(array $overrides = [])
    {
        return array_merge([
            'origen'           => 'blue',
            'casa'             => 'blue',
            'punta'            => 'venta',
            'valor_manual'     => null,
            'avisar_cambios'   => true,
            'variacion_minima' => 1.5,
            'disparo'          => 'login',
        ], $overrides);
    }

    /**
     * Deja el comercio con el dólar y la selección que se le pasen.
     *
     * @param  array $columnas
     * @return void
     */
    protected function poner(array $columnas)
    {
        foreach ($columnas as $columna => $valor) {
            $this->comercio->$columna = $valor;
        }

        $this->comercio->save();
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function guarda_el_valor_de_la_api_y_las_cinco_columnas_de_seleccion()
    {
        $this->poner(['dollar' => 1500, 'dolar_cotizacion_actualizada_at' => null]);
        $this->fakear_blue_venta(1560);

        $this->postJson('api/dolar-cotizacion', $this->payload())->assertStatus(200);

        $fresco = $this->comercio->fresh();

        $this->assertEquals(1560.00, (float) $fresco->dollar);
        $this->assertEquals('blue', $fresco->dolar_cotizacion_origen);
        $this->assertEquals('blue', $fresco->dolar_cotizacion_casa);
        $this->assertEquals('venta', $fresco->dolar_cotizacion_punta);
        $this->assertEquals(1560.00, (float) $fresco->dolar_cotizacion_valor);
        $this->assertNotNull($fresco->dolar_cotizacion_actualizada_at);

        /* La marca es de recién: alimenta el "actualizaste la cotización el …" del modal. */
        $this->assertLessThanOrEqual(
            60,
            Carbon::parse($fresco->dolar_cotizacion_actualizada_at)->diffInSeconds(Carbon::now())
        );

        /* Y las preferencias que vinieron en el mismo payload. */
        $this->assertEquals(1, (int) $fresco->dolar_avisar_cambios);
        $this->assertEquals(1.50, (float) $fresco->dolar_variacion_minima);
    }

    /**
     * 🔴 LA COTIZACIÓN ES DEL COMERCIO, NO DE LA PERSONA.
     *
     * Un empleado con admin_access opera en nombre del dueño: la escritura tiene que ir a la fila
     * del dueño, que es la que lee el costeo (ArticleHelper:650, ArticlePriceTypeMonedaHelper:106,
     * ProviderOrderHelper:327). Se aseveran LAS DOS filas, porque el bug es justamente que la
     * escritura caiga en la equivocada y el sistema siga andando con el dólar viejo.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function un_empleado_admin_escribe_en_la_fila_del_dueno_y_no_en_la_suya()
    {
        $this->poner(['dollar' => 1500]);
        $this->fakear_blue_venta(1560);

        $empleado = User::create([
            'name'         => 'zz-cotizacion-empleado-' . uniqid(),
            'email'        => 'zz-cotizacion-' . uniqid() . '@test.local',
            'password'     => Hash::make('zz-password-testing'),
            'status'       => 'commerce',
            'owner_id'     => $this->comercio->id,
            'admin_access' => 1,
            'dollar'       => 999,
        ]);

        $this->actingAs($empleado, 'web');

        $this->postJson('api/dolar-cotizacion', $this->payload())->assertStatus(200);

        $this->assertEquals(1560.00, (float) $this->comercio->fresh()->dollar);
        $this->assertEquals(999.00, (float) $empleado->fresh()->dollar);
        $this->assertNull($empleado->fresh()->dolar_cotizacion_origen);

        /* Y el historial cuelga del comercio, dejando asentado quién lo hizo. */
        $registro = DolarCotizacionRegistro::where('user_id', $this->comercio->id)->latest('id')->first();

        $this->assertNotNull($registro);
        $this->assertEquals($this->comercio->id, $registro->user_id);
        $this->assertEquals($empleado->id, $registro->auth_user_id);
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function despacha_el_recalculo_con_el_origen_dolar_cuando_el_valor_cambio()
    {
        $this->poner(['dollar' => 1500]);
        $this->fakear_blue_venta(1560);

        $respuesta = $this->postJson('api/dolar-cotizacion', $this->payload())->assertStatus(200);

        $this->assertTrue($respuesta->json('recalculo_encolado'));

        $owner_id = $this->comercio->id;

        Queue::assertPushed(ProcessSetFinalPrices::class, function ($job) use ($owner_id) {
            return $job->user_id === $owner_id
                && $job->from_dolar === true
                && $job->origen === 'dolar'
                && !empty($job->origen_detalle);
        });
    }

    /**
     * 🔴 Reconfirmar el mismo número NO despacha nada —recalcular el catálogo entero le mandaría
     * al comerciante un aviso de "se actualizaron tus precios" sin que se le haya movido uno—,
     * PERO sí actualiza la marca de tiempo y sí deja la fila de historial: el usuario reconfirmó,
     * y eso es información. Es exactamente el caso que `price_update_runs` no puede registrar.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function reconfirmar_el_mismo_valor_no_despacha_pero_deja_rastro()
    {
        $this->poner([
            'dollar'                          => 1560,
            'dolar_cotizacion_origen'         => 'blue',
            'dolar_cotizacion_casa'           => 'blue',
            'dolar_cotizacion_punta'          => 'venta',
            'dolar_cotizacion_valor'          => 1560,
            'dolar_cotizacion_actualizada_at' => '2026-08-01 09:00:00',
        ]);
        $this->fakear_blue_venta(1560);

        $registros_antes = DolarCotizacionRegistro::where('user_id', $this->comercio->id)->count();

        $respuesta = $this->postJson('api/dolar-cotizacion', $this->payload())->assertStatus(200);

        $this->assertFalse($respuesta->json('recalculo_encolado'));
        Queue::assertNothingPushed();

        $fresco = $this->comercio->fresh();

        $this->assertLessThanOrEqual(
            60,
            Carbon::parse($fresco->dolar_cotizacion_actualizada_at)->diffInSeconds(Carbon::now())
        );

        $this->assertEquals(
            $registros_antes + 1,
            DolarCotizacionRegistro::where('user_id', $this->comercio->id)->count()
        );
    }

    /**
     * 🔴 LOS DOS NÚMEROS SON DISTINTOS Y SE ASEVERAN POR SEPARADO. El usuario tipeó 1600 (eso va a
     * `users.dollar`, que es con lo que costea) y eligió comparar contra el blue venta, que hoy
     * vale 1540 (eso va a `dolar_cotizacion_valor`, que es contra lo que se mide la variación).
     * Si se guardara un solo número, el modal le saltaría en el próximo login por una variación
     * que él mismo acaba de crear escribiendo.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function el_origen_manual_guarda_el_valor_tipeado_y_la_referencia_por_separado()
    {
        $this->poner(['dollar' => 1500]);
        $this->fakear_blue_venta(1540);

        $this->postJson('api/dolar-cotizacion', $this->payload([
            'origen'       => 'manual',
            'casa'         => 'blue',
            'punta'        => 'venta',
            'valor_manual' => 1600,
        ]))->assertStatus(200);

        $fresco = $this->comercio->fresh();

        $this->assertEquals(1600.00, (float) $fresco->dollar);
        $this->assertEquals(1540.00, (float) $fresco->dolar_cotizacion_valor);
        $this->assertEquals('manual', $fresco->dolar_cotizacion_origen);
        $this->assertEquals('blue', $fresco->dolar_cotizacion_casa);
    }

    /**
     * 🔴 Sin saber cuánto vale el blue no se puede guardar "el blue venta". 409 y CERO escrituras:
     * ni la columna, ni el historial, ni el job.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function con_el_proveedor_caido_un_origen_preestablecido_devuelve_409_y_no_escribe_nada()
    {
        $this->poner(['dollar' => 1500, 'dolar_cotizacion_origen' => null]);
        $this->fakear_proveedor_caido();

        $registros_antes = DolarCotizacionRegistro::where('user_id', $this->comercio->id)->count();

        $respuesta = $this->postJson('api/dolar-cotizacion', $this->payload())->assertStatus(409);

        $this->assertEquals('proveedor_caido', $respuesta->json('estado'));
        $this->assertNotEmpty($respuesta->json('error.motivo'));

        $fresco = $this->comercio->fresh();

        $this->assertEquals(1500.00, (float) $fresco->dollar);
        $this->assertNull($fresco->dolar_cotizacion_origen);

        $this->assertEquals(
            $registros_antes,
            DolarCotizacionRegistro::where('user_id', $this->comercio->id)->count()
        );

        Queue::assertNothingPushed();
    }

    /**
     * Manual con el proveedor caído: el valor se guarda igual, pero SIN referencia — y se dice, no
     * en silencio. Sin referencia no hay comparación posible, así que el usuario dejaría de recibir
     * avisos sin haberlo pedido.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function con_el_proveedor_caido_el_origen_manual_guarda_y_avisa_que_quedo_sin_referencia()
    {
        $this->poner(['dollar' => 1500]);
        $this->fakear_proveedor_caido();

        $respuesta = $this->postJson('api/dolar-cotizacion', $this->payload([
            'origen'       => 'manual',
            'valor_manual' => 1600,
        ]))->assertStatus(200);

        $fresco = $this->comercio->fresh();

        $this->assertEquals(1600.00, (float) $fresco->dollar);
        $this->assertEquals('manual', $fresco->dolar_cotizacion_origen);
        $this->assertNull($fresco->dolar_cotizacion_casa);
        $this->assertNull($fresco->dolar_cotizacion_punta);
        $this->assertNull($fresco->dolar_cotizacion_valor);

        $tipos = array_column($respuesta->json('notifications'), 'type');

        $this->assertContains('warning', $tipos);
    }

    /**
     * 🔴 EL ENDPOINT NO CONFÍA EN EL NÚMERO DEL CLIENTE.
     *
     * Con un origen preestablecido el valor sale de la API y de ningún otro lado. Un front viejo o
     * un request armado a mano no puede meter un dólar arbitrario en el costeo de todo el catálogo.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function con_origen_preestablecido_se_ignora_el_valor_manual_que_mande_el_cliente()
    {
        $this->poner(['dollar' => 1500]);
        $this->fakear_blue_venta(1560);

        $this->postJson('api/dolar-cotizacion', $this->payload([
            'origen'       => 'blue',
            'valor_manual' => 99999,
        ]))->assertStatus(200);

        $this->assertEquals(1560.00, (float) $this->comercio->fresh()->dollar);
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function los_payloads_invalidos_devuelven_422()
    {
        $this->poner(['dollar' => 1500]);
        $this->fakear_blue_venta(1560);

        $invalidos = [
            'origen fuera de la lista blanca'          => ['origen' => 'cripto', 'casa' => 'blue'],
            'punta que no es compra ni venta'          => ['punta' => 'medio'],
            'casa distinta del origen preestablecido'  => ['origen' => 'blue', 'casa' => 'oficial'],
            'variacion_minima en cero'                 => ['variacion_minima' => 0],
            'variacion_minima negativa'                => ['variacion_minima' => -1],
            'variacion_minima mayor a cien'            => ['variacion_minima' => 101],
            'origen manual sin valor_manual'           => ['origen' => 'manual', 'valor_manual' => null],
        ];

        foreach ($invalidos as $caso => $overrides) {
            $this->postJson('api/dolar-cotizacion', $this->payload($overrides))
                ->assertStatus(422, 'Tenía que ser 422: ' . $caso);
        }

        /* Y ninguno de los siete tocó el dólar del comercio. */
        $this->assertEquals(1500.00, (float) $this->comercio->fresh()->dollar);
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function la_fila_de_historial_guarda_la_tripleta_completa()
    {
        $this->poner(['dollar' => 1500]);
        $this->fakear_blue_venta(1560);

        $this->postJson('api/dolar-cotizacion', $this->payload(['disparo' => 'login']))->assertStatus(200);

        $registro = DolarCotizacionRegistro::where('user_id', $this->comercio->id)->latest('id')->first();

        $this->assertNotNull($registro);
        $this->assertEquals('blue', $registro->origen);
        $this->assertEquals('blue', $registro->casa);
        $this->assertEquals('venta', $registro->punta);
        $this->assertEquals(1560.00, (float) $registro->valor_dolar);
        $this->assertEquals(1560.00, (float) $registro->valor_cotizacion);
        $this->assertEquals(1500.00, (float) $registro->valor_dolar_anterior);
        $this->assertEquals(4.00, (float) $registro->variacion_porcentaje);
        $this->assertEquals('login', $registro->disparo);
        $this->assertEquals($this->comercio->id, $registro->auth_user_id);
    }

    /**
     * Sin `disparo` en el payload, el registro queda como 'configuracion': es el botón de la
     * pantalla de Configuración, que es de donde sale un POST sin contexto.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function sin_disparo_el_registro_queda_como_configuracion()
    {
        $this->poner(['dollar' => 1500]);
        $this->fakear_blue_venta(1560);

        $payload = $this->payload();
        unset($payload['disparo']);

        $this->postJson('api/dolar-cotizacion', $payload)->assertStatus(200);

        $registro = DolarCotizacionRegistro::where('user_id', $this->comercio->id)->latest('id')->first();

        $this->assertEquals('configuracion', $registro->disparo);
    }
}
