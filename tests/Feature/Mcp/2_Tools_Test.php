<?php

namespace Tests\Feature\Mcp;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseConcept;
use App\Models\Pending;
use App\Models\Provider;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\AsistenteIa\Mcp\McpHerramientasHelper;
use Carbon\Carbon;

/**
 * Misión asistente-mcp — las tools: el registro que ve el cliente y el despacho por tools/call.
 *
 * Lo que protege este archivo:
 *
 * - Que tools/list sea EXACTAMENTE el registro del asistente (lectura + carga menos la de fotos),
 *   en ese orden, con la forma que un cliente MCP valida: inputSchema de tipo object, `properties`
 *   como objeto (nunca `[]`), title y annotations.
 * - Que tools/call pase por el MISMO camino que el loop interno: el scoping por dueño de una
 *   lectura, y el flujo completo de una carga —propuesta en una llamada, confirmación por texto en
 *   la siguiente, con la tarjeta colgada de un assistant canal 'mcp' y el gasto registrado de
 *   verdad—, más la auto-ejecución en modo directo y el rebote de lo propuesto en la pantalla.
 *
 * 🔴 EL MODO DE FALLA QUE TAPA: una tool que el cliente "tiene" y no puede llamar (o al revés), un
 * `properties: []` que hace que Claude Desktop descarte la tool entera sin decir por qué, y —el
 * grave— un par de mensajes mal armado que deje la confirmación por texto rebotando siempre con
 * MENSAJE_MISMO_TURNO o, peor, confirmando lo que se propuso en la pantalla.
 *
 * El comercio es nuevo y no tiene cajas: las propuestas de gasto van sin caja, que es lo que hace
 * la pantalla cuando la cuenta no tiene ninguna (mismo criterio que ChatIa/15).
 */
class Tools_Test extends McpTestCase
{
    /** Delta para comparar montos. */
    const DELTA = 0.01;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    /**
     * Subcategoría de gasto del dueño.
     *
     * @param  string  $nombre
     * @return ExpenseConcept
     */
    protected function subcategoria($nombre)
    {
        return ExpenseConcept::create([
            'num'     => (int) ExpenseConcept::where('user_id', $this->dueno->id)->max('num') + 1,
            'name'    => $nombre,
            'user_id' => $this->dueno->id,
        ]);
    }

    /**
     * El método de pago "Efectivo" del catálogo global (lo siembra CurrentAcountPaymentMethodSeeder).
     *
     * @return CurrentAcountPaymentMethod
     */
    protected function efectivo()
    {
        $metodo = CurrentAcountPaymentMethod::where('name', 'Efectivo')->whereNull('c_a_payment_method_type_id')->where('id', '!=', 1)->first();

        if (is_null($metodo)) {
            $metodo = CurrentAcountPaymentMethod::create(['name' => 'Efectivo']);
        }

        return $metodo;
    }

    /**
     * Los argumentos de un proponer_gasto completo.
     *
     * @param  ExpenseConcept  $subcategoria
     * @param  float  $monto
     * @return array
     */
    protected function argumentos_de_gasto($subcategoria, $monto)
    {
        return [
            'subcategoria_id' => $subcategoria->id,
            'monto'           => $monto,
            'pagos'           => [['metodo_de_pago_id' => $this->efectivo()->id]],
        ];
    }

