<?php

namespace Tests\Feature\Expenses;

use App\Models\Caja;
use App\Models\Expense;
use App\Models\ExtencionEmpresa;
use App\Models\MovimientoCaja;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Un gasto repartido entre DOS metodos de pago en monedas distintas.
 *
 * El caso real que los origino (29/8/2026): un gasto en pesos pagado con efectivo en pesos a una
 * caja en pesos MAS efectivo en dolares a una caja en dolares. Si la caja en dolares nunca habia
 * tenido apertura, ExpenseController::store() se cortaba en la segunda vuelta del loop con el gasto
 * ya creado, el desglose ya adjunto y el egreso de la caja en pesos ya impactado. El usuario veia un
 * 500, reintentaba, y terminaba con el gasto duplicado.
 *
 * Es el mismo agujero que tests/Feature/CurrentAcount/2_Pago_Multimoneda_Test.php cubre para el pago
 * de cuenta corriente desde el 21/8/2026: aquel hotfix puso la prevalidacion y el warning solo en
 * CurrentAcountController, y el camino del gasto quedo como estaba.
 *
 * Los dos metodos de pago comparten el mismo `current_acount_payment_method_id` (los dos son
 * "Efectivo"), que es lo que hace particular al escenario.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni #[...].
 *
 * @group expenses
 */
