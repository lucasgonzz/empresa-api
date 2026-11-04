<?php

namespace Tests\Feature\EscaneoFacturaCompra;

use App\Jobs\RunProviderOrderScanJob;
use App\Models\AiTokenUsage;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderScanImage;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;
use Tests\Feature\AgentesIa\OtorgaAgentesIa;

/**
 * Misión escaneo-factura-haiku: el modelo económico lee y el caro relee solo si las cuentas no dan.
 *
 * Lo que protege este archivo es la condición de la que depende el ahorro sin perder calidad:
 *
 * - 🔴 Que una lectura que no cierra NUNCA llegue al usuario si hay una revisión posible. Es el peor
 *   error de esta misión, y falla en silencio: el escaneo termina "listo" con el costo mal.
 * - Que una lectura que sí cierra no gaste la segunda llamada: si el control revisa de más, se pierde
 *   lo que se vino a ahorrar sin que nada se rompa.
 * - Que el usuario no se entere de una primera lectura caída, y que si fallan las dos vea el error.
 *
 * 🔴 SEGURO DE GASTO: el setUp apaga `services.anthropic.api_key`, igual que el archivo 2. Cada test
 * la prende en el mismo paso que registra su `Http::fake()`. Los datos son inventados.
 */
class Lectura_con_revision_Test extends EmpresaTestCase
{
    use OtorgaAgentesIa;

    /**
     * Dueño de los datos (la empresa del fixture).
     *
     * @var \App\Models\User
     */
    protected $owner;

    /**
     * Compra a la que pertenecen los escaneos de este archivo.
     *
     * @var \App\Models\ProviderOrder
     */
    protected $compra;

    /**
     * Rutas absolutas de las fotos escritas en disco por los tests, para borrarlas al terminar.
     *
     * @var array
     */
    protected $archivos_creados = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* 🔴 Nunca la clave real del .env.testing: cada test prende la suya. */
        config(['services.anthropic.api_key' => null]);

        /* Los modelos por defecto del repo, aunque el .env.testing del slot cargue otros. */
        config([
            'services.escaneo_factura_compra.model'          => 'claude-haiku-5-5',
            'services.escaneo_factura_compra.model_revision' => 'claude-sonnet-5-5',
        ]);

        Notification::fake();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->otorgar_agentes_ia($this->owner);

