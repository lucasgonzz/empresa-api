<?php

namespace Tests\Feature\CotizacionDolar;

use App\Services\Dolar\CotizacionDolarService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión cotizacion-dolar — archivo 2: EL SERVICIO.
 *
 * Acá se prueba la única promesa que sostiene toda la funcionalidad: 🔴 UNA MEDICIÓN QUE FALLA
 * NUNCA DEVUELVE UN VALOR TRANQUILIZADOR. Ni un array vacío que se pueda leer como "no hay
 * novedades", ni dos casas de tres, ni un cero.
 *
 * Los dos que dan sentido al archivo son el de `casas_faltantes` (que un payload incompleto es
 * proveedor caído y no un 'ok' recortado) y el del mapeo `bolsa` -> `mep`, que es el error que
 * dejaría al modal mostrando dos opciones donde se pidieron tres.
 *
 * PHP 7.4 también acá.
 */
class Servicio_de_cotizaciones_Test extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * La caché es de proceso (CACHE_DRIVER=array), así que cada test arranca con una aplicación
         * nueva y el store vacío. El forget explícito es barato y deja escrito que ningún test de
         * este archivo depende de lo que haya dejado el anterior.
         */
        Cache::forget(CotizacionDolarService::CACHE_KEY);
    }

    /**
     * Payload con la forma real de dolarapi (verificada el 20/8/2026): siete casas en producción,
     * el MEP llamándose `bolsa`, y ninguna casa llamada `mep`.
     *
     * @param  array $overrides  Reemplazos por casa, p. ej. ['blue' => ['compra' => 'aa']].
     * @param  array $sacar      Casas a excluir del payload.
     * @return array
     */
    protected function payload(array $overrides = [], array $sacar = [])
    {
        $casas = [
            'oficial'         => ['nombre' => 'Oficial', 'compra' => 1465, 'venta' => 1515],
            'blue'            => ['nombre' => 'Blue', 'compra' => 1540, 'venta' => 1560],
            'bolsa'           => ['nombre' => 'Bolsa', 'compra' => 1523.6, 'venta' => 1523.6],
            'contadoconliqui' => ['nombre' => 'Contado con liqui', 'compra' => 1600, 'venta' => 1610],
            'mayorista'       => ['nombre' => 'Mayorista', 'compra' => 1450, 'venta' => 1455],
            'cripto'          => ['nombre' => 'Cripto', 'compra' => 1570, 'venta' => 1580],
            'tarjeta'         => ['nombre' => 'Tarjeta', 'compra' => 1900, 'venta' => 1900],
        ];

        $items = [];

        foreach ($casas as $casa => $datos) {
            if (in_array($casa, $sacar, true)) {
                continue;
            }

            if (isset($overrides[$casa])) {
                $datos = array_merge($datos, $overrides[$casa]);
            }

            $items[] = [
                'moneda'             => 'USD',
                'casa'               => $casa,
                'nombre'             => $datos['nombre'],
                'compra'             => $datos['compra'],
                'venta'              => $datos['venta'],
                'fechaActualizacion' => '2026-08-20T13:56:00.000Z',
            ];
        }

        return $items;
    }

    /**
     * Deja el proveedor devolviendo el payload que se le pase.
     *
     * @param  mixed $respuesta
     * @return void
     */
    protected function fakear($respuesta)
    {
        Http::fake(['*' => $respuesta]);
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function con_el_payload_real_devuelve_las_tres_casas_y_mapea_bolsa_a_mep()
    {
        $this->fakear(Http::response($this->payload(), 200));

        $resultado = CotizacionDolarService::obtener();

        $this->assertEquals('ok', $resultado['estado']);
        $this->assertNull($resultado['error']);
        $this->assertCount(3, $resultado['cotizaciones']);

        $por_clave = CotizacionDolarService::por_clave($resultado['cotizaciones']);

        $this->assertEquals(['oficial', 'blue', 'mep'], array_keys($por_clave));

        /*
         * 🔴 El corazón del test: la API devuelve `bolsa` y el sistema lo llama `mep`. Un mapeo
         * directo por nombre dejaría el MEP afuera —la casa 'mep' NO EXISTE en dolarapi— y el modal
         * mostraría dos opciones donde se pidieron tres.
         */
        $this->assertEquals('MEP', $por_clave['mep']['nombre']);
        $this->assertEquals(1523.6, $por_clave['mep']['compra']);

        /*
         * Y el MEP viene con compra == venta. Se devuelven las dos puntas tal cual, sin colapsarlas
         * y sin asumir que compra < venta: un chequeo de orden acá sería un falso positivo.
         */
        $this->assertEquals($por_clave['mep']['compra'], $por_clave['mep']['venta']);

        $this->assertEquals('Blue', $por_clave['blue']['nombre']);
        $this->assertEquals(1540.0, $por_clave['blue']['compra']);
        $this->assertEquals(1560.0, $por_clave['blue']['venta']);
    }

    /**
     * `users.dollar` es decimal(10,2): el número que el usuario ve en el modal tiene que ser
     * exactamente el que se va a guardar. Si el redondeo pasara recién en el save(), el modal
     * prometería un número y la base guardaría otro.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function redondea_compra_y_venta_a_dos_decimales()
    {
        $this->fakear(Http::response($this->payload([
            'blue' => ['compra' => 1540.456, 'venta' => 1560.444],
        ]), 200));

        $por_clave = CotizacionDolarService::por_clave(CotizacionDolarService::obtener()['cotizaciones']);

        $this->assertEquals(1540.46, $por_clave['blue']['compra']);
        $this->assertEquals(1560.44, $por_clave['blue']['venta']);
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function un_500_del_proveedor_es_http_error_y_no_un_ok_vacio()
    {
        $this->fakear(Http::response('', 500));

        $resultado = CotizacionDolarService::obtener();

        $this->assertEquals('proveedor_caido', $resultado['estado']);
        $this->assertEquals([], $resultado['cotizaciones']);
        $this->assertEquals('http_error', $resultado['error']['motivo']);
        $this->assertNotEmpty($resultado['error']['mensaje']);
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function una_conexion_caida_es_timeout()
    {
        Http::fake(function () {
            throw new ConnectionException('zz simulacion: cURL error 28, se acabo el timeout');
        });

        $resultado = CotizacionDolarService::obtener();

        $this->assertEquals('proveedor_caido', $resultado['estado']);
        $this->assertEquals([], $resultado['cotizaciones']);
        $this->assertEquals('timeout', $resultado['error']['motivo']);
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function un_body_que_no_es_json_es_payload_invalido()
    {
        $this->fakear(Http::response('esto no es json, es el html de un portal cautivo', 200));

        $resultado = CotizacionDolarService::obtener();

        $this->assertEquals('proveedor_caido', $resultado['estado']);
        $this->assertEquals([], $resultado['cotizaciones']);
        $this->assertEquals('payload_invalido', $resultado['error']['motivo']);
    }

    /**
     * Un "1.540,00" con formato argentino, un null o un texto darían 0 al castear, y un dólar en 0
     * recalcularía el catálogo entero a precio cero.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function una_compra_no_numerica_es_payload_invalido()
    {
        $this->fakear(Http::response($this->payload([
            'blue' => ['compra' => 'mil quinientos cuarenta'],
        ]), 200));

        $resultado = CotizacionDolarService::obtener();

        $this->assertEquals('proveedor_caido', $resultado['estado']);
        $this->assertEquals([], $resultado['cotizaciones']);
        $this->assertEquals('payload_invalido', $resultado['error']['motivo']);
    }

    /**
     * 🔴 EL TEST QUE JUSTIFICA EL ARCHIVO.
     *
     * Faltando UNA de las tres casas, el estado es 'proveedor_caido' y las cotizaciones van VACÍAS.
     * Devolver dos de tres es la versión sutil de la medición que falla y devuelve un valor
     * tranquilizador: el usuario elegiría entre las que hay creyendo que son todas, y se quedaría
     * costeando con el oficial cuando quería el blue.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function sin_la_casa_blue_es_proveedor_caido_y_no_un_ok_con_dos_casas()
    {
        $this->fakear(Http::response($this->payload([], ['blue']), 200));

        $resultado = CotizacionDolarService::obtener();

        $this->assertEquals('proveedor_caido', $resultado['estado']);
        $this->assertEquals([], $resultado['cotizaciones']);
        $this->assertEquals('casas_faltantes', $resultado['error']['motivo']);
    }

    /**
     * La caché saca a la API del camino crítico de los cuarenta logins de la mañana.
     *
     * 🔴 Y ESTO VALE DENTRO DE UN PROCESO, NADA MÁS. El sistema corre con CACHE_DRIVER=array (.env
     * y .env.testing), así que entre dos requests HTTP la caché está siempre vacía: es una
     * optimización, NUNCA una garantía, y ningún camino de código puede depender de ella. Un test
     * que aseverara "la segunda request no salió a la red" estaría midiendo algo que en producción
     * no pasa.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function dos_llamadas_seguidas_en_el_mismo_proceso_pegan_una_sola_vez()
    {
        $this->fakear(Http::response($this->payload(), 200));

        $primera = CotizacionDolarService::obtener();
        $segunda = CotizacionDolarService::obtener();

        $this->assertEquals('ok', $primera['estado']);
        $this->assertEquals($primera, $segunda);

        Http::assertSentCount(1);
    }

    /**
     * 🔴 Los fallos NO se cachean: un hipo de red guardado diez minutos convierte un error de un
     * segundo en diez minutos de "no puedo consultar" para el que aprieta el botón.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function un_fallo_no_se_cachea_y_el_siguiente_intento_sale_de_nuevo()
    {
        $this->fakear(Http::response('', 500));

        $this->assertEquals('proveedor_caido', CotizacionDolarService::obtener()['estado']);
        $this->assertEquals('proveedor_caido', CotizacionDolarService::obtener()['estado']);

        Http::assertSentCount(2);
    }

    /**
     * 🔴 UNA PUNTA EN CERO NO ES UNA COTIZACIÓN. Es el test del bug más caro de esta funcionalidad.
     *
     * `is_numeric(0)` es true, así que la guarda original —que solo pedía `is_numeric`— dejaba
     * pasar un cero derecho hasta `users.dollar`, y de ahí a `ProcessSetFinalPrices`, que
     * recalculaba a precio CERO todos los artículos con costo en dólares. Y después la
     * funcionalidad se apagaba sola: con la referencia en 0, `comparar()` devuelve null y el modal
     * no vuelve a aparecer. El comercio quedaba costeando en cero sin que nada le avisara, y el
     * único síntoma visible era un guioncito en un botón del modal (porque `price(0)` da '-').
     *
     * No es hipotético: dolarapi devuelve `compra: 0` para las casas sin punta compradora y para
     * cualquier casa cuya fuente de arriba falle.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function una_punta_en_cero_o_negativa_es_proveedor_caido_y_no_una_cotizacion_valida()
    {
        $casos = [
            'compra en cero'    => ['blue' => ['compra' => 0]],
            'venta en cero'     => ['blue' => ['venta' => 0]],
            'compra negativa'   => ['blue' => ['compra' => -5]],
            'venta negativa'    => ['blue' => ['venta' => -5]],
            'las dos en cero'   => ['oficial' => ['compra' => 0, 'venta' => 0]],
        ];

        foreach ($casos as $nombre => $override) {

            Cache::forget(CotizacionDolarService::CACHE_KEY);
            $this->fakear(Http::response($this->payload($override), 200));

            $resultado = CotizacionDolarService::obtener();

            $this->assertEquals(
                'proveedor_caido',
                $resultado['estado'],
                'Con ' . $nombre . ' el servicio devolvió una cotización usable.'
            );
            $this->assertEquals('payload_invalido', $resultado['error']['motivo'], $nombre);

            /*
             * Y las cotizaciones van VACÍAS: devolver las otras dos casas dejaría al usuario
             * eligiendo entre las que quedaron, creyendo que son todas.
             */
            $this->assertEquals([], $resultado['cotizaciones'], $nombre);
        }
    }
}
