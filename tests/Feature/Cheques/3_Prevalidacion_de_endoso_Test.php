<?php

namespace Tests\Feature\Cheques;

use App\Models\Cheque;
use App\Models\CurrentAcount;
use App\Models\Expense;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * Misión cheques-endoso-y-bancos — la prevalidación del endoso: un cheque que no se puede endosar
 * corta con 422 ANTES de escribir nada (ni pago, ni gasto, ni cheque nuevo, ni marca en el
 * recibido), con el motivo en lenguaje de comerciante. Y `GET cheque/disponibles-para-endosar`
 * no ofrece ninguno de esos.
 *
 * @group cheques
 */
class Prevalidacion_de_endoso_Test extends ChequesTestCase
{
    /**
     * Manda el pago a proveedor con esas filas, exige 422 con el texto, y verifica que no se
     * escribió nada.
     *
     * @param array $filas
     * @param string $texto_esperado
     * @param Cheque|null $recibido El cheque que no tenía que cambiar.
     * @return void
     */
    protected function pago_rechazado(array $filas, $texto_esperado, $recibido = null)
    {
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor prevalidación ' . uniqid(), self::DEUDA_PROVEEDOR);

        $cheques_antes = Cheque::count();
        $pagos_antes = CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotNull('haber')->count();
        $antes = is_null($recibido) ? null : $recibido->fresh()->toArray();

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta, $filas));

        $this->assertEquals(422, $response->getStatusCode(), 'Tenía que ser 422 y fue ' . $response->getStatusCode() . ': ' . $response->getContent());
        $this->assertStringContainsString($texto_esperado, $response->json('message'));

        $this->assertEquals($cheques_antes, Cheque::count(), 'No tenía que nacer ningún cheque.');
        $this->assertEquals($pagos_antes, CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotNull('haber')->count(), 'No tenía que registrarse ningún pago.');
        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR, (float) $cuenta->fresh()->saldo, self::DELTA);

        if (!is_null($recibido)) {
            $this->assertEquals($antes, $recibido->fresh()->toArray(), 'El cheque recibido no tenía que cambiar.');
        }
    }

    /**
     * @test
     */
    public function un_cheque_ya_endosado_no_se_endosa_de_nuevo()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente ya endosado ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '5001']);

        // Primer endoso, por el camino normal.
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor primero ' . uniqid(), self::DEUDA_PROVEEDOR);
        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta, [$this->fila_de_pago($this->claves_de_endoso($recibido))]));
        $response->assertStatus(201);
        $this->cobros_cc_creados_por_escenarios[] = (int) $response->json('current_acount.id');

        $this->pago_rechazado([$this->fila_de_pago($this->claves_de_endoso($recibido))], 'El cheque N° 5001 ya fue endosado', $recibido);
        $this->assertCount(1, $this->copias_de($recibido));
    }

    /**
     * @test
     */
    public function un_cheque_endosado_en_un_gasto_tampoco_se_endosa_a_un_proveedor()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente endosado en gasto ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '5002']);

        $response = $this->postJson('api/expense', $this->payload_de_gasto([$this->fila_de_gasto($this->claves_de_endoso($recibido))]));
        $response->assertStatus(201);
        $this->gastos_creados_por_escenarios[] = (int) $response->json('model.id');

        $this->pago_rechazado([$this->fila_de_pago($this->claves_de_endoso($recibido))], 'El cheque N° 5002 ya fue endosado', $recibido);
    }

    /**
     * @test
     */
    public function un_cheque_cobrado_o_rechazado_no_se_endosa()
    {
        $cobrado = $this->cheque_a_mano(['numero' => '5003', 'estado_manual' => 'cobrado', 'cobrado_en' => Carbon::now()]);
        $rechazado = $this->cheque_a_mano(['numero' => '5004', 'estado_manual' => 'rechazado', 'rechazado_en' => Carbon::now()]);

        $this->pago_rechazado([$this->fila_de_pago($this->claves_de_endoso($cobrado))], 'El cheque N° 5003 ya fue cobrado', $cobrado);
        $this->pago_rechazado([$this->fila_de_pago($this->claves_de_endoso($rechazado))], 'El cheque N° 5004 fue rechazado', $rechazado);
    }

    /**
     * @test
     */
    public function un_cheque_vencido_no_se_endosa()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente vencido ' . uniqid());

        // 31 días después de la fecha de pago ya pasó el plazo de depósito.
        $vencido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, [
            'numero'        => '5005',
            'fecha_emision' => Carbon::today()->subDays(40)->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->subDays(31)->format('Y-m-d'),
        ]);

        $this->assertEquals('recibido.vencidos', $this->solapa_de($vencido->id));

        $this->pago_rechazado([$this->fila_de_pago($this->claves_de_endoso($vencido))], 'El cheque N° 5005 está vencido', $vencido);
    }

    /**
     * @test
     */
    public function un_cheque_se_endosa_entero_o_no_se_endosa()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente monto ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '5006']);

        $fila = $this->fila_de_pago($this->claves_de_endoso($recibido));
        $fila['amount'] = self::MONTO_CHEQUE - 5000;

        $this->pago_rechazado([$fila], 'El cheque N° 5006 es de $ 45.000: un cheque se endosa entero', $recibido);
    }

    /**
     * @test
     */
    public function el_mismo_cheque_no_puede_ir_en_dos_filas()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente dos filas ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '5007']);

        $this->pago_rechazado(
            [$this->fila_de_pago($this->claves_de_endoso($recibido)), $this->fila_de_pago($this->claves_de_endoso($recibido))],
            'El cheque N° 5007 está elegido en dos filas',
            $recibido
        );
    }

    /**
     * @test
     */
    public function un_cheque_de_otro_dueno_o_inexistente_no_se_endosa()
    {
        $otro_dueno = User::create([
            'name'     => 'Otro comercio cheques',
            'email'    => 'cheques-otro-dueno-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $ajeno = $this->cheque_a_mano(['numero' => '5008', 'user_id' => $otro_dueno->id]);

        $this->pago_rechazado([$this->fila_de_pago($this->claves_de_endoso($ajeno))], 'El cheque elegido para endosar no existe o no es de tu cuenta', $ajeno);

        $fila = $this->fila_de_pago(['cheque_id' => 999999999]);

        $this->pago_rechazado([$fila], 'El cheque elegido para endosar no existe o no es de tu cuenta');
    }

    /**
     * @test
     */
    public function un_cheque_emitido_no_se_endosa()
    {
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor del emitido ' . uniqid());

        $emitido = $this->cheque_a_mano(['numero' => '5009', 'tipo' => 'emitido', 'provider_id' => $proveedor->id]);

        $this->pago_rechazado([$this->fila_de_pago($this->claves_de_endoso($emitido))], 'El cheque N° 5009 no es un cheque recibido', $emitido);
    }

    /**
     * Un `cheque_id` en una fila que no es de tipo cheque (Efectivo) se rechaza: attach solo crea
     * cheques para ese tipo, así que la fila se habría registrado como efectivo con el cheque
     * todavía en cartera.
     *
     * @test
     */
    public function un_cheque_en_una_fila_que_no_es_de_tipo_cheque_se_rechaza()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente fila efectivo ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '5010']);

        $efectivo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        $fila = $this->fila_de_pago($this->claves_de_endoso($recibido));
        $fila['current_acount_payment_method_id'] = $efectivo->id;

        $this->pago_rechazado([$fila], 'El cheque N° 5010 está en una fila que no es de tipo cheque', $recibido);
    }

    /**
     * Un cobro a un CLIENTE con `cheque_id` no es un endoso: el cheque no cambia de manos.
     *
     * @test
     */
    public function un_cobro_a_cliente_con_un_cheque_elegido_se_rechaza()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente cobro con endoso ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '5011']);

        list($otro_cliente, $otra_cuenta) = $this->cliente_con_cuenta('Otro cliente ' . uniqid());

        $cheques_antes = Cheque::count();
        $antes = $recibido->fresh()->toArray();

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('client', $otro_cliente->id, $otra_cuenta, [$this->fila_de_pago($this->claves_de_endoso($recibido))]));

        $response->assertStatus(422);
        $this->assertStringContainsString('no en un cobro a un cliente', $response->json('message'));
        $this->assertEquals($cheques_antes, Cheque::count());
        $this->assertEquals($antes, $recibido->fresh()->toArray());
        $this->assertEquals(0, CurrentAcount::where('credit_account_id', $otra_cuenta->id)->whereNotNull('haber')->count());
    }

    /**
     * El gasto frena con el mismo criterio y sin escribir nada.
     *
     * @test
     */
    public function el_gasto_tambien_frena_antes_de_escribir()
    {
        $cobrado = $this->cheque_a_mano(['numero' => '5012', 'estado_manual' => 'cobrado', 'cobrado_en' => Carbon::now()]);

        $cheques_antes = Cheque::count();
        $gastos_antes = Expense::where('user_id', $this->dueno->id)->count();
        $antes = $cobrado->fresh()->toArray();

        $response = $this->postJson('api/expense', $this->payload_de_gasto([$this->fila_de_gasto($this->claves_de_endoso($cobrado))]));

        $response->assertStatus(422);
        $this->assertStringContainsString('El cheque N° 5012 ya fue cobrado', $response->json('message'));
        $this->assertEquals($cheques_antes, Cheque::count());
        $this->assertEquals($gastos_antes, Expense::where('user_id', $this->dueno->id)->count());
        $this->assertEquals($antes, $cobrado->fresh()->toArray());
    }

    /**
     * Varios problemas en un mismo payload se informan todos juntos.
     *
     * @test
     */
    public function varios_problemas_se_informan_juntos()
    {
        $cobrado = $this->cheque_a_mano(['numero' => '5013', 'estado_manual' => 'cobrado']);
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente varios ' . uniqid());
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '5014']);

        $fila_monto = $this->fila_de_pago($this->claves_de_endoso($recibido));
        $fila_monto['amount'] = 1;

        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor varios ' . uniqid(), self::DEUDA_PROVEEDOR);

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta, [$this->fila_de_pago($this->claves_de_endoso($cobrado)), $fila_monto]));

        $response->assertStatus(422);
        $this->assertStringContainsString('El cheque N° 5013 ya fue cobrado', $response->json('message'));
        $this->assertStringContainsString('El cheque N° 5014 es de $ 45.000', $response->json('message'));
    }

    /**
     * `GET cheque/disponibles-para-endosar` ofrece solo los recibidos en cartera y no vencidos,
     * del dueño, ordenados por fecha de pago, y cada uno con las relaciones que el select muestra.
     *
     * @test
     */
    public function disponibles_para_endosar_no_lista_ninguno_de_los_que_no_se_pueden_endosar()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente disponibles ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor disponibles ' . uniqid());

        $otro_dueno = User::create([
            'name'     => 'Otro comercio disponibles',
            'email'    => 'cheques-otro-dueno-disp-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        // Los que SÍ: pendiente (fecha futura), disponible para cobrar (hoy), en el último día del
        // plazo (hace 30 días) y pronto a vencerse (hace 28).
        $pendiente     = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'D-01', 'fecha_pago' => Carbon::today()->addDays(10)->format('Y-m-d')]);
        $de_hoy        = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'D-02', 'fecha_pago' => Carbon::today()->format('Y-m-d')]);
        $ultimo_dia    = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'D-03', 'fecha_emision' => Carbon::today()->subDays(35)->format('Y-m-d'), 'fecha_pago' => Carbon::today()->subDays(30)->format('Y-m-d')]);
        $por_vencer    = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'D-04', 'fecha_emision' => Carbon::today()->subDays(35)->format('Y-m-d'), 'fecha_pago' => Carbon::today()->subDays(28)->format('Y-m-d')]);

        // Los que NO.
        $vencido       = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'N-01', 'fecha_emision' => Carbon::today()->subDays(40)->format('Y-m-d'), 'fecha_pago' => Carbon::today()->subDays(31)->format('Y-m-d')]);
        $cobrado       = $this->cheque_a_mano(['numero' => 'N-02', 'estado_manual' => 'cobrado']);
        $rechazado     = $this->cheque_a_mano(['numero' => 'N-03', 'estado_manual' => 'rechazado']);
        $a_proveedor   = $this->cheque_a_mano(['numero' => 'N-04', 'endosado_a_provider_id' => $proveedor->id, 'fecha_endoso' => Carbon::now()]);
        $en_gasto      = $this->cheque_a_mano(['numero' => 'N-05', 'endosado_en_expense_id' => 1, 'fecha_endoso' => Carbon::now()]);
        $emitido       = $this->cheque_a_mano(['numero' => 'N-06', 'tipo' => 'emitido', 'provider_id' => $proveedor->id]);
        $ajeno         = $this->cheque_a_mano(['numero' => 'N-07', 'user_id' => $otro_dueno->id]);

        $response = $this->getJson('api/cheque/disponibles-para-endosar');

        $response->assertStatus(200);

        $ids = [];
        $por_id = [];

        foreach ($response->json('models') as $model) {
            $ids[] = (int) $model['id'];
            $por_id[(int) $model['id']] = $model;
        }

        foreach ([$pendiente, $de_hoy, $ultimo_dia, $por_vencer] as $si) {
            $this->assertContains($si->id, $ids, 'Tenía que listar el cheque N° ' . $si->numero);
        }

        foreach ([$vencido, $cobrado, $rechazado, $a_proveedor, $en_gasto, $emitido, $ajeno] as $no) {
            $this->assertNotContains($no->id, $ids, 'No tenía que listar el cheque N° ' . $no->numero);
        }

        // Orden: el que vence antes, primero.
        $posiciones = array_flip($ids);
        $this->assertLessThan($posiciones[$por_vencer->id], $posiciones[$ultimo_dia->id]);
        $this->assertLessThan($posiciones[$de_hoy->id], $posiciones[$por_vencer->id]);
        $this->assertLessThan($posiciones[$pendiente->id], $posiciones[$de_hoy->id]);

        // Cada uno viene con lo que el select muestra: cliente y banco del catálogo (null acá).
        $this->assertEquals($cliente->name, $por_id[$pendiente->id]['client']['name']);
        $this->assertArrayHasKey('cheque_banco', $por_id[$pendiente->id]);
        $this->assertNull($por_id[$pendiente->id]['cheque_banco']);
        $this->assertEquals('Banco Nación', $por_id[$pendiente->id]['banco']);
    }
}