        $this->compra = ProviderOrder::create([
            'provider_id' => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS)->id,
            'user_id'     => $this->owner->id,
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->archivos_creados as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
                @rmdir(dirname($ruta));
            }
        }

        $this->archivos_creados = [];

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     *  Helpers de fixture
     * ------------------------------------------------------------------ */

    /**
     * Crea un escaneo de la compra del fixture, con una foto chica generada con GD.
     *
     * @return \App\Models\ProviderOrderScan
     */
    protected function escaneo_con_foto()
    {
        $scan = ProviderOrderScan::create([
            'uuid'              => Str::uuid()->toString(),
            'user_id'           => $this->owner->id,
            'auth_user_id'      => $this->owner->id,
            'provider_order_id' => $this->compra->id,
            'estado'            => 'pendiente',
            'progreso'          => 0,
        ]);

        $ruta_relativa = 'provider_order_scans/' . $scan->user_id . '/' . $scan->uuid . '/1.jpg';
        $ruta_absoluta = storage_path('app/' . $ruta_relativa);

        if (!is_dir(dirname($ruta_absoluta))) {
            mkdir(dirname($ruta_absoluta), 0777, true);
        }

        $gd = imagecreatetruecolor(120, 90);
        imagefilledrectangle($gd, 0, 0, 119, 89, imagecolorallocate($gd, 235, 235, 235));
        imagejpeg($gd, $ruta_absoluta);
        imagedestroy($gd);

        $this->archivos_creados[] = $ruta_absoluta;

        ProviderOrderScanImage::create([
            'provider_order_scan_id' => $scan->id,
            'provider_order_id'      => $scan->provider_order_id,
            'user_id'                => $scan->user_id,
            'orden'                  => 1,
            'path'                   => $ruta_relativa,
            'mime'                   => 'image/jpeg',
            'bytes'                  => filesize($ruta_absoluta),
            'nombre_original'        => 'pagina1.jpg',
        ]);

        return $scan;
    }

    /**
     * Renglón leído, con los campos que importan para el control.
     *
     * @param  mixed  $cantidad
     * @param  mixed  $costo
     * @param  mixed  $descuento
     * @param  mixed  $total
     * @param  string $codigo  Dos renglones con el mismo código, cantidad y costo cuentan como repetidos
     * @return array
     */
    protected function renglon($cantidad, $costo, $descuento, $total, string $codigo = 'INV-001'): array
    {
        return [
            'fila'                 => 1,
            'pagina'               => 1,
            'bar_code'             => null,
            'codigo_proveedor'     => $codigo,
            'nombre'               => 'ARTICULO INVENTADO',
            'cantidad'             => $cantidad,
            'costo_unitario'       => $costo,
            'descuento_porcentaje' => $descuento,
            'iva_porcentaje'       => 21,
            'total_linea'          => $total,
            'notas'                => null,
            'confianza_fila'       => 0.9,
            'campos_dudosos'       => [],
        ];
    }

    /**
     * JSON de una factura leída con esos renglones.
     *
     * @param  array       $articulos
     * @param  float|null  $neto_gravado
     * @param  array       $cabecera  Campos de la factura a pisar (total, ivas, percepcion_iibb…)
     * @return string
     */
    protected function factura_leida(array $articulos, $neto_gravado = null, array $cabecera = []): string
    {
        return json_encode([
            'columnas_detectadas' => [],
            'articulos'           => $articulos,
            'factura'             => array_merge([
                'es_factura_afip' => true,
                'neto_gravado'    => $neto_gravado,
                'ivas'            => [],
                'campos_dudosos'  => [],
            ], $cabecera),
            'avisos'              => [],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Respuesta de Anthropic a una llamada, identificada con el modelo que se le pidió.
     *
     * @param  string  $modelo
     * @param  string  $texto
     * @param  array   $extras  Claves a pisar (stop_reason, content, usage…)
     * @return array
     */
    protected function respuesta(string $modelo, string $texto, array $extras = []): array
    {
        return array_merge([
            'model'       => $modelo,
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 1000, 'output_tokens' => 500],
        ], $extras);
    }

    /**
     * Prende la clave y responde según el modelo pedido. `$por_modelo[modelo]` es un array para un 200
     * o un entero para un error con ese status.
     *
     * @param  array  $por_modelo
     * @return void
     */
    protected function fake_por_modelo(array $por_modelo)
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Http::fake(function ($request) use ($por_modelo) {
            $modelo = $request->data()['model'];

            /*
             * Un modelo que este test no esperaba responde error, sin assert acá adentro: el
             * `catch (\Exception)` del servicio se lo tragaría. Lo denuncia modelos_llamados().
             */
            $respuesta = array_key_exists($modelo, $por_modelo) ? $por_modelo[$modelo] : 418;

            if (is_int($respuesta)) {
                return Http::response(['error' => ['message' => 'falla de prueba']], $respuesta);
            }

            return Http::response($respuesta, 200);
        });
    }

    /**
     * Corre el job sobre un escaneo y lo devuelve refrescado.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return \App\Models\ProviderOrderScan
     */
    protected function correr_el_job(ProviderOrderScan $scan)
    {
        (new RunProviderOrderScanJob($scan->id))->handle();

        return $scan->fresh();
    }

    /**
     * Modelos pedidos a la API, en orden.
     *
     * @return array
     */
    protected function modelos_llamados(): array
    {
        $modelos = [];

        foreach (Http::recorded() as $par) {
            $modelos[] = $par[0]->data()['model'];
        }

        return $modelos;
    }

    /* ------------------------------------------------------------------ *
     *  La lectura buena: una sola llamada
     * ------------------------------------------------------------------ */

    /**
     * Una lectura que cierra termina ahí: una llamada, con Haiku. Si esto da dos, la misión no ahorra
     * nada y ningún otro test lo nota, porque el resultado sale igual de bien.
     *
     * @test
     * @return void
     */
    public function una_lectura_que_cierra_hace_una_sola_llamada_con_el_modelo_economico()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida([
                $this->renglon(12, 2450.5, 10, 26465.4),
            ])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertSame(['claude-haiku-5-5'], $this->modelos_llamados());

        $this->assertSame('claude-haiku-5-5', $scan->resultado['lectura']['modelo']);
        $this->assertFalse($scan->resultado['lectura']['revisada']);
        $this->assertNull($scan->resultado['lectura']['motivo_revision']);
    }

    /**
     * Un `.env` con otro modelo sigue ganando, en las dos posiciones. Es la salida prevista para un
     * cliente puntual, sin tocar código.
     *
     * @test
     * @return void
     */
    public function el_modelo_del_env_gana_en_las_dos_posiciones()
    {
        config([
            'services.escaneo_factura_compra.model'          => 'claude-modelo-a',
            'services.escaneo_factura_compra.model_revision' => 'claude-modelo-b',
        ]);

        $this->fake_por_modelo([
            'claude-modelo-a' => 500,
            'claude-modelo-b' => $this->respuesta('claude-modelo-b', $this->factura_leida([
                $this->renglon(1, 100, null, 100),
            ])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertSame(['claude-modelo-a', 'claude-modelo-b'], $this->modelos_llamados());
    }

    /**
     * Los defaults del repo son los de la medición: si cambian, los números de la misión dejan de
     * valer y hay que medir de nuevo. Se lee del archivo porque el .env.testing puede pisar el config.
     *
     * @test
     * @return void
     */
    public function los_modelos_por_defecto_son_los_medidos()
    {
        $archivo = file_get_contents(base_path('config/services.php'));

        $this->assertStringContainsString("env('ESCANEO_FACTURA_MODEL', 'claude-haiku-5-5')", $archivo);
        $this->assertStringContainsString("env('ESCANEO_FACTURA_MODEL_REVISION', 'claude-sonnet-5-5')", $archivo);

        /* Si config() viene vacío, el fallback del servicio tiene que decir lo mismo. */
        config([
            'services.escaneo_factura_compra.model'          => '',
            'services.escaneo_factura_compra.model_revision' => '',
        ]);

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => 500,
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([
                $this->renglon(1, 100, null, 100),
            ])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5'], $this->modelos_llamados());
        $this->assertEquals('listo', $scan->estado);
    }

    /* ------------------------------------------------------------------ *
     *  🔴 Las cuentas que no dan
     * ------------------------------------------------------------------ */

    /**
     * 🔴 El caso que motivó todo: bonificación 50 % + 15 % leída como 50 %. Sin el control, el costo
     * entra un 15 % más caro a la cuenta corriente del proveedor y el escaneo termina "listo".
     *
     * Gana Sonnet entero, sin mezclar: ni el nombre de Haiku queda en el renglón.
     *
     * @test
     * @return void
     */
    public function una_bonificacion_doble_mal_leida_hace_releer_y_gana_la_revision()
    {
        $mal_leida = $this->renglon(10, 1000, 50, 4250);
        $mal_leida['nombre'] = 'NOMBRE DE LA PRIMERA LECTURA';

        $bien_leida = $this->renglon(10, 1000, 57.5, 4250);
        $bien_leida['nombre'] = 'NOMBRE DE LA REVISION';

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$mal_leida])),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$bien_leida])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5'], $this->modelos_llamados());

        $this->assertEquals(57.5, $scan->resultado['articulos'][0]['descuento_porcentaje']);
        $this->assertSame('NOMBRE DE LA REVISION', $scan->resultado['articulos'][0]['nombre']);

        $this->assertSame('claude-sonnet-5-5', $scan->resultado['lectura']['modelo']);
        $this->assertSame('claude-haiku-5-5', $scan->resultado['lectura']['primer_modelo']);
        $this->assertTrue($scan->resultado['lectura']['revisada']);
        $this->assertSame('renglon_no_cierra', $scan->resultado['lectura']['motivo_revision']);
    }

    /** @test */
    public function evita_volver_a_descontar_un_unitario_ya_bonificado()
    {
        $articulos = [
            $this->renglon(5, 10.21, 20, 51.04, 'A000008461'),
            $this->renglon(5, 3.88, 40, 19.41, 'A000006368'),
            $this->renglon(2, 50, 20, 80, 'DESCUENTO-GENUINO'),
            $this->renglon(1, 100, 1, 100, 'DESCUENTO-AMBIGUO'),
            $this->renglon(1, 50, 20, null, 'SIN-TOTAL-IMPRESO'),
        ];
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida($articulos)),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('listo', $scan->estado);
        $this->assertSame(['claude-haiku-5-5'], $this->modelos_llamados());
        $this->assertSame([null, null, 20.0, 1.0, 20.0], array_map(function ($articulo) {
            return $articulo['descuento_porcentaje'] === null ? null : (float) $articulo['descuento_porcentaje'];
        }, $scan->resultado['articulos']));
        $this->assertEquals(51.04, $scan->resultado['articulos'][0]['total_linea']);
        $this->assertEquals(19.41, $scan->resultado['articulos'][1]['total_linea']);
    }

    /** @test */
    public function conserva_la_revision_si_el_unitario_ya_bonificado_es_dudoso()
    {
        $renglon = $this->renglon(5, 10.21, 20, 51.04);
        $renglon['campos_dudosos'] = ['costo_unitario'];
        $factura = $this->factura_leida([$renglon]);
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $factura),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $factura),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('listo', $scan->estado);
        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5'], $this->modelos_llamados());
        $this->assertEquals(20, $scan->resultado['articulos'][0]['descuento_porcentaje']);
        $this->assertContains('costo_unitario', $scan->resultado['articulos'][0]['campos_dudosos']);
    }

    /**
     * Un solo renglón que no cierra entre varios alcanza para revisar: la factura se relee entera,
     * porque el costo mal de un renglón es el que termina en la cuenta corriente.
     *
     * @test
     * @return void
     */
    public function un_solo_renglon_que_no_cierra_entre_varios_hace_releer()
    {
        $bien = $this->renglon(2, 100, null, 200);

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$bien, $bien, $this->renglon(1, 500, null, 300), $bien])),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$bien])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('renglon_no_cierra', $scan->resultado['lectura']['motivo_revision']);
    }

    /* ------------------------------------------------------------------ *
     *  Tolerancia: no se paga una segunda lectura por centavos
     * ------------------------------------------------------------------ */

    /**
     * Unitario redondeado a dos decimales y cantidades decimales: la cuenta nunca da exacta. Si esto
     * revisara, mandaría a revisar casi todas las facturas sin un solo error de por medio.
     *
     * @test
     * @dataProvider renglones_dentro_de_la_tolerancia
     * @return void
     */
    public function no_revisa_por_redondeos($cantidad, $costo, $descuento, $total)
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida([
                $this->renglon($cantidad, $costo, $descuento, $total),
            ])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame(['claude-haiku-5-5'], $this->modelos_llamados());
        $this->assertFalse($scan->resultado['lectura']['revisada']);
    }

    /**
     * @return array
     */
    public function renglones_dentro_de_la_tolerancia(): array
    {
        return [
            'centavos de redondeo'                => [3, 33.33, null, 100],
            'justo 2 pesos, en un total chico'    => [1, 100, null, 102],
            'el 1 % de un total grande'           => [1, 100000, null, 100900],
            'cantidad decimal'                    => [1.75, 1234.56, null, 2160.48],
            'descuento null es sin descuento'     => [4, 250, null, 1000],
            'descuento decimal'                   => [2, 1000, 12.5, 1750],
            'centavos en un total de dólares'     => [3, 0.33, null, 1.00],
            'unitario redondeado por mucha cantidad' => [1000, 0.13, null, 125],
        ];
    }

    /**
     * 🔴 El piso de la tolerancia no puede comerse un total chico. En una factura en dólares, un costo
     * de 3,50 leído 5,00 es un 43 % de error, y con un piso fijo de 2 pasaba como redondeo.
     *
     * @test
     * @return void
     */
    public function un_error_grande_en_un_total_chico_hace_releer()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(1, 5.00, null, 3.50)])),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(1, 3.50, null, 3.50)])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('renglon_no_cierra', $scan->resultado['lectura']['motivo_revision']);
        $this->assertEquals(3.50, $scan->resultado['articulos'][0]['costo_unitario']);
    }

    /**
     * Y por afuera de la tolerancia sí revisa: 2,01 pesos en un total chico ya no son centavos, y el 1 %
     * no le da margen a un total de cien.
     *
     * @test
     * @return void
     */
    public function revisa_apenas_se_pasa_de_la_tolerancia()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(1, 100, null, 102.01)])),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(1, 102.01, null, 102.01)])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('renglon_no_cierra', $scan->resultado['lectura']['motivo_revision']);
    }

    /* ------------------------------------------------------------------ *
     *  Sin con qué hacer la cuenta
     * ------------------------------------------------------------------ */

    /**
     * Más de la mitad de los renglones sin total: el control no está controlando nada, así que no se
     * puede dar la lectura por buena.
     *
     * @test
     * @return void
     */
    public function mas_de_la_mitad_sin_total_hace_releer()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([
                $this->renglon(1, 100, null, null),
                $this->renglon(2, 100, null, null),
                $this->renglon(3, 100, null, 300),
            ])),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(3, 100, null, 300)])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('renglones_sin_datos', $scan->resultado['lectura']['motivo_revision']);
    }

    /**
     * Sin costo es lo mismo que sin total: tampoco hay cuenta.
     *
     * @test
     * @return void
     */
    public function mas_de_la_mitad_sin_costo_hace_releer()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([
                $this->renglon(1, null, null, 100),
                $this->renglon(2, null, null, 200),
            ])),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(1, 100, null, 100)])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('renglones_sin_datos', $scan->resultado['lectura']['motivo_revision']);
    }

    /**
     * Justo la mitad sin datos no revisa: el plan dice "más de la mitad", y un remito con algún renglón
     * sin precio no tiene por qué pagar la segunda lectura si el resto cierra.
     *
     * @test
     * @return void
     */
    public function justo_la_mitad_sin_datos_no_revisa()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida([
                $this->renglon(1, 100, null, null, 'INV-001'),
                $this->renglon(1, 100, null, 100, 'INV-002'),
            ])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame(['claude-haiku-5-5'], $this->modelos_llamados());
        $this->assertFalse($scan->resultado['lectura']['revisada']);
    }

    /**
     * Sin artículos: puede ser una foto de otra cosa, pero también una lectura que no vio la tabla.
     *
     * @test
     * @return void
     */
    public function sin_articulos_hace_releer()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([])),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5'], $this->modelos_llamados());
        $this->assertSame('sin_articulos', $scan->resultado['lectura']['motivo_revision']);

        /* Un comprobante sin tabla no es un error: sigue siendo un resultado. */
        $this->assertEquals('listo', $scan->estado);
    }

    /* ------------------------------------------------------------------ *
     *  La suma contra el neto: faltan renglones
     * ------------------------------------------------------------------ */

    /**
     * Los renglones cierran uno por uno pero suman menos que el neto: falta alguno. Es lo único que la
     * cuenta por renglón no puede ver.
     *
     * @test
     * @return void
     */
    public function una_suma_menor_que_el_neto_hace_releer()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(1, 1000, null, 1000)], 2000)),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(2, 1000, null, 2000)], 2000)),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('faltan_renglones', $scan->resultado['lectura']['motivo_revision']);
    }

    /**
     * 🔴 Sin neto, el renglón perdido en un corte se ve igual contra `total − percepciones`. Un remito o
     * una factura C no traen neto, y antes ahí el control se apagaba justo donde nada más lo cubría.
     *
     * @test
     * @dataProvider cabeceras_sin_neto_con_renglones_faltantes
     * @param  array  $cabecera
     * @return void
     */
    public function sin_neto_un_renglon_perdido_se_ve_contra_el_total(array $cabecera)
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(1, 500, null, 500)], null, $cabecera)),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(2, 500, null, 1000)], null, $cabecera)),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('faltan_renglones', $scan->resultado['lectura']['motivo_revision']);
    }

    /**
     * @return array
     */
    public function cabeceras_sin_neto_con_renglones_faltantes(): array
    {
        return [
            'solo total'               => [['total' => 1000]],
            'total con percepción'     => [['total' => 1100, 'percepcion_iibb' => 100]],
            'total con IVA discriminado' => [['total' => 1210, 'total_iva' => 210]],
        ];
    }

    /**
     * La otra mitad: con los renglones completos no revisa, ni con percepciones ni con IVA aparte. Si no
     * se descontaran, cada factura con una percepción pagaría la segunda lectura sin motivo.
     *
     * @test
     * @dataProvider cabeceras_sin_neto_que_cierran
     * @param  array  $cabecera
     * @return void
     */
    public function sin_neto_con_los_renglones_completos_no_revisa(array $cabecera)
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(2, 500, null, 1000)], null, $cabecera)),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame(['claude-haiku-5-5'], $this->modelos_llamados());
        $this->assertFalse($scan->resultado['lectura']['revisada']);
    }

    /**
     * @return array
     */
    public function cabeceras_sin_neto_que_cierran(): array
    {
        return [
            'solo total'                 => [['total' => 1000]],
            'total con percepción'       => [['total' => 1100, 'percepcion_iibb' => 100]],
            'total con IVA discriminado' => [['total' => 1210, 'total_iva' => 210]],
        ];
    }

    /**
     * Un renglón sin total no apaga el control: se estima con su propia cuenta. Si no, alcanzaba un solo
     * total ilegible para que no se viera un renglón perdido en toda la factura.
     *
     * @test
     * @return void
     */
    public function un_renglon_sin_total_se_estima_y_el_control_sigue_prendido()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([
                $this->renglon(1, 1000, null, null, 'INV-001'),
                $this->renglon(1, 500, null, 500, 'INV-002'),
            ], 3000)),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(3, 1000, null, 3000)], 3000)),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('faltan_renglones', $scan->resultado['lectura']['motivo_revision']);
    }

    /**
     * Y la estimación no da falsas alarmas: 1000 estimado más 500 impreso es el neto de 1500.
     *
     * @test
     * @return void
     */
    public function un_renglon_sin_total_bien_estimado_no_revisa()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida([
                $this->renglon(1, 1000, null, null, 'INV-001'),
                $this->renglon(1, 500, null, 500, 'INV-002'),
            ], 1500)),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame(['claude-haiku-5-5'], $this->modelos_llamados());
    }

    /**
     * 🔴 Factura B con neto leído: los renglones traen el IVA adentro, así que su suma queda un 21 % arriba
     * del neto y tapa un renglón perdido. Se compara contra el total, que está en la misma base.
     *
     * @test
     * @return void
     */
    public function en_una_factura_b_un_renglon_perdido_no_lo_tapa_el_iva()
    {
        $cabecera = ['tipo_comprobante' => 'B', 'total' => 1210];

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(1, 1100, null, 1100)], 1000, $cabecera)),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(1, 1210, null, 1210)], 1000, $cabecera)),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('faltan_renglones', $scan->resultado['lectura']['motivo_revision']);
    }

    /**
     * Y una factura B completa no revisa, aunque la suma de sus renglones esté arriba del neto.
     *
     * @test
     * @return void
     */
    public function una_factura_b_completa_no_revisa()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida(
                [$this->renglon(1, 1210, null, 1210)],
                1000,
                ['tipo_comprobante' => 'B', 'total' => 1210]
            )),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame(['claude-haiku-5-5'], $this->modelos_llamados());
    }

    /* ------------------------------------------------------------------ *
     *  🔴 Dos lecturas que no cierran
     * ------------------------------------------------------------------ */

    /**
     * 🔴 Si Sonnet también lee mal la bonificación doble, el escaneo sigue "listo": hay datos para revisar a
     * mano. Pero no en silencio. Va un aviso en `avisos`, que la pantalla de revisión muestra, y los
     * renglones que no cierran quedan en `campos_dudosos`, solo esos. Y no hay una tercera lectura.
     *
     * @test
     * @return void
     */
    public function si_la_revision_tampoco_cierra_queda_listo_con_aviso_y_campos_dudosos()
    {
        $bien = $this->renglon(2, 100, null, 200, 'INV-001');
        $bien['fila'] = 1;

        $mal = $this->renglon(10, 1000, 50, 4250, 'INV-002');
        $mal['fila'] = 2;

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$bien, $mal])),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$bien, $mal])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5'], $this->modelos_llamados());
        $this->assertSame('renglon_no_cierra', $scan->resultado['lectura']['sigue_sin_cerrar']);

        $avisos = implode(' | ', $scan->resultado['avisos']);

        $this->assertStringContainsString('fila 2', $avisos, 'El aviso tiene que decir qué renglón mirar.');
        $this->assertStringContainsString('no cierra', $avisos);

        foreach (['cantidad', 'costo_unitario', 'descuento_porcentaje'] as $campo) {
            $this->assertContains($campo, $scan->resultado['articulos'][1]['campos_dudosos']);
        }

        $this->assertSame([], $scan->resultado['articulos'][0]['campos_dudosos'], 'El renglón que cierra no se marca: si todo es dudoso, nada lo es.');
    }

    /**
     * Si la revisión sí cierra no hay nada que avisar: la primera lectura mala queda atrás.
     *
     * @test
     * @return void
     */
    public function si_la_revision_cierra_no_hay_aviso()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(10, 1000, 50, 4250)])),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(10, 1000, 57.5, 4250)])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertNull($scan->resultado['lectura']['sigue_sin_cerrar']);
        $this->assertSame([], $scan->resultado['avisos']);
        $this->assertSame([], $scan->resultado['articulos'][0]['campos_dudosos']);
    }

    /**
     * Cuando la revisión también deja renglones afuera, se avisa cuánto falta.
     *
     * @test
     * @return void
     */
    public function si_tambien_faltan_renglones_en_la_revision_va_un_aviso()
    {
        $leida = $this->factura_leida([$this->renglon(1, 1000, null, 1000)], 2000);

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $leida),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $leida),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertSame('faltan_renglones', $scan->resultado['lectura']['sigue_sin_cerrar']);
        $this->assertStringContainsString('puede faltar', implode(' | ', $scan->resultado['avisos']));
    }

    /* ------------------------------------------------------------------ *
     *  🔴 El renglón repetido por el solape
     * ------------------------------------------------------------------ */

    /**
     * 🔴 Dos filas iguales cierran cada una su cuenta y la suma mayor al neto pasa. Al confirmar, la compra
     * guarda una sola y el stock se mueve por cada fila: stock ×2 y deuda ×1, sin que nada avise.
     *
     * @test
     * @return void
     */
    public function un_renglon_repetido_hace_releer_y_gana_la_lectura_sin_repetir()
    {
        $renglon = $this->renglon(2, 100, null, 200);

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$renglon, $renglon], 200)),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$renglon], 200)),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('renglon_repetido', $scan->resultado['lectura']['motivo_revision']);
        $this->assertCount(1, $scan->resultado['articulos']);
        $this->assertSame([], $scan->resultado['avisos']);
    }

    /**
     * Si la revisión también lo trae, puede ser real: una factura puede listar dos veces lo mismo. No se
     * saca solo, se avisa, y lo decide quien tiene el papel adelante.
     *
     * @test
     * @return void
     */
    public function si_la_revision_tambien_trae_el_repetido_va_un_aviso_y_no_se_saca()
    {
        $primero = $this->renglon(2, 100, null, 200);
        $primero['fila'] = 3;

        $segundo = $this->renglon(2, 100, null, 200);
        $segundo['fila'] = 4;

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$primero, $segundo])),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$primero, $segundo])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertCount(2, $scan->resultado['articulos'], 'No se saca solo: puede ser real.');
        $this->assertSame('renglon_repetido', $scan->resultado['lectura']['sigue_sin_cerrar']);

        $avisos = implode(' | ', $scan->resultado['avisos']);

        $this->assertStringContainsString('filas 3 y 4', $avisos);
        $this->assertStringContainsString('stock', $avisos);
        $this->assertContains('cantidad', $scan->resultado['articulos'][1]['campos_dudosos']);
    }

    /**
     * Mismo código con otra cantidad u otro costo no es un repetido: es una compra normal.
     *
     * @test
     * @return void
     */
    public function mismo_codigo_con_otra_cantidad_o_costo_no_es_repetido()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida([
                $this->renglon(2, 100, null, 200),
                $this->renglon(3, 100, null, 300),
                $this->renglon(2, 150, null, 300),
            ])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame(['claude-haiku-5-5'], $this->modelos_llamados());
    }

    /**
     * Sin código, el repetido se reconoce por el nombre: hay facturas que no traen códigos.
     *
     * @test
     * @return void
     */
    public function sin_codigo_el_repetido_se_reconoce_por_el_nombre()
    {
        $renglon = $this->renglon(2, 100, null, 200);
        $renglon['codigo_proveedor'] = null;

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$renglon, $renglon])),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$renglon])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('renglon_repetido', $scan->resultado['lectura']['motivo_revision']);
    }

    /* ------------------------------------------------------------------ *
     *  🔴 La cabecera: el total contra su desglose
     * ------------------------------------------------------------------ */

    /**
     * Cabecera que cierra o no según la percepción, que es justo lo que se mide que se pierde: una línea
     * "Regímenes Especiales" al pie que el modelo a veces no toma como percepción.
     *
     * @param  float|null  $percepcion
     * @return array
     */
    protected function cabecera_con_percepcion($percepcion): array
    {
        return [
            'tipo_comprobante' => 'A',
            'total_iva'        => 210,
            'total'            => 1250,
            'ivas'             => [['porcentaje' => 21, 'neto' => 1000, 'importe' => 210]],
            'percepcion_iibb'  => $percepcion,
        ];
    }

    /**
     * 🔴 Una percepción perdida mueve la deuda: el total que se guarda sale del desglose más las
     * percepciones. Si el total impreso no cierra, se relee, y si la revisión la trae, gana.
     *
     * @test
     * @return void
     */
    public function un_total_que_no_cuadra_hace_releer()
    {
        $renglones = [$this->renglon(10, 100, null, 1000)];

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida($renglones, 1000, $this->cabecera_con_percepcion(null))),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida($renglones, 1000, $this->cabecera_con_percepcion(40))),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame('total_no_cuadra', $scan->resultado['lectura']['motivo_revision']);
        $this->assertNull($scan->resultado['lectura']['sigue_sin_cerrar']);
        $this->assertEquals(40, $scan->resultado['factura']['percepcion_iibb']);
        $this->assertStringNotContainsString('no coincide', implode(' | ', $scan->resultado['avisos']));
    }

    /**
     * Si la revisión tampoco lo cierra, queda el aviso de siempre con el total marcado, una sola vez: es
     * el mismo que ya mostraba la pantalla de revisión.
     *
     * @test
     * @return void
     */
    public function si_la_revision_tampoco_cuadra_el_total_queda_el_aviso_una_sola_vez()
    {
        $leida = $this->factura_leida([$this->renglon(10, 100, null, 1000)], 1000, $this->cabecera_con_percepcion(null));

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $leida),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $leida),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertSame('total_no_cuadra', $scan->resultado['lectura']['sigue_sin_cerrar']);
        $this->assertContains('total', $scan->resultado['factura']['campos_dudosos']);
        $this->assertSame(1, substr_count(implode(' | ', $scan->resultado['avisos']), 'no coincide'));
    }

    /**
     * Un total que cierra, o una factura sin desglose, no piden segunda lectura.
     *
     * @test
     * @dataProvider cabeceras_que_no_revisan_por_el_total
     * @param  array  $cabecera
     * @return void
     */
    public function un_total_que_cuadra_o_sin_desglose_no_revisa(array $cabecera)
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(10, 100, null, 1000)], 1000, $cabecera)),
        ]);

        $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame(['claude-haiku-5-5'], $this->modelos_llamados());
    }

    /**
     * @return array
     */
    public function cabeceras_que_no_revisan_por_el_total(): array
    {
        return [
            'cierra con la percepción' => [array_merge(['tipo_comprobante' => 'A', 'total' => 1250], ['ivas' => [['porcentaje' => 21, 'neto' => 1000, 'importe' => 210]], 'percepcion_iibb' => 40])],
            'medio peso de redondeo'   => [['tipo_comprobante' => 'A', 'total' => 1210.4, 'ivas' => [['porcentaje' => 21, 'neto' => 1000, 'importe' => 210]]]],
            'sin desglose'             => [['tipo_comprobante' => 'A', 'total' => 1250]],
        ];
    }

    /**
     * 🔴 El prompt pide que ninguna línea del pie quede afuera. La causa medida de los totales que no
     * cuadran es una percepción rotulada "Regímenes Especiales" que tanto Haiku como Sonnet 5.5 pierden:
     * el modelo no la reconoce como percepción, y el total que se guarda sale de lo que leyó.
     *
     * También que el control pida no inventar, y que la regla anti-complacencia siga siendo la última:
     * un modelo al que se le pide que la cuenta cierre puede inflar un importe para que cierre.
     *
     * @test
     * @return void
     */
    public function el_prompt_pide_que_ninguna_linea_del_pie_quede_afuera()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(1, 100, null, 100)])),
        ]);

        $this->correr_el_job($this->escaneo_con_foto());

        $prompt = Http::recorded()->first()[0]->data()['system'];

        foreach (['Regímenes Especiales', 'Perc. IIBB', 'Percepción IVA', 'Imp. Internos', '"Ret."'] as $rotulo) {
            $this->assertStringContainsString($rotulo, $prompt);
        }

        $this->assertStringContainsString('No puede quedar afuera', $prompt);
        $this->assertStringContainsString('todo lo demás, en "percepcion_iibb"', $prompt);
        $this->assertStringContainsString('tiene que dar el "total" impreso', $prompt);
        $this->assertStringContainsString('NO inventes ni ajustes ningún valor para que cierre', $prompt);
        $this->assertStringContainsString('"avisos" que no cierra', $prompt);

        /* El neto no es una percepción: sin esta salvedad, "toda línea que suma" también lo incluye. */
        $this->assertStringContainsString('no sea el neto (gravado, exento o no gravado) ni el IVA', $prompt);

        $this->assertGreaterThan(
            strpos($prompt, 'No puede quedar afuera'),
            strpos($prompt, 'REGLA ANTI-COMPLACENCIA'),
            'La regla anti-complacencia tiene que seguir siendo la última.'
        );
    }

    /* ------------------------------------------------------------------ *
     *  El rastro en el log
     * ------------------------------------------------------------------ */

    /**
     * 🔴 El piso de log de casi todas las instalaciones es `warning`: en `info`, el rastro de una factura
     * revisada no se escribía y no había con qué medir cuántas se revisan.
     *
     * @test
     * @return void
     */
    public function una_factura_revisada_deja_rastro_en_warning()
    {
        Log::spy();

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(10, 1000, 50, 4250)])),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(10, 1000, 57.5, 4250)])),
        ]);

        $this->correr_el_job($this->escaneo_con_foto());

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'lectura terminada') !== false && $contexto['motivo_revision'] === 'renglon_no_cierra';
        });
    }

    /**
     * Una lectura que pasó de una no ensucia el log de producción: queda en `info`.
     *
     * @test
     * @return void
     */
    public function una_factura_sin_revision_deja_rastro_en_info()
    {
        Log::spy();

        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(1, 100, null, 100)])),
        ]);

        $this->correr_el_job($this->escaneo_con_foto());

        Log::shouldHaveReceived('info')->withArgs(function ($mensaje) {
            return strpos($mensaje, 'lectura terminada') !== false;
        });

        Log::shouldNotHaveReceived('warning', [\Mockery::pattern('/lectura terminada/'), \Mockery::any()]);
    }

    /**
     * 🔴 La respuesta cruda trae datos de la factura de un cliente: no va al log, con ninguno de los dos
     * errores de parseo. Alcanza con el error y el largo para diagnosticar.
     *
     * @test
     * @dataProvider respuestas_que_no_se_pueden_parsear
     * @param  string  $texto
     * @return void
     */
    public function el_log_de_un_json_invalido_no_trae_la_respuesta_cruda(string $texto)
    {
        Log::spy();

        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $texto),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(1, 100, null, 100)])),
        ]);

        $this->correr_el_job($this->escaneo_con_foto());

        Log::shouldHaveReceived('error')->withArgs(function ($mensaje, $contexto = []) use ($texto) {
            return strpos($mensaje, 'EscaneoFacturaCompraService') !== false
                && !array_key_exists('respuesta_cruda', $contexto)
                && $contexto['largo'] === mb_strlen($texto)
                && strpos(json_encode($contexto, JSON_UNESCAPED_UNICODE), 'CUIT SECRETO') === false;
        });
    }

    /**
     * @return array
     */
    public function respuestas_que_no_se_pueden_parsear(): array
    {
        return [
            'sin objeto JSON' => ['La factura es de CUIT SECRETO y no la pude leer'],
            'JSON inválido'   => ['{"nombre": "CUIT SECRETO \\Z"}'],
        ];
    }

    /**
     * 🔴 Una suma MAYOR que el neto no revisa: es una bonificación de pie, que baja el neto y no toca
     * los renglones. Medido: dos de siete facturas A lo traen, hasta un 18 %, en lecturas correctas.
     * Si esto revisara, una de cada cinco facturas pagaría la segunda lectura sin ningún error.
     *
     * @test
     * @return void
     */
    public function una_suma_mayor_que_el_neto_por_bonificacion_de_pie_no_revisa()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(1, 1000, null, 1000)], 850)),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame(['claude-haiku-5-5'], $this->modelos_llamados());
        $this->assertFalse($scan->resultado['lectura']['revisada']);
    }

    /**
     * Dentro del 1 % tampoco revisa, y sin neto ni total no hay con qué comparar. Sin neto pero con total
     * sí hay: ver sin_neto_un_renglon_perdido_se_ve_contra_el_total().
     *
     * @test
     * @dataProvider netos_que_no_revisan
     * @param  mixed  $neto
     * @return void
     */
    public function dentro_del_uno_por_ciento_o_sin_neto_no_revisa($neto)
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(1, 1000, null, 1000)], $neto)),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertSame(['claude-haiku-5-5'], $this->modelos_llamados());
        $this->assertFalse($scan->resultado['lectura']['revisada']);
    }

    /**
     * @return array
     */
    public function netos_que_no_revisan(): array
    {
        return [
            'dentro del 1 %'  => [1005],
            'sin neto ni total' => [null],
        ];
    }

    /* ------------------------------------------------------------------ *
     *  Lecturas que fallan
     * ------------------------------------------------------------------ */

    /**
     * El JSON roto pasó 1 vez en 30 (`Invalid \escape`). El usuario no se tiene que enterar.
     *
     * @test
     * @return void
     */
    public function un_json_roto_hace_releer_y_el_usuario_no_ve_error()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', '{"articulos": [{"nombre": "A\\Z"}]}'),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(1, 100, null, 100)])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertNull($scan->error);
        $this->assertSame('respuesta_invalida', $scan->resultado['lectura']['motivo_revision']);
    }

    /**
     * Un 500 del modelo económico no le llega al usuario: lo salva la revisión.
     *
     * @test
     * @return void
     */
    public function un_500_de_la_primera_lectura_hace_releer_y_el_usuario_no_ve_error()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => 500,
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(1, 100, null, 100)])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertNull($scan->error);
        $this->assertSame('error_de_la_api', $scan->resultado['lectura']['motivo_revision']);
        $this->assertSame('claude-sonnet-5-5', $scan->resultado['lectura']['modelo']);
    }

    /**
     * Cortada por `max_tokens`: aunque el texto decodifique, le pueden faltar renglones. No se confía.
     *
     * @test
     * @return void
     */
    public function una_lectura_cortada_por_max_tokens_hace_releer_aunque_el_json_decodifique()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(1, 100, null, 100)]), ['stop_reason' => 'max_tokens']),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(2, 100, null, 200)])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertSame('corte_por_max_tokens', $scan->resultado['lectura']['motivo_revision']);
        $this->assertEquals(2, $scan->resultado['articulos'][0]['cantidad']);
    }

    /**
     * `stop_reason: refusal` llega con un 200 y es una lectura fallida. Haiku 5.5 no tiene fallback de
     * servidor, así que si no se revisara acá el usuario se quedaría con un error evitable.
     *
     * @test
     * @return void
     */
    public function un_rechazo_del_modelo_hace_releer()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', '', ['stop_reason' => 'refusal', 'content' => []]),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(1, 100, null, 100)])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertSame('rechazo_del_modelo', $scan->resultado['lectura']['motivo_revision']);
    }

    /**
     * Fallan las dos: el error de siempre, y con aviso. No un escaneo colgado ni uno vacío.
     *
     * @test
     * @return void
     */
    public function si_fallan_las_dos_lecturas_el_usuario_ve_el_error_de_siempre()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => 500,
            'claude-sonnet-5-5' => 500,
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('error', $scan->estado);
        $this->assertStringContainsString('El servicio de IA respondió con un error al leer la factura', (string) $scan->error);
        $this->assertNull($scan->resultado);
    }

    /**
     * 🔴 La primera lectura trajo datos que no cierran y la revisión falló: va el error, no los datos.
     * Ya se sabe que esa lectura está mal, y el usuario la confirmaría sin darse cuenta.
     *
     * @test
     * @return void
     */
    public function si_la_revision_falla_no_se_muestra_la_lectura_que_no_cerraba()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(10, 1000, 50, 4250)])),
            'claude-sonnet-5-5' => 500,
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('error', $scan->estado);
        $this->assertNull($scan->resultado);
    }

    /* ------------------------------------------------------------------ *
     *  Consumo y forma de la llamada
     * ------------------------------------------------------------------ */

    /**
     * Las dos lecturas se pagan y quedan registradas, cada una con su modelo y en el mismo proceso. Sin
     * esto, una factura revisada parecería costar lo mismo que una que no: justo el número que hay que
     * mirar para saber si la misión ahorra.
     *
     * @test
     * @return void
     */
    public function registra_los_tokens_de_las_dos_lecturas_cada_una_con_su_modelo()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', $this->factura_leida([$this->renglon(10, 1000, 50, 4250)]), ['usage' => ['input_tokens' => 1000, 'output_tokens' => 400]]),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(10, 1000, 57.5, 4250)]), ['usage' => ['input_tokens' => 1100, 'output_tokens' => 600]]),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $consumos = AiTokenUsage::where('proceso', 'escaneo_factura_compra')
            ->where('referencia_id', $scan->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $consumos);

        $this->assertSame('claude-haiku-5-5', $consumos[0]->modelo);
        $this->assertEquals(1000, $consumos[0]->input_tokens);
        $this->assertEquals(400, $consumos[0]->output_tokens);

        $this->assertSame('claude-sonnet-5-5', $consumos[1]->modelo);
        $this->assertEquals(1100, $consumos[1]->input_tokens);
        $this->assertEquals(600, $consumos[1]->output_tokens);

        /* En el resultado queda lo que costó el escaneo entero. */
        $this->assertEquals(2100, $scan->resultado['tokens']['input']);
        $this->assertEquals(1000, $scan->resultado['tokens']['output']);
    }

    /**
     * Una lectura rota también se paga: su fila tiene que existir aunque no haya servido.
     *
     * @test
     * @return void
     */
    public function registra_el_consumo_de_una_lectura_que_no_sirvio()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => $this->respuesta('claude-haiku-5-5', 'esto no es JSON'),
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(1, 100, null, 100)])),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals(2, AiTokenUsage::where('referencia_id', $scan->id)->where('proceso', 'escaneo_factura_compra')->count());
    }

    /**
     * Si el fallback de servidor resolvió con otro modelo, se contabiliza con el que produjo la
     * respuesta, que es el que se cobró. `model` de la respuesta es la fuente.
     *
     * @test
     * @return void
     */
    public function contabiliza_con_el_modelo_que_produjo_la_respuesta()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => 500,
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5', $this->factura_leida([$this->renglon(1, 100, null, 100)]), [
                'content' => [
                    ['type' => 'fallback', 'from' => ['model' => 'claude-sonnet-5-5'], 'to' => ['model' => 'claude-sonnet-5']],
                    ['type' => 'text', 'text' => $this->factura_leida([$this->renglon(1, 100, null, 100)])],
                ],
            ]),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertSame('claude-sonnet-5', $scan->resultado['lectura']['modelo']);
        $this->assertSame('claude-sonnet-5', AiTokenUsage::where('referencia_id', $scan->id)->first()->modelo);
    }

    /**
     * El razonamiento de los modelos nuevos viene en bloques antes del texto. Si el parser los mezclara
     * con la respuesta, todas las lecturas darían JSON roto.
     *
     * @test
     * @return void
     */
    public function saltea_los_bloques_de_razonamiento()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5' => $this->respuesta('claude-haiku-5-5', '', [
                'content' => [
                    ['type' => 'thinking', 'thinking' => '', 'signature' => 'firma-de-prueba'],
                    ['type' => 'text', 'text' => $this->factura_leida([$this->renglon(1, 100, null, 100)])],
                ],
            ]),
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado);
        $this->assertSame(['claude-haiku-5-5'], $this->modelos_llamados());
    }

    /**
     * Sin parámetros que estos modelos rechazan con un 400. Y el fallback de servidor va SOLO en
     * Sonnet 5.5 y con su header: en Haiku 5.5 no sirve, y sin el header el pedido entero da 400.
     *
     * @test
     * @return void
     */
    public function arma_cada_llamada_con_lo_que_admite_su_modelo()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => 500,
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(1, 100, null, 100)])),
        ]);

        $this->correr_el_job($this->escaneo_con_foto());

        $grabadas = Http::recorded();

        $this->assertCount(2, $grabadas);

        foreach ($grabadas as $par) {
            $data = $par[0]->data();

            $this->assertArrayNotHasKey('temperature', $data);
            $this->assertArrayNotHasKey('thinking', $data);
            $this->assertArrayNotHasKey('tool_choice', $data);
        }

        $haiku  = $grabadas[0][0];
        $sonnet = $grabadas[1][0];

        $this->assertArrayNotHasKey('fallbacks', $haiku->data());
        $this->assertFalse($haiku->hasHeader('anthropic-beta'));

        $this->assertSame('default', $sonnet->data()['fallbacks']);
        $this->assertTrue($sonnet->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01'));
    }

    /**
     * La revisión relee con las mismas imágenes: es otra lectura del mismo papel, no un pedido nuevo.
     *
     * @test
     * @return void
     */
    public function la_revision_manda_las_mismas_imagenes_y_el_mismo_prompt()
    {
        $this->fake_por_modelo([
            'claude-haiku-5-5'  => 500,
            'claude-sonnet-5-5' => $this->respuesta('claude-sonnet-5-5', $this->factura_leida([$this->renglon(1, 100, null, 100)])),
        ]);

        $this->correr_el_job($this->escaneo_con_foto());

        $grabadas = Http::recorded();

        $this->assertSame($grabadas[0][0]->data()['messages'], $grabadas[1][0]->data()['messages']);
        $this->assertSame($grabadas[0][0]->data()['system'], $grabadas[1][0]->data()['system']);
    }
}
