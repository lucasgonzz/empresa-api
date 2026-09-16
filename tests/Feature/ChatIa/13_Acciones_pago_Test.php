<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaTareaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\MovimientoCaja;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-ia-acciones — el pago de cuenta corriente que propone el asistente (un cobro a un
 * cliente o un pago a un proveedor) y lo que pasa al confirmarlo.
 *
 * Lo que protege:
 *
 * - La moneda de la cuenta: sin la extensión ventas_en_dolares la única cuenta que existe para el
 *   asistente es la de pesos (crear_credit_accounts() crea las dos siempre y la pantalla esconde la
 *   de dólares: BtnCurrentAcounts::show()); con la extensión y dos cuentas, el asistente pregunta.
 * - Que confirmar registre el pago por CurrentAcountPagoAltaHelper::registrar(), el mismo camino que
 *   el modal de Pago: el haber, el saldo, la imputación contra el débito y el movimiento de caja
 *   (ingreso para un cobro, egreso para un pago a proveedor).
 * - Que con fecha pasada se recalcule la cuenta entera (check_saldos_y_pagos), como en la pantalla.
 * - Que con fecha futura no se mueva plata: se agenda una tarea para cobrar o pagar ese día.
 *
 * @group chat-ia
 */
class Acciones_pago_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta para comparar montos. */
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        $this->service = new AsistenteIaService();
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    /**
     * Le da al dueño la extensión de ventas en dólares (la transacción del test la revierte).
     *
     * @return void
     */
    protected function dar_ventas_en_dolares()
    {
        $extencion = ExtencionEmpresa::where('slug', 'ventas_en_dolares')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'ventas_en_dolares', 'name' => 'Ventas en dolares']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');
    }

    /**
     * @param User|null $persona
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
            'contenido'          => 'Cobrale a Juan',
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
     * La cuenta corriente de un modelo del fixture en una moneda (creándolas si faltaran, como hace
     * la pantalla al dar de alta el cliente).
     *
     * @param string $model_name client | provider
     * @param int $model_id
     * @param int $moneda_id
     * @return CreditAccount
     */
    protected function cuenta($model_name, $model_id, $moneda_id = 1)
    {
        CreditAccountHelper::crear_credit_accounts($model_name, $model_id, $this->dueno->id);

        $cuenta = CreditAccount::where('model_name', $model_name)
                                ->where('model_id', $model_id)
                                ->where('moneda_id', $moneda_id)
                                ->where('user_id', $this->dueno->id)
                                ->first();

        $this->assertNotNull($cuenta, 'No se pudo resolver la cuenta corriente de ' . $model_name . ' ' . $model_id . ' en moneda ' . $moneda_id . '.');

        return $cuenta;
    }

    /**
     * Deja una deuda en la cuenta por el endpoint real de nota de débito (el camino de la pantalla).
     *
     * @param string $model_name
     * @param int $model_id
     * @param CreditAccount $cuenta
     * @param float $monto
     * @return CurrentAcount
     */
    protected function deuda($model_name, $model_id, $cuenta, $monto)
    {
        $response = $this->postJson('api/current-acount/nota-debito', [
            'credit_account_id' => $cuenta->id,
            'model_name'        => $model_name,
            'model_id'          => $model_id,
            'debe'              => $monto,
            'description'       => 'Deuda del test P13',
        ]);

        $response->assertStatus(201);

        $debito = CurrentAcount::find($response->json('current_acount.id'));

        $this->cobros_cc_creados_por_escenarios[] = $debito->id;

        return $debito;
    }

    /**
     * Propone un pago y devuelve la respuesta de la herramienta.
     *
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param array $input
     * @return array
     */
    protected function proponer_pago($conversation, $assistant, array $input)
    {
        return $this->herramienta($conversation, $assistant, 'proponer_pago', $input);
    }

    /**
     * Deja el assistant en listo (la SPA recién ahí muestra la tarjeta) y confirma.
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

        $antes = $this->max_id_movimiento_caja();

        $response = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');

        $this->registrar_movimientos_caja_nuevos($antes);

        return $response;
    }

    /**
     * @test
     */
    public function sin_ventas_en_dolares_el_cobro_va_directo_a_la_cuenta_en_pesos()
    {
        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $pesos = $this->cuenta('client', $cliente->id, 1);
        $this->cuenta('client', $cliente->id, 2);

        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer_pago($conversation, $assistant, [
            'tipo'  => 'cliente',
            'id'    => $cliente->id,
            'monto' => 4000,
            'pagos' => [['metodo_de_pago_id' => $metodo->id, 'caja_id' => $caja->id]],
        ]);

        $this->assertTrue($respuesta['ok'], 'Sin la extensión no hay nada que preguntar: ' . json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals('pago', $tarjeta->tipo);
        $this->assertEquals('pago:' . $pesos->id, $tarjeta->clave);
        $this->assertEquals($pesos->id, $tarjeta->datos['credit_account_id']);
        $this->assertEquals('Pago de cliente', $tarjeta->presentacion['titulo']);
        $this->assertStringContainsString('cuenta en pesos', $tarjeta->presentacion['renglones'][0]['valor']);

        // Y la cuenta en dólares no existe para el asistente.
        $cuentas = $this->herramienta($conversation, $assistant, 'consultar_cuentas_corrientes', [
            'tipo' => 'cliente',
            'id'   => $cliente->id,
        ]);

        $this->assertCount(1, $cuentas);
        $this->assertEquals('pesos', $cuentas[0]['moneda']);
    }

    /**
     * @test
     */
    public function con_ventas_en_dolares_y_sin_moneda_pregunta_en_que_cuenta()
    {
        $this->dar_ventas_en_dolares();

        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->cuenta('client', $cliente->id, 1);
        $this->cuenta('client', $cliente->id, 2);

        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer_pago($conversation, $assistant, [
            'tipo'  => 'cliente',
            'id'    => $cliente->id,
            'monto' => 4000,
            'pagos' => [['metodo_de_pago_id' => $metodo->id]],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals(['en qué cuenta: pesos o dólares'], $respuesta['faltan']);
        $this->assertCount(2, $respuesta['opciones']['cuentas'], 'La pregunta va con los saldos de las dos cuentas.');
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Con la extensión, consultar_cuentas_corrientes muestra las dos.
        $cuentas = $this->herramienta($conversation, $assistant, 'consultar_cuentas_corrientes', [
            'tipo' => 'cliente',
            'id'   => $cliente->id,
        ]);

        $this->assertCount(2, $cuentas);
    }

    /**
     * @test
     */
    public function confirmar_un_cobro_deja_el_pago_imputado_y_el_ingreso_en_la_caja()
    {
        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->cuenta('client', $cliente->id, 1);
        $debito = $this->deuda('client', $cliente->id, $cuenta, 10000);

        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer_pago($conversation, $assistant, [
            'tipo'        => 'cliente',
            'id'          => $cliente->id,
            'monto'       => 4000,
            'pagos'       => [['metodo_de_pago_id' => $metodo->id, 'caja_id' => $caja->id]],
            'descripcion' => 'Cobro del test P13',
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        // La tarjeta dice el saldo de antes y el de después.
        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);
        $renglones = [];
        foreach ($tarjeta->presentacion['renglones'] as $renglon) {
            $renglones[$renglon['etiqueta']] = $renglon['valor'];
        }
        $this->assertEquals('debe $ 10.000', $renglones['Saldo actual']);
        $this->assertEquals('debe $ 6.000', $renglones['Saldo después']);
        $this->assertEquals('Efectivo · $ 4.000 → Caja Efectivo', $renglones['Pago']);

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);
        $this->assertEquals('confirmada', $confirmar->json('model.estado'));
        $this->assertNull($confirmar->json('model.resultado.ruta'), 'Un pago no manda a ninguna pantalla.');

        $pago = CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotNull('haber')->orderBy('id', 'DESC')->first();

        $this->cobros_cc_creados_por_escenarios[] = $pago->id;

        $this->assertEqualsWithDelta(4000, (float) $pago->haber, self::DELTA);
        $this->assertEquals('pago_from_client', $pago->status);
        $this->assertEquals('Pago N°' . $pago->num_receipt, $pago->detalle);
        $this->assertEquals($cliente->id, (int) $pago->client_id);
        $this->assertEqualsWithDelta(6000, (float) $pago->saldo, self::DELTA);
        $this->assertEquals($this->dueno->id, (int) $pago->user_id);

        $this->assertEquals(
            'Pago N° ' . $pago->num_receipt . ' registrado. Saldo de ' . $cliente->name . ': $ 6.000',
            $confirmar->json('model.resultado.texto')
        );

        // El saldo de la cuenta quedó sincronizado.
        $this->assertEqualsWithDelta(6000, (float) CreditAccount::find($cuenta->id)->saldo, self::DELTA);

        // La imputación FIFO tocó el débito.
        $debito->refresh();
        $this->assertEquals('pagandose', $debito->status);
        $this->assertEqualsWithDelta(4000, (float) $debito->pagandose, self::DELTA);
        $this->assertEquals(1, DB::table('pagado_por')->where('debe_id', $debito->id)->where('haber_id', $pago->id)->count());

        // Y el ingreso impactó en la caja.
        $movimiento = MovimientoCaja::where('caja_id', $caja->id)->orderBy('id', 'DESC')->first();
        $this->assertEqualsWithDelta(4000, (float) $movimiento->ingreso, self::DELTA);
        $this->assertNull($movimiento->egreso);
    }

    /**
     * @test
     */
    public function confirmar_un_pago_a_proveedor_deja_el_egreso_en_la_caja()
    {
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $this->assertNotNull($proveedor);

        $cuenta = $this->cuenta('provider', $proveedor->id, 1);
        $this->deuda('provider', $proveedor->id, $cuenta, 8000);

        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer_pago($conversation, $assistant, [
            'tipo'  => 'proveedor',
            'id'    => $proveedor->id,
            'monto' => 2000,
            'pagos' => [['metodo_de_pago_id' => $metodo->id, 'caja_id' => $caja->id]],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertEquals('Pago a proveedor', $tarjeta->presentacion['titulo']);
        $this->assertStringContainsString('le debés $ 8.000', $tarjeta->presentacion['renglones'][1]['valor']);

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);

        $pago = CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotNull('haber')->orderBy('id', 'DESC')->first();

        $this->cobros_cc_creados_por_escenarios[] = $pago->id;

        $this->assertEquals($proveedor->id, (int) $pago->provider_id);
        $this->assertEqualsWithDelta(2000, (float) $pago->haber, self::DELTA);
        $this->assertEqualsWithDelta(6000, (float) CreditAccount::find($cuenta->id)->saldo, self::DELTA);

        $movimiento = MovimientoCaja::where('caja_id', $caja->id)->orderBy('id', 'DESC')->first();
        $this->assertEqualsWithDelta(2000, (float) $movimiento->egreso, self::DELTA);
        $this->assertNull($movimiento->ingreso);
    }

    /**
     * Con fecha pasada, el pago entra en el medio del orden cronológico y la cuenta se recalcula
     * entera (check_saldos_y_pagos), igual que en la pantalla.
     *
     * @test
     */
    public function un_pago_con_fecha_pasada_recalcula_la_cuenta_entera()
    {
        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->cuenta('client', $cliente->id, 1);
        $debito = $this->deuda('client', $cliente->id, $cuenta, 10000);

        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        list($conversation, $assistant) = $this->conversacion();

        $anteayer = Carbon::today()->subDays(2);

        $respuesta = $this->proponer_pago($conversation, $assistant, [
            'tipo'  => 'cliente',
            'id'    => $cliente->id,
            'monto' => 3000,
            'fecha' => $anteayer->format('Y-m-d'),
            'pagos' => [['metodo_de_pago_id' => $metodo->id, 'caja_id' => $caja->id]],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);

        $pago = CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotNull('haber')->orderBy('id', 'DESC')->first();

        $this->cobros_cc_creados_por_escenarios[] = $pago->id;

        $this->assertEquals($anteayer->format('Y-m-d'), Carbon::parse($pago->created_at)->format('Y-m-d'), 'El pago quedó fechado ese día.');

        /*
         * El pago es ANTERIOR al débito, así que el recálculo completo deja el pago en -3000 y el
         * débito (posterior) en 7000. Sin check_saldos_y_pagos la cuenta habría quedado en 10000.
         */
        $this->assertEqualsWithDelta(-3000, (float) $pago->saldo, self::DELTA);
        $this->assertEqualsWithDelta(7000, (float) $debito->fresh()->saldo, self::DELTA);
        $this->assertEqualsWithDelta(7000, (float) CreditAccount::find($cuenta->id)->saldo, self::DELTA);
    }

    /**
     * @test
     */
    public function un_pago_con_fecha_futura_se_convierte_en_tarea_para_cobrar()
    {
        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->cuenta('client', $cliente->id, 1);

        list($conversation, $assistant) = $this->conversacion();

        $el_viernes = Carbon::today()->addDays(3);

        $respuesta = $this->proponer_pago($conversation, $assistant, [
            'tipo'  => 'cliente',
            'id'    => $cliente->id,
            'monto' => 5000,
            'fecha' => $el_viernes->format('Y-m-d'),
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertEquals('tarea_nueva', $respuesta['tipo']);
        $this->assertEquals('pago', $respuesta['convertido_desde']);
        $this->assertEquals('fecha futura', $respuesta['motivo']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals('tarea_nueva:pago:' . $cuenta->id, $tarjeta->clave);
        $this->assertEquals('Cobrar a ' . $cliente->name . ' $ 5.000', $tarjeta->datos['detalle']);
        $this->assertEquals($el_viernes->format('Y-m-d'), $tarjeta->datos['fecha_realizacion']);
        $this->assertNull($tarjeta->datos['expense_concept_id'], 'Un cobro futuro no lleva gasto asociado.');
        $this->assertEquals(PropuestaTareaIaHelper::AVISO_PAGO_FUTURO, $tarjeta->presentacion['aviso']);

        // Nada de plata se movió.
        $this->assertEquals(0, CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotNull('haber')->count());
    }

    /**
     * @test
     */
    public function si_el_pago_supera_la_deuda_la_tarjeta_lo_avisa()
    {
        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->cuenta('client', $cliente->id, 1);
        $this->deuda('client', $cliente->id, $cuenta, 1000);

        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer_pago($conversation, $assistant, [
            'tipo'  => 'cliente',
            'id'    => $cliente->id,
            'monto' => 1500,
            'pagos' => [['metodo_de_pago_id' => $metodo->id, 'caja_id' => $caja->id]],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals('Supera lo que debe: le quedan $ 500 a favor.', $tarjeta->presentacion['aviso']);
    }

    /**
     * @test
     */
    public function un_empleado_sin_el_permiso_de_clientes_no_puede_cobrar()
    {
        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->cuenta('client', $cliente->id, 1);

        $empleado = User::create([
            'name'     => 'Empleado sin clientes P13',
            'email'    => 'acciones-p13-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        list($conversation, $assistant) = $this->conversacion($empleado);

        $respuesta = $this->proponer_pago($conversation, $assistant, [
            'tipo'  => 'cliente',
            'id'    => $cliente->id,
            'monto' => 1000,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals('No tenés permiso para cargar pagos de clientes desde tu usuario.', $respuesta['error']);

        $opciones = $this->herramienta($conversation, $assistant, 'consultar_opciones_de_carga', []);

        $this->assertFalse($opciones['puede']['pagos_de_clientes']);
        $this->assertFalse($opciones['puede']['pagos_a_proveedores']);
    }

    /**
     * @test
     */
    public function un_proveedor_de_otro_dueno_no_se_puede_pagar()
    {
        $ajeno = DB::table('providers')->where('user_id', '!=', $this->dueno->id)->whereNull('deleted_at')->first();

        if (is_null($ajeno)) {
            $this->markTestSkipped('La base no tiene ningún proveedor de otro dueño para probar la tenencia.');
        }

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer_pago($conversation, $assistant, [
            'tipo'  => 'proveedor',
            'id'    => $ajeno->id,
            'monto' => 1000,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals('No encontré ese proveedor entre los tuyos.', $respuesta['error']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }
}
