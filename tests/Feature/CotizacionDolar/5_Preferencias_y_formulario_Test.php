<?php

namespace Tests\Feature\CotizacionDolar;

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
 * Misión cotizacion-dolar — archivo 5: LAS PREFERENCIAS Y EL FORMULARIO DE PERFIL.
 *
 * Dos caminos que no se pisan y que tienen que seguir sin pisarse:
 *
 *  - `PUT api/dolar-cotizacion/preferencias` cambia SOLO el aviso y su umbral. No toca el dólar, no
 *    despacha jobs, no escribe historial. Existe aparte porque "el usuario dijo *ahora no* pero
 *    apagó el aviso" es cambiar preferencias sin cambiar el dólar.
 *
 *  - 🔴 `UserController@update` NO PUEDE ESCRIBIR ESAS DOS COLUMNAS. `ModelForm` postea el modelo
 *    ENTERO —`auth/me` devuelve el User completo, así que las siete columnas viajan de vuelta en
 *    cada guardado—, con lo cual un cliente que entra a cambiarse el teléfono pisaría sus
 *    preferencias de aviso con lo que el front tenga en memoria. Es el mismo agujero que el repo ya
 *    documentó para `modo_redondeo`, `aplicar_iva_al_costo` y `ofertas_ultima_generacion_at`.
 *
 * 🔴 Los payloads del PUT de perfil se arman con `$user->toArray()` y CONTRADICIENDO la base, no
 * con un request mínimo de laboratorio: si el payload trajera los mismos valores que la base, el
 * test pasaría por dos motivos indistinguibles —porque el endpoint no las tocó (lo que se quiere
 * probar) o porque las escribió encima con esos mismos valores (lo que se quiere prohibir)—.
 *
 * PHP 7.4 también acá.
 */
class Preferencias_y_formulario_Test extends TestCase
{
    use DatabaseTransactions;

    /** El slug de la extensión que gatea el endpoint de preferencias. */
    const SLUG = 'costo_en_dolares';

    /**
     * Las cinco columnas de selección, las que el PUT de perfil sí puede marcar (y solo esas).
     *
     * @var array<int, string>
     */
    const COLUMNAS_SELECCION = [
        'dolar_cotizacion_origen',
        'dolar_cotizacion_casa',
        'dolar_cotizacion_punta',
        'dolar_cotizacion_valor',
        'dolar_cotizacion_actualizada_at',
    ];

    /** @var \App\Models\User|null */
    protected $comercio;

    /**
     * Lo que devuelve la API de cotizaciones en este test. Público porque lo lee el closure del
     * stub de `Http::fake()` del setUp. Vacío = ninguna casa = proveedor caído, que es el default
     * correcto para los tests de este archivo, que no miden cotizaciones.
     *
     * @var array
     */
    public $cotizaciones_fakeadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(CotizacionDolarService::CACHE_KEY);

        /* El PUT de perfil despacha el recálculo cuando cambia el dólar. */
        Queue::fake();

        /*
         * Ninguna suite sale a la red, ni siquiera por accidente.
         *
         * 🔴 El stub es un closure que lee `$this->cotizaciones_fakeadas` en cada llamada, y no un
         * array fijo: `Http::fake()` NO reemplaza los stubs anteriores, los APILA, y el handler se
         * queda con el primero que matchea. Un segundo `Http::fake(['*' => ...])` adentro de un
         * test no pisa nada y el test mide contra la respuesta del setUp sin darse cuenta.
         */
        $test = $this;
        Http::fake(['*' => function () use ($test) {
            return Http::response($test->cotizaciones_fakeadas, 200);
        }]);

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
     * Deja el comercio con lo que se le pase.
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
     * Payload del PUT de perfil tal como lo manda `ModelForm`: el modelo entero, con lo que se
     * quiera pisar encima.
     *
     * @param  \App\Models\User $user
     * @param  array            $overrides
     * @return array
     */
    protected function payload_de_perfil($user, array $overrides = [])
    {
        return array_merge($user->fresh()->toArray(), $overrides);
    }

