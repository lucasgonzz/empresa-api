<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EscaneoDeFacturaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderStatus;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;
use Tests\Feature\AgentesIa\OtorgaAgentesIa;

/**
 * Misión asistente-diario-recuperacion-20261127, CA3 (A4 del informe de la semana).
 *
 * EL CASO REAL (Pescamayorista, msgs 373-405). Una compra con escaneo quedó SIN renglones (confirmar un
 * escaneo saltea en silencio los renglones sin artículo asociado ni alta), el asistente afirmó
 * «quedaron cargados» y le indicó al dueño pasarla a «Recibido»: una compra Recibida sin artículos no
 * carga stock, y nadie se enteró.
 *
 * Lo que se fija:
 *  - `consultar_escaneo_de_factura` NO dice «quedaron cargados» a ciegas: trae el estado de la compra, cuántos
 *    artículos tiene y lo que el escaneo aplicó de verdad, y su `mensaje` lo cuenta;
 *  - pasar a «Recibido» una compra sin artículos por la pantalla (`PUT api/provider-order/{id}`) se frena con
 *    un error, sin tarjeta; con artículos la tarjeta sigue como siempre;
 *  - el prompt con acciones le pide al modelo leer antes de afirmar.
 *
 * 🔴 Sin red. PHP 7.4.
 *
 * @group chat-ia
 */
class U211_Compra_vacia_no_se_da_por_cargada_Test extends EmpresaTestCase
{
    use OtorgaAgentesIa;

    /** @var User */
    protected $dueno;

    /** @var string|null */
    protected $confianza_original = null;

    /** @var \App\Models\ProviderOrder */
    protected $compra;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba-u211']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->confianza_original = $this->dueno->agente_confianza;

        $this->otorgar_agentes_ia($this->dueno);

        // En "cauteloso" toda propuesta deja tarjeta (en "directo" una PUT se ejecutaría en el acto y esta
        // escena no arma el cuerpo completo del formulario de la compra).
        User::where('id', $this->dueno->id)->update(['agente_confianza' => ConfianzaDelAgenteIaHelper::CAUTELOSO]);

        $this->dueno = User::find($this->dueno->id);
        $this->dueno->load('extencions');

