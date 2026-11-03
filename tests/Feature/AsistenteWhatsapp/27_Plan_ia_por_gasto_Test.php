<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\TopeDeTokensHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTokenUsage;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EscondeColumnas;

/**
 * El tope mensual del plan de IA medido en plata (misión plan-ia-por-gasto, 2/11/2026).
 *
 * Pedido de Lucas: «el límite no sería en sí la cantidad de tokens usados, sino la plata gastada de esos
 * tokens». Medido en Ferre Total: 11,3 millones de tokens de caché de DeepSeek Flash cuestan 7 centavos y
 * 217 mil de salida de Sonnet 5, US$ 2,17.
 *
 * Protege:
 *   - el gasto: las cuatro puntas, varios modelos, y la misma cantidad de tokens da plata distinta;
 *   - el caso real de Ferre Total;
 *   - el corte por gasto aunque los tokens no lleguen, y que con tope de gasto los tokens no corten;
 *   - la tarifa: exacta, por prefijo con fecha, y un modelo sin tarifa se cobra a la más cara de su proveedor
 *     (revisión del contrato, 2/11/2026: a la más cara de toda la tabla, un id nuevo de DeepSeek dejaba a un
 *     negocio sin agente), con un warning por modelo y por día;
 *   - sin tope de gasto, todo igual que antes;
 *   - el contrato con el admin: con y sin las dos claves nuevas, y lo que se rechaza;
 *   - 🔴 el dueño no ve dólares ni precios: mi-consumo-ia, get_user del dueño y del empleado, el modelo
 *     serializado y audit_logs;
 *   - un deploy que subió los archivos y todavía no migró (columnas ausentes).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Plan_ia_por_gasto_Test extends AsistenteWhatsappTestCase
{
    use EscondeColumnas;

    /** Las columnas nuevas del dueño. */
    const COLUMNA_TOPE = 'plan_ia_tope_gasto_usd_mensual';
    const COLUMNA_PRECIOS = 'plan_ia_precios';

    /**
     * Copia de las filas de config/ia_precios.php del admin que usan estos tests, en dólares por millón.
     * Son los precios del caso real: si cambian allá, estos no tienen por qué cambiar.
     */
    const PRECIOS = [
        'claude-sonnet-5'   => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
        'claude-sonnet-4-5' => ['input' => 3.00, 'output' => 15.00, 'cache_write' => 3.75, 'cache_read' => 0.30],
        'claude-haiku-4-5'  => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],
        'claude-opus-5'     => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'deepseek-flash'    => ['input' => 0.30, 'output' => 1.20, 'cache_write' => 0.00, 'cache_read' => 0.006],
        'deepseek-v4-pro'   => ['input' => 1.32, 'output' => 3.96, 'cache_write' => 0.00, 'cache_read' => 0.044],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        /* Si una corrida anterior se cortó con una columna escondida, se devuelve antes de nada. */
        $this->restaurar_columna_si_quedo_escondida('users', self::COLUMNA_TOPE);
        $this->restaurar_columna_si_quedo_escondida('users', self::COLUMNA_PRECIOS);
    }

    /* -------------------------------------------------------------------------------------------------
     | El gasto
     * ---------------------------------------------------------------------------------------------- */

    /** @test */
    public function el_gasto_multiplica_las_cuatro_puntas_por_la_tarifa_de_cada_modelo()
    {
        $this->con_tope_de_gasto(100);

        // 2,00 + 1,00 + 0,50 + 0,20 = 3,70.
        $this->consumo('claude-sonnet-5', 1000000, 100000, 200000, 1000000);
        // 0,30 + 0,006 = 0,306: DeepSeek no cobra la escritura en caché.
        $this->consumo('deepseek-flash', 1000000, 0, 500000, 1000000);

        $gasto = TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh());

        $this->assertEqualsWithDelta(4.006, $gasto, 0.000001);
    }

    /** @test */
    public function la_misma_cantidad_de_tokens_con_modelos_distintos_da_plata_distinta()
    {
        $otro = $this->otro_dueno();

        $this->con_tope_de_gasto(100);
        $this->con_tope_de_gasto(100, self::PRECIOS, $otro);

        $this->consumo('deepseek-flash', 0, 1000000);
        $this->consumo('claude-opus-5', 0, 1000000, 0, 0, 'chat_mensaje', $otro->id);

        $del_comercio = TopeDeTokensHelper::estado($this->comercio->fresh());
        $del_otro = TopeDeTokensHelper::estado($otro->fresh());

        $this->assertSame($del_comercio['consumo_tokens'], $del_otro['consumo_tokens'], 'Los tokens son los mismos.');
        $this->assertEqualsWithDelta(1.20, $del_comercio['consumo_usd'], 0.000001);
        $this->assertEqualsWithDelta(25.00, $del_otro['consumo_usd'], 0.000001);
    }

    /**
     * Los tres renglones medidos en Ferre Total, del 28/10 al 2/11, a precio pleno: Flash 0,28, Pro 1,66 y
     * Sonnet 5 (escaneo de factura) 2,41.
     *
     * @test
     */
    public function el_caso_real_de_ferre_total()
    {
        $this->con_tope_de_gasto(100);

        $this->consumo('deepseek-flash', 614000, 27000, 0, 11300000);
        $this->consumo('deepseek-v4-pro', 759000, 105000, 0, 5600000);
        $this->consumo('claude-sonnet-5', 122000, 217000, 0, 0, 'escaneo_factura');

        $this->assertEqualsWithDelta(0.2844 + 1.66408 + 2.414, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);
    }

    /**
     * El porqué de la misión: contra un tope de tokens, 11,3 millones de caché de Flash se pasan y 217 mil
     * de salida de Sonnet 5 no. En plata es al revés.
     *
     * @test
     */
    public function once_millones_de_cache_de_flash_cuestan_menos_que_217_mil_de_salida_de_sonnet()
    {
        $con_flash = $this->comercio;
        $con_sonnet = $this->otro_dueno();

        foreach ([$con_flash, $con_sonnet] as $dueno) {
            $this->con_tope_de_gasto(1, self::PRECIOS, $dueno);
            $dueno->plan_ia_tope_tokens_mensual = 5000000;
            $dueno->save();
        }

        $this->consumo('deepseek-flash', 0, 0, 0, 11300000, 'chat_mensaje', $con_flash->id);
        $this->consumo('claude-sonnet-5', 0, 217000, 0, 0, 'escaneo_factura', $con_sonnet->id);

        $estado_flash = TopeDeTokensHelper::estado($con_flash->fresh());
        $estado_sonnet = TopeDeTokensHelper::estado($con_sonnet->fresh());

        $this->assertEqualsWithDelta(0.0678, $estado_flash['consumo_usd'], 0.000001);
        $this->assertFalse($estado_flash['supero'], '7 centavos no llegan a un tope de un dólar, aunque sean 11,3 millones de tokens.');

        $this->assertEqualsWithDelta(2.17, $estado_sonnet['consumo_usd'], 0.000001);
        $this->assertTrue($estado_sonnet['supero'], 'US$ 2,17 se pasan del tope de un dólar, aunque sean 217 mil tokens.');
    }

    /** @test */
    public function suman_todos_los_procesos_y_solo_este_mes_y_este_dueno()
    {
        $otro = $this->otro_dueno();
        $this->con_tope_de_gasto(100);

        $this->consumo('claude-sonnet-5', 0, 100000, 0, 0, 'chat_mensaje');
        $this->consumo('claude-sonnet-5', 0, 100000, 0, 0, 'escaneo_factura');
        $this->consumo('claude-sonnet-5', 0, 100000, 0, 0, 'chat_transcripcion_foto');
        $this->consumo('claude-sonnet-5', 0, 100000, 0, 0, 'importacion_excel');

        // Nada de esto cuenta: otro dueño, y el mes pasado.
        $this->consumo('claude-opus-5', 0, 1000000, 0, 0, 'chat_mensaje', $otro->id);
        $this->consumo('claude-opus-5', 0, 1000000, 0, 0, 'chat_mensaje', null, Carbon::now()->startOfMonth()->subSecond());

        $this->assertEqualsWithDelta(4.00, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);
    }

    /* -------------------------------------------------------------------------------------------------
     | El corte
     * ---------------------------------------------------------------------------------------------- */

    /** @test */
    public function corta_por_gasto_aunque_los_tokens_no_lleguen()
    {
        $this->con_tope_de_gasto(1);
        $this->comercio->plan_ia_tope_tokens_mensual = 50000000;
        $this->comercio->save();

        $this->consumo('claude-opus-5', 0, 40000); // US$ 1,00 justo.

        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());

        $this->assertTrue($estado['supero']);
        $this->assertTrue($estado['supero_gasto']);
        $this->assertFalse($estado['supero_tokens']);
        $this->assertFalse($estado['supero_interacciones']);
        $this->assertSame(40000, $estado['consumo_tokens']);
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE, TopeDeTokensHelper::mensaje_limite($estado));
    }

    /**
     * El corte de verdad en los dos caminos: el panel (send_message) y el WhatsApp del asistente.
     *
     * @test
     */
    public function pasado_de_gasto_el_panel_y_el_whatsapp_contestan_el_limite_sin_despachar()
    {
        Queue::fake();
        $this->dar_extension();
        $this->con_tope_de_gasto(1);
        $this->consumo('claude-opus-5', 0, 40000);

        $conversacion = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $r = $this->actingAs($this->comercio, 'sanctum')
                  ->postJson('api/ai-conversations/' . $conversacion->id . '/messages', [
                      'contenido' => '¿Cómo venís hoy?',
                      'acciones'  => true,
                  ]);

        $r->assertStatus(201);
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE, $r->json('assistant_message.contenido'));

        $this->conversacion_whatsapp(now()->subDay());

        $this->mandar(['texto' => '¿Cómo venís hoy?', 'whatsapp_message_id' => 'wamid.gasto.' . uniqid()])
             ->assertStatus(202);

        $por_whatsapp = AiMessage::where('rol', 'assistant')
            ->where('canal', AiMessage::CANAL_WHATSAPP)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($por_whatsapp);
        $this->assertSame('listo', (string) $por_whatsapp->estado);
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE, (string) $por_whatsapp->contenido);

        Queue::assertNotPushed(ResponderMensajeChatIaJob::class);
    }

    /** @test */
    public function con_tope_de_gasto_los_tokens_no_cortan_ni_avisan()
    {
        Queue::fake();
        $this->dar_extension();
        $this->con_tope_de_gasto(5);
        $this->comercio->plan_ia_tope_tokens_mensual = 1000;
        $this->comercio->save();

        // 11,3 millones de tokens contra un tope de mil, y 7 centavos contra un tope de 5 dólares.
        $this->consumo('deepseek-flash', 0, 0, 0, 11300000);

        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());

        $this->assertFalse($estado['supero']);
        $this->assertFalse($estado['cerca']);
        $this->assertFalse($estado['supero_tokens']);
        $this->assertNull($estado['tope_tokens'], 'Un tope que no corta no se informa como vigente.');
        $this->assertSame(11300000, $estado['consumo_tokens']);

        $this->conversacion_whatsapp(now()->subDay());

        $this->mandar(['texto' => '¿Cómo venís hoy?', 'whatsapp_message_id' => 'wamid.gasto.' . uniqid()])
             ->assertStatus(202);

        Queue::assertPushed(ResponderMensajeChatIaJob::class);
    }

    /** @test */
    public function avisa_cerca_con_el_mismo_umbral()
    {
        $this->con_tope_de_gasto(10);

        $this->consumo('claude-sonnet-5', 0, 790000); // US$ 7,90.
        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());
        $this->assertFalse($estado['cerca']);

        $this->consumo('claude-sonnet-5', 0, 10000); // US$ 8,00: el 80 %.
        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());
        $this->assertTrue($estado['cerca']);
        $this->assertTrue($estado['cerca_del_mes']);
        $this->assertFalse($estado['supero']);
    }

    /** @test */
    public function el_tope_de_interacciones_sigue_cortando_con_tope_de_gasto()
    {
        $this->con_tope_de_gasto(100);
        $this->comercio->plan_ia_tope_interacciones_diarias = 1;
        $this->comercio->save();

        AiTokenUsage::create($this->fila('deepseek-flash', 1, 1, 0, 0, 'chat_mensaje', $this->comercio->id) + ['referencia_id' => 1]);

        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());

        $this->assertTrue($estado['supero']);
        $this->assertTrue($estado['supero_interacciones']);
        $this->assertFalse($estado['supero_gasto']);
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE_DIARIO, TopeDeTokensHelper::mensaje_limite($estado), 'Solo el diario: mañana se renueva.');
    }

    /** @test */
    public function con_los_dos_pasados_el_texto_es_el_del_mes()
    {
        $estado = ['supero_tokens' => false, 'supero_interacciones' => true, 'supero_gasto' => true];

        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE, TopeDeTokensHelper::mensaje_limite($estado), 'Mañana no se arregla: el gasto es del mes.');
    }

    /* -------------------------------------------------------------------------------------------------
     | La tarifa
     * ---------------------------------------------------------------------------------------------- */

    /** @test */
    public function un_modelo_con_fecha_al_final_usa_la_tarifa_de_su_prefijo()
    {
        $this->con_tope_de_gasto(100);

        // A la de claude-sonnet-4-5, no a la más cara: US$ 15 y no US$ 25.
        $this->consumo('claude-sonnet-4-5-20250929', 0, 1000000);

        $this->assertEqualsWithDelta(15.00, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);
    }

    /** @test */
    public function gana_el_prefijo_mas_largo()
    {
        $this->con_tope_de_gasto(100, [
            'modelo-x'   => ['input' => 0, 'output' => 1.00, 'cache_write' => 0, 'cache_read' => 0],
            'modelo-x-5' => ['input' => 0, 'output' => 2.00, 'cache_write' => 0, 'cache_read' => 0],
        ]);

        $this->consumo('modelo-x-5-20250929', 0, 1000000);

        $this->assertEqualsWithDelta(2.00, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);
    }

    /**
     * 🔴 Un modelo desconocido nunca es gratis: se cobra a la tarifa más cara de la tabla, con un warning en
     * el log una sola vez por proceso, aunque estado() se llame muchas veces.
     *
     * @test
     */
    public function un_modelo_sin_tarifa_se_cobra_a_la_mas_cara_con_un_solo_warning()
    {
        Log::spy();

        $this->con_tope_de_gasto(100);

        $modelo = 'modelo-sin-tarifa-' . uniqid();

        $this->consumo($modelo, 1000000, 1000000);

        $dueno = $this->comercio->fresh();

        // La más cara de la tabla es claude-opus-5: 5,00 + 25,00.
        $this->assertEqualsWithDelta(30.00, TopeDeTokensHelper::estado($dueno)['consumo_usd'], 0.000001);
        $this->assertEqualsWithDelta(30.00, TopeDeTokensHelper::estado($dueno)['consumo_usd'], 0.000001);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje, $contexto = []) use ($modelo) {
                return isset($contexto['modelo']) && $contexto['modelo'] === $modelo;
            })
            ->once();
    }

    /**
     * La frontera del guion: `claude-haiku-4-55` es otro modelo, no una fecha de `claude-haiku-4-5`. Cae en la
     * más cara, y un modelo vacío también.
     *
     * @test
     */
    public function un_prefijo_sin_guion_no_es_tarifa_y_un_modelo_vacio_tampoco()
    {
        $this->con_tope_de_gasto(100);

        $this->consumo('claude-haiku-4-55', 0, 1000000);
        $this->assertEqualsWithDelta(25.00, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);

        $this->consumo('', 0, 1000000);
        $this->assertEqualsWithDelta(50.00, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);
    }

    /**
     * Una llamada que volvió sin `usage` se graba en cero (AiTokenUsageHelper): no cuesta ni ensucia el log.
     *
     * @test
     */
    public function una_fila_sin_tokens_no_cuesta_ni_avisa()
    {
        Log::spy();

        $this->con_tope_de_gasto(100);

        $modelo = 'modelo-en-cero-' . uniqid();

        $this->consumo($modelo, 0, 0);

        $this->assertSame(0.0, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()));

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * 🔴 El caso que encontró la revisión: un id de DeepSeek que no está en la tabla (lo permite
     * DEEPSEEK_MODEL). Con el volumen de Ferre Total y la más cara de TODA la tabla, Opus 5, daba US$ 9,40 en
     * vez de US$ 0,28, y un tope de US$ 5 dejaba al negocio sin agente con un warning como único rastro. A la
     * más cara de DeepSeek, que es Pro: 0,81048 + 0,10692 + 0,4972.
     *
     * @test
     */
    public function un_id_de_deepseek_que_no_esta_en_la_tabla_se_cobra_a_la_mas_cara_de_deepseek()
    {
        $this->con_tope_de_gasto(5);

        $this->consumo_de('deepseek', 'deepseek-v5-que-no-esta', 614000, 27000, 0, 11300000);

        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());

        $this->assertEqualsWithDelta(1.4146, $estado['consumo_usd'], 0.000001);
        $this->assertFalse($estado['supero'], 'Un id nuevo de DeepSeek no puede dejar al negocio sin agente.');
    }

    /**
     * Lo mismo para Claude: la más cara de sus modelos, aunque otro proveedor tenga una más cara. El prefijo
     * corta en un guion: `claudette-carisimo` no es un modelo de Claude.
     *
     * @test
     */
    public function un_id_de_claude_que_no_esta_en_la_tabla_se_cobra_a_la_mas_cara_de_claude()
    {
        $this->con_tope_de_gasto(1000, [
            'claude-chico'       => ['input' => 1, 'output' => 2, 'cache_write' => 1, 'cache_read' => 0.1],
            'claude-mediano'     => ['input' => 3, 'output' => 15, 'cache_write' => 3.75, 'cache_read' => 0.3],
            'claudette-carisimo' => ['input' => 80, 'output' => 90, 'cache_write' => 80, 'cache_read' => 80],
            'deepseek-carisimo'  => ['input' => 100, 'output' => 200, 'cache_write' => 100, 'cache_read' => 100],
        ]);

        $this->consumo_de('anthropic', 'claude-que-no-esta', 0, 1000000);

        $this->assertEqualsWithDelta(15.00, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);
    }

    /**
     * Sin ningún modelo de su proveedor en la tabla, recién ahí la más cara de todas: sigue sin ser gratis.
     *
     * @test
     */
    public function sin_modelos_de_su_proveedor_en_la_tabla_va_a_la_mas_cara_de_todas()
    {
        $this->con_tope_de_gasto(1000, [
            'claude-opus-5' => self::PRECIOS['claude-opus-5'],
        ]);

        $this->consumo_de('deepseek', 'deepseek-que-no-esta', 0, 1000000);

        $this->assertEqualsWithDelta(25.00, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);
    }

    /** @test */
    public function un_proveedor_que_no_se_conoce_va_a_la_mas_cara_de_todas()
    {
        $this->con_tope_de_gasto(1000);

        $this->consumo_de('google', 'gemini-que-no-esta', 0, 1000000);

        $this->assertEqualsWithDelta(25.00, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);
    }

    /**
     * 🔴 El único modelo de OpenAI de la tabla es un embedding, con la salida en cero. Un `gpt-*` desconocido
     * que genera salida no se puede cobrar a esa tarifa: saldría gratis, que es justo lo que no puede pasar.
     * `gpt-4o-mini-transcribe` está en este caso hasta que la tabla tenga su precio.
     *
     * @test
     */
    public function un_gpt_con_salida_no_sale_gratis_por_la_tarifa_del_embedding()
    {
        $this->con_tope_de_gasto(1000, array_merge(self::PRECIOS, [
            'text-embedding-3-small' => ['input' => 0.02, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0],
        ]));

        $this->consumo_de('openai', 'gpt-4o-mini-transcribe', 0, 1000000);

        $this->assertEqualsWithDelta(25.00, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);
    }

    /**
     * Un embedding nuevo no genera salida: la tarifa de OpenAI le sirve, y no se lo cobra como a Opus.
     *
     * @test
     */
    public function un_embedding_que_no_esta_en_la_tabla_usa_la_tarifa_de_openai()
    {
        $this->con_tope_de_gasto(1000, array_merge(self::PRECIOS, [
            'text-embedding-3-small' => ['input' => 0.02, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0],
        ]));

        $this->consumo_de('openai', 'text-embedding-3-large', 1000000, 0);

        $this->assertEqualsWithDelta(0.02, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);
    }

    /**
     * El día que la tabla tenga un `gpt-*`, un `gpt-*` desconocido se cobra a ése.
     *
     * @test
     */
    public function con_un_gpt_en_la_tabla_un_gpt_que_no_esta_usa_esa_tarifa()
    {
        $this->con_tope_de_gasto(1000, array_merge(self::PRECIOS, [
            'text-embedding-3-small' => ['input' => 0.02, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0],
            'gpt-4o-mini'            => ['input' => 0.15, 'output' => 0.60, 'cache_write' => 0, 'cache_read' => 0.075],
        ]));

        $this->consumo_de('openai', 'gpt-que-no-esta', 0, 1000000);

        $this->assertEqualsWithDelta(0.60, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);
    }

    /**
     * `proveedor` está en el agrupado: el mismo nombre de modelo con dos proveedores se cobra por separado.
     *
     * @test
     */
    public function el_mismo_modelo_con_dos_proveedores_se_cobra_por_separado()
    {
        $this->con_tope_de_gasto(1000);

        $this->consumo_de('anthropic', 'modelo-raro', 0, 1000000);
        $this->consumo_de('deepseek', 'modelo-raro', 0, 1000000);

        $this->assertEqualsWithDelta(25.00 + 3.96, TopeDeTokensHelper::gasto_del_mes($this->comercio->fresh()), 0.000001);
    }

    /**
     * 🔴 Bajo PHP-FPM el `static` dura un request: cada request web repetía el warning. Uno por modelo y por
     * día, aunque cada request arranque de cero.
     *
     * @test
     */
    public function el_warning_sale_una_vez_por_dia_aunque_cada_request_arranque_de_cero()
    {
        Log::spy();

        $this->con_tope_de_gasto(100);

        $modelo = 'modelo-por-dia-' . uniqid();

        $this->consumo($modelo, 0, 1000000);

        $dueno = $this->comercio->fresh();

        TopeDeTokensHelper::estado($dueno);
        $this->como_en_un_request_nuevo();
        TopeDeTokensHelper::estado($dueno);
        $this->como_en_un_request_nuevo();
        TopeDeTokensHelper::estado($dueno);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje, $contexto = []) use ($modelo) {
                return isset($contexto['modelo']) && $contexto['modelo'] === $modelo;
            })
            ->once();
    }

    /**
     * Al otro día vuelve a avisar: el modelo sigue sin tarifa y alguien lo tiene que ver. Va con una fecha fija
     * a mitad de mes, para que el día siguiente caiga en el mismo mes y la fila siga contando.
     *
     * @test
     */
    public function al_otro_dia_el_warning_vuelve_a_salir()
    {
        Log::spy();

        Carbon::setTestNow(Carbon::create(2026, 11, 10, 12, 0, 0));

        try {

            $this->con_tope_de_gasto(100);

            $modelo = 'modelo-al-otro-dia-' . uniqid();

            $this->consumo($modelo, 0, 1000000);

            $dueno = $this->comercio->fresh();

            TopeDeTokensHelper::estado($dueno);

            Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0));
            $this->como_en_un_request_nuevo();

            TopeDeTokensHelper::estado($dueno);

            Log::shouldHaveReceived('warning')
                ->withArgs(function ($mensaje, $contexto = []) use ($modelo) {
                    return isset($contexto['modelo']) && $contexto['modelo'] === $modelo;
                })
                ->twice();

        } finally {

            Carbon::setTestNow();
        }
    }

    /**
     * Si el caché se cae, el corte no se cae con él: se mide igual y el warning sale, una vez por proceso.
     * Mejor un aviso de más que ninguno.
     *
     * @test
     */
    public function con_el_cache_caido_mide_igual_y_el_warning_sale()
    {
        Log::spy();

        $this->con_tope_de_gasto(100);

        $modelo = 'modelo-sin-cache-' . uniqid();

        $this->consumo($modelo, 0, 1000000);

        $dueno = $this->comercio->fresh();

        Cache::shouldReceive('add')->andThrow(new \RuntimeException('El caché está caído.'));

        $this->assertEqualsWithDelta(25.00, TopeDeTokensHelper::estado($dueno)['consumo_usd'], 0.000001);
        $this->assertEqualsWithDelta(25.00, TopeDeTokensHelper::estado($dueno)['consumo_usd'], 0.000001);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje, $contexto = []) use ($modelo) {
                return isset($contexto['modelo']) && $contexto['modelo'] === $modelo;
            })
            ->once();
    }

    /**
     * 🔴 Un tope sin tabla no rige: no hay con qué medir, y no se puede ni regalar ni cortar a ciegas. Sigue
     * el de tokens. Vale para una tabla vacía y para un JSON roto.
     *
     * @test
     * @dataProvider provider_precios_que_no_sirven
     */
    public function un_tope_sin_tabla_de_precios_sigue_con_el_de_tokens($precios)
    {
        Log::spy();

        $this->comercio->plan_ia_tope_gasto_usd_mensual = 1;
        $this->comercio->plan_ia_precios = $precios;
        $this->comercio->plan_ia_tope_tokens_mensual = 1000;
        $this->comercio->save();

        $this->consumo('claude-opus-5', 0, 2000);

        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());

        $this->assertNull($estado['tope_usd']);
        $this->assertNull($estado['consumo_usd']);
        $this->assertSame(1000, $estado['tope_tokens']);
        $this->assertTrue($estado['supero_tokens']);
        $this->assertTrue($estado['supero']);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje) {
                return strpos($mensaje, 'no hay tabla de precios') !== false;
            })
            ->once();
    }

    /**
     * @return array
     */
    public function provider_precios_que_no_sirven()
    {
        return [
            'sin tabla'       => [null],
            'objeto vacío'    => ['{}'],
            'JSON roto'       => ['{no es json'],
            'filas sin forma' => ['{"modelo": 3}'],
        ];
    }

    /* -------------------------------------------------------------------------------------------------
     | Sin tope de gasto, como hoy
     * ---------------------------------------------------------------------------------------------- */

    /**
     * Sin tope de gasto, aunque la tabla de precios ya haya llegado: tokens e interacciones, los mismos
     * valores de siempre, y ni una consulta de más.
     *
     * @test
     */
    public function sin_tope_de_gasto_todo_es_como_hoy()
    {
        $this->comercio->plan_ia_precios = json_encode(self::PRECIOS);
        $this->comercio->plan_ia_tope_tokens_mensual = 1000;
        $this->comercio->plan_ia_tope_interacciones_diarias = 100;
        $this->comercio->save();

        // Con un proceso que no es el del chat: una fila `chat_mensaje` también sumaría una interacción.
        $this->consumo('deepseek-flash', 800, 0, 0, 0, 'importacion_excel');

        $dueno = $this->comercio->fresh();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $estado = TopeDeTokensHelper::estado($dueno);

        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(2, $consultas, 'Los tokens del mes y las interacciones del día, nada más.');

        $this->assertSame(800, $estado['consumo_tokens']);
        $this->assertSame(0, $estado['consumo_interacciones']);
        $this->assertSame(1000, $estado['tope_tokens']);
        $this->assertSame(100, $estado['tope_interacciones']);
        $this->assertFalse($estado['supero']);
        $this->assertTrue($estado['cerca'], '800 de 1000 es el 80 %.');
        $this->assertFalse($estado['supero_tokens']);
        $this->assertFalse($estado['supero_interacciones']);

        $this->assertNull($estado['consumo_usd']);
        $this->assertNull($estado['tope_usd']);
        $this->assertFalse($estado['supero_gasto']);
        $this->assertNull(TopeDeTokensHelper::porcentaje_del_plan($estado));

        $this->consumo('deepseek-flash', 200, 0, 0, 0, 'importacion_excel');

        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());

        $this->assertTrue($estado['supero']);
        $this->assertTrue($estado['supero_tokens']);
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE, TopeDeTokensHelper::mensaje_limite($estado));
    }

    /** @test */
    public function sin_tope_de_gasto_mi_consumo_no_cambia()
    {
        $this->dar_extension();
        $this->comercio->plan_ia_nombre = 'Agentes Light';
        $this->comercio->plan_ia_tope_tokens_mensual = 1000;
        $this->comercio->save();

        $this->consumo('deepseek-flash', 1000, 0);

        $this->actingAs($this->comercio, 'sanctum')->getJson('api/mi-consumo-ia')
             ->assertStatus(200)
             ->assertJson([
                 'plan'                => ['nombre' => 'Agentes Light', 'tope_tokens_mensual' => 1000],
                 'consumo_mes'         => ['tokens' => 1000],
                 'supero'              => true,
                 'supero_tokens'       => true,
                 'porcentaje_del_plan' => null,
             ]);
    }

    /** @test */
    public function un_tope_de_gasto_cero_es_sin_tope()
    {
        $this->con_tope_de_gasto(0);
        $this->comercio->plan_ia_tope_tokens_mensual = 1000;
        $this->comercio->save();

        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());

        $this->assertNull($estado['tope_usd']);
        $this->assertSame(1000, $estado['tope_tokens']);
    }

    /* -------------------------------------------------------------------------------------------------
     | El contrato con el admin
     * ---------------------------------------------------------------------------------------------- */

    /** @test */
    public function el_receptor_guarda_el_tope_y_los_precios()
    {
        $this->putJson('api/admin-sync/plan-ia', $this->plan_con_gasto(), $this->headers())
             ->assertStatus(200)
             ->assertJson(['ok' => true]);

        $dueno = $this->comercio->fresh();

        $this->assertSame('Agentes Light', (string) $dueno->plan_ia_nombre);
        $this->assertSame(6.41, (float) $dueno->plan_ia_tope_gasto_usd_mensual);
        $this->assertEquals(self::PRECIOS, json_decode($dueno->plan_ia_precios, true));
    }

    /**
     * Un admin viejo no manda las claves nuevas: no se tocan las columnas, y el negocio queda como estaba.
     *
     * @test
     */
    public function sin_las_claves_nuevas_no_se_tocan_las_columnas()
    {
        $this->putJson('api/admin-sync/plan-ia', $this->plan_con_gasto(), $this->headers())->assertStatus(200);

        $this->putJson('api/admin-sync/plan-ia', [
            'nombre'                     => 'Intermedio',
            'tope_tokens_mensual'        => 60000000,
            'tope_interacciones_diarias' => 50,
        ], $this->headers())->assertStatus(200);

        $dueno = $this->comercio->fresh();

        $this->assertSame('Intermedio', (string) $dueno->plan_ia_nombre, 'El resto del plan sí se guarda.');
        $this->assertSame(6.41, (float) $dueno->plan_ia_tope_gasto_usd_mensual);
        $this->assertEquals(self::PRECIOS, json_decode($dueno->plan_ia_precios, true));
    }

    /**
     * Un cliente nuevo al que un admin viejo le pushea el plan: las dos columnas quedan en null y manda el
     * tope de tokens.
     *
     * @test
     */
    public function un_cliente_al_que_nunca_le_llegaron_sigue_con_el_tope_de_tokens()
    {
        $this->putJson('api/admin-sync/plan-ia', [
            'nombre'                     => 'Intermedio',
            'tope_tokens_mensual'        => 1000,
            'tope_interacciones_diarias' => 50,
        ], $this->headers())->assertStatus(200);

        $this->consumo('deepseek-flash', 1000, 0);

        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());

        $this->assertNull($this->comercio->fresh()->plan_ia_tope_gasto_usd_mensual);
        $this->assertNull($this->comercio->fresh()->plan_ia_precios);
        $this->assertTrue($estado['supero_tokens']);
    }

    /**
     * Sin paquete, el admin manda las dos en null: ahí sí se limpian. Se pregunta has() y no input().
     *
     * @test
     */
    public function vinieron_null_y_se_limpian()
    {
        $this->putJson('api/admin-sync/plan-ia', $this->plan_con_gasto(), $this->headers())->assertStatus(200);

        $this->putJson('api/admin-sync/plan-ia', array_merge($this->plan_con_gasto(), [
            'tope_gasto_usd_mensual' => null,
            'precios_ia'             => null,
        ]), $this->headers())->assertStatus(200);

        $dueno = $this->comercio->fresh();

        $this->assertNull($dueno->plan_ia_tope_gasto_usd_mensual);
        $this->assertNull($dueno->plan_ia_precios);
    }

    /** @test */
    public function un_tope_cero_y_una_tabla_vacia_se_guardan_como_null()
    {
        $this->putJson('api/admin-sync/plan-ia', $this->plan_con_gasto(), $this->headers())->assertStatus(200);

        $this->putJson('api/admin-sync/plan-ia', array_merge($this->plan_con_gasto(), [
            'tope_gasto_usd_mensual' => 0,
            'precios_ia'             => [],
        ]), $this->headers())->assertStatus(200);

        $dueno = $this->comercio->fresh();

        $this->assertNull($dueno->plan_ia_tope_gasto_usd_mensual);
        $this->assertNull($dueno->plan_ia_precios);
    }

    /**
     * Se guardan solo las cuatro puntas del contrato, y un modelo con un punto en el nombre no se rompe (la
     * regla `precios_ia.*` lo leería como otro nivel). Los números pueden venir como texto, y el tope se
     * redondea a centavos.
     *
     * @test
     */
    public function la_tabla_se_guarda_limpia()
    {
        $this->putJson('api/admin-sync/plan-ia', array_merge($this->plan_con_gasto(), [
            'tope_gasto_usd_mensual' => '6.417',
            'precios_ia'             => [
                'gpt-4.1' => ['input' => '1.5', 'output' => 3, 'cache_write' => 0, 'cache_read' => 0.25, 'otra_punta' => 99],
            ],
        ]), $this->headers())->assertStatus(200);

        $dueno = $this->comercio->fresh();

        $this->assertSame(6.42, (float) $dueno->plan_ia_tope_gasto_usd_mensual);
        $this->assertEquals(
            ['gpt-4.1' => ['input' => 1.5, 'output' => 3.0, 'cache_write' => 0.0, 'cache_read' => 0.25]],
            json_decode($dueno->plan_ia_precios, true)
        );
    }

    /**
     * Un cuerpo inválido es un 422 y el plan queda exactamente como estaba: ni el nombre se toca.
     *
     * @test
     * @dataProvider provider_cuerpos_invalidos
     */
    public function un_cuerpo_invalido_corta_con_422_sin_tocar_nada($invalido)
    {
        $this->putJson('api/admin-sync/plan-ia', $this->plan_con_gasto(), $this->headers())->assertStatus(200);

        $this->putJson('api/admin-sync/plan-ia', array_merge($this->plan_con_gasto(), ['nombre' => 'No se guarda'], $invalido), $this->headers())
             ->assertStatus(422);

        $dueno = $this->comercio->fresh();

        $this->assertSame('Agentes Light', (string) $dueno->plan_ia_nombre);
        $this->assertSame(6.41, (float) $dueno->plan_ia_tope_gasto_usd_mensual);
        $this->assertEquals(self::PRECIOS, json_decode($dueno->plan_ia_precios, true));
    }

    /**
     * @return array
     */
    public function provider_cuerpos_invalidos()
    {
        $completa = ['input' => 1, 'output' => 1, 'cache_write' => 1, 'cache_read' => 1];

        return [
            'tope negativo'         => [['tope_gasto_usd_mensual' => -1]],
            'tope que no es número' => [['tope_gasto_usd_mensual' => 'mucho']],
            'tope que no entra'     => [['tope_gasto_usd_mensual' => 100000000]],
            'tabla que no es tabla' => [['precios_ia' => 'gratis']],
            'lista sin modelo'      => [['precios_ia' => [$completa]]],
            'tarifa que no es fila' => [['precios_ia' => ['modelo' => 3]]],
            'falta una punta'       => [['precios_ia' => ['modelo' => ['input' => 1, 'output' => 1, 'cache_write' => 1]]]],
            'punta negativa'        => [['precios_ia' => ['modelo' => array_merge($completa, ['output' => -1])]]],
            'punta que no es número' => [['precios_ia' => ['modelo' => array_merge($completa, ['output' => 'caro'])]]],
        ];
    }

    /** @test */
    public function sin_la_clave_del_admin_corta_con_401()
    {
        $this->putJson('api/admin-sync/plan-ia', $this->plan_con_gasto(), $this->headers(false))
             ->assertStatus(401);

        $this->assertNull($this->comercio->fresh()->plan_ia_tope_gasto_usd_mensual);
    }

    /* -------------------------------------------------------------------------------------------------
     | 🔴 El dueño no ve dólares ni precios
     * ---------------------------------------------------------------------------------------------- */

    /** @test */
    public function mi_consumo_dice_el_porcentaje_y_no_la_plata()
    {
        $this->dar_extension();
        $this->con_precios_reconocibles();
        $this->comercio->plan_ia_nombre = 'Agentes Light';
        $this->comercio->plan_ia_tope_tokens_mensual = 50000000;
        $this->comercio->save();

        // 42 mil de salida a US$ 987,65 el millón: US$ 41,48 de un tope de US$ 98,76: 42 %.
        $this->consumo('claude-sonnet-5', 0, 42000);

        $r = $this->actingAs($this->comercio, 'sanctum')->getJson('api/mi-consumo-ia');

        $r->assertStatus(200);
        $this->assertSame(42, $r->json('porcentaje_del_plan'));
        $this->assertNull($r->json('plan.tope_tokens_mensual'), 'Para que un SPA viejo saque su barra: ese tope ya no corta.');
        $this->assertSame('Agentes Light', $r->json('plan.nombre'));
        $this->assertSame(42000, $r->json('consumo_mes.tokens'));

        $this->assertSinPlata($r->getContent());
    }

    /**
     * @test
     * @dataProvider provider_porcentajes
     */
    public function el_porcentaje_del_plan_va_de_0_a_100_para_abajo($salida, $porcentaje, $supero)
    {
        $this->dar_extension();
        $this->con_tope_de_gasto(10);

        // US$ 10 por millón de salida: $salida tokens son $salida / 100000 dólares.
        if ($salida > 0) {
            $this->consumo('claude-sonnet-5', 0, $salida);
        }

        $r = $this->actingAs($this->comercio, 'sanctum')->getJson('api/mi-consumo-ia');

        $r->assertStatus(200);
        $this->assertSame($porcentaje, $r->json('porcentaje_del_plan'));
        $this->assertSame($supero, $r->json('supero'));
    }

    /**
     * @return array
     */
    public function provider_porcentajes()
    {
        return [
            'sin consumo'                => [0, 0, false],
            'con ruido en los decimales' => [290000, 29, false],
            'casi, y no dice 100'        => [999000, 99, false],
            'justo'                      => [1000000, 100, true],
            'pasado, no se desborda'     => [3000000, 100, true],
        ];
    }

    /**
     * 🔴 Con los dos topes pasados, un SPA viejo dice «mañana se renueva» si `supero_tokens` queda en false
     * (`supero_interacciones && !supero_tokens`). En esta respuesta esa clave quiere decir «el del mes».
     *
     * @test
     */
    public function mi_consumo_con_el_gasto_pasado_prende_supero_tokens()
    {
        $this->dar_extension();
        $this->con_tope_de_gasto(1);
        $this->comercio->plan_ia_tope_interacciones_diarias = 1;
        $this->comercio->save();

        AiTokenUsage::create($this->fila('claude-opus-5', 0, 40000, 0, 0, 'chat_mensaje', $this->comercio->id) + ['referencia_id' => 1]);

        $this->actingAs($this->comercio, 'sanctum')->getJson('api/mi-consumo-ia')
             ->assertStatus(200)
             ->assertJson(['supero' => true, 'supero_tokens' => true, 'supero_interacciones' => true]);
    }

    /**
     * `cerca` también se prende por las interacciones de hoy: `cerca_del_mes` es lo que le deja decir al SPA
     * de cuál tope se está cerca.
     *
     * @test
     */
    public function mi_consumo_distingue_de_que_tope_esta_cerca()
    {
        $this->dar_extension();
        $this->con_tope_de_gasto(100);
        $this->comercio->plan_ia_tope_interacciones_diarias = 1;
        $this->comercio->save();

        AiTokenUsage::create($this->fila('deepseek-flash', 1, 1, 0, 0, 'chat_mensaje', $this->comercio->id) + ['referencia_id' => 1]);

        $this->actingAs($this->comercio, 'sanctum')->getJson('api/mi-consumo-ia')
             ->assertStatus(200)
             ->assertJson(['cerca' => true, 'cerca_del_mes' => false, 'porcentaje_del_plan' => 0]);
    }

    /**
     * AuthController@get_user devuelve el modelo entero. Un empleado recibe al dueño adentro del suyo, y por
     * eso es un test aparte: UserHelper::user() lee primero session('auth_user').
     *
     * @test
     */
    public function get_user_del_dueno_no_trae_la_plata()
    {
        $this->con_precios_reconocibles();

        Auth::forgetGuards();
        $this->actingAs($this->comercio, 'web');

        $r = $this->getJson('api/user');

        $r->assertStatus(200);
        $this->assertSame((int) $this->comercio->id, (int) $r->json('user.id'));
        $this->assertArrayNotHasKey(self::COLUMNA_TOPE, $r->json('user'));
        $this->assertArrayNotHasKey(self::COLUMNA_PRECIOS, $r->json('user'));
        $this->assertSinPlata($r->getContent());
    }

    /** @test */
    public function get_user_del_empleado_trae_al_dueno_sin_la_plata()
    {
        $this->con_precios_reconocibles();

        Auth::forgetGuards();
        $this->actingAs($this->empleado, 'web');

        $r = $this->getJson('api/user');

        $r->assertStatus(200);
        $this->assertSame((int) $this->empleado->id, (int) $r->json('user.id'));
        $this->assertSame((int) $this->comercio->id, (int) $r->json('user.owner.id'), 'Trae al dueño adentro: es justo lo que se quiere probar.');
        $this->assertSinPlata($r->getContent());
    }

    /** @test */
    public function el_modelo_serializado_no_trae_la_plata_pero_el_helper_la_lee()
    {
        $this->con_precios_reconocibles();

        $dueno = User::find($this->comercio->id);

        $this->assertSame(98.76, (float) $dueno->plan_ia_tope_gasto_usd_mensual, 'El modelo la tiene: de acá la lee el helper.');
        $this->assertArrayNotHasKey(self::COLUMNA_TOPE, $dueno->toArray());
        $this->assertArrayNotHasKey(self::COLUMNA_PRECIOS, $dueno->toArray());
        $this->assertSinPlata($dueno->toJson());
    }

    /**
     * Estar en `$hidden` también las oculta de audit_logs (AuditLogRecorder::es_sensible()): queda que
     * cambiaron, sin el valor.
     *
     * @test
     */
    public function audit_logs_guarda_que_cambio_sin_el_valor()
    {
        $id_inicial = (int) AuditLog::max('id');

        $this->con_precios_reconocibles();

        $fila = AuditLog::where('id', '>', $id_inicial)
            ->where('auditable_type', User::class)
            ->where('event', 'updated')
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($fila, 'Cambiar el plan tiene que dejar una fila, o este test no prueba nada.');

        $nuevos = json_decode($fila->new_values, true);

        $this->assertSame('[oculto]', $nuevos[self::COLUMNA_TOPE]);
        $this->assertSame('[oculto]', $nuevos[self::COLUMNA_PRECIOS]);
        $this->assertSinLosValores($fila->new_values);
    }

    /* -------------------------------------------------------------------------------------------------
     | Columnas ausentes: un deploy sube los archivos antes de migrar
     * ---------------------------------------------------------------------------------------------- */

    /** @test */
    public function sin_las_columnas_el_plan_se_guarda_igual_y_manda_el_tope_de_tokens()
    {
        $this->sin_la_columna('users', self::COLUMNA_PRECIOS, function () {
            $this->sin_la_columna('users', self::COLUMNA_TOPE, function () {

                /* sin_la_columna() cerró la transacción del setUp: el dueño hay que crearlo de nuevo. */
                $dueno = User::create([
                    'name'         => 'Comercio sin migrar',
                    'company_name' => 'Ferretería sin migrar',
                    'email'        => 'wsp-sin-migrar-' . uniqid() . '@test.local',
                    'password'     => Hash::make('secret'),
                ]);

                config(['app.USER_ID' => $dueno->id]);

                $this->putJson('api/admin-sync/plan-ia', array_merge($this->plan_con_gasto(), [
                    'tope_tokens_mensual' => 1000,
                ]), $this->headers())->assertStatus(200);

                $dueno = $dueno->fresh();

                $this->assertSame('Agentes Light', (string) $dueno->plan_ia_nombre);
                $this->assertSame(1000, (int) $dueno->plan_ia_tope_tokens_mensual);

                $this->consumo('claude-opus-5', 0, 2000, 0, 0, 'chat_mensaje', $dueno->id);

                $estado = TopeDeTokensHelper::estado($dueno);

                $this->assertNull($estado['tope_usd']);
                $this->assertTrue($estado['supero_tokens']);
                $this->assertTrue($estado['supero']);
            });
        });
    }

    /* -------------------------------------------------------------------------------------------------
     | Ayudantes
     * ---------------------------------------------------------------------------------------------- */

    /**
     * El cuerpo del PUT tal como lo manda el admin nuevo.
     *
     * @return array
     */
    protected function plan_con_gasto()
    {
        return [
            'nombre'                     => 'Agentes Light',
            'tope_tokens_mensual'        => 50000000,
            'tope_interacciones_diarias' => 100,
            'tope_busquedas_web_diarias' => 30,
            'tope_gasto_usd_mensual'     => 6.41,
            'precios_ia'                 => self::PRECIOS,
        ];
    }

    /**
     * Le pone al dueño un tope de gasto y una tabla de precios, como si los hubiera mandado el admin.
     *
     * @param  float  $tope
     * @param  array  $precios
     * @param  User|null  $dueno
     * @return void
     */
    protected function con_tope_de_gasto($tope, array $precios = self::PRECIOS, $dueno = null)
    {
        $dueno = is_null($dueno) ? $this->comercio : $dueno;

        $dueno->plan_ia_tope_gasto_usd_mensual = $tope;
        $dueno->plan_ia_precios = json_encode($precios);
        $dueno->save();
    }

    /**
     * Un tope y una tarifa con números que no aparecen por casualidad, para buscarlos en una respuesta:
     * US$ 98,76 y US$ 987,65 por millón de salida.
     *
     * @return void
     */
    protected function con_precios_reconocibles()
    {
        $this->con_tope_de_gasto(98.76, [
            'claude-sonnet-5' => ['input' => 123.45, 'output' => 987.65, 'cache_write' => 234.56, 'cache_read' => 345.67],
        ]);
    }

    /**
     * Que el texto no traiga ni los valores de con_precios_reconocibles() ni los nombres que los llevan.
     *
     * @param  string  $texto
     * @return void
     */
    protected function assertSinPlata($texto)
    {
        $this->assertSinLosValores($texto);

        foreach (['consumo_usd', 'tope_usd', 'tope_gasto', 'plan_ia_precios', 'precios_ia', 'cache_write', 'supero_gasto'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, (string) $texto);
        }
    }

    /**
     * Que el texto no traiga los valores de con_precios_reconocibles().
     *
     * @param  string  $texto
     * @return void
     */
    protected function assertSinLosValores($texto)
    {
        foreach (['98.76', '987.65', '123.45', '234.56', '345.67'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, (string) $texto);
        }
    }

    /**
     * Graba una fila de consumo.
     *
     * @param  string  $modelo
     * @param  int  $input
     * @param  int  $output
     * @param  int  $cache_write
     * @param  int  $cache_read
     * @param  string  $proceso
     * @param  int|null  $owner_id
     * @param  \Carbon\Carbon|null  $cuando
     * @return void
     */
    protected function consumo($modelo, $input, $output, $cache_write = 0, $cache_read = 0, $proceso = 'chat_mensaje', $owner_id = null, $cuando = null)
    {
        AiTokenUsage::create($this->fila($modelo, $input, $output, $cache_write, $cache_read, $proceso, $owner_id, $cuando));
    }

    /**
     * Graba una fila de consumo de un proveedor dado (consumo() las graba de `anthropic`).
     *
     * @param  string  $proveedor
     * @param  string  $modelo
     * @param  int  $input
     * @param  int  $output
     * @param  int  $cache_write
     * @param  int  $cache_read
     * @return void
     */
    protected function consumo_de($proveedor, $modelo, $input, $output, $cache_write = 0, $cache_read = 0)
    {
        AiTokenUsage::create(array_merge($this->fila($modelo, $input, $output, $cache_write, $cache_read), [
            'proveedor' => $proveedor,
        ]));
    }

    /**
     * Vacía lo que el helper recuerda en memoria, que es lo que le pasa a cada request bajo PHP-FPM: los
     * `static` no sobreviven entre uno y otro. Lo que sobrevive es el caché.
     *
     * @return void
     */
    protected function como_en_un_request_nuevo()
    {
        $memoria = new \ReflectionProperty(TopeDeTokensHelper::class, 'ya_avisado');
        $memoria->setAccessible(true);
        $memoria->setValue(null, []);
    }

    /**
     * Los campos de una fila de consumo.
     *
     * @return array
     */
    protected function fila($modelo, $input, $output, $cache_write = 0, $cache_read = 0, $proceso = 'chat_mensaje', $owner_id = null, $cuando = null)
    {
        $owner_id = is_null($owner_id) ? $this->comercio->id : $owner_id;
        $cuando = is_null($cuando) ? Carbon::now() : $cuando;

        return [
            'user_id'                     => $owner_id,
            'auth_user_id'                => $owner_id,
            'proceso'                     => $proceso,
            'proveedor'                   => 'anthropic',
            'modelo'                      => $modelo,
            'input_tokens'                => $input,
            'output_tokens'               => $output,
            'cache_creation_input_tokens' => $cache_write,
            'cache_read_input_tokens'     => $cache_read,
            'created_at'                  => $cuando,
            'updated_at'                  => $cuando,
        ];
    }
}
