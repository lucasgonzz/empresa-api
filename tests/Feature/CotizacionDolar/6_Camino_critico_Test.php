<?php

namespace Tests\Feature\CotizacionDolar;

use App\Jobs\ProcessSetFinalPrices;
use App\Models\DolarCotizacionRegistro;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\Dolar\CotizacionDolarService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión cotizacion-dolar — archivo 6: EL CAMINO CRÍTICO, de punta a punta.
 *
 * Un solo método largo, con base de por medio y pegándole a los endpoints reales por HTTP. Recorre
 * la vida de una cuenta: nunca eligió → elige → el dólar se mueve → acepta → el dólar se queda
 * quieto → el proveedor se cae.
 *
 * 🔴 LO QUE HACE VALER ESTE ARCHIVO ES EL CONTRASTE ENTRE LOS PASOS 5 Y 6, EN UN MISMO TEST:
 *
 *   paso 5 -> estado 'ok'              + comparacion con variacion_porcentaje 0.00  ("no varió")
 *   paso 6 -> estado 'proveedor_caido' + comparacion null                            ("no se pudo medir")
 *
 * Los dos son "no hay nada nuevo que mostrarte" desde la vereda del usuario, y son cosas
 * COMPLETAMENTE distintas: en el primero la cotización está al día, en el segundo el comerciante
 * puede estar costeando con un dólar de la semana pasada sin saberlo. Si algún día alguien
 * "simplifica" el segundo caso a un 0%, este test se pone rojo. Esa es la red que atrapa la clase
 * de error de APRENDER_NO_PARCHEAR.md.
 *
 * PHP 7.4 también acá.
 */
class Camino_critico_Test extends TestCase
{
    use DatabaseTransactions;

    /** El slug de la extensión que gatea los endpoints. */
    const SLUG = 'costo_en_dolares';

    /** @var \App\Models\User|null */
    protected $comercio;

    /** Cuánto vale hoy el blue venta del lado del proveedor. @var float */
    protected $blue_venta = 1540;

    /** Si el proveedor está caído. @var bool */
    protected $proveedor_caido = false;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        /*
         * 🔴 UN SOLO stub para todo el test, que lee el estado actual en cada llamada.
         *
         * `Http::fake()` NO reemplaza los stubs anteriores: los apila, y el handler se queda con el
         * PRIMERO que matchea. O sea que llamar a `Http::fake(['*' => ...])` seis veces deja las
         * seis requests contestando lo mismo que la primera, y un test así mide la primera línea que
         * escribió en vez de medir el endpoint. (Costó un rojo descubrirlo, y queda escrito acá para
         * que el próximo no lo pague de nuevo.)
         */
        Http::fake(function () {
            return $this->respuesta_del_proveedor();
        });

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
     * Lo que contesta el proveedor con el estado de HOY del test.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    protected function respuesta_del_proveedor()
    {
        if ($this->proveedor_caido) {
            return Http::response('', 502);
        }

        return Http::response([
            ['moneda' => 'USD', 'casa' => 'oficial', 'nombre' => 'Oficial', 'compra' => 1465, 'venta' => 1515, 'fechaActualizacion' => '2026-08-20T11:00:00.000Z'],
            ['moneda' => 'USD', 'casa' => 'blue', 'nombre' => 'Blue', 'compra' => 1500, 'venta' => $this->blue_venta, 'fechaActualizacion' => '2026-08-20T13:56:00.000Z'],
            ['moneda' => 'USD', 'casa' => 'bolsa', 'nombre' => 'Bolsa', 'compra' => 1523.6, 'venta' => 1523.6, 'fechaActualizacion' => '2026-08-20T13:56:00.000Z'],
        ], 200);
    }

    /**
     * Mueve la cotización del proveedor.
     *
     * 🔴 El `Cache::forget` no es un truco para que el test pase: es lo que REPRODUCE producción.
     * El sistema corre con CACHE_DRIVER=array, así que cada request HTTP es un proceso PHP nuevo con
     * el store vacío. Adentro de un test, en cambio, los seis pasos comparten una sola aplicación y
     * una sola caché: sin el forget, el paso 3 leería la cotización del paso 2 y el test estaría
     * midiendo la caché en vez del endpoint.
     *
     * @param  float $blue_venta
     * @return void
     */
    protected function mover_cotizacion($blue_venta)
    {
        Cache::forget(CotizacionDolarService::CACHE_KEY);

        $this->proveedor_caido = false;
        $this->blue_venta      = $blue_venta;
    }

    /**
     * Tira abajo al proveedor.
     *
     * @return void
     */
    protected function tirar_abajo_el_proveedor()
    {
        Cache::forget(CotizacionDolarService::CACHE_KEY);

        $this->proveedor_caido = true;
    }

