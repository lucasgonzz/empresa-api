<?php

namespace Tests\Feature\EscaneoFacturaCompra;

use App\Jobs\RunProviderOrderScanJob;
use App\Models\AiTokenUsage;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderScanImage;
use App\Models\User;
use App\Services\EscaneoFacturaCompraService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;
use Tests\Feature\AgentesIa\OtorgaAgentesIa;

/**
 * Misión asistente-diario-20261116, rama B — A2 (14/11): escaneo de factura con JSON inválido o vacío.
 *
 * EL CASO REAL (Ferre Total, mensajes 376 y 475): dos escaneos terminaron en «La IA no devolvió
 * contenido» / «Syntax error» y el dueño tuvo que volver a mandar la foto. La primera lectura rota ya
 * pasaba a la revisión con el modelo caro; lo que faltaba:
 *
 *  - si la REVISIÓN también devuelve algo que no es un JSON utilizable, se le pide UNA vez más antes del
 *    error (doble del modelo: basura, basura y después un JSON bueno → el escaneo queda «listo»);
 *  - si igual falla, el dueño ve un mensaje claro que dice qué hacer, no el «Syntax error»;
 *  - un error de la API (5xx) NO se reintenta así: el comportamiento de siempre (lo fija el archivo 5).
 *
 * 🔴 SEGURO DE GASTO: la clave se apaga en el setUp y cada test prende la suya con su Http::fake(). No
 * sale a ninguna API. Los datos son inventados.
 *
 * @group escaneo-factura
 */
class T163_Reintento_de_json_invalido_Test extends EmpresaTestCase
{
    use OtorgaAgentesIa;

    /** @var \App\Models\User */
    protected $owner;

    /** @var \App\Models\ProviderOrder */
    protected $compra;

    /** @var array Fotos escritas en disco, para borrarlas al terminar. */
    protected $archivos_creados = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

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

    /** Un escaneo de la compra del fixture, con una foto chica generada con GD. */
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

