<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\asistente_ia\ModelosIaHelper;
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
 * Misión ia-excel-reintento-corte (23/11/2026, soporte de Plazoleta).
 *
 * Caso real: DeepSeek Pro (con razonamiento) gasta ~117 s y se corta por `max_tokens`. El reintento
 * por corte heredaba el REMANENTE de los 120 s (3 s) y además repetía Pro, que podía volver a gastar
 * el techo pensando: el usuario leía "La IA no respondió a tiempo" sin que se hubiera esperado nada.
 *
 * Contrato nuevo (PLAN §2 y §6):
 *  - El reintento por corte tiene plazo propio: min(timeout × factor, 840 − segundos gastados), y si
 *    quedan menos de 30 s no se pide y se dice "respuesta ilegible" (nunca "sin respuesta").
 *  - Un Pro con razonamiento cortado se reintenta con Flash SIN razonamiento y techo ×2. Los demás
 *    (Anthropic, Flash elegido, Pro sin thinking) repiten el mismo modelo con techo y timeout ×2.
 *  - Sin cascada: el reintento con Flash es UN solo pedido.
 *
 * Se prueban los tres analizadores (son copias; un arreglo en uno solo ya se perdió una vez). Ningún
 * test sale a la red. No extiende ImportTestCase a propósito: esa base necesita un usuario tenant que
 * esta base de pruebas no tiene.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class ReintentoPorCorteConPlazoTest extends TestCase
{
    use DatabaseTransactions;

    /** @var User Dueño del comercio de prueba (DeepSeek Pro por defecto). */
    protected $dueno;

    /** @var array Pedidos que salieron: url, modelo, body y timeout de cada uno. */
    protected $enviados = [];

    /**
     * Config de los proveedores y un dueño nuevo, igual que RespaldoFlashEnImportacionTest.
     *
     * @return void
     */
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
            'name'         => 'Comercio reintento por corte',
            'company_name' => 'Plazoleta de prueba',
            'email'        => 'reintento-corte-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Arma el fake: cada pedido se anota (modelo, body, timeout) y se contesta con lo que devuelva
     * $segun_pedido ('conexion' = ConnectionException; [body, status] = respuesta HTTP).
     *
     * @param  callable $segun_pedido  fn(string $modelo, int $numero_de_pedido)
     * @return void
     */
    protected function falsear(callable $segun_pedido)
    {
        $this->enviados = [];
        $test = $this;

        Http::swap(new \Tests\Fakes\HttpFactorySinSalida(app('events')));

        Http::fake(function ($request, $options) use ($test, $segun_pedido) {
            $body = $request->data();
            $test->enviados[] = [
                'url'     => $request->url(),
                'modelo'  => isset($body['model']) ? $body['model'] : null,
                'body'    => $body,
                'timeout' => isset($options['timeout']) ? $options['timeout'] : null,
            ];

            $que = $segun_pedido((string) $body['model'], count($test->enviados));

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
     * @return array [body, status]
     */
    protected function ok($modelo)
    {
        return [[
            'model'       => $modelo,
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => '{"column_mapping":{"A":"nombre"}}']],
            'usage'       => ['input_tokens' => 100, 'output_tokens' => 50],
        ], 200];
    }

    /**
     * Respuesta CORTADA por el techo de salida (`stop_reason = max_tokens`): un bloque de
     * razonamiento y un JSON a medias.
     *
     * @param  string $modelo
     * @return array [body, status]
     */
    protected function cortada($modelo)
    {
        return [[
            'model'       => $modelo,
            'stop_reason' => 'max_tokens',
            'content'     => [
                ['type' => 'thinking', 'thinking' => 'Razonando sobre las columnas...'],
                ['type' => 'text', 'text' => '{"column_mapping":{"A":"nom'],
            ],
            'usage'       => ['input_tokens' => 100, 'output_tokens' => 16000],
        ], 200];
    }

    /**
     * Los tres analizadores con call_claude() expuesto, con su proceso de consumo. NO pisan nada
     * más: el comportamiento es el del código real.
     *
     * @return array nombre => [analizador, proceso]
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
     * Igual que analizadores() pero con el reloj pisado: el primer intento "ya gastó" 815 s, o sea
     * que no queda presupuesto (840 − 815 = 25 s, menos del mínimo de 30). Pisa
     * segundos_gastados_desde() para no depender de un sleep real.
     *
     * @return array nombre => [analizador, proceso]
     */
    protected function analizadores_sin_presupuesto()
    {
        $id = (int) $this->dueno->id;

        return [
            'articulos'   => [new class($id) extends AiExcelAnalyzer {
                public function llamar($prompt)
                {
                    return $this->call_claude($prompt);
                }

                protected function segundos_gastados_desde($inicio)
                {
                    return 815;
                }
            }, 'import_excel_articulos'],
            'clientes'    => [new class($id) extends AiClientAnalyzer {
                public function llamar($prompt)
                {
                    return $this->call_claude($prompt);
                }

                protected function segundos_gastados_desde($inicio)
                {
                    return 815;
                }
            }, 'import_excel_clientes'],
            'proveedores' => [new class($id) extends AiProviderAnalyzer {
                public function llamar($prompt)
                {
                    return $this->call_claude($prompt);
                }

                protected function segundos_gastados_desde($inicio)
                {
                    return 815;
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
     * CA1 (unidad): el plazo del reintento es propio y respeta el presupuesto de 840 s.
     *
     * @group import
     * @test
     */
    public function el_timeout_del_reintento_es_propio_y_respeta_el_presupuesto()
    {
        // [timeout, factor, segundos gastados, esperado]
        $casos = [
            [120, 2, 117, 240], // el caso de Plazoleta: antes 3 s, ahora el doble del timeout
            [120, 2, 700, 140], // lo que queda del presupuesto, no más
            [120, 2, 810, 30],  // justo el mínimo útil
            [120, 2, 815, 0],   // 25 s: no vale la pena pedir
            [600, 2, 600, 240], // timeout alto (Mayorista de Pesca): se topa con el presupuesto
            [1, 2, 1, 2],
            [7, 2, 0, 14],
            [7, 2, 5, 14],
        ];

        foreach ($casos as $caso) {
            list($timeout, $factor, $gastados, $esperado) = $caso;

            $this->assertSame(
                $esperado,
                ModelosIaHelper::timeout_del_reintento_por_corte($timeout, $factor, $gastados),
                'timeout=' . $timeout . ' factor=' . $factor . ' gastados=' . $gastados
            );
        }
    }

    /**
     * CA1 (integrado): con Pro cortado el reintento sale con plazo propio. Con timeout = 1 el primer
     * intento "gasta" 1 s y el remanente viejo daba 0: no se hacía el segundo pedido.
     *
     * @group import
     * @test
     */
    public function con_pro_cortado_el_reintento_sale_con_plazo_propio_en_los_tres()
    {
        config(['services.importacion_excel_ia.timeout' => 1]);

        foreach ($this->analizadores() as $nombre => $par) {
            $this->falsear(function ($modelo, $numero) {
                return $numero === 1 ? $this->cortada($modelo) : $this->ok($modelo);
            });

            $mensaje = $this->mensaje_de_error($par[0]);

            $this->assertNull($mensaje, $nombre . ': el reintento tiene que hacerse y no tirar "' . $mensaje . '".');
            $this->assertCount(2, $this->enviados, $nombre . ': Pro cortado y el reintento.');
            $this->assertSame(1, (int) $this->enviados[0]['timeout'], $nombre . ': el primer pedido usa el timeout de config.');
            $this->assertSame(2, (int) $this->enviados[1]['timeout'], $nombre . ': el reintento usa su propio plazo (timeout × 2), no el remanente.');
        }
    }

    /**
     * CA2: el reintento de un Pro con razonamiento cortado va a Flash, sin razonamiento y con techo ×2.
     * El consumo de los dos pedidos queda registrado y el segundo con el modelo de Flash.
     *
     * @group import
     * @test
     */
    public function el_reintento_de_pro_cortado_va_a_flash_sin_razonamiento_en_los_tres()
    {
        foreach ($this->analizadores() as $nombre => $par) {
            list($analizador, $proceso) = $par;

            $this->falsear(function ($modelo, $numero) {
                return $numero === 1 ? $this->cortada($modelo) : $this->ok($modelo);
            });

            $texto = $analizador->llamar('Prompt de prueba');

            $this->assertCount(2, $this->enviados, $nombre . ': total de pedidos.');
            $this->assertSame(
                ['deepseek-pro-test', 'deepseek-flash-test'],
                array_column($this->enviados, 'modelo'),
                $nombre . ': el reintento tiene que ir a Flash, no repetir Pro.'
            );

            // El primero es Pro con razonamiento y su techo.
            $this->assertSame('enabled', $this->enviados[0]['body']['thinking']['type'], $nombre);
            $this->assertSame(16000, $this->enviados[0]['body']['max_tokens'], $nombre);

            // El segundo es Flash sin razonamiento, con techo ×2 y el mismo contenido.
            $flash = $this->enviados[1]['body'];
            $this->assertNotSame('enabled', isset($flash['thinking']['type']) ? $flash['thinking']['type'] : null, $nombre . ': Flash va sin razonamiento.');
            $this->assertSame(16000, $flash['max_tokens'], $nombre . ': techo base × 2.');
            $this->assertSame($this->enviados[0]['body']['messages'], $flash['messages'], $nombre . ': mismo prompt.');

            // Lo que devuelve es el texto de Flash.
            $this->assertSame('{"column_mapping":{"A":"nombre"}}', $texto, $nombre);

            // Los dos pedidos se pagaron: dos consumos, el segundo con el modelo de Flash.
            $filas = AiTokenUsage::where('user_id', $this->dueno->id)->where('proceso', $proceso)->orderBy('id', 'asc')->get();

            $this->assertCount(2, $filas, $nombre . ': un consumo por pedido.');
            $this->assertSame('deepseek-flash-test', $filas[1]->modelo, $nombre . ': el segundo consumo es de Flash.');
        }
    }

    /**
     * CA3: si el reintento con Flash vence el timeout hay exactamente DOS pedidos (Pro y Flash):
     * pedir_importacion() no abre un tercero porque el reintento ya es Flash. Y el mensaje es el de
     * "sin respuesta", que acá sí es verdad.
     *
     * @group import
     * @test
     */
    public function si_el_reintento_con_flash_vence_no_hay_tercer_pedido()
    {
        foreach ($this->analizadores() as $nombre => $par) {
            $this->falsear(function ($modelo, $numero) {
                return $numero === 1 ? $this->cortada($modelo) : 'conexion';
            });

            $mensaje = $this->mensaje_de_error($par[0]);

            $this->assertCount(2, $this->enviados, $nombre . ': Pro y Flash, sin tercer pedido.');
            $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_SIN_RESPUESTA, $mensaje, $nombre);
        }
    }

    /**
     * CA4 (i): Pro cortado y Flash también cortado = respuesta ilegible, con dos pedidos.
     *
     * @group import
     * @test
     */
    public function dos_cortes_seguidos_dan_ilegible_en_los_tres()
    {
        foreach ($this->analizadores() as $nombre => $par) {
            $this->falsear(function ($modelo) {
                return $this->cortada($modelo);
            });

            $mensaje = $this->mensaje_de_error($par[0]);

            $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_RESPUESTA_ILEGIBLE, $mensaje, $nombre);
            $this->assertCount(2, $this->enviados, $nombre);
        }
    }

    /**
     * CA4 (ii): sin presupuesto para el reintento (815 s gastados) no se pide nada y el mensaje es
     * "respuesta ilegible": la IA sí contestó, lo que no se pudo fue leerla entera. Nunca "sin
     * respuesta" sin haber hecho el pedido.
     *
     * @group import
     * @test
     */
    public function sin_presupuesto_para_el_reintento_da_ilegible_sin_pedir_en_los_tres()
    {
        foreach ($this->analizadores_sin_presupuesto() as $nombre => $par) {
            $this->falsear(function ($modelo) {
                return $this->cortada($modelo);
            });

            $mensaje = $this->mensaje_de_error($par[0]);

            $this->assertCount(1, $this->enviados, $nombre . ': sin presupuesto no se hace el reintento.');
            $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_RESPUESTA_ILEGIBLE, $mensaje, $nombre);
        }
    }

    /**
     * CA5: con Anthropic (sin clave de DeepSeek) el reintento repite el MISMO modelo con techo ×2 y
     * timeout ×2 (config 7 -> 14).
     *
     * @group import
     * @test
     */
    public function con_anthropic_el_reintento_repite_modelo_con_doble_techo_y_doble_timeout()
    {
        config([
            'services.deepseek.api_key'                     => '',
            'services.importacion_excel_ia.timeout'         => 7,
        ]);

        foreach ($this->analizadores() as $nombre => $par) {
            $this->falsear(function ($modelo, $numero) {
                return $numero === 1 ? $this->cortada($modelo) : $this->ok($modelo);
            });

            $mensaje = $this->mensaje_de_error($par[0]);

            $this->assertNull($mensaje, $nombre);
            $this->assertCount(2, $this->enviados, $nombre);
            $this->assertSame(
                ['claude-sonnet-legado-test', 'claude-sonnet-legado-test'],
                array_column($this->enviados, 'modelo'),
                $nombre . ': mismo modelo.'
            );
            $this->assertSame(8000, $this->enviados[0]['body']['max_tokens'], $nombre);
            $this->assertSame(16000, $this->enviados[1]['body']['max_tokens'], $nombre);
            $this->assertSame(7, (int) $this->enviados[0]['timeout'], $nombre);
            $this->assertSame(14, (int) $this->enviados[1]['timeout'], $nombre . ': el reintento tiene timeout × 2.');
        }
    }

    /**
     * CA5: con Flash ELEGIDO por el dueño no hay a quién cambiar: el reintento repite Flash con techo
     * ×2 y timeout ×2. (Hoy ya se comporta así: es una guarda contra regresión.)
     *
     * @group import
     * @test
     */
    public function con_flash_elegido_el_reintento_repite_flash_con_doble_techo_y_doble_timeout()
    {
        config(['services.importacion_excel_ia.timeout' => 7]);

        $columna = ModelosIaHelper::COLUMNAS_POR_TAREA[ModelosIaHelper::TAREA_EXCEL];
        $this->dueno->{$columna} = ModelosIaHelper::DEEPSEEK_FLASH;
        $this->dueno->save();

        foreach ($this->analizadores() as $nombre => $par) {
            $this->falsear(function ($modelo, $numero) {
                return $numero === 1 ? $this->cortada($modelo) : $this->ok($modelo);
            });

            $mensaje = $this->mensaje_de_error($par[0]);

            $this->assertNull($mensaje, $nombre);
            $this->assertSame(
                ['deepseek-flash-test', 'deepseek-flash-test'],
                array_column($this->enviados, 'modelo'),
                $nombre
            );
            $this->assertSame(8000, $this->enviados[0]['body']['max_tokens'], $nombre);
            $this->assertSame(16000, $this->enviados[1]['body']['max_tokens'], $nombre);
            $this->assertSame(7, (int) $this->enviados[0]['timeout'], $nombre);
            $this->assertSame(14, (int) $this->enviados[1]['timeout'], $nombre);
        }
    }

    /**
     * CA5 (unidad): ia_del_reintento_por_corte() no toca Anthropic, ni Flash, ni Pro sin razonamiento;
     * y convierte Pro con razonamiento en Flash con el thinking apagado.
     *
     * @group import
     * @test
     */
    public function ia_del_reintento_no_cambia_anthropic_ni_flash_ni_pro_sin_thinking()
    {
        $columna = ModelosIaHelper::COLUMNAS_POR_TAREA[ModelosIaHelper::TAREA_EXCEL];

        // Pro con razonamiento (el default): pasa a Flash sin razonamiento.
        $pro = ModelosIaHelper::resolver($this->dueno, ModelosIaHelper::TAREA_EXCEL);
        $this->assertSame('enabled', $pro['thinking']['type'], 'Precondición: el default es Pro con razonamiento.');

        $reintento = ModelosIaHelper::ia_del_reintento_por_corte($pro);

        $this->assertSame(ModelosIaHelper::DEEPSEEK_FLASH, $reintento['opcion_efectiva']);
        $this->assertSame('deepseek-flash-test', $reintento['modelo']);
        $this->assertSame('disabled', $reintento['thinking']['type']);

        // Pro con thinking apagado por config: no cambia.
        config(['services.deepseek.thinking_profundo' => 'disabled']);
        $pro_sin_thinking = ModelosIaHelper::resolver($this->dueno, ModelosIaHelper::TAREA_EXCEL);
        $this->assertSame($pro_sin_thinking, ModelosIaHelper::ia_del_reintento_por_corte($pro_sin_thinking), 'Pro sin thinking queda igual.');
        config(['services.deepseek.thinking_profundo' => 'enabled']);

        // Flash elegido: no cambia.
        $this->dueno->{$columna} = ModelosIaHelper::DEEPSEEK_FLASH;
        $this->dueno->save();
        $flash = ModelosIaHelper::resolver($this->dueno->fresh(), ModelosIaHelper::TAREA_EXCEL);
        $this->assertSame($flash, ModelosIaHelper::ia_del_reintento_por_corte($flash), 'Flash queda igual.');

        // Anthropic (sin clave de DeepSeek): no cambia.
        config(['services.deepseek.api_key' => '']);
        $anthropic = ModelosIaHelper::resolver($this->dueno->fresh(), ModelosIaHelper::TAREA_EXCEL);
        $this->assertSame('anthropic', $anthropic['proveedor'], 'Precondición: sin clave de DeepSeek cae a Anthropic.');
        $this->assertSame($anthropic, ModelosIaHelper::ia_del_reintento_por_corte($anthropic), 'Anthropic queda igual.');
    }
}
