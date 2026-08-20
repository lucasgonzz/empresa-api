<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Tests del reparto por alicuota del importe personalizado al emitir una factura de venta.
 *
 * Como se ejercita SIN red: se arma un `AfipTicket` EN MEMORIA (sin `save()`) con la venta, la
 * configuracion fiscal y el importe personalizado, y se llama
 * `(new AfipHelper($afip_ticket))->getImportes()`. Es exactamente lo que hace
 * `SaleHelper::set_total_a_facturar()` en produccion: no toca AFIP, ni SOAP, ni el TA_file.
 *
 * NUNCA se llama `MakeAfipTicket::make_afip_ticket()`: eso dispara `AfipWsController` contra
 * ARCA de verdad.
 *
 * Lo que blindan estos tests:
 *   - que no haya regresion en el caso de hoy (todo al 21 %);
 *   - que las claves internas '10' y '2' se dividan por 1.105 y 1.025 (son 10,5 % y 2,5 %,
 *     NO 10 % y 2 %);
 *   - que `base + iva` de EXACTAMENTE el importe que autorizo el usuario, porque ese es el
 *     ImpTotal que sale hacia ARCA;
 *   - que el descuadre de centavos lo absorba la ULTIMA fila del orden fijo (la alicuota menor);
 *   - que el importe personalizado no se cotice nunca por `valor_dolar`: ya viene en pesos.
 *
 * @group facturacion
 */
