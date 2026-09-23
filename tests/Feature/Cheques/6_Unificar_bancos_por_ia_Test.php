<?php

namespace Tests\Feature\Cheques;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Cheque;
use App\Models\ChequeBanco;
use App\Models\ExtencionEmpresa;
use App\Models\PermissionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Support\Facades\Hash;

/**
 * Misión cheques-endoso-y-bancos — el asistente unifica los bancos de los cheques: consulta los
 * textos libres, propone los grupos (SIEMPRE tarjeta, nunca escribe al proponer) y al confirmar
 * crea los bancos y asigna los cheques. Molde de cómo se invoca una herramienta y se confirma sin
 * pegarle a Anthropic: ChatIa/12_Acciones_gasto_Test.
 *
 * @group cheques
 * @group chat-ia
 */
class Unificar_bancos_por_ia_Test extends ChequesTestCase
{
    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        $this->service = new AsistenteIaService();
    }

    /**
     * Conversación de una persona de la cuenta con su assistant pendiente y las acciones habilitadas.
     *
     * @param User|null $persona Default: el dueño.
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($persona = null)
    {
        $persona = is_null($persona) ? $this->dueno : $persona;

        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $persona->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Unificame los bancos de los cheques',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Llama a una herramienta por el mismo camino que el loop del servicio.
     *
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param string $herramienta
     * @param array $input
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => $herramienta,
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * Confirma la tarjeta por el endpoint, como el botón.
     *
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param int $tarjeta_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    /**
     * Los cheques con texto libre del escenario: dos variantes de Nación, una de Galicia, y uno que
     * ya tiene banco del catálogo (no se lista) y otro sin texto (se cuenta aparte).
     *
     * @return array{0: array<int, Cheque>, 1: ChequeBanco}
     */
    protected function sembrar_textos()
    {
        $existente = ChequeBanco::create(['name' => 'Banco Provincia', 'user_id' => $this->dueno->id]);

        $cheques = [
            'nacion_1'   => $this->cheque_a_mano(['numero' => 'B-01', 'banco' => 'Bco Nacion']),
            'nacion_2'   => $this->cheque_a_mano(['numero' => 'B-02', 'banco' => 'Bco Nacion']),
            'nacion_3'   => $this->cheque_a_mano(['numero' => 'B-03', 'banco' => 'banco nación']),
            'galicia'    => $this->cheque_a_mano(['numero' => 'B-04', 'banco' => 'Galicia', 'tipo' => 'emitido']),
            'con_banco'  => $this->cheque_a_mano(['numero' => 'B-05', 'banco' => 'Banco Provincia', 'cheque_banco_id' => $existente->id]),
            'sin_texto'  => $this->cheque_a_mano(['numero' => 'B-06', 'banco' => '']),
        ];

        return [$cheques, $existente];
    }

    /**
     * El renglón de la presentación con esa etiqueta.
     *
     * @param array $presentacion
     * @param string $etiqueta
     * @return string|null
     */
    protected function renglon(array $presentacion, $etiqueta)
    {
        foreach ($presentacion['renglones'] as $renglon) {
            if ($renglon['etiqueta'] === $etiqueta) {
                return $renglon['valor'];
            }
        }

        return null;
    }

    /**
     * @test
     */
    public function consultar_devuelve_los_bancos_y_los_textos_sin_banco_con_sus_conteos()
    {
        list($cheques, $existente) = $this->sembrar_textos();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'consultar_bancos_de_cheques', []);

        $this->assertTrue($respuesta['ok']);
        $this->assertEquals([['nombre' => 'Banco Provincia', 'cheques' => 1]], $respuesta['bancos']);

        // Por cantidad de cheques, de mayor a menor; "Bco Nacion" y "banco nación" son dos textos.
        $this->assertEquals([
            ['texto' => 'Bco Nacion', 'cheques' => 2],
            ['texto' => 'banco nación', 'cheques' => 1],
            ['texto' => 'Galicia', 'cheques' => 1],
        ], $respuesta['textos_sin_banco']);

        $this->assertEquals(1, $respuesta['cheques_sin_texto']);
        $this->assertEquals(3, $respuesta['total_textos']);
        $this->assertArrayNotHasKey('nota', $respuesta);
    }

    /**
     * @test
     */
    public function proponer_deja_la_tarjeta_con_un_renglon_por_banco_y_no_escribe_nada()
    {
        list($cheques, $existente) = $this->sembrar_textos();

        list($conversation, $assistant) = $this->conversacion();

        $bancos_antes = ChequeBanco::where('user_id', $this->dueno->id)->count();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_unificar_bancos_de_cheques', [
            'grupos' => [
                ['banco' => 'Banco Nación', 'textos' => ['Bco Nacion', 'banco nación']],
                ['banco' => 'Banco Galicia', 'textos' => ['Galicia']],
            ],
        ]);

        $this->assertTrue($respuesta['ok'], 'La propuesta tenía que quedar armada: ' . json_encode($respuesta));
        $this->assertEquals('unificar_bancos_cheques', $respuesta['tipo']);
        $this->assertStringContainsString('Crear 2 bancos, asignar 4 cheques', $respuesta['resumen']);
        $this->assertEquals(4, $respuesta['cheques_alcanzados']);
        $this->assertEquals(2, $respuesta['bancos_nuevos']);
        $this->assertEquals([], $respuesta['reemplazo']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals('propuesta', $tarjeta->estado);
        $this->assertEquals($assistant->id, $tarjeta->ai_message_id);
        $this->assertStringStartsWith('unificar_bancos_cheques:', $tarjeta->clave);

        $presentacion = $tarjeta->presentacion;

        $this->assertEquals('Unificar bancos de cheques', $presentacion['titulo']);
        $this->assertEquals('Crear 2 bancos, asignar 4 cheques', $this->renglon($presentacion, 'Qué va a pasar'));
        $this->assertEquals('3 cheques: Bco Nacion, banco nación', $this->renglon($presentacion, 'Banco Nación (nuevo)'));
        $this->assertEquals('1 cheque: Galicia', $this->renglon($presentacion, 'Banco Galicia (nuevo)'));
        $this->assertStringContainsString('No se escribe nada hasta que confirmes', $presentacion['aviso']);

        // 🔴 Nada escrito: ni bancos nuevos ni cheques tocados.
        $this->assertEquals($bancos_antes, ChequeBanco::where('user_id', $this->dueno->id)->count());
        $this->assertNull($cheques['nacion_1']->fresh()->cheque_banco_id);
        $this->assertNull($cheques['galicia']->fresh()->cheque_banco_id);
        $this->assertEquals('Bco Nacion', $cheques['nacion_1']->fresh()->banco);

        // Y el tipo no se auto-confirma nunca: no está en la lista y el case no pasa por quizas_auto_confirmar.
        $this->assertNotContains(AiMessageAction::TIPO_UNIFICAR_BANCOS, HerramientasDeCarga::AUTO_CONFIRMABLES);
    }

    /**
     * @test
     */
    public function un_banco_que_ya_esta_en_el_catalogo_se_marca_como_existente_y_se_reusa()
    {
        list($cheques, $existente) = $this->sembrar_textos();

        // Un texto que es el banco que ya existe, escrito distinto (sin tilde, en minúsculas).
        $this->cheque_a_mano(['numero' => 'B-07', 'banco' => 'provincia']);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_unificar_bancos_de_cheques', [
            'grupos' => [
                ['banco' => 'banco provincia', 'textos' => ['provincia']],
            ],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertEquals(0, $respuesta['bancos_nuevos']);

        $presentacion = AiMessageAction::find($respuesta['tarjeta_id'])->presentacion;

        $this->assertEquals('1 cheque: provincia', $this->renglon($presentacion, 'Banco Provincia (existente)'));
        $this->assertEquals('Crear 0 bancos, asignar 1 cheque', $this->renglon($presentacion, 'Qué va a pasar'));

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);

        $this->assertEquals(1, ChequeBanco::where('user_id', $this->dueno->id)->count(), 'No tenía que crear otro Banco Provincia.');
        $this->assertEquals($existente->id, Cheque::where('numero', 'B-07')->where('user_id', $this->dueno->id)->first()->cheque_banco_id);
    }

    /**
     * @test
     */
    public function confirmar_crea_los_bancos_y_asigna_los_cheques_sin_tocar_el_texto()
    {
        list($cheques, $existente) = $this->sembrar_textos();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_unificar_bancos_de_cheques', [
            'grupos' => [
                ['banco' => 'Banco Nación', 'textos' => ['Bco Nacion', 'banco nación']],
                ['banco' => 'Banco Galicia', 'textos' => ['Galicia']],
            ],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);
        $this->assertEquals('confirmada', $confirmar->json('model.estado'));
        $this->assertEquals('Se crearon 2 bancos y se asignaron 4 cheques.', $confirmar->json('model.resultado.texto'));
        $this->assertEquals('cheque', $confirmar->json('model.resultado.ruta.name'));
        $this->assertEquals(['sub_view' => 'recibido', 'sub_sub_view' => 'pendientes'], $confirmar->json('model.resultado.ruta.params'));

        $nacion = ChequeBanco::where('user_id', $this->dueno->id)->where('name', 'Banco Nación')->first();
        $galicia = ChequeBanco::where('user_id', $this->dueno->id)->where('name', 'Banco Galicia')->first();

        $this->assertNotNull($nacion);
        $this->assertNotNull($galicia);
        $this->assertEquals(3, ChequeBanco::where('user_id', $this->dueno->id)->count());

        foreach (['nacion_1', 'nacion_2', 'nacion_3'] as $clave) {
            $this->assertEquals($nacion->id, $cheques[$clave]->fresh()->cheque_banco_id, $clave);
        }

        $this->assertEquals($galicia->id, $cheques['galicia']->fresh()->cheque_banco_id);

        // El texto no se toca; el que ya tenía banco y el que no tiene texto quedan como estaban.
        $this->assertEquals('Bco Nacion', $cheques['nacion_1']->fresh()->banco);
        $this->assertEquals('banco nación', $cheques['nacion_3']->fresh()->banco);
        $this->assertEquals($existente->id, $cheques['con_banco']->fresh()->cheque_banco_id);
        $this->assertNull($cheques['sin_texto']->fresh()->cheque_banco_id);

        // Después de unificar, consultar ya no lista esos textos.
        list($otra_conversation, $otro_assistant) = $this->conversacion();
        $consulta = $this->herramienta($otra_conversation, $otro_assistant, 'consultar_bancos_de_cheques', []);

        $this->assertEquals([], $consulta['textos_sin_banco']);
        $this->assertEquals([
            ['nombre' => 'Banco Galicia', 'cheques' => 1],
            ['nombre' => 'Banco Nación', 'cheques' => 3],
            ['nombre' => 'Banco Provincia', 'cheques' => 1],
        ], $consulta['bancos']);

        // Y GET cheque muestra el banco del catálogo en cada uno.
        $this->assertEquals('Banco Nación', $this->cheque_del_listado($cheques['nacion_3']->id)['cheque_banco']['name']);
    }

    /**
     * @test
     */
    public function un_texto_que_no_existe_devuelve_faltan_y_no_crea_tarjeta()
    {
        $this->sembrar_textos();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_unificar_bancos_de_cheques', [
            'grupos' => [
                ['banco' => 'Banco Nación', 'textos' => ['Bco Nacion', 'BNA']],
            ],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertCount(1, $respuesta['faltan']);
        $this->assertStringContainsString('"BNA"', $respuesta['faltan'][0]);
        $this->assertNull($respuesta['error']);
        $this->assertEquals('Bco Nacion', $respuesta['opciones']['textos_sin_banco'][0]['texto']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * @test
     */
    public function un_texto_en_dos_grupos_o_un_grupo_sin_banco_es_un_error()
    {
        $this->sembrar_textos();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_unificar_bancos_de_cheques', [
            'grupos' => [
                ['banco' => 'Banco Nación', 'textos' => ['Bco Nacion']],
                ['banco' => 'Banco de la Nación', 'textos' => ['bco nacion']],
            ],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('está en dos grupos', $respuesta['error']);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_unificar_bancos_de_cheques', [
            'grupos' => [
                ['banco' => '', 'textos' => ['Galicia']],
            ],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('no tiene el nombre del banco', $respuesta['error']);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_unificar_bancos_de_cheques', []);

        $this->assertFalse($respuesta['ok']);
        $this->assertCount(1, $respuesta['faltan']);

        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * @test
     */
    public function un_empleado_sin_el_permiso_del_modulo_de_cheques_no_puede()
    {
        $this->sembrar_textos();

        $empleado = User::create([
            'name'     => 'Empleado sin cheques',
            'email'    => 'cheques-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        list($conversation, $assistant) = $this->conversacion($empleado);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_unificar_bancos_de_cheques', [
            'grupos' => [['banco' => 'Banco Galicia', 'textos' => ['Galicia']]],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals('No tenés permiso para cargar bancos de cheques desde tu usuario.', $respuesta['error']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Con el permiso del módulo (espejo de la pantalla), sí. PermissionEmpresa y no Permission:
        // User::permissions() es belongsToMany(PermissionEmpresa::class) (ver ChatIa/22).
        $permiso = PermissionEmpresa::where('slug', 'reportes.cheques')->first();

        if (is_null($permiso)) {
            $permiso = PermissionEmpresa::forceCreate(['slug' => 'reportes.cheques', 'name' => 'Cheques']);
        }

        $empleado->permissions()->syncWithoutDetaching([$permiso->id]);

        list($conversation, $assistant) = $this->conversacion($empleado->fresh());

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_unificar_bancos_de_cheques', [
            'grupos' => [['banco' => 'Banco Galicia', 'textos' => ['Galicia']]],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
    }

    /**
     * Las dos herramientas están declaradas y despachadas (las dos puntas del mismo archivo).
     *
     * 🔴 ESTE TEST NO PUEDE AFIRMAR QUE SON LAS DOS ÚLTIMAS DEL CATÁLOGO, y hasta el 22/9/2026 lo
     * hacía (`array_slice($nombres, -2)`), con lo cual quedó ROJO en `develop` sin que nadie lo
     * notara: vive fuera del filtro `ChatIa` que corren las misiones del asistente.
     *
     * Lo rompió la regla que el propio repo impone. Toda herramienta nueva se agrega AL FINAL del
     * array, porque el request a la API se renderiza tools → system → messages y el caché de prompt
     * es por prefijo de bytes: mover una de lugar tira el caché entero. O sea que "ser las dos
     * últimas" es una propiedad que cualquier misión posterior rompe **haciendo lo correcto** — y de
     * hecho la rompieron las cinco herramientas de `asistente-omnisciente` (21/9) y las cuatro de
     * `asistente-capacidades-y-hilos` (22/9).
     *
     * El invariante que sí importa, y que es el que el docblock siempre dijo, es que las dos estén
     * declaradas, despachadas y juntas, con su esquema. Eso es lo que se afirma ahora. Que lo nuevo
     * vaya al final se sostiene con la regla escrita, no atando un test de cheques al largo del
     * catálogo.
     *
     * @test
     */
    public function las_dos_herramientas_estan_declaradas_juntas_y_despachadas()
    {
        $nombres = HerramientasDeCarga::nombres();

        $consultar = array_search('consultar_bancos_de_cheques', $nombres);
        $proponer_i = array_search('proponer_unificar_bancos_de_cheques', $nombres);

        $this->assertNotFalse($consultar, 'consultar_bancos_de_cheques no está declarada');
        $this->assertNotFalse($proponer_i, 'proponer_unificar_bancos_de_cheques no está declarada');

        /* Juntas y en ese orden: primero se consultan los textos libres, después se proponen los grupos. */
        $this->assertSame($consultar + 1, $proponer_i, 'Las dos herramientas de cheques dejaron de estar una al lado de la otra');

        $this->assertTrue(HerramientasDeCarga::maneja('consultar_bancos_de_cheques'));
        $this->assertTrue(HerramientasDeCarga::maneja('proponer_unificar_bancos_de_cheques'));

        $definiciones = HerramientasDeCarga::definiciones();
        $proponer = $definiciones[$proponer_i];

        $this->assertSame('proponer_unificar_bancos_de_cheques', $proponer['name']);
        $this->assertEquals(['grupos'], $proponer['input_schema']['required']);
        $this->assertEquals(['banco', 'textos'], $proponer['input_schema']['properties']['grupos']['items']['required']);
    }
}
