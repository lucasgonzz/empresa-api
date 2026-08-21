<?php

namespace Tests\Feature\CotizacionDolar;

use App\Models\DolarCotizacionRegistro;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\Dolar\CotizacionDolarService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión cotizacion-dolar — archivo 3: EL ENDPOINT DE LECTURA.
 *
 * `GET api/dolar-cotizacion` es lo que corre en cada login, así que es el lugar donde una medición
 * fallida se disfrazaría de buena noticia con menos ruido: nadie está mirando.
 *
 * 🔴 EL TEST CENTRAL DE LA MISIÓN vive en este archivo: con una selección cargada y el proveedor
 * caído, la respuesta dice `proveedor_caido` con `comparacion` en null — y se asevera
 * EXPLÍCITAMENTE que en ningún lado del JSON aparece un `variacion_porcentaje` en 0. Ese cero sería
 * indistinguible de "tu cotización está al día" y el usuario se quedaría costeando con un dólar
 * viejo creyendo que está al día.
 *
 * PHP 7.4 también acá.
 */
class Endpoint_de_lectura_Test extends TestCase
{
    use DatabaseTransactions;

    /** El slug de la extensión que gatea el endpoint. */
    const SLUG = 'costo_en_dolares';

    /**
     * Las siete columnas nuevas de `users`, para la foto del "el GET no escribe".
     *
     * @var array<int, string>
     */
    const COLUMNAS_USERS = [
        'dolar_cotizacion_origen',
        'dolar_cotizacion_casa',
        'dolar_cotizacion_punta',
        'dolar_cotizacion_valor',
        'dolar_cotizacion_actualizada_at',
        'dolar_avisar_cambios',
        'dolar_variacion_minima',
    ];

    /** @var \App\Models\User|null */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(CotizacionDolarService::CACHE_KEY);

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
     * Escribe una selección en la fila del comercio.
     *
     * @param  array $columnas
     * @return void
     */
    protected function poner_seleccion(array $columnas)
    {
        foreach ($columnas as $columna => $valor) {
            $this->comercio->$columna = $valor;
        }

        $this->comercio->save();
    }

    /**
     * La foto de `dollar` + las siete columnas, leída de la base.
     *
     * @return array<string, mixed>
     */
    protected function foto()
    {
        $fresco = $this->comercio->fresh();
        $foto   = ['dollar' => $fresco->dollar];

        foreach (self::COLUMNAS_USERS as $columna) {
            $foto[$columna] = $fresco->$columna;
        }

        return $foto;
    }

