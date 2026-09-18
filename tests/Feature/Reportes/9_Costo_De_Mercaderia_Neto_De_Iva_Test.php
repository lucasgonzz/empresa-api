<?php

namespace Tests\Feature\Reportes;

use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión saneo-ganancia-ventas (17/9/2026) — el COSTO de mercadería del Estado de Resultados sale
 * neto del IVA de compra que el negocio recupera.
 *
 * 🔴 Es la otra mitad del mismo arreglo, y por eso no se podía dejar para después. Esta misión hizo
 * que `ventas_brutas()` saliera NETA del IVA declarado; si el costo se hubiera seguido sumando
 * BRUTO —`SUM(article_sale.cost * article_sale.amount)` pelado—, el "resultado bruto" de una cuenta
 * legacy con `aplicar_iva_al_costo` prendida habría quedado subvaluado en el 21 % del costo. Y peor
 * que antes de la misión: antes las dos puntas estaban igual de brutas y el error se cancelaba solo.
 *
 * El escenario de todos los tests es el mismo, con los mismos números que
 * `tests/Feature/Sales/25_Ganancia_Con_Costo_Bruto_Test`, para que se puedan cruzar de un vistazo:
 * costo neto 100, margen 40 %, IVA 21 % → precio final 169,40 y un comprobante que declara 29,40.
 *
 * | Cuenta | `article_sale.cost` | Ventas netas | CMV | Resultado bruto |
 * |---|---|---|---|---|
 * | Migrada (RI) | 100 (neto) | 140,00 | 100,00 | 40,00 |
 * | Legacy con la tilde prendida | 121 (BRUTO) | 140,00 | **100,00** (antes: 121,00) | **40,00** (antes: 19,00) |
 *
 * El criterio no se escribe acá ni se escribe dos veces: es el mismo `CostoDeVentaHelper` que usa
 * `sales.ganancia`, expresado en SQL porque el reporte suma decenas de miles de líneas y además las
 * pagina. `la_tarjeta_da_lo_mismo_que_la_ganancia_de_la_venta()` es el test que ata las dos ramas:
 * si alguien toca una sola, ese se pone rojo.
 *
 * Rango de fechas propio, verificado archivo por archivo: 1_ usa 2016 y 2019, 2_ usa 2019 y 2020,
 * 3_ usa 2021, 4_ abril de 2018, 5_ mayo de 2017 y 8_ todo 2015. Este archivo usa **2014**, que no
 * aparece en ninguno, con un mes por test.
 *
 * @group reportes
 */
