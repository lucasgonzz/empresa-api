<?php

namespace Tests\Feature\Comisiones;

use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Client;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerCommission;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Archivo 1 — comision del motor GENERICO (ComisionPorcentajeGeneral) con el check nuevo
 * `commission_with_iva` del vendedor (mision comision-vendedor-liquidacion-iva, 14/9/2026).
 *
 * Con el check ACTIVADO (default, valor 1) el comportamiento no cambia respecto de antes de esta
 * mision: comision sobre el TOTAL FINAL de la venta, con IVA incluido (decision de Lucas,
 * 29/7/2026). Con el check DESACTIVADO (0) se resta el IVA de cada ARTICULO vendido —usando el
 * precio unitario CONGELADO en el pivot al momento de la venta (`price`/`price_sin_iva`), no la
 * alicuota actual del articulo— antes de aplicar el porcentaje de comision.
 *
 * Los tres renglones de la venta de prueba cubren los tres casos de `article_sale.iva_percentage`
 * que conviven en esa columna (ver TestingFerreteriaSeeder): un articulo al 21%, uno al 10.5% y
 * uno Exento —que no tiene que aportar IVA a restar—.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group comisiones
 */
class Comision_Con_Y_Sin_Iva_Test extends EmpresaTestCase
{
    /**
     * Delta para comparaciones de plata.
     */
    const DELTA = 0.01;

    /**
     * Arma una venta con tres renglones de articulos del fixture (21%, 10.5% y Exento),
     * asignada al vendedor pasado, y dispara el motor de comisiones exactamente como lo hace
     * `SaleHelper` en produccion (`new ComisionesHelper($sale)` + `crear_comision()`).
     *
     * Precios elegidos para que `price_sin_iva` de cada renglon cierre exacto, sin arrastre de
     * redondeo: Martillo acero (21%) $1210 -> $1000 neto; Cuchilla (10.5%) $552,5 -> $500 neto;
     * Cuchara (Exento) $300 -> $300 neto (no se divide).
     *
     * @param \App\Models\Seller $seller
     * @return \App\Models\Sale
     */
    protected function crear_venta_con_seller($seller)
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        // El usuario del fixture tiene comision_funcion = 'distri_creo' (es el motor personalizado
        // de ese cliente puntual, ver DistriCreoComision) — para probar el motor GENERICO
        // (ComisionPorcentajeGeneral, el unico que toca esta mision) hay que forzarlo a null acá.
        // Se hace por query directa (no $user->save()) para no disparar de mas, y queda revertido
        // solo al terminar el test porque EmpresaTestCase corre dentro de una transaccion.
        DB::table('users')->where('id', $user->id)->update(['comision_funcion' => null]);

        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CONTADO)->first();
        $this->assertNotNull($client, 'Falta el cliente del fixture.');

        // [nombre del articulo del fixture, precio unitario CON iva, cantidad]
        $renglones = [
            ['Martillo acero', 1210,  2],
            ['Cuchilla',       552.5, 1],
            ['Cuchara',        300,   1],
        ];

        $total = 0;
        foreach ($renglones as $renglon) {
            $total += $renglon[1] * $renglon[2];
        }

        $sale = Sale::create([
            'user_id'                          => $user->id,
            'client_id'                        => $client->id,
            'seller_id'                        => $seller->id,
            'omitir_en_cuenta_corriente'       => 0,
            'save_current_acount'              => 0,
            'terminada'                        => 1,
            'is_cerrada'                       => 0,
            'sub_total'                        => $total,
            'total'                            => $total,
            'moneda_id'                        => 1,
            'descuento'                        => 0,
            'aplicar_recargos_directo_a_items' => 0,
        ]);

        foreach ($renglones as $renglon) {
            list($nombre, $price, $amount) = $renglon;

            $articulo = $this->articulo($nombre);
            $this->assertNotNull($articulo, 'Falta el articulo "'.$nombre.'" del fixture.');
            $this->assertNotNull($articulo->iva, 'El articulo "'.$nombre.'" no tiene IVA cargado.');

            $price_sin_iva = SaleHelper::get_price_sin_iva(['id' => $articulo->id], $price);

            $sale->articles()->attach($articulo->id, [
                'amount'         => $amount,
                'price'          => $price,
                'price_sin_iva'  => $price_sin_iva,
                'iva_percentage' => $articulo->iva->percentage,
            ]);
        }

        $sale->refresh();

        (new ComisionesHelper($sale))->crear_comision();

        return $sale;
    }

    /**
     * Crea el vendedor de prueba con el `percentage_commission` y `commission_with_iva` dados.
     *
     * @param float $percentage_commission
     * @param int   $commission_with_iva
     * @return \App\Models\Seller
     */
    protected function crear_seller($percentage_commission, $commission_with_iva)
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        return Seller::create([
            'num'                       => 900000 + (int) $commission_with_iva,
            'name'                      => 'Vendedor test commission_with_iva='.$commission_with_iva,
            'commission_after_pay_sale' => 0,
            'percentage_commission'     => $percentage_commission,
            'commission_with_iva'       => $commission_with_iva,
            'user_id'                   => $user->id,
        ]);
    }

    /**
     * Con el check ACTIVADO (default), la comision sigue igual que siempre: sobre el total final
     * CON iva. Este comportamiento no puede cambiar por esta mision.
     *
     * @test
     */
    public function con_el_check_activado_la_comision_sigue_sobre_el_total_con_iva()
    {
        $seller = $this->crear_seller(10, 1);

        $sale = $this->crear_venta_con_seller($seller);

        $comision = SellerCommission::where('sale_id', $sale->id)->first();
        $this->assertNotNull($comision, 'No se creo la comision.');

        $esperado = round((float) $sale->total * 10 / 100, 2);
        $this->assertEqualsWithDelta($esperado, (float) $comision->debe, self::DELTA);
    }

    /**
     * Con el check DESACTIVADO, se resta el IVA de cada articulo antes de aplicar el porcentaje.
     * IVA esperado: Martillo 21% (2 x $210) = $420; Cuchilla 10.5% (1 x $52,5) = $52,5;
     * Cuchara Exento = $0. Total IVA = $472,5. Total de la venta = $3272,5, base sin iva = $2800,
     * comision al 10% = $280.
     *
     * @test
     */
    public function con_el_check_desactivado_se_resta_el_iva_de_cada_articulo()
    {
        $seller = $this->crear_seller(10, 0);

        $sale = $this->crear_venta_con_seller($seller);

        $comision = SellerCommission::where('sale_id', $sale->id)->first();
        $this->assertNotNull($comision, 'No se creo la comision.');

        $iva_esperado = 472.5;
        $base_esperada = (float) $sale->total - $iva_esperado;
        $esperado = round($base_esperada * 10 / 100, 2);

        $this->assertEqualsWithDelta(280.0, $esperado, self::DELTA, 'El propio calculo esperado del test esta mal.');
        $this->assertEqualsWithDelta($esperado, (float) $comision->debe, self::DELTA);
    }
}