    /**
     * Recorre el JSON entero y falla si encuentra un `variacion_porcentaje` en 0.
     *
     * 🔴 Es la red de esta misión: `comparacion === null` significa "no se pudo medir", y un 0 en
     * su lugar se leería como "no varió". Los dos estados tienen que quedar distinguibles en el
     * cable, no solo en el código.
     *
     * @param  mixed  $valor
     * @param  string $ruta
     * @return void
     */
    protected function assert_sin_variacion_en_cero($valor, $ruta = 'raiz')
    {
        if (!is_array($valor)) {
            return;
        }

        foreach ($valor as $clave => $sub) {
            if ($clave === 'variacion_porcentaje' && !is_null($sub)) {
                $this->assertNotEquals(
                    0.0,
                    (float) $sub,
                    'El JSON trae variacion_porcentaje en 0 en ' . $ruta . '.' . $clave
                        . ': un cero acá se lee como "no varió" cuando lo cierto es que no se pudo medir.'
                );
            }

            $this->assert_sin_variacion_en_cero($sub, $ruta . '.' . $clave);
        }
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function mide_la_variacion_contra_la_referencia_guardada()
    {
        $this->poner_seleccion([
            'dolar_cotizacion_origen' => 'blue',
            'dolar_cotizacion_casa'   => 'blue',
            'dolar_cotizacion_punta'  => 'venta',
            'dolar_cotizacion_valor'  => 1540,
            'dolar_variacion_minima'  => 1.00,
        ]);

        $this->fakear_blue_venta(1560);

        $json = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertEquals('ok', $json['estado']);
        $this->assertEquals(1540.0, $json['comparacion']['valor_referencia']);
        $this->assertEquals(1560.0, $json['comparacion']['valor_nuevo']);
        $this->assertEquals(20.0, $json['comparacion']['diferencia']);
        $this->assertEquals(1.30, $json['comparacion']['variacion_porcentaje']);
        $this->assertTrue($json['comparacion']['supera_umbral']);
    }

    /**
     * 🔴 EL UMBRAL FILTRA SI SE MUESTRA EL MODAL, NUNCA BORRA LA MEDICIÓN. La misma variación de
     * 1,30% con el umbral en 2% sigue saliendo completa: lo único que cambia es `supera_umbral`.
     * Si el endpoint escondiera el número, el usuario que abre el modal a propósito vería "tu
     * cotización está al día" con un dólar que se movió.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function el_umbral_no_borra_la_medicion()
    {
        $this->poner_seleccion([
            'dolar_cotizacion_origen' => 'blue',
            'dolar_cotizacion_casa'   => 'blue',
            'dolar_cotizacion_punta'  => 'venta',
            'dolar_cotizacion_valor'  => 1540,
            'dolar_variacion_minima'  => 2.00,
        ]);

        $this->fakear_blue_venta(1560);

        $json = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertEquals(1.30, $json['comparacion']['variacion_porcentaje']);
        $this->assertFalse($json['comparacion']['supera_umbral']);
    }

    /**
     * Que el dólar BAJE le importa al comerciante tanto como que suba: el umbral compara el valor
     * absoluto y el porcentaje conserva el signo.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function una_baja_tambien_supera_el_umbral_y_conserva_el_signo()
    {
        $this->poner_seleccion([
            'dolar_cotizacion_origen' => 'blue',
            'dolar_cotizacion_casa'   => 'blue',
            'dolar_cotizacion_punta'  => 'venta',
            'dolar_cotizacion_valor'  => 1540,
            'dolar_variacion_minima'  => 1.00,
        ]);

        $this->fakear_blue_venta(1520);

        $json = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertEquals(-1.30, $json['comparacion']['variacion_porcentaje']);
        $this->assertTrue($json['comparacion']['supera_umbral']);
    }

    /**
     * 🔴 No haber elegido nunca y que la API se caiga son DOS COSAS DISTINTAS, y el endpoint las
     * distingue: acá `estado` es 'ok' (las cotizaciones están, se pueden elegir) y lo único que
     * falta es la referencia contra la que medir.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function sin_seleccion_previa_no_hay_comparacion_pero_el_estado_es_ok()
    {
        $this->poner_seleccion([
            'dolar_cotizacion_origen' => null,
            'dolar_cotizacion_casa'   => null,
            'dolar_cotizacion_punta'  => null,
            'dolar_cotizacion_valor'  => null,
        ]);

        $this->fakear_blue_venta(1560);

        $json = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertEquals('ok', $json['estado']);
        $this->assertNotEquals('proveedor_caido', $json['estado']);
        $this->assertNull($json['comparacion']);
        $this->assertNull($json['seleccion_actual']['origen']);
        $this->assertCount(3, $json['cotizaciones']);
    }

    /**
     * 🔴🔴 EL TEST CENTRAL DE LA MISIÓN.
     *
     * Hay una selección cargada —o sea que HABRÍA contra qué comparar— y el proveedor no contesta.
     * La respuesta tiene que decir exactamente eso: `proveedor_caido`, `comparacion` en null y un
     * motivo. Lo que NO puede pasar bajo ninguna forma es que en el JSON aparezca un
     * `variacion_porcentaje` en 0, porque el front lo mostraría como "tu cotización está al día".
     *
     * @group cotizacion-dolar
     * @test
     */
    public function con_el_proveedor_caido_no_hay_comparacion_y_no_hay_ningun_cero()
    {
        $this->poner_seleccion([
            'dolar_cotizacion_origen' => 'blue',
            'dolar_cotizacion_casa'   => 'blue',
            'dolar_cotizacion_punta'  => 'venta',
            'dolar_cotizacion_valor'  => 1540,
            'dolar_variacion_minima'  => 1.00,
        ]);

        $this->fakear_proveedor_caido();

        $json = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertEquals('proveedor_caido', $json['estado']);
        $this->assertNull($json['comparacion']);
        $this->assertEquals([], $json['cotizaciones']);
        $this->assertNotEmpty($json['error']['motivo']);
        $this->assertNotEmpty($json['error']['mensaje']);

        /* El dato local sale igual: la selección guardada no depende de que la API conteste. */
        $this->assertEquals('blue', $json['seleccion_actual']['origen']);
        $this->assertEquals('venta', $json['seleccion_actual']['punta']);
        $this->assertEquals(1540.0, $json['seleccion_actual']['valor']);

        $this->assert_sin_variacion_en_cero($json);
    }

    /**
     * 🔴 Con origen manual la variación se mide contra `dolar_cotizacion_valor` (lo que valía la
     * referencia cuando eligió), NO contra `users.dollar` (lo que él tipeó). Si se midiera contra
     * el número tipeado, el modal le saltaría en el próximo login por una variación que él mismo
     * acaba de crear escribiendo.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function con_origen_manual_se_mide_contra_la_referencia_y_no_contra_el_valor_tipeado()
    {
        $this->poner_seleccion([
            'dollar'                  => 1600,
            'dolar_cotizacion_origen' => 'manual',
            'dolar_cotizacion_casa'   => 'blue',
            'dolar_cotizacion_punta'  => 'venta',
            'dolar_cotizacion_valor'  => 1540,
            'dolar_variacion_minima'  => 1.00,
        ]);

        $this->fakear_blue_venta(1560);

        $json = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertEquals(1540.0, $json['comparacion']['valor_referencia']);
        $this->assertEquals(1.30, $json['comparacion']['variacion_porcentaje']);
        $this->assertEquals(1600.0, $json['valor_dolar_actual']);
    }

    /**
     * Referencia en 0: sin base positiva no hay porcentaje. `comparacion` en null, sin división por
     * cero y sin excepción — nunca un 0% (R11).
     *
     * @group cotizacion-dolar
     * @test
     */
    public function una_referencia_en_cero_no_divide_por_cero_ni_devuelve_cero_por_ciento()
    {
        $this->poner_seleccion([
            'dolar_cotizacion_origen' => 'blue',
            'dolar_cotizacion_casa'   => 'blue',
            'dolar_cotizacion_punta'  => 'venta',
            'dolar_cotizacion_valor'  => 0,
        ]);

        $this->fakear_blue_venta(1560);

        $json = $this->getJson('api/dolar-cotizacion')->assertStatus(200)->json();

        $this->assertEquals('ok', $json['estado']);
        $this->assertNull($json['comparacion']);

        $this->assert_sin_variacion_en_cero($json);
    }

    /**
     * 🔴 EL GET ES LECTURA PURA. Corre en cada login: si escribiera aunque sea `actualizada_at`,
     * el "actualizaste la cotización el …" del modal diría hoy todos los días y el historial se
     * llenaría de filas que nadie pidió.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function el_get_no_escribe_ni_una_columna_ni_una_fila_de_historial()
    {
        $this->poner_seleccion([
            'dolar_cotizacion_origen' => 'blue',
            'dolar_cotizacion_casa'   => 'blue',
            'dolar_cotizacion_punta'  => 'venta',
            'dolar_cotizacion_valor'  => 1540,
        ]);

        $this->fakear_blue_venta(1560);

        $antes             = $this->foto();
        $registros_antes   = DolarCotizacionRegistro::where('user_id', $this->comercio->id)->count();

        $this->getJson('api/dolar-cotizacion')->assertStatus(200);

        $this->assertEquals($antes, $this->foto());
        $this->assertEquals(
            $registros_antes,
            DolarCotizacionRegistro::where('user_id', $this->comercio->id)->count()
        );
    }
}
