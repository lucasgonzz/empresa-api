<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\BusquedaPorCodigoDeBarrasIaHelper;
use App\Models\AiMessageImagen;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Misión asistente-fotos-barras-y-compras (24/9/2026) — la herramienta
 * `buscar_producto_por_codigo_de_barras` y su tope diario.
 *
 * El caso de Lucas en demo3 (conv 12): mandó la foto de un producto con su código de barras y el
 * asistente contestó "no puedo leer el código ni buscar en internet". Protege:
 *   - un código con el verificador roto corta antes de salir a la red;
 *   - un código que el negocio ya tiene no se busca;
 *   - lo que está en Open Food Facts se resuelve SIN búsqueda web y no cuenta para el tope;
 *   - la búsqueda web cuenta UNA vez por búsqueda (también con reenvíos por pause_turn), va sin
 *     user_location (todo el mundo) y pide la descripción en español;
 *   - el tope es el del plan del dueño o, sin plan, 30; superado, no se gasta la llamada;
 *   - la foto elegida queda como ai_message_imagenes del mensaje ASSISTANT en curso;
 *   - el receptor del plan del admin acepta la cuarta clave y, si no viene, no toca la columna.
 *
 * 🔴 Nada sale a la red: todo pasa por Http::fake(). La clave de Anthropic es una de mentira solo
 * para que el servicio no corte por "sin clave" antes de llegar al fake.
 */
class Busqueda_por_codigo_de_barras_Test extends AsistenteWhatsappTestCase
{
    /** Cera Nic: no está en Open Food Facts; la foto real de Lucas. */
    const EAN_WEB = '7798111212032';

    /** Aceite Cocinero: sí está en Open Food Facts. */
    const EAN_OFF = '7790070012050';

    /** @var array<int, array> Los payloads que se le mandaron a Anthropic, en orden. */
    protected $a_anthropic = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config(['services.anthropic.api_key' => 'clave-de-mentira-para-el-fake']);
        config(['services.anthropic.base_url' => 'https://api.anthropic.com']);
        config(['services.article_image_validation.enabled' => true]);

        /* Sin key de Google: el último recurso de la foto no corre en estos tests. */
        config(['services.google_search.api_key' => '']);

