<?php

namespace Tests\Feature\Sales;

use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\EmpresaTestCase;

/**
 * Misión saneo-ganancia-ventas, hallazgo 1 (17/9/2026) — la ganancia de una cuenta cuyo costo está
 * guardado BRUTO.
 *
 * La fórmula que estrenó la misión (`total − total_cost − IVA declarado`) da por sentado que
 * `total_cost` es NETO. En una cuenta **legacy** (`usar_condicion_fiscal_en_costeo = 0`) con la
 * tilde vieja `aplicar_iva_al_costo = 1` no lo es: el IVA se le suma al costo ANTES del margen, así
 * que restarle además el IVA débito completo lo descuenta DOS VECES y el número nuevo queda peor
 * que el viejo.
 *
 * Los números de todos los tests de este archivo salen del mismo escenario, para que se puedan
 * comparar de un vistazo: costo neto 100, margen 40 %, IVA 21 % → precio final 169,40 y un
 * comprobante que declara 29,40.
 *
 * | Cuenta | `total_cost` | Facturada da | Sin comprobante da |
 * |---|---|---|---|
 * | Migrada (RI) | 100 (neto) | 40,00 | 69,40 |
 * | Legacy con la tilde prendida | 121 (BRUTO) | **40,00** (antes: 19,00) | **69,40** (antes: 48,40) |
 * | Monotributista migrado | 121 (BRUTO) | **48,40** — no se le netea NADA | 48,40 |
 *
 * 🔴 Los dos Monotributistas del sistema tienen el costo bruto y NO se netean: el MT no recupera el
 * IVA de sus compras, ese es su costo real. `monotributista_migrado_no_se_netea()` es el test que
 * se pone rojo si alguien "simplifica" el predicado a "costo bruto ⇒ netear".
 *
 * @group sales
 */
