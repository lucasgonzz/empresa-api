<?php

namespace Tests\Feature\ModelosIa;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Models\AiTokenUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión modelos-ia-por-cliente (30/9/2026) — la importación de Excel con DeepSeek Pro.
 *
 * Protege, en los TRES analizadores (son copias y un arreglo en uno solo ya se perdió una vez):
 * - con la clave de DeepSeek y el default (Pro) la llamada va a DeepSeek con Pro y el thinking
 *   prendido, y una respuesta con el bloque `thinking` PRIMERO se lee bien (antes se leía
 *   content[0]['text'] y daba "no pudo interpretar esta planilla");
 * - los mismos cinco mensajes al usuario con los errores de DeepSeek (402 saldo = rechazo; 503 y
 *   429 = no disponible);
 * - el fallback legado: sin clave de DeepSeek va a Anthropic con el modelo de siempre
 *   (`services.importacion_excel_ia.model_anthropic`), sin clave `thinking` y con el techo de
 *   siempre;
 * - el consumo se registra con el proveedor y el modelo que contestaron;
 * - y la recomendación (ask_claude_for_recomendation) pasa por el mismo camino.
 *
 * call_claude() es protected: se expone con una subclase anónima que solo lo reenvía.
 *
 * 🔴 Ningún test sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Importacion_excel_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $dueno;

    /** @var array Requests que salieron (url, headers, body). */
    protected $enviados = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'                    => 'clave-anthropic-de-prueba',
            'services.deepseek.api_key'                     => 'clave-deepseek-de-prueba',
            'services.deepseek.model_profundo'              => 'deepseek-pro-test',
            'services.deepseek.max_tokens_profundo'         => 8000,
            'services.importacion_excel_ia.max_tokens_con_razonamiento' => 16000,
            'services.importacion_excel_ia.model_anthropic' => 'claude-sonnet-legado-test',
        ]);

        $this->dueno = User::create([
            'name'         => 'Comercio modelos IA E4',
            'company_name' => 'Ferreteria E4',
            'email'        => 'modelos-ia-e4-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Los tres analizadores, con call_claude() expuesto, y el proceso con el que registran.
     *
     * @return array<string, array{0: object, 1: string}>
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
     * Fake de los dos proveedores con la misma respuesta (o el mismo error), capturando lo que sale.
     *
     * @param  array $body
     * @param  int   $status
     * @return void
     */
    protected function falsear(array $body, $status = 200)
    {
        $this->enviados = [];
        $test = $this;

        Http::swap(new \Tests\Fakes\HttpFactorySinSalida(app('events')));

        Http::fake(function ($request, $options) use ($test, $body, $status) {
            $test->enviados[] = [
                'url'     => $request->url(),
                'headers' => $request->headers(),
                'body'    => $request->data(),
                /* Las opciones de Guzzle del request: de acá sale el timeout que se configuró. */
                'timeout' => isset($options['timeout']) ? $options['timeout'] : null,
            ];

            return Http::response($body, $status);
        });
    }

    /**
     * La respuesta de DeepSeek Pro: primero el bloque `thinking`, después el texto.
     *
     * @param  string $texto
     * @return array
     */
    protected function respuesta_con_thinking_primero($texto)
    {
        return [
            'model'   => 'deepseek-pro-de-la-respuesta',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Miro las columnas y decido...', 'signature' => 'firma'],
                ['type' => 'text', 'text' => $texto],
            ],
            'usage'   => ['input_tokens' => 2000, 'output_tokens' => 400],
        ];
    }

    /**
     * El mensaje de la RuntimeException que tira call_claude(), o null si no tiró.
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
    public function con_la_clave_de_deepseek_los_tres_van_a_pro_pensando_y_leen_el_texto_despues_del_thinking()
    {
        foreach ($this->analizadores() as $nombre => $par) {
            list($analizador, $proceso) = $par;

            $this->falsear($this->respuesta_con_thinking_primero('{"column_mapping":{"A":"nombre"}}'));

            $texto = $analizador->llamar('Prompt de prueba');

            $this->assertSame('{"column_mapping":{"A":"nombre"}}', $texto, $nombre . ': el texto es el del bloque text, no content[0] (thinking).');

            $this->assertCount(1, $this->enviados, $nombre);

            $enviado = $this->enviados[0];

            $this->assertSame('https://api.deepseek.com/anthropic/v1/messages', $enviado['url'], $nombre);
            $this->assertSame(['clave-deepseek-de-prueba'], $enviado['headers']['x-api-key'], $nombre);
            $this->assertSame('deepseek-pro-test', $enviado['body']['model'], $nombre . ': el default de la importación es Pro.');
            $this->assertSame('enabled', $enviado['body']['thinking']['type'], $nombre);
            $this->assertSame(16000, $enviado['body']['max_tokens'], $nombre . ': con thinking el techo es max_tokens_con_razonamiento, no el de profundo del asistente.');

            $fila = AiTokenUsage::where('user_id', $this->dueno->id)->where('proceso', $proceso)->orderBy('id', 'desc')->first();

            $this->assertNotNull($fila, $nombre);
            $this->assertSame('deepseek', $fila->proveedor, $nombre);
            $this->assertSame('deepseek-pro-de-la-respuesta', $fila->modelo, $nombre);
        }
    }

    /**
     * 🔴 Sin clave de DeepSeek (la producción de hoy): la llamada de siempre, byte a byte en el
     * modelo, sin thinking y con el techo de cada analizador.
     *
     * @group import
     * @test
     */
    public function sin_clave_de_deepseek_los_tres_llaman_a_anthropic_con_el_modelo_de_siempre()
    {
        config(['services.deepseek.api_key' => null]);

        $techos = ['articulos' => 8000, 'clientes' => 8000, 'proveedores' => 8000];

        foreach ($this->analizadores() as $nombre => $par) {
            list($analizador, $proceso) = $par;

            $this->falsear([
                'model'   => 'claude-sonnet-4-5-20250929',
                'content' => [['type' => 'text', 'text' => '{"column_mapping":{}}']],
                'usage'   => ['input_tokens' => 10, 'output_tokens' => 5],
            ]);

            $this->assertSame('{"column_mapping":{}}', $analizador->llamar('Prompt de prueba'), $nombre);

            $enviado = $this->enviados[0];

            $this->assertSame('https://api.anthropic.com/v1/messages', $enviado['url'], $nombre);
            $this->assertSame(['clave-anthropic-de-prueba'], $enviado['headers']['x-api-key'], $nombre);
            $this->assertSame('claude-sonnet-legado-test', $enviado['body']['model'], $nombre . ': el modelo legado.');
            $this->assertArrayNotHasKey('thinking', $enviado['body'], $nombre);
            $this->assertSame($techos[$nombre], $enviado['body']['max_tokens'], $nombre);
            $this->assertSame([['role' => 'user', 'content' => 'Prompt de prueba']], $enviado['body']['messages'], $nombre);

            $fila = AiTokenUsage::where('user_id', $this->dueno->id)->where('proceso', $proceso)->orderBy('id', 'desc')->first();

            $this->assertSame('anthropic', $fila->proveedor, $nombre);
        }
    }

    /**
     * Los errores de DeepSeek dan los mismos mensajes en los tres: 402 (saldo) es rechazo; 503 y 429
     * son "no disponible"; una respuesta con solo thinking es "no se pudo interpretar".
     *
     * @group import
     * @test
     */
    public function los_errores_de_deepseek_dan_los_mismos_cinco_mensajes_en_los_tres()
    {
        $casos = [
            'saldo insuficiente 402' => [['error' => ['message' => 'Insufficient Balance']], 402, AiExcelAnalyzer::MENSAJE_IA_RECHAZO],
            'saturado 503'           => [['error' => ['message' => 'Service Unavailable']], 503, AiExcelAnalyzer::MENSAJE_IA_NO_DISPONIBLE],
            'rate limit 429'         => [['error' => ['message' => 'Rate limit']], 429, AiExcelAnalyzer::MENSAJE_IA_NO_DISPONIBLE],
            'solo thinking'          => [['content' => [['type' => 'thinking', 'thinking' => 'pensé y no escribí']]], 200, AiExcelAnalyzer::MENSAJE_IA_RESPUESTA_ILEGIBLE],
        ];

        foreach ($casos as $causa => $caso) {
            foreach ($this->analizadores() as $nombre => $par) {
                $this->falsear($caso[0], $caso[1]);

                $this->assertSame($caso[2], $this->mensaje_de_error($par[0]), $causa . ' / ' . $nombre);
            }
        }

        /* Y sin ninguna clave: el "sin configurar" de siempre, sin salir a la red. */
        config(['services.anthropic.api_key' => null, 'services.deepseek.api_key' => null]);

        foreach ($this->analizadores() as $nombre => $par) {
            $this->falsear([]);

            $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_SIN_CONFIGURAR, $this->mensaje_de_error($par[0]), $nombre);
            $this->assertCount(0, $this->enviados, $nombre);
        }
    }

    /**
     * Elegir Claude Opus para Excel, con las dos claves: Anthropic con el Opus del catálogo.
     *
     * @group import
     * @test
     */
    public function eligiendo_claude_opus_va_a_anthropic_con_el_opus_del_catalogo()
    {
        config(['services.anthropic.model_profundo' => 'claude-opus-catalogo-test']);

        $this->dueno->ia_modelo_excel = 'claude_opus';
        $this->dueno->save();

        list($analizador) = $this->analizadores()['articulos'];

        $this->falsear(['content' => [['type' => 'text', 'text' => 'ok']]]);

        $analizador->llamar('Prompt de prueba');

        $this->assertSame('https://api.anthropic.com/v1/messages', $this->enviados[0]['url']);
        $this->assertSame('claude-opus-catalogo-test', $this->enviados[0]['body']['model']);
        $this->assertArrayNotHasKey('thinking', $this->enviados[0]['body']);
    }

    /**
     * La recomendación pasa por el mismo call_claude(): con Pro y el thinking primero, la
     * recomendación de la IA se aplica (no cae al fallback heurístico).
     *
     * @group import
     * @test
     */
    public function la_recomendacion_con_pro_y_thinking_primero_se_aplica()
    {
        $this->falsear($this->respuesta_con_thinking_primero(json_encode([
            'politica_colision'      => 'saltear_y_reportar',
            'politica_intra_archivo' => 'productos_distintos',
            'explicacion'            => 'Recomendación de Pro (prueba).',
        ])));

        $resultado = (new AiExcelAnalyzer((int) $this->dueno->id))->ask_claude_for_recomendation([
            'total_filas_datos'                           => 0,
            'bar_codes_duplicados_intra_archivo'          => 0,
            'provider_codes_duplicados_intra_archivo'     => 0,
            'provider_codes_existentes_mismo_proveedor'   => 0,
            'provider_codes_existentes_otros_proveedores' => 0,
        ]);

        $this->assertSame('Recomendación de Pro (prueba).', $resultado['explicacion'], 'Si cayera al fallback heurístico, la explicación sería otra.');
        $this->assertSame('deepseek-pro-test', $this->enviados[0]['body']['model']);
    }

    /**
     * Una respuesta cortada por el techo (`stop_reason = max_tokens`) es "no se pudo interpretar" en
     * los tres, aunque el texto cortado se pudiera leer. Desde la misión techo-ia-importacion-planillas-anchas
     * (2/10/2026) la primera vez que se corta se reintenta UNA vez con el doble de techo: si el reintento
     * también se corta, son dos llamadas y las dos quedan registradas (las dos se pagaron).
     *
     * @group import
     * @test
     */
    public function una_respuesta_cortada_por_el_techo_es_ilegible_y_queda_registrada()
    {
        foreach ($this->analizadores() as $nombre => $par) {
            list($analizador, $proceso) = $par;

            $respuesta = $this->respuesta_con_thinking_primero('{"column_mapping":{"A":"nom');
            $respuesta['stop_reason'] = 'max_tokens';

            $this->falsear($respuesta);

            $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_RESPUESTA_ILEGIBLE, $this->mensaje_de_error($analizador), $nombre);

            $this->assertSame(2, AiTokenUsage::where('user_id', $this->dueno->id)->where('proceso', $proceso)->count(), $nombre . ': las dos llamadas cortadas se pagaron.');
        }

        /* Con stop_reason end_turn, la misma forma se lee normal. */
        list($analizador) = $this->analizadores()['articulos'];

        $respuesta = $this->respuesta_con_thinking_primero('{"column_mapping":{}}');
        $respuesta['stop_reason'] = 'end_turn';

        $this->falsear($respuesta);

        $this->assertSame('{"column_mapping":{}}', $analizador->llamar('Prompt de prueba'));
    }

    /**
     * Los tres usan el timeout de `services.importacion_excel_ia.timeout` (antes era 60 fijo).
     *
     * @group import
     * @test
     */
    public function los_tres_usan_el_timeout_de_config()
    {
        config(['services.importacion_excel_ia.timeout' => 7]);

        foreach ($this->analizadores() as $nombre => $par) {
            $this->falsear($this->respuesta_con_thinking_primero('ok'));

            $par[0]->llamar('Prompt de prueba');

            $this->assertEquals(7, $this->enviados[0]['timeout'], $nombre . ': el timeout sale de config.');
        }

        /* Y el fallback legado también. */
        config(['services.deepseek.api_key' => null, 'services.importacion_excel_ia.timeout' => 9]);

        list($analizador) = $this->analizadores()['clientes'];

        $this->falsear(['content' => [['type' => 'text', 'text' => 'ok']]]);

        $analizador->llamar('Prompt de prueba');

        $this->assertEquals(9, $this->enviados[0]['timeout']);
    }
}
