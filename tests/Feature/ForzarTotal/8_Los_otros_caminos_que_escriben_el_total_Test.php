<?php

namespace Tests\Feature\ForzarTotal;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Models\AfipInformation;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Sale;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 8 — LOS OTROS CAMINOS QUE ESCRIBEN `sales.total` O `budgets.total`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA FAMILIA DE ERROR QUE ESTE ARCHIVO PERSIGUE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Es una sola, y aparecio tres veces en esta misma mision:
 *
 *      un camino que escribe el TOTAL (ya forzado) sin escribir el MONTO que lo explica.
 *
 *  El sintoma cambia segun donde pase, y ninguno de los tres se anuncia solo:
 *
 *   - Duplicar un presupuesto: `getTotal()` suma 0 de ajuste contra un `total` que lo trae
 *     adentro, la diferencia se pasa del margen de 3 y el guardado muere con 500. Con un monto de
 *     3 pesos o menos ni siquiera muere: guarda un duplicado incoherente, en silencio.
 *   - Confirmar un presupuesto: la venta nacia sin `sub_total`, y los renglones del comprobante
 *     que lo leen imprimian numeros sin sentido.
 *   - Consolidar facturacion: la venta consolidada suma totales ya forzados pero copia los precios
 *     crudos, asi que a un Responsable Inscripto se le factura el bruto.
 *
 *  Por eso los cuatro tests de acá miden ESTADO GUARDADO, no valores de retorno: lo que importa es
 *  que la fila quede coherente consigo misma.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group presupuestos
 * @group forzar_total
 */
class Los_otros_caminos_que_escriben_el_total_Test extends ForzarTotalTestCase
{
    /** Ids de `budget_statuses`, tabla global sembrada por `BudgetStatusSeeder`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /** Slug de la extension que gatea el duplicado de presupuestos. */
    const EXTENCION_DUPLICAR = 'duplicar_presupuestos';

    /**
     * Siembra los estados de presupuesto (la tabla global puede venir vacia en la base del slot) y
     * le da al comercio la extension de duplicar.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $estados = [
            self::ESTADO_SIN_CONFIRMAR => 'Sin confirmar',
            self::ESTADO_CONFIRMADO    => 'Confirmado',
        ];

        foreach ($estados as $id => $name) {

            if (is_null(BudgetStatus::find($id))) {

                $estado = new BudgetStatus();
                $estado->id = $id;
                $estado->name = $name;
                $estado->save();
            }
        }
    }

    /**
     * Le da al comercio la extension `duplicar_presupuestos`.
     *
     * ⚠️ `extencion_empresas` viene VACIA en la base del slot, asi que la fila del catalogo hay que
     * crearla. Y `ExtencionEmpresa` no declara `$fillable`, por eso `forceCreate()`.
     *
     * @return void
     */
    protected function dar_extencion_duplicar()
    {
        $extencion = \App\Models\ExtencionEmpresa::where('slug', self::EXTENCION_DUPLICAR)->first();

        if (is_null($extencion)) {
            $extencion = \App\Models\ExtencionEmpresa::forceCreate([
                'slug' => self::EXTENCION_DUPLICAR,
                'name' => 'Duplicar presupuestos',
            ]);
        }

        $user = $this->comercio();
        $user->extencions()->syncWithoutDetaching([$extencion->id]);
        $user->load('extencions');
    }

    /**
     * Crea un presupuesto forzado directo en la base, con un renglon del articulo centinela.
     *
     * @param  float  $monto
     * @return \App\Models\Budget
     */
    protected function presupuesto_forzado($monto)
    {
        $budget = Budget::create([
            'num'                              => 9100 + rand(1, 800),
            'user_id'                          => $this->comercio()->id,
            'client_id'                        => $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC)->id,
            'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
            'total'                            => self::BRUTO + $monto,
            'forzar_total_monto'               => $monto,
            'discount_stock'                   => 0,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => 0,
            'moneda_id'                        => 1,
        ]);

        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $budget->articles()->attach($articulo->id, [
            'amount' => 1,
            'price'  => self::BRUTO,
        ]);

