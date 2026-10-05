<?php

namespace Tests\Feature\Precios\CatalogoPorLista;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\article\ProviderImportMappingHelper;
use Illuminate\Support\Facades\DB;

/**
 * La columna "visible en la tienda" en el análisis de la planilla con IA (misión
 * catalogo-por-lista-tienda, 5/10/2026): la propiedad codificada es
 * `price_type_{id}_visible_en_tienda` (el SPA la expande a la columna `visible_en_tienda_<lista>`).
 *
 *  - Se le explica a la IA SOLO si hay listas con el catálogo restringido, y solo para ellas.
 *  - 🔴 Sin listas restringidas (hoy, todos los comercios) el prompt queda byte a byte igual.
 *  - De la respuesta de la IA se acepta solo para listas restringidas del dueño.
 *
 * Los tres métodos son protegidos y no llaman a ninguna IA: se invocan por reflexión.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group catalogo-por-lista
 */
class Mapeo_de_la_columna_por_ia_Test extends CatalogoPorListaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_comercios();
    }

    /**
     * Invoca un método protegido del analizador.
     *
     * @param  \App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer $analizador
     * @param  string                                                       $metodo
     * @param  array                                                        $argumentos
     * @return mixed
     */
    protected function invocar($analizador, $metodo, array $argumentos = [])
    {
        $reflexion = new \ReflectionMethod($analizador, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($analizador, $argumentos);
    }

    /**
     * Una muestra mínima de planilla.
     *
     * @return array
     */
    protected function muestra()
    {
        return [
            'headers' => ['Codigo', 'Precio mayorista', 'Visible mayorista'],
            'rows'    => [['A-1', '100', 'Si']],
        ];
    }

    /**
     * Las listas llegan con su interruptor y el prompt explica la columna solo para la restringida.
     *
     * @return void
     */
    public function test_el_prompt_ofrece_la_columna_solo_para_listas_restringidas()
    {
        $analizador = new AiExcelAnalyzer($this->dueno->id);

        $listas = $this->invocar($analizador, 'get_available_price_types');

        $por_id = [];
        foreach ($listas as $lista) {
            $por_id[$lista['id']] = $lista;
        }

        $this->assertSame(1, $por_id[$this->mayorista->id]['catalogo_restringido_en_tienda']);
        $this->assertSame(0, $por_id[$this->minorista->id]['catalogo_restringido_en_tienda']);

        $prompt = $this->invocar($analizador, 'build_prompt', [$this->muestra(), [], 'lista.xlsx', [], $listas]);

        $this->assertNotFalse(strpos($prompt, 'price_type_{id}_visible_en_tienda'), 'La IA tiene que conocer la propiedad.');
        $this->assertNotFalse(strpos($prompt, $this->mayorista->name . ' (catálogo restringido en la tienda)'), 'Mayorista va marcada.');
        $this->assertFalse(strpos($prompt, $this->minorista->name . ' (catálogo restringido en la tienda)'), 'Minorista no.');
    }

    /**
     * 🔴 Sin listas restringidas, el prompt es exactamente el de antes de la misión (el que se arma
     * con las listas sin la clave nueva).
     *
     * @return void
     */
    public function test_sin_listas_restringidas_el_prompt_no_cambia()
    {
        DB::table('price_types')->where('id', $this->mayorista->id)->update(['catalogo_restringido_en_tienda' => null]);

        $analizador = new AiExcelAnalyzer($this->dueno->id);

        $listas = $this->invocar($analizador, 'get_available_price_types');

        // Las mismas listas como las armaba el código de antes: solo id y name.
        $listas_de_antes = [];
        foreach ($listas as $lista) {
            $listas_de_antes[] = ['id' => $lista['id'], 'name' => $lista['name']];
        }

        $prompt       = $this->invocar($analizador, 'build_prompt', [$this->muestra(), [], 'lista.xlsx', [], $listas]);
        $prompt_antes = $this->invocar($analizador, 'build_prompt', [$this->muestra(), [], 'lista.xlsx', [], $listas_de_antes]);

        $this->assertSame($prompt_antes, $prompt);
        $this->assertFalse(strpos($prompt, 'visible_en_tienda'), 'Sin listas restringidas la IA no oye hablar de la columna.');
    }

    /**
     * De la respuesta de la IA, la propiedad vale solo para una lista restringida del dueño.
     *
     * @return void
     */
    public function test_la_respuesta_de_la_ia_se_valida_contra_las_listas_restringidas()
    {
        $analizador = new AiExcelAnalyzer($this->dueno->id);

        $listas = $this->invocar($analizador, 'get_available_price_types');

        $respuesta_de_la_ia = json_encode([
            'column_mapping' => [
                ['excel_column' => 'Visible mayorista', 'system_property' => 'price_type_' . $this->mayorista->id . '_visible_en_tienda'],
                ['excel_column' => 'Visible minorista', 'system_property' => 'price_type_' . $this->minorista->id . '_visible_en_tienda'],
                ['excel_column' => 'Visible ajena',     'system_property' => 'price_type_' . $this->lista_ajena->id . '_visible_en_tienda'],
                ['excel_column' => 'Precio minorista',  'system_property' => 'price_type_' . $this->minorista->id . '_final_price'],
            ],
            'provider_id'         => null,
            'provider_confidence' => 'bajo',
        ]);

        $parseado = $this->invocar($analizador, 'parse_claude_response', [$respuesta_de_la_ia, [], [], $listas]);

        $propiedades = [];
        foreach ($parseado['column_mapping'] as $columna) {
            $propiedades[$columna['excel_column']] = $columna['system_property'];
        }

        $this->assertSame('price_type_' . $this->mayorista->id . '_visible_en_tienda', $propiedades['Visible mayorista'], 'Restringida y del dueño: se acepta.');
        $this->assertNull($propiedades['Visible minorista'], 'Lista sin restricción: se descarta.');
        $this->assertNull($propiedades['Visible ajena'], 'Lista ajena: se descarta.');
        $this->assertSame('price_type_' . $this->minorista->id . '_final_price', $propiedades['Precio minorista'], 'Lo de siempre sigue igual.');
    }

    /**
     * La configuración de columnas guardada por proveedor (ProviderImportMappingHelper) también
     * reconoce la propiedad, con la misma validación por id de lista que las demás.
     *
     * @return void
     */
    public function test_el_mapeo_guardado_por_proveedor_reconoce_la_propiedad()
    {
        $reflexion = new \ReflectionMethod(ProviderImportMappingHelper::class, 'propiedad_guardada_valida');
        $reflexion->setAccessible(true);

        $propiedad = 'price_type_' . $this->mayorista->id . '_visible_en_tienda';

        $valida = $reflexion->invoke(null, ['system_property' => $propiedad], [
            'addresses'   => [],
            'price_types' => [(int) $this->mayorista->id],
        ]);

        $de_una_lista_que_ya_no_esta = $reflexion->invoke(null, ['system_property' => $propiedad], [
            'addresses'   => [],
            'price_types' => [],
        ]);

        $this->assertSame($propiedad, $valida);
        $this->assertNull($de_una_lista_que_ya_no_esta);
    }
}
