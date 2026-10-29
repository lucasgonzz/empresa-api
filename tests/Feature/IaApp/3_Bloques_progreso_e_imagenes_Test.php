<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\Helpers\asistente_ia\BloquesIaHelper;
use App\Models\AiMessage;
use App\Models\IaImagenPedido;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Misión app-ia-y-memoria — gráficos, tablas, imágenes generadas y la línea de progreso (formato
 * `bloques` del §3 y §4 E1.5–E1.7 del plan; contratos C1.4 y C2.1).
 *
 * Lo que protege este archivo:
 *   1. El validador de bloques: lo que cumple sale normalizado, lo que no (números con formato, torta
 *      con dos series, datos que no calzan con las categorías, más de los topes) se rechaza con un motivo
 *      que el modelo puede corregir, y la lista guardada nunca pasa de 6.
 *   2. mostrar_grafico, mostrar_tabla y pedir_imagen existen SOLO en el canal app (declaradas y
 *      despachadas); en el sistema son "Tool desconocida". El prompt de la app permite markdown simple y
 *      el del sistema sigue en texto plano.
 *   3. El progreso se escribe antes de cada herramienta y vuelve a null al terminar.
 *   4. El pedido de imagen viaja al admin (C2.1) con su forma; un 404 lo deja `no_disponible`; el tope
 *      diario corta sin crear pedido; el resultado del admin (C1.4) exige la clave, es idempotente, tiene
 *      tenencia por dueño y deja el bloque `lista` con su url; la imagen se sirve solo a su dueño.
 */
