<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AiExcelAnalyzer::ask_claude_for_recomendation() (grupo 287, prompt 04): la funcion que
 * preselecciona politica_colision / politica_intra_archivo para cada importacion de los
 * ~40 clientes. Quedo reescrita por el grupo 284 (prompts 02 y 03) sin un solo test.
 *
 * NO extiende ImportTestCase: la funcion recibe arrays y devuelve un array, no toca la
 * base de datos -- levantar el seeder de ImportTestCase seria trabajo al pedo. Extiende
 * Tests\TestCase directo, que alcanza para Http::fake() y config().
 *
 * Dos caminos, sin red en ningun caso:
 *   - Con Http::fake(): intercepta el POST a la API de Anthropic (Http::withHeaders()->post()
 *     dentro de call_claude()), asi que nunca sale a internet.
 *   - Con config(['services.anthropic.api_key' => '']): call_claude() tira RuntimeException
 *     en su primera linea (api_key vacia) antes de armar ningun request, y
 *     ask_claude_for_recomendation() cae directo al bloque catch (fallback heuristico).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class RecomendacionIaTest extends TestCase
{
    /**
     * Array minimo de stats que espera ask_claude_for_recomendation(), con todo en 0
     * salvo lo que el test quiera variar.
     *
     * @param  array $overrides
     * @return array
     */
    protected function stats(array $overrides = []): array
    {
        return array_merge([
            'total_filas_datos'                          => 0,
            'provider_codes_duplicados_intra_archivo'     => 0,
            'provider_codes_existentes_mismo_proveedor'   => 0,
            'provider_codes_existentes_otros_proveedores' => 0,
        ], $overrides);
    }

    /**
     * Body fake con la forma REAL de la respuesta de la API de Anthropic: el texto de
     * Claude (el JSON de la recomendacion, como string) va en content[0].text -- ver
     * AiExcelAnalyzer::call_claude(), "$response_data['content'][0]['text']".
     *
     * @param  array $recomendacion  ['politica_colision' => ..., 'politica_intra_archivo' => ..., 'explicacion' => ...]
     * @return array
     */
    protected function respuesta_claude(array $recomendacion): array
    {
        return [
            'content' => [
                ['text' => json_encode($recomendacion)],
            ],
        ];
    }

    /**
     * @return \App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer
     */
    protected function analyzer()
    {
        return new AiExcelAnalyzer(1);
    }

    /* ------------------------------------------------------------------
     * Camino Claude responde (Http::fake())
     * ------------------------------------------------------------------ */

    /**
     * Claude devuelve una respuesta valida y sin repetidos intra-archivo en los stats:
     * el resultado trae exactamente esos tres campos con esos valores.
     *
     * @return void
     */
    public function test_respuesta_valida_se_aplica()
    {
        config(['services.anthropic.api_key' => 'fake-key']);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuesta_claude([
                'politica_colision'      => 'saltear_y_reportar',
                'politica_intra_archivo' => 'productos_distintos',
                'explicacion'            => 'Se van a actualizar los articulos existentes.',
            ]), 200),
        ]);

        $resultado = $this->analyzer()->ask_claude_for_recomendation($this->stats());

        Http::assertSentCount(1);

        $this->assertSame([
            'politica_colision'      => 'saltear_y_reportar',
            'politica_intra_archivo' => 'productos_distintos',
            'explicacion'            => 'Se van a actualizar los articulos existentes.',
        ], $resultado);
    }

    /**
     * Claude devuelve el valor legado 'actualizar_uno' (stats sin repetidos): se traduce
     * a 'saltear_y_reportar'. Traduccion de compatibilidad del prompt 02 del grupo 284.
     *
     * @return void
     */
    public function test_actualizar_uno_legado_se_traduce_a_saltear_y_reportar()
    {
        config(['services.anthropic.api_key' => 'fake-key']);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuesta_claude([
                'politica_colision'      => 'actualizar_uno',
                'politica_intra_archivo' => 'ultima_gana',
                'explicacion'            => 'texto',
            ]), 200),
        ]);

        $resultado = $this->analyzer()->ask_claude_for_recomendation($this->stats());

        Http::assertSentCount(1);
        $this->assertSame('saltear_y_reportar', $resultado['politica_colision']);
    }

    /**
     * Claude devuelve politica_colision valida y politica_intra_archivo invalida
     * ('cualquier_cosa'): el resultado conserva la colision de Claude y trae el
     * default 'ultima_gana'. politica_intra_archivo es tolerante a proposito; la
     * colision no.
     *
     * @return void
     */
    public function test_politica_intra_invalida_cae_al_default_sin_tirar_la_recomendacion()
    {
        config(['services.anthropic.api_key' => 'fake-key']);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuesta_claude([
                'politica_colision'      => 'saltear_y_reportar',
                'politica_intra_archivo' => 'cualquier_cosa',
                'explicacion'            => 'texto',
            ]), 200),
        ]);

        $resultado = $this->analyzer()->ask_claude_for_recomendation($this->stats());

        Http::assertSentCount(1);
        $this->assertSame('saltear_y_reportar', $resultado['politica_colision']);
        $this->assertSame('ultima_gana', $resultado['politica_intra_archivo']);
    }

    /**
     * Claude devuelve politica_colision fuera del set valido ('inventada'), con stats
     * sin existentes en base: la respuesta entera se descarta y el resultado es el del
     * fallback heuristico.
     *
     * @return void
     */
    public function test_politica_colision_invalida_manda_todo_al_fallback()
    {
        config(['services.anthropic.api_key' => 'fake-key']);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuesta_claude([
                'politica_colision'      => 'inventada',
                'politica_intra_archivo' => 'ultima_gana',
                'explicacion'            => 'texto',
            ]), 200),
        ]);

        $resultado = $this->analyzer()->ask_claude_for_recomendation($this->stats());

        Http::assertSentCount(1);

        $this->assertSame([
            'politica_colision'      => 'actualizar_todos',
            'politica_intra_archivo' => 'ultima_gana',
            'explicacion'            => 'Recomendación generada automáticamente porque la IA no devolvió una respuesta válida.',
        ], $resultado);
    }

    /**
     * Stats con provider_codes_duplicados_intra_archivo = 3 y Claude devolviendo
     * 'saltear_y_reportar': el resultado es 'actualizar_todos'. Override deterministico
     * (bloque "if ($stats['provider_codes_duplicados_intra_archivo'] > 0)" en
     * ask_claude_for_recomendation()): con repetidos intra-archivo la politica_colision
     * se fuerza sin importar lo que haya dicho Claude. No es un bug si este test parece
     * "ignorar" la respuesta -- es exactamente lo que el override hace a proposito.
     *
     * @return void
     */
    public function test_override_con_repetidos_intra_archivo_fuerza_actualizar_todos()
    {
        config(['services.anthropic.api_key' => 'fake-key']);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuesta_claude([
                'politica_colision'      => 'saltear_y_reportar',
                'politica_intra_archivo' => 'ultima_gana',
                'explicacion'            => 'texto',
            ]), 200),
        ]);

        $resultado = $this->analyzer()->ask_claude_for_recomendation(
            $this->stats(['provider_codes_duplicados_intra_archivo' => 3])
        );

        Http::assertSentCount(1);
        $this->assertSame('actualizar_todos', $resultado['politica_colision']);
    }

    /* ------------------------------------------------------------------
     * Camino fallback (sin red: config(['services.anthropic.api_key' => '']))
     * ------------------------------------------------------------------ */

    /**
     * Sin api_key, call_claude() nunca llega a armar un request: stats en cero da
     * 'actualizar_todos' + 'ultima_gana'.
     *
     * @return void
     */
    public function test_fallback_sin_existentes_en_base_recomienda_actualizar_todos()
    {
        config(['services.anthropic.api_key' => '']);

        Http::fake();

        $resultado = $this->analyzer()->ask_claude_for_recomendation($this->stats());

        Http::assertNothingSent();
        $this->assertSame('actualizar_todos', $resultado['politica_colision']);
        $this->assertSame('ultima_gana', $resultado['politica_intra_archivo']);
    }

    /**
     * Umbral UMBRAL_PROPORCION_PROVIDER_CODES_REPETIDOS_FALLBACK = 0.3, fijado de los
     * dos lados: existentes_mismo_proveedor = 5, total_filas_datos = 10,
     * duplicados_intra_archivo = 3 (proporcion 0.3 exacta, la comparacion es >=) da
     * 'saltear_y_reportar'; el caso hermano con proporcion 0.29 (duplicados = 29,
     * total = 100) da 'actualizar_todos'.
     *
     * @return void
     */
    public function test_fallback_con_muchos_repetidos_recomienda_saltear()
    {
        config(['services.anthropic.api_key' => '']);

        Http::fake();

        $en_el_umbral = $this->analyzer()->ask_claude_for_recomendation($this->stats([
            'provider_codes_existentes_mismo_proveedor' => 5,
            'total_filas_datos'                         => 10,
            'provider_codes_duplicados_intra_archivo'   => 3,
        ]));

        $this->assertSame('saltear_y_reportar', $en_el_umbral['politica_colision']);

        $debajo_del_umbral = $this->analyzer()->ask_claude_for_recomendation($this->stats([
            'provider_codes_existentes_mismo_proveedor' => 5,
            'total_filas_datos'                         => 100,
            'provider_codes_duplicados_intra_archivo'   => 29,
        ]));

        $this->assertSame('actualizar_todos', $debajo_del_umbral['politica_colision']);

        Http::assertNothingSent();
    }

    /**
     * Grilla chica de stats (existentes 0/1/5 x duplicados 0/3/9 sobre total 10): la
     * politica_colision del fallback siempre es 'actualizar_todos' o
     * 'saltear_y_reportar'. crear_nuevo es la unica opcion que duplica catalogo y no
     * puede salir nunca de una heuristica sin supervision.
     *
     * @return void
     */
    public function test_fallback_nunca_recomienda_crear_nuevo()
    {
        config(['services.anthropic.api_key' => '']);

        Http::fake();

        foreach ([0, 1, 5] as $existentes) {
            foreach ([0, 3, 9] as $duplicados) {

                $resultado = $this->analyzer()->ask_claude_for_recomendation($this->stats([
                    'provider_codes_existentes_mismo_proveedor' => $existentes,
                    'total_filas_datos'                         => 10,
                    'provider_codes_duplicados_intra_archivo'   => $duplicados,
                ]));

                $this->assertContains(
                    $resultado['politica_colision'],
                    ['actualizar_todos', 'saltear_y_reportar'],
                    'existentes=' . $existentes . ' duplicados=' . $duplicados . ': el fallback nunca puede recomendar crear_nuevo.'
                );
            }
        }

        Http::assertNothingSent();
    }

    /**
     * Misma grilla que el test anterior: politica_intra_archivo del fallback siempre
     * es 'ultima_gana' -- sin Claude no hay forma de comparar nombres para distinguir
     * "mismo producto" de "productos distintos que comparten codigo".
     *
     * @return void
     */
    public function test_fallback_intra_siempre_ultima_gana()
    {
        config(['services.anthropic.api_key' => '']);

        Http::fake();

        foreach ([0, 1, 5] as $existentes) {
            foreach ([0, 3, 9] as $duplicados) {

                $resultado = $this->analyzer()->ask_claude_for_recomendation($this->stats([
                    'provider_codes_existentes_mismo_proveedor' => $existentes,
                    'total_filas_datos'                         => 10,
                    'provider_codes_duplicados_intra_archivo'   => $duplicados,
                ]));

                $this->assertSame(
                    'ultima_gana',
                    $resultado['politica_intra_archivo'],
                    'existentes=' . $existentes . ' duplicados=' . $duplicados . ': el fallback siempre usa ultima_gana.'
                );
            }
        }

        Http::assertNothingSent();
    }
}