    /**
     * Payload del POST.
     *
     * @return array
     */
    protected function payload()
    {
        return [
            'origen'           => 'blue',
            'casa'             => 'blue',
            'punta'            => 'venta',
            'valor_manual'     => null,
            'avisar_cambios'   => true,
            'variacion_minima' => 1.00,
            'disparo'          => 'login',
        ];
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function el_camino_completo_de_una_cuenta_desde_que_nunca_eligio_hasta_que_se_cae_el_proveedor()
    {
        /* Punto de partida: una cuenta que nunca eligió cotización. */
        $this->comercio->dollar                          = 1000;
        $this->comercio->dolar_cotizacion_origen         = null;
        $this->comercio->dolar_cotizacion_casa           = null;
        $this->comercio->dolar_cotizacion_punta          = null;
        $this->comercio->dolar_cotizacion_valor          = null;
        $this->comercio->dolar_cotizacion_actualizada_at = null;
        $this->comercio->dolar_avisar_cambios            = 1;
        $this->comercio->dolar_variacion_minima          = 1.00;
        $this->comercio->save();

        $registros_antes = DolarCotizacionRegistro::where('user_id', $this->comercio->id)->count();

        // ---------------------------------------------------------------------------------
        // PASO 1 — Nunca eligió (E3). Hay cotizaciones para elegir, pero no hay contra qué medir.
        // ---------------------------------------------------------------------------------
        $this->mover_cotizacion(1540);

        $paso1 = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertEquals('ok', $paso1['estado']);
        $this->assertNull($paso1['comparacion']);
        $this->assertNull($paso1['seleccion_actual']['origen']);
        $this->assertCount(3, $paso1['cotizaciones']);

        // ---------------------------------------------------------------------------------
        // PASO 2 — Elige el blue venta, que hoy vale 1540.
        // ---------------------------------------------------------------------------------
        $this->postJson('api/dolar-cotizacion', $this->payload())->assertStatus(200);

        $fresco = $this->comercio->fresh();

        $this->assertEquals(1540.00, (float) $fresco->dollar);
        $this->assertEquals('blue', $fresco->dolar_cotizacion_origen);
        $this->assertEquals('venta', $fresco->dolar_cotizacion_punta);
        $this->assertEquals(1540.00, (float) $fresco->dolar_cotizacion_valor);

        $this->assertEquals(
            $registros_antes + 1,
            DolarCotizacionRegistro::where('user_id', $this->comercio->id)->count()
        );

        // ---------------------------------------------------------------------------------
        // PASO 3 — El dólar se mueve a 1560 (E6): la variación supera el umbral del usuario.
        // ---------------------------------------------------------------------------------
        $this->mover_cotizacion(1560);

        $paso3 = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertEquals('ok', $paso3['estado']);
        $this->assertEquals(1.30, $paso3['comparacion']['variacion_porcentaje']);
        $this->assertTrue($paso3['comparacion']['supera_umbral']);

        // ---------------------------------------------------------------------------------
        // PASO 4 — Acepta el valor nuevo: se guarda y se encola el recálculo de precios.
        // ---------------------------------------------------------------------------------
        $this->postJson('api/dolar-cotizacion', $this->payload())->assertStatus(200);

        $this->assertEquals(1560.00, (float) $this->comercio->fresh()->dollar);

        $this->assertEquals(
            $registros_antes + 2,
            DolarCotizacionRegistro::where('user_id', $this->comercio->id)->count()
        );

        $owner_id = $this->comercio->id;

        Queue::assertPushed(ProcessSetFinalPrices::class, function ($job) use ($owner_id) {
            return $job->user_id === $owner_id
                && $job->from_dolar === true
                && $job->origen === 'dolar'
                && !empty($job->origen_detalle);
        });

        /* Dos corridas: la del paso 2 y la del paso 4. Ninguna de más. */
        $this->assertCount(2, Queue::pushed(ProcessSetFinalPrices::class));

        // ---------------------------------------------------------------------------------
        // PASO 5 — El dólar se queda quieto (E4): la cotización ESTÁ AL DÍA. Medición exitosa
        // cuyo resultado es cero.
        // ---------------------------------------------------------------------------------
        $this->mover_cotizacion(1560);

        $paso5 = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertEquals('ok', $paso5['estado']);
        $this->assertNotNull($paso5['comparacion']);
        $this->assertEquals(0.00, $paso5['comparacion']['variacion_porcentaje']);
        $this->assertFalse($paso5['comparacion']['supera_umbral']);

        // ---------------------------------------------------------------------------------
        // PASO 6 — El proveedor se cae (E2): NO SE PUDO MEDIR. Otra cosa completamente.
        // ---------------------------------------------------------------------------------
        $this->tirar_abajo_el_proveedor();

        $paso6 = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertEquals('proveedor_caido', $paso6['estado']);
        $this->assertNull($paso6['comparacion']);
        $this->assertEquals([], $paso6['cotizaciones']);
        $this->assertNotEmpty($paso6['error']['motivo']);

        // ---------------------------------------------------------------------------------
        // 🔴 EL CONTRASTE. Los dos últimos pasos tienen que ser distinguibles en el cable.
        // ---------------------------------------------------------------------------------
        $this->assertNotEquals(
            $paso5['estado'],
            $paso6['estado'],
            'El estado del proveedor caído tiene que ser distinto del de "no varió".'
        );

        $this->assertNotEquals(
            $paso5['comparacion'],
            $paso6['comparacion'],
            'Una medición que da cero y una que no se pudo hacer NO PUEDEN VERSE IGUAL desde el front.'
        );

        /* Y el dato local sigue estando aunque el proveedor no conteste. */
        $this->assertEquals('blue', $paso6['seleccion_actual']['origen']);
        $this->assertEquals(1560.00, $paso6['valor_dolar_actual']);
    }
}
