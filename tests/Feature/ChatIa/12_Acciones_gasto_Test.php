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
     * 🔴 EL MÉTODO DE PAGO 1 DEL CATÁLOGO (EL CHEQUE) PASÓ A SER USABLE EL 22/9/2026 (misión
     * asistente-capacidades-y-hilos), y este test dice cómo.
     *
     * Hasta ese día devolvía el motivo "Los cheques se cargan desde la pantalla", que es
     * literalmente lo que el agente contestó en el mensaje #46 de demo3. Ahora se puede cargar,
     * pero SOLO con sus datos: sin número, banco y fecha de vencimiento el cheque quedaría en
     * blanco (todas las columnas de `cheques` son nullable y `ChequeHelper::crear_cheque()` no
     * valida nada), así que lo que antes era un "no se puede" ahora es un "faltan".
     *
     * Y la regla por id (METODO_SIN_CAJA_ID) sigue existiendo para todo lo demás: lo que se levanta
     * es la excepción del cheque, que no lleva caja porque no mueve caja.
     *
     * @test
     */
    public function el_metodo_de_pago_uno_es_el_cheque_y_ahora_se_puede_usar_con_sus_datos()
    {
        $metodo = CurrentAcountPaymentMethod::find(1);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        $this->assertNotNull($metodo, 'El catálogo del fixture tiene que tener el método de pago 1.');
        $this->assertTrue(OpcionesDeCargaIaHelper::es_cheque($metodo), 'El método 1 del catálogo tiene que ser el Cheque.');

        list($conversation, $assistant) = $this->conversacion();

        // Sin los datos del cheque no se propone nada, y se pide lo que falta.
        $sin_datos = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
            'pagos'           => [['metodo_de_pago_id' => 1]],
        ]);

        $this->assertFalse($sin_datos['ok']);
        $this->assertNotEmpty($sin_datos['faltan']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Con los datos, sí: y el cheque NO lleva caja.
        $con_datos = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 5000,
            'pagos'           => [[
                'metodo_de_pago_id' => 1,
                'cheque'            => [
                    'numero'     => '12345678',
                    'banco'      => 'Banco Nación',
                    'fecha_pago' => Carbon::today()->addDays(30)->format('Y-m-d'),
                ],
            ]],
        ]);

        $this->assertTrue($con_datos['ok'], json_encode($con_datos));

        // Y en las opciones de carga ya no viaja como no usable.
        $opciones = $this->herramienta($conversation, $assistant, 'consultar_opciones_de_carga', []);

        $por_id = [];
        foreach ($opciones['metodos_de_pago'] as $fila) {
            $por_id[$fila['id']] = $fila;
        }

        $this->assertTrue($por_id[1]['se_puede_usar']);
        $this->assertArrayNotHasKey('motivo', $por_id[1]);
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

    /**
     * 🔴 La caja se cierra DESPUÉS de proponer la tarjeta y antes del clic (el cajero cerró el turno).
     * Tiene apertura previa, así que el chequeo de aperturas la dejaría pasar y el movimiento se
     * colgaría de una apertura ya cerrada, descuadrando ese arqueo. Se revalida contra el mismo
     * desplegable que se ofreció (cajas_ofrecibles) y el 422 nombra las que quedan.
     *
     * @test
     */
    public function una_caja_que_salio_de_las_ofrecibles_da_422_al_confirmar()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        // La otra caja del fixture queda abierta para que el 422 tenga qué ofrecer.
        $otra = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_MP);
        $this->asegurar_caja_abierta($otra);

        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 900,
            'pagos'           => [$this->pago($metodo, $caja)],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        // Y acá el cajero cierra la caja.
        Caja::where('id', $caja->id)->update(['abierta' => 0]);

        $gastos_antes = Expense::where('user_id', $this->dueno->id)->count();
        $movimientos_antes = $this->max_id_movimiento_caja();

        $confirmar = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');

        $confirmar->assertStatus(422);

        $motivo = $confirmar->json('model.error_mensaje');

        $this->assertStringContainsString(TestingFerreteriaSeeder::CAJA_EFECTIVO, $motivo);
        $this->assertStringContainsString('ya no está disponible', $motivo);
        $this->assertStringContainsString(TestingFerreteriaSeeder::CAJA_MP, $motivo, 'El 422 tiene que decir qué cajas quedan.');
        $this->assertEquals('propuesta', $confirmar->json('model.estado'));

        $this->assertEquals($gastos_antes, Expense::where('user_id', $this->dueno->id)->count(), 'No se puede haber escrito el gasto.');
        $this->assertEquals(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count());
    }

    /**
     * La caja se borra entre la propuesta y el clic: corta antes de escribir.
     *
     * @test
     */
    public function una_caja_borrada_entre_la_propuesta_y_el_clic_da_422()
    {
        $caja = Caja::create([
            'name'                  => 'Caja que se borra P12',
            'num'                   => (int) Caja::where('user_id', $this->dueno->id)->max('num') + 1,
            'user_id'               => $this->dueno->id,
            'abierta'               => 0,
            'saldo'                 => 0,
            'comision_iva_incluido' => 0,
        ]);

        // Con apertura real, para que lo único que falle sea que la caja ya no existe.
        $this->asegurar_caja_abierta($caja);

        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 800,
            'pagos'           => [$this->pago($metodo, $caja)],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        Caja::where('id', $caja->id)->delete();

        $gastos_antes = Expense::where('user_id', $this->dueno->id)->count();

        $confirmar = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');

        $confirmar->assertStatus(422);
        $this->assertStringContainsString('ya no existe', $confirmar->json('model.error_mensaje'));
        $this->assertEquals('propuesta', $confirmar->json('model.estado'));
        $this->assertEquals($gastos_antes, Expense::where('user_id', $this->dueno->id)->count());
    }

    /**
     * El acceso se revoca entre la propuesta y el clic: la tarjeta NO se ejecuta, y eso se
     * revalida con la persona autenticada de ESE request, no con la que propuso.
     *
     * 🔴 QUÉ CAMBIÓ ACÁ Y POR QUÉ. Hasta la misión agente-ia-mano-derecha el corte lo daba
     * PermisosIaHelper adentro del ejecutor y salía como 422 con el motivo guardado en la tarjeta.
     * Desde que el chat es SOLO del dueño (decisión de Lucas del 16/9/2026), sacarle admin_access
     * al encargado lo deja afuera una puerta ANTES: el middleware solo_el_dueno_ia le contesta 403
     * y el request no llega al ejecutor. Es el mismo defecto atajado antes y más arriba.
     *
     * Lo que este test siempre quiso probar —que el permiso se revalida en el clic y que el gasto
     * NO se carga— se sigue probando igual, y esa última aserción es la que importa. PermisosIaHelper
     * no se borró: queda de segunda defensa para el día que el gate se afloje, y sus caminos propios
     * los mide 13_Acciones_pago_Test por el servicio, sin pasar por HTTP.
     *
     * @test
     */
    public function un_acceso_revocado_entre_la_propuesta_y_el_clic_no_ejecuta_la_tarjeta()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        // Un encargado con acceso de administrador: can() le da true a todo (es_admin).
        $encargado = User::create([
            'name'         => 'Encargado P12',
            'email'        => 'acciones-p12-encargado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->dueno->id,
            'admin_access' => 1,
        ]);

        $this->actingAs($encargado, 'web');

        list($conversation, $assistant) = $this->conversacion($encargado);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 1100,
            'pagos'           => [$this->pago($metodo, $caja)],
        ]);

        $this->assertTrue($respuesta['ok'], 'Con admin_access tiene que poder proponer: ' . json_encode($respuesta));

        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        // Y acá el dueño le saca el acceso.
        User::where('id', $encargado->id)->update(['admin_access' => 0]);

        $gastos_antes = Expense::where('user_id', $this->dueno->id)->count();

        $confirmar = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');

        $confirmar->assertStatus(403);
        $this->assertEquals('Solo el dueño puede usar el asistente de IA.', $confirmar->json('message'));

        // 🔴 La aserción que no se mueve pase lo que pase: sin acceso, el gasto NO se carga.
        $this->assertEquals($gastos_antes, Expense::where('user_id', $this->dueno->id)->count());

        // Y la tarjeta queda intacta, esperando: nadie la ejecutó ni la cerró.
        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertEquals('propuesta', $tarjeta->estado);
    }

    /**
     * 🔴 UN GASTO REPARTIDO EN DOS MÉTODOS DE PAGO. Es el caso de plata que ningún test ejercía: la
     * suma de las filas contra el total, la caja resuelta POR FILA, los dos renglones "Pago" de la
     * tarjeta y los dos movimientos de caja, uno en cada caja. Si el reparto se rompe, la plata
     * termina en una caja que no es.
     *
     * @test
     */
    public function un_gasto_repartido_en_dos_metodos_mueve_las_dos_cajas_y_suma_las_filas()
    {
        $caja_efectivo = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja_efectivo);
        $caja_mp = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_MP);
        $this->asegurar_caja_abierta($caja_mp);

        $efectivo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $transferencia = CurrentAcountPaymentMethod::where('name', 'Transferencia')->first();
        $this->assertNotNull($transferencia, 'El catálogo del fixture tiene que traer Transferencia.');

        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 8000,
            'pagos'           => [
                $this->pago($efectivo, $caja_efectivo, 5000),
                $this->pago($transferencia, $caja_mp, 3000),
            ],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $pagos = [];
        foreach ($accion->presentacion['renglones'] as $renglon) {
            if ($renglon['etiqueta'] === 'Pago') {
                $pagos[] = $renglon['valor'];
            }
        }

        $this->assertCount(2, $pagos, 'La tarjeta tiene que mostrar las dos formas de pago.');
        $this->assertStringContainsString($caja_efectivo->name, $pagos[0]);
        $this->assertStringContainsString($caja_mp->name, $pagos[1]);
        $this->assertStringContainsString('5.000', $pagos[0]);
        $this->assertStringContainsString('3.000', $pagos[1]);

        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $movimientos_antes = $this->max_id_movimiento_caja();

        $confirmar = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $accion->id . '/confirmar');

        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $confirmar->assertStatus(200);

        $gasto = Expense::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();

        $this->gastos_creados_por_escenarios[] = $gasto->id;

        $this->assertEqualsWithDelta(8000, (float) $gasto->amount, self::DELTA, 'El gasto vale la suma de las filas.');

        $gasto->load('current_acount_payment_methods');
        $this->assertCount(2, $gasto->current_acount_payment_methods);

        $por_caja = [];
        foreach ($gasto->current_acount_payment_methods as $metodo) {
            $por_caja[(int) $metodo->pivot->caja_id] = (float) $metodo->pivot->amount;
        }

        $this->assertEqualsWithDelta(5000, $por_caja[$caja_efectivo->id], self::DELTA);
        $this->assertEqualsWithDelta(3000, $por_caja[$caja_mp->id], self::DELTA);

        $movimientos = MovimientoCaja::where('expense_id', $gasto->id)->get();

        $this->assertCount(2, $movimientos, 'Cada fila con caja deja su movimiento.');

        $egresos = [];
        foreach ($movimientos as $movimiento) {
            $egresos[(int) $movimiento->caja_id] = (float) $movimiento->egreso;
        }

        $this->assertEqualsWithDelta(5000, $egresos[$caja_efectivo->id], self::DELTA);
        $this->assertEqualsWithDelta(3000, $egresos[$caja_mp->id], self::DELTA);
    }

    /**
     * 🔴 La tarjeta escribe la fecha CON EL NOMBRE DEL DÍA, y este test lo asierta. Sin él, el defecto
     * que originó la misión —el asistente contestó "viernes 19/09/2026" siendo el 15/09 martes— podría
     * volver por un corrimiento de FormatoIaHelper::DIAS y toda la suite seguiría verde: ninguna otra
     * aserción de tarjeta mira el día de la semana.
     *
     * @test
     */
    public function la_fecha_de_la_tarjeta_lleva_el_nombre_del_dia_de_la_semana()
    {
        $this->fijar_reloj_en(Carbon::parse('2026-09-18 10:00:00'));

        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 1000,
            'pagos'           => [$this->pago($metodo, $caja)],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals('viernes 18/09/2026', $this->renglon($accion->presentacion, 'Fecha'));
    }

    /**
     * 🔴 Los params de la ruta tienen que viajar como objeto vacío y no como lista: la SPA los pasa a
     * router.push. Se asierta sobre el contenido CRUDO porque el json() de TestResponse convierte las
     * dos formas al mismo array de PHP y no las distingue.
     *
     * @test
     */
    public function los_params_de_la_ruta_viajan_como_objeto_vacio_y_no_como_lista()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_gasto', [
            'subcategoria_id' => $concepto->id,
            'monto'           => 2500,
            'pagos'           => [$this->pago($metodo, $caja)],
        ]);

        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $movimientos_antes = $this->max_id_movimiento_caja();

        $confirmar = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');

        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $confirmar->assertStatus(200);

        $gasto = Expense::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();
        $this->gastos_creados_por_escenarios[] = $gasto->id;

        $this->assertStringContainsString('"params":{}', $confirmar->getContent(), 'Con params como lista la SPA recibiría una lista vacía en vez de un objeto.');
    }

    /**
     * Las dos lecturas nuevas que el asistente usa para no inventar ids: las subcategorías (que en la
     * pantalla se llaman "Sub categoría") y los proveedores con las cuentas que muestra la pantalla.
     * Ningún otro test las llamaba.
     *
     * @test
     */
    public function las_lecturas_de_subcategorias_y_proveedores_devuelven_los_ids_que_piden_las_propuestas()
    {
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        list($conversation, $assistant) = $this->conversacion();

        $subcategorias = $this->herramienta($conversation, $assistant, 'consultar_subcategorias_de_gasto', [
            'busqueda' => mb_substr(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 0, 5),
        ]);

        $ids = [];
        foreach ($subcategorias as $fila) {
            $ids[] = (int) $fila['id'];
        }

        $this->assertContains((int) $concepto->id, $ids, 'La subcategoría del fixture tiene que aparecer con su id.');

        $proveedores = $this->herramienta($conversation, $assistant, 'consultar_proveedores', [
            'busqueda' => TestingFerreteriaSeeder::PROVIDER_BSAS,
        ]);

        $this->assertNotEmpty($proveedores, 'El proveedor del fixture tiene que aparecer.');
        $this->assertArrayHasKey('id', $proveedores[0]);
        $this->assertArrayHasKey('cuentas', $proveedores[0]);
        $this->assertCount(1, $proveedores[0]['cuentas'], 'Sin ventas_en_dolares va una sola cuenta, como en BtnCurrentAcounts::show.');
    }

    /**
     * 🔴 TODA HERRAMIENTA DECLARADA TIENE QUE ESTAR DESPACHADA, y acá se verifica EJECUTÁNDOLAS. El
     * test que compara el texto "case 'nombre':" del archivo pasa igual si ese case despacha al
     * helper equivocado o si está adentro de un comentario (hallazgo del chequeo del contrato). Con
     * input vacío ninguna propuesta escribe nada: devuelven "faltan" o "error", y lo único que este
     * test mira es que NO vuelva "Tool desconocida".
     *
     * @test
     */
    public function toda_herramienta_declarada_responde_algo_que_no_sea_tool_desconocida()
    {
        list($conversation, $assistant) = $this->conversacion();

        $definiciones = \App\Services\AsistenteIa\HerramientasDeCarga::definiciones();

        $this->assertNotEmpty($definiciones, 'Sin definiciones este test no prueba nada.');

        foreach ($definiciones as $definicion) {

            $resultados = $this->service->execute_tool_calls([[
                'type'  => 'tool_use',
                'id'    => 'toolu_' . uniqid(),
                'name'  => $definicion['name'],
                'input' => [],
            ]], $conversation, $assistant);

            $this->assertCount(1, $resultados, 'La herramienta ' . $definicion['name'] . ' no devolvió ningún tool_result.');
            $this->assertStringNotContainsString(
                'Tool desconocida',
                $resultados[0]['content'],
                'La herramienta ' . $definicion['name'] . ' está declarada y no está despachada.'
            );
        }
    }
}
