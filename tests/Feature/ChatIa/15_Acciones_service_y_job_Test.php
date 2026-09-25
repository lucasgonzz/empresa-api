<?php

namespace Tests\Feature\ChatIa;

use App\Events\ChatIaMensajeActualizado;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\ExpenseConcept;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión asistente-ia-acciones — el servicio y el job con las herramientas de carga.
 *
 * Lo que protege este archivo: que SIN el flag `acciones` el asistente sea exactamente el de antes
 * (las mismas tools de lectura y el prompt de solo lectura) y CON el flag lleve las de carga; que
 * el loop de tool use deje la tarjeta colgada del mensaje; el reemplazo por clave y el aviso de
 * carga parecida (las dos defensas contra la carga duplicada); que el job en error descarte las
 * tarjetas; que el historial le cuente a la IA qué pasó con cada tarjeta; que el prompt traiga el
 * día de la semana correcto; y que toda herramienta de carga declarada tenga su despacho.
 *
 * El comercio de este archivo es nuevo y no tiene cajas: las propuestas de gasto van sin caja, que
 * es lo que hace la pantalla cuando la cuenta no tiene ninguna.
 *
 * 🔴 Anthropic siempre con Http::fake y la clave de prueba: los tests jamás salen a la red.
 */
class Acciones_service_y_job_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Las tools de lectura del asistente, EN SU ORDEN.
     *
     * 🔴 El orden es parte de la aserción y no un detalle: `AsistenteIaService::con_cache_control()`
     * cachea el bloque `tools` como un prefijo, así que reordenar el registro invalida el caché de
     * todas las conversaciones. Las ocho primeras son las de antes de la misión
     * agente-ia-mano-derecha y no se mueven; las siete de abajo son las del bloque B y por eso van
     * al final.
     *
     * ⚠️ Esta lista se mantiene AL DÍA a mano, a propósito: es la que denuncia una tool agregada
     * sin querer (o sacada sin querer). Derivarla del registro la volvería una tautología.
     */
    const HERRAMIENTAS_DE_LECTURA = [
        'consultar_stock_de_articulos',
        'consultar_clientes',
        'consultar_movimientos_de_cuenta_corriente',
        'consultar_articulos_mas_vendidos',
        'consultar_precios_de_proveedores',
        'consultar_ofertas_activas',
        'consultar_actividad_de_un_cliente',
        'consultar_interesados_en_un_articulo',
        'consultar_ventas_impagas_de_un_cliente',
        'consultar_quien_compro_un_articulo',
        'consultar_compras_de_un_articulo',
        'consultar_compras_a_un_proveedor',
        'consultar_stock_por_deposito',
        'que_puedo_consultar',
        'consultar_datos',
        // Misión asistente-omnisciente (21/9/2026): las cuatro de lectura sin límites, al final.
        'resumir_datos',
        'consultar_resumen_de_ventas',
        'consultar_reporte_contable',
        'mostrar_imagenes_de_articulos',
        // Misión asistente-ventas-y-fotos (21/9/2026): las ventas sin cobrar del negocio, al final.
        'consultar_ventas_sin_cobrar',
        // Misión asistente-capacidades-y-hilos (22/9/2026): el link del PDF, después de aquélla.
        'consultar_link_de_pdf',
        // Misión asistente-fotos-barras-y-compras (24/9/2026): la búsqueda por código de barras, al final.
        'buscar_producto_por_codigo_de_barras',
    ];

    /** @var User */
    protected $comercio;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->comercio = User::create([
            'name'         => 'Comercio acciones P15',
            'company_name' => 'Ferreteria P15',
            'email'        => 'acciones-p15-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->service = new AsistenteIaService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    /**
     * Conversación del dueño con un pedido del usuario y el assistant pendiente.
     *
     * @param bool $con_acciones
     * @param string $pedido
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion_con_pendiente($con_acciones, $pedido = 'Cargame el flete de 5000 en efectivo')
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => $con_acciones,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Subcategoría de gasto del comercio.
     *
     * @param string $nombre
     * @return ExpenseConcept
     */
    protected function subcategoria($nombre)
    {
        return ExpenseConcept::create([
            'num'     => (int) ExpenseConcept::where('user_id', $this->comercio->id)->max('num') + 1,
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
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
     * Bloque tool_use de proponer_gasto.
     *
     * @param ExpenseConcept $subcategoria
     * @param float $monto
     * @param string $id
     * @return array
     */
    protected function bloque_proponer_gasto($subcategoria, $monto, $id = 'toolu_gasto_01')
    {
        return [
            'type'  => 'tool_use',
            'id'    => $id,
            'name'  => 'proponer_gasto',
            'input' => [
                'subcategoria_id' => $subcategoria->id,
                'monto'           => $monto,
                'pagos'           => [
                    ['metodo_de_pago_id' => $this->efectivo()->id],
                ],
            ],
        ];
    }

    /**
     * Respuesta end_turn de Anthropic.
     *
     * @param string $texto
     * @return array
     */
    protected function end_turn($texto)
    {
        return [
            'model'       => 'claude-modelo-fake',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 100, 'output_tokens' => 10],
        ];
    }

    /**
     * @group chat-ia
     * @test
     */
    public function sin_acciones_el_request_lleva_solo_las_tools_de_lectura_y_el_prompt_de_solo_lectura()
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->end_turn('Tenés 12 tornillos.'), 200)]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente(false, '¿Cuántos tornillos tengo?');

        $this->service->responder($conversation, $assistant);

        $body = Http::recorded()[0][0]->data();

        $this->assertEquals(self::HERRAMIENTAS_DE_LECTURA, array_column($body['tools'], 'name'), 'Sin el flag viajan exactamente las tools de lectura declaradas, en su orden.');

        $prompt = $body['system'][0]['text'];

        $this->assertStringContainsString('Solo podés LEER. No podés crear, modificar ni borrar nada del sistema', $prompt);
        $this->assertStringNotContainsString('Qué podés cargar', $prompt);
        $this->assertStringContainsString('Hoy es ' . now()->format('d/m/Y') . '.', $prompt);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function con_acciones_el_request_suma_las_herramientas_de_carga_y_el_prompt_de_carga()
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->end_turn('¿De cuánto fue el flete?'), 200)]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente(true, 'Cargame el flete');

        $this->service->responder($conversation, $assistant);

        $body = Http::recorded()[0][0]->data();

        $nombres = array_column($body['tools'], 'name');

        $this->assertEquals(array_merge(self::HERRAMIENTAS_DE_LECTURA, HerramientasDeCarga::nombres()), $nombres);
        $this->assertContains('proponer_gasto', $nombres);
        $this->assertContains('proponer_marcar_tarea_hecha', $nombres);

        $prompt = $body['system'][0]['text'];

        $this->assertStringContainsString('Qué podés cargar, siempre con una tarjeta que la persona confirma:', $prompt);
        $this->assertStringContainsString('Nunca digas "ya lo cargué"', $prompt);
        $this->assertStringNotContainsString('Solo podés LEER', $prompt);

        // La tool sin argumentos viaja con properties como OBJETO JSON: Anthropic rechaza un array.
        $this->assertStringContainsString('"name":"consultar_opciones_de_carga","description"', Http::recorded()[0][0]->body());
        $this->assertStringNotContainsString('"properties":[]', Http::recorded()[0][0]->body());
    }

    /**
     * 🔴 TODA HERRAMIENTA DE CARGA TIENE QUE ESTAR NOMBRADA EN LA ENUMERACIÓN DEL PROMPT, porque
     * esa enumeración cierra con "Nada más:". Una tool que viaja en el bloque `tools` pero no
     * figura ahí es una tool construida y MUDA: el modelo la tiene y se niega a usarla porque el
     * prompt le dijo que eso no se puede. Es un defecto sin síntoma — no hay error, no hay log, la
     * funcionalidad simplemente no aparece nunca.
     *
     * Pasó con los combos y las ofertas (misión agente-ia-mano-derecha): se sumaron a
     * HerramientasDeCarga y el "Nada más" del prompt las habría dejado afuera.
     *
     * @group chat-ia
     * @test
     */
    public function la_enumeracion_del_prompt_nombra_todo_lo_que_se_puede_cargar()
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->end_turn('Listo.'), 200)]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente(true, 'Armame un combo');

        $this->service->responder($conversation, $assistant);

        $prompt = Http::recorded()[0][0]->data()['system'][0]['text'];

        /*
         * Misión asistente-masivas-imagenes-y-remito (19/9/2026): las tres cargas nuevas también.
         *
         * 🔴 Y la misión asistente-capacidades-y-hilos (22/9/2026) suma las suyas, que es la mitad
         * del arreglo: el 22/9 el agente contestó diez veces "no puedo" sobre cosas que la pantalla
         * hace, y el motivo es que el prompt no las nombraba. Una capacidad que existe y que el
         * prompt no enumera es una capacidad que el modelo no usa.
         */
        $lo_nuevo = ['CHEQUE', 'un PRESUPUESTO', 'MOVER STOCK', 'CARGARLE STOCK', 'UN PERMISO a un empleado', 'consultar_link_de_pdf'];

        // Misión asistente-mcp (22/9/2026): las acciones de pantalla y sus cuatro herramientas.
        $lo_nuevo = array_merge($lo_nuevo, ['ACCIONES DE PANTALLA', 'que_acciones_de_pantalla_hay', 'consultar_por_pantalla', 'proponer_accion_de_pantalla', 'proponer_borrado_por_pantalla']);

        foreach (array_merge(['un combo', 'una oferta', 'Gastos', 'pagos de clientes', 'pagos a proveedores', 'tareas nuevas de la agenda', 'imágenes para las categorías', 'actualización masiva de artículos', 'diseño de PDF'], $lo_nuevo) as $lo_que_se_carga) {
            $this->assertStringContainsString(
                $lo_que_se_carga,
                $prompt,
                'La enumeración del prompt no nombra "' . $lo_que_se_carga . '", y cierra con "Nada más": la herramienta queda muda.'
            );
        }

        // 🔴 El aviso al cliente queda apagado a propósito: si el modelo promete que le avisó,
        // miente. El prompt tiene que decírselo.
        $this->assertStringContainsString('no se le manda ningún mail', $prompt);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_loop_con_proponer_gasto_y_end_turn_deja_la_tarjeta_colgada_del_mensaje()
    {
        $flete = $this->subcategoria('Flete P15');

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'model'       => 'claude-modelo-fake',
                    'stop_reason' => 'tool_use',
                    'content'     => [$this->bloque_proponer_gasto($flete, 5000)],
                    'usage'       => ['input_tokens' => 300, 'output_tokens' => 40],
                ], 200)
                ->push($this->end_turn('Te dejé la tarjeta del flete para confirmar.'), 200),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente(true);

        $texto = $this->service->responder($conversation, $assistant);

        $this->assertEquals('Te dejé la tarjeta del flete para confirmar.', $texto);

        $tarjetas = AiMessageAction::where('ai_message_id', $assistant->id)->get();

        $this->assertCount(1, $tarjetas);
        $this->assertEquals('gasto', $tarjetas[0]->tipo);
        $this->assertEquals('propuesta', $tarjetas[0]->estado);
        $this->assertEquals('gasto:' . $flete->id, $tarjetas[0]->clave);
        $this->assertEquals('Gasto', $tarjetas[0]->presentacion['titulo']);
        $this->assertEquals($this->comercio->id, $tarjetas[0]->user_id);

        // Cuenta sin cajas: la fila va sin caja, con la forma completa que manda la pantalla.
        $fila = $tarjetas[0]->datos['payment_methods'][0];
        $this->assertEquals(0, $fila['caja_id']);
        $this->assertEquals(5000, $fila['amount']);
        $this->assertEquals(
            ['current_acount_payment_method_id', 'amount', 'caja_id', 'moneda_id', 'cotizacion', 'amount_cotizado', 'cuota_id', 'bank', 'payment_date', 'num', 'credit_card_id', 'credit_card_payment_plan_id'],
            array_keys($fila)
        );

        $segundo_body = Http::recorded()[1][0]->body();
        $this->assertStringContainsString('"tool_use_id":"toolu_gasto_01"', $segundo_body);
        $this->assertStringContainsString('\"ok\":true', $segundo_body);
        $this->assertStringContainsString('\"tarjeta_id\":' . $tarjetas[0]->id, $segundo_body);
    }

    /**
     * La defensa contra el doble registro: una corrección de la misma carga reemplaza a la tarjeta
     * anterior; una carga distinta no la pisa.
     *
     * @group chat-ia
     * @test
     */
    public function una_propuesta_con_la_misma_clave_reemplaza_a_la_anterior_y_otra_clave_no()
    {
        $flete = $this->subcategoria('Flete P15');
        $nafta = $this->subcategoria('Nafta P15');

        list($conversation, $assistant) = $this->conversacion_con_pendiente(true);

        $primera = json_decode($this->service->execute_tool_calls([$this->bloque_proponer_gasto($flete, 5000, 'toolu_1')], $conversation, $assistant)[0]['content'], true);
        $corregida = json_decode($this->service->execute_tool_calls([$this->bloque_proponer_gasto($flete, 6000, 'toolu_2')], $conversation, $assistant)[0]['content'], true);
        $otra = json_decode($this->service->execute_tool_calls([$this->bloque_proponer_gasto($nafta, 300, 'toolu_3')], $conversation, $assistant)[0]['content'], true);

        $this->assertTrue($primera['ok']);
        $this->assertTrue($corregida['ok']);
        $this->assertEquals([$primera['tarjeta_id']], $corregida['reemplazo'], 'La corrección informa qué tarjeta reemplazó.');
        $this->assertEquals([], $otra['reemplazo']);

        $this->assertEquals('reemplazada', AiMessageAction::find($primera['tarjeta_id'])->estado);
        $this->assertEquals('propuesta', AiMessageAction::find($corregida['tarjeta_id'])->estado, 'La nafta no pisa la tarjeta del flete.');
        $this->assertEquals('propuesta', AiMessageAction::find($otra['tarjeta_id'])->estado);
    }

    /**
     * La otra mitad de la carrera: si la tarjeta anterior de la misma carga ya se confirmó después
     * del pedido de la persona, la nueva nace con un aviso y la herramienta lo informa.
     *
     * @group chat-ia
     * @test
     */
    public function una_tarjeta_confirmada_despues_del_pedido_con_la_misma_clave_suma_el_aviso_de_carga_parecida()
    {
        $flete = $this->subcategoria('Flete P15');

        list($conversation, $assistant) = $this->conversacion_con_pendiente(true, 'no, era 6000');

        $pedido = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'user')->first();
        AiMessage::where('id', $pedido->id)->update(['created_at' => now()->subMinutes(2)]);

        $anterior = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'contenido'          => 'Te dejé la tarjeta.',
            'created_at'         => now()->subMinutes(5),
        ]);

        $confirmada = AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $anterior->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'tipo'               => 'gasto',
            'clave'              => 'gasto:' . $flete->id,
            'estado'             => 'confirmada',
            'datos'              => [],
            'presentacion'       => ['titulo' => 'Gasto', 'renglones' => [], 'aviso' => null],
            'resultado'          => ['texto' => 'Gasto N° 88 registrado', 'ruta' => null],
            // Se confirmó ANTES del pedido: no es la carrera, no hay aviso.
            'resuelta_at'        => now()->subMinutes(3),
        ]);

        $sin_aviso = json_decode($this->service->execute_tool_calls([$this->bloque_proponer_gasto($flete, 6000, 'toolu_a')], $conversation, $assistant)[0]['content'], true);

        $this->assertArrayNotHasKey('confirmada_parecida', $sin_aviso);
        $this->assertNull(AiMessageAction::find($sin_aviso['tarjeta_id'])->presentacion['aviso']);

        // Ahora se confirmó DESPUÉS del pedido, mientras se armaba la corrección.
        AiMessageAction::where('id', $confirmada->id)->update(['resuelta_at' => now()]);

        $con_aviso = json_decode($this->service->execute_tool_calls([$this->bloque_proponer_gasto($flete, 6000, 'toolu_b')], $conversation, $assistant)[0]['content'], true);

        $this->assertEquals(['tarjeta_id' => $confirmada->id, 'resultado' => 'Gasto N° 88 registrado'], $con_aviso['confirmada_parecida']);
        $this->assertEquals(
            'Ojo: hace un momento confirmaste una carga parecida (Gasto N° 88 registrado). Confirmá esta solo si es otra carga.',
            AiMessageAction::find($con_aviso['tarjeta_id'])->presentacion['aviso']
        );
    }

    /**
     * 🔴 La ventana fina de la misma carrera: la tarjeta vieja se confirma ENTRE el SELECT que la lee
     * como candidata a reemplazo y el UPDATE que la reemplaza. El UPDATE exige `propuesta`, así que no
     * la toca; informar el SELECT sería decirle a la IA que la reemplazó —y la IA le diría a la
     * persona que la vieja quedó cancelada— cuando en realidad ya está REGISTRADA, y confirmar la
     * nueva duplicaría la carga.
     *
     * La carrera se provoca con un listener de consultas que confirma la vieja justo después del
     * pluck. No hay otra forma de meterse en esa ventana desde un test, y si mañana el reemplazo deja
     * de leer las candidatas con ese SELECT, la aserción del listener avisa que el test dejó de medir.
     *
     * @group chat-ia
     * @test
     */
    public function una_tarjeta_confirmada_entre_el_select_y_el_update_no_se_informa_como_reemplazada()
    {
        $flete = $this->subcategoria('Flete P15');

        list($conversation, $assistant) = $this->conversacion_con_pendiente(true, 'no, era 6000');

        $primera = json_decode($this->service->execute_tool_calls([$this->bloque_proponer_gasto($flete, 5000, 'toolu_1')], $conversation, $assistant)[0]['content'], true);

        $this->assertTrue($primera['ok'], json_encode($primera));

        $confirmada_en_el_medio = false;

        DB::listen(function ($query) use ($primera, &$confirmada_en_el_medio) {

            if ($confirmada_en_el_medio) {
                return;
            }

            // El pluck de las candidatas al reemplazo.
            if (strpos($query->sql, 'select `id` from `ai_message_actions`') !== 0) {
                return;
            }

            $confirmada_en_el_medio = true;

            // update() de una query no pasa por los casts: el resultado va como JSON a mano.
            AiMessageAction::where('id', $primera['tarjeta_id'])->update([
                'estado'      => 'confirmada',
                'resuelta_at' => now(),
                'resultado'   => json_encode(['texto' => 'Gasto N° 91 registrado', 'ruta' => null]),
            ]);
        });

        $corregida = json_decode($this->service->execute_tool_calls([$this->bloque_proponer_gasto($flete, 6000, 'toolu_2')], $conversation, $assistant)[0]['content'], true);

        $this->assertTrue($confirmada_en_el_medio, 'El listener no corrió: el reemplazo ya no lee las candidatas con ese SELECT y este test dejó de medir la carrera.');

        $this->assertTrue($corregida['ok'], json_encode($corregida));
        $this->assertEquals([], $corregida['reemplazo'], 'Una tarjeta que quedó confirmada no se informa como reemplazada.');
        $this->assertEquals('confirmada', AiMessageAction::find($primera['tarjeta_id'])->estado_guardado());

        // Y la nueva nace con el aviso, que es lo único que evita el doble registro.
        $this->assertEquals(
            ['tarjeta_id' => (int) $primera['tarjeta_id'], 'resultado' => 'Gasto N° 91 registrado'],
            $corregida['confirmada_parecida']
        );
        $this->assertStringContainsString('carga parecida', AiMessageAction::find($corregida['tarjeta_id'])->presentacion['aviso']);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_job_que_termina_en_error_descarta_las_tarjetas_que_alcanzo_a_proponer()
    {
        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();
        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }
        $this->comercio->extencions()->attach($extencion->id);

        Event::fake([ChatIaMensajeActualizado::class]);

        $flete = $this->subcategoria('Flete P15');

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'model'       => 'claude-modelo-fake',
                    'stop_reason' => 'tool_use',
                    'content'     => [$this->bloque_proponer_gasto($flete, 5000)],
                    'usage'       => ['input_tokens' => 300, 'output_tokens' => 40],
                ], 200)
                ->push(['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente(true);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $this->assertEquals('error', $assistant->fresh()->estado);

        $tarjeta = AiMessageAction::where('ai_message_id', $assistant->id)->first();

        $this->assertNotNull($tarjeta, 'La herramienta alcanzó a crear la tarjeta antes del 529.');
        $this->assertEquals('descartada', $tarjeta->estado, 'La tarjeta de una respuesta que falló no se puede confirmar.');
    }

    /**
     * 🔴 Si la respuesta que falló venía a CORREGIR una tarjeta anterior, la anterior tiene que volver
     * a ser confirmable. Antes quedaba 'reemplazada' para siempre: la persona veía una sola tarjeta
     * diciendo "Reemplazada por una versión corregida" —por una corrección que nunca existió, porque
     * el mensaje falló— y no tenía nada que confirmar.
     *
     * @group chat-ia
     * @test
     */
    public function el_job_que_falla_devuelve_a_propuesta_lo_que_su_tanda_habia_reemplazado()
    {
        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();
        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }
        $this->comercio->extencions()->attach($extencion->id);

        Event::fake([ChatIaMensajeActualizado::class]);

        $flete = $this->subcategoria('Flete P15');

        // Primera respuesta, que sí llegó: deja la tarjeta del flete de 5000.
        list($conversation, $primer_assistant) = $this->conversacion_con_pendiente(true);

        $primera = json_decode($this->service->execute_tool_calls([$this->bloque_proponer_gasto($flete, 5000, 'toolu_ok')], $conversation, $primer_assistant)[0]['content'], true);

        $this->assertTrue($primera['ok'], json_encode($primera));

        AiMessage::where('id', $primer_assistant->id)->update(['estado' => 'listo', 'contenido' => 'Te dejé la tarjeta.']);

        // La persona corrige, y esa respuesta se cae después de proponer la corrección.
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'no, era 6000',
        ]);

        $segundo_assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'model'       => 'claude-modelo-fake',
                    'stop_reason' => 'tool_use',
                    'content'     => [$this->bloque_proponer_gasto($flete, 6000, 'toolu_falla')],
                    'usage'       => ['input_tokens' => 300, 'output_tokens' => 40],
                ], 200)
                ->push(['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529),
        ]);

        (new ResponderMensajeChatIaJob($segundo_assistant->id))->handle();

        $this->assertEquals('error', $segundo_assistant->fresh()->estado);

        $correccion = AiMessageAction::where('ai_message_id', $segundo_assistant->id)->first();

        $this->assertNotNull($correccion);
        $this->assertEquals('descartada', $correccion->estado);

        $vieja = AiMessageAction::find($primera['tarjeta_id']);

        $this->assertEquals('propuesta', $vieja->estado_guardado(), 'La tarjeta que la tanda fallida reemplazó vuelve a ser confirmable.');
        $this->assertNull($vieja->resuelta_at, 'Y deja de estar resuelta.');
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_historial_agrega_la_linea_de_cada_tarjeta_y_deja_igual_los_mensajes_sin_tarjetas()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'cargame el flete']);

        $con_tarjeta = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'Te dejé la tarjeta.']);

        $tarjeta = AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $con_tarjeta->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'tipo'               => 'gasto',
            'clave'              => 'gasto:1',
            'estado'             => 'confirmada',
            'datos'              => [],
            'presentacion'       => [
                'titulo'    => 'Gasto',
                'renglones' => [
                    ['etiqueta' => 'Subcategoría', 'valor' => 'Fletes · Flete'],
                    ['etiqueta' => 'Monto', 'valor' => '$ 5.000'],
                ],
                'aviso'     => null,
            ],
            'resultado'          => ['texto' => 'Gasto N° 88 registrado', 'ruta' => null],
            'resuelta_at'        => now(),
        ]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'gracias']);
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'De nada.']);

        $payload = $this->service->build_messages_payload($conversation);

        $this->assertCount(4, $payload);
        $this->assertEquals('cargame el flete', $payload[0]['content']);
        $this->assertEquals(
            "Te dejé la tarjeta.\n[Tarjeta #" . $tarjeta->id . ' · Gasto · Subcategoría: Fletes · Flete; Monto: $ 5.000 · estado: confirmada (Gasto N° 88 registrado)]',
            $payload[1]['content']
        );
        $this->assertEquals('De nada.', $payload[3]['content'], 'Un mensaje sin tarjetas viaja idéntico.');
    }

    /**
     * La captura del pedido: el 15/9/2026 es martes y el viernes es el 18. El prompt lleva el día y
     * los próximos 7 con su nombre, en las dos variantes.
     *
     * @group chat-ia
     * @test
     */
    public function el_prompt_trae_el_dia_de_la_semana_y_los_proximos_dias_con_su_nombre()
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00'));

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        foreach ([false, true] as $con_acciones) {
            $prompt = $this->service->build_system_prompt($conversation, $this->comercio, $con_acciones);

            $this->assertStringContainsString('Hoy es 15/09/2026. Es martes.', $prompt);
            $this->assertStringContainsString('miércoles 16/09, jueves 17/09, viernes 18/09, sábado 19/09, domingo 20/09, lunes 21/09, martes 22/09', $prompt);
            $this->assertStringNotContainsString('viernes 19/09', $prompt);
        }
    }

    /**
     * 🔴 LAS DOS PUNTAS: toda herramienta de carga declarada tiene su `case` en el despacho del mismo
     * archivo. Se lee el archivo porque el switch no es introspectable de otra forma.
     *
     * @group chat-ia
     * @test
     */
    public function toda_herramienta_de_carga_declarada_tiene_su_despacho_en_el_mismo_archivo()
    {
        $contenido = file_get_contents(app_path('Services/AsistenteIa/HerramientasDeCarga.php'));

        // El inventario: 10 de la misión asistente-ia-acciones + proponer_combo y proponer_oferta
        // (agente-ia-mano-derecha, 16/9/2026) + proponer_foto_sucursal
        // (foto-sucursal-y-asistente-configurable, 17/9/2026) + las 7 de
        // asistente-masivas-imagenes-y-remito (19/9/2026: consultar_categorias_sin_imagen,
        // proponer_imagenes_para_categorias, contar_articulos_por_filtro,
        // proponer_imagenes_para_articulos, proponer_actualizacion_masiva, consultar_disenos_de_pdf,
        // proponer_cambio_en_diseno_pdf) + las 2 de cheques-endoso-y-bancos (21/9/2026:
        // consultar_bancos_de_cheques, proponer_unificar_bancos_de_cheques) + las 5 de
        // asistente-omnisciente (21/9/2026: que_puedo_cargar, proponer_alta, proponer_edicion,
        // proponer_baja, proponer_venta) + proponer_foto_articulo (asistente-ventas-y-fotos,
        // 21/9/2026) + las 4 de asistente-capacidades-y-hilos (22/9/2026:
        // proponer_movimiento_de_stock, proponer_stock_en_deposito, proponer_presupuesto,
        // proponer_permiso_de_empleado) + las 4 de asistente-mcp (22/9/2026: las acciones de
        // pantalla, que_acciones_de_pantalla_hay, consultar_por_pantalla,
        // proponer_accion_de_pantalla, proponer_borrado_por_pantalla). El número se toca SOLO
        // cuando se agrega o se saca una herramienta a propósito: si se mueve sin que nadie lo haya
        // pedido, es que algo se declaró (o se borró) de más.
        $this->assertCount(36, HerramientasDeCarga::definiciones());

        // Y cada misión va al FINAL de lo que había, en su orden: el array es el prefijo del caché
        // de prompt. Las siete de asistente-masivas-imagenes-y-remito...
        $this->assertSame(
            [
                'consultar_categorias_sin_imagen',
                'proponer_imagenes_para_categorias',
                'contar_articulos_por_filtro',
                'proponer_imagenes_para_articulos',
                'proponer_actualizacion_masiva',
                'consultar_disenos_de_pdf',
                'proponer_cambio_en_diseno_pdf',
            ],
            array_slice(HerramientasDeCarga::nombres(), 13, 7)
        );

        // ...las dos de cheques-endoso-y-bancos después de ellas...
        $this->assertSame(
            [
                'consultar_bancos_de_cheques',
                'proponer_unificar_bancos_de_cheques',
            ],
            array_slice(HerramientasDeCarga::nombres(), 20, 2)
        );

        // ...las cinco de asistente-omnisciente después de ellas...
        $this->assertSame(
            [
                'que_puedo_cargar',
                'proponer_alta',
                'proponer_edicion',
                'proponer_baja',
                'proponer_venta',
            ],
            array_slice(HerramientasDeCarga::nombres(), 22, 5)
        );

        // ...la de asistente-ventas-y-fotos después de ellas...
        $this->assertSame(
            ['proponer_foto_articulo'],
            array_slice(HerramientasDeCarga::nombres(), 27, 1)
        );

        // ...las cuatro de asistente-capacidades-y-hilos después de aquélla...
        $this->assertSame(
            [
                'proponer_movimiento_de_stock',
                'proponer_stock_en_deposito',
                'proponer_presupuesto',
                'proponer_permiso_de_empleado',
            ],
            array_slice(HerramientasDeCarga::nombres(), 28, 4)
        );

        // ...y las cuatro acciones de pantalla de asistente-mcp al final de todo.
        $this->assertSame(
            [
                'que_acciones_de_pantalla_hay',
                'consultar_por_pantalla',
                'proponer_accion_de_pantalla',
                'proponer_borrado_por_pantalla',
            ],
            array_slice(HerramientasDeCarga::nombres(), 32)
        );

        foreach (HerramientasDeCarga::nombres() as $nombre) {
            $this->assertStringContainsString(
                "case '" . $nombre . "':",
                $contenido,
                'La herramienta ' . $nombre . ' está declarada y no se despacha: la IA la llamaría y recibiría "Tool desconocida".'
            );
        }
    }

    /**
     * Sin el flag (o sin mensaje) una herramienta de carga no existe para el loop, igual que antes de
     * la misión; y una propuesta sin mensaje donde colgar la tarjeta vuelve con is_error.
     *
     * @group chat-ia
     * @test
     */
    public function sin_el_flag_una_herramienta_de_carga_es_desconocida_y_sin_mensaje_una_propuesta_va_con_is_error()
    {
        $flete = $this->subcategoria('Flete P15');

        list($conversation, $sin_flag) = $this->conversacion_con_pendiente(false);

        $con_dos_argumentos = $this->service->execute_tool_calls([$this->bloque_proponer_gasto($flete, 5000)], $conversation);
        $this->assertTrue($con_dos_argumentos[0]['is_error']);
        $this->assertEquals('Tool desconocida: proponer_gasto', $con_dos_argumentos[0]['content']);

        $mensaje_sin_flag = $this->service->execute_tool_calls([$this->bloque_proponer_gasto($flete, 5000)], $conversation, $sin_flag);
        $this->assertTrue($mensaje_sin_flag[0]['is_error']);

        $sin_mensaje = HerramientasDeCarga::ejecutar('proponer_gasto', ['subcategoria_id' => $flete->id, 'monto' => 5000], $conversation, null);
        $this->assertTrue($sin_mensaje['is_error']);

        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Nada de esto puede haber creado una tarjeta.');
    }
}