    /**
     * Las cinco columnas de selección del comercio, leídas de la base.
     *
     * @return array<string, mixed>
     */
    protected function seleccion_en_base()
    {
        $fresco = $this->comercio->fresh();
        $valores = [];

        foreach (self::COLUMNAS_SELECCION as $columna) {
            $valores[$columna] = $fresco->$columna;
        }

        return $valores;
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function el_put_de_preferencias_cambia_solo_el_aviso_y_el_umbral()
    {
        $this->poner([
            'dollar'                 => 1500,
            'dolar_avisar_cambios'   => 1,
            'dolar_variacion_minima' => 1.00,
        ]);

        $registros_antes = DolarCotizacionRegistro::count();

        $respuesta = $this->putJson('api/dolar-cotizacion/preferencias', [
            'avisar_cambios'   => false,
            'variacion_minima' => 2.5,
        ])->assertStatus(200);

        $this->assertTrue($respuesta->json('ok'));
        $this->assertFalse($respuesta->json('avisar_cambios'));
        $this->assertEquals(2.5, $respuesta->json('variacion_minima'));

        $fresco = $this->comercio->fresh();

        $this->assertEquals(0, (int) $fresco->dolar_avisar_cambios);
        $this->assertEquals(2.50, (float) $fresco->dolar_variacion_minima);

        /* No toca el dólar, no despacha nada, no escribe historial. */
        $this->assertEquals(1500.00, (float) $fresco->dollar);
        Queue::assertNothingPushed();
        $this->assertEquals($registros_antes, DolarCotizacionRegistro::count());
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function el_put_de_preferencias_valida_el_umbral()
    {
        $this->putJson('api/dolar-cotizacion/preferencias', [
            'avisar_cambios'   => true,
            'variacion_minima' => 0,
        ])->assertStatus(422);

        $this->putJson('api/dolar-cotizacion/preferencias', [
            'avisar_cambios'   => true,
            'variacion_minima' => 101,
        ])->assertStatus(422);
    }

    /**
     * 🔴 EL TEST DEL AGUJERO CONOCIDO. El payload dice `dolar_avisar_cambios = false` y
     * `dolar_variacion_minima = 50` —o sea, contradice la base— y las dos columnas tienen que
     * quedar como estaban. Si el `update` las asignara, un cliente que entra a cambiarse el
     * teléfono se apagaría los avisos sin enterarse.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function el_put_de_perfil_no_pisa_las_preferencias_de_aviso()
    {
        $this->poner([
            'dolar_avisar_cambios'   => 1,
            'dolar_variacion_minima' => 1.00,
        ]);

        $payload = $this->payload_de_perfil($this->comercio, [
            'dolar_avisar_cambios'   => false,
            'dolar_variacion_minima' => 50,
        ]);

        $this->putJson('api/user/' . $this->comercio->id, $payload)->assertStatus(200);

        $fresco = $this->comercio->fresh();

        $this->assertEquals(1, (int) $fresco->dolar_avisar_cambios);
        $this->assertEquals(1.00, (float) $fresco->dolar_variacion_minima);
    }

    /**
     * 🔴 Cambiar el dólar a mano desde el formulario de Configuración marca el origen como manual
     * pero CONSERVA la referencia que el comercio ya tenía elegida.
     *
     * Este test aseveraba lo contrario —que `casa`, `punta` y `valor` quedaban en null— y eso era
     * el defecto, no la especificación: con la referencia borrada, `comparar()` devolvía null,
     * `debe_avisar` daba false para siempre y el comercio no volvía a recibir un aviso nunca,
     * mientras el checkbox "Avisarme cuando cambie" le seguía apareciendo tildado. Lucas pidió
     * explícitamente que el aviso siga funcionando cuando la cotización se cargó a mano, así que
     * apagarlo en silencio contradice el pedido.
     *
     * Conservándola, la próxima medición dice cuánto se movió esa casa desde la última vez que el
     * usuario la eligió, que es una afirmación cierta. Lo que sigue sin pasar es salir a la API
     * externa desde un PUT de perfil: el valor de referencia queda como estaba, no se refresca.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function cambiar_el_dolar_desde_el_formulario_marca_la_cotizacion_como_manual()
    {
        $this->poner([
            'dollar'                          => 1500,
            'dolar_cotizacion_origen'         => 'blue',
            'dolar_cotizacion_casa'           => 'blue',
            'dolar_cotizacion_punta'          => 'venta',
            'dolar_cotizacion_valor'          => 1500,
            'dolar_cotizacion_actualizada_at' => '2026-08-01 09:00:00',
        ]);

        $registros_antes = DolarCotizacionRegistro::count();

        $payload = $this->payload_de_perfil($this->comercio, ['dollar' => 1700]);

        $this->putJson('api/user/' . $this->comercio->id, $payload)->assertStatus(200);

        $fresco = $this->comercio->fresh();

        $this->assertEquals(1700.00, (float) $fresco->dollar);
        $this->assertEquals('manual', $fresco->dolar_cotizacion_origen);

        /*
         * La referencia sobrevive intacta. Es lo que mantiene vivos los avisos para el comercio
         * que corrige el valor a mano, que era el agujero que este test tapaba.
         */
        $this->assertEquals('blue', $fresco->dolar_cotizacion_casa);
        $this->assertEquals('venta', $fresco->dolar_cotizacion_punta);
        $this->assertEquals(1500.00, (float) $fresco->dolar_cotizacion_valor);

        $this->assertLessThanOrEqual(
            60,
            Carbon::parse($fresco->dolar_cotizacion_actualizada_at)->diffInSeconds(Carbon::now())
        );

        $this->assertEquals($registros_antes + 1, DolarCotizacionRegistro::count());

        $registro = DolarCotizacionRegistro::where('user_id', $this->comercio->id)->latest('id')->first();

        $this->assertEquals('formulario', $registro->disparo);
        $this->assertEquals('manual', $registro->origen);
        $this->assertEquals('blue', $registro->casa);
        $this->assertEquals('venta', $registro->punta);
        $this->assertEquals(1700.00, (float) $registro->valor_dolar);
        $this->assertEquals(1500.00, (float) $registro->valor_dolar_anterior);
    }

    /**
     * 🔴 El comercio que corrige el dólar a mano SIGUE recibiendo avisos.
     *
     * Es la contraparte del test de arriba, y el que de verdad demuestra que el agujero se cerró:
     * no alcanza con que las columnas sobrevivan, tiene que quedar una medición del lado del GET.
     * Antes de este arreglo, acá `comparacion` venía en null y el modal no volvía a aparecer nunca.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function despues_de_cambiar_el_dolar_a_mano_el_aviso_sigue_funcionando()
    {
        $this->poner([
            'dollar'                          => 1500,
            'dolar_cotizacion_origen'         => 'blue',
            'dolar_cotizacion_casa'           => 'blue',
            'dolar_cotizacion_punta'          => 'venta',
            'dolar_cotizacion_valor'          => 1500,
            'dolar_cotizacion_actualizada_at' => '2026-08-01 09:00:00',
            'dolar_avisar_cambios'            => 1,
            'dolar_variacion_minima'          => 1.00,
        ]);

        $payload = $this->payload_de_perfil($this->comercio, ['dollar' => 1700]);
        $this->putJson('api/user/' . $this->comercio->id, $payload)->assertStatus(200);

        /* El blue venta se movió de 1500 a 1560: 4% sobre la referencia conservada. */
        Cache::forget(CotizacionDolarService::CACHE_KEY);
        $this->cotizaciones_fakeadas = [
            ['moneda' => 'USD', 'casa' => 'oficial', 'nombre' => 'Oficial', 'compra' => 1465, 'venta' => 1515, 'fechaActualizacion' => '2026-08-20T11:00:00.000Z'],
            ['moneda' => 'USD', 'casa' => 'blue', 'nombre' => 'Blue', 'compra' => 1540, 'venta' => 1560, 'fechaActualizacion' => '2026-08-20T13:56:00.000Z'],
            ['moneda' => 'USD', 'casa' => 'bolsa', 'nombre' => 'Bolsa', 'compra' => 1523.6, 'venta' => 1523.6, 'fechaActualizacion' => '2026-08-20T13:56:00.000Z'],
        ];

        $respuesta = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertEquals('ok', $respuesta['estado']);
        $this->assertNotNull(
            $respuesta['comparacion'],
            'Sin referencia no hay medición, y el comercio se queda sin avisos para siempre.'
        );
        $this->assertEquals(1500.00, (float) $respuesta['comparacion']['valor_referencia']);
        $this->assertEquals(1560.00, (float) $respuesta['comparacion']['valor_nuevo']);
        $this->assertEquals(4.00, (float) $respuesta['comparacion']['variacion_porcentaje']);
        $this->assertTrue($respuesta['comparacion']['supera_umbral']);
    }

