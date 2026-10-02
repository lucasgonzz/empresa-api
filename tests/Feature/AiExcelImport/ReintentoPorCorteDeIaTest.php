<?php

namespace Tests\Feature\AiExcelImport;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Doblep herrajes (2/10/2026): una planilla de ~40 columnas hizo que la IA devolviera el
 * column_mapping cortado a la mitad (stop_reason = max_tokens) y el usuario leyó "La IA no pudo
 * interpretar esta planilla". Dos cosas se fijan acá:
 *
 *   1. El techo de salida de los tres analizadores arranca en 8000 (antes 2000 / 2000 / 4000).
 *   2. Si aun así la respuesta llega cortada, se repite UNA vez la misma llamada con el doble de
 *      techo; si se corta de nuevo, el mismo MENSAJE_IA_RESPUESTA_ILEGIBLE de siempre.
 *
 * Se llama a los analizadores directo (sin pasar por el job) porque lo que se mide es la
 * conversación con la IA: cuántos requests salen y con qué max_tokens.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class ReintentoPorCorteDeIaTest extends EmpresaTestCase
{
    const FIXTURE = '15_una_sola_hoja.xlsx';

    /** Ancho de la planilla de Doblep: el column_mapping que la IA devuelve es largo. */
    const COLUMNAS = 42;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'fake-key']);
    }

    /**
     * ID del dueño del comercio autenticado: a quien se le imputa el consumo de la IA.
     *
     * @return int
     */
    protected function dueno_id()
    {
        $user = auth()->user();

        return (int) ($user->owner_id ? $user->owner_id : $user->id);
    }

    /**
     * Ruta absoluta al fixture de la planilla.
     *
     * @return string
     */
    protected function planilla()
    {
        $ruta = base_path('tests/Import/fixtures/' . self::FIXTURE);

        $this->assertFileExists($ruta);

        return $ruta;
    }

    /** Un column_mapping ancho y completo, tal como lo devuelve la IA. */
    protected function json_completo()
    {
        $mapping = [];

        for ($i = 1; $i <= self::COLUMNAS; $i++) {
            $mapping[] = [
                'excel_column'        => 'Columna ' . $i,
                'system_property'     => null,
                'confidence'          => 0.5,
                'interpretation_note' => null,
            ];
        }

        return json_encode([
            'column_mapping'      => $mapping,
            'provider_id'         => null,
            'provider_confidence' => 'bajo',
        ]);
    }

    /** El mismo JSON, cortado a la mitad como lo deja el techo de salida. */
    protected function json_cortado()
    {
        $completo = $this->json_completo();

        return substr($completo, 0, (int) (strlen($completo) / 2));
    }

    /**
     * Cuerpo JSON de una respuesta de Anthropic.
     *
     * @param  string $texto
     * @param  string $stop_reason  end_turn | max_tokens
     * @return array
     */
    protected function cuerpo($texto, $stop_reason)
    {
        return [
            'id'          => 'msg_fake',
            'type'        => 'message',
            'role'        => 'assistant',
            'model'       => 'claude-sonnet-4-5',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'stop_reason' => $stop_reason,
            'usage'       => ['input_tokens' => 1000, 'output_tokens' => 8000],
        ];
    }

    /**
     * Fake de dos respuestas en secuencia: la del primer request y la del segundo.
     *
     * @return void
     */
    protected function fakear_dos($texto_1, $stop_1, $texto_2, $stop_2)
    {
        /* Factory nueva: Http::fake() acumula stubs y gana el primero registrado (ver MensajesDeErrorTest). */
        Http::swap(new \Illuminate\Http\Client\Factory());

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->cuerpo($texto_1, $stop_1), 200)
                ->push($this->cuerpo($texto_2, $stop_2), 200),
        ]);
    }

    /**
     * Los max_tokens de cada request que salió, en orden.
     *
     * @return array
     */
    protected function techos_enviados()
    {
        $techos = [];

        foreach (Http::recorded() as $par) {
            $techos[] = (int) $par[0]->data()['max_tokens'];
        }

        return $techos;
    }

    /* =====================================================================
     * Clientes
     * ================================================================== */

    public function test_clientes_si_la_primera_respuesta_se_corta_reintenta_una_vez_con_el_doble_de_techo()
    {
        $this->fakear_dos($this->json_cortado(), 'max_tokens', $this->json_completo(), 'end_turn');

        $resultado = (new AiClientAnalyzer($this->dueno_id()))->analyze($this->planilla(), 'clientes.xlsx');

        $this->assertCount(self::COLUMNAS, $resultado['column_mapping']);

        $techos = $this->techos_enviados();

        $this->assertCount(2, $techos, 'Tenía que haber exactamente 2 requests.');
        $this->assertSame($techos[0] * 2, $techos[1], 'El reintento tiene que llevar el doble de techo.');
    }

    public function test_clientes_si_las_dos_respuestas_se_cortan_da_el_mensaje_de_siempre_con_dos_requests()
    {
        $this->fakear_dos($this->json_cortado(), 'max_tokens', $this->json_cortado(), 'max_tokens');

        try {
            (new AiClientAnalyzer($this->dueno_id()))->analyze($this->planilla(), 'clientes.xlsx');

            $this->fail('Tenía que lanzar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertSame(AiClientAnalyzer::MENSAJE_IA_RESPUESTA_ILEGIBLE, $e->getMessage());
        }

        $this->assertCount(2, $this->techos_enviados(), 'Se reintenta una sola vez, no más.');
    }

    public function test_clientes_el_primer_request_lleva_un_techo_de_al_menos_8000()
    {
        $this->fakear_dos($this->json_completo(), 'end_turn', $this->json_completo(), 'end_turn');

        (new AiClientAnalyzer($this->dueno_id()))->analyze($this->planilla(), 'clientes.xlsx');

        $techos = $this->techos_enviados();

        $this->assertCount(1, $techos, 'Sin corte no hay reintento.');
        $this->assertGreaterThanOrEqual(8000, $techos[0]);
    }

    /* =====================================================================
     * Proveedores y artículos
     * ================================================================== */

    public function test_proveedores_si_la_primera_respuesta_se_corta_reintenta_una_vez_con_el_doble_de_techo()
    {
        $this->fakear_dos($this->json_cortado(), 'max_tokens', $this->json_completo(), 'end_turn');

        $resultado = (new AiProviderAnalyzer($this->dueno_id()))->analyze($this->planilla(), 'proveedores.xlsx');

        $this->assertCount(self::COLUMNAS, $resultado['column_mapping']);

        $techos = $this->techos_enviados();

        $this->assertCount(2, $techos);
        $this->assertGreaterThanOrEqual(8000, $techos[0]);
        $this->assertSame($techos[0] * 2, $techos[1]);
    }

    public function test_articulos_si_las_dos_respuestas_se_cortan_da_el_mensaje_de_siempre_con_dos_requests()
    {
        $this->fakear_dos($this->json_cortado(), 'max_tokens', $this->json_cortado(), 'max_tokens');

        try {
            (new AiExcelAnalyzer($this->dueno_id()))->analyze($this->planilla(), 'articulos.xlsx');

            $this->fail('Tenía que lanzar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_RESPUESTA_ILEGIBLE, $e->getMessage());
        }

        $techos = $this->techos_enviados();

        $this->assertCount(2, $techos);
        $this->assertGreaterThanOrEqual(8000, $techos[0]);
        $this->assertSame($techos[0] * 2, $techos[1]);
    }
}