class Ganancia_Con_Costo_Bruto_Test extends EmpresaTestCase
{
    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la suite). */
    const DELTA = 0.01;

    /**
     * Ids de los artículos creados por este archivo, para borrarlos en el tearDown.
     *
     * @var array<int,int>
     */
    protected $articulos_creados = [];

    /**
     * Borra los artículos que creó el test antes del rollback de `DatabaseTransactions`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->articulos_creados) >= 1) {
            Article::whereIn('id', $this->articulos_creados)->forceDelete();
        }

        parent::tearDown();
    }

    /**
     * Test 1 — Cuenta legacy con la tilde prendida, venta FACTURADA: no se descuenta el IVA dos
     * veces.
     *
     * Costo bruto 121 (neto 100 + 21 de IVA de compra), precio 169,40, comprobante con 29,40 de IVA.
     * El IVA de compra es crédito fiscal recuperable: se lo devuelve al costo antes de restar.
     *
     *     169,40 − (121 − 21) − 29,40 = 40,00
     *
     * Antes de este arreglo daba 19,00: un −52,5 %. Y el error es constante en pesos (21 % del costo
     * neto), así que como porcentaje crece cuando el margen baja; con margen ≤ 21 % informa ganancia
     * NEGATIVA a un negocio que gana plata, que es el síntoma con el que arrancó esta misión.
     *
     * @group sales
     * @test
     */
    public function cuenta_legacy_con_costo_bruto_no_descuenta_el_iva_dos_veces()
    {
        $this->cuenta_legacy_con_la_tilde_prendida();

        $venta = $this->crear_venta_con_una_linea(121, 169.40, 1);

        $this->facturar($venta, 29.40);

        $this->assertEqualsWithDelta(
            40.00,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'El costo de esta cuenta está guardado BRUTO (121). Su IVA de compra es crédito fiscal '.
            'recuperable, no costo: la ganancia es 169,40 - 100 - 29,40 = 40,00. Si da 19,00, se está '.
            'descontando el IVA dos veces.'
        );
    }

    /**
     * Test 2 — La MISMA cuenta legacy, venta SIN comprobante.
     *
     * No declaró IVA débito (el IVA cobrado se lo queda la casa), pero el crédito fiscal de la
     * compra existe igual: es una propiedad del negocio, no de la venta.
     *
     *     169,40 − (121 − 21) − 0 = 69,40
     *
     * Antes daba 48,40. Que el número coincida con el de una cuenta migrada haciendo la misma venta
     * (test 4, sin facturar) es la prueba de que el saneo dejó a las dos cuentas midiendo lo mismo.
     *
     * @group sales
     * @test
     */
    public function cuenta_legacy_con_costo_bruto_sin_comprobante()
    {
        $this->cuenta_legacy_con_la_tilde_prendida();

        $venta = $this->crear_venta_con_una_linea(121, 169.40, 1);

        $this->assertEqualsWithDelta(
            69.40,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Sin comprobante no hay IVA débito que restar, pero el crédito fiscal del costo sigue '.
            'existiendo: 169,40 - 100 - 0 = 69,40. Si da 48,40, el costo bruto no se está neteando.'
        );
    }

    /**
     * Test 3 — Monotributista migrado: su costo también está BRUTO y NO se le netea nada.
     *
     * 🔴 Es el test que protege el caso que más fácil se rompe. El MT migrado guarda el costo tal
     * cual lo carga —con el IVA adentro, desde la misión `iva-fuera-del-costeo-monotributista`— pero
     * NO recupera ese IVA: lo que le factura el proveedor es su costo real. Su Factura C declara
     * `importe_iva = 0`, así que su ganancia ya daba bien antes de esta misión y tiene que seguir
     * dando lo mismo:
     *
     *     169,40 − 121 − 0 = 48,40
     *
     * Si alguien escribiera el predicado como "costo bruto ⇒ netear", acá daría 69,40 y le estaría
     * inventando al monotributista un 21 % de ganancia que nunca existió.
     *
     * @group sales
     * @test
     */
    public function monotributista_migrado_no_se_netea()
    {
        $this->cuenta_monotributista_migrada();

        $venta = $this->crear_venta_con_una_linea(121, 169.40, 1);

        $this->assertEqualsWithDelta(
            48.40,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'El monotributista no recupera el IVA de sus compras: 121 ES su costo. Sin comprobante la '.
            'ganancia es 169,40 - 121 = 48,40.'
        );

        // Factura C: no discrimina IVA, así que declara 0 y el número no se mueve.
        $this->facturar($venta, 0.00, 'A', 11);

        $this->assertEqualsWithDelta(
            48.40,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Con Factura C (importe_iva 0) la ganancia del monotributista tiene que seguir siendo 48,40. '.
            'Si da 69,40, se le está neteando un IVA que él no recupera.'
        );
    }

    /**
     * Test 4 — Cuenta migrada (el caso normal): costo NETO 100, nada cambia respecto de hoy.
     *
     * Es el test de no-regresión de todo este arreglo: la enorme mayoría de las cuentas tiene el
     * costo neto y el crédito fiscal a devolver es 0.
     *
     * @group sales
     * @test
     */
    public function cuenta_migrada_con_costo_neto_no_cambia()
    {
        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $this->assertEqualsWithDelta(
            69.40,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Sin comprobante: 169,40 - 100.'
        );

        $this->facturar($venta, 29.40);

        $this->assertEqualsWithDelta(
            40.00,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Facturada: 169,40 - 100 - 29,40. El costo ya era neto, no hay nada que devolverle.'
        );
    }

    /**
     * Test 5 — Que la CUENTA tenga el costo bruto no significa que TODAS sus líneas lo tengan.
     *
     * `ArticlePricesHelper::aplicar_iva()` sólo le suma el IVA al costo si el artículo tiene
     * `aplicar_iva` prendido. Un artículo con esa tilde apagada, en la misma cuenta legacy, tiene el
     * costo NETO — y su pivot igual guarda `iva_percentage = 21`, porque
     * `SaleHelper::get_iva_percentage_for_pivot()` persiste la alícuota del artículo sin mirar
     * `aplicar_iva`. O sea que un neteo hecho por venta, mirando sólo la alícuota del pivot, le
     * sacaría un 21 % que este costo nunca tuvo.
     *
     * Costo neto 100, precio 140 (sin IVA encima, que es lo que hace un artículo así), sin
     * comprobante → 40,00.
     *
     * @group sales
     * @test
     */
    public function linea_de_articulo_sin_aplicar_iva_no_se_netea_aunque_la_cuenta_sea_legacy()
    {
        $this->cuenta_legacy_con_la_tilde_prendida();

        $venta = $this->crear_venta([
            ['costo_real' => 100, 'price_vender' => 140.00, 'amount' => 1, 'aplicar_iva' => 0],
        ]);

        $this->assertEqualsWithDelta(
            40.00,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Este artículo tiene aplicar_iva apagado: a su costo nunca se le sumó IVA, así que no hay '.
            'nada que devolverle. 140 - 100 = 40. Si da 57,36, se está neteando por venta en vez de '.
            'por línea.'
        );
    }

    /**
     * Test 6 — El backfill SE NIEGA A CORRER en una cuenta legacy con la tilde prendida.
     *
     * 🔴 El motivo no es técnico, es que el dato no existe: para una venta VIEJA no hay forma de
     * saber en qué base estaba guardado su costo, porque **el cambio de esa tilde no se persiste en
     * ninguna tabla** (dispara un recálculo y un broadcast, y nada más). El backfill tendría que
     * elegir entre dos números que pueden estar los dos mal. En vez de escribir uno peor que el que
     * había, para.
     *
     * Se ensucia la columna a propósito antes de correr: si el comando escribiera igual, la basura
     * desaparecería y el test se pondría rojo.
     *
     * @group sales
     * @test
     */
    public function el_backfill_se_niega_a_correr_en_una_cuenta_legacy_con_el_costo_bruto()
    {
        $this->cuenta_legacy_con_la_tilde_prendida();

        $venta = $this->crear_venta_con_una_linea(121, 169.40, 1);

        Sale::where('id', $venta->id)->update(['ganancia' => -999999]);

        $salida = Artisan::call('set_sales_ganancia', ['--chunk' => 10]);

        $this->assertEquals(
            1,
            $salida,
            'El backfill tiene que devolver 1 (frenado) en una cuenta legacy con aplicar_iva_al_costo '.
            'prendida. Devolvió '.$salida.'.'
        );

        $this->assertEqualsWithDelta(
            -999999,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'El backfill frenado no puede haber escrito una sola fila.'
        );
    }

    /**
     * Test 7 — `--force` saltea la guarda, y el número que escribe es el mismo que el del guardado
     * en vivo.
     *
     * La salida de escape existe para cuando alguien, sabiendo que a las ventas viejas se les va a
     * aplicar el criterio de hoy, igual necesita el histórico. Lo que no puede pasar es que el
     * `--force` escriba una fórmula distinta de la del guardado en vivo.
     *
     * @group sales
     * @test
     */
    public function el_backfill_con_force_corre_y_escribe_el_mismo_numero_que_el_guardado_en_vivo()
    {
        $this->cuenta_legacy_con_la_tilde_prendida();

        $venta = $this->crear_venta_con_una_linea(121, 169.40, 1);

        $this->facturar($venta, 29.40);

        $en_vivo = (float) Sale::find($venta->id)->ganancia;

        Sale::where('id', $venta->id)->update(['ganancia' => -999999]);

        $salida = Artisan::call('set_sales_ganancia', ['--chunk' => 10, '--force' => true]);

        $this->assertEquals(0, $salida, 'Con --force el backfill tiene que correr. Devolvió '.$salida.'.');

        $this->assertEqualsWithDelta(
            $en_vivo,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'El backfill forzado tiene que dejar el mismo número que el guardado en vivo ('.$en_vivo.').'
        );

        $this->assertEqualsWithDelta(
            40.00,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Y ese número es el correcto de la cuenta con costo bruto: 169,40 - 100 - 29,40.'
        );
    }

    /**
     * Test 8 — El backfill SÍ corre en una cuenta migrada.
     *
     * La contracara del test 6: la guarda tiene que frenar el caso ambiguo y sólo ése. Una cuenta
     * migrada tiene el costo neto, no hay ambigüedad histórica y el backfill es exactamente tan
     * válido como antes de esta misión.
     *
     * @group sales
     * @test
     */
    public function el_backfill_corre_normalmente_en_una_cuenta_migrada()
    {
        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        Sale::where('id', $venta->id)->update(['ganancia' => -999999]);

        $salida = Artisan::call('set_sales_ganancia', ['--chunk' => 10]);

        $this->assertEquals(0, $salida, 'En una cuenta migrada el backfill tiene que correr. Devolvió '.$salida.'.');

        $this->assertEqualsWithDelta(
            69.40,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'El backfill tiene que haber recalculado la ganancia (169,40 - 100), pisando la basura.'
        );
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Deja al dueño del fixture como una cuenta LEGACY con la tilde vieja prendida, que es la
     * configuración medida en ferretotal el 17/9/2026.
     *
     * Se escribe por query builder y no por `UserController@update` a propósito: ese endpoint
     * dispara el recálculo de precios de todo el catálogo, que acá no aporta nada y tardaría.
     *
     * @return void
     */
    protected function cuenta_legacy_con_la_tilde_prendida()
    {
        User::where('id', $this->user_id())->update([
            'usar_condicion_fiscal_en_costeo' => 0,
            'aplicar_iva_al_costo'            => 1,
            'condicion_iva_precios'           => User::CONDICION_RRII,
        ]);
    }

    /**
     * Deja al dueño del fixture como Monotributista MIGRADO: su costo se guarda tal cual lo carga
     * (bruto) y el IVA no participa del precio en ningún punto.
     *
     * @return void
     */
    protected function cuenta_monotributista_migrada()
    {
        User::where('id', $this->user_id())->update([
            'usar_condicion_fiscal_en_costeo' => 1,
            'condicion_iva_precios'           => User::CONDICION_MT,
        ]);
    }

    /**
     * Id del usuario dueño del fixture de testing.
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Crea una venta de una sola línea por el endpoint real y devuelve el modelo ya guardado.
     *
     * @param  float $costo_real Costo unitario del artículo, tal cual queda en `articles.costo_real`.
     * @param  float $price_vender Precio unitario de venta (CON IVA, como lo manda Vender).
     * @param  int $amount Unidades.
     * @return \App\Models\Sale
     */
    protected function crear_venta_con_una_linea($costo_real, $price_vender, $amount)
    {
        return $this->crear_venta([
            ['costo_real' => $costo_real, 'price_vender' => $price_vender, 'amount' => $amount],
        ]);
    }

    /**
     * Crea una venta por `POST api/sale` con una línea por cada entrada de `$lineas`, cada una con
     * su propio artículo recién creado.
     *
     * @param  array<int,array> $lineas Cada una con `costo_real`, `price_vender`, `amount` y,
     *                                  opcionalmente, `aplicar_iva`.
     * @return \App\Models\Sale
     */
    protected function crear_venta($lineas)
    {
        $items = [];
        $total = 0.0;

        foreach ($lineas as $linea) {

            $atributos = [
                'name'       => 'zz Test ganancia costo bruto '.uniqid(),
                'user_id'    => $this->user_id(),
                'costo_real' => $linea['costo_real'],
            ];

            if (isset($linea['aplicar_iva'])) {
                $atributos['aplicar_iva'] = $linea['aplicar_iva'];
            }

            $articulo = Article::create($atributos);

            $this->articulos_creados[] = $articulo->id;

            $items[] = [
                'is_article'   => true,
                'id'           => $articulo->id,
                'price_vender' => $linea['price_vender'],
                'amount'       => $linea['amount'],
                'costo_real'   => $linea['costo_real'],
            ];

            $total += (float) $linea['price_vender'] * (int) $linea['amount'];
        }

        $total = round($total, 2);

        $response = $this->postJson('api/sale', [
            'client_id'                        => null,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 1,
            'to_check'                         => 0,
            'current_acount_payment_method_id' => null,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                      => null,
            'sub_total'                        => $total,
            'total'                            => $total,
            'terminada'                        => 1,
            'seller_id'                        => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'caja_id'                          => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => $items,
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail('POST api/sale devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $venta = Sale::find($response->json('model.id'));

        // Guard del escenario: si el costo de la línea no se persistió, el test no estaría probando
        // la fórmula sino un null. Se para y se reporta, no se ajusta la aserción.
        if (is_null($venta->total_cost) || (float) $venta->total_cost == 0.0) {
            $this->fail(
                'La venta recién creada quedó con total_cost '.var_export($venta->total_cost, true).
                '. Sin costo persistido este test no prueba nada.'
            );
        }

        return $venta;
    }

    /**
     * "Factura" una venta: crea su `AfipTicket` y recalcula la ganancia, que es lo que hace en
     * producción `MakeAfipTicket::recalcular_ganancia_facturada()` cuando ARCA contesta.
     *
     * @param  \App\Models\Sale $venta
     * @param  float|null $importe_iva IVA declarado por el comprobante.
     * @param  string $resultado 'A' autorizado.
     * @param  int $cbte_tipo Código de ARCA (1 Factura A, 11 Factura C).
     * @return \App\Models\AfipTicket
     */
    protected function facturar($venta, $importe_iva, $resultado = 'A', $cbte_tipo = 1)
    {
        $afip_ticket = AfipTicket::create([
            'sale_id'            => $venta->id,
            'resultado'          => $resultado,
            'importe_iva'        => $importe_iva,
            'importe_total'      => $venta->total,
            'afip_fecha_emision' => Carbon::now()->format('Y-m-d'),
            'cbte_numero'        => (string) $venta->id,
            'cbte_letra'         => $cbte_tipo == 11 ? 'C' : 'A',
            'cbte_tipo'          => $cbte_tipo,
            'cuit_negocio'       => '20000000000',
            'cae'                => '00000000000000',
        ]);

        \App\Http\Controllers\Helpers\SaleHelper::set_sale_ganancia(Sale::find($venta->id));

        return $afip_ticket;
    }
}