class Gasto_Multimoneda_Caja_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** @var \App\Models\User */
    protected $usuario;

    /** @var \App\Models\Caja */
    protected $caja_ars;

    /** @var \App\Models\Caja */
    protected $caja_usd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        // El escenario es el de un comercio con la extencion de ventas en dolares activa.
        $extencion = ExtencionEmpresa::where('slug', 'ventas_en_dolares')->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => 'ventas_en_dolares',
                'name' => 'Ventas en dolares',
            ]);
        }

        $this->usuario->extencions()->syncWithoutDetaching([$extencion->id]);

        $this->caja_ars = $this->caja_de_moneda('Caja Pesos Gasto Test', 1);
        $this->caja_usd = $this->caja_de_moneda('Caja Dolares Gasto Test', 2);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Ayudantes
    // ------------------------------------------------------------------------------------------

    /**
     * Crea (o reusa) una caja de la moneda pedida. Si $abrir es true la deja abierta.
     *
     * @param string $name
     * @param int $moneda_id
     * @param bool $abrir
     * @return \App\Models\Caja
     */
    protected function caja_de_moneda($name, $moneda_id, $abrir = true)
    {
        $caja = Caja::where('name', $name)->first();

        if (is_null($caja)) {

            $num = (int) Caja::where('user_id', $this->usuario->id)->max('num') + 1;

            $caja = Caja::create([
                'name'                  => $name,
                'num'                   => $num,
                'moneda_id'             => $moneda_id,
                'user_id'               => $this->usuario->id,
                'saldo'                 => 0,
                'abierta'               => 0,
                'comision_iva_incluido' => 0,
            ]);
        }

        if ($abrir) {
            $this->asegurar_caja_abierta($caja);
        }

        return $caja;
    }

    /**
     * Payload de `POST api/expense` con el desglose que se le pase.
     *
     * @param array $payment_methods
     * @param float $monto
     * @return array
     */
    protected function payload_de_gasto($payment_methods, $monto = 2000)
    {
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        return [
            'expense_concept_id' => $concepto->id,
            'amount'             => $monto,
            'moneda_id'          => 1,
            'importe_iva'        => 0,
            'observations'       => 'Gasto multimoneda de test',
            'created_at'         => Carbon::now()->format('Y-m-d H:i:s'),
            'payment_methods'    => $payment_methods,
        ];
    }

    // ------------------------------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------------------------------

    /**
     * El camino feliz: las DOS cajas reciben su egreso, cada una en su moneda.
     *
     * La caja en dolares recibe 10 (dolares) y no el equivalente en pesos: el controller usa
     * `pivot->amount` crudo, que es lo correcto porque la caja ya esta denominada en esa moneda.
     *
     * @test
     */
    public function un_gasto_en_dos_monedas_impacta_en_las_dos_cajas()
    {
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        $saldo_ars_inicial = (float) $this->caja_ars->fresh()->saldo;
        $saldo_usd_inicial = (float) $this->caja_usd->fresh()->saldo;

        $gasto = $this->crear_gasto(
            TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO,
            2000,
            [
                'payment_methods' => [
                    [
                        'current_acount_payment_method_id' => $metodo->id,
                        'amount'                           => 1000,
                        'caja_id'                          => $this->caja_ars->id,
                        'moneda_id'                        => 1,
                    ],
                    [
                        'current_acount_payment_method_id' => $metodo->id,
                        'amount'                           => 10,
                        'caja_id'                          => $this->caja_usd->id,
                        'moneda_id'                        => 2,
                        'cotizacion'                       => 100,
                        'amount_cotizado'                  => 1000,
                    ],
                ],
            ]
        );

        $this->assertNotNull($gasto, 'El gasto no se creo.');

        // Las dos filas quedaron adjuntas, aunque compartan el mismo metodo de pago.
        $this->assertCount(2, $gasto->fresh()->current_acount_payment_methods,
            'El desglose tiene que tener las dos filas, una por moneda.');

        $this->assertEquals(
            $saldo_ars_inicial - 1000,
            (float) $this->caja_ars->fresh()->saldo,
            'La caja en pesos tiene que haber recibido el egreso de 1000 pesos.'
        );

        $this->assertEquals(
            $saldo_usd_inicial - 10,
            (float) $this->caja_usd->fresh()->saldo,
            'La caja en dolares tiene que haber recibido el egreso de 10 dolares, no el convertido.'
        );
    }

    /**
     * 🔴 El test del bug. Una caja destino que NUNCA tuvo apertura corta el gasto con un 422 y no
     * deja NADA escrito: ni el gasto, ni el desglose, ni el egreso de la otra caja.
     *
     * Antes del 29/8/2026 este mismo escenario devolvia 500 con el gasto ya creado y la caja en
     * pesos ya debitada, y el usuario lo duplicaba al reintentar.
     *
     * @test
     */
    public function una_caja_sin_apertura_frena_el_gasto_antes_de_escribir_nada()
    {
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        // Caja en dolares que existe pero nunca se abrio.
        $caja_sin_apertura = $this->caja_de_moneda('Caja Dolares Sin Apertura Test', 2, false);

        $gastos_antes    = Expense::where('user_id', $this->usuario->id)->count();
        $movimientos_antes = MovimientoCaja::count();
        $saldo_ars_inicial = (float) $this->caja_ars->fresh()->saldo;

        $response = $this->postJson('api/expense', $this->payload_de_gasto([
            [
                'current_acount_payment_method_id' => $metodo->id,
                'amount'                           => 1000,
                'caja_id'                          => $this->caja_ars->id,
                'moneda_id'                        => 1,
            ],
            [
                'current_acount_payment_method_id' => $metodo->id,
                'amount'                           => 10,
                'caja_id'                          => $caja_sin_apertura->id,
                'moneda_id'                        => 2,
                'cotizacion'                       => 100,
                'amount_cotizado'                  => 1000,
            ],
        ]));

        $response->assertStatus(422);

        // El mensaje tiene que nombrar la caja, para que el usuario sepa cual abrir.
        $this->assertStringContainsString(
            $caja_sin_apertura->name,
            $response->json('message'),
            'El 422 tiene que nombrar la caja que falta abrir.'
        );

        $this->assertEquals(
            $gastos_antes,
            Expense::where('user_id', $this->usuario->id)->count(),
            'No se tiene que haber creado ningun gasto.'
        );

        $this->assertEquals(
            $movimientos_antes,
            MovimientoCaja::count(),
            'No se tiene que haber creado ningun movimiento de caja.'
        );

        $this->assertEquals(
            $saldo_ars_inicial,
            (float) $this->caja_ars->fresh()->saldo,
            'El saldo de la caja en pesos no se tiene que haber movido.'
        );
    }

    /**
     * Una caja CERRADA pero con aperturas previas tiene que seguir funcionando. Es la regresion que
     * la prevalidacion podria haber introducido: se valida que la caja tenga ALGUNA apertura, no que
     * este abierta ahora, porque si no se le rompe la carga de gastos a todo comercio que cierra la
     * caja a la noche.
     *
     * @test
     */
    public function una_caja_cerrada_con_aperturas_previas_sigue_aceptando_el_gasto()
    {
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        /*
         * Caja dedicada, para no pisar la caja_ars que usan los otros tests. caja_de_moneda() la
         * abre -y le deja la AperturaCaja en el historial-, y despues se cierra a mano, como al
         * final de la jornada. El cierre va directo al modelo y no por el endpoint a proposito: si
         * dependiera de un PUT que puede fallar, la caja quedaria abierta, el POST devolveria 201
         * igual y este test pasaria en verde sin haber probado nada.
         */
        $caja = $this->caja_de_moneda('Caja Pesos Cerrada De Noche Gasto Test', 1);
        $caja->abierta = 0;
        $caja->save();

        // La precondicion se verifica: sin esto el test no prueba lo que dice probar.
        $this->assertEquals(0, (int) $caja->fresh()->abierta,
            'La caja tiene que quedar cerrada para que este test signifique algo.');

        $antes = $this->max_id_movimiento_caja();

        $response = $this->postJson('api/expense', $this->payload_de_gasto([
            [
                'current_acount_payment_method_id' => $metodo->id,
                'amount'                           => 500,
                'caja_id'                          => $caja->id,
                'moneda_id'                        => 1,
            ],
        ], 500));

        $response->assertStatus(201);

        $gasto_id = $this->extraer_id_de_respuesta($response, 'api/expense');

        $this->gastos_creados_por_escenarios[] = $gasto_id;

        $this->registrar_movimientos_caja_nuevos($antes);

        // Y ademas tiene que haber registrado el movimiento: un 201 que no impacta la caja
        // seria la misma regresion, en silencio.
        $this->assertNotNull(
            MovimientoCaja::where('caja_id', $caja->id)->where('id', '>', $antes)->first(),
            'Una caja cerrada con apertura previa dejo de registrar el movimiento de caja.'
        );
    }

    /**
     * Una fila con monto y SIN caja destino no frena el gasto: se guarda igual, con 201, y lo unico
     * que falta es el movimiento de caja (que queda anotado en el log). El gasto es valido y se
     * arregla desde Tesoreria; lanzar excepcion aca le romperia la carga al usuario.
     *
     * @test
     */
    public function una_fila_sin_caja_destino_no_frena_el_gasto()
    {
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        $saldo_ars_inicial = (float) $this->caja_ars->fresh()->saldo;

        $gasto = $this->crear_gasto(
            TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO,
            1500,
            [
                'payment_methods' => [
                    [
                        'current_acount_payment_method_id' => $metodo->id,
                        'amount'                           => 1000,
                        'caja_id'                          => $this->caja_ars->id,
                        'moneda_id'                        => 1,
                    ],
                    [
                        'current_acount_payment_method_id' => $metodo->id,
                        'amount'                           => 500,
                        'caja_id'                          => 0,
                        'moneda_id'                        => 1,
                    ],
                ],
            ]
        );

        $this->assertNotNull($gasto, 'El gasto se tiene que haber creado igual.');

        // Solo la fila con caja impacto; la otra quedo guardada sin movimiento.
        $this->assertEquals(
            $saldo_ars_inicial - 1000,
            (float) $this->caja_ars->fresh()->saldo,
            'Solo tiene que haber impactado la fila que si tenia caja destino.'
        );
    }
}
