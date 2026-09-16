<?php

namespace Tests\Feature\CurrentAcount;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Caja;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\MovimientoCaja;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-ia-acciones — el MISMO cobro, por la pantalla y por el asistente, deja las mismas
 * filas.
 *
 * Es el test que sostiene la extracción de CurrentAcountController::pago() a
 * CurrentAcountPagoAltaHelper::registrar(): los dos caminos pasan por el mismo helper, así que lo que
 * se compara acá es que el asistente no arme un payload distinto del que manda el modal de Pago. Si
 * mañana alguien "simplifica" las filas de pago del asistente (menos claves, otra moneda, sin
 * cotización), este test se pone rojo.
 *
 * 🔴 El payload del camino de la pantalla está COPIADO del que manda la SPA
 * (components/common/current-acounts/pago/Index.vue::hacerPago() y el payment_method_factory de
 * PaymentMethods.vue), no escrito mirando el helper: testear el back contra un payload que el front
 * nunca manda es una clase de error conocida.
 *
 * @group current-acount
 */
class Pago_por_helper_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta para comparar montos. */
    const DELTA = 0.01;

    /** Monto de la deuda que se siembra en cada cuenta. */
    const DEUDA = 10000;

    /** Monto del cobro que se compara. */
    const COBRO = 4000;

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
     * Un cliente del dueño con su cuenta corriente en pesos y una deuda sembrada por el endpoint
     * real de nota de débito.
     *
     * @param string $nombre
     * @return array{0: Client, 1: CreditAccount, 2: CurrentAcount}
     */
    protected function cliente_con_deuda($nombre)
    {
        $cliente = Client::create([
            'num'     => (int) Client::where('user_id', $this->dueno->id)->max('num') + 1,
            'name'    => $nombre,
            'user_id' => $this->dueno->id,
        ]);

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $this->dueno->id);

        $cuenta = CreditAccount::where('model_name', 'client')
                                ->where('model_id', $cliente->id)
                                ->where('moneda_id', 1)
                                ->first();

        $response = $this->postJson('api/current-acount/nota-debito', [
            'credit_account_id' => $cuenta->id,
            'model_name'        => 'client',
            'model_id'          => $cliente->id,
            'debe'              => self::DEUDA,
            'description'       => 'Deuda del test de paridad',
        ]);

        $response->assertStatus(201);

        $debito = CurrentAcount::find($response->json('current_acount.id'));

        $this->cobros_cc_creados_por_escenarios[] = $debito->id;

        return [$cliente, $cuenta, $debito];
    }

    /**
     * Payload de `POST api/current-acount/pago` tal como lo manda la SPA: el objeto `pago` del modal
     * más las claves que agrega hacerPago(), con la fila del payment_method_factory.
     *
     * @param Client $cliente
     * @param CreditAccount $cuenta
     * @param Caja $caja
     * @param int $metodo_id
     * @return array
     */
    protected function payload_de_la_pantalla($cliente, $cuenta, $caja, $metodo_id)
    {
        return [
            'credit_account_id'              => $cuenta->id,
            'model_name'                     => 'client',
            'model_id'                       => $cliente->id,
            'current_date'                   => true,
            'description'                    => 'Cobro de prueba de paridad',
            'created_at'                     => '',
            'haber'                          => self::COBRO,
            'is_provisorio'                  => 0,
            'current_acount_payment_methods' => [
                [
                    '__row_id'                         => '1789500000000_abc123',
                    'current_acount_payment_method_id' => $metodo_id,
                    'amount'                           => self::COBRO,
                    'bank'                             => '',
                    'payment_date'                     => '',
                    'num'                              => '',
                    'credit_card_id'                   => 0,
                    'credit_card_payment_plan_id'      => 0,
                    'caja_id'                          => $caja->id,
                    'moneda_id'                        => 1,
                    'cotizacion'                       => 0,
                    'amount_cotizado'                  => '',
                    'numero'                           => '',
                    'banco'                            => '',
                    'fecha_emision'                    => '',
                    'fecha_pago'                       => '',
                    'es_echeq'                         => 0,
                    'cuota_id'                         => 0,
                ],
            ],
            'to_pay'                         => null,
            'payment_plan_cuota'             => null,
        ];
    }

    /**
     * Cobra por el asistente: propone la tarjeta y la confirma por el endpoint.
     *
     * @param Client $cliente
     * @param Caja $caja
     * @param int $metodo_id
     * @return int  Id del pago creado.
     */
    protected function cobrar_por_el_asistente($cliente, $caja, $metodo_id)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Cobrale ' . self::COBRO . ' a ' . $cliente->name,
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_paridad',
            'name'  => 'proponer_pago',
            'input' => [
                'tipo'        => 'cliente',
                'id'          => $cliente->id,
                'monto'       => self::COBRO,
                'pagos'       => [['metodo_de_pago_id' => $metodo_id, 'caja_id' => $caja->id]],
                'descripcion' => 'Cobro de prueba de paridad',
            ],
        ]], $conversation, $assistant);

        $respuesta = json_decode($resultados[0]['content'], true);

        $this->assertTrue($respuesta['ok'], 'El asistente no pudo armar la tarjeta: ' . $resultados[0]['content']);

        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $confirmar = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');

        $confirmar->assertStatus(200);

        $pago = CurrentAcount::where('client_id', $cliente->id)->whereNotNull('haber')->orderBy('id', 'DESC')->first();

        $this->assertNotNull($pago);

        return $pago->id;
    }

    /**
     * Todo lo observable que dejó un cobro: la fila del pago, su desglose, su imputación, el
     * movimiento de caja y el saldo de la cuenta. Sin ids ni fechas, que son propios de cada corrida.
     *
     * @param int $pago_id
     * @param CreditAccount $cuenta
     * @param CurrentAcount $debito
     * @param int $movimientos_desde  Marca de agua de movimiento_cajas.
     * @return array
     */
    protected function radiografia($pago_id, $cuenta, $debito, $movimientos_desde)
    {
        $pago = CurrentAcount::find($pago_id);
        $pago->load('current_acount_payment_methods');

        $metodos = [];

        foreach ($pago->current_acount_payment_methods as $metodo) {
            $metodos[] = [
                'metodo_de_pago'  => (int) $metodo->id,
                'amount'          => round((float) $metodo->pivot->amount, 2),
                'caja_id'         => (int) $metodo->pivot->caja_id,
                'moneda_id'       => (int) $metodo->pivot->moneda_id,
                'amount_cotizado' => round((float) $metodo->pivot->amount_cotizado, 2),
                'cotizacion'      => round((float) $metodo->pivot->cotizacion, 2),
            ];
        }

        $imputaciones = [];

        foreach (DB::table('pagado_por')->where('haber_id', $pago->id)->orderBy('debe_id')->get() as $fila) {
            $imputaciones[] = [
                'pagado'     => round((float) $fila->pagado, 2),
                'total_pago' => round((float) $fila->total_pago, 2),
                'a_cubrir'   => round((float) $fila->a_cubrir, 2),
                'remantente' => round((float) $fila->remantente, 2),
                'es_el_debito_sembrado' => (int) $fila->debe_id === (int) $debito->id,
            ];
        }

        $movimientos = [];

        foreach (MovimientoCaja::where('id', '>', $movimientos_desde)->orderBy('id')->get() as $movimiento) {
            $movimientos[] = [
                'ingreso'                     => is_null($movimiento->ingreso) ? null : round((float) $movimiento->ingreso, 2),
                'egreso'                      => is_null($movimiento->egreso) ? null : round((float) $movimiento->egreso, 2),
                'caja_id'                     => (int) $movimiento->caja_id,
                'concepto_movimiento_caja_id' => (int) $movimiento->concepto_movimiento_caja_id,
            ];
        }

        $debito = $debito->fresh();

        return [
            'haber'                   => round((float) $pago->haber, 2),
            'saldo'                   => round((float) $pago->saldo, 2),
            'status'                  => $pago->status,
            'detalle_con_su_numero'   => $pago->detalle === 'Pago N°' . $pago->num_receipt,
            'description'             => $pago->description,
            'is_provisorio'           => (int) $pago->is_provisorio,
            'tiene_client_id'         => !is_null($pago->client_id),
            'tiene_provider_id'       => !is_null($pago->provider_id),
            'employee_id'             => (int) $pago->employee_id,
            'user_id'                 => (int) $pago->user_id,
            'to_pay_id'               => $pago->to_pay_id,
            'fechado_hoy'             => Carbon::parse($pago->created_at)->format('Y-m-d') === Carbon::today()->format('Y-m-d'),
            'metodos'                 => $metodos,
            'imputaciones'            => $imputaciones,
            'movimientos_de_caja'     => $movimientos,
            'debito_status'           => $debito->status,
            'debito_pagandose'        => round((float) $debito->pagandose, 2),
            'debito_saldo'            => round((float) $debito->saldo, 2),
            'saldo_de_la_cuenta'      => round((float) CreditAccount::find($cuenta->id)->saldo, 2),
        ];
    }

    /**
     * @test
     */
    public function el_mismo_cobro_por_la_pantalla_y_por_el_asistente_deja_las_mismas_filas()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        // Dos clientes iguales, cada uno con su deuda: los dos caminos arrancan del mismo estado.
        list($cliente_pantalla, $cuenta_pantalla, $debito_pantalla) = $this->cliente_con_deuda('Paridad pantalla ' . uniqid());
        list($cliente_asistente, $cuenta_asistente, $debito_asistente) = $this->cliente_con_deuda('Paridad asistente ' . uniqid());

        // --- Camino de la pantalla -------------------------------------------------------------
        $movimientos_antes_pantalla = $this->max_id_movimiento_caja();

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_la_pantalla($cliente_pantalla, $cuenta_pantalla, $caja, $metodo->id));

        $response->assertStatus(201);

        $pago_pantalla_id = (int) $response->json('current_acount.id');
        $this->cobros_cc_creados_por_escenarios[] = $pago_pantalla_id;
        $this->registrar_movimientos_caja_nuevos($movimientos_antes_pantalla);

        $radiografia_pantalla = $this->radiografia($pago_pantalla_id, $cuenta_pantalla, $debito_pantalla, $movimientos_antes_pantalla);

        // --- Camino del asistente --------------------------------------------------------------
        $movimientos_antes_asistente = $this->max_id_movimiento_caja();

        $pago_asistente_id = $this->cobrar_por_el_asistente($cliente_asistente, $caja, $metodo->id);

        $this->cobros_cc_creados_por_escenarios[] = $pago_asistente_id;
        $this->registrar_movimientos_caja_nuevos($movimientos_antes_asistente);

        $radiografia_asistente = $this->radiografia($pago_asistente_id, $cuenta_asistente, $debito_asistente, $movimientos_antes_asistente);

        // --- Lo mismo, fila por fila -----------------------------------------------------------
        $this->assertEquals(
            $radiografia_pantalla,
            $radiografia_asistente,
            'El cobro del asistente tiene que dejar exactamente lo mismo que el de la pantalla.'
        );

        // Y un par de valores concretos, para que el test no pase con las dos radiografías vacías.
        $this->assertEqualsWithDelta(self::COBRO, $radiografia_asistente['haber'], self::DELTA);
        $this->assertEquals('pago_from_client', $radiografia_asistente['status']);
        $this->assertTrue($radiografia_asistente['detalle_con_su_numero']);
        $this->assertEqualsWithDelta(self::DEUDA - self::COBRO, $radiografia_asistente['saldo_de_la_cuenta'], self::DELTA);
        $this->assertCount(1, $radiografia_asistente['metodos']);
        $this->assertEquals($caja->id, $radiografia_asistente['metodos'][0]['caja_id']);
        $this->assertCount(1, $radiografia_asistente['imputaciones']);
        $this->assertTrue($radiografia_asistente['imputaciones'][0]['es_el_debito_sembrado']);
        $this->assertCount(1, $radiografia_asistente['movimientos_de_caja']);
        $this->assertEqualsWithDelta(self::COBRO, $radiografia_asistente['movimientos_de_caja'][0]['ingreso'], self::DELTA);

        // El correlativo del recibo es el mismo contador para los dos caminos.
        $this->assertEquals(
            (int) CurrentAcount::find($pago_pantalla_id)->num_receipt + 1,
            (int) CurrentAcount::find($pago_asistente_id)->num_receipt
        );
    }

    /**
     * Los dos caminos frenan ANTES de escribir cuando una caja destino nunca se abrió, con el mismo
     * criterio (CurrentAcountCajaHelper::cajas_sin_apertura_en_payload) y nombrando la caja.
     *
     * @test
     */
    public function los_dos_caminos_frenan_igual_con_una_caja_que_nunca_se_abrio()
    {
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        $caja_sin_apertura = Caja::create([
            'name'                  => 'Caja sin apertura paridad',
            'num'                   => (int) Caja::where('user_id', $this->dueno->id)->max('num') + 1,
            'user_id'               => $this->dueno->id,
            'abierta'               => 1,
            'saldo'                 => 0,
            'comision_iva_incluido' => 0,
        ]);

        list($cliente, $cuenta, $debito) = $this->cliente_con_deuda('Paridad sin apertura ' . uniqid());

        $pagos_antes = CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotNull('haber')->count();
        $movimientos_antes = $this->max_id_movimiento_caja();

        // Pantalla: 422 con el nombre de la caja.
        $response = $this->postJson('api/current-acount/pago', $this->payload_de_la_pantalla($cliente, $cuenta, $caja_sin_apertura, $metodo->id));

        $response->assertStatus(422);
        $this->assertStringContainsString('Caja sin apertura paridad', $response->json('message'));
        $this->assertStringContainsString('registrar el pago', $response->json('message'));

        // Asistente: la tarjeta se puede armar (el desplegable ofrece la caja porque está "abierta"),
        // y el 422 llega al confirmar, con el mismo texto guardado en la tarjeta.
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Cobrale a ' . $cliente->name,
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'listo',
            'contenido'            => 'Te dejé la tarjeta para confirmar.',
            'acciones_habilitadas' => true,
        ]);

        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_paridad_sin_apertura',
            'name'  => 'proponer_pago',
            'input' => [
                'tipo'  => 'cliente',
                'id'    => $cliente->id,
                'monto' => self::COBRO,
                'pagos' => [['metodo_de_pago_id' => $metodo->id, 'caja_id' => $caja_sin_apertura->id]],
            ],
        ]], $conversation, $assistant);

        $respuesta = json_decode($resultados[0]['content'], true);

        $this->assertTrue($respuesta['ok'], $resultados[0]['content']);

        $confirmar = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');

        $confirmar->assertStatus(422);
        $this->assertStringContainsString('Caja sin apertura paridad', $confirmar->json('model.error_mensaje'));
        $this->assertStringContainsString('registrar el pago', $confirmar->json('model.error_mensaje'));

        // Ninguno de los dos escribió nada.
        $this->assertEquals($pagos_antes, CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotNull('haber')->count());
        $this->assertEquals(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count());
        $this->assertEquals('sin_pagar', $debito->fresh()->status);

        Caja::where('id', $caja_sin_apertura->id)->delete();
    }
}
