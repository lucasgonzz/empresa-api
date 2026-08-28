<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "El módulo de IA para proveedores debe importar el saldo actual" (Lucas).
 *
 * La importación clásica de proveedores tenía el saldo; el modal con IA lo listaba para
 * clientes pero no para proveedores, así que quien migraba proveedores con su saldo de
 * cuenta corriente, con IA no podía.
 *
 * La mitad de atrás ya estaba: `ProviderImport` llama a
 * `LocalImportHelper::setSaldoInicial()`, que busca la columna con los alias
 * `['saldo_actual', 'saldo actual']`. Lo que faltaba era que el analizador conociera la
 * propiedad — sin eso la IA nunca la sugiere sola, que es exactamente lo que se pidió.
 *
 * 🔴 Este es el caso INVERSO al del resto de la misión: los mensajes de error van en los
 * tres analizadores, el saldo va sólo en dos. Un artículo no tiene cuenta corriente.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class SaldoDeProveedorTest extends TestCase
{
    /**
     * Lee la constante protegida SYSTEM_PROPERTIES de un analizador.
     *
     * @param  string $clase
     * @return array
     */
    protected function system_properties($clase)
    {
        $reflection = new \ReflectionClass($clase);

        return $reflection->getConstant('SYSTEM_PROPERTIES');
    }

    /**
     * El analizador de proveedores conoce la propiedad.
     *
     * @return void
     */
    public function test_el_analizador_de_proveedores_conoce_el_saldo_actual()
    {
        $this->assertContains(
            'saldo_actual',
            $this->system_properties(AiProviderAnalyzer::class),
            'Sin esto la IA nunca sugiere sola la columna de saldo y el usuario tiene que mapearla a mano.'
        );
    }

    /**
     * Y el de clientes la sigue teniendo: es el molde que se copió, no se toca.
     *
     * @return void
     */
    public function test_el_analizador_de_clientes_sigue_teniendo_el_saldo_actual()
    {
        $this->assertContains('saldo_actual', $this->system_properties(AiClientAnalyzer::class));
    }

    /**
     * ⚠️ Y el de artículos NO la tiene.
     *
     * Los tres analizadores son copias casi idénticas y el reflejo instalado en este módulo
     * es "si lo agregás en uno, agregalo en los tres". Acá ese reflejo está mal: un artículo
     * no tiene cuenta corriente, y ofrecer "saldo actual" en el mapeo de artículos sería una
     * opción que no hace nada.
     *
     * @return void
     */
    public function test_el_analizador_de_articulos_no_tiene_saldo_actual()
    {
        $this->assertNotContains(
            'saldo_actual',
            $this->system_properties(AiExcelAnalyzer::class),
            'Un artículo no tiene cuenta corriente: ofrecer la propiedad sería ofrecer un mapeo que no hace nada.'
        );
    }

    /**
     * 🔴 La clave tiene que ser una de las que el importador realmente busca.
     *
     * `LocalImportHelper::setSaldoInicial()` lee la columna por alias. Si el analizador
     * sugiriera una clave que no está en esa lista, el usuario vería la columna mapeada, la
     * importación diría que salió bien, y el saldo no se cargaría — en silencio, sin ningún
     * error. Por eso el contrato se mide contra el consumidor y no contra una constante
     * escrita a mano en el test.
     *
     * @return void
     */
    public function test_la_clave_es_una_de_las_que_busca_el_importador()
    {
        $codigo = (string) file_get_contents(base_path('app/Http/Controllers/Helpers/LocalImportHelper.php'));

        $this->assertMatchesRegularExpression(
            "/getColumnValueByAliases\(\\\$row, \[[^\]]*'saldo_actual'/",
            $codigo,
            'LocalImportHelper::setSaldoInicial() dejó de aceptar el alias "saldo_actual": '
                . 'el saldo se importaría en silencio como nada.'
        );
    }

    /**
     * De punta a punta: la propiedad y su regla tienen que viajar en el prompt que se le
     * manda a Claude.
     *
     * Que estén en la constante no alcanza — lo que la IA ve es el texto del prompt. Si la
     * lista se arma de otra fuente, o si alguien saca la regla, la constante queda verde y
     * la sugerencia no aparece igual.
     *
     * @return void
     */
    public function test_el_prompt_que_se_le_manda_a_claude_incluye_el_saldo_actual()
    {
        config(['services.anthropic.api_key' => 'fake-key']);

        /* Factory nueva: los stubs de Http::fake() se acumulan entre llamadas. */
        Http::swap(new \Illuminate\Http\Client\Factory());

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['text' => json_encode(['column_mapping' => []])],
                ],
            ], 200),
        ]);

        $analyzer = new AiProviderAnalyzer(1);

        $analyzer->analyze(__DIR__ . '/fixtures/15_una_sola_hoja.xlsx', '15_una_sola_hoja.xlsx');

        Http::assertSent(function ($request) {
            $cuerpo = $request->data();

            $prompt = isset($cuerpo['messages'][0]['content']) ? (string) $cuerpo['messages'][0]['content'] : '';

            /* La propiedad en la lista de propiedades disponibles. */
            if (strpos($prompt, 'saldo_actual') === false) {
                return false;
            }

            /* Y la regla que le dice a Claude cuándo usarla. */
            return strpos($prompt, 'cuenta corriente') !== false;
        });
    }
}
