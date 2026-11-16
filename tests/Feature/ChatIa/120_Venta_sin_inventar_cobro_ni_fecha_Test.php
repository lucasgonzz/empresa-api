<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\VentaConDatosInventadosIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), rama A, P1 — ventas sin inventar cobro ni fecha.
 *
 * 🔴 LOS CASOS REALES (Truvari, 13/11/2026, por audio, con la confianza en «directo»):
 *  - V2: «El cliente se llama Pomodoro Pablo y llevó una caja de Mascota Malbec…» (sin decir el cobro) →
 *    venta 3101 «cobrada con transferencia a la caja BRUBANK FER», sacado de otra charla.
 *  - V1: «generes una venta del día de ayer… del 11 de noviembre» → «Quedó cargada con fecha 11/11/2026»
 *    y «Date: sábado 7/11», con las ventas registradas el 13/11 (proponer_venta no tiene fecha).
 *  - V24: «Dejalo como pago en efectivo» → «¿A qué caja va el efectivo?» con una sola caja de efectivo.
 *
 * Corre sobre el fixture de plata (TestingFerreteriaSeeder). Artículos propios (prefijo `zz-c112`).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Venta_sin_inventar_cobro_ni_fecha_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** @var User */
    protected $dueno;

    /** @var Article Precio 2.000, stock 10. */
    protected $martillo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'agentes_ia']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');

        $this->martillo = Article::create([
            'name'        => 'zz-c112 Martillo prueba',
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 2000,
            'cost'        => 1000,
            'stock'       => 10,
            'iva_id'      => 2,
        ]);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Una conversación con, opcionalmente, una venta ya registrada en un turno anterior (la 3100 del caso
     * real), y el pedido nuevo de la persona con su assistant pendiente.
     *
     * @param  string  $pedido
     * @param  bool  $con_venta_anterior
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido, $con_venta_anterior = true)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        if ($con_venta_anterior) {

            AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Vendele un martillo, pagó por transferencia a la caja efectivo', 'estado' => 'listo']);

            $anterior = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'Venta N° 3100 registrada.', 'estado' => 'listo', 'acciones_habilitadas' => true]);

            AiMessageAction::forceCreate([
                'ai_message_id'      => $anterior->id,
                'ai_conversation_id' => $conversation->id,
                'user_id'            => $this->dueno->id,
                'auth_user_id'       => $this->dueno->id,
                'tipo'          => AiMessageAction::TIPO_VENTA,
                'clave'         => 'venta:anterior-c112',
                'estado'        => AiMessageAction::ESTADO_CONFIRMADA,
                'datos'         => ['resumen' => ['cobro' => 'contado', 'metodo_de_pago' => 'Transferencia', 'caja' => TestingFerreteriaSeeder::CAJA_EFECTIVO]],
                'presentacion'  => ['titulo' => 'Venta', 'renglones' => [], 'aviso' => null],
            ]);
        }

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    protected function proponer($conversation, $assistant, array $input)
    {
        return PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, $input);
    }

    /** Lo que mandó el modelo en el caso real: el cobro de la venta de antes. */
    protected function input_heredado(array $extra = [])
    {
        return array_merge([
            'items'          => [['articulo_id' => $this->martillo->id, 'cantidad' => 1]],
            'cliente'        => TestingFerreteriaSeeder::CLIENTE_CC,
            'cobro'          => 'contado',
            'metodo_de_pago' => 'Transferencia',
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ], $extra);
    }

    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta.';
        $assistant->estado = 'listo';
        $assistant->save();

        $movimientos_antes = $this->max_id_movimiento_caja();

        $http = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');

        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $venta_id = $http->json('model.resultado.venta_id');

        if (!is_null($venta_id)) {
            $this->ventas_creadas_por_escenarios[] = (int) $venta_id;
        }

        return $http;
    }

    // ---------------------------------------------------------------------
    // V2 — el cobro que no dijo la persona
    // ---------------------------------------------------------------------

    /**
     * 🔴 V2: la persona pidió la venta sin decir cómo cobró y el modelo mandó el cobro de la venta de antes.
     * No se arma tarjeta (con «directo» se habría registrado sola): se pregunta el cobro, con la nota.
     *
     * @test
     */
    public function el_cobro_que_no_dijo_la_persona_no_se_hereda_y_se_pregunta()
    {
        list($conversation, $assistant) = $this->conversacion(
            'El cliente se llama ' . TestingFerreteriaSeeder::CLIENTE_CC . ' y llevó un martillo zz-c112'
        );

        $respuesta = $this->proponer($conversation, $assistant, $this->input_heredado());

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertNotEmpty($respuesta['faltan']);
        $this->assertStringContainsString('cuenta corriente', implode(' | ', $respuesta['faltan']));
        $this->assertArrayHasKey('nota', $respuesta);
        $this->assertStringContainsString('no lo saques de otra venta', $respuesta['nota']);
        $this->assertSame(0, AiMessageAction::where('ai_message_id', $assistant->id)->count(), 'No puede quedar tarjeta.');
    }

    /**
     * Sin cliente la venta es de contado, pero el MÉTODO que no se dijo igual se pregunta.
     *
     * @test
     */
    public function sin_cliente_el_metodo_que_no_se_dijo_se_pregunta()
    {
        list($conversation, $assistant) = $this->conversacion('Llevó un martillo zz-c112');

        $respuesta = $this->proponer($conversation, $assistant, $this->input_heredado(['cliente' => null]));

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertContains('con qué método de pago se cobra', $respuesta['faltan']);
        $this->assertSame(0, AiMessageAction::where('ai_message_id', $assistant->id)->count());
    }

    /**
     * La cuenta corriente que no se dijo tampoco: se vuelve a preguntar cómo se cobra.
     *
     * @test
     */
    public function la_cuenta_corriente_que_no_se_dijo_se_pregunta()
    {
        // Cliente Contado (sin cuenta): si el cobro no se descartara, la respuesta sería el error de la cuenta y no la pregunta.
        list($conversation, $assistant) = $this->conversacion('El cliente es ' . TestingFerreteriaSeeder::CLIENTE_CONTADO . ', llevó un martillo zz-c112');

        $respuesta = $this->proponer($conversation, $assistant, [
            'items'   => [['articulo_id' => $this->martillo->id, 'cantidad' => 1]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
            'cobro'   => 'cuenta_corriente',
        ]);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertStringContainsString('cuenta corriente', implode(' | ', $respuesta['faltan']));
    }

    /**
     * 🔴 El camino real con el cobro DICHO (dictado como lo transcribe el audio: «la caja efetivo»):
     * propuesta → confirmación por el endpoint → venta en la base con su método, su caja, el movimiento de
     * caja y el stock descontado.
     *
     * @test
     */
    public function con_el_cobro_dicho_se_arma_y_al_confirmar_queda_la_venta_con_su_caja()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $transferencia = $this->resolver_metodo_pago_por_nombre('Transferencia');

        list($conversation, $assistant) = $this->conversacion(
            'El cliente es ' . TestingFerreteriaSeeder::CLIENTE_CC . ', llevó un martillo zz-c112. Me transfirió a la caja efetivo'
        );

        $respuesta = $this->proponer($conversation, $assistant, $this->input_heredado());

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $http = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $this->assertSame(200, $http->getStatusCode(), json_encode($http->json()));

        $venta = Sale::findOrFail((int) $http->json('model.resultado.venta_id'));

        $this->assertEquals(2000, (float) $venta->total, '', 0.01);
        $this->assertSame((int) $caja->id, (int) DB::table('current_acount_payment_method_sale')->where('sale_id', $venta->id)->value('caja_id'));
        $this->assertSame((int) $transferencia->id, (int) DB::table('current_acount_payment_method_sale')->where('sale_id', $venta->id)->value('current_acount_payment_method_id'));
        $this->assertTrue(DB::table('movimiento_cajas')->where('caja_id', $caja->id)->where('id', '>', 0)->exists());
        $this->assertEquals(9, (float) Article::find($this->martillo->id)->stock, 'El stock se descuenta.');
    }

    /**
     * Varias ventas del mismo pedido (un lote): la primera del turno no corta el tramo de la segunda.
     *
     * @test
     */
    public function una_venta_de_este_mismo_turno_no_corta_lo_dicho_para_la_siguiente()
    {
        list($conversation, $assistant) = $this->conversacion(
            'Dos ventas: un martillo zz-c112 para ' . TestingFerreteriaSeeder::CLIENTE_CC . ' y otro sin cliente, las dos por transferencia a la caja efectivo',
            false
        );

        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        $primera = $this->proponer($conversation, $assistant, $this->input_heredado());
        $this->assertTrue(!empty($primera['ok']), json_encode($primera));

        AiMessageAction::where('id', $primera['tarjeta_id'])->update(['estado' => AiMessageAction::ESTADO_CONFIRMADA]);

        $segunda = $this->proponer($conversation, $assistant, $this->input_heredado(['cliente' => null]));
        $this->assertTrue(!empty($segunda['ok']), json_encode($segunda));
    }

    /**
     * Con una foto en el tramo (una planilla de pedidos), el cobro pudo salir de ahí: no se descarta.
     *
     * @test
     */
    public function con_una_foto_en_el_medio_el_cobro_no_se_descarta()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        list($conversation, $assistant) = $this->conversacion('Cargá la venta de la planilla');

        $foto = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'user')->orderBy('id', 'DESC')->first();

        AiMessageImagen::forceCreate(['ai_message_id' => $foto->id, 'user_id' => $this->dueno->id, 'orden' => 1, 'path' => 'ai/c112.jpg']);

        $respuesta = $this->proponer($conversation, $assistant, $this->input_heredado());

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
    }

    // ---------------------------------------------------------------------
    // V24 — una sola caja de efectivo
    // ---------------------------------------------------------------------

    /**
     * «Dejalo como pago en efectivo» → no se pregunta la caja: es la única de efectivo, y la tarjeta la nombra.
     *
     * @test
     */
    public function efectivo_sin_caja_va_a_la_unica_caja_de_efectivo()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        list($conversation, $assistant) = $this->conversacion('Dejalo como pago en efectivo, un martillo zz-c112', false);

        $respuesta = $this->proponer($conversation, $assistant, [
            'items'          => [['articulo_id' => $this->martillo->id, 'cantidad' => 1]],
            'cobro'          => 'contado',
            'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame((int) $caja->id, (int) $tarjeta->datos['payload']['caja_id']);
        $this->assertStringContainsString(TestingFerreteriaSeeder::CAJA_EFECTIVO, json_encode($tarjeta->presentacion, JSON_UNESCAPED_UNICODE));
    }

    // ---------------------------------------------------------------------
    // V1 — la fecha que la venta no tiene
    // ---------------------------------------------------------------------

    /**
     * El detector con los textos reales (la fecha del caso se corre a «otro día que no es hoy»).
     *
     * @test
     */
    public function el_detector_de_la_venta_con_otra_fecha()
    {
        $hoy = Carbon::create(2026, 11, 13);

        $mienten = [
            'Pasame eso y la cargo con fecha 11/11/2026.',
            'La fecha quedó tomada como miércoles 11/11/2026.',
            'Venta N° 3101 registrada, total $97.000. Quedó cargada con fecha 11/11/2026.',
            'Decime el cliente, qué artículos y qué cantidad. Con eso la cargo para el sábado 7/11.',
            "Venta N° 3103 registrada: KRISLAO DARIO, total \$ 111.000.\n\nDate: sábado 7/11, y una caja de cada uno.",
        ];

        foreach ($mienten as $texto) {
            $this->assertTrue(VentaConDatosInventadosIaHelper::afirma_otra_fecha($texto, $hoy), $texto);
        }

        $no_mienten = [
            'La venta N° 3102 es la de RUBEN EDUARDO GIUSTO: 1 Rutini Encuentro Malbec, total $ 84.000, del 13/11.',
            'Venta N° 3104 registrada con fecha 13/11.',
            'La entrega queda para el viernes 20/11.',
            'Las ventas que registro quedan con la fecha de hoy.',
            'Ayer vendiste $ 120.000 en 4 ventas.',
            'Registré la venta y te agendé el recordatorio para el lunes 23/11.',
            'Te dejé la tarea para el viernes 20/11.',
        ];

        foreach ($no_mienten as $texto) {
            $this->assertFalse(VentaConDatosInventadosIaHelper::afirma_otra_fecha($texto, $hoy), $texto);
        }
    }

    /**
     * El cobro que dice el texto tiene que ser el de la venta del turno.
     *
     * @test
     */
    public function el_detector_del_cobro_que_la_tarjeta_no_tiene()
    {
        list($conversation, $assistant) = $this->conversacion('Un martillo zz-c112 en efectivo', false);

        AiMessageAction::forceCreate([
            'ai_message_id'      => $assistant->id,
            'ai_conversation_id' => $conversation->id,
            'user_id'            => $this->dueno->id,
            'auth_user_id'       => $this->dueno->id,
            'tipo'               => AiMessageAction::TIPO_VENTA,
            'clave'              => 'venta:c112-cobro',
            'estado'             => AiMessageAction::ESTADO_CONFIRMADA,
            'datos'              => ['resumen' => ['cobro' => 'contado', 'metodo_de_pago' => 'Efectivo', 'caja' => TestingFerreteriaSeeder::CAJA_EFECTIVO]],
            'presentacion'       => ['titulo' => 'Venta', 'renglones' => [], 'aviso' => null],
        ]);

        $this->assertTrue(VentaConDatosInventadosIaHelper::afirma_otro_cobro('Venta N° 3101 registrada. Quedó cobrada con transferencia.', $assistant));
        $this->assertFalse(VentaConDatosInventadosIaHelper::afirma_otro_cobro('Venta N° 3101 registrada, cobrada en efectivo.', $assistant));
        $this->assertFalse(VentaConDatosInventadosIaHelper::afirma_otro_cobro('Si te pagó por transferencia, decime y la corrijo.', $assistant));

        $honesto = VentaConDatosInventadosIaHelper::texto_honesto('Venta N° 3101 registrada. Quedó cobrada con transferencia.', $assistant);

        $this->assertStringNotContainsString('transferencia', $honesto);
        $this->assertStringContainsString(VentaConDatosInventadosIaHelper::AVISO_COBRO, $honesto);
    }

    /**
     * 🔴 El turno real por WhatsApp: el modelo promete cargarla «con fecha» de otro día; la guarda reintenta
     * con la nota y, si insiste, la persona no lee esa oración sino el aviso honesto.
     *
     * @test
     */
    public function por_whatsapp_la_venta_con_otra_fecha_reintenta_y_no_llega_la_mentira()
    {
        config([
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-c112',
            'services.deepseek.model'          => 'deepseek-general-c112',
            'services.deepseek.model_agil'     => 'deepseek-flash-c112',
            'services.deepseek.model_profundo' => 'deepseek-pro-c112',
            'broadcasting.default'             => 'null',
        ]);

        $comercio = User::create([
            'name'         => 'Comercio c112',
            'company_name' => 'Bebidas c112',
            'email'        => 'venta-c112-' . uniqid() . '@test.local',
            'password'     => bcrypt('secret'),
        ]);
        $comercio->agente_proveedor   = 'deepseek';
        $comercio->agente_pensamiento = 'agil';
        $comercio->agente_confianza   = 'resuelto';
        $comercio->save();

        $otro_dia = Carbon::today()->subDays(2)->format('d/m/Y');
        $mentira = 'Para armarla necesito el detalle: qué artículos, a qué cliente y cómo se cobra. Pasame eso y la cargo con fecha ' . $otro_dia . '.';

        $respuesta = function ($texto) {
            return ['model' => 'x', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $texto]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
        };

        $secuencia = Http::sequence()
            ->push($respuesta($mentira), 200)
            ->push($respuesta($mentira), 200)
            ->whenEmpty(Http::response(['error' => ['type' => 'api_error', 'message' => 'agotada c112']], 500));

        Http::fake(['api.deepseek.com/*' => $secuencia, '*' => Http::response(['error' => 'sin stub'], 500)]);

        $conversation = AiConversation::create(['user_id' => $comercio->id, 'auth_user_id' => $comercio->id]);
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => '¿Te puedo pedir que generes una venta del día de antes de ayer?', 'estado' => 'listo', 'canal' => AiMessage::CANAL_WHATSAPP]);
        $assistant = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true, 'canal' => AiMessage::CANAL_WHATSAPP]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertStringNotContainsString('con fecha ' . $otro_dia, $texto);
        $this->assertStringContainsString(VentaConDatosInventadosIaHelper::AVISO_FECHA, $texto);

        $bodies = [];
        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'api.deepseek.com') !== false) {
                $bodies[] = $par[0]->body();
            }
        }

        $this->assertCount(2, $bodies, 'Un solo reintento.');
        $this->assertStringContainsString('quedan SIEMPRE con la fecha de hoy', $bodies[1]);
    }
}
