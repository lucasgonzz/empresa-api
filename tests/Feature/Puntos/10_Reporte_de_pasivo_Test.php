<?php

namespace Tests\Feature\Puntos;

use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 10 — el REPORTE DE PASIVO del programa.
 *
 * El reporte contesta una sola pregunta del comercio: "¿cuánto le debo hoy a mis clientes?". Y la
 * contesta con cinco flujos del período (emitidos, canjeados, vencidos, revertidos, ajustes) más un
 * stock (`saldo_vivo`).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA IDENTIDAD QUE TIENE QUE CERRAR
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *      emitidos - canjeados - vencidos - revertidos + ajustes == saldo_vivo
 *
 *  No es una coincidencia aritmética: es el saldo escrito de dos formas. `saldo_vivo` es
 *  `SUM(puntos)` de todo el libro, y las cinco tarjetas son ese mismo SUM partido por tipo, con el
 *  signo sacado de los tres tipos que se guardan en negativo (el reporte dice "se canjearon 300
 *  puntos", no "-300"). Si algún día no cierra, es que apareció un tipo de movimiento que el
 *  reporte no conoce, o que alguno cambió de signo — las dos cosas se manifiestan acá y en ningún
 *  otro lado.
 *
 *  ⚠️ `saldo_vivo` NO respeta `desde`: es un STOCK, no un flujo. Por eso el escenario de estos
 *  tests crea TODOS sus movimientos dentro del período que después se consulta; si no, la identidad
 *  no tendría por qué cerrar y el test estaría midiendo su propia fixture.
 */
class Reporte_de_pasivo_Test extends PuntosTestCase
{
    const VALOR_PUNTO = 100;

    /**
     * Un escenario con los CINCO tipos de movimiento, todos con fecha de hoy.
     *
     * @return \App\Models\Client
     */
    protected function escenario_completo()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa([
            'valor_punto'       => self::VALOR_PUNTO,
            'minimo_canje'      => 10,
            'tope_porcentaje'   => 20,
            'vencimiento_meses' => 12,
        ]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        // 1. GANADOS: una venta de mostrador de neto $100.000 -> 100 puntos.
        $this->crear_venta($this->payload_venta($cliente->id, 121000, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        // 2. CANJEADOS: otra venta con canje de 15 puntos ($1.500 sobre un bruto de $121.000).
        $this->crear_venta($this->payload_venta($cliente->id, 119500, [
            'omitir_en_cuenta_corriente' => 1,
            'puntos_canjeados'           => 15,
            'descuento_puntos'           => 1500,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => 121000,
                    'amount'       => 1,
                ],
            ],
        ]));

        // 3. AJUSTES: uno en más y otro en menos, por el endpoint real.
        $this->postJson('api/puntos/ajuste', [
            'client_id' => $cliente->id,
            'puntos'    => 50,
            'detalle'   => 'Ajuste a favor (test)',
        ])->assertStatus(201);

        $this->postJson('api/puntos/ajuste', [
            'client_id' => $cliente->id,
            'puntos'    => -10,
            'detalle'   => 'Ajuste en contra (test)',
        ])->assertStatus(201);

        // 4. REVERTIDOS: una venta que se otorga y después se borra.
        $venta_borrada = $this->crear_venta($this->payload_venta($cliente->id, 60500, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $this->deleteJson('api/sale/'.$venta_borrada->id)->assertStatus(200);

        // 5. VENCIDOS: un lote con la fecha ya pasada, barrido por el comando.
        $this->sembrar_lote($cliente, $sistema, 40, Carbon::now()->subDays(1));

        $this->artisan('puntos:vencer')->assertExitCode(0);

        return $cliente;
    }

    /**
     * @param  string|null  $extra
     * @return array
     */
    protected function pedir_reporte($extra = null)
    {
        $url = 'api/puntos/reporte?desde='.Carbon::now()->subDay()->format('Y-m-d')
            .'&hasta='.Carbon::now()->addDay()->format('Y-m-d');

        if (!is_null($extra)) {
            $url .= $extra;
        }

        $response = $this->getJson($url);

        $response->assertStatus(200);

        return json_decode($response->getContent(), true);
    }

    /**
     * 🔴 La identidad del pasivo cierra.
     *
     * @group puntos
     * @test
     */
    public function la_identidad_del_reporte_cierra_contra_el_saldo_vivo()
    {
        $this->escenario_completo();

        $reporte = $this->pedir_reporte();

        foreach (['emitidos', 'canjeados', 'vencidos', 'revertidos', 'ajustes', 'saldo_vivo'] as $clave) {
            $this->assertArrayHasKey($clave, $reporte, 'Al reporte le falta la tarjeta "'.$clave.'".');
            $this->assertArrayHasKey('puntos', $reporte[$clave]);
            $this->assertArrayHasKey('pesos', $reporte[$clave]);
        }

        $calculado = $reporte['emitidos']['puntos']
            - $reporte['canjeados']['puntos']
            - $reporte['vencidos']['puntos']
            - $reporte['revertidos']['puntos']
            + $reporte['ajustes']['puntos'];

        $this->assertEqualsWithDelta(
            $reporte['saldo_vivo']['puntos'],
            $calculado,
            self::DELTA,
            'emitidos - canjeados - vencidos - revertidos + ajustes tiene que dar exactamente el saldo_vivo.'
        );
    }

    /**
     * Y el saldo vivo del reporte es el mismo número que la ficha del cliente y que VENDER: hay un
     * solo cliente en el escenario, así que su saldo tiene que ser el pasivo entero del comercio.
     *
     * @group puntos
     * @test
     */
    public function el_saldo_vivo_del_reporte_es_el_mismo_que_el_del_cliente()
    {
        $cliente = $this->escenario_completo();

        $reporte = $this->pedir_reporte();

        $this->assertEqualsWithDelta(
            $this->saldo_de_puntos($cliente),
            $reporte['saldo_vivo']['puntos'],
            self::DELTA,
            'El pasivo del comercio y el saldo del único cliente tienen que ser el mismo número.'
        );

        $disponible = json_decode($this->getJson('api/puntos/disponible/'.$cliente->id)->getContent(), true);
        $ficha = json_decode($this->getJson('api/puntos/cliente/'.$cliente->id)->getContent(), true);

        $this->assertEqualsWithDelta(
            $reporte['saldo_vivo']['puntos'],
            $disponible['saldo'],
            self::DELTA,
            'VENDER muestra un saldo distinto del que muestra el reporte.'
        );
        $this->assertEqualsWithDelta(
            $reporte['saldo_vivo']['puntos'],
            $ficha['saldo'],
            self::DELTA,
            'La ficha del cliente muestra un saldo distinto del que muestra el reporte.'
        );
    }

    /**
     * Los tres tipos que se guardan en negativo se muestran en MAGNITUD, y los pesos son los puntos
     * por el valor del punto.
     *
     * @group puntos
     * @test
     */
    public function los_tipos_negativos_se_muestran_en_magnitud_y_los_pesos_acompanan()
    {
        $this->escenario_completo();

        $reporte = $this->pedir_reporte();

        $this->assertEqualsWithDelta(self::VALOR_PUNTO, $reporte['valor_punto'], self::DELTA);

        $this->assertEqualsWithDelta(15, $reporte['canjeados']['puntos'], self::DELTA, 'Se canjearon 15 puntos, y la tarjeta los muestra en positivo.');
        $this->assertEqualsWithDelta(40, $reporte['vencidos']['puntos'], self::DELTA);
        $this->assertEqualsWithDelta(50, $reporte['revertidos']['puntos'], self::DELTA);

        /*
         * 'ajuste' es el único tipo que puede ir para los dos lados, así que va con el signo NETO
         * tal cual, que es su significado: +50 y -10 son 40 puntos netos ajustados a favor.
         */
        $this->assertEqualsWithDelta(40, $reporte['ajustes']['puntos'], self::DELTA);

        foreach (['emitidos', 'canjeados', 'vencidos', 'revertidos', 'ajustes', 'saldo_vivo'] as $clave) {
            $this->assertEqualsWithDelta(
                round($reporte[$clave]['puntos'] * self::VALOR_PUNTO, 2),
                $reporte[$clave]['pesos'],
                self::DELTA,
                'Los pesos de la tarjeta "'.$clave.'" no son sus puntos por el valor del punto.'
            );
        }
    }

    /**
     * El drill-down de cada tarjeta suma exactamente el total de su tipo, y la paginación no pierde
     * ni repite filas.
     *
     * @group puntos
     * @test
     */
    public function el_detalle_paginado_suma_exactamente_el_total_de_su_tipo()
    {
        $this->escenario_completo();

        $reporte = $this->pedir_reporte();

        $claves = [
            'ganados'    => 'emitidos',
            'canjeados'  => 'canjeados',
            'vencidos'   => 'vencidos',
            'revertidos' => 'revertidos',
            'ajuste'     => 'ajustes',
        ];

        foreach ($claves as $tipo => $clave_del_reporte) {

            $suma = 0;
            $vistos = [];
            $page = 1;

            do {
                $url = 'api/puntos/reporte/detalle?tipo='.$tipo
                    .'&desde='.Carbon::now()->subDay()->format('Y-m-d')
                    .'&hasta='.Carbon::now()->addDay()->format('Y-m-d')
                    .'&per_page=1&page='.$page;

                $cuerpo = json_decode($this->getJson($url)->assertStatus(200)->getContent(), true);

                $this->assertEquals($tipo, $cuerpo['tipo']);

                foreach ($cuerpo['registros'] as $registro) {

                    $this->assertArrayNotHasKey(
                        $registro['id'],
                        $vistos,
                        'El detalle de "'.$tipo.'" repitió la fila '.$registro['id'].' entre páginas.'
                    );

                    $vistos[$registro['id']] = true;

                    $suma += (float) $registro['puntos'];
                }

                $page++;

            } while (count($cuerpo['registros']) > 0 && $page <= $cuerpo['paginacion']['total_registros']);

            $this->assertCount(
                (int) $cuerpo['paginacion']['total_registros'],
                $vistos,
                'El detalle de "'.$tipo.'" no devolvió todas las filas que dice tener.'
            );

            /*
             * Los registros vienen con el signo REAL del libro y la tarjeta en magnitud: la
             * comparación se hace en valor absoluto para los tipos negativos. 'ajuste' va con signo
             * en los dos lados.
             */
            $esperado = $reporte[$clave_del_reporte]['puntos'];

            if ($tipo !== 'ganados' && $tipo !== 'ajuste') {
                $suma = abs($suma);
            }

            $this->assertEqualsWithDelta(
                $esperado,
                round($suma, 2),
                self::DELTA,
                'El detalle de "'.$tipo.'" no suma lo mismo que su tarjeta del reporte.'
            );
        }
    }

    /**
     * Un `tipo` que no está en la lista blanca devuelve 422, nunca una lista vacía que se lea como
     * "no hubo movimientos".
     *
     * @group puntos
     * @test
     */
    public function un_tipo_desconocido_en_el_detalle_devuelve_422()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $this->getJson('api/puntos/reporte/detalle?tipo=inventado')
            ->assertStatus(422);
    }

    /**
     * El ajuste manual escribe un movimiento con el signo que se pidió y devuelve el saldo nuevo.
     * Y el positivo es un LOTE (nace con `vence_at`), porque si no lo fuera, un cliente cuyo saldo
     * entero viniera de ajustes tendría saldo mayor a cero y nada de donde consumir al canjear.
     *
     * @group puntos
     * @test
     */
    public function el_ajuste_positivo_es_un_lote_con_vencimiento()
    {
        $this->dar_extencion();
        $this->crear_programa(['vencimiento_meses' => 12]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $response = $this->postJson('api/puntos/ajuste', [
            'client_id' => $cliente->id,
            'puntos'    => 80,
            'detalle'   => 'Premio de fin de año',
        ]);

        $response->assertStatus(201);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertEqualsWithDelta(80, $cuerpo['saldo'], self::DELTA);

        $ajustes = $this->movimientos_del_cliente($cliente, 'ajuste');

        $this->assertCount(1, $ajustes);
        $this->assertEqualsWithDelta(80, (float) $ajustes->first()->puntos, self::DELTA);
        $this->assertEquals('Premio de fin de año', $ajustes->first()->detalle);
        $this->assertNotNull(
            $ajustes->first()->vence_at,
            'El ajuste positivo es un lote: tiene que nacer con su fecha de vencimiento como cualquier otro.'
        );
        $this->assertNull($ajustes->first()->sale_id);
    }

    /**
     * 🔴 Un ajuste negativo no puede dejar el saldo en rojo. La reversión de una venta anulada SÍ
     * puede —es una consecuencia automática de un hecho económico—, pero un ajuste es alguien
     * tipeando un número a mano: ahí un 422 con el saldo real adentro es mucho mejor que un saldo
     * negativo que después nadie sabe explicar.
     *
     * @group puntos
     * @test
     */
    public function un_ajuste_negativo_mayor_al_saldo_devuelve_422()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $this->postJson('api/puntos/ajuste', [
            'client_id' => $cliente->id,
            'puntos'    => 20,
            'detalle'   => 'Carga inicial',
        ])->assertStatus(201);

        $this->postJson('api/puntos/ajuste', [
            'client_id' => $cliente->id,
            'puntos'    => -50,
            'detalle'   => 'Se pasó de la raya',
        ])->assertStatus(422);

        $this->assertEqualsWithDelta(
            20,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El ajuste rechazado no puede haber movido el saldo.'
        );
    }

    /**
     * El ajuste negativo consume los lotes por FIFO, igual que un canje: si no lo hiciera, el saldo
     * bajaría pero los lotes seguirían enteros y el vencimiento mataría puntos que ya no existen.
     *
     * @group puntos
     * @test
     */
    public function el_ajuste_negativo_consume_los_lotes_por_fifo()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $viejo = $this->sembrar_lote($cliente, $sistema, 30, Carbon::now()->addMonths(12), Carbon::now()->subDays(20));
        $nuevo = $this->sembrar_lote($cliente, $sistema, 30, Carbon::now()->addMonths(12), Carbon::now()->subDays(2));

        $response = $this->postJson('api/puntos/ajuste', [
            'client_id' => $cliente->id,
            'puntos'    => -40,
            'detalle'   => 'Corrección de carga',
        ]);

        $response->assertStatus(201);

        $viejo->refresh();
        $nuevo->refresh();

        $this->assertEqualsWithDelta(30, (float) $viejo->consumido, self::DELTA, 'El lote más viejo tiene que consumirse primero.');
        $this->assertEqualsWithDelta(10, (float) $nuevo->consumido, self::DELTA);

        $this->assertEqualsWithDelta(20, $this->saldo_de_puntos($cliente), self::DELTA);
    }
}