    /**
     * 🔴 EL GANCHO NO CORRE PARA UN EMPLEADO, Y NO ES UN OLVIDO.
     *
     * `UserController@update` escribe `dollar` en `Auth()->user()`, que para un empleado admin es SU
     * PROPIA FILA y no la del dueño (bug preexistente de develop; esta misión NO lo arregla).
     * Escribir el marcador en la fila del owner mientras el `dollar` se guardó en la del empleado
     * dejaría las dos filas contando historias distintas: el owner diría "cotización manual de hoy"
     * con un dólar que nadie le cambió.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function el_put_de_perfil_de_un_empleado_no_marca_la_cotizacion_del_dueno()
    {
        $this->poner([
            'dollar'                  => 1500,
            'dolar_cotizacion_origen' => 'blue',
            'dolar_cotizacion_casa'   => 'blue',
            'dolar_cotizacion_punta'  => 'venta',
            'dolar_cotizacion_valor'  => 1500,
        ]);

        $empleado = User::create([
            'name'         => 'zz-cotizacion-empleado-' . uniqid(),
            'email'        => 'zz-cotizacion-' . uniqid() . '@test.local',
            'password'     => Hash::make('zz-password-testing'),
            'status'       => 'commerce',
            'owner_id'     => $this->comercio->id,
            'admin_access' => 1,
            'dollar'       => 900,
        ]);

        $this->actingAs($empleado, 'web');

        $antes           = $this->seleccion_en_base();
        $registros_antes = DolarCotizacionRegistro::count();

        $payload = $this->payload_de_perfil($empleado, ['dollar' => 1800]);

        $this->putJson('api/user/' . $empleado->id, $payload)->assertStatus(200);

        $this->assertEquals($antes, $this->seleccion_en_base());
        $this->assertEquals($registros_antes, DolarCotizacionRegistro::count());
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function un_put_de_perfil_que_no_cambia_el_dolar_no_toca_ninguna_columna_de_cotizacion()
    {
        $this->poner([
            'dollar'                          => 1500,
            'dolar_cotizacion_origen'         => 'blue',
            'dolar_cotizacion_casa'           => 'blue',
            'dolar_cotizacion_punta'          => 'venta',
            'dolar_cotizacion_valor'          => 1500,
            'dolar_cotizacion_actualizada_at' => '2026-08-01 09:00:00',
        ]);

        $antes           = $this->seleccion_en_base();
        $registros_antes = DolarCotizacionRegistro::count();

        $payload = $this->payload_de_perfil($this->comercio, ['phone' => '2999' . rand(100000, 999999)]);

        $this->putJson('api/user/' . $this->comercio->id, $payload)->assertStatus(200);

        $this->assertEquals($antes, $this->seleccion_en_base());
        $this->assertEquals($registros_antes, DolarCotizacionRegistro::count());
    }

    /**
     * 🔴 Un POST que NO manda las preferencias no las cambia.
     *
     * Eran `required`, y eso obligaba al modal a mandarlas siempre — también en las pantallas donde
     * el checkbox y el umbral ni se dibujan (proveedor caído). Ahí el modal mandaba su copia local,
     * que tras un fallo de red son los defaults del store (avisar=true, umbral=1%): un comercio que
     * había APAGADO el aviso y puesto el umbral en 5% lo veía volver a prenderse en 1% por guardar
     * un valor a mano, sin haber tocado ningún control y sin que nada se lo dijera.
     *
     * Es el mismo agujero que `UserController@update` ya tiene documentado para ModelForm,
     * entrando por la otra puerta. Quien no manda el campo, no lo cambia.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function un_post_sin_preferencias_no_pisa_las_que_el_comercio_tenia()
    {
        $this->poner([
            'dollar'                 => 1500,
            'dolar_avisar_cambios'   => 0,
            'dolar_variacion_minima' => 5.00,
        ]);

        Cache::forget(CotizacionDolarService::CACHE_KEY);
        $this->cotizaciones_fakeadas = [];

        /* Sin `avisar_cambios` ni `variacion_minima` en el payload, que es el caso del bug. */
        $this->postJson('api/dolar-cotizacion', [
            'origen'       => 'manual',
            'casa'         => 'blue',
            'punta'        => 'venta',
            'valor_manual' => 1650,
            'disparo'      => 'configuracion',
        ])->assertStatus(200);

