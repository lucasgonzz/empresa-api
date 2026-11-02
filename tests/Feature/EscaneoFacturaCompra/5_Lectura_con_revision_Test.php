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
     * @return array
     */
    protected function renglon($cantidad, $costo, $descuento, $total): array
    {
        return [
            'fila'                 => 1,
            'pagina'               => 1,
            'bar_code'             => null,
            'codigo_proveedor'     => 'INV-001',
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
     * @return string
     */
    protected function factura_leida(array $articulos, $neto_gravado = null): string
    {
        return json_encode([
            'columnas_detectadas' => [],
            'articulos'           => $articulos,
            'factura'             => [
                'es_factura_afip' => true,
                'neto_gravado'    => $neto_gravado,
                'ivas'            => [],
                'campos_dudosos'  => [],
            ],
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
        ];
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
                $this->renglon(1, 100, null, null),
                $this->renglon(1, 100, null, 100),
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

        $this->assertSame('suma_menor_que_el_neto', $scan->resultado['lectura']['motivo_revision']);
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
     * Dentro del 1 % tampoco revisa, y sin neto no hay con qué comparar (remitos, facturas C).
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
            'dentro del 1 %' => [1005],
            'sin neto'       => [null],
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
