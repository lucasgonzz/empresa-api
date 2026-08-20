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

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(CotizacionDolarService::CACHE_KEY);

        /* El PUT de perfil despacha el recálculo cuando cambia el dólar. */
        Queue::fake();

        /* Ninguna suite sale a la red, ni siquiera por accidente. */
        Http::fake(['*' => Http::response([], 200)]);

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
     * 🔴 Cambiar el dólar a mano desde el formulario de Configuración marca la cotización como
     * manual y SIN referencia: desde ahí no hay ninguna casa elegida, y no se sale a una API
     * externa desde un PUT de perfil. Sin referencia no hay comparación, así que el modal del login
     * no va a aparecer hasta que el usuario elija una casa. Eso es correcto y es explícito — es
     * justamente el estado del que el botón de Configuración es la única salida.
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
        $this->assertNull($fresco->dolar_cotizacion_casa);
        $this->assertNull($fresco->dolar_cotizacion_punta);
        $this->assertNull($fresco->dolar_cotizacion_valor);

        $this->assertLessThanOrEqual(
            60,
            Carbon::parse($fresco->dolar_cotizacion_actualizada_at)->diffInSeconds(Carbon::now())
        );

        $this->assertEquals($registros_antes + 1, DolarCotizacionRegistro::count());

        $registro = DolarCotizacionRegistro::where('user_id', $this->comercio->id)->latest('id')->first();

        $this->assertEquals('formulario', $registro->disparo);
        $this->assertEquals('manual', $registro->origen);
        $this->assertNull($registro->casa);
        $this->assertEquals(1700.00, (float) $registro->valor_dolar);
        $this->assertEquals(1500.00, (float) $registro->valor_dolar_anterior);
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
}