    /**
     * 🔴 tools/list es EXACTAMENTE nombres_de_lectura() + nombres(true) menos la de fotos, en ese
     * orden, y cada tool tiene la forma que pide el protocolo.
     *
     * @group mcp
     * @test
     */
    public function tools_list_es_exactamente_el_registro_del_asistente_traducido()
    {
        $respuesta = $this->rpc('tools/list', [], 1);

        $respuesta->assertStatus(200);

        $tools = $respuesta->json('result.tools');

        $esperados = (new AsistenteIaService())->nombres_de_lectura();

        foreach (HerramientasDeCarga::nombres(true) as $nombre) {

            if ($nombre === 'proponer_compra_con_factura') {
                continue;
            }

            $esperados[] = $nombre;
        }

        $this->assertSame($esperados, array_column($tools, 'name'), 'Las mismas tools que el asistente, en el mismo orden, sin la de fotos.');
        $this->assertSame($esperados, McpHerramientasHelper::nombres());
        $this->assertNotContains('proponer_compra_con_factura', array_column($tools, 'name'), 'Necesita las fotos de WhatsApp: acá no hay.');

        // Lo que un cliente valida: se mira el JSON crudo, porque decodificado `{}` y `[]` son iguales.
        $this->assertStringNotContainsString('"properties":[]', $respuesta->getContent(), 'properties vacío tiene que ser {} y nunca [].');

        foreach ($tools as $tool) {

            $this->assertEquals('object', $tool['inputSchema']['type'], $tool['name'] . ': inputSchema.type');
            $this->assertArrayHasKey('properties', $tool['inputSchema'], $tool['name'] . ': inputSchema.properties');
            $this->assertNotEquals('', (string) $tool['title'], $tool['name'] . ': title');
            $this->assertNotEquals('', (string) $tool['description'], $tool['name'] . ': description');
            // Desde el 24/9/2026 hay una tool que sí sale a internet (la búsqueda por código de
            // barras): la premisa "ninguna sale del negocio" pasó a ser "sólo las declaradas".
            $this->assertSame(
                in_array($tool['name'], McpHerramientasHelper::SALEN_A_INTERNET, true),
                $tool['annotations']['openWorldHint'],
                $tool['name'] . ': sólo las de SALEN_A_INTERNET salen del negocio'
            );
            $this->assertIsBool($tool['annotations']['readOnlyHint']);
            $this->assertIsBool($tool['annotations']['destructiveHint']);

            if (strpos($tool['name'], 'consultar_') === 0) {
                $this->assertTrue($tool['annotations']['readOnlyHint'], $tool['name'] . ' solo lee.');
            }
        }

        $por_nombre = array_column($tools, null, 'name');

        $this->assertFalse($por_nombre['proponer_gasto']['annotations']['readOnlyHint']);
        $this->assertFalse($por_nombre['proponer_gasto']['annotations']['destructiveHint']);
        $this->assertTrue($por_nombre['proponer_baja']['annotations']['destructiveHint']);
        $this->assertTrue($por_nombre['proponer_actualizacion_masiva']['annotations']['destructiveHint']);
        $this->assertTrue($por_nombre['que_puedo_consultar']['annotations']['readOnlyHint']);
        $this->assertEquals('Consultar stock de articulos', $por_nombre['consultar_stock_de_articulos']['title']);

        // La que en el origen tiene properties => new \stdClass() sigue siendo un objeto.
        $this->assertStringContainsString('"name":"consultar_opciones_de_carga","title":"Consultar opciones de carga"', $respuesta->getContent());
    }

    /**
     * Una lectura por MCP filtra por el dueño de la sesión, igual que en el chat: el artículo de
     * otro negocio no aparece aunque matchee la búsqueda.
     *
     * @group mcp
     * @test
     */
    public function consultar_stock_devuelve_lo_del_dueno_y_no_lo_de_otro()
    {
        Article::create(['name' => 'Tornillo MCP propio', 'user_id' => $this->dueno->id]);
        Article::create(['name' => 'Tornillo MCP ajeno', 'user_id' => $this->otro_dueno(true)->id]);

        $sesion = $this->inicializar();

        $respuesta = $this->tool('consultar_stock_de_articulos', ['busqueda' => 'Tornillo MCP'], $sesion);

        $respuesta->assertStatus(200);

        $this->assertFalse($respuesta->json('result.isError'));
        $this->assertEquals('text', $respuesta->json('result.content.0.type'));

        $texto = (string) $respuesta->json('result.content.0.text');

        $this->assertStringContainsString('Tornillo MCP propio', $texto);
        $this->assertStringNotContainsString('Tornillo MCP ajeno', $texto, 'El scoping por dueño es el mismo que en el chat.');

        // Una lista no es un objeto: structuredContent solo va cuando el content es un objeto JSON.
        $this->assertArrayNotHasKey('structuredContent', $respuesta->json('result'));

        // Y una lectura no escribe mensajes en la conversación.
        $this->assertEquals(0, AiMessage::where('ai_conversation_id', $this->conversacion_de($sesion)->id)->count(), 'Una lectura no infla ai_messages.');
    }