class Costo_De_Mercaderia_Neto_De_Iva_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la carpeta). */
    const DELTA = 0.01;

    /**
     * Ids de los `afip_tickets` creados por este archivo.
     *
     * @var array<int,int>
     */
    protected $afip_tickets_sembrados = [];

    /**
     * Ids de los `current_acounts` de nota de crédito creados por este archivo.
     *
     * @var array<int,int>
     */
    protected $notas_credito_sembradas = [];

    /**
     * Ids de los artículos creados por este archivo.
     *
     * @var array<int,int>
     */
    protected $articulos_creados = [];

    /**
     * Limpia lo que sembró el test antes del rollback de `DatabaseTransactions` — red real
     * redundante, mismo criterio que `EscenariosDePlata::limpiar_escenarios()`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->afip_tickets_sembrados) >= 1) {
            AfipTicket::whereIn('id', $this->afip_tickets_sembrados)->forceDelete();
        }

        if (count($this->notas_credito_sembradas) >= 1) {
            CurrentAcount::whereIn('id', $this->notas_credito_sembradas)->delete();
        }

        $this->limpiar_escenarios();

        if (count($this->articulos_creados) >= 1) {
            Article::whereIn('id', $this->articulos_creados)->forceDelete();
        }

        parent::tearDown();
    }

    /**
     * Test 1 — Cuenta legacy con el costo BRUTO: el CMV sale neto y el resultado bruto da 40,00.
     *
     * Costo guardado 121 (neto 100 + 21 de IVA de compra), venta 169,40, comprobante con 29,40.
     *
     *     ventas netas 140,00 − CMV 100,00 = resultado bruto 40,00
     *
     * Antes de este arreglo el CMV informaba 121,00 y el resultado bruto 19,00, con las ventas ya
     * neteadas: un renglón mestizo, precio sin IVA contra costo con IVA.
     *
     * @group reportes
     * @test
     */
    public function cuenta_legacy_con_costo_bruto_informa_el_cmv_neto()
    {
        $this->cuenta_legacy_con_la_tilde_prendida();

        $this->fijar_reloj_en('2014-01-10 10:00:00');

        $venta = $this->crear_venta_con_una_linea(121, 169.40, 1);

        $this->facturar($venta, 29.40);

        $estado = $this->pedir_estado_resultados('2014-01-01', '2014-01-31');

        // Guard del escenario: si las ventas no salieran neteadas, el resultado bruto de abajo
        // estaría midiendo otra cosa.
        $this->assertEqualsWithDelta(
            140.00,
            (float) $estado['ventas_netas'],
            self::DELTA,
            'Las ventas netas tienen que ser 169,40 - 29,40 = 140,00.'
        );

        $this->assertEqualsWithDelta(
            100.00,
            (float) $estado['costo_mercaderia_vendida'],
            self::DELTA,
            'El costo está guardado BRUTO (121). Su IVA de compra es crédito fiscal recuperable: el '.
            'CMV tiene que informar 100,00. Si informa 121,00, se está sumando el costo con IVA '.
            'adentro contra unas ventas que ya salen sin IVA.'
        );

        $this->assertEqualsWithDelta(
            40.00,
            (float) $estado['resultado_bruto'],
            self::DELTA,
            'Resultado bruto = 140,00 - 100,00 = 40,00. Si da 19,00, el renglón quedó mestizo.'
        );
    }

    /**
     * Test 2 — Cuenta migrada (el caso normal): costo NETO 100, nada cambia respecto de hoy.
     *
     * Es el test de no-regresión: la enorme mayoría de las cuentas tiene el costo neto y la query
     * ni siquiera agrega los joins de alícuota.
     *
     * @group reportes
     * @test
     */
    public function cuenta_migrada_con_costo_neto_no_cambia()
    {
        $this->fijar_reloj_en('2014-02-10 10:00:00');

        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $this->facturar($venta, 29.40);

        $estado = $this->pedir_estado_resultados('2014-02-01', '2014-02-28');

        $this->assertEqualsWithDelta(
            100.00,
            (float) $estado['costo_mercaderia_vendida'],
            self::DELTA,
            'El costo de una cuenta migrada ya es neto: el CMV tiene que seguir siendo 100,00.'
        );

        $this->assertEqualsWithDelta(
            40.00,
            (float) $estado['resultado_bruto'],
            self::DELTA,
            'Resultado bruto = 140,00 - 100,00 = 40,00, igual que antes de esta misión.'
        );
    }

    /**
     * Test 3 — Una línea de artículo con `aplicar_iva` apagado no se netea, aunque la cuenta sea
     * legacy.
     *
     * `ArticlePricesHelper::aplicar_iva()` sólo le suma el IVA al costo de los artículos con esa
     * tilde prendida, así que el costo de éste ya es neto. Y su pivot igual guarda
     * `iva_percentage = 21`, porque `get_iva_percentage_for_pivot()` persiste la alícuota del
     * artículo sin mirar `aplicar_iva`: netear mirando sólo esa columna le sacaría un 21 % que este
     * costo nunca tuvo. El neteo tiene que ser línea por línea, no por venta.
     *
     * @group reportes
     * @test
     */
    public function linea_con_aplicar_iva_apagado_no_se_netea()
    {
        $this->cuenta_legacy_con_la_tilde_prendida();

        $this->fijar_reloj_en('2014-03-10 10:00:00');

        $venta = $this->crear_venta_con_una_linea(100, 140.00, 1, ['aplicar_iva' => 0]);

        $estado = $this->pedir_estado_resultados('2014-03-01', '2014-03-31');

        $this->assertEqualsWithDelta(
            100.00,
            (float) $estado['costo_mercaderia_vendida'],
            self::DELTA,
            'A este costo nunca se le sumó IVA: el CMV tiene que informar 100,00 y no 82,64.'
        );
    }

    /**
     * Test 4 — La mercadería DEVUELTA se netea con el mismo criterio.
     *
     * 🔴 `article_current_acount.cost` es una copia del costo de la línea de venta original (ver
     * `CurrentAcountHelper::attachNotaCreditoArticles()`), así que arrastra el mismo costo bruto.
     * Netear el CMV sin netear la devolución dejaría el costo neto de mercadería restando de más un
     * 21 %: el mismo error, cambiado de signo.
     *
     * Se devuelve la línea entera (costo bruto 121) → el costo devuelto tiene que informar 100,00 y
     * el costo neto de mercadería, 0.
     *
     * @group reportes
     * @test
     */
    public function la_mercaderia_devuelta_tambien_sale_neta()
    {
        $this->cuenta_legacy_con_la_tilde_prendida();

        $this->fijar_reloj_en('2014-04-10 10:00:00');

        $venta = $this->crear_venta_con_una_linea(121, 169.40, 1);

        $this->devolver_la_linea_entera($venta, 121, 1);

        $estado = $this->pedir_estado_resultados('2014-04-01', '2014-04-30');

        $this->assertEqualsWithDelta(
            100.00,
            (float) $estado['costo_mercaderia_vendida'],
            self::DELTA,
            'Guard del escenario: el CMV tiene que estar neteado antes de mirar la devolución.'
        );

        $this->assertEqualsWithDelta(
            100.00,
            (float) $estado['costo_mercaderia_devuelta'],
            self::DELTA,
            'La devolución trae el mismo costo bruto (121) y se netea igual: 100,00. Si informa '.
            '121,00, el costo neto de mercadería resta de más.'
        );
    }

    /**
     * Test 5 — El drill-down del CMV suma exactamente lo mismo que la tarjeta, y tiene la misma
     * cantidad de renglones.
     *
     * Las dos mitades importan. Si la tarjeta neteara y el detalle no, el usuario abriría el renglón
     * y vería otro número. Y si los joins de alícuota multiplicaran filas, el detalle mostraría la
     * misma línea repetida — por eso los dos van contra una PK.
     *
     * @group reportes
     * @test
     */
    public function el_drill_down_del_cmv_coincide_con_la_tarjeta()
    {
        $this->cuenta_legacy_con_la_tilde_prendida();

        $this->fijar_reloj_en('2014-05-10 10:00:00');

        $this->crear_venta_con_una_linea(121, 169.40, 1);

        $estado = $this->pedir_estado_resultados('2014-05-01', '2014-05-31');

        $detalle = $this->pedir_detalle('costo_mercaderia_vendida', '2014-05-01', '2014-05-31');

        $suma_detalle = 0.0;
        foreach ($detalle['registros'] as $registro) {
            $suma_detalle += (float) $registro['monto'];
        }

        $this->assertEqualsWithDelta(
            100.00,
            (float) $estado['costo_mercaderia_vendida'],
            self::DELTA,
            'Guard del escenario: la tarjeta tiene que estar neteada.'
        );

        $this->assertEqualsWithDelta(
            (float) $estado['costo_mercaderia_vendida'],
            $suma_detalle,
            self::DELTA,
            'La suma de las líneas del drill-down tiene que coincidir con la tarjeta.'
        );

        $this->assertEquals(
            1,
            (int) $detalle['paginacion']['total_registros'],
            'El neteo no puede cambiar la cantidad de renglones del detalle: los joins de alícuota '.
            'van contra una PK y no tienen que multiplicar filas.'
        );
    }

    /**
     * Test 6 — 🔴 EL PUENTE: la tarjeta del reporte y `sales.ganancia` dan el MISMO número.
     *
     * El criterio del crédito fiscal está escrito dos veces por necesidad —una en PHP, para el
     * guardado en vivo de `sales.ganancia`, y otra en SQL, porque el reporte suma decenas de miles
     * de líneas y las pagina—, y lo único que impide que se separen es este test.
     *
     * Con una sola venta en el período y sin devoluciones, las dos cuentas tienen que dar idéntico
     * por álgebra:
     *
     *     resultado bruto = (total − IVA) − costo neto
     *     sales.ganancia  =  total − costo neto − IVA
     *
     * Si alguien toca una sola de las dos ramas (el `CASE` del SQL o `credito_de_la_linea()`), acá
     * aparece la diferencia.
     *
     * @group reportes
     * @test
     */
    public function la_tarjeta_da_lo_mismo_que_la_ganancia_de_la_venta()
    {
        $this->cuenta_legacy_con_la_tilde_prendida();

        $this->fijar_reloj_en('2014-06-10 10:00:00');

        $venta = $this->crear_venta_con_una_linea(121, 169.40, 1);

        $this->facturar($venta, 29.40);

        // Lo que hace en producción MakeAfipTicket::recalcular_ganancia_facturada() al facturar.
        \App\Http\Controllers\Helpers\SaleHelper::set_sale_ganancia(Sale::find($venta->id));

        $ganancia = Sale::find($venta->id)->ganancia;

        $estado = $this->pedir_estado_resultados('2014-06-01', '2014-06-30');

        $this->assertNotNull($ganancia, 'Guard del escenario: la venta tiene que tener ganancia calculada.');

        $this->assertEqualsWithDelta(
            40.00,
            (float) $ganancia,
            self::DELTA,
            'Guard del escenario: la ganancia de la venta tiene que ser 40,00.'
        );

        $this->assertEqualsWithDelta(
            (float) $ganancia,
            (float) $estado['resultado_bruto'],
            self::DELTA,
            'El resultado bruto del reporte (SQL) y sales.ganancia (PHP) salen del MISMO criterio: '.
            'tienen que dar el mismo número sobre la misma venta.'
        );
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Deja al dueño del fixture como una cuenta LEGACY con la tilde vieja prendida, que es la
     * configuración medida en ferretotal el 17/9/2026.
     *
     * @return void
     */
    protected function cuenta_legacy_con_la_tilde_prendida()
    {
        User::where('id', $this->usuario_de_testing()->id)->update([
            'usar_condicion_fiscal_en_costeo' => 0,
            'aplicar_iva_al_costo'            => 1,
            'condicion_iva_precios'           => User::CONDICION_RRII,
        ]);
    }

    /**
     * Usuario dueño del fixture de testing.
     *
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /**
     * Crea una venta cobrada de contado con UNA línea de artículo, con el costo exacto que pide el
     * test (así no depende del estado del fixture).
     *
     * @param  float $costo_real Costo unitario que queda en `article_sale.cost`.
     * @param  float $price_vender Precio unitario de venta.
     * @param  int $amount Unidades.
     * @param  array $atributos_articulo Overrides del artículo (por ejemplo `aplicar_iva`).
     * @return \App\Models\Sale
     */
    protected function crear_venta_con_una_linea($costo_real, $price_vender, $amount, $atributos_articulo = [])
    {
        $articulo = Article::create(array_merge([
            'name'       => 'zz Test cmv neto '.uniqid(),
            'user_id'    => $this->usuario_de_testing()->id,
            'costo_real' => $costo_real,
        ], $atributos_articulo));

        $this->articulos_creados[] = $articulo->id;

        $total = round((float) $price_vender * (int) $amount, 2);

        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            $total,
            ['items' => [[
                'is_article'   => true,
                'id'           => $articulo->id,
                'price_vender' => $price_vender,
                'amount'       => $amount,
                'costo_real'   => $costo_real,
            ]]]
        );

        // Guard del escenario: sin costo persistido en el pivot, el CMV de este test sería 0 y
        // todas las aserciones de abajo pasarían por casualidad.
        $linea = \Illuminate\Support\Facades\DB::table('article_sale')->where('sale_id', $venta->id)->first();

        if (is_null($linea) || is_null($linea->cost) || (float) $linea->cost == 0.0) {
            $this->fail(
                'La venta recién creada no quedó con el costo en article_sale. Sin eso este test no '.
                'mide el costo de mercadería.'
            );
        }

        return $venta;
    }

    /**
     * "Factura" una venta: le crea su `AfipTicket` autorizado.
     *
     * @param  \App\Models\Sale $venta
     * @param  float|null $importe_iva
     * @return \App\Models\AfipTicket
     */
    protected function facturar($venta, $importe_iva)
    {
        $afip_ticket = AfipTicket::create([
            'sale_id'            => $venta->id,
            'resultado'          => 'A',
            'importe_iva'        => $importe_iva,
            'importe_total'      => $venta->total,
            'afip_fecha_emision' => Carbon::now()->format('Y-m-d'),
            'cbte_numero'        => (string) $venta->id,
            'cbte_letra'         => 'A',
            'cbte_tipo'          => 1,
            'cuit_negocio'       => '20000000000',
            'cae'                => '00000000000000',
        ]);

        $this->afip_tickets_sembrados[] = $afip_ticket->id;

        return $afip_ticket;
    }

    /**
     * Devuelve la línea entera de una venta: la nota de crédito más su línea en
     * `article_current_acount`, con la misma forma que deja
     * `CurrentAcountHelper::attachNotaCreditoArticles()` — el costo copiado del pivot de la venta y
     * la alícuota también.
     *
     * @param  \App\Models\Sale $venta
     * @param  float $cost Costo unitario copiado de la línea de venta.
     * @param  int $amount Unidades devueltas.
     * @return \App\Models\CurrentAcount
     */
    protected function devolver_la_linea_entera($venta, $cost, $amount)
    {
        $nota_credito = CurrentAcount::create([
            'detalle'     => 'Nota Credito de test',
            'description' => 'Devolución de test',
            'haber'       => $venta->total,
            'status'      => 'nota_credito',
            'sale_id'     => $venta->id,
            'user_id'     => $this->usuario_de_testing()->id,
            'moneda_id'   => 1,
        ]);

        $this->notas_credito_sembradas[] = $nota_credito->id;

        $articulo = Sale::find($venta->id)->articles()->first();

        $nota_credito->articles()->attach($articulo->id, [
            'amount'         => $amount,
            'price'          => $articulo->pivot->price,
            'cost'           => $cost,
            'discount'       => 0,
            'iva_percentage' => $articulo->pivot->iva_percentage,
        ]);

        return $nota_credito;
    }

    /**
     * Pega contra `GET api/reportes/estado-resultados` y devuelve el array ya decodificado.
     *
     * @param  string $desde
     * @param  string $hasta
     * @return array
     */
    protected function pedir_estado_resultados($desde, $hasta)
    {
        $response = $this->getJson('api/reportes/estado-resultados?desde='.$desde.'&hasta='.$hasta.'&moneda=pesos');

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/estado-resultados devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $body = json_decode($response->getContent(), true);

        if (!isset($body['estado_resultados'])) {
            $this->fail('La respuesta no trae la clave "estado_resultados". Cuerpo completo: '.$response->getContent());
        }

        return $body['estado_resultados'];
    }

    /**
     * Pega contra `GET api/reportes/detalle` (mismo helper que el resto de la carpeta).
     *
     * @param  string $concepto
     * @param  string $desde
     * @param  string $hasta
     * @return array
     */
    protected function pedir_detalle($concepto, $desde, $hasta)
    {
        $response = $this->getJson('api/reportes/detalle?'.http_build_query([
            'concepto' => $concepto,
            'desde'    => $desde,
            'hasta'    => $hasta,
        ]));

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/detalle devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        return json_decode($response->getContent(), true);
    }
}
