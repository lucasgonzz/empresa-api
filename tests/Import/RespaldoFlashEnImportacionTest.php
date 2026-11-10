<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\asistente_ia\ModelosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Models\AiTokenUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión excel-ia-respaldo-flash (10/11/2026, soporte de Mayorista de Pesca).
 *
 * El análisis de Excel con IA corre con DeepSeek Pro por defecto. Con una planilla de ~1.100 filas
 * Pro agotaba el timeout y el respaldo viejo le dejaba a Flash 30 s, así que también fallaba. Ahora
 * ModelosIaHelper::pedir_importacion() llama a Pro con el timeout completo y, si falla de forma
 * transitoria, reintenta UNA vez con Flash (sin razonamiento, con el techo de salida base).
 *
 * Se prueba el helper directo y los tres analizadores (son copias; un arreglo en uno solo ya se
 * perdió una vez) para los mensajes finales. Ningún test sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class RespaldoFlashEnImportacionTest extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $dueno;

    /** @var array Pedidos que salieron: modelo, body y timeout de cada uno. */
    protected $enviados = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'                                => 'clave-anthropic-de-prueba',
            'services.deepseek.api_key'                                 => 'clave-deepseek-de-prueba',
            'services.deepseek.model_profundo'                          => 'deepseek-pro-test',
            'services.deepseek.model_agil'                              => 'deepseek-flash-test',
            'services.deepseek.max_tokens_profundo'                     => 8000,
            'services.importacion_excel_ia.max_tokens_con_razonamiento' => 16000,
            'services.importacion_excel_ia.timeout'                     => 120,
            'services.importacion_excel_ia.model_anthropic'             => 'claude-sonnet-legado-test',
        ]);

        $this->dueno = User::create([
            'name'         => 'Comercio respaldo Flash',
            'company_name' => 'Pesca respaldo',
            'email'        => 'respaldo-flash-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Arma el fake: cada pedido se anota y se contesta con lo que devuelva $segun_modelo
     * ('conexion' = ConnectionException; [body, status] = respuesta HTTP).
     *
     * @param  callable $segun_modelo  fn(string $modelo, int $numero_de_pedido)
     * @return void
     */
    protected function falsear(callable $segun_modelo)
    {
        $this->enviados = [];
        $test = $this;

        Http::swap(new \Tests\Fakes\HttpFactorySinSalida(app('events')));

        Http::fake(function ($request, $options) use ($test, $segun_modelo) {
            $body = $request->data();
            $test->enviados[] = [
                'url'     => $request->url(),
                'modelo'  => isset($body['model']) ? $body['model'] : null,
                'body'    => $body,
                'timeout' => isset($options['timeout']) ? $options['timeout'] : null,
            ];

            $que = $segun_modelo((string) $body['model'], count($test->enviados));

            if ($que === 'conexion') {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response($que[0], $que[1]);
        });
    }

    /**
     * Respuesta buena de la IA con el modelo indicado.
     *
     * @param  string $modelo
     * @return array
     */
    protected function ok($modelo)
    {
        return [[
            'model'   => $modelo,
            'content' => [['type' => 'text', 'text' => '{"column_mapping":{"A":"nombre"}}']],
            'usage'   => ['input_tokens' => 100, 'output_tokens' => 50],
        ], 200];
    }

    /**
     * Lo que resuelve la importación para el dueño (Pro por defecto) y el payload de Pro como lo
     * arman los analizadores.
     *
     * @return array [ia, payload]
     */
    protected function pedido_de_pro()
    {
        $ia = ModelosIaHelper::resolver($this->dueno, ModelosIaHelper::TAREA_EXCEL);

        $payload = ProveedorIaHelper::agregar_thinking([
            'model'      => $ia['modelo'],
            'max_tokens' => 16000,
            'messages'   => [['role' => 'user', 'content' => 'Prompt de prueba']],
        ], $ia['thinking']);

        return [$ia, $payload];
    }

    /**
     * Los tres analizadores con call_claude() expuesto, con su proceso de consumo.
     *
     * @return array
     */
    protected function analizadores()
    {
        $id = (int) $this->dueno->id;

        return [
            'articulos'   => [new class($id) extends AiExcelAnalyzer {
                public function llamar($prompt)
                {
                    return $this->call_claude($prompt);
                }
            }, 'import_excel_articulos'],
            'clientes'    => [new class($id) extends AiClientAnalyzer {
                public function llamar($prompt)
                {
                    return $this->call_claude($prompt);
                }
            }, 'import_excel_clientes'],
            'proveedores' => [new class($id) extends AiProviderAnalyzer {
                public function llamar($prompt)
                {
                    return $this->call_claude($prompt);
                }
            }, 'import_excel_proveedores'],
        ];
    }

    /**
     * Mensaje de la RuntimeException de call_claude(), o null si no tiró.
     *
     * @param  object $analizador
     * @return string|null
     */
    protected function mensaje_de_error($analizador)
    {
        try {
            $analizador->llamar('Prompt de prueba');
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @group import
     * @test
     */
    public function si_pro_vence_el_timeout_se_reintenta_con_flash_y_se_devuelve_flash()
    {
        $this->falsear(function ($modelo) {
            return $modelo === 'deepseek-pro-test' ? 'conexion' : $this->ok('deepseek-flash-test');
        });

        list($ia, $payload) = $this->pedido_de_pro();

        list($respuesta, $modelo) = ModelosIaHelper::pedir_importacion($ia, 120, $payload, 8000);

        $this->assertTrue($respuesta->successful());
        $this->assertSame('deepseek-flash-test', $modelo, 'El modelo devuelto es el de Flash.');
        $this->assertSame(['deepseek-pro-test', 'deepseek-flash-test'], array_column($this->enviados, 'modelo'));

        // Pro tuvo el timeout COMPLETO (no 3/4) y sus opciones de siempre.
        $this->assertSame(120, (int) $this->enviados[0]['timeout']);
        $this->assertSame('enabled', $this->enviados[0]['body']['thinking']['type']);
        $this->assertSame(16000, $this->enviados[0]['body']['max_tokens']);
    }

    /**
     * @group import
     * @test
     */
    public function el_pedido_a_flash_va_sin_razonamiento_y_con_el_techo_base_y_con_timeout_propio()
    {
        $this->falsear(function ($modelo) {
            return $modelo === 'deepseek-pro-test' ? 'conexion' : $this->ok('deepseek-flash-test');
        });

        list($ia, $payload) = $this->pedido_de_pro();

        ModelosIaHelper::pedir_importacion($ia, 120, $payload, 8000);

        $flash = $this->enviados[1];

        $this->assertNotSame('enabled', $flash['body']['thinking']['type'], 'Flash no lleva thinking enabled.');
        $this->assertSame(8000, $flash['body']['max_tokens'], 'Flash va con el techo base, no con el de razonamiento.');
        $this->assertSame($payload['messages'], $flash['body']['messages'], 'El contenido del pedido es el mismo.');
        $this->assertGreaterThanOrEqual(30, (int) $flash['timeout']);
        $this->assertLessThanOrEqual(120, (int) $flash['timeout']);
    }

    /**
     * @group import
     * @test
     */
    public function si_pro_contesta_503_se_reintenta_con_flash()
    {
        $this->falsear(function ($modelo) {
            return $modelo === 'deepseek-pro-test'
                ? [['error' => ['type' => 'overloaded_error']], 503]
                : $this->ok('deepseek-flash-test');
        });

        list($ia, $payload) = $this->pedido_de_pro();

        list($respuesta, $modelo) = ModelosIaHelper::pedir_importacion($ia, 120, $payload, 8000);

        $this->assertTrue($respuesta->successful());
        $this->assertSame('deepseek-flash-test', $modelo);
        $this->assertCount(2, $this->enviados);
    }

    /**
     * Un error que Flash tampoco arreglaría (saldo, clave) no se reintenta.
     *
     * @group import
     * @test
     */
    public function un_error_no_transitorio_de_pro_no_se_reintenta()
    {
        $this->falsear(function () {
            return [['error' => ['message' => 'Insufficient Balance']], 402];
        });

        list($ia, $payload) = $this->pedido_de_pro();

        list($respuesta, $modelo) = ModelosIaHelper::pedir_importacion($ia, 120, $payload, 8000);

        $this->assertSame(402, $respuesta->status());
        $this->assertSame('deepseek-pro-test', $modelo);
        $this->assertCount(1, $this->enviados);
    }

    /**
     * @group import
     * @test
     */
    public function si_flash_tambien_falla_los_tres_analizadores_dan_el_mensaje_con_las_causas()
    {
        foreach ($this->analizadores() as $nombre => $par) {
            $analizador = $par[0];

            // Pro y Flash vencen el timeout.
            $this->falsear(function () {
                return 'conexion';
            });

            $mensaje = $this->mensaje_de_error($analizador);

            $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_SIN_RESPUESTA, $mensaje, $nombre);
            $this->assertCount(2, $this->enviados, $nombre . ': un pedido a Pro y UNO a Flash, nada más.');
            $this->assertStringContainsString('Excel', $mensaje, $nombre);
            $this->assertStringContainsString('filas', $mensaje, $nombre);
            $this->assertStringContainsString('soporte', $mensaje, $nombre);
            $this->assertStringContainsString('más chicos', $mensaje, $nombre);

            // Pro y Flash saturados: el mensaje de "no disponible" también lista qué hacer.
            $this->falsear(function () {
                return [['error' => ['type' => 'overloaded_error']], 503];
            });

            $mensaje = $this->mensaje_de_error($analizador);

            $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_NO_DISPONIBLE, $mensaje, $nombre);
            $this->assertCount(2, $this->enviados, $nombre);
            $this->assertStringContainsString('guardalo', $mensaje, $nombre);
        }
    }

    /**
     * Con Flash ELEGIDO no hay a quién respaldar: una sola llamada.
     *
     * @group import
     * @test
     */
    public function con_flash_elegido_no_se_reintenta()
    {
        $columna = ModelosIaHelper::COLUMNAS_POR_TAREA[ModelosIaHelper::TAREA_EXCEL];
        $this->dueno->{$columna} = ModelosIaHelper::DEEPSEEK_FLASH;
        $this->dueno->save();

        foreach ($this->analizadores() as $nombre => $par) {
            $this->falsear(function () {
                return 'conexion';
            });

            $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_SIN_RESPUESTA, $this->mensaje_de_error($par[0]), $nombre);
            $this->assertSame(['deepseek-flash-test'], array_column($this->enviados, 'modelo'), $nombre);
        }
    }

    /**
     * Sin clave de DeepSeek (cae al Claude legado) nada cambia: una sola llamada.
     *
     * @group import
     * @test
     */
    public function con_anthropic_no_se_reintenta_ni_cambia_el_pedido()
    {
        config(['services.deepseek.api_key' => '']);

        foreach ($this->analizadores() as $nombre => $par) {
            $this->falsear(function () {
                return [['error' => ['type' => 'overloaded_error']], 529];
            });

            $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_NO_DISPONIBLE, $this->mensaje_de_error($par[0]), $nombre);
            $this->assertCount(1, $this->enviados, $nombre);
            $this->assertSame('claude-sonnet-legado-test', $this->enviados[0]['modelo'], $nombre);
            $this->assertArrayNotHasKey('thinking', $this->enviados[0]['body'], $nombre);
        }
    }

    /**
     * El consumo queda registrado con el modelo de Flash, que fue el que contestó.
     *
     * @group import
     * @test
     */
    public function el_consumo_se_registra_con_el_modelo_de_flash()
    {
        foreach ($this->analizadores() as $nombre => $par) {
            list($analizador, $proceso) = $par;

            $this->falsear(function ($modelo) {
                return $modelo === 'deepseek-pro-test' ? 'conexion' : $this->ok('deepseek-flash-de-la-respuesta');
            });

            $texto = $analizador->llamar('Prompt de prueba');

            $this->assertSame('{"column_mapping":{"A":"nombre"}}', $texto, $nombre);

            $fila = AiTokenUsage::where('user_id', $this->dueno->id)->where('proceso', $proceso)->orderBy('id', 'desc')->first();

            $this->assertNotNull($fila, $nombre);
            $this->assertSame('deepseek', $fila->proveedor, $nombre);
            $this->assertSame('deepseek-flash-de-la-respuesta', $fila->modelo, $nombre);
        }
    }
}