    /**
     * @group mcp
     * @test
     */
    public function una_tool_desconocida_es_32602()
    {
        $sesion = $this->inicializar();

        $respuesta = $this->tool('borrar_todo', [], $sesion);

        $respuesta->assertStatus(200);

        $this->assertEquals(-32602, $respuesta->json('error.code'));
        $this->assertStringContainsString('borrar_todo', $respuesta->json('error.message'));
    }

    /**
     * La tool excluida existe en HerramientasDeCarga pero el MCP no la declara, así que tampoco se
     * puede llamar.
     *
     * @group mcp
     * @test
     */
    public function la_tool_excluida_tampoco_se_puede_llamar()
    {
        $sesion = $this->inicializar();

        $respuesta = $this->tool('proponer_compra_con_factura', ['proveedor' => 'x'], $sesion);

        $this->assertEquals(-32602, $respuesta->json('error.code'));
    }

    /**
     * 🔴 EL FLUJO COMPLETO DE UNA CARGA, con el dueño en "resuelto": la llamada 1 deja la tarjeta
     * (colgada de un assistant canal 'mcp' que termina 'listo', detrás de un 'user' que registra el
     * pedido) y la llamada 2 la confirma por texto y el gasto queda registrado de verdad.
     *
     * @group mcp
     * @test
     */
    public function proponer_gasto_deja_la_tarjeta_y_confirmar_carga_pendiente_la_ejecuta()
    {
        $this->assertEquals(ConfianzaDelAgenteIaHelper::RESUELTO, ConfianzaDelAgenteIaHelper::con_default($this->dueno), 'Este test corre con el default.');

        $flete = $this->subcategoria('Flete MCP');

        $sesion = $this->inicializar();

        $conversation = $this->conversacion_de($sesion);

        $gastos_antes = Expense::where('user_id', $this->dueno->id)->count();

        // Llamada 1: la propuesta.
        $propuesta = $this->estructurado($this->tool('proponer_gasto', $this->argumentos_de_gasto($flete, 1234.5), $sesion, 'p1'));

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));
        $this->assertNotEmpty($propuesta['tarjeta_id']);
        $this->assertEquals(AiMessageAction::TIPO_GASTO, $propuesta['tipo']);
        $this->assertStringContainsString('Flete MCP', $propuesta['resumen']);

        $tarjeta = AiMessageAction::find($propuesta['tarjeta_id']);

        $this->assertEquals(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado(), 'En resuelto el gasto NO se ejecuta solo.');
        $this->assertEquals($conversation->id, $tarjeta->ai_conversation_id, 'La tarjeta cuelga de la conversación de la sesión.');
        $this->assertEquals($gastos_antes, Expense::where('user_id', $this->dueno->id)->count(), 'Proponer no registra nada.');

        $mensajes = AiMessage::where('ai_conversation_id', $conversation->id)->orderBy('id')->get();

        $this->assertCount(2, $mensajes, 'Una carga crea el par user + assistant.');
        $this->assertEquals('user', $mensajes[0]->rol);
        $this->assertEquals(AiMessage::CANAL_MCP, $mensajes[0]->canal);
        $this->assertStringStartsWith('Pedido por MCP: proponer_gasto ', (string) $mensajes[0]->contenido);
        $this->assertEquals('assistant', $mensajes[1]->rol);
        $this->assertEquals(AiMessage::CANAL_MCP, $mensajes[1]->canal);
        $this->assertEquals('listo', $mensajes[1]->estado, 'El assistant termina listo: sin eso la confirmación rebota con MENSAJE_MISMO_TURNO.');
        $this->assertTrue((bool) $mensajes[1]->acciones_habilitadas);
        $this->assertEquals($mensajes[1]->id, $tarjeta->ai_message_id, 'La tarjeta cuelga del assistant de esta llamada.');
        $this->assertStringContainsString('Flete MCP', (string) $mensajes[1]->contenido, 'El panel muestra el resumen de la propuesta.');

        // Llamada 2: la confirmación por texto, en un turno posterior.
        $confirmacion = $this->estructurado($this->tool('confirmar_carga_pendiente', ['tarjeta_id' => $propuesta['tarjeta_id']], $sesion, 'p2'));

        $this->assertTrue($confirmacion['ok'], 'Motivo: ' . json_encode($confirmacion));
        $this->assertEquals(AiMessageAction::ESTADO_CONFIRMADA, $confirmacion['estado']);
        $this->assertStringContainsString('registrado', $confirmacion['resultado']);

        $this->assertEquals($gastos_antes + 1, Expense::where('user_id', $this->dueno->id)->count(), 'El gasto tiene que existir de verdad.');

        $gasto = Expense::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();

        $this->assertEqualsWithDelta(1234.5, (float) $gasto->amount, self::DELTA);
        $this->assertEquals($flete->id, (int) $gasto->expense_concept_id);

        $this->assertEquals(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($propuesta['tarjeta_id'])->estado_guardado());

        $this->assertEquals(4, AiMessage::where('ai_conversation_id', $conversation->id)->count(), 'La confirmación también deja su par.');

        $ultimo = AiMessage::where('ai_conversation_id', $conversation->id)->orderBy('id', 'DESC')->first();

        $this->assertEquals('listo', $ultimo->estado);
        $this->assertStringContainsString('registrado', (string) $ultimo->contenido, 'El panel muestra lo que quedó registrado, no la nota al modelo.');
    }

    /**
     * Con el dueño en "directo", proponer_gasto ejecuta en el acto: la respuesta ya viene con el
     * estado confirmada y el gasto existe.
     *
     * @group mcp
     * @test
     */
    public function en_modo_directo_proponer_gasto_ejecuta_en_el_acto()
    {
        $this->dueno->agente_confianza = 'directo';
        $this->dueno->save();

        $nafta = $this->subcategoria('Nafta MCP');

        $sesion = $this->inicializar();

        $gastos_antes = Expense::where('user_id', $this->dueno->id)->count();

        $respuesta = $this->estructurado($this->tool('proponer_gasto', $this->argumentos_de_gasto($nafta, 300), $sesion));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertEquals(AiMessageAction::ESTADO_CONFIRMADA, $respuesta['estado'], 'En directo se ejecuta en la misma llamada.');
        $this->assertStringContainsString('registrado', $respuesta['resultado']);

        $this->assertEquals($gastos_antes + 1, Expense::where('user_id', $this->dueno->id)->count());
        $this->assertEquals(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
    }

    /**
     * 🔴 Lo que se propuso en la PANTALLA (canal 'sistema') no se confirma por MCP: la persona lo
     * miró y no lo tocó, y eso se respeta igual que en WhatsApp.
     *
     * @group mcp
     * @test
     */
    public function confirmar_una_tarjeta_propuesta_desde_la_pantalla_rebota_con_otro_canal()
    {
        $sesion = $this->inicializar();

        $conversation = $this->conversacion_de($sesion);

        $desde_la_pantalla = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'contenido'            => 'Te dejé la tarjeta.',
            'estado'               => 'listo',
            'canal'                => AiMessage::CANAL_SISTEMA,
            'acciones_habilitadas' => true,
        ]);

        $tarjeta = AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $desde_la_pantalla->id,
            'user_id'            => $this->dueno->id,
            'auth_user_id'       => $this->dueno->id,
            'tipo'               => AiMessageAction::TIPO_TAREA_NUEVA,
            'clave'              => 'tarea_nueva:mcp-test',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => [
                'detalle'               => 'Pagar el alquiler ' . uniqid(),
                'fecha_realizacion'     => Carbon::today()->addDays(3)->format('Y-m-d'),
                'es_recurrente'         => false,
                'unidad_frecuencia_id'  => null,
                'cantidad_frecuencia'   => null,
                'fecha_fin_recurrencia' => null,
                'expense_concept_id'    => null,
                'expense_amount'        => null,
                'notas'                 => null,
            ],
            'presentacion'       => ['titulo' => 'Tarea en la agenda', 'renglones' => [], 'aviso' => null],
        ]);

        $tareas_antes = Pending::where('user_id', $this->dueno->id)->count();

        $respuesta = $this->estructurado($this->tool('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta->id], $sesion));

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals(ConfirmacionPorTextoIaHelper::MENSAJE_OTRO_CANAL, $respuesta['error']);
        $this->assertEquals($tareas_antes, Pending::where('user_id', $this->dueno->id)->count(), 'No se ejecutó nada.');
        $this->assertEquals(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta->id)->estado_guardado());
    }

    /**
     * proponer_baja deja tarjeta en "resuelto" (nunca se borra solo) y el registro sigue ahí.
     *
     * @group mcp
     * @test
     */
    public function proponer_baja_deja_tarjeta_y_no_borra_nada()
    {
        $proveedor = Provider::create(['name' => 'Proveedor MCP para baja', 'user_id' => $this->dueno->id]);

        $sesion = $this->inicializar();

        $respuesta = $this->estructurado($this->tool('proponer_baja', ['entidad' => 'provider', 'registro' => 'Proveedor MCP para baja'], $sesion));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertEquals(AiMessageAction::TIPO_BAJA, $respuesta['tipo']);
        $this->assertEquals(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
        $this->assertNotNull(Provider::find($proveedor->id), 'Proponer la baja no borra.');
    }

    /**
     * Sin header de sesión, dos tools/call seguidos caen en la MISMA conversación (la última de las
     * 6 horas), y no en una por llamada.
     *
     * @group mcp
     * @test
     */
    public function sin_header_de_sesion_dos_llamadas_caen_en_la_misma_conversacion()
    {
        $flete = $this->subcategoria('Flete sin sesión');

        $antes = AiConversation::where('auth_user_id', $this->dueno->id)->where('origen', AiConversation::ORIGEN_MCP)->count();

        $primera = $this->estructurado($this->tool('proponer_gasto', $this->argumentos_de_gasto($flete, 100), null, 'a'));
        $segunda = $this->estructurado($this->tool('proponer_gasto', $this->argumentos_de_gasto($flete, 200), null, 'b'));

        $this->assertTrue($primera['ok'], json_encode($primera));
        $this->assertTrue($segunda['ok'], json_encode($segunda));

        $conversaciones = AiConversation::where('auth_user_id', $this->dueno->id)->where('origen', AiConversation::ORIGEN_MCP)->get();

        $this->assertCount($antes + 1, $conversaciones, 'Una sola conversación para las dos llamadas.');

        $conversation = $conversaciones->last();

        $this->assertStringContainsString('sin sesión', (string) $conversation->titulo);
        $this->assertEquals(4, AiMessage::where('ai_conversation_id', $conversation->id)->count());
        $this->assertEquals(
            $conversation->id,
            (int) AiMessageAction::find($segunda['tarjeta_id'])->ai_conversation_id
        );
    }

    /**
     * Una respuesta negativa de negocio (faltan datos) NO es un error del protocolo: viaja como
     * resultado con isError false, y el assistant del panel dice qué falta.
     *
     * @group mcp
     * @test
     */
    public function una_propuesta_con_datos_faltantes_no_es_error_de_protocolo()
    {
        $sesion = $this->inicializar();

        $respuesta = $this->tool('proponer_gasto', ['monto' => 50], $sesion);

        $respuesta->assertStatus(200);

        $this->assertFalse($respuesta->json('result.isError'), 'Faltan datos es una respuesta de negocio, no una falla técnica.');
        $this->assertFalse($respuesta->json('result.structuredContent.ok'));
        $this->assertNotEmpty($respuesta->json('result.structuredContent.faltan'));

        $ultimo = AiMessage::where('ai_conversation_id', $this->conversacion_de($sesion)->id)->orderBy('id', 'DESC')->first();

        $this->assertEquals('listo', $ultimo->estado);
        $this->assertStringStartsWith('Faltan datos:', (string) $ultimo->contenido);
    }

    /**
     * 🔴 Una LECTURA que vive en HerramientasDeCarga (consultar_proveedores, que_puedo_cargar…) no
     * escribe nada: ni mensajes ni last_message_at. Antes del arreglo dejaba el par con el JSON crudo
     * como contenido, igual que una carga (hallazgo del verificador del servidor, 23/9/2026).
     *
     * @group mcp
     * @test
     */
    public function una_lectura_de_herramientas_de_carga_no_escribe_nada()
    {
        Provider::create(['name' => 'Proveedor solo lectura MCP', 'user_id' => $this->dueno->id]);

        $sesion = $this->inicializar();

        $conversation = $this->conversacion_de($sesion);

        $last_message_at_antes = (string) $conversation->last_message_at;

        $this->assertTrue(HerramientasDeCarga::maneja('consultar_proveedores'), 'La premisa del test: es una tool de HerramientasDeCarga.');
        $this->assertFalse(HerramientasDeCarga::es_de_carga('consultar_proveedores'), '…pero de lectura.');

        $respuesta = $this->tool('consultar_proveedores', ['busqueda' => 'solo lectura MCP'], $sesion);

        $respuesta->assertStatus(200);

        $this->assertFalse($respuesta->json('result.isError'));
        $this->assertStringContainsString('Proveedor solo lectura MCP', (string) $respuesta->json('result.content.0.text'), 'La lectura se ejecutó de verdad.');

        $catalogo = $this->tool('que_puedo_cargar', [], $sesion, 2);

        $this->assertFalse($catalogo->json('result.isError'));

        $this->assertEquals(0, AiMessage::where('ai_conversation_id', $conversation->id)->count(), 'Una lectura no deja mensajes.');

        $conversation->refresh();

        $this->assertEquals($last_message_at_antes, (string) $conversation->last_message_at, 'Ni mueve last_message_at.');
    }

    /**
     * cancelar_carga_pendiente SÍ deja su par (toca una tarjeta y el dueño tiene que ver quién la
     * cerró) y la tarjeta queda cancelada sin ejecutar nada.
     *
     * @group mcp
     * @test
     */
    public function cancelar_carga_pendiente_deja_su_par_y_cancela_la_tarjeta()
    {
        $flete = $this->subcategoria('Flete a cancelar MCP');

        $sesion = $this->inicializar();

        $conversation = $this->conversacion_de($sesion);

        $gastos_antes = Expense::where('user_id', $this->dueno->id)->count();

        $propuesta = $this->estructurado($this->tool('proponer_gasto', $this->argumentos_de_gasto($flete, 900), $sesion, 'p1'));

        $this->assertTrue($propuesta['ok'], json_encode($propuesta));
        $this->assertEquals(2, AiMessage::where('ai_conversation_id', $conversation->id)->count(), 'La propuesta deja su par.');

        $cancelacion = $this->estructurado($this->tool('cancelar_carga_pendiente', ['tarjeta_id' => $propuesta['tarjeta_id']], $sesion, 'p2'));

        $this->assertTrue($cancelacion['ok'], json_encode($cancelacion));
        $this->assertEquals(AiMessageAction::ESTADO_CANCELADA, $cancelacion['estado']);
        $this->assertEquals(AiMessageAction::ESTADO_CANCELADA, AiMessageAction::find($propuesta['tarjeta_id'])->estado_guardado());
        $this->assertEquals($gastos_antes, Expense::where('user_id', $this->dueno->id)->count(), 'Cancelar no registra nada.');

        $this->assertEquals(4, AiMessage::where('ai_conversation_id', $conversation->id)->count(), 'La cancelación también deja su par.');

        $ultimo = AiMessage::where('ai_conversation_id', $conversation->id)->orderBy('id', 'DESC')->first();

        $this->assertEquals('listo', $ultimo->estado);
        $this->assertEquals('Carga cancelada.', (string) $ultimo->contenido, 'El panel dice que se canceló, no la nota al modelo que habla de "registrado".');
    }
}
