<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\OpcionesDeCargaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaTareaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Caja;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\DefaultPaymentMethodCaja;
use App\Models\Expense;
use App\Models\ExpenseConcept;
use App\Models\ExtencionEmpresa;
use App\Models\MovimientoCaja;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-ia-acciones — el gasto que propone el asistente y lo que pasa al confirmarlo.
 *
 * Corre sobre el fixture de plata (TestingFerreteriaSeeder): la caja de efectivo, el método de pago
 * Efectivo y la subcategoría Alquiler. Lo que protege:
 *
 * - Que la tarjeta se arme con los datos de la pantalla: las filas de pago con TODAS las claves que
 *   manda MultiPaymentMethods (copiadas de AgendaTestCase::fila_metodo_de_pago, nunca escritas
 *   mirando el helper).
 * - Las tres reglas de caja que espeja PagosIaHelper: sin caja y con cajas en la cuenta se pregunta;
 *   una caja que el desplegable no ofrece es un error; y una cuenta SIN cajas carga la fila sin caja.
 * - Que el método de pago 1 (el Cheque del catálogo) no se pueda usar, con el motivo de su tipo.
 * - Que un gasto con fecha futura no sea un gasto sino una tarea con su gasto asociado.
 * - Que confirmar registre el gasto por ExpenseHelper::crear (desglose + movimiento de caja +
 *   correlativo) y que una caja sin apertura corte con 422 sin escribir nada, dejando el motivo en la
 *   tarjeta.
 *
 * @group chat-ia
 */