        $fresco = $this->comercio->fresh();

        $this->assertEquals(1650.00, (float) $fresco->dollar, 'El valor manual sí se tenía que guardar.');
        $this->assertEquals(0, (int) $fresco->dolar_avisar_cambios, 'Le volvieron a prender el aviso que había apagado.');
        $this->assertEquals(5.00, (float) $fresco->dolar_variacion_minima, 'Le pisaron el umbral con el default.');
    }

    /**
     * 🔴 "Ahora no" deja anotado de qué valor el comercio ya se enteró, y el arranque deja de
     * abrirle el modal por ESE mismo valor.
     *
     * Sin esto, la comparación se hace siempre contra `dolar_cotizacion_valor` —que solo se mueve
     * cuando el usuario ACEPTA—, así que el mismo modal, con los mismos dos números, volvía a
     * abrirse en cada inicio de sesión y en cada F5. Lucas pidió que se avise "cuando se detecte un
     * NUEVO cambio"; repetir el aviso viejo entrena al usuario a cerrarlo sin leer.
     *
     * 🔴 Y la medición se sigue devolviendo igual: el umbral y lo pospuesto filtran si el modal
     * aparece SOLO, no lo que se dice cuando el usuario lo abre a mano y pregunta.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function ahora_no_deja_de_avisar_por_ese_valor_pero_sigue_avisando_por_el_siguiente()
    {
        $this->poner([
            'dollar'                          => 1500,
            'dolar_cotizacion_origen'         => 'blue',
            'dolar_cotizacion_casa'           => 'blue',
            'dolar_cotizacion_punta'          => 'venta',
            'dolar_cotizacion_valor'          => 1500,
            'dolar_cotizacion_actualizada_at' => '2026-08-01 09:00:00',
            'dolar_avisar_cambios'            => 1,
            'dolar_variacion_minima'          => 1.00,
        ]);

        $this->fakear_blue_venta(1560);

        /* Antes de posponer, la medición supera el umbral y no está pospuesta. */
        $antes = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();
        $this->assertTrue($antes['comparacion']['supera_umbral']);
        $this->assertFalse($antes['comparacion']['pospuesto']);

        /* El usuario aprieta "Ahora no". */
        $this->putJson('api/dolar-cotizacion/preferencias', [
            'avisar_cambios'   => true,
            'variacion_minima' => 1.00,
            'pospuesto_valor'  => 1560,
        ])->assertStatus(200);

        $this->fakear_blue_venta(1560);
        $mismo = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertTrue($mismo['comparacion']['pospuesto'], 'El modal le vuelve a saltar por el mismo valor.');
        $this->assertTrue(
            $mismo['comparacion']['supera_umbral'],
            'La medición no se borra: abierto a mano, el modal tiene que seguir mostrando la variación.'
        );

        /* El dólar se mueve otra vez: eso SÍ es un cambio nuevo y tiene que volver a avisar. */
        $this->fakear_blue_venta(1600);
        $nuevo = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertFalse($nuevo['comparacion']['pospuesto'], 'Un cambio nuevo quedó tapado por lo pospuesto.');
        $this->assertTrue($nuevo['comparacion']['supera_umbral']);
    }

    /**
     * Adoptar una cotización limpia lo pospuesto: la referencia cambió y ese "ya me enteré" quedó
     * viejo, así que el próximo movimiento tiene que volver a avisar.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function adoptar_una_cotizacion_limpia_lo_pospuesto()
    {
        $this->poner([
            'dollar'                      => 1500,
            'dolar_cotizacion_origen'     => 'blue',
            'dolar_cotizacion_casa'       => 'blue',
            'dolar_cotizacion_punta'      => 'venta',
            'dolar_cotizacion_valor'      => 1500,
            'dolar_aviso_pospuesto_valor' => 1560,
        ]);

        $this->fakear_blue_venta(1560);

        $this->postJson('api/dolar-cotizacion', [
            'origen'  => 'blue',
            'casa'    => 'blue',
            'punta'   => 'venta',
            'disparo' => 'login',
        ])->assertStatus(200);

        $this->assertNull($this->comercio->fresh()->dolar_aviso_pospuesto_valor);
    }

    /**
     * Deja fakeada la respuesta de la API con el blue venta que se le pase.
     *
     * @param  float|int $blue_venta
     * @return void
     */
    protected function fakear_blue_venta($blue_venta)
    {
        Cache::forget(CotizacionDolarService::CACHE_KEY);

        $this->cotizaciones_fakeadas = [
            ['moneda' => 'USD', 'casa' => 'oficial', 'nombre' => 'Oficial', 'compra' => 1465, 'venta' => 1515, 'fechaActualizacion' => '2026-08-20T11:00:00.000Z'],
            ['moneda' => 'USD', 'casa' => 'blue', 'nombre' => 'Blue', 'compra' => 1540, 'venta' => $blue_venta, 'fechaActualizacion' => '2026-08-20T13:56:00.000Z'],
            ['moneda' => 'USD', 'casa' => 'bolsa', 'nombre' => 'Bolsa', 'compra' => 1523.6, 'venta' => 1523.6, 'fechaActualizacion' => '2026-08-20T13:56:00.000Z'],
        ];
    }
}