        $this->comercio->google_custom_search_api_key = null;
        $this->comercio->save();
    }

    // ------------------------------------------------------------------ código

    /** @test */
    public function un_codigo_con_el_verificador_roto_corta_sin_salir_a_la_red()
    {
        Http::fake();

        $r = BusquedaPorCodigoDeBarrasIaHelper::buscar($this->comercio->id, '7798111212033');

        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsString('no es un código de barras válido', $r['error']);
        $this->assertStringContainsString('7798111212033', $r['error'], 'Dice qué leyó, para que la persona lo corrija.');
        Http::assertNothingSent();
    }

    /** @test */
    public function un_codigo_que_el_negocio_ya_tiene_no_se_busca()
    {
        Http::fake();

        $articulo = Article::create([
            'name'     => 'zz-barras Aceite Cocinero',
            'user_id'  => $this->comercio->id,
            'status'   => 'active',
            'bar_code' => self::EAN_OFF,
        ]);

        /* Con los espacios con que viene impreso debajo de las barras. */
        $r = BusquedaPorCodigoDeBarrasIaHelper::buscar($this->comercio->id, '7 790070 012050');

        $this->assertArrayHasKey('ya_existe', $r);
        $this->assertSame((int) $articulo->id, $r['ya_existe']['article_id']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ Open Food Facts

    /** @test */
    public function lo_que_esta_en_open_food_facts_se_resuelve_sin_busqueda_web_y_no_cuenta_para_el_tope()
    {
        $this->fake_de_la_red([
            'off' => [
                'status'  => 1,
                'product' => [
                    'code'            => self::EAN_OFF,
                    'product_name'    => 'Aceite de girasol',
                    'brands'          => 'Cocinero,Molinos',
                    'quantity'        => '1.5 L',
                    'image_front_url' => 'https://images.openfoodfacts.org/aceite.jpg',
                ],
            ],
            'redaccion' => [
                'nombre'      => 'Aceite de Girasol Cocinero 1,5 L',
                'marca'       => 'Cocinero',
                'descripcion' => 'Aceite de girasol refinado, ideal para cocinar y freír.',
            ],
        ]);

        $r = BusquedaPorCodigoDeBarrasIaHelper::buscar($this->comercio->id, self::EAN_OFF);

        $this->assertSame('Aceite de Girasol Cocinero 1,5 L', $r['nombre']);
        $this->assertSame('Cocinero', $r['marca']);
        $this->assertSame('open_food_facts', $r['origen_de_los_datos']);
        $this->assertNull($r['imagen_id'], 'Sin mensaje assistant no hay de dónde colgar la foto.');

        foreach ($this->a_anthropic as $payload) {
            $this->assertArrayNotHasKey('tools', $payload, 'Lo de Open Food Facts no gasta una búsqueda web.');
        }

        $this->assertSame(0, BusquedaPorCodigoDeBarrasIaHelper::busquedas_web_de_hoy($this->comercio->id));
        $this->assertSame(1, AiTokenUsage::where('user_id', $this->comercio->id)
            ->where('proceso', BusquedaPorCodigoDeBarrasIaHelper::PROCESO_BASE_ABIERTA)->count());
        $this->assertSame(30, $r['busquedas_restantes_hoy']);
    }

    // ------------------------------------------------------------------ búsqueda web

    /** @test */
    public function la_busqueda_web_cuenta_para_el_tope_va_a_todo_el_mundo_y_pide_espanol()
    {
        $this->fake_de_la_red([
            'web' => [$this->respuesta_web_final()],
        ]);

        $r = BusquedaPorCodigoDeBarrasIaHelper::buscar($this->comercio->id, self::EAN_WEB);

        $this->assertSame('Cera Modeladora Efecto Mate Nic Modeleitor 90 g', $r['nombre']);
        $this->assertSame('Nic', $r['marca']);
        $this->assertSame('Cera para peinar con efecto mate. Fija sin dejar brillo.', $r['descripcion']);
        $this->assertSame('busqueda_web', $r['origen_de_los_datos']);
        $this->assertSame(['https://tienda.example/cera-nic'], $r['fuentes']);

        $web = $this->payloads_con_busqueda_web();
        $this->assertCount(1, $web);
        $this->assertSame('web_search_20250305', $web[0]['tools'][0]['type']);
        $this->assertArrayNotHasKey('user_location', $web[0]['tools'][0], 'Decisión de Lucas: busca en todo el mundo.');
        $this->assertStringContainsString('SIEMPRE EN ESPAÑOL', $web[0]['system'], 'La descripción se pide siempre en español.');

        $this->assertSame(1, BusquedaPorCodigoDeBarrasIaHelper::busquedas_web_de_hoy($this->comercio->id));
        $this->assertSame(29, $r['busquedas_restantes_hoy']);

        $fila = AiTokenUsage::where('user_id', $this->comercio->id)
            ->where('proceso', BusquedaPorCodigoDeBarrasIaHelper::PROCESO_WEB)->first();
        $this->assertSame(1000, (int) $fila->input_tokens);
    }

    /** @test */
    public function pause_turn_se_reenvia_y_registra_una_sola_busqueda_con_el_consumo_sumado()
    {
        $pausa = [
            'model'       => 'claude-haiku-4-5-20251001',
            'stop_reason' => 'pause_turn',
            'content'     => [
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => self::EAN_WEB]],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => [
                    ['type' => 'web_search_result', 'url' => 'https://otra.example/p', 'title' => 'Cera'],
                ]],
            ],
            'usage' => ['input_tokens' => 400, 'output_tokens' => 20, 'server_tool_use' => ['web_search_requests' => 1]],
        ];

        $this->fake_de_la_red([
            'web' => [$pausa, $this->respuesta_web_final()],
        ]);

        $r = BusquedaPorCodigoDeBarrasIaHelper::buscar($this->comercio->id, self::EAN_WEB);

        $this->assertSame('Cera Modeladora Efecto Mate Nic Modeleitor 90 g', $r['nombre'], 'El corte por pause_turn no es la respuesta final.');

        $web = $this->payloads_con_busqueda_web();
        $this->assertCount(2, $web, 'Se reenvía una vez.');
        $this->assertCount(2, $web[1]['messages'], 'El reenvío lleva el turno del assistant que quedó a medias.');
        $this->assertSame('assistant', $web[1]['messages'][1]['role']);

        $filas = AiTokenUsage::where('user_id', $this->comercio->id)
            ->where('proceso', BusquedaPorCodigoDeBarrasIaHelper::PROCESO_WEB)->get();
        $this->assertCount(1, $filas, 'Una búsqueda es una fila, aunque haya habido reenvío.');
        $this->assertSame(1400, (int) $filas[0]->input_tokens);
    }

    /** @test */
    public function sin_nombre_confirmado_no_se_inventa_el_producto()
    {
        $vacia = $this->respuesta_web_final();
        $vacia['content'][3]['text'] = '{"nombre": null, "marca": null, "descripcion": null, "fuentes": []}';

        $this->fake_de_la_red(['web' => [$vacia]]);

        $r = BusquedaPorCodigoDeBarrasIaHelper::buscar($this->comercio->id, self::EAN_WEB);

        $this->assertNull($r['nombre']);
        $this->assertStringContainsString('No inventes', $r['aviso']);
        $this->assertSame(1, BusquedaPorCodigoDeBarrasIaHelper::busquedas_web_de_hoy($this->comercio->id), 'La búsqueda se hizo y se pagó igual.');
    }

    // ------------------------------------------------------------------ tope

    /** @test */
    public function superado_el_tope_del_plan_no_se_gasta_la_llamada()
    {
        $this->comercio->plan_ia_tope_busquedas_web_diarias = 2;
        $this->comercio->save();

        $this->sembrar_busquedas_web(2);

        $this->fake_de_la_red(['web' => [$this->respuesta_web_final()]]);

        $r = BusquedaPorCodigoDeBarrasIaHelper::buscar($this->comercio->id, self::EAN_WEB);

        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsString('tope de 2 búsquedas por código de barras de hoy', $r['error']);
        $this->assertCount(0, $this->payloads_con_busqueda_web(), 'Pasado el tope no se llama a la búsqueda web.');
    }

    /** @test */
    public function el_tope_es_el_del_plan_y_sin_plan_el_defecto_de_30()
    {
        $this->assertSame(30, BusquedaPorCodigoDeBarrasIaHelper::tope_diario($this->comercio->fresh()), 'Sin plan: el defecto de Lucas.');

        $this->comercio->plan_ia_tope_busquedas_web_diarias = 0;
        $this->comercio->save();
        $this->assertSame(30, BusquedaPorCodigoDeBarrasIaHelper::tope_diario($this->comercio->fresh()), 'Un 0 se lee como "sin tope propio".');

        $this->comercio->plan_ia_tope_busquedas_web_diarias = 5;
        $this->comercio->save();
        $this->assertSame(5, BusquedaPorCodigoDeBarrasIaHelper::tope_diario($this->comercio->fresh()));

        /* Las de ayer no cuentan: el tope es por día. */
        $this->sembrar_busquedas_web(3, Carbon::now()->subDay());
        $this->sembrar_busquedas_web(1);
        $this->assertSame(4, BusquedaPorCodigoDeBarrasIaHelper::busquedas_restantes_hoy($this->comercio->fresh()));
    }

    // ------------------------------------------------------------------ foto

    /** @test */
    public function la_foto_elegida_queda_colgada_del_mensaje_assistant_en_curso()
    {
        $this->fake_de_la_red([
            'web'    => [$this->respuesta_web_final()],
            'pagina' => '<html><head><meta property="og:image" content="https://cdn.example/cera-nic.png"></head><body>Cera</body></html>',
            'imagen' => $this->png(600, 600),
            'vision' => ['es_el_producto' => true, 'tipo' => 'producto', 'confianza' => 'high', 'motivo' => 'Es la cera Nic.'],
        ]);

        $conversacion = $this->conversacion_whatsapp();
        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'cargame este producto']);
        $assistant = $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        /* Por el mismo camino que el loop del chat: execute_tool_calls con el assistant en curso. */
        $resultados = (new AsistenteIaService())->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_barras_1',
            'name'  => 'buscar_producto_por_codigo_de_barras',
            'input' => ['codigo' => self::EAN_WEB],
        ]], $conversacion, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], (string) $resultados[0]['content']);

        $r = json_decode($resultados[0]['content'], true);

        $this->assertNotNull($r['imagen_id'], 'Tiene que haber elegido la og:image de la fuente.');
        $this->assertSame('pagina:tienda.example', $r['imagen_origen']);
        $this->assertSame('https://cdn.example/cera-nic.png', $r['imagen_fuente']);

        $imagen = AiMessageImagen::find($r['imagen_id']);
        $this->assertSame((int) $assistant->id, (int) $imagen->ai_message_id, 'Cuelga del ASSISTANT: nunca se confunde con una foto del dueño.');
        $this->assertSame((int) $this->comercio->id, (int) $imagen->user_id);
        $this->assertSame(1, (int) $imagen->orden);
        $this->assertSame('image/webp', (string) $imagen->mime);
        $this->assertNull($imagen->gestionada_at, 'Queda libre para que el alta la tome.');
        Storage::disk('local')->assertExists($imagen->path);
    }

    /** @test */
    public function una_foto_chica_o_rechazada_por_la_vision_no_se_elige()
    {
        $this->fake_de_la_red([
            'web'    => [$this->respuesta_web_final()],
            'pagina' => '<html><head><meta content="https://cdn.example/chica.png" property="og:image"></head></html>',
            'imagen' => $this->png(120, 120),
        ]);

        $conversacion = $this->conversacion_whatsapp();
        $assistant    = $this->mensaje($conversacion, 'assistant', 'pendiente');

        $r = BusquedaPorCodigoDeBarrasIaHelper::buscar($this->comercio->id, self::EAN_WEB, $conversacion, $assistant);

        $this->assertNull($r['imagen_id'], 'Una foto de 120 px no sirve para la tienda.');
        $this->assertArrayHasKey('aviso_foto', $r);
        $this->assertSame(0, AiMessageImagen::where('ai_message_id', $assistant->id)->count());
    }

    // ------------------------------------------------------------------ contrato con el admin

    /** @test */
    public function el_plan_del_admin_con_la_clave_nueva_guarda_el_tope_de_busquedas()
    {
        $this->putJson('api/admin-sync/plan-ia', [
            'nombre'                     => 'Intermedio',
            'tope_tokens_mensual'        => 1000,
            'tope_interacciones_diarias' => 50,
            'tope_busquedas_web_diarias' => 12,
        ], $this->headers())->assertStatus(200);

        $this->assertSame(12, (int) $this->comercio->fresh()->plan_ia_tope_busquedas_web_diarias);

        $this->putJson('api/admin-sync/plan-ia', [
            'nombre'                     => 'Intermedio',
            'tope_tokens_mensual'        => 1000,
            'tope_interacciones_diarias' => 50,
            'tope_busquedas_web_diarias' => 0,
        ], $this->headers())->assertStatus(200);

        $this->assertNull($this->comercio->fresh()->plan_ia_tope_busquedas_web_diarias, '0 = el defecto de config.');
    }

    /** @test */
    public function un_admin_viejo_sin_la_clave_no_toca_el_tope_de_busquedas()
    {
        $this->comercio->plan_ia_tope_busquedas_web_diarias = 7;
        $this->comercio->save();

        $this->putJson('api/admin-sync/plan-ia', [
            'nombre'                     => 'Básico',
            'tope_tokens_mensual'        => 500,
            'tope_interacciones_diarias' => 10,
        ], $this->headers())->assertStatus(200);

        $dueno = $this->comercio->fresh();
        $this->assertSame(7, (int) $dueno->plan_ia_tope_busquedas_web_diarias, 'Sin la clave, la columna no se toca.');
        $this->assertSame(10, (int) $dueno->plan_ia_tope_interacciones_diarias, 'El resto del plan se guarda como siempre.');
    }

    /** @test */
    public function el_plan_del_admin_rechaza_un_tope_de_busquedas_negativo()
    {
        $this->putJson('api/admin-sync/plan-ia', [
            'nombre'                     => 'Intermedio',
            'tope_busquedas_web_diarias' => -3,
        ], $this->headers())->assertStatus(422);
    }

    // ------------------------------------------------------------------ fakes

    /**
     * Arma el Http::fake de toda la red que toca la búsqueda y guarda los payloads de Anthropic.
     *
     * Claves de $escenario: off (cuerpo de Open Food Facts; sin ella, no está), redaccion (JSON de
     * la redacción), web (respuestas de la búsqueda web, en orden), pagina (HTML de las fuentes),
     * imagen (binario de la og:image), vision (JSON del validador por visión).
     *
     * @param  array  $escenario
     * @return void
     */
    protected function fake_de_la_red(array $escenario)
    {
        $web = isset($escenario['web']) ? $escenario['web'] : [];

        Http::fake(function (Request $request) use ($escenario, &$web) {
            $url = $request->url();

            if (strpos($url, 'world.openfoodfacts.org') !== false) {
                return Http::response(isset($escenario['off']) ? $escenario['off'] : ['status' => 0], 200);
            }

            if (strpos($url, 'world.openbeautyfacts.org') !== false) {
                return Http::response(['status' => 0], 200);
            }

            if (strpos($url, 'api.anthropic.com') !== false) {
                $payload             = $request->data();
                $this->a_anthropic[] = $payload;

                if (isset($payload['tools'])) {
                    $siguiente = array_shift($web);

                    return Http::response($siguiente ?: ['error' => ['message' => 'sin respuesta en el fake']], $siguiente ? 200 : 500);
                }

                if ($this->lleva_imagen($payload)) {
                    $vision = isset($escenario['vision'])
                        ? $escenario['vision']
                        : ['es_el_producto' => false, 'tipo' => 'otro_producto', 'confianza' => 'high', 'motivo' => 'No es.'];

                    return Http::response($this->respuesta_de_texto(json_encode($vision)), 200);
                }

                return Http::response($this->respuesta_de_texto(json_encode(isset($escenario['redaccion']) ? $escenario['redaccion'] : [])), 200);
            }

            if (strpos($url, 'tienda.example') !== false || strpos($url, 'otra.example') !== false) {
                return Http::response(isset($escenario['pagina']) ? $escenario['pagina'] : '', isset($escenario['pagina']) ? 200 : 404);
            }

            if (strpos($url, 'cdn.example') !== false || strpos($url, 'images.openfoodfacts.org') !== false) {
                return Http::response(isset($escenario['imagen']) ? $escenario['imagen'] : '', isset($escenario['imagen']) ? 200 : 404);
            }

            return Http::response('', 404);
        });
    }

    /**
     * @param  array  $payload
     * @return bool
     */
    protected function lleva_imagen(array $payload)
    {
        foreach (isset($payload['messages']) ? $payload['messages'] : [] as $mensaje) {
            if (! isset($mensaje['content']) || ! is_array($mensaje['content'])) {
                continue;
            }

            foreach ($mensaje['content'] as $bloque) {
                if (is_array($bloque) && ($bloque['type'] ?? '') === 'image') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Los payloads a Anthropic que llevaron la herramienta de búsqueda web.
     *
     * @return array<int, array>
     */
    protected function payloads_con_busqueda_web()
    {
        return array_values(array_filter($this->a_anthropic, function ($payload) {
            return isset($payload['tools']);
        }));
    }

    /**
     * Una respuesta final de la búsqueda web, con la forma real de la API: texto previo, la
     * búsqueda, sus resultados y el JSON al final.
     *
     * @return array
     */
    protected function respuesta_web_final()
    {
        return [
            'model'       => 'claude-haiku-4-5-20251001',
            'stop_reason' => 'end_turn',
            'content'     => [
                ['type' => 'text', 'text' => 'Voy a buscar el código.'],
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_2', 'name' => 'web_search', 'input' => ['query' => self::EAN_WEB]],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_2', 'content' => [
                    ['type' => 'web_search_result', 'url' => 'https://tienda.example/cera-nic', 'title' => 'Cera Nic'],
                ]],
                ['type' => 'text', 'text' => '{"nombre": "Cera Modeladora Efecto Mate Nic Modeleitor 90 g", "marca": "Nic", "descripcion": "Cera para peinar con efecto mate. Fija sin dejar brillo.", "fuentes": ["https://tienda.example/cera-nic"]}'],
            ],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 80, 'server_tool_use' => ['web_search_requests' => 1]],
        ];
    }

    /**
     * @param  string  $texto
     * @return array
     */
    protected function respuesta_de_texto($texto)
    {
        return [
            'model'       => 'claude-haiku-4-5-20251001',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 50, 'output_tokens' => 30],
        ];
    }

    /**
     * Un PNG real del tamaño pedido (el filtro de tamaño y el resize lo leen de los bytes).
     *
     * @param  int  $ancho
     * @param  int  $alto
     * @return string
     */
    protected function png($ancho, $alto)
    {
        return (string) (new ImageManager())->canvas($ancho, $alto, '#cc3333')->encode('png');
    }

    /**
     * @param  int  $cuantas
     * @param  \Carbon\Carbon|null  $cuando
     * @return void
     */
    protected function sembrar_busquedas_web($cuantas, $cuando = null)
    {
        $cuando = is_null($cuando) ? Carbon::now() : $cuando;

        for ($i = 0; $i < $cuantas; $i++) {
            AiTokenUsage::create([
                'user_id'                     => $this->comercio->id,
                'proceso'                     => BusquedaPorCodigoDeBarrasIaHelper::PROCESO_WEB,
                'proveedor'                   => 'anthropic',
                'modelo'                      => 'claude-haiku-4-5-20251001',
                'input_tokens'                => 10,
                'output_tokens'               => 10,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
                'created_at'                  => $cuando,
                'updated_at'                  => $cuando,
            ]);
        }
    }
}