class Acciones_gasto_Test extends EmpresaTestCase
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

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red (no llama a la API).
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        // El gate de las rutas del chat pide la extensión; el rollback de la transacción la saca.
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
     * Conversación de una persona de la cuenta con su assistant pendiente y las acciones habilitadas.
     *
     * @param User|null $persona  Default: el dueño del fixture.
     * @param User|null $owner  Default: el dueño del fixture.
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($persona = null, $owner = null)
    {
        $persona = is_null($persona) ? $this->dueno : $persona;
        $owner = is_null($owner) ? $this->dueno : $owner;

        $conversation = AiConversation::create([
            'user_id'      => $owner->id,
            'auth_user_id' => $persona->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Cargame el gasto',
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
     * Llama a una herramienta por el mismo camino que el loop del servicio y devuelve su respuesta
     * decodificada.
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
     * Fila de `pagos` para la herramienta.
     *
     * @param CurrentAcountPaymentMethod $metodo
     * @param Caja|null $caja
     * @param float|null $monto
     * @return array
     */
    protected function pago($metodo, $caja = null, $monto = null)
    {
        $fila = ['metodo_de_pago_id' => $metodo->id];

        if (!is_null($caja)) {
            $fila['caja_id'] = $caja->id;
        }

        if (!is_null($monto)) {
            $fila['monto'] = $monto;
        }

        return $fila;
    }

    /**
     * Una fila de `payment_methods` con todas las claves que manda MultiPaymentMethods en la SPA.
     * Copiada textual de tests/Feature/Agenda/AgendaTestCase.php::fila_metodo_de_pago (el payload
     * real de la pantalla), nunca escrita mirando el helper.
     *
     * @param int $current_acount_payment_method_id
     * @param float $monto
     * @param int $caja_id
     * @return array
     */
    protected function fila_metodo_de_pago($current_acount_payment_method_id, $monto, $caja_id)
    {
        return [
            'current_acount_payment_method_id'  => $current_acount_payment_method_id,
            'amount'                            => $monto,
            'caja_id'                           => $caja_id,
            'moneda_id'                         => 1,
            'cotizacion'                        => 0,
            'amount_cotizado'                   => 0,
            'cuota_id'                          => 0,
            'bank'                              => '',
            'payment_date'                      => '',
            'num'                               => '',
            'credit_card_id'                    => 0,
            'credit_card_payment_plan_id'       => 0,
        ];
    }

    /**
     * El renglón de la presentación con esa etiqueta (el primero).
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
    public function una_propuesta_completa_deja_la_tarjeta_con_los_datos_de_la_pantalla()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
            'pagos'           => [$this->pago($metodo, $caja)],
            'observaciones'   => 'Alquiler de septiembre',
        ]);

        $this->assertTrue($respuesta['ok'], 'La propuesta tenía que quedar armada: ' . json_encode($respuesta));
        $this->assertEquals('gasto', $respuesta['tipo']);
        $this->assertStringContainsString('$ 5.000', $respuesta['resumen']);
        $this->assertStringContainsString('Caja Efectivo', $respuesta['resumen']);
        $this->assertEquals([], $respuesta['reemplazo']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals('propuesta', $tarjeta->estado);
        $this->assertEquals('gasto:' . $concepto->id, $tarjeta->clave);
        $this->assertEquals($assistant->id, $tarjeta->ai_message_id);

        $presentacion = $tarjeta->presentacion;

        $this->assertEquals('Gasto', $presentacion['titulo']);
        $this->assertEquals(OpcionesDeCargaIaHelper::nombre_de_subcategoria($concepto), $this->renglon($presentacion, 'Subcategoría'));
        $this->assertEquals('$ 5.000', $this->renglon($presentacion, 'Monto'));
        $this->assertEquals('Efectivo · $ 5.000 → Caja Efectivo', $this->renglon($presentacion, 'Pago'));
        $this->assertEquals('Alquiler de septiembre', $this->renglon($presentacion, 'Observaciones'));
        $this->assertNull($presentacion['aviso']);

        // La fecha va con el día de la semana, como pide el contrato.
        $this->assertStringContainsString(Carbon::today()->format('d/m/Y'), $this->renglon($presentacion, 'Fecha'));

        // 🔴 La fila de pago tiene la forma COMPLETA que manda la pantalla.
        $this->assertEquals(
            [$this->fila_metodo_de_pago($metodo->id, 5000.0, $caja->id)],
            $tarjeta->datos['payment_methods']
        );
        $this->assertEquals($concepto->id, $tarjeta->datos['expense_concept_id']);
        $this->assertEquals(Carbon::today()->format('Y-m-d'), $tarjeta->datos['fecha']);
    }

    /**
     * @test
     */
    public function sin_pagos_pregunta_como_se_pago_y_no_crea_tarjeta()
    {
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals(['cómo se pagó'], $respuesta['faltan']);
        $this->assertNull($respuesta['error']);
        $this->assertNotEmpty($respuesta['opciones']['metodos_de_pago']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * La caja tiene que estar entre las que ofrece el desplegable: "Caja Sin Concepto" está cerrada.
     *
     * @test
     */
    public function una_caja_que_el_desplegable_no_ofrece_devuelve_error()
    {
        $cerrada = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_SIN_CONCEPTO);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        $this->assertEmpty($cerrada->abierta, 'El fixture tiene que traer esta caja cerrada.');

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
            'pagos'           => [$this->pago($metodo, $cerrada)],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals('La caja elegida para el pago con Efectivo no está entre las que podés usar.', $respuesta['error']);
        $this->assertNotEmpty($respuesta['opciones']['cajas']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Con cajas en la cuenta y sin caja en la fila, se usa la caja por defecto configurada para ese
     * método de pago (espejo de get_caja_por_defecto de la pantalla).
     *
     * @test
     */
    public function sin_caja_usa_la_caja_por_defecto_del_metodo_de_pago()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        // El fixture no trae ninguna configuración de caja por defecto: se crea acá (la transacción la revierte).
        DefaultPaymentMethodCaja::create([
            'caja_id'                          => $caja->id,
            'current_acount_payment_method_id' => $metodo->id,
            'address_id'                       => null,
            'employee_id'                      => null,
            'user_id'                          => $this->dueno->id,
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 1200,
            'pagos'           => [$this->pago($metodo)],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals($caja->id, $tarjeta->datos['payment_methods'][0]['caja_id']);
        $this->assertEquals('Efectivo · $ 1.200 → Caja Efectivo', $this->renglon($tarjeta->presentacion, 'Pago'));
    }

    /**
     * Sin caja en la fila y sin caja por defecto, el asistente pregunta a qué caja va (no elige una).
     *
     * @test
     */
    public function sin_caja_y_sin_caja_por_defecto_pregunta_a_que_caja_va()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        $this->assertEquals(
            0,
            DefaultPaymentMethodCaja::where('user_id', $this->dueno->id)->where('current_acount_payment_method_id', $metodo->id)->count(),
            'Este test necesita que no haya caja por defecto para Efectivo.'
        );

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
            'pagos'           => [$this->pago($metodo)],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals(['a qué caja va el pago con Efectivo'], $respuesta['faltan']);
        $this->assertNotEmpty($respuesta['opciones']['cajas'], 'La pregunta va con las cajas que puede usar.');
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * El método de pago 1 del catálogo (el Cheque) no lo carga el asistente, y el motivo es el de su
     * tipo: la pantalla tampoco le dibuja caja.
     *
     * @test
     */
    public function el_metodo_de_pago_uno_devuelve_error_con_el_motivo_de_su_tipo()
    {
        $metodo = CurrentAcountPaymentMethod::find(1);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        $this->assertNotNull($metodo, 'El catálogo del fixture tiene que tener el método de pago 1.');

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
            'pagos'           => [['metodo_de_pago_id' => 1]],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals(OpcionesDeCargaIaHelper::MOTIVOS_NO_USABLES['cheque'], $respuesta['error']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Y en las opciones de carga viaja como no usable, con ese mismo motivo.
        $opciones = $this->herramienta($conversation, $assistant, 'consultar_opciones_de_carga', []);

        $por_id = [];
        foreach ($opciones['metodos_de_pago'] as $fila) {
            $por_id[$fila['id']] = $fila;
        }

        $this->assertFalse($por_id[1]['se_puede_usar']);
        $this->assertEquals(OpcionesDeCargaIaHelper::MOTIVOS_NO_USABLES['cheque'], $por_id[1]['motivo']);
    }

    /**
     * Decisión 2 de Lucas: con fecha futura no es un gasto, es una tarea con su gasto asociado.
     *
     * @test
     */
    public function un_gasto_con_fecha_futura_se_convierte_en_tarea_con_su_gasto_asociado()
    {
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        list($conversation, $assistant) = $this->conversacion();

        $viernes = Carbon::today()->addDays(3);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
            'fecha'           => $viernes->format('Y-m-d'),
            'observaciones'   => 'Pagar flete',
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertEquals('tarea_nueva', $respuesta['tipo']);
        $this->assertEquals('gasto', $respuesta['convertido_desde']);
        $this->assertEquals('fecha futura', $respuesta['motivo']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals('tarea_nueva:gasto:' . $concepto->id, $tarjeta->clave);
        $this->assertEquals('Tarea en la agenda', $tarjeta->presentacion['titulo']);
        $this->assertEquals(PropuestaTareaIaHelper::AVISO_GASTO_FUTURO, $tarjeta->presentacion['aviso']);
        $this->assertEquals('Pagar flete', $tarjeta->datos['detalle']);
        $this->assertEquals($viernes->format('Y-m-d'), $tarjeta->datos['fecha_realizacion']);
        $this->assertEquals($concepto->id, $tarjeta->datos['expense_concept_id']);
        $this->assertEqualsWithDelta(5000, (float) $tarjeta->datos['expense_amount'], self::DELTA);

        // No se pidieron los pagos: ese día, al marcarla hecha, se pregunta cómo se pagó.
        $this->assertStringContainsString('5.000 estimado', $this->renglon($tarjeta->presentacion, 'Gasto asociado'));
    }

    /**
     * @test
     */
    public function un_empleado_sin_el_permiso_de_gastos_no_puede_proponer()
    {
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        $empleado = User::create([
            'name'     => 'Empleado sin gastos P12',
            'email'    => 'acciones-p12-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        list($conversation, $assistant) = $this->conversacion($empleado);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
            'pagos'           => [['metodo_de_pago_id' => $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO)->id]],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals('No tenés permiso para cargar gastos desde tu usuario.', $respuesta['error']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Y en las opciones de carga, `puede.gastos` viene en false.
        $opciones = $this->herramienta($conversation, $assistant, 'consultar_opciones_de_carga', []);
        $this->assertFalse($opciones['puede']['gastos']);
    }

    /**
     * Una cuenta sin ninguna caja carga la fila SIN caja: la pantalla no dibuja el selector
     * (PaymentMethodsStep::show_caja_select() pide cajas.length).
     *
     * @test
     */
    public function una_cuenta_sin_cajas_propone_la_fila_sin_caja()
    {
        $otro_dueno = User::create([
            'name'     => 'Comercio sin cajas P12',
            'email'    => 'acciones-p12-sin-cajas-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $concepto = ExpenseConcept::create([
            'num'     => 1,
            'name'    => 'Flete sin cajas P12',
            'user_id' => $otro_dueno->id,
        ]);

        $this->assertEquals(0, Caja::where('user_id', $otro_dueno->id)->count());

        list($conversation, $assistant) = $this->conversacion($otro_dueno, $otro_dueno);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 800,
            'pagos'           => [['metodo_de_pago_id' => $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO)->id]],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals(0, $tarjeta->datos['payment_methods'][0]['caja_id']);
        $this->assertEquals('Efectivo · $ 800 (sin caja)', $this->renglon($tarjeta->presentacion, 'Pago'));
    }

    /**
     * El camino completo: confirmar registra el gasto por ExpenseHelper::crear, con su desglose, su
     * movimiento de caja y su correlativo.
     *
     * @test
     */
    public function confirmar_registra_el_gasto_con_su_desglose_el_movimiento_de_caja_y_el_correlativo()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
            'pagos'           => [$this->pago($metodo, $caja)],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        // El mensaje pasa a listo: la SPA recién ahí muestra la tarjeta.
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $correlativo_anterior = (int) Expense::where('user_id', $this->dueno->id)->max('num');
        $movimientos_antes = $this->max_id_movimiento_caja();

        $confirmar = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');

        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $confirmar->assertStatus(200);
        $this->assertEquals('confirmada', $confirmar->json('model.estado'));
        $this->assertEquals('expense', $confirmar->json('model.resultado.ruta.name'));
        $this->assertEquals('Ver en Gastos', $confirmar->json('model.resultado.ruta.texto'));

        $gasto = Expense::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();

        $this->gastos_creados_por_escenarios[] = $gasto->id;

        $this->assertEquals('Gasto N° ' . $gasto->num . ' registrado', $confirmar->json('model.resultado.texto'));
        $this->assertEquals($correlativo_anterior + 1, (int) $gasto->num, 'El correlativo sale de num(), como en la pantalla.');
        $this->assertEqualsWithDelta(5000, (float) $gasto->amount, self::DELTA);
        $this->assertEquals($concepto->id, $gasto->expense_concept_id);
        $this->assertEquals(1, (int) $gasto->moneda_id);
        $this->assertEquals(Carbon::today()->format('Y-m-d'), Carbon::parse($gasto->created_at)->format('Y-m-d'));

        // El desglose quedó adjunto con su caja (es lo que lee el listado de Gastos).
        $gasto->load('current_acount_payment_methods');
        $this->assertCount(1, $gasto->current_acount_payment_methods);
        $this->assertEquals($metodo->id, $gasto->current_acount_payment_methods[0]->id);
        $this->assertEquals($caja->id, $gasto->current_acount_payment_methods[0]->pivot->caja_id);

        // Y el egreso impactó en la caja.
        $movimientos = MovimientoCaja::where('expense_id', $gasto->id)->get();

        $this->assertCount(1, $movimientos);
        $this->assertEquals($caja->id, $movimientos[0]->caja_id);
        $this->assertEqualsWithDelta(5000, (float) $movimientos[0]->egreso, self::DELTA);
    }

    /**
     * 🔴 Una caja que nunca se abrió corta con 422 ANTES de escribir: no queda gasto, la tarjeta
     * sigue propuesta y el motivo queda guardado en error_mensaje (la SPA lo muestra ahí).
     *
     * @test
     */
    public function una_caja_que_nunca_se_abrio_da_422_sin_escribir_nada_y_guarda_el_motivo()
    {
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        /*
         * Una caja marcada como abierta pero sin ninguna apertura: el desplegable la ofrece (mira
         * `abierta`) y el alta la rechaza (mira las aperturas). Es el caso que el 422 tiene que cubrir.
         */
        $caja_sin_apertura = Caja::create([
            'name'                  => 'Caja sin apertura P12',
            'num'                   => (int) Caja::where('user_id', $this->dueno->id)->max('num') + 1,
            'user_id'               => $this->dueno->id,
            'abierta'               => 1,
            'saldo'                 => 0,
            'comision_iva_incluido' => 0,
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 700,
            'pagos'           => [$this->pago($metodo, $caja_sin_apertura)],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $gastos_antes = Expense::where('user_id', $this->dueno->id)->count();
        $movimientos_antes = $this->max_id_movimiento_caja();

        $confirmar = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');

        $confirmar->assertStatus(422);
        $this->assertStringContainsString('Caja sin apertura P12', $confirmar->json('model.error_mensaje'));
        $this->assertStringContainsString('nunca se abrieron', $confirmar->json('model.error_mensaje'));
        $this->assertStringContainsString('registrar el gasto', $confirmar->json('model.error_mensaje'));
        $this->assertEquals('propuesta', $confirmar->json('model.estado'), 'La tarjeta queda confirmable: se puede reintentar.');

        $this->assertEquals($gastos_antes, Expense::where('user_id', $this->dueno->id)->count(), 'El 422 no puede haber escrito el gasto.');
        $this->assertEquals(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count());

        Caja::where('id', $caja_sin_apertura->id)->delete();
    }
}
