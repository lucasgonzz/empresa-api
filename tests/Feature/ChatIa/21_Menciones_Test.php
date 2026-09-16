<?php

namespace Tests\Feature\ChatIa;

use App\Events\ChatIaMensajeActualizado;
use App\Http\Controllers\Helpers\asistente_ia\MencionesIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión agente-ia-mano-derecha — bloque C1: `menciones[]`, el canal para el clic y el hover.
 *
 * 🔴 LO QUE ESTE ARCHIVO PROTEGE NO ES QUE APAREZCAN MENCIONES: es que NO aparezcan las
 * equivocadas. Una mención mal puesta manda al dueño a la ficha de otro artículo o a la cuenta
 * corriente de otro cliente, y eso es peor que no marcar nada — un nombre sin marcar no molesta a
 * nadie. Por eso la mitad del archivo son casos donde la respuesta correcta es `[]`: nombre que no
 * está en el texto, nombre adentro de otra palabra, dos clientes que se llaman igual, un id de otro
 * comercio, un nombre repetido en el catálogo.
 *
 * Y el otro invariante: las menciones viajan por los TRES lugares donde viaja un mensaje (el índice
 * paginado, show_message y el POST), porque la SPA usa los tres.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class Menciones_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita el chat. */
    const SLUG = 'asistente_ia';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio menciones C1',
            'company_name' => 'Ferreteria menciones C1',
            'email'        => 'menciones-c1-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'         => 'Otro comercio menciones C1',
            'company_name' => 'Ajeno menciones C1',
            'email'        => 'menciones-c1-ajeno-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Asigna la extensión al comercio (creando la fila del catálogo si la base del slot todavía no
     * la tiene sembrada).
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Asistente IA',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Conversación del dueño con un user 'listo' y un assistant 'pendiente'.
     *
     * @param  string  $pregunta
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion_con_pendiente($pregunta = '¿Quién me debe?')
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pregunta,
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Fakea el loop: una vuelta que llama a una tool y otra que contesta el texto final.
     *
     * @param  string  $tool
     * @param  array   $input
     * @param  string  $texto
     * @return void
     */
    protected function fakear_loop($tool, array $input, $texto)
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'stop_reason' => 'tool_use',
                    'content'     => [
                        [
                            'type'  => 'tool_use',
                            'id'    => 'toolu_menciones_01',
                            'name'  => $tool,
                            'input' => $input,
                        ],
                    ],
                    'usage'       => ['input_tokens' => 100, 'output_tokens' => 10],
                ], 200)
                ->push([
                    'stop_reason' => 'end_turn',
                    'content'     => [
                        ['type' => 'text', 'text' => $texto],
                    ],
                    'usage'       => ['input_tokens' => 120, 'output_tokens' => 12],
                ], 200),
        ]);
    }

    /**
     * Un cliente del comercio con deuda en cuenta corriente (la fuente de "¿quién me debe?").
     *
     * @param  string  $nombre
     * @param  float   $saldo
     * @return Client
     */
    protected function cliente_con_deuda($nombre, $saldo)
    {
        $cliente = Client::create(['name' => $nombre, 'user_id' => $this->comercio->id]);

        CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $cliente->id,
            'user_id'    => $this->comercio->id,
            'moneda_id'  => 1,
            'saldo'      => $saldo,
        ]);

        return $cliente;
    }

    /**
     * 🔴 EL CAMINO COMPLETO, Y POR LOS TRES LUGARES. El loop consulta clientes, el modelo nombra a
     * uno, y esa mención tiene que estar guardada y viajar por el índice paginado, por
     * show_message y —como lista vacía, porque nace 'pendiente'— por el `assistant_message` del
     * POST.
     *
     * @group chat-ia
     * @test
     */
    public function la_mencion_se_guarda_y_viaja_por_los_tres_lugares()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Event::fake([ChatIaMensajeActualizado::class]);

        $cliente = $this->cliente_con_deuda('Ferreteria Tucumana', 687610.88);

        $this->fakear_loop(
            'consultar_clientes',
            ['busqueda' => 'Ferreteria'],
            'El cliente que más te debe es Ferreteria Tucumana, con un saldo de $687.610,88.'
        );

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant->refresh();

        $this->assertEquals('listo', $assistant->estado);
        $this->assertEquals(
            [['tipo' => 'cliente', 'id' => $cliente->id, 'texto' => 'Ferreteria Tucumana']],
            $assistant->menciones,
            'La mención se arma en el servidor cruzando lo que devolvió la tool contra el texto final.'
        );

        $this->actingAs($this->comercio, 'web');

        // 1. El índice paginado.
        $indice = $this->getJson('api/ai-conversations/' . $conversation->id . '/messages');
        $indice->assertStatus(200);

        $del_indice = null;

        foreach ($indice->json('models.data') as $fila) {
            if ((int) $fila['id'] === (int) $assistant->id) {
                $del_indice = $fila;
            }
        }

        $this->assertNotNull($del_indice, 'El assistant tiene que estar en el índice.');
        $this->assertEquals(
            [['tipo' => 'cliente', 'id' => $cliente->id, 'texto' => 'Ferreteria Tucumana']],
            $del_indice['menciones']
        );

        // 2. show_message.
        $uno = $this->getJson('api/ai-conversations/' . $conversation->id . '/messages/' . $assistant->id);
        $uno->assertStatus(200);
        $this->assertEquals(
            [['tipo' => 'cliente', 'id' => $cliente->id, 'texto' => 'Ferreteria Tucumana']],
            $uno->json('model.menciones')
        );

        // 3. El POST: el assistant nace 'pendiente' y sin menciones, pero la clave viaja igual.
        Queue::fake();

        $nuevo = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => '¿Y el segundo?',
        ]);

        $nuevo->assertStatus(201);
        $this->assertSame(
            [],
            $nuevo->json('assistant_message.menciones'),
            'Siempre presente: si no hay menciones va [], nunca null y nunca ausente.'
        );
        $this->assertSame([], $nuevo->json('user_message.menciones'));
    }

    /**
     * Un mensaje anterior a la misión —columna en null— viaja con `[]`, no con null: es lo que le
     * permite a la SPA no preguntar si la clave existe.
     *
     * @group chat-ia
     * @test
     */
    public function un_mensaje_viejo_sin_menciones_viaja_con_lista_vacia()
    {
        $this->dar_extension();

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        $assistant->contenido = 'Una respuesta de antes de la misión.';
        $assistant->estado = 'listo';
        $assistant->save();

        $this->actingAs($this->comercio, 'web');

        $respuesta = $this->getJson('api/ai-conversations/' . $conversation->id . '/messages/' . $assistant->id);

        $respuesta->assertStatus(200);
        $this->assertSame([], $respuesta->json('model.menciones'));
    }

    /**
     * 🔴 El nombre que la tool devolvió pero el modelo NO escribió no viaja. Es la regla que
     * sostiene que `texto` sea siempre encontrable en `contenido`.
     *
     * @group chat-ia
     * @test
     */
    public function un_nombre_que_no_esta_en_el_texto_no_viaja()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Event::fake([ChatIaMensajeActualizado::class]);

        $this->cliente_con_deuda('Distribuidora Del Norte', 4000);
        $this->cliente_con_deuda('Almacen San Juan', 100);

        // La tool devuelve los dos; el modelo nombra a uno solo.
        $this->fakear_loop(
            'consultar_clientes',
            ['busqueda' => ''],
            'El que más te debe es Distribuidora Del Norte. El resto tiene saldos chicos.'
        );

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant->refresh();

        $this->assertCount(1, $assistant->menciones);
        $this->assertEquals('Distribuidora Del Norte', $assistant->menciones[0]['texto']);
    }

    /**
     * 🔴 El caso de los dos nombres donde uno es substring del otro. Si el modelo escribió el
     * LARGO, marcar el corto adentro mandaría al cliente equivocado: gana la más larga.
     *
     * @group chat-ia
     * @test
     */
    public function dos_nombres_donde_uno_es_substring_del_otro_no_se_pisan()
    {
        $corto = Client::create(['name' => 'Ferreteria El Tornillo', 'user_id' => $this->comercio->id]);
        $largo = Client::create(['name' => 'Ferreteria El Tornillo SA', 'user_id' => $this->comercio->id]);

        $candidatos = [
            ['tipo' => 'cliente', 'id' => $corto->id, 'texto' => 'Ferreteria El Tornillo'],
            ['tipo' => 'cliente', 'id' => $largo->id, 'texto' => 'Ferreteria El Tornillo SA'],
        ];

        // Solo aparece el largo: el corto no puede quedarse con ese tramo.
        $solo_el_largo = MencionesIaHelper::cruzar(
            $candidatos,
            'Le vendiste a Ferreteria El Tornillo SA la semana pasada.',
            $this->comercio->id
        );

        $this->assertEquals(
            [['tipo' => 'cliente', 'id' => $largo->id, 'texto' => 'Ferreteria El Tornillo SA']],
            $solo_el_largo
        );

        // Y cuando aparecen los dos, cada uno se queda con el suyo, en orden de aparición.
        $los_dos = MencionesIaHelper::cruzar(
            $candidatos,
            'Ferreteria El Tornillo SA te debe $10.000 y Ferreteria El Tornillo, $2.000.',
            $this->comercio->id
        );

        $this->assertCount(2, $los_dos);
        $this->assertEquals('Ferreteria El Tornillo SA', $los_dos[0]['texto']);
        $this->assertEquals($largo->id, $los_dos[0]['id']);
        $this->assertEquals('Ferreteria El Tornillo', $los_dos[1]['texto']);
        $this->assertEquals($corto->id, $los_dos[1]['id']);
    }

    /**
     * 🔴 Un id de OTRO comercio no se marca nunca, aunque el nombre coincida con el literal del
     * texto. Es el filtro que lee la base scopeada por dueño.
     *
     * @group chat-ia
     * @test
     */
    public function no_se_marca_un_id_de_otro_comercio()
    {
        $ajeno = Client::create(['name' => 'Corralon Ajeno', 'user_id' => $this->otro_comercio->id]);

        $menciones = MencionesIaHelper::cruzar(
            [['tipo' => 'cliente', 'id' => $ajeno->id, 'texto' => 'Corralon Ajeno']],
            'Corralon Ajeno te debe $5.000.',
            $this->comercio->id
        );

        $this->assertSame([], $menciones, 'Un cliente de otro comercio no puede terminar en un link del chat.');

        // Y lo mismo para artículos.
        $articulo_ajeno = Article::create(['name' => 'Lampara Ajena', 'user_id' => $this->otro_comercio->id]);

        $this->assertSame([], MencionesIaHelper::cruzar(
            [['tipo' => 'articulo', 'id' => $articulo_ajeno->id, 'texto' => 'Lampara Ajena']],
            'Tenés 3 de Lampara Ajena.',
            $this->comercio->id
        ));
    }

    /**
     * 🔴 Un nombre adentro de otra palabra no es una mención: "CABLE" adentro de "CABLEADO"
     * mandaría a la ficha de un artículo del que nadie habló.
     *
     * @group chat-ia
     * @test
     */
    public function un_nombre_adentro_de_otra_palabra_no_es_mencion()
    {
        $cable = Article::create(['name' => 'CABLE', 'user_id' => $this->comercio->id]);

        $this->assertSame([], MencionesIaHelper::cruzar(
            [['tipo' => 'articulo', 'id' => $cable->id, 'texto' => 'CABLE']],
            'El CABLEADO del galpon te salio $40.000.',
            $this->comercio->id
        ));

        // Delimitado sí, con signos alrededor incluidos.
        $marcada = MencionesIaHelper::cruzar(
            [['tipo' => 'articulo', 'id' => $cable->id, 'texto' => 'CABLE']],
            'Del articulo "CABLE" te quedan 3.',
            $this->comercio->id
        );

        $this->assertCount(1, $marcada);
        $this->assertEquals($cable->id, $marcada[0]['id']);
    }

    /**
     * 🔴 Dos clientes que se llaman IGUAL no se marcan: no hay forma de saber de cuál habló el
     * modelo, y elegir uno es tirar una moneda con la cuenta corriente del dueño.
     *
     * @group chat-ia
     * @test
     */
    public function un_nombre_que_resuelve_a_dos_registros_no_se_marca()
    {
        $uno = Client::create(['name' => 'Kiosco Central', 'user_id' => $this->comercio->id]);
        $dos = Client::create(['name' => 'Kiosco Central', 'user_id' => $this->comercio->id]);

        // Por id, los dos candidatos con el mismo literal: ambigua, se descartan los dos.
        $this->assertSame([], MencionesIaHelper::cruzar(
            [
                ['tipo' => 'cliente', 'id' => $uno->id, 'texto' => 'Kiosco Central'],
                ['tipo' => 'cliente', 'id' => $dos->id, 'texto' => 'Kiosco Central'],
            ],
            'Kiosco Central te debe $3.000.',
            $this->comercio->id
        ));

        // Y por nombre suelto tampoco: el nombre no es único entre los del dueño.
        $this->assertSame([], MencionesIaHelper::cruzar(
            [['tipo' => 'cliente', 'id' => 0, 'texto' => 'Kiosco Central']],
            'Kiosco Central te debe $3.000.',
            $this->comercio->id
        ));
    }

    /**
     * Un artículo y un cliente que se llaman igual tampoco se marcan: el tipo de la mención decide
     * a qué pantalla va el clic, y acá no se puede decidir.
     *
     * @group chat-ia
     * @test
     */
    public function un_literal_que_es_cliente_y_articulo_a_la_vez_no_se_marca()
    {
        $cliente = Client::create(['name' => 'Pintureria Sol', 'user_id' => $this->comercio->id]);
        $articulo = Article::create(['name' => 'Pintureria Sol', 'user_id' => $this->comercio->id]);

        $this->assertSame([], MencionesIaHelper::cruzar(
            [
                ['tipo' => 'cliente', 'id' => $cliente->id, 'texto' => 'Pintureria Sol'],
                ['tipo' => 'articulo', 'id' => $articulo->id, 'texto' => 'Pintureria Sol'],
            ],
            'Pintureria Sol aparece en las dos listas.',
            $this->comercio->id
        ));
    }

    /**
     * Un candidato cuyo nombre en la base ya NO es el literal (le cambiaron el nombre entre la
     * consulta y el texto, o la tool devolvió un nombre derivado) no se marca.
     *
     * @group chat-ia
     * @test
     */
    public function un_id_cuyo_nombre_actual_no_coincide_con_el_literal_no_se_marca()
    {
        $cliente = Client::create(['name' => 'Almacen Nuevo Nombre', 'user_id' => $this->comercio->id]);

        $this->assertSame([], MencionesIaHelper::cruzar(
            [['tipo' => 'cliente', 'id' => $cliente->id, 'texto' => 'Almacen Nombre Viejo']],
            'Almacen Nombre Viejo te debe $1.000.',
            $this->comercio->id
        ));
    }

    /**
     * 🔴 Media docena de consultas devuelven el NOMBRE y no el id —`consultar_articulos_mas_vendidos`
     * ("¿qué es lo que más vendo?"), `consultar_ofertas_activas`, `consultar_precios_de_proveedores`,
     * `consultar_interesados_en_un_articulo`—. Ese nombre se resuelve contra la base del dueño, y
     * solo si es ÚNICO entre los suyos: un homónimo de otro comercio no desempata ni se roba la
     * mención.
     *
     * @group chat-ia
     * @test
     */
    public function un_nombre_sin_id_se_resuelve_contra_la_base_del_dueno()
    {
        $articulo = Article::create(['name' => 'Fernet Branca 750', 'user_id' => $this->comercio->id]);

        // Un homónimo en OTRO comercio: no puede desempatar ni robarse la mención.
        Article::create(['name' => 'Fernet Branca 750', 'user_id' => $this->otro_comercio->id]);

        // La forma exacta de una fila de consultar_articulos_mas_vendidos: nombre, sin id.
        $candidatos = MencionesIaHelper::candidatos_de_tool('consultar_articulos_mas_vendidos', [
            ['nombre' => 'Fernet Branca 750', 'total_vendido' => 41.0],
        ]);

        $this->assertEquals(
            [['tipo' => 'articulo', 'id' => 0, 'texto' => 'Fernet Branca 750']],
            $candidatos,
            'Sin id, el candidato sale con 0 y se resuelve después contra la base.'
        );

        $menciones = MencionesIaHelper::cruzar(
            $candidatos,
            'Lo que más vendiste este mes es Fernet Branca 750, con 41 unidades.',
            $this->comercio->id
        );

        $this->assertEquals(
            [['tipo' => 'articulo', 'id' => $articulo->id, 'texto' => 'Fernet Branca 750']],
            $menciones
        );
    }

    /**
     * La misma clave `nombre` en OTRA tool no es un artículo: en las respuestas del stock por
     * depósito es el nombre de una sucursal. Por eso `nombre` solo cuenta para la tool que la usa
     * como nombre de artículo.
     *
     * @group chat-ia
     * @test
     */
    public function la_clave_nombre_de_otra_tool_no_se_toma_como_articulo()
    {
        $candidatos = MencionesIaHelper::candidatos_de_tool('consultar_stock_por_deposito', [
            'depositos' => [['nombre' => 'Sucursal Norte', 'stock' => 12]],
        ]);

        $this->assertSame([], $candidatos);
    }

    /**
     * La consulta genérica dice en su respuesta de qué entidad son las filas, así que sus artículos
     * y clientes también se pueden mencionar — y los proveedores, que tienen la misma forma de
     * fila, NO.
     *
     * @group chat-ia
     * @test
     */
    public function la_consulta_generica_menciona_articulos_y_clientes_pero_no_proveedores()
    {
        $articulo = Article::create(['name' => 'Taladro Percutor 750W', 'user_id' => $this->comercio->id]);

        $de_articulos = MencionesIaHelper::candidatos_de_tool('consultar_datos', [
            'entidad'    => 'article',
            'registros'  => [['id' => $articulo->id, 'name' => 'Taladro Percutor 750W']],
        ]);

        $this->assertEquals(
            [['tipo' => 'articulo', 'id' => $articulo->id, 'texto' => 'Taladro Percutor 750W']],
            $de_articulos
        );

        $de_proveedores = MencionesIaHelper::candidatos_de_tool('consultar_datos', [
            'entidad'   => 'provider',
            'registros' => [['id' => $articulo->id, 'name' => 'Taladro Percutor 750W']],
        ]);

        $this->assertSame([], $de_proveedores, 'Un proveedor no tiene tipo de mención: su fila no se anota.');
    }

    /**
     * Los rellenos de las consultas ("cliente borrado") no son nombres de nadie.
     *
     * @group chat-ia
     * @test
     */
    public function el_relleno_de_un_registro_borrado_no_se_marca()
    {
        $cliente = Client::create(['name' => 'cliente borrado', 'user_id' => $this->comercio->id]);

        $this->assertSame([], MencionesIaHelper::cruzar(
            [['tipo' => 'cliente', 'id' => $cliente->id, 'texto' => 'cliente borrado']],
            'La venta mas vieja es de cliente borrado.',
            $this->comercio->id
        ));
    }

    /**
     * Un literal de menos de tres caracteres no se marca: caería adentro de media docena de
     * palabras de cualquier mensaje.
     *
     * @group chat-ia
     * @test
     */
    public function un_literal_demasiado_corto_no_se_marca()
    {
        $cliente = Client::create(['name' => 'JR', 'user_id' => $this->comercio->id]);

        $this->assertSame([], MencionesIaHelper::cruzar(
            [['tipo' => 'cliente', 'id' => $cliente->id, 'texto' => 'JR']],
            'JR te debe $500.',
            $this->comercio->id
        ));
    }

    /**
     * Una respuesta que falló NO guarda menciones: el texto que la persona lee es el del error, y
     * anotarle nombres sería marcar palabras de un mensaje que el asistente nunca escribió.
     *
     * @group chat-ia
     * @test
     */
    public function una_respuesta_en_error_no_guarda_menciones()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Event::fake([ChatIaMensajeActualizado::class]);

        $this->cliente_con_deuda('Deudor Del Error', 1000);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
            ], 529),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant->refresh();

        $this->assertEquals('error', $assistant->estado);
        $this->assertSame([], $assistant->menciones);
    }
}