    /** JSON de una factura leída con un renglón que cierra (no pide revisión). */
    protected function factura_buena(): string
    {
        return json_encode([
            'columnas_detectadas' => [],
            'articulos'           => [[
                'fila'                 => 1,
                'pagina'               => 1,
                'bar_code'             => null,
                'codigo_proveedor'     => 'INV-001',
                'nombre'               => 'ARTICULO INVENTADO',
                'cantidad'             => 1,
                'costo_unitario'       => 100,
                'descuento_porcentaje' => null,
                'iva_porcentaje'       => 21,
                'total_linea'          => 100,
                'notas'                => null,
                'confianza_fila'       => 0.9,
                'campos_dudosos'       => [],
            ]],
            'factura'             => ['es_factura_afip' => true, 'neto_gravado' => null, 'ivas' => [], 'campos_dudosos' => []],
            'avisos'              => [],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Respuesta de Anthropic con ese texto. */
    protected function respuesta(string $modelo, string $texto): array
    {
        return [
            'model'       => $modelo,
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 1000, 'output_tokens' => 500],
        ];
    }

    /**
     * El doble del modelo: cada modelo contesta, en orden, lo que tiene en su cola (un array es un 200,
     * un entero un error con ese status). Lo que sobra repite el último.
     */
    protected function fake_en_secuencia(array $colas)
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $llamadas = [];

        Http::fake(function ($request) use ($colas, &$llamadas) {
            $modelo = $request->data()['model'];

            $cola = array_key_exists($modelo, $colas) ? $colas[$modelo] : [418];

            $n = isset($llamadas[$modelo]) ? $llamadas[$modelo] : 0;
            $llamadas[$modelo] = $n + 1;

            $respuesta = $cola[min($n, count($cola) - 1)];

            if (is_int($respuesta)) {
                return Http::response(['error' => ['message' => 'falla de prueba']], $respuesta);
            }

            return Http::response($respuesta, 200);
        });
    }

    protected function correr_el_job(ProviderOrderScan $scan)
    {
        (new RunProviderOrderScanJob($scan->id))->handle();

        return $scan->fresh();
    }

    protected function modelos_llamados(): array
    {
        $modelos = [];

        foreach (Http::recorded() as $par) {
            $modelos[] = $par[0]->data()['model'];
        }

        return $modelos;
    }

    /**
     * 🔴 Basura de la primera, basura de la revisión y JSON bueno en el reintento: el escaneo queda listo.
     *
     * @test
     */
    public function la_revision_con_json_roto_se_reintenta_una_vez_y_el_escaneo_queda_listo()
    {
        $this->fake_en_secuencia([
            'claude-haiku-5-5'  => [$this->respuesta('claude-haiku-5-5', '{"articulos": [ basura')],
            'claude-sonnet-5-5' => [
                $this->respuesta('claude-sonnet-5-5', 'No pude armar el JSON, perdón'),
                $this->respuesta('claude-sonnet-5-5', $this->factura_buena()),
            ],
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado, (string) $scan->error);
        $this->assertNull($scan->error);
        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-sonnet-5-5'], $this->modelos_llamados());
        $this->assertEquals(1, $scan->resultado['articulos'][0]['cantidad']);

        // Las tres lecturas se pagaron y quedan registradas.
        $this->assertEquals(3, AiTokenUsage::where('referencia_id', $scan->id)->where('proceso', 'escaneo_factura_compra')->count());
        $this->assertEquals(3000, $scan->resultado['tokens']['input']);
    }

    /**
     * Una respuesta VACÍA de la revisión («La IA no devolvió contenido») también se reintenta.
     *
     * @test
     */
    public function una_revision_vacia_tambien_se_reintenta()
    {
        $this->fake_en_secuencia([
            'claude-haiku-5-5'  => [$this->respuesta('claude-haiku-5-5', '')],
            'claude-sonnet-5-5' => [
                $this->respuesta('claude-sonnet-5-5', ''),
                $this->respuesta('claude-sonnet-5-5', $this->factura_buena()),
            ],
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('listo', $scan->estado, (string) $scan->error);
        $this->assertCount(3, $this->modelos_llamados());
    }

    /**
     * Si sigue roto después del reintento, el dueño ve un mensaje claro (no el «Syntax error»), y no hay
     * un cuarto intento.
     *
     * @test
     */
    public function si_sigue_roto_el_duenio_ve_un_mensaje_claro_y_no_hay_un_cuarto_intento()
    {
        $this->fake_en_secuencia([
            'claude-haiku-5-5'  => [$this->respuesta('claude-haiku-5-5', '{"roto": ')],
            'claude-sonnet-5-5' => [$this->respuesta('claude-sonnet-5-5', '{"roto": ')],
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('error', $scan->estado);
        $this->assertSame(EscaneoFacturaCompraService::MENSAJE_LECTURA_ILEGIBLE, (string) $scan->error);
        $this->assertStringNotContainsString('Syntax error', (string) $scan->error);
        $this->assertNull($scan->resultado);
        $this->assertCount(3, $this->modelos_llamados(), 'Una lectura, la revisión y UN reintento: no más.');
    }

    /**
     * Un error de la API en la revisión NO se reintenta por este camino: sigue el error de siempre.
     *
     * @test
     */
    public function un_error_de_la_api_en_la_revision_no_se_reintenta()
    {
        $this->fake_en_secuencia([
            'claude-haiku-5-5'  => [$this->respuesta('claude-haiku-5-5', 'sin json')],
            'claude-sonnet-5-5' => [500],
        ]);

        $scan = $this->correr_el_job($this->escaneo_con_foto());

        $this->assertEquals('error', $scan->estado);
        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5'], $this->modelos_llamados());
        $this->assertStringContainsString('El servicio de IA respondió con un error al leer la factura', (string) $scan->error);
    }
}
