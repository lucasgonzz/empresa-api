<?php

namespace Tests\Feature\Comisiones;

use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Models\Seller;
use App\Models\SellerCommission;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * `ComisionesHelper::recalcular_saldos()` se reescribio el 18/9/2026 (mision
 * `recalcular-saldos-comisiones`, diagnostico en produccion de Fenix) para hacer el MISMO calculo
 * en bloque (un SELECT liviano + UPDATE...CASE por tandas) en vez de traer el modelo Eloquent
 * completo de cada fila y guardarlas una por una. Este archivo prueba dos cosas por separado:
 * que el resultado matematico no cambio, y que la cantidad de queries ya no crece 1 a 1 con la
 * cantidad de comisiones.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group comisiones
 */
class Recalcular_Saldos_Performance_Test extends EmpresaTestCase
{
    const DELTA = 0.001;

    /**
     * @return \App\Models\Seller
     */
    protected function crear_seller($sufijo)
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return Seller::create([
            'num'                       => 950000 + $sufijo,
            'name'                      => 'Vendedor test recalcular_saldos '.$sufijo,
            'commission_after_pay_sale' => 0,
            'percentage_commission'     => 10,
            'user_id'                   => $user->id,
        ]);
    }

    /**
     * Crea una comision suelta (sin venta real detras: recalcular_saldos no la necesita, solo lee
     * seller_commissions).
     *
     * @param \App\Models\Seller $seller
     * @param string $status
     * @param float $debe
     * @param float $haber
     * @param int|null $moneda_id
     * @return \App\Models\SellerCommission
     */
    protected function crear_comision($seller, $status, $debe, $haber, $moneda_id = null)
    {
        return SellerCommission::create([
            'seller_id'  => $seller->id,
            'user_id'    => $seller->user_id,
            'status'     => $status,
            'debe'       => $debe,
            'haber'      => $haber,
            'moneda_id'  => $moneda_id,
        ]);
    }

    /**
     * El saldo de cada comision ACTIVE tiene que ser el acumulado (debe - haber) en orden de id,
     * por moneda, exactamente como calcularia a mano el algoritmo viejo. Mezcla a proposito:
     * moneda_id = 1, moneda_id = null (tiene que sumar junto con la de moneda 1: "pesos" es el
     * default de las filas historicas) y moneda_id = 2 (tiene que quedar en un acumulado aparte).
     * Una comision INACTIVE queda intercalada y tiene que salir con saldo NULL sin cortar el
     * acumulado de las que siguen.
     *
     * @test
     */
    public function el_saldo_acumulado_es_correcto_con_varias_monedas_y_comisiones_inactivas()
    {
        $seller = $this->crear_seller(1);

        // Orden de creacion = orden de id, que es el orden que usa recalcular_saldos.
        $c1 = $this->crear_comision($seller, 'active', 100, 0, 1);       // pesos: saldo 100
        $c2 = $this->crear_comision($seller, 'active', 50, 0, null);     // pesos (null=1): saldo 150
        $c3 = $this->crear_comision($seller, 'inactive', 999, 0, 1);     // no entra al ledger
        $c4 = $this->crear_comision($seller, 'active', 0, 30, 1);        // pesos: saldo 120
        $c5 = $this->crear_comision($seller, 'active', 200, 0, 2);       // moneda 2: saldo 200
        $c6 = $this->crear_comision($seller, 'active', 0, 50, 2);        // moneda 2: saldo 150

        ComisionesHelper::recalcular_saldos($seller->id, 1);
        ComisionesHelper::recalcular_saldos($seller->id, 2);

        $this->assertEqualsWithDelta(100.00, (float) $c1->fresh()->saldo, self::DELTA);
        $this->assertEqualsWithDelta(150.00, (float) $c2->fresh()->saldo, self::DELTA);
        $this->assertNull($c3->fresh()->saldo, 'Una comision inactive no puede tener saldo.');
        $this->assertEqualsWithDelta(120.00, (float) $c4->fresh()->saldo, self::DELTA);
        $this->assertEqualsWithDelta(200.00, (float) $c5->fresh()->saldo, self::DELTA);
        $this->assertEqualsWithDelta(150.00, (float) $c6->fresh()->saldo, self::DELTA);
    }

    /**
     * Corriendo recalcular_saldos() dos veces seguidas sobre el mismo vendedor (sin cambios en el
     * medio) el resultado tiene que ser identico — es la garantia minima de que el UPDATE en
     * bloque no arrastra ningun estado entre corridas.
     *
     * @test
     */
    public function correrlo_dos_veces_seguidas_da_el_mismo_resultado()
    {
        $seller = $this->crear_seller(2);

        $c1 = $this->crear_comision($seller, 'active', 300, 100, 1);
        $c2 = $this->crear_comision($seller, 'active', 0, 50, 1);

        ComisionesHelper::recalcular_saldos($seller->id, 1);
        $saldo_c1_primera = (float) $c1->fresh()->saldo;
        $saldo_c2_primera = (float) $c2->fresh()->saldo;

        ComisionesHelper::recalcular_saldos($seller->id, 1);

        $this->assertEqualsWithDelta($saldo_c1_primera, (float) $c1->fresh()->saldo, self::DELTA);
        $this->assertEqualsWithDelta($saldo_c2_primera, (float) $c2->fresh()->saldo, self::DELTA);
        $this->assertEqualsWithDelta(200.00, $saldo_c1_primera, self::DELTA);
        $this->assertEqualsWithDelta(150.00, $saldo_c2_primera, self::DELTA);
    }

    /**
     * La cantidad de queries no puede crecer 1 a 1 con la cantidad de comisiones: con la version
     * vieja (Eloquent ->get() + ->save() por fila) 200 comisiones activas hacian ~200 queries de
     * escritura; con esta version, un SELECT liviano + un UPDATE por tanda de 500 ids. Comparando
     * 200 (1 tanda) contra 2.000 (4 tandas) el crecimiento de queries tiene que ser MUCHO menor
     * que el crecimiento de filas (10x).
     *
     * @test
     */
    public function la_cantidad_de_queries_crece_por_tandas_no_una_por_fila()
    {
        $seller_chico = $this->crear_seller(3);
        for ($i = 0; $i < 200; $i++) {
            $this->crear_comision($seller_chico, 'active', 10, 0, 1);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        ComisionesHelper::recalcular_saldos($seller_chico->id, 1);
        $queries_200 = count(DB::getQueryLog());

        $seller_grande = $this->crear_seller(4);
        for ($i = 0; $i < 2000; $i++) {
            $this->crear_comision($seller_grande, 'active', 10, 0, 1);
        }

        DB::flushQueryLog();
        ComisionesHelper::recalcular_saldos($seller_grande->id, 1);
        $queries_2000 = count(DB::getQueryLog());

        DB::disableQueryLog();

        // 2000 comisiones = 4 tandas de UPDATE + 1 SELECT + 1 UPDATE de las inactive (vacio pero
        // se ejecuta igual) = manejable en un puñado de queries, muy lejos de una por fila.
        $this->assertLessThanOrEqual(10, $queries_2000, 'recalcular_saldos volvio a crecer 1 query por comision.');

        // El crecimiento de queries tiene que ser sub-lineal: 10x mas filas, mucho menos que 10x mas queries.
        $this->assertLessThan($queries_200 * 10, $queries_2000);

        // Verificacion de correccion sobre el lote grande: la ultima comision tiene que arrastrar
        // el acumulado completo (2000 * 10 = 20000), no solo el de su propia tanda.
        $ultima = SellerCommission::where('seller_id', $seller_grande->id)->orderBy('id', 'DESC')->first();
        $this->assertEqualsWithDelta(20000.00, (float) $ultima->saldo, self::DELTA);
    }
}