class Bloques_progreso_e_imagenes_Test extends IaAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba',
            'services.deepseek.model'          => 'deepseek-general-test',
            'services.deepseek.model_agil'     => 'deepseek-flash-test',
            'services.deepseek.model_profundo' => 'deepseek-pro-test',
            'services.deepseek.model_vision'   => 'deepseek-vision-test',
        ]);
    }

    /**
     * Un gráfico válido de barras.
     *
     * @return array
     */
    protected function grafico_valido()
    {
        return [
            'tipo'       => 'barras',
            'titulo'     => 'Ventas de la semana',
            'categorias' => ['Lun', 'Mar', 'Mié'],
            'series'     => [['nombre' => 'Ventas', 'datos' => [1200.5, 980, null]]],
            'eje_y'      => ['titulo' => 'Pesos', 'formato' => 'moneda'],
        ];
    }

    /**
     * Una respuesta tool_use del modelo.
     *
     * @param  string  $herramienta
     * @param  array  $input
     * @return array
     */
    protected function tool_use($herramienta, array $input)
    {
        return [
            'stop_reason' => 'tool_use',
            'content'     => [
                ['type' => 'text', 'text' => 'Ya te lo armo.'],
                ['type' => 'tool_use', 'id' => 'toolu_' . Str::random(8), 'name' => $herramienta, 'input' => $input],
            ],
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    /**
     * Una respuesta final del modelo.
     *
     * @param  string  $texto
     * @return array
     */
    protected function end_turn($texto = 'Ahí tenés: el martes vendiste menos.')
    {
        return [
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    /**
     * Los cuerpos de los pedidos que se mandaron a un host.
     *
     * @param  string  $host
     * @return array<int, array>
     */
    protected function cuerpos_a($host)
    {
        $cuerpos = [];

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), $host) !== false) {
                $cuerpos[] = json_decode($par[0]->body(), true) ?: [];
            }
        }

        return $cuerpos;
    }

    /**
     * Los nombres de las tools de un pedido al modelo.
     *
     * @param  array  $cuerpo
     * @return array<int, string>
     */
    protected function herramientas_de(array $cuerpo)
    {
        return array_column($cuerpo['tools'] ?? [], 'name');
    }

    // ---------------------------------------------------------------- el validador

    public function test_un_grafico_valido_sale_normalizado()
    {
        $resultado = BloquesIaHelper::grafico($this->grafico_valido());

        $this->assertNull($resultado['error']);
        $this->assertSame('grafico', $resultado['bloque']['tipo']);
        $this->assertSame([1200.5, 980, null], $resultado['bloque']['grafico']['series'][0]['datos']);
        $this->assertSame('moneda', $resultado['bloque']['grafico']['eje_y']['formato']);
        $this->assertSame('ARS', $resultado['bloque']['grafico']['moneda'], 'Con formato moneda y sin moneda, pesos.');
        $this->assertNull($resultado['bloque']['grafico']['subtitulo']);
        $this->assertSame(['titulo' => null], $resultado['bloque']['grafico']['eje_x']);
    }

    public function test_el_validador_rechaza_lo_que_no_cumple_con_un_motivo()
    {
        $con_formato = $this->grafico_valido();
        $con_formato['series'][0]['datos'] = ['1.200,50', 980, 10];

        $torta_con_dos = $this->grafico_valido();
        $torta_con_dos['tipo'] = 'torta';
        $torta_con_dos['series'][] = ['nombre' => 'Otra', 'datos' => [1, 2, 3]];

        $no_calzan = $this->grafico_valido();
        $no_calzan['series'][0]['datos'] = [1, 2];

        $muchas = $this->grafico_valido();
        $muchas['series'] = array_fill(0, 9, ['nombre' => 'S', 'datos' => [1, 2, 3]]);

        $demasiados = $this->grafico_valido();
        $demasiados['categorias'] = array_fill(0, 501, 'x');
        $demasiados['series'][0]['datos'] = array_fill(0, 501, 1);

        $sin_tipo = $this->grafico_valido();
        $sin_tipo['tipo'] = 'radar';

        foreach ([$con_formato, $torta_con_dos, $no_calzan, $muchas, $demasiados, $sin_tipo] as $i => $grafico) {
            $resultado = BloquesIaHelper::grafico($grafico);

            $this->assertNull($resultado['bloque'], 'El caso ' . $i . ' no se puede mostrar.');
            $this->assertNotEmpty($resultado['error'], 'El caso ' . $i . ' tiene que decir por qué.');
        }
    }

    public function test_la_dispersion_usa_puntos_y_los_textos_largos_se_recortan()
    {
        $resultado = BloquesIaHelper::grafico([
            'tipo'   => 'dispersion',
            'titulo' => str_repeat('T', 150),
            'series' => [['nombre' => 'Artículos', 'puntos' => [[1, 2], ['3.5', 4]]]],
        ]);

        $this->assertNull($resultado['error']);
        $this->assertSame([[1, 2], [3.5, 4]], $resultado['bloque']['grafico']['series'][0]['puntos']);
        $this->assertSame([], $resultado['bloque']['grafico']['categorias']);
        $this->assertSame(120, mb_strlen($resultado['bloque']['grafico']['titulo']));
    }

    public function test_una_tabla_valida_y_las_que_no()
    {
        $valida = BloquesIaHelper::tabla([
            'titulo'   => 'Quién me debe',
            'columnas' => [
                ['clave' => 'cliente', 'etiqueta' => 'Cliente'],
                ['clave' => 'deuda', 'etiqueta' => 'Deuda', 'formato' => 'moneda'],
            ],
            'filas'    => [['cliente' => 'Juan', 'deuda' => 12000, 'sobra' => 'x'], ['cliente' => 'Ana', 'deuda' => '850.5']],
        ]);

        $this->assertNull($valida['error']);
        $this->assertSame([['cliente' => 'Juan', 'deuda' => 12000], ['cliente' => 'Ana', 'deuda' => 850.5]], $valida['bloque']['tabla']['filas']);
        $this->assertSame('texto', $valida['bloque']['tabla']['columnas'][0]['formato']);

        $con_formato = BloquesIaHelper::tabla([
            'titulo'   => 'X',
            'columnas' => [['clave' => 'deuda', 'etiqueta' => 'Deuda', 'formato' => 'moneda']],
            'filas'    => [['deuda' => '$ 12.000']],
        ]);
        $this->assertNull($con_formato['bloque']);

        $muchas_columnas = BloquesIaHelper::tabla([
            'titulo'   => 'X',
            'columnas' => array_map(function ($i) {
                return ['clave' => 'c' . $i, 'etiqueta' => 'C' . $i];
            }, range(1, 13)),
            'filas'    => [],
        ]);
        $this->assertNull($muchas_columnas['bloque']);

        $muchas_filas = BloquesIaHelper::tabla([
            'titulo'   => 'X',
            'columnas' => [['clave' => 'a', 'etiqueta' => 'A']],
            'filas'    => array_fill(0, 301, ['a' => 1]),
        ]);
        $this->assertNull($muchas_filas['bloque']);
    }

    public function test_la_lista_guardada_descarta_lo_invalido_y_no_pasa_de_seis()
    {
        $bueno = BloquesIaHelper::grafico($this->grafico_valido())['bloque'];

        $lista = array_merge([['tipo' => 'grafico', 'grafico' => ['tipo' => 'radar']], ['tipo' => 'video']], array_fill(0, 8, $bueno));

        $normalizada = BloquesIaHelper::normalizar($lista);

        $this->assertCount(6, $normalizada);
        $this->assertSame($bueno, $normalizada[0]);
    }

    // ---------------------------------------------------------------- el loop, por la app

    public function test_mostrar_grafico_por_la_app_deja_el_bloque_y_el_progreso_vuelve_a_null()
    {
        $progreso_en_la_segunda_vuelta = null;
        $vuelta = 0;
        $primera = $this->tool_use('mostrar_grafico', $this->grafico_valido());
        $segunda = $this->end_turn();

        Http::fake([
            'api.deepseek.com/*' => function () use (&$vuelta, &$progreso_en_la_segunda_vuelta, $primera, $segunda) {
                $vuelta++;

                if ($vuelta === 2) {
                    $progreso_en_la_segunda_vuelta = AiMessage::where('rol', 'assistant')->orderBy('id', 'DESC')->value('progreso');
                }

                return Http::response($vuelta === 1 ? $primera : $segunda, 200);
            },
        ]);

        $conversation = $this->conversacion(['titulo' => 'Ventas']);

        $respuesta = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'Mostrame las ventas de la semana en un gráfico',
        ], $this->de_la_app());

        $respuesta->assertStatus(201);

        $assistant = AiMessage::find($respuesta->json('assistant_message.id'));

        $this->assertSame('listo', $assistant->estado);
        $this->assertSame('Armando el gráfico…', $progreso_en_la_segunda_vuelta, 'Antes de la herramienta se escribe su etiqueta.');
        $this->assertNull($assistant->progreso, 'Al terminar, el progreso vuelve a null.');
        $this->assertCount(1, $assistant->bloques);
        $this->assertSame('grafico', $assistant->bloques[0]['tipo']);
        $this->assertSame('Ventas de la semana', $assistant->bloques[0]['grafico']['titulo']);

        $cuerpos = $this->cuerpos_a('api.deepseek.com');

        $this->assertContains('mostrar_grafico', $this->herramientas_de($cuerpos[0]));
        $this->assertContains('mostrar_tabla', $this->herramientas_de($cuerpos[0]));
        $this->assertContains('pedir_imagen', $this->herramientas_de($cuerpos[0]));
        $this->assertStringContainsString('markdown simple', $cuerpos[0]['system'][0]['text']);
        $this->assertStringNotContainsString('TEXTO PLANO', $cuerpos[0]['system'][0]['text']);

        $this->como_otro_cliente();

        $this->getJson('api/ai-conversations/' . $conversation->id . '/messages/' . $assistant->id, $this->de_la_app())
            ->assertStatus(200)
            ->assertJsonPath('model.bloques.0.tipo', 'grafico')
            ->assertJsonPath('model.progreso', null);
    }

    public function test_un_grafico_mal_armado_vuelve_al_modelo_como_error_y_no_deja_bloque()
    {
        $malo = $this->grafico_valido();
        $malo['series'][0]['datos'] = ['$ 1.200', 2, 3];

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->tool_use('mostrar_grafico', $malo), 200)
                ->push($this->end_turn('No pude armar el gráfico.'), 200),
        ]);

        $conversation = $this->conversacion(['titulo' => 'Ventas']);

        $respuesta = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'Un gráfico de ventas',
        ], $this->de_la_app())->assertStatus(201);

        $assistant = AiMessage::find($respuesta->json('assistant_message.id'));

        $this->assertNull($assistant->bloques);

        $segunda = $this->cuerpos_a('api.deepseek.com')[1];
        $resultado = end($segunda['messages'])['content'][0];

        $this->assertSame('tool_result', $resultado['type']);
        $this->assertTrue($resultado['is_error']);
        $this->assertStringContainsString('números sin formato', $resultado['content']);
    }

    public function test_en_el_sistema_las_herramientas_de_la_app_no_existen()
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->tool_use('mostrar_grafico', $this->grafico_valido()), 200)
                ->push($this->end_turn('Listo.'), 200),
        ]);

        $this->actingAs($this->dueno, 'web');

        $conversation = $this->conversacion(['titulo' => 'Ventas']);

        $respuesta = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'Un gráfico de ventas',
            'acciones'  => true,
        ])->assertStatus(201);

        $cuerpos = $this->cuerpos_a('api.deepseek.com');

        $this->assertNotContains('mostrar_grafico', $this->herramientas_de($cuerpos[0]));
        $this->assertStringContainsString('TEXTO PLANO', $cuerpos[0]['system'][0]['text']);

        $resultado = end($cuerpos[1]['messages'])['content'][0];
        $this->assertTrue($resultado['is_error']);
        $this->assertStringContainsString('Tool desconocida', $resultado['content']);

        $this->assertNull(AiMessage::find($respuesta->json('assistant_message.id'))->bloques);
    }

    // ---------------------------------------------------------------- las imágenes

    public function test_pedir_imagen_crea_el_pedido_el_bloque_y_lo_manda_al_admin()
    {
        Http::fake([
            'api.deepseek.com/*'   => Http::sequence()
                ->push($this->tool_use('pedir_imagen', [
                    'prompt' => 'Placa cuadrada con el texto "20% OFF en herramientas"',
                    'tamano' => 'cuadrada',
                    'estilo' => 'minimalista',
                ]), 200)
                ->push($this->end_turn('Te la estoy haciendo.'), 200),
            'admin.ia-app.test/*'  => Http::response(['ok' => true, 'estado' => 'pendiente'], 201),
        ]);

        $conversation = $this->conversacion(['titulo' => 'Promo']);

        $respuesta = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'Haceme una placa para Instagram',
        ], $this->de_la_app())->assertStatus(201);

        $assistant = AiMessage::find($respuesta->json('assistant_message.id'));
        $pedido = IaImagenPedido::where('ai_message_id', $assistant->id)->first();

        $this->assertNotNull($pedido);
        $this->assertSame($this->dueno->id, (int) $pedido->user_id);
        $this->assertSame('pendiente', $pedido->estado);
        $this->assertNotNull($pedido->enviado_al_admin_en, 'El 201 del admin deja la marca de enviado.');

        // assertEquals y no assertSame: la columna es JSON de MySQL, que guarda las claves en SU orden
        // (por largo). El orden de las claves de un objeto JSON no es parte del contrato; los valores sí.
        $this->assertEquals([[
            'tipo'   => 'imagen_generada',
            'uuid'   => $pedido->uuid,
            'estado' => 'pendiente',
            'prompt' => 'Placa cuadrada con el texto "20% OFF en herramientas"',
            'tamano' => 'cuadrada',
            'url'    => null,
            'error'  => null,
        ]], $assistant->bloques);

        Http::assertSent(function ($request) use ($pedido, $assistant) {
            if ($request->url() !== 'https://admin.ia-app.test/api/inbound/ia-imagenes') {
                return false;
            }

            return $request->hasHeader('X-Admin-Api-Key', 'clave-inbound-de-prueba')
                && $request['client_uuid'] === 'uuid-del-cliente-de-prueba'
                && $request['uuid'] === $pedido->uuid
                && $request['tamano'] === 'cuadrada'
                && $request['estilo'] === 'minimalista'
                && (int) $request['ai_message_id'] === (int) $assistant->id
                && strpos($request['prompt'], '20% OFF') !== false;
        });
    }

    public function test_si_el_admin_no_tiene_la_cola_el_bloque_queda_no_disponible()
    {
        Http::fake([
            'api.deepseek.com/*'  => Http::sequence()
                ->push($this->tool_use('pedir_imagen', ['prompt' => 'Un cartel', 'tamano' => 'vertical']), 200)
                ->push($this->end_turn('Te la estoy haciendo.'), 200),
            'admin.ia-app.test/*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $conversation = $this->conversacion(['titulo' => 'Cartel']);

        $respuesta = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'Haceme un cartel',
        ], $this->de_la_app())->assertStatus(201);

        $assistant = AiMessage::find($respuesta->json('assistant_message.id'));

        $this->assertSame('no_disponible', IaImagenPedido::where('ai_message_id', $assistant->id)->value('estado'));
        $this->assertSame('no_disponible', $assistant->bloques[0]['estado']);
        $this->assertNotNull($assistant->bloques[0]['error']);

        Http::assertSentCount(3);
    }

    public function test_los_rechazos_definitivos_del_admin_dejan_el_bloque_fallido_sin_reintentar()
    {
        $casos = [
            [429, 'tope_diario', 'Llegaste al tope de imágenes de hoy; probá mañana.'],
            [409, 'uuid_en_uso', null],
            [403, 'cliente_no_coincide', null],
        ];

        foreach ($casos as $caso) {

            Http::swap(new \Tests\Fakes\HttpFactorySinSalida(app('events')));

            Http::fake([
                'api.deepseek.com/*'  => Http::sequence()
                    ->push($this->tool_use('pedir_imagen', ['prompt' => 'Un cartel ' . $caso[1], 'tamano' => 'cuadrada']), 200)
                    ->push($this->end_turn('Te la estoy haciendo.'), 200),
                'admin.ia-app.test/*' => Http::response(['code' => $caso[1], 'message' => 'no'], $caso[0]),
            ]);

            $conversation = $this->conversacion(['titulo' => 'Rechazo ' . $caso[1]]);

            $respuesta = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
                'contenido' => 'Haceme un cartel',
            ], $this->de_la_app())->assertStatus(201);

            $assistant = AiMessage::find($respuesta->json('assistant_message.id'));
            $pedido = IaImagenPedido::where('ai_message_id', $assistant->id)->first();

            $this->assertSame('fallida', $pedido->estado, 'HTTP ' . $caso[0] . ' es definitivo.');
            $this->assertStringContainsString($caso[1], (string) $pedido->error);
            $this->assertSame('fallida', $assistant->bloques[0]['estado']);
            $this->assertSame(
                is_null($caso[2]) ? \App\Http\Controllers\Helpers\asistente_ia\PedidosDeImagenIaHelper::ERROR_PARA_EL_DUENO : $caso[2],
                $assistant->bloques[0]['error']
            );

            $al_admin = 0;
            foreach (Http::recorded() as $par) {
                if (strpos($par[0]->url(), 'admin.ia-app.test') !== false) {
                    $al_admin++;
                }
            }
            $this->assertSame(1, $al_admin, 'Un rechazo definitivo no se reintenta.');

            $this->como_otro_cliente();
        }
    }

    public function test_la_url_del_admin_con_api_al_final_no_se_duplica()
    {
        foreach (['https://admin.ia-app.test/api', 'https://admin.ia-app.test/api/', 'https://admin.ia-app.test'] as $base) {

            config(['services.admin_api.url' => $base]);

            Http::swap(new \Tests\Fakes\HttpFactorySinSalida(app('events')));
            Http::fake(['admin.ia-app.test/*' => Http::response(['ok' => true, 'estado' => 'pendiente'], 201)]);

            list($pedido) = $this->pedido_pendiente();
            $pedido->enviado_al_admin_en = null;
            $pedido->save();

            (new \App\Jobs\EnviarPedidoDeImagenAlAdminJob($pedido->id))->handle();

            $urls = [];
            foreach (Http::recorded() as $par) {
                $urls[] = $par[0]->url();
            }

            $this->assertSame(['https://admin.ia-app.test/api/inbound/ia-imagenes'], $urls, 'Con la base "' . $base . '".');
            $this->assertNotNull($pedido->fresh()->enviado_al_admin_en);
        }
    }

    public function test_pasado_el_tope_diario_no_se_crea_el_pedido()
    {
        config(['services.ia_imagenes.tope_diario' => 2]);

        foreach ([1, 2] as $i) {
            IaImagenPedido::create([
                'uuid' => (string) Str::uuid(), 'user_id' => $this->dueno->id, 'prompt' => 'p' . $i,
                'tamano' => 'cuadrada', 'estado' => 'lista',
            ]);
        }

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->tool_use('pedir_imagen', ['prompt' => 'Otra más', 'tamano' => 'cuadrada']), 200)
                ->push($this->end_turn('Hoy ya no puedo hacer más imágenes.'), 200),
        ]);

        $conversation = $this->conversacion(['titulo' => 'Tope']);

        $respuesta = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'Otra imagen',
        ], $this->de_la_app())->assertStatus(201);

        $this->assertSame(2, IaImagenPedido::where('user_id', $this->dueno->id)->count());
        $this->assertNull(AiMessage::find($respuesta->json('assistant_message.id'))->bloques);

        $resultado = end($this->cuerpos_a('api.deepseek.com')[1]['messages'])['content'][0];
        $this->assertStringContainsString('tope de 2 imágenes por día', $resultado['content']);
    }

    /**
     * Un pedido pendiente con su mensaje y su bloque, como lo deja pedir_imagen.
     *
     * @param  \App\Models\User|null  $dueno
     * @return array{0: \App\Models\IaImagenPedido, 1: \App\Models\AiMessage}
     */
    protected function pedido_pendiente($dueno = null)
    {
        $dueno = is_null($dueno) ? $this->dueno : $dueno;

        $conversation = \App\Models\AiConversation::create(['user_id' => $dueno->id, 'auth_user_id' => $dueno->id, 'titulo' => 'Imagen']);

        $mensaje = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'listo',
            'contenido'          => 'Te la estoy haciendo.',
            'canal'              => 'app',
        ]);

        $pedido = IaImagenPedido::create([
            'uuid'                => (string) Str::uuid(),
            'user_id'             => $dueno->id,
            'ai_message_id'       => $mensaje->id,
            'prompt'              => 'Una placa',
            'tamano'              => 'cuadrada',
            'estado'              => 'pendiente',
            'enviado_al_admin_en' => now(),
        ]);

        $mensaje->bloques = [['tipo' => 'imagen_generada', 'uuid' => $pedido->uuid, 'estado' => 'pendiente', 'prompt' => 'Una placa', 'tamano' => 'cuadrada']];
        $mensaje->save();

        return [$pedido, $mensaje];
    }

    /**
     * Un PNG de verdad.
     *
     * @return \Illuminate\Http\UploadedFile
     */
    protected function png()
    {
        return UploadedFile::fake()->image('generada.png', 64, 64);
    }

    public function test_el_resultado_del_admin_deja_la_imagen_lista_y_es_idempotente()
    {
        list($pedido, $mensaje) = $this->pedido_pendiente();

        $url = 'api/admin-sync/ia-imagenes/' . $pedido->uuid . '/resultado';

        $this->post($url, ['estado' => 'lista', 'imagen' => $this->png()], $this->del_admin())
            ->assertStatus(200)
            ->assertExactJson(['ok' => true, 'estado' => 'lista']);

        $pedido->refresh();
        $mensaje->refresh();

        $this->assertSame('lista', $pedido->estado);
        $this->assertSame('ia_imagenes/' . $this->dueno->id . '/' . $pedido->uuid . '.png', $pedido->path);
        Storage::disk('local')->assertExists($pedido->path);

        $this->assertSame('lista', $mensaje->bloques[0]['estado']);
        $this->assertSame('ia-imagenes/' . $pedido->uuid, $mensaje->bloques[0]['url']);

        $actualizado = $pedido->updated_at->toDateTimeString();

        // El reintento del admin no cambia nada; un "fallida" tardío tampoco pisa la imagen.
        $this->post($url, ['estado' => 'lista', 'imagen' => $this->png()], $this->del_admin())->assertStatus(200)->assertJsonPath('estado', 'lista');
        $this->post($url, ['estado' => 'fallida', 'error' => 'tarde'], $this->del_admin())->assertStatus(200)->assertJsonPath('estado', 'lista');

        $this->assertSame($actualizado, $pedido->fresh()->updated_at->toDateTimeString());
        $this->assertSame('lista', $mensaje->fresh()->bloques[0]['estado']);

        // La imagen se le sirve al dueño por su app.
        $this->como_otro_cliente();

        $imagen = $this->get('api/ia-imagenes/' . $pedido->uuid, $this->de_la_app());
        $imagen->assertStatus(200);
        $this->assertStringContainsString('private', (string) $imagen->headers->get('Cache-Control'));
    }

    public function test_el_resultado_exige_la_clave_y_respeta_la_tenencia()
    {
        list($pedido) = $this->pedido_pendiente();

        $url = 'api/admin-sync/ia-imagenes/' . $pedido->uuid . '/resultado';

        $this->post($url, ['estado' => 'lista', 'imagen' => $this->png()], $this->del_admin('otra'))->assertStatus(401);
        $this->post($url, ['estado' => 'lista', 'imagen' => $this->png()], ['Accept' => 'application/json'])->assertStatus(401);

        $this->post('api/admin-sync/ia-imagenes/' . Str::uuid() . '/resultado', ['estado' => 'lista', 'imagen' => $this->png()], $this->del_admin())
            ->assertStatus(404)
            ->assertJsonPath('code', 'pedido_inexistente');

        // El pedido de OTRO negocio de la misma base no lo toca esta instancia.
        $otro = User::create([
            'name' => 'Otro', 'company_name' => 'Otro negocio', 'email' => 'ia-app-otro-' . uniqid() . '@test.local', 'password' => Hash::make('x'),
        ]);
        list($ajeno) = $this->pedido_pendiente($otro);

        $this->post('api/admin-sync/ia-imagenes/' . $ajeno->uuid . '/resultado', ['estado' => 'lista', 'imagen' => $this->png()], $this->del_admin())
            ->assertStatus(404);

        // Lista sin imagen, o con algo que no es una imagen: 422.
        $this->post($url, ['estado' => 'lista'], $this->del_admin())->assertStatus(422);
        $this->post($url, ['estado' => 'lista', 'imagen' => UploadedFile::fake()->createWithContent('x.png', 'no es png')], $this->del_admin())->assertStatus(422);
        $this->post($url, ['estado' => 'cualquiera'], $this->del_admin())->assertStatus(422);

        $this->assertSame('pendiente', $pedido->fresh()->estado);
        $this->assertSame('pendiente', $ajeno->fresh()->estado);
    }

    public function test_un_fallido_del_admin_deja_el_bloque_fallido_con_un_texto_para_el_dueno()
    {
        list($pedido, $mensaje) = $this->pedido_pendiente();

        $this->post('api/admin-sync/ia-imagenes/' . $pedido->uuid . '/resultado', ['estado' => 'fallida', 'error' => 'timeout de la PC'], $this->del_admin())
            ->assertStatus(200)
            ->assertJsonPath('estado', 'fallida');

        $this->assertSame('fallida', $pedido->fresh()->estado);
        $this->assertSame('timeout de la PC', $pedido->fresh()->error);

        $bloque = $mensaje->fresh()->bloques[0];

        $this->assertSame('fallida', $bloque['estado']);
        $this->assertNull($bloque['url']);
        $this->assertNotSame('timeout de la PC', $bloque['error'], 'El detalle técnico no va al dueño.');
    }

    public function test_la_imagen_no_se_le_sirve_a_otro_dueno_ni_antes_de_estar_lista()
    {
        list($pedido) = $this->pedido_pendiente();

        $this->get('api/ia-imagenes/' . $pedido->uuid, $this->de_la_app())->assertStatus(404);

        $this->post('api/admin-sync/ia-imagenes/' . $pedido->uuid . '/resultado', ['estado' => 'lista', 'imagen' => $this->png()], $this->del_admin())->assertStatus(200);

        $otro = User::create([
            'name' => 'Otro', 'company_name' => 'Otro negocio', 'email' => 'ia-app-otro-' . uniqid() . '@test.local', 'password' => Hash::make('x'),
        ]);
        $this->dar_extension($otro);

        $this->como_otro_cliente();

        $this->get('api/ia-imagenes/' . $pedido->uuid, $this->de_la_app($otro->createToken('ia-app:x', ['ia-app'])->plainTextToken))
            ->assertStatus(404);
    }
}