class Importe_Personalizado_Por_Alicuota_Test extends EmpresaTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca assertSame sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Delta "exacto": para los invariantes que tienen que cerrar al centavo redondo, no
     * simplemente parecerse.
     */
    const DELTA_EXACTO = 0.0001;

    /**
     * Configuracion fiscal EN MEMORIA (no toca la base) con la condicion IVA pedida.
     *
     * @param string $condicion Nombre de la `iva_condition` ('Responsable inscripto', etc.).
     * @return \App\Models\AfipInformation
     */
    protected function afip_information_en_memoria($condicion)
    {
        $iva_condition = IvaCondition::where('name', $condicion)->first();

        $this->assertNotNull($iva_condition, 'Falta la iva_condition "'.$condicion.'" en la base de testing.');

        $afip_information = new AfipInformation();
        $afip_information->iva_condition_id = $iva_condition->id;
        $afip_information->punto_venta = 1;
        $afip_information->cuit = '20111111112';
        $afip_information->setRelation('iva_condition', $iva_condition);

        return $afip_information;
    }

    /**
     * Corre el calculo de importes de una venta con importe personalizado, sin tocar la red.
     *
     * @param \App\Models\Sale $sale Venta sobre la que se factura.
     * @param float|null $importe Importe personalizado en pesos (null = calculo normal por items).
     * @param array|null $filas Reparto por alicuota: [ ['key' => '21', 'importe' => 12100], ... ].
     * @param string $condicion Condicion IVA del emisor.
     * @return array Resultado de AfipHelper::getImportes().
     */
    protected function importes_de($sale, $importe, $filas = null, $condicion = 'Responsable inscripto')
    {
        $afip_ticket = new AfipTicket();
        $afip_ticket->facturar_importe_personalizado = $importe;
        $afip_ticket->importe_personalizado_ivas_json = is_null($filas) ? null : json_encode($filas);
        $afip_ticket->afip_tipo_comprobante_id = 1;
        $afip_ticket->sale = $sale;
        $afip_ticket->setRelation('afip_information', $this->afip_information_en_memoria($condicion));

        $afip_helper = new AfipHelper($afip_ticket);

        return $afip_helper->getImportes();
    }

    /**
     * Crea una venta minima del comercio del fixture.
     *
     * @param array $overrides Campos a pisar (moneda_id, valor_dolar, total, ...).
     * @return \App\Models\Sale
     */
    protected function crear_venta($overrides = [])
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return Sale::create(array_merge([
            'user_id'                    => $user->id,
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'sub_total'                  => 1000,
            'total'                      => 1000,
            'moneda_id'                  => 1,
        ], $overrides));
    }

    /**
     * Test 1 - sin reparto explicito, el importe personalizado se liquida entero al 21 %,
     * exactamente como antes de esta mision. Es el test de NO REGRESION.
     *
     * @group facturacion
     * @test
     */
    public function sin_reparto_liquida_todo_al_21_como_hoy()
    {
        $sale = $this->crear_venta();

        $importes = $this->importes_de($sale, 12100.00, null);

        $this->assertEquals(10000.00, $importes['ivas']['21']['BaseImp'], 'la base del 21 % tiene que ser 12100 / 1.21', self::DELTA);
        $this->assertEquals(2100.00, $importes['ivas']['21']['Importe'], 'el IVA del 21 % tiene que ser la resta contra la base', self::DELTA);
        $this->assertEquals(12100.00, $importes['total'], 'el total tiene que ser el importe que autorizo el usuario', self::DELTA);
        $this->assertEquals(0, $importes['ivas']['10']['BaseImp'], 'sin reparto no se toca ninguna otra alicuota', self::DELTA);
    }

    /**
     * Test 2 - reparto entre 21 % y 10,5 %. Lo que blinda es que la clave interna '10' se
     * divida por 1.105 y NO por 1.10: son 10,5 puntos, no 10.
     *
     * @group facturacion
     * @test
     */
    public function reparte_entre_21_y_10_coma_5_dividiendo_por_1_105()
    {
        $sale = $this->crear_venta();

        $importes = $this->importes_de($sale, 17625.00, [
            ['key' => '21', 'importe' => 12100.00],
            ['key' => '10', 'importe' => 5525.00],
        ]);

        $this->assertEquals(10000.00, $importes['ivas']['21']['BaseImp'], 'base del 21 %', self::DELTA);
        $this->assertEquals(2100.00, $importes['ivas']['21']['Importe'], 'IVA del 21 %', self::DELTA);

        // 5525 / 1.105 = 5000 exacto. Con 1.10 daria 5022.73: por eso este test.
        $this->assertEquals(5000.00, $importes['ivas']['10']['BaseImp'], 'la base del 10,5 % sale de dividir por 1.105, no por 1.10', self::DELTA);
        $this->assertEquals(525.00, $importes['ivas']['10']['Importe'], 'IVA del 10,5 %', self::DELTA);

        $this->assertEquals(4, $importes['ivas']['10']['Id'], 'el Id de AFIP del bucket 10,5 % es 4');
        $this->assertEquals(17625.00, $importes['total'], 'el total tiene que dar el importe autorizado, exacto', self::DELTA_EXACTO);
    }

    /**
     * Test 3 - la clave interna '2' es 2,5 %, no 2 %: divide por 1.025.
     *
     * @group facturacion
     * @test
     */
    public function alicuota_2_coma_5_divide_por_1_025()
    {
        $sale = $this->crear_venta();

        $importes = $this->importes_de($sale, 1025.00, [
            ['key' => '2', 'importe' => 1025.00],
        ]);

        $this->assertEquals(1000.00, $importes['ivas']['2']['BaseImp'], 'la base del 2,5 % sale de dividir por 1.025', self::DELTA);
        $this->assertEquals(25.00, $importes['ivas']['2']['Importe'], 'IVA del 2,5 %', self::DELTA);
        $this->assertEquals(9, $importes['ivas']['2']['Id'], 'el Id de AFIP del bucket 2,5 % es 9');
        $this->assertEquals(1025.00, $importes['total'], 'el total tiene que dar el importe autorizado', self::DELTA_EXACTO);
    }

    /**
     * Test 4 - si el reparto no cierra por centavos, el faltante lo absorbe la ULTIMA fila del
     * orden fijo ('27','21','10','5','2','0'), o sea la alicuota MENOR, y el total sigue dando
     * exactamente el importe autorizado.
     *
     * @group facturacion
     * @test
     */
    public function la_ultima_fila_absorbe_el_descuadre_de_centavos()
    {
        $sale = $this->crear_venta();

        // Las filas suman 9999.99: falta un centavo para los 10000.00 autorizados.
        $importes = $this->importes_de($sale, 10000.00, [
            ['key' => '21', 'importe' => 5000.00],
            ['key' => '10', 'importe' => 2000.00],
            ['key' => '0',  'importe' => 2999.99],
        ]);

        $this->assertEquals(10000.00, $importes['total'], 'el total tiene que dar el importe autorizado, exacto', self::DELTA_EXACTO);

        // El centavo cayo en la alicuota 0 %, que es la ultima del orden fijo: 2999.99 + 0.01.
        $this->assertEquals(3000.00, $importes['ivas']['0']['BaseImp'], 'el centavo tiene que caer en la ultima fila del orden fijo', self::DELTA_EXACTO);
        $this->assertEquals(0.00, $importes['ivas']['0']['Importe'], 'la alicuota 0 % no genera IVA', self::DELTA_EXACTO);

        // Y las alicuotas mayores quedaron intactas.
        $this->assertEquals(4132.23, $importes['ivas']['21']['BaseImp'], 'la fila del 21 % no se toca', self::DELTA_EXACTO);
        $this->assertEquals(1809.95, $importes['ivas']['10']['BaseImp'], 'la fila del 10,5 % no se toca', self::DELTA_EXACTO);
    }

    /**
     * Test 5 - invariante central: pase lo que pase, `gravado + iva` tiene que dar EXACTAMENTE
     * el importe personalizado. Ese numero es el ImpTotal que sale hacia ARCA.
     *
     * Barrido con importes de decimales feos, repartidos en 3 alicuotas.
     *
     * @group facturacion
     * @test
     */
    public function base_mas_iva_siempre_da_el_importe_personalizado_exacto()
    {
        $sale = $this->crear_venta();

        foreach ([33333.33, 0.03, 99999.97] as $importe) {

            $filas = [
                ['key' => '21', 'importe' => round($importe * 0.5, 2)],
                ['key' => '10', 'importe' => round($importe * 0.3, 2)],
                ['key' => '5',  'importe' => round($importe * 0.2, 2)],
            ];

            $importes = $this->importes_de($sale, $importe, $filas);

            $this->assertEquals(
                $importe,
                $importes['gravado'] + $importes['iva'],
                'con importe '.$importe.': gravado + iva tiene que dar el importe autorizado, exacto',
                self::DELTA_EXACTO
            );

            $this->assertEquals(
                $importe,
                $importes['total'],
                'con importe '.$importe.': el total tiene que dar el importe autorizado, exacto',
                self::DELTA_EXACTO
            );
        }
    }

    /**
     * Test 6 - venta en dolares, emisor Responsable Inscripto: el importe personalizado ya esta
     * en pesos y NO se multiplica por la cotizacion.
     *
     * @group facturacion
     * @test
     */
    public function venta_en_dolares_no_cotiza_el_importe_personalizado_en_ri()
    {
        $sale = $this->crear_venta([
            'moneda_id'   => 2,
            'valor_dolar' => 1200,
            'sub_total'   => 100,
            'total'       => 100,
        ]);

        $importes = $this->importes_de($sale, 50000.00, [
            ['key' => '21', 'importe' => 50000.00],
        ]);

        $this->assertEquals(50000.00, $importes['total'], 'el importe personalizado ya viene en pesos: no se cotiza (no son 60.000.000)', self::DELTA_EXACTO);
    }

    /**
     * Test 7 - lo mismo con un emisor Monotributista, que va por el camino
     * `calculate_for_no_responsable_inscripto()`. Ahi vivia la multiplicacion de mas.
     *
     * Y el complemento: SIN importe personalizado, la conversion por cotizacion sigue viva
     * para el camino normal.
     *
     * @group facturacion
     * @test
     */
    public function venta_en_dolares_no_cotiza_el_importe_personalizado_en_no_ri()
    {
        $sale = $this->crear_venta([
            'moneda_id'   => 2,
            'valor_dolar' => 1200,
            'sub_total'   => 100,
            'total'       => 100,
        ]);

        $importes = $this->importes_de($sale, 50000.00, null, 'Monotributista');

        $this->assertEquals(50000.00, $importes['total'], 'en no-RI el importe personalizado tampoco se cotiza', self::DELTA_EXACTO);

        // Complemento: sin importe personalizado, la cotizacion SI se aplica.
        $importes_sin_personalizado = $this->importes_de($sale, null, null, 'Monotributista');

        $this->assertEquals(
            120000.00,
            $importes_sin_personalizado['total'],
            'sin importe personalizado, el total de una venta en dolares si se pasa a pesos por la cotizacion',
            self::DELTA
        );
    }

    /**
     * Test 8 - el endpoint rechaza con 422 un importe personalizado mayor al total de la venta.
     *
     * El 422 se devuelve ANTES de instanciar `MakeAfipTicket`, asi que este test no toca ARCA.
     *
     * @group facturacion
     * @test
     */
    public function rechaza_con_422_un_importe_mayor_al_total()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $iva_condition = IvaCondition::where('name', 'Monotributista')->first();

        $sale = $this->crear_venta();

        // Configuracion fiscal real en base: `get_tope_en_pesos()` la resuelve por id.
        $afip_information = AfipInformation::create([
            'user_id'          => $user->id,
            'iva_condition_id' => $iva_condition->id,
            'punto_venta'      => 1,
            'cuit'             => '20111111112',
            'razon_social'     => 'Fixture facturacion',
        ]);

        try {

            $response = $this->postJson('api/afip-ticket', [
                'sale_id'                    => $sale->id,
                'ventas_afip_information_id' => $afip_information->id,
                'afip_tipo_comprobante_id'   => 1,
                // La venta es de 1000: 5000 no puede facturarse.
                'monto_a_facturar'           => 5000,
            ]);

            $response->assertStatus(422);

            $this->assertNotEmpty($response->json('message'), 'el 422 tiene que explicar por que se rechazo');

            $this->assertEquals(
                0,
                AfipTicket::where('sale_id', $sale->id)->count(),
                'un importe rechazado no puede haber creado ningun afip_ticket'
            );

        } finally {

            AfipInformation::where('id', $afip_information->id)->delete();
        }
    }
}