        return $budget->fresh();
    }

    /**
     * Test 1 — DUPLICAR un presupuesto forzado no revienta y el duplicado queda coherente.
     *
     * Sin copiar `forzar_total_monto`, `BudgetController::duplicate()` compara `getTotal()` (que
     * sumaria 0 de ajuste) contra el `total` copiado (que lo trae adentro) y corta con 500.
     *
     * @group forzar_total
     * @test
     */
    public function duplicar_un_presupuesto_forzado_no_revienta_y_copia_el_monto()
    {
        $this->dar_extencion_duplicar();

        $origen = $this->presupuesto_forzado(self::MONTO);

        $nuevo_id = $this->postJson('api/budget/'.$origen->id.'/duplicate')
                            ->assertStatus(201)
                            ->json('model.id');

        $duplicado = Budget::find($nuevo_id);

        $this->assertNotNull($duplicado, 'El duplicado tiene que existir.');

        $this->assertEqualsWithDelta(
            self::MONTO,
            (float) $duplicado->forzar_total_monto,
            self::DELTA,
            'el duplicado tiene que llevarse el monto del forzado'
        );

        $this->assertEqualsWithDelta(
            self::FORZADO,
            (float) $duplicado->total,
            self::DELTA,
            'y su total tiene que seguir siendo el forzado'
        );

        /*
         * 🔴 La coherencia interna, que es lo que el 500 estaba protegiendo: lo que dice la fila
         * tiene que ser lo mismo que calculan sus renglones.
         */
        $this->assertEqualsWithDelta(
            (float) $duplicado->total,
            (float) BudgetHelper::getTotal($duplicado),
            self::DELTA,
            'el total guardado y el que calculan los renglones tienen que coincidir'
        );
    }

    /**
     * Test 2 — EL CASO QUE NO FALLA, que es el peor. Con un monto chico la diferencia entra en el
     * margen de tolerancia de `duplicate()`, asi que sin el arreglo el duplicado se guardaba
     * incoherente y en silencio: `total` forzado y `forzar_total_monto` en null.
     *
     * Y esa incoherencia despues viaja a la venta por `BudgetHelper::saveSale()`.
     *
     * @group forzar_total
     * @test
     */
    public function un_monto_chico_no_puede_colarse_sin_copiarse()
    {
        $this->dar_extencion_duplicar();

        // -2 entra en el margen de 3 de duplicate(): no dispara el 500 que protegeria al resto.
        $origen = $this->presupuesto_forzado(-2.00);

        $nuevo_id = $this->postJson('api/budget/'.$origen->id.'/duplicate')
                            ->assertStatus(201)
                            ->json('model.id');

        $this->assertEqualsWithDelta(
            -2.00,
            (float) Budget::find($nuevo_id)->forzar_total_monto,
            self::DELTA,
            'un monto que no dispara el 500 tiene que copiarse igual: si no, el duplicado queda incoherente sin que nada avise'
        );
    }

    /**
     * Test 3 — al CONFIRMAR, la venta nace con `sub_total`.
     *
     * `BudgetHelper::saveSale()` no escribia esa columna: la venta nacida de un presupuesto quedaba
     * con `sub_total` en null y nadie se enteraba porque nadie lo leia. Los comprobantes SI lo
     * leen, y esta mision los hizo leerlo: con null adentro el ticket de 80mm arranca en 0 e
     * imprime "Total $0", "Ajuste -$12   $-12" y "Total sin descuentos: $-12".
     *
     * @group forzar_total
     * @test
     */
    public function la_venta_nacida_de_un_presupuesto_tiene_sub_total()
    {
        $budget = $this->presupuesto_forzado(self::MONTO);

        $this->postJson('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');

        $this->assertNotNull(
            $sale->sub_total,
            'la venta nacida de un presupuesto no puede quedar con sub_total en null: los comprobantes lo leen'
        );

        $this->assertEqualsWithDelta(
            self::BRUTO,
            (float) $sale->sub_total,
            self::DELTA,
            'el sub_total tiene que ser el bruto de los renglones (4.012), que es lo mismo que manda VENDER en el alta'
        );

        /*
         * La coherencia que hace cerrar el desglose del comprobante:
         * sub_total + monto = total.
         */
        $this->assertEqualsWithDelta(
            (float) $sale->total,
            (float) $sale->sub_total + (float) $sale->forzar_total_monto,
            self::DELTA,
            'sub_total + monto tiene que dar el total: es lo que el ticket imprime renglon por renglon'
        );
    }

    /**
     * Test 4 — CONSOLIDAR FACTURACION: la venta consolidada se lleva la suma de los montos.
     *
     * `total` suma los `sales.total` de las originales, que ya vienen forzados, pero los renglones
     * se copian con su precio CRUDO. Sin el monto, la consolidada queda diciendo 8.000 con
     * renglones que suman 8.024: el factor de AFIP da 1 y a un Responsable Inscripto se le facturan
     * los 8.024 — mas de lo que el cliente pago, en un comprobante fiscal.
     *
     * @group forzar_total
     * @test
     */
    public function la_venta_consolidada_se_lleva_la_suma_de_los_montos()
    {
        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);

        $afip_information = AfipInformation::where('user_id', $this->comercio()->id)->first();

        $this->assertNotNull($afip_information, 'Falta la configuracion de AFIP del fixture.');

        /** Dos ventas forzadas, cada una de 4.012 cobrada a 4.000. */
        $ventas = [];

        foreach ([0, 1] as $i) {

            $sale = $this->crear_venta_en_base([
                'client_id'                  => $cliente->id,
                'omitir_en_cuenta_corriente' => 1,
            ]);

            $this->enganchar_articulo($sale, TestingFerreteriaSeeder::ARTICULO_CENTINELA, self::BRUTO, 1);

            $ventas[] = $sale->fresh();
        }

        $consolidada = ConsolidarFacturacionHelper::consolidar(
            [$ventas[0]->id, $ventas[1]->id],
            $cliente->id,
            $this->comercio()->id,
            $afip_information->id,
            1,
            false,
            [],
            // 🔴 Sin emitir: emitir pegaria contra ARCA de verdad.
            false
        );

        $fila = Sale::find($consolidada->id);

        $this->assertEqualsWithDelta(
            self::MONTO * 2,
            (float) $fila->forzar_total_monto,
            self::DELTA,
            'el monto de la consolidada tiene que ser la SUMA de los montos de las ventas originales'
        );

        $this->assertEqualsWithDelta(
            self::FORZADO * 2,
            (float) $fila->total,
            self::DELTA,
            'y su total, la suma de los totales forzados'
        );

        /*
         * 🔴 LA COHERENCIA QUE IMPORTA: el total menos el monto tiene que dar lo que suman los
         * renglones copiados. Es exactamente la cuenta que hace el factor de AFIP para decidir
         * cuanto escalar, y es la que estaba rota.
         */
        $this->assertEqualsWithDelta(
            self::BRUTO * 2,
            (float) $fila->total - (float) $fila->forzar_total_monto,
            self::DELTA,
            'total - monto tiene que dar el bruto de los renglones copiados (8.024)'
        );
    }
}
