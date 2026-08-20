<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\MakeAfipTicket;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
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
 * Sobre las aserciones de plata: se usa `assertEqualsWithDelta()`, NO `assertEquals()`.
 * `assertEquals()` de PHPUnit 9.6 toma tres parametros (`$expected, $actual, $message`): un
 * cuarto argumento con la tolerancia se descarta en silencio y la comparacion real termina
 * corriendo con el EPSILON de 1e-10 del DoubleComparator.
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
     * Porcentaje REAL de cada clave interna de alicuota. Espejo de
     * `AfipImportesCalculator::porcentajes_reales()`, escrito a mano a proposito: si el test
     * lo leyera del codigo bajo prueba, no mediria nada.
     *
     * @return array
     */
    protected function porcentajes_reales()
    {
        return ['27' => 27.0, '21' => 21.0, '10' => 10.5, '5' => 5.0, '2' => 2.5, '0' => 0.0];
    }

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
            'descuento'                  => 0,
        ], $overrides));
    }

    /**
     * Crea una configuracion fiscal REAL en base (la necesita `get_tope_en_pesos()`, que la
     * resuelve por id).
     *
     * @param string $condicion Nombre de la `iva_condition`.
     * @return \App\Models\AfipInformation
     */
    protected function crear_afip_information($condicion = 'Monotributista')
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $iva_condition = IvaCondition::where('name', $condicion)->first();

        return AfipInformation::create([
            'user_id'          => $user->id,
            'iva_condition_id' => $iva_condition->id,
            'punto_venta'      => 1,
            'cuit'             => '20111111112',
            'razon_social'     => 'Fixture facturacion',
        ]);
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

        $this->assertEqualsWithDelta(10000.00, $importes['ivas']['21']['BaseImp'], self::DELTA, 'la base del 21 % tiene que ser 12100 / 1.21');
        $this->assertEqualsWithDelta(2100.00, $importes['ivas']['21']['Importe'], self::DELTA, 'el IVA del 21 % tiene que ser la resta contra la base');
        $this->assertEqualsWithDelta(12100.00, $importes['total'], self::DELTA, 'el total tiene que ser el importe que autorizo el usuario');
        $this->assertEqualsWithDelta(0, $importes['ivas']['10']['BaseImp'], self::DELTA, 'sin reparto no se toca ninguna otra alicuota');
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

        $this->assertEqualsWithDelta(10000.00, $importes['ivas']['21']['BaseImp'], self::DELTA, 'base del 21 %');
        $this->assertEqualsWithDelta(2100.00, $importes['ivas']['21']['Importe'], self::DELTA, 'IVA del 21 %');

        // 5525 / 1.105 = 5000 exacto. Con 1.10 daria 5022.73: por eso este test.
        $this->assertEqualsWithDelta(5000.00, $importes['ivas']['10']['BaseImp'], self::DELTA, 'la base del 10,5 % sale de dividir por 1.105, no por 1.10');
        $this->assertEqualsWithDelta(525.00, $importes['ivas']['10']['Importe'], self::DELTA, 'IVA del 10,5 %');

        $this->assertEquals(4, $importes['ivas']['10']['Id'], 'el Id de AFIP del bucket 10,5 % es 4');
        $this->assertEqualsWithDelta(17625.00, $importes['total'], self::DELTA_EXACTO, 'el total tiene que dar el importe autorizado, exacto');
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

        $this->assertEqualsWithDelta(1000.00, $importes['ivas']['2']['BaseImp'], self::DELTA, 'la base del 2,5 % sale de dividir por 1.025');
        $this->assertEqualsWithDelta(25.00, $importes['ivas']['2']['Importe'], self::DELTA, 'IVA del 2,5 %');
        $this->assertEquals(9, $importes['ivas']['2']['Id'], 'el Id de AFIP del bucket 2,5 % es 9');
        $this->assertEqualsWithDelta(1025.00, $importes['total'], self::DELTA_EXACTO, 'el total tiene que dar el importe autorizado');
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

        $this->assertEqualsWithDelta(10000.00, $importes['total'], self::DELTA_EXACTO, 'el total tiene que dar el importe autorizado, exacto');

        // El centavo cayo en la alicuota 0 %, que es la ultima del orden fijo: 2999.99 + 0.01.
        $this->assertEqualsWithDelta(3000.00, $importes['ivas']['0']['BaseImp'], self::DELTA_EXACTO, 'el centavo tiene que caer en la ultima fila del orden fijo');
        $this->assertEqualsWithDelta(0.00, $importes['ivas']['0']['Importe'], self::DELTA_EXACTO, 'la alicuota 0 % no genera IVA');

        // Y las alicuotas mayores quedaron intactas.
        $this->assertEqualsWithDelta(4132.23, $importes['ivas']['21']['BaseImp'], self::DELTA_EXACTO, 'la fila del 21 % no se toca');
        $this->assertEqualsWithDelta(1809.95, $importes['ivas']['10']['BaseImp'], self::DELTA_EXACTO, 'la fila del 10,5 % no se toca');
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

            $this->assertEqualsWithDelta(
                $importe,
                $importes['gravado'] + $importes['iva'],
                self::DELTA_EXACTO,
                'con importe '.$importe.': gravado + iva tiene que dar el importe autorizado, exacto'
            );

            $this->assertEqualsWithDelta(
                $importe,
                $importes['total'],
                self::DELTA_EXACTO,
                'con importe '.$importe.': el total tiene que dar el importe autorizado, exacto'
            );
        }
    }

    /**
     * Test 6 - venta en dolares, emisor Responsable Inscripto: el importe personalizado ya esta
     * en pesos y NO se multiplica por la cotizacion.
     *
     * ⚠️ Este test es de blindaje, no de regresion: el camino RI nunca multiplico por
     * `valor_dolar`, asi que tambien pasa en `origin/develop`. Queda para que un refactor futuro
     * del calculador no le meta la cotizacion.
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

        $this->assertEqualsWithDelta(50000.00, $importes['total'], self::DELTA_EXACTO, 'el importe personalizado ya viene en pesos: no se cotiza (no son 60.000.000)');
    }

    /**
     * Test 7 - lo mismo con un emisor Monotributista, que va por el camino
     * `calculate_for_no_responsable_inscripto()`. Ahi si vivia la multiplicacion de mas.
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

        $this->assertEqualsWithDelta(50000.00, $importes['total'], self::DELTA_EXACTO, 'en no-RI el importe personalizado tampoco se cotiza');

        // Complemento: sin importe personalizado, la cotizacion SI se aplica.
        $importes_sin_personalizado = $this->importes_de($sale, null, null, 'Monotributista');

        $this->assertEqualsWithDelta(
            120000.00,
            $importes_sin_personalizado['total'],
            self::DELTA,
            'sin importe personalizado, el total de una venta en dolares si se pasa a pesos por la cotizacion'
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
        $sale = $this->crear_venta();
        $afip_information = $this->crear_afip_information();

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

    /**
     * Test 9 - invariante que declara el docblock del calculador y que ningun otro test cubre:
     * el `Importe` de CADA bucket tiene que ser coherente con su propia alicuota, o sea estar a
     * menos de un centavo de `BaseImp x porcentaje / 100`. Es lo que ARCA valida fila por fila.
     *
     * Sin este test, mover el ajuste del descuadre a DESPUES del back-out (en vez de antes)
     * pasa desapercibido: `gravado + iva` sigue dando exacto, pero la fila sale desfasada.
     *
     * Incluye a proposito un descuadre GRANDE (10 pesos), que es el caso que el 422 del
     * controller no puede atajar porque ya paso cuando el calculador reparte.
     *
     * @group facturacion
     * @test
     */
    public function el_importe_de_cada_bucket_es_coherente_con_su_alicuota()
    {
        $sale = $this->crear_venta();

        $escenarios = [
            // [importe autorizado, filas]
            'reparto exacto en 4 alicuotas' => [
                24000.00,
                [
                    ['key' => '27', 'importe' => 6350.00],
                    ['key' => '21', 'importe' => 8470.00],
                    ['key' => '10', 'importe' => 5525.00],
                    ['key' => '5',  'importe' => 3655.00],
                ],
            ],
            // Las filas suman 9990: faltan 10 pesos, que absorbe la fila del 5 %.
            'descuadre grande absorbido por la ultima fila' => [
                10000.00,
                [
                    ['key' => '21', 'importe' => 5000.00],
                    ['key' => '10', 'importe' => 2000.00],
                    ['key' => '5',  'importe' => 2990.00],
                ],
            ],
        ];

        foreach ($escenarios as $nombre => $escenario) {

            list($importe, $filas) = $escenario;

            $importes = $this->importes_de($sale, $importe, $filas);

            foreach ($this->porcentajes_reales() as $key => $porcentaje) {

                if ($importes['ivas'][$key]['BaseImp'] == 0 && $importes['ivas'][$key]['Importe'] == 0) {
                    continue;
                }

                $this->assertEqualsWithDelta(
                    $importes['ivas'][$key]['BaseImp'] * $porcentaje / 100,
                    $importes['ivas'][$key]['Importe'],
                    self::DELTA,
                    $nombre.': el IVA del bucket "'.$key.'" tiene que ser coherente con su alicuota ('.$porcentaje.' %). '.
                    'Si no lo es, el ajuste del descuadre se esta aplicando DESPUES del back-out.'
                );

                // Y ademas base + iva de la fila tiene que reconstruir su propio importe.
                $this->assertEqualsWithDelta(
                    $importes['ivas'][$key]['BaseImp'] + $importes['ivas'][$key]['Importe'],
                    round($importes['ivas'][$key]['BaseImp'] + $importes['ivas'][$key]['Importe'], 2),
                    self::DELTA_EXACTO,
                    $nombre.': el bucket "'.$key.'" tiene que cerrar en 2 decimales'
                );
            }

            $this->assertEqualsWithDelta(
                $importe,
                $importes['total'],
                self::DELTA_EXACTO,
                $nombre.': el total tiene que dar el importe autorizado, exacto'
            );
        }
    }

    /**
     * Test 10 - el endpoint rechaza con 422 un reparto cuya suma no da el importe a facturar.
     *
     * @group facturacion
     * @test
     */
    public function rechaza_con_422_un_reparto_que_no_suma()
    {
        $sale = $this->crear_venta();
        $afip_information = $this->crear_afip_information();

        try {

            $response = $this->postJson('api/afip-ticket', [
                'sale_id'                    => $sale->id,
                'ventas_afip_information_id' => $afip_information->id,
                'afip_tipo_comprobante_id'   => 1,
                'monto_a_facturar'           => 1000,
                // 500 + 400 = 900, no 1000.
                'importe_personalizado_ivas' => [
                    ['key' => '21', 'importe' => 500],
                    ['key' => '10', 'importe' => 400],
                ],
            ]);

            $response->assertStatus(422);

            $this->assertStringContainsString(
                'suma del reparto',
                $response->json('message'),
                'el 422 tiene que decir que el reparto no suma'
            );

            $this->assertEquals(
                0,
                AfipTicket::where('sale_id', $sale->id)->count(),
                'un reparto rechazado no puede haber creado ningun afip_ticket'
            );

        } finally {

            AfipInformation::where('id', $afip_information->id)->delete();
        }
    }

    /**
     * Test 11 - 🔴 el caso critico. Una fila con clave de alicuota invalida se RECHAZA con 422;
     * NUNCA se descarta en silencio.
     *
     * El descarte silencioso era el bug: `[{'10.5', 90000}, {'0', 10000}]` sobre 100000 pasaba
     * la validacion de la suma (porque se sumaban las dos filas), y despues el normalizador
     * tiraba la fila del '10.5' por clave desconocida. Al calculador le llegaba un reparto de
     * 10000 para un importe de 100000, le encajaba los 90000 de diferencia a la unica fila viva
     * — la del 0 % — y a ARCA le salia una factura de $100.000 con IVA CERO.
     *
     * Y '10.5' no es rebuscado: la clave interna de 10,5 % es '10', asi que cualquier cliente
     * que mande el porcentaje real cae justo ahi.
     *
     * @group facturacion
     * @test
     */
    public function rechaza_con_422_una_fila_con_alicuota_invalida()
    {
        $sale = $this->crear_venta(['sub_total' => 100000, 'total' => 100000]);
        $afip_information = $this->crear_afip_information();

        try {

            $response = $this->postJson('api/afip-ticket', [
                'sale_id'                    => $sale->id,
                'ventas_afip_information_id' => $afip_information->id,
                'afip_tipo_comprobante_id'   => 1,
                'monto_a_facturar'           => 100000,
                'importe_personalizado_ivas' => [
                    // '10.5' NO es una clave valida: la clave interna de 10,5 % es '10'.
                    ['key' => '10.5', 'importe' => 90000],
                    ['key' => '0',    'importe' => 10000],
                ],
            ]);

            $response->assertStatus(422);

            $this->assertStringContainsString(
                '10.5',
                $response->json('message'),
                'el 422 tiene que nombrar la alicuota invalida para que el front pueda corregirla'
            );

            $this->assertEquals(
                0,
                AfipTicket::where('sale_id', $sale->id)->count(),
                'un reparto invalido no puede haber creado ningun afip_ticket'
            );

        } finally {

            AfipInformation::where('id', $afip_information->id)->delete();
        }

        // Y el criterio compartido, medido directo: una fila invalida invalida el reparto entero,
        // no se descarta.
        $validacion = MakeAfipTicket::validar_filas_importe_personalizado([
            ['key' => '10.5', 'importe' => 90000],
            ['key' => '0',    'importe' => 10000],
        ]);

        $this->assertNotNull($validacion['error'], 'una clave invalida tiene que dar error, no descarte');
        $this->assertCount(0, $validacion['filas'], 'con error no puede sobrevivir ninguna fila');

        // Una fila en cero tampoco se descarta: se rechaza.
        $validacion_cero = MakeAfipTicket::validar_filas_importe_personalizado([
            ['key' => '21', 'importe' => 1000],
            ['key' => '10', 'importe' => 0],
        ]);

        $this->assertNotNull($validacion_cero['error'], 'una fila en cero tiene que dar error, no descarte');

        // Y los importes validos salen cuantizados a 2 decimales.
        $validacion_ok = MakeAfipTicket::validar_filas_importe_personalizado([
            ['key' => '21', 'importe' => 1000.005],
        ]);

        $this->assertNull($validacion_ok['error'], 'un reparto valido no tiene que dar error');
        $this->assertEqualsWithDelta(
            1000.01,
            $validacion_ok['filas'][0]['importe'],
            self::DELTA_EXACTO,
            'el importe de cada fila se redondea a 2 decimales: el reparto viaja en un longText y sin eso rompe el ImpTotal'
        );
    }

    /**
     * Test 12 - en una facturacion consolidada no se admite importe personalizado, aunque el
     * request traiga uno.
     *
     * Se mide sobre `build_afip_ticket_data()`, que se extrajo justamente para poder verificar
     * el payload sin llamar `make_afip_ticket()` (que sale a ARCA).
     *
     * @group facturacion
     * @test
     */
    public function la_consolidacion_no_admite_importe_personalizado()
    {
        $data = ConsolidarFacturacionHelper::build_afip_ticket_data(
            [
                'afip_fecha_emision' => '2026-08-20',
                'monto_a_facturar'   => 5000,
                'forma_de_pago'      => 'Contado',
                'permiso_existente'  => 'N',
                'incoterms'          => null,
            ],
            123,
            456,
            1
        );

        $this->assertNull($data['facturar_importe_personalizado'], 'la consolidada nunca lleva importe personalizado');
        $this->assertNull($data['importe_personalizado_ivas'], 'la consolidada nunca lleva reparto por alicuota');

        // El resto del payload si tiene que pasar tal cual.
        $this->assertEquals(123, $data['sale_id']);
        $this->assertEquals(456, $data['afip_information_id']);
        $this->assertEquals(1, $data['afip_tipo_comprobante_id']);
        $this->assertEquals('2026-08-20', $data['afip_fecha_emision']);
        $this->assertEquals('Contado', $data['forma_de_pago']);
        $this->assertEquals('N', $data['permiso_existente']);
    }
}