        $this->compra = ProviderOrder::create([
            'provider_id'              => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS)->id,
            'user_id'                  => $this->dueno->id,
            'provider_order_status_id' => $this->estado_de_compra('En proceso')->id,
        ]);

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);

        parent::tearDown();
    }

    /**
     * El estado de compra por NOMBRE (nunca por id): se siembra si la base no lo tiene.
     *
     * @param  string  $nombre
     * @return \App\Models\ProviderOrderStatus
     */
    protected function estado_de_compra($nombre)
    {
        return ProviderOrderStatus::firstOrCreate(['name' => $nombre]);
    }

    /**
     * Un escaneo confirmado de la compra, con lo que el confirmar asentó de verdad en `aplicado`.
     *
     * @param  array  $aplicado
     * @return \App\Models\ProviderOrderScan
     */
    protected function escaneo_confirmado(array $aplicado)
    {
        return ProviderOrderScan::create([
            'uuid'              => Str::uuid()->toString(),
            'user_id'           => $this->dueno->id,
            'auth_user_id'      => $this->dueno->id,
            'provider_order_id' => $this->compra->id,
            'estado'            => 'listo',
            'progreso'          => 100,
            'resultado_gestion' => 'confirmado',
            'gestionado_at'     => now(),
            'aplicado'          => $aplicado,
            'resultado'         => [
                'factura'   => ['total' => 10000],
                'articulos' => [
                    ['fila' => 1, 'nombre' => 'Renglón uno', 'cantidad' => 2, 'costo_unitario' => 100, 'match' => ['estado' => 'sin_match', 'article_id' => null, 'candidatos' => []]],
                    ['fila' => 2, 'nombre' => 'Renglón dos', 'cantidad' => 1, 'costo_unitario' => 50, 'match' => ['estado' => 'sin_match', 'article_id' => null, 'candidatos' => []]],
                ],
            ],
        ]);
    }

    protected function conversacion($pedido)
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Lo que devuelve `consultar_escaneo_de_factura` para la compra de la escena.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @return array
     */
    protected function consultar_el_escaneo($conversation)
    {
        return EscaneoDeFacturaIaHelper::consultar($this->dueno->id, $conversation, (int) $this->compra->num);
    }

    /**
     * Pasar la compra a «Recibido» por la pantalla, como lo propondría el modelo.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant
     * @return array
     */
    protected function proponer_recibido($conversation, $assistant)
    {
        $resultado = HerramientasDeCarga::ejecutar('proponer_accion_de_pantalla', [
            'metodo'      => 'PUT',
            'ruta'        => 'api/provider-order/{provider_order}',
            'parametros'  => ['provider_order' => (int) $this->compra->id],
            'cuerpo'      => ['provider_order_status_id' => (int) $this->estado_de_compra('Recibido')->id],
            'descripcion' => 'Pasar la compra a Recibido',
        ], $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /**
     * 🔴 CA3.a: un escaneo «confirmado» que no cargó nada NO se cuenta como cargado.
     *
     * @test
     */
    public function escaneo_confirmado_sin_renglones_no_dice_que_quedaron_cargados()
    {
        $this->escaneo_confirmado(['articulos_agregados' => 0, 'articulos_creados' => 0, 'articulos_omitidos' => 2]);

        list($conversation) = $this->conversacion('¿Qué pasó con la factura?');

        $datos = $this->consultar_el_escaneo($conversation);

        $this->assertTrue($datos['ok'], json_encode($datos));
        $this->assertSame('revisado', $datos['estado']);
        $this->assertSame(0, $datos['articulos_en_la_compra']);
        $this->assertSame('En proceso', $datos['compra_estado']);
        $this->assertSame(0, (int) $datos['aplicado']['articulos_agregados']);
        $this->assertSame(2, (int) $datos['aplicado']['articulos_omitidos']);

        $this->assertStringNotContainsString('quedaron cargados', $datos['mensaje']);
        $this->assertStringContainsString('ningún renglón', $datos['mensaje']);
        $this->assertStringContainsString('Recibido', $datos['mensaje']);
        $this->assertStringContainsString('no carga stock', $datos['mensaje']);
    }

    /**
     * CA3.b: con renglones cargados, el mensaje dice cuántos.
     *
     * @test
     */
    public function escaneo_confirmado_con_renglones_dice_cuantos()
    {
        foreach (['Artículo cargado uno', 'Artículo cargado dos'] as $nombre) {
            $articulo = Article::create(['name' => $nombre, 'user_id' => $this->dueno->id, 'status' => 'active']);
            $this->compra->articles()->attach($articulo->id, ['amount' => 1, 'cost' => 100]);
        }

        $this->escaneo_confirmado(['articulos_agregados' => 2, 'articulos_creados' => 0, 'articulos_omitidos' => 0]);

        list($conversation) = $this->conversacion('¿Qué pasó con la factura?');

        $datos = $this->consultar_el_escaneo($conversation);

        $this->assertTrue($datos['ok'], json_encode($datos));
        $this->assertSame(2, $datos['articulos_en_la_compra']);
        $this->assertSame(2, (int) $datos['aplicado']['articulos_agregados']);
        $this->assertStringContainsString('se cargaron 2 renglones', $datos['mensaje']);
        $this->assertStringNotContainsString('ningún renglón', $datos['mensaje']);
    }

    /**
     * 🔴 CA3.c: pasar a «Recibido» una compra sin artículos se frena: error, sin tarjeta y la compra sigue
     * «En proceso».
     *
     * @test
     */
    public function no_propone_pasar_a_recibido_una_compra_vacia()
    {
        list($conversation, $assistant) = $this->conversacion('Pasala a recibido');

        $respuesta = $this->proponer_recibido($conversation, $assistant);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertStringContainsString('no tiene artículos', (string) $respuesta['error'], json_encode($respuesta));
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
        $this->assertSame('En proceso', $this->compra->fresh()->provider_order_status->name);
    }

    /**
     * CA3.c (contraprueba): con artículos la tarjeta sigue como hoy.
     *
     * @test
     */
    public function con_articulos_la_tarjeta_de_recibido_sigue_igual()
    {
        $articulo = Article::create(['name' => 'Artículo de la compra', 'user_id' => $this->dueno->id, 'status' => 'active']);
        $this->compra->articles()->attach($articulo->id, ['amount' => 1, 'cost' => 100]);

        list($conversation, $assistant) = $this->conversacion('Pasala a recibido');

        $respuesta = $this->proponer_recibido($conversation, $assistant);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * CA3.d: el system prompt con acciones trae la regla de leer antes de afirmar y de no indicar «Recibido»
     * con 0 artículos.
     *
     * @test
     */
    public function el_prompt_pide_leer_antes_de_afirmar()
    {
        $servicio = new AsistenteIaService();

        list($conversation) = $this->conversacion('Hola');

        User::where('id', $this->dueno->id)->update(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);

        $prompt = $servicio->build_system_prompt($conversation, $this->dueno->fresh(), true, false);

        $this->assertStringContainsString('con 0 artículos no indiques «Recibido»', $prompt);
        $this->assertStringContainsString('Nunca afirmes un resultado sin el dato de una herramienta que lo muestre', $prompt);
    }

    /**
     * CA4 (lado asistente): un renglón sin match muestra sus `candidatos` (nombre y código) para que el
     * modelo diga «hay uno con código A9548 6» en vez de «ninguno coincide».
     *
     * @test
     */
    public function consultar_muestra_los_candidatos_de_un_renglon_sin_match()
    {
        ProviderOrderScan::create([
            'uuid'              => Str::uuid()->toString(),
            'user_id'           => $this->dueno->id,
            'auth_user_id'      => $this->dueno->id,
            'provider_order_id' => $this->compra->id,
            'estado'            => 'listo',
            'progreso'          => 100,
            'resultado'         => [
                'factura'   => ['total' => 10000],
                'articulos' => [
                    [
                        'fila'             => 1,
                        'nombre'           => 'Reel sin nombre parecido',
                        'codigo_proveedor' => 'A9548',
                        'cantidad'         => 2,
                        'costo_unitario'   => 100,
                        'match'            => [
                            'estado'     => 'sin_match',
                            'article_id' => null,
                            'candidatos' => [
                                ['article_id' => 1, 'nombre' => 'Reel Polaris 6000', 'provider_code' => 'A9548 6', 'motivo' => 'código que empieza igual'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        list($conversation) = $this->conversacion('¿Qué trae la factura?');

        $datos = $this->consultar_el_escaneo($conversation);

        $this->assertTrue($datos['ok'], json_encode($datos));
        $this->assertArrayHasKey('candidatos', $datos['articulos'][0], json_encode($datos['articulos'][0]));
        $this->assertSame('Reel Polaris 6000', $datos['articulos'][0]['candidatos'][0]['nombre']);
        $this->assertSame('A9548 6', $datos['articulos'][0]['candidatos'][0]['provider_code']);
    }
}
