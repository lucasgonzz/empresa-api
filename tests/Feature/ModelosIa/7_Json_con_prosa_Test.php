<?php

namespace Tests\Feature\ModelosIa;

use App\Http\Controllers\Helpers\asistente_ia\ModelosIaHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Models\ImageAssignmentItem;
use App\Services\ArticleImageValidationService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\ImagenesInteligentes\ImagenesInteligentesTestCase;

/**
 * Misión modelos-ia-por-cliente (30/9/2026, arreglo 9 de la revisión) — el JSON de la IA con prosa
 * alrededor.
 *
 * La comparación real Flash vs Haiku mostró que DeepSeek Flash a veces escribe PROSA antes del JSON
 * y que Haiku lo envuelve en ```json. Con "del primer `{` al último `}`" una llave en la prosa dejaba
 * la respuesta ilegible; los analizadores de Excel decodificaban el texto entero y cualquier frase los
 * tiraba. Protege, con las cuatro formas (JSON pelado, ```json envuelto, prosa sin llaves + JSON,
 * prosa con `{ejemplo}` + JSON): ModelosIaHelper::extraer_json(), la evaluación de candidatas del
 * motor de imágenes, validate() y el parseo de los tres analizadores de Excel.
 *
 * Extiende ImagenesInteligentesTestCase por la siembra del motor (comercio, Serper e imágenes falsas).
 *
 * 🔴 Ningún test sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Json_con_prosa_Test extends ImagenesInteligentesTestCase
{
    /**
     * Las cuatro formas en que puede venir un JSON, envolviendo el que se pasa.
     *
     * @param  string $json
     * @return array<string, string>
     */
    protected function formas($json)
    {
        return [
            'JSON pelado'                 => $json,
            '```json envuelto'            => "```json\n" . $json . "\n```",
            'prosa sin llaves + JSON'     => "Analizo las imágenes y el artículo. Este es el resultado:\n\n" . $json,
            'prosa con {ejemplo} + JSON'  => "Uso el formato {indice, veredicto} que me pediste, por ejemplo {1: si}. Resultado:\n" . $json . "\nEspero que sirva.",
        ];
    }

    /**
     * @test
     */
    public function extraer_json_encuentra_el_objeto_en_las_cuatro_formas()
    {
        $json = json_encode(['candidatas' => [['indice' => 1, 'es_el_producto' => 'si', 'motivo' => 'Tiene una {llave} en el motivo.']]]);

        $es_valido = function ($c) {
            return isset($c['candidatas']);
        };

        foreach ($this->formas($json) as $forma => $texto) {
            $resultado = ModelosIaHelper::extraer_json($texto, $es_valido);

            $this->assertNotNull($resultado, $forma);
            $this->assertSame('si', $resultado['candidatas'][0]['es_el_producto'], $forma);
            $this->assertSame('Tiene una {llave} en el motivo.', $resultado['candidatas'][0]['motivo'], $forma . ': las llaves adentro de un string no cortan el objeto.');
        }

        /* Sin la forma esperada, null (aunque haya JSON válido). */
        $this->assertNull(ModelosIaHelper::extraer_json('Mirá: {"otra_cosa": 1}', $es_valido));
        $this->assertNull(ModelosIaHelper::extraer_json('No hay JSON acá, ni {esto}.', $es_valido));
        $this->assertNull(ModelosIaHelper::extraer_json('', $es_valido));
    }

    /**
     * El motor de imágenes: con cualquiera de las cuatro formas, la candidata se evalúa y se asigna.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function la_evaluacion_de_candidatas_lee_el_veredicto_en_las_cuatro_formas()
    {
        $contador = 0;

        foreach (array_keys($this->formas('{}')) as $forma) {
            $contador++;
            $codigo   = $this->con_verificador('77900070000' . $contador);
            $articulo = $this->nuevo_articulo('Producto con prosa ' . $contador, $codigo);
            $run      = $this->asignacion([$articulo]);
            $url      = $this->url_imagen('prosa-' . $contador);
            $test     = $this;

            $this->falsear([$codigo => [$this->resultado($url, 1000, 1000, 1)]], [$url => $this->png(1000, 1000, 'rojo')], []);

            /* Se re-falsea la IA: el veredicto real envuelto en la forma de este caso. */
            Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
            Http::fake(function ($request) use ($test, $codigo, $url, $forma) {
                $destino = $request->url();

                if (strpos($destino, 'google.serper.dev') !== false) {
                    return Http::response(['images' => [$test->resultado_publico($url)]], 200);
                }

                if (strpos($destino, 'api.anthropic.com') !== false) {
                    $json = json_encode(['candidatas' => [array_merge(['indice' => 1], $test->veredicto_publico('si'))]]);

                    return Http::response([
                        'model'   => 'claude-haiku-4-5-20251001',
                        'content' => [['type' => 'text', 'text' => $test->formas_publico($json)[$forma]]],
                        'usage'   => ['input_tokens' => 10, 'output_tokens' => 5],
                    ], 200);
                }

                if ($destino === $url) {
                    return Http::response($test->png_publico(), 200, ['Content-Type' => 'image/png']);
                }

                return Http::response('no encontrada', 404);
            });

            $item = $this->procesar($run, $articulo);

            $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status, $forma . ': la respuesta tenía que leerse y la candidata asignarse.');
        }
    }

    /**
     * validate(): las cuatro formas dan un veredicto evaluado.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function validate_lee_el_veredicto_en_las_cuatro_formas()
    {
        $articulo = $this->nuevo_articulo('Yerba mate 1 kg', '7791234567898');
        $json     = json_encode(['es_el_producto' => true, 'tipo' => 'producto', 'confianza' => 'high', 'motivo' => 'Se ve el paquete.']);

        foreach ($this->formas($json) as $forma => $texto) {
            Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
            Http::fake(function ($request) use ($texto) {
                return Http::response(['content' => [['type' => 'text', 'text' => $texto]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200);
            });

            $veredicto = (new ArticleImageValidationService())->validate($this->png(300, 300, 'rojo'), $articulo, $this->owner->id);

            $this->assertTrue($veredicto['evaluated'], $forma);
            $this->assertTrue($veredicto['accepted'], $forma);
            $this->assertSame('high', $veredicto['confidence'], $forma);
        }
    }

    /**
     * Los tres analizadores de Excel leen el column_mapping en las cuatro formas (antes, cualquier
     * prosa daba "no se pudo interpretar la planilla").
     *
     * @group import
     * @test
     */
    public function los_tres_analizadores_leen_el_mapeo_en_las_cuatro_formas()
    {
        $id = (int) $this->owner->id;

        $analizadores = [
            'articulos'   => new class($id) extends AiExcelAnalyzer {
                public function parsear($texto)
                {
                    return $this->parse_claude_response($texto);
                }
            },
            'clientes'    => new class($id) extends AiClientAnalyzer {
                public function parsear($texto)
                {
                    return $this->parse_claude_response($texto);
                }
            },
            'proveedores' => new class($id) extends AiProviderAnalyzer {
                public function parsear($texto)
                {
                    return $this->parse_claude_response($texto);
                }
            },
        ];

        $json = json_encode(['column_mapping' => [['excel_column' => 'Nombre', 'excel_column_index' => 0, 'system_property' => 'nombre', 'confidence' => 'high']]]);

        foreach ($analizadores as $nombre => $analizador) {
            foreach ($this->formas($json) as $forma => $texto) {
                $resultado = $analizador->parsear($texto);

                $this->assertIsArray($resultado['column_mapping'], $nombre . ' / ' . $forma);
                $this->assertNotEmpty($resultado['column_mapping'], $nombre . ' / ' . $forma);
            }

            /* Sin column_mapping sigue siendo "no se pudo interpretar". */
            try {
                $analizador->parsear('No puedo con esta planilla, perdón {sin mapeo}.');
                $this->fail($nombre . ': tenía que tirar la excepción de respuesta ilegible.');
            } catch (\RuntimeException $e) {
                $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_RESPUESTA_ILEGIBLE, $e->getMessage(), $nombre);
            }
        }
    }

    /* Accesos públicos a los helpers protegidos del padre, para usarlos adentro del closure del fake. */

    /**
     * @param  string $url
     * @return array
     */
    public function resultado_publico($url)
    {
        return $this->resultado($url, 1000, 1000, 1);
    }

    /**
     * @param  string $es_el_producto
     * @return array
     */
    public function veredicto_publico($es_el_producto)
    {
        return $this->veredicto($es_el_producto, 'high');
    }

    /**
     * @param  string $json
     * @return array
     */
    public function formas_publico($json)
    {
        return $this->formas($json);
    }

    /**
     * @return string
     */
    public function png_publico()
    {
        return $this->png(1000, 1000, 'rojo');
    }
}
