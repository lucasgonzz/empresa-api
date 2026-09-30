<?php

namespace Tests\Feature\ModelosIa;

use App\Http\Controllers\Helpers\asistente_ia\ModelosIaHelper;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión modelos-ia-por-cliente (30/9/2026) — la resolución de ModelosIaHelper, sin red.
 *
 * Protege: el default de cada tarea (Flash; Excel en Pro), qué opciones valen por tarea (imágenes sin
 * Pro), la traducción del asistente desde agente_proveedor + agente_pensamiento, y sobre todo el
 * FALLBACK SIN CLAVE: sin DEEPSEEK_API_KEY cada tarea cae a Anthropic con su modelo LEGADO (el de
 * antes de la misión), no con el "equivalente" del catálogo — es lo que hace que el deploy no cambie
 * nada en una producción donde ningún .env tiene la clave de DeepSeek. También: la caída al revés
 * (Claude elegido sin clave de Anthropic), sin claves = null, la guarda de visión con Pro, el texto
 * del primer bloque `text` y la regla de errores transitorios de la importación.
 *
 * 🔴 Ningún test sale a la red. Claves y modelos de mentira, fijados con config().
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Resolucion_por_tarea_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'           => 'clave-anthropic-de-prueba',
            'services.anthropic.model'             => 'claude-general-legado-test',
            'services.anthropic.model_agil'        => 'claude-haiku-test',
            'services.anthropic.model_equilibrado' => 'claude-sonnet-test',
            'services.anthropic.model_profundo'    => 'claude-opus-test',
            'services.deepseek.api_key'            => 'clave-deepseek-de-prueba',
            'services.deepseek.model'              => 'deepseek-general-test',
            'services.deepseek.model_agil'         => 'deepseek-flash-test',
            'services.deepseek.model_profundo'     => 'deepseek-pro-test',
            'services.deepseek.model_vision'       => 'deepseek-vision-test',
            'services.article_image_validation.model'       => 'claude-haiku-legado-imagenes-test',
            'services.importacion_excel_ia.model_anthropic' => 'claude-sonnet-legado-excel-test',
        ]);

        $this->dueno = User::create([
            'name'         => 'Comercio modelos IA R1',
            'company_name' => 'Ferreteria R1',
            'email'        => 'modelos-ia-r1-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Sin tocar nada: el asistente y las otras tres tareas en sus defaults, todos DeepSeek, con el
     * modelo del catálogo y el thinking de cada variante.
     *
     * @test
     */
    public function sin_eleccion_cada_tarea_va_a_su_default_de_deepseek()
    {
        $this->assertSame('deepseek_flash', ModelosIaHelper::opcion_elegida($this->dueno, 'asistente'));
        $this->assertSame('deepseek_flash', ModelosIaHelper::opcion_elegida($this->dueno, 'whatsapp'));
        $this->assertSame('deepseek_flash', ModelosIaHelper::opcion_elegida($this->dueno, 'imagenes'));
        $this->assertSame('deepseek_pro', ModelosIaHelper::opcion_elegida($this->dueno, 'excel'), 'Excel va en Pro: un mapeo mal hecho ensucia un catálogo entero.');

        $whatsapp = ModelosIaHelper::resolver($this->dueno, 'whatsapp');

        $this->assertSame('deepseek', $whatsapp['proveedor']);
        $this->assertSame('deepseek-flash-test', $whatsapp['modelo']);
        $this->assertSame(['type' => 'disabled'], $whatsapp['thinking']);
        $this->assertFalse($whatsapp['fallback']);

        $excel = ModelosIaHelper::resolver($this->dueno, 'excel');

        $this->assertSame('deepseek-pro-test', $excel['modelo']);
        $this->assertSame('enabled', $excel['thinking']['type'], 'Pro razona: thinking enabled.');
        $this->assertArrayHasKey('budget_tokens', $excel['thinking']);

        $asistente = ModelosIaHelper::resolver($this->dueno, 'asistente');

        $this->assertSame('deepseek_flash', $asistente['opcion_efectiva']);
        $this->assertSame('deepseek-flash-test', $asistente['modelo']);
    }

    /**
     * Imágenes no acepta Pro (no ve); las demás tareas aceptan las cinco opciones.
     *
     * @test
     */
    public function imagenes_no_acepta_deepseek_pro_y_lo_guardado_invalido_cae_al_default()
    {
        $this->assertNotContains('deepseek_pro', ModelosIaHelper::opciones_validas('imagenes'));
        $this->assertCount(5, ModelosIaHelper::opciones_validas('whatsapp'));
        $this->assertCount(5, ModelosIaHelper::opciones_validas('excel'));

        /* Un Pro escrito a mano en la columna de imágenes no se usa: vale el default. */
        $this->dueno->ia_modelo_imagenes = 'deepseek_pro';
        $this->dueno->ia_modelo_whatsapp = 'cualquier_cosa';
        $this->dueno->save();

        $this->assertSame('deepseek_flash', ModelosIaHelper::opcion_elegida($this->dueno->fresh(), 'imagenes'));
        $this->assertSame('deepseek_flash', ModelosIaHelper::opcion_elegida($this->dueno->fresh(), 'whatsapp'));
    }

    /**
     * El asistente se lee de las dos columnas de siempre, con los mismos criterios de
     * ProveedorIaHelper (equilibrado con DeepSeek es Flash).
     *
     * @test
     */
    public function el_asistente_se_traduce_desde_agente_proveedor_y_pensamiento()
    {
        $casos = [
            ['anthropic', 'agil', 'claude_haiku'],
            ['anthropic', 'equilibrado', 'claude_sonnet'],
            ['anthropic', 'profundo', 'claude_opus'],
            ['deepseek', 'agil', 'deepseek_flash'],
            ['deepseek', 'profundo', 'deepseek_pro'],
            ['deepseek', 'equilibrado', 'deepseek_flash'],
        ];

        foreach ($casos as $caso) {
            $this->dueno->agente_proveedor   = $caso[0];
            $this->dueno->agente_pensamiento = $caso[1];

            $this->assertSame($caso[2], ModelosIaHelper::opcion_elegida($this->dueno, 'asistente'), $caso[0] . '/' . $caso[1]);
        }
    }

    /**
     * 🔴 EL FALLBACK LEGADO. Sin DEEPSEEK_API_KEY (la producción de hoy), cada tarea con DeepSeek
     * elegido cae a Anthropic con el MISMO modelo que usaba antes de la misión, sin thinking, con
     * `opcion_efectiva` null (no es una opción del catálogo) y `fallback` true.
     *
     * @test
     */
    public function sin_clave_de_deepseek_cada_tarea_cae_a_su_modelo_legado_de_anthropic()
    {
        config(['services.deepseek.api_key' => null]);

        $esperados = [
            'whatsapp' => 'claude-general-legado-test',
            'imagenes' => 'claude-haiku-legado-imagenes-test',
            'excel'    => 'claude-sonnet-legado-excel-test',
        ];

        foreach ($esperados as $tarea => $modelo) {
            $resolucion = ModelosIaHelper::resolver($this->dueno, $tarea, $tarea === 'imagenes');

            $this->assertSame('anthropic', $resolucion['proveedor'], $tarea);
            $this->assertSame($modelo, $resolucion['modelo'], $tarea . ': el modelo de ANTES, no el equivalente del catálogo.');
            $this->assertNull($resolucion['thinking'], $tarea . ': a Anthropic no se le manda thinking.');
            $this->assertNull($resolucion['opcion_efectiva'], $tarea);
            $this->assertSame('deepseek_' . ($tarea === 'excel' ? 'pro' : 'flash'), $resolucion['opcion_elegida']);
            $this->assertTrue($resolucion['fallback'], $tarea);
        }

        /* El asistente: lo de siempre de ProveedorIaHelper (Flash elegido → Haiku, agil de Anthropic). */
        $asistente = ModelosIaHelper::resolver($this->dueno, 'asistente');

        $this->assertSame('anthropic', $asistente['proveedor']);
        $this->assertSame('claude-haiku-test', $asistente['modelo']);
        $this->assertTrue($asistente['fallback']);
    }

    /**
     * Al revés: Claude elegido y la instalación sin clave de Anthropic → DeepSeek Flash (Excel, Pro).
     *
     * @test
     */
    public function claude_elegido_sin_clave_de_anthropic_cae_a_deepseek_flash_salvo_excel_en_pro()
    {
        config(['services.anthropic.api_key' => null]);

        $this->dueno->ia_modelo_whatsapp = 'claude_opus';
        $this->dueno->ia_modelo_excel    = 'claude_sonnet';
        $this->dueno->save();

        $whatsapp = ModelosIaHelper::resolver($this->dueno->fresh(), 'whatsapp');

        $this->assertSame('deepseek', $whatsapp['proveedor']);
        $this->assertSame('deepseek_flash', $whatsapp['opcion_efectiva']);
        $this->assertSame('claude_opus', $whatsapp['opcion_elegida']);
        $this->assertTrue($whatsapp['fallback']);

        $excel = ModelosIaHelper::resolver($this->dueno->fresh(), 'excel');

        $this->assertSame('deepseek_pro', $excel['opcion_efectiva']);
        $this->assertSame('deepseek-pro-test', $excel['modelo']);
    }

    /**
     * Sin ninguna clave, resolver() devuelve null en las cuatro tareas: cada llamador hace su "sin
     * configurar" de siempre.
     *
     * @test
     */
    public function sin_ninguna_clave_resolver_devuelve_null()
    {
        config(['services.anthropic.api_key' => null, 'services.deepseek.api_key' => null]);

        foreach (ModelosIaHelper::TAREAS as $tarea) {
            $this->assertNull(ModelosIaHelper::resolver($this->dueno, $tarea), $tarea);
        }
    }

    /**
     * WhatsApp con Pro elegido: sin foto va a Pro; con foto va al modelo de visión de DeepSeek, con
     * el thinking de Pro (Flash también razona). Con Claude no cambia nada: todos ven.
     *
     * @test
     */
    public function whatsapp_con_pro_y_una_foto_va_al_modelo_de_vision_de_deepseek()
    {
        $this->dueno->ia_modelo_whatsapp = 'deepseek_pro';
        $this->dueno->save();

        $sin_foto = ModelosIaHelper::resolver($this->dueno->fresh(), 'whatsapp');
        $con_foto = ModelosIaHelper::resolver($this->dueno->fresh(), 'whatsapp', true);

        $this->assertSame('deepseek-pro-test', $sin_foto['modelo']);
        $this->assertFalse($sin_foto['vision']);

        $this->assertSame('deepseek-vision-test', $con_foto['modelo'], 'Pro no ve imágenes y DeepSeek no avisa.');
        $this->assertTrue($con_foto['vision']);
        $this->assertSame('enabled', $con_foto['thinking']['type']);
        $this->assertSame('deepseek_pro', $con_foto['opcion_efectiva']);
    }

    /**
     * El texto de una respuesta es el del PRIMER bloque `text`: con thinking prendido el primero es
     * `thinking` y content[0] no tiene texto.
     *
     * @test
     */
    public function el_texto_es_el_del_primer_bloque_text_y_no_content_cero()
    {
        $body = [
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Razono sobre las columnas...', 'signature' => 'x'],
                ['type' => 'text', 'text' => '{"column_mapping":{}}'],
                ['type' => 'text', 'text' => 'segundo bloque'],
            ],
        ];

        $this->assertSame('{"column_mapping":{}}', ModelosIaHelper::texto_de_respuesta($body));
        $this->assertNull(ModelosIaHelper::texto_de_respuesta(['content' => [['type' => 'thinking', 'thinking' => 'solo pensó']]]));
        $this->assertNull(ModelosIaHelper::texto_de_respuesta(['content' => []]));
        $this->assertSame('sin tipo', ModelosIaHelper::texto_de_respuesta(['content' => [['text' => 'sin tipo']]]));
    }

    /**
     * Errores transitorios de la importación: con Anthropic la regla de siempre (429 es rechazo);
     * con DeepSeek la de ProveedorIaHelper (429/500/503 son saturación; 402 de saldo es rechazo).
     *
     * @test
     */
    public function la_regla_de_error_transitorio_de_la_importacion_depende_del_proveedor()
    {
        $respuesta = function ($status, $tipo) {
            return new Response(new \GuzzleHttp\Psr7\Response($status, [], json_encode(['error' => ['type' => $tipo, 'message' => 'x']])));
        };

        $this->assertFalse(ModelosIaHelper::error_transitorio_de_importacion($respuesta(429, 'rate_limit_error'), 'anthropic'));
        $this->assertTrue(ModelosIaHelper::error_transitorio_de_importacion($respuesta(529, 'overloaded_error'), 'anthropic'));

        $this->assertTrue(ModelosIaHelper::error_transitorio_de_importacion($respuesta(429, 'rate_limit_error'), 'deepseek'));
        $this->assertTrue(ModelosIaHelper::error_transitorio_de_importacion($respuesta(503, 'server_error'), 'deepseek'));
        $this->assertFalse(ModelosIaHelper::error_transitorio_de_importacion($respuesta(402, 'insufficient_balance'), 'deepseek'));
    }
}
