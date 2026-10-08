<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Carbon\Carbon;

/**
 * Archivo 12 — la NOTA DE CREDITO hecha desde VENDER (`PUT api/sale/{id}` con `save_nota_credito`)
 * no vuelve a restar el descuento ni a sumar el recargo cuando el precio del renglon devuelto ya lo
 * trae adentro (decision de Lucas, 8/10/2026: corregir la nota de credito para descuentos Y recargos;
 * el de recargos era el hallazgo 1 del informe del 28/9/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL DEFECTO QUE ESTO CIERRA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `SaleHelper::checkNotaCredito()` calcula el `haber` de la NC como
 *  `price_vender × returned_amount`, menos los descuentos de la venta y mas sus recargos. El
 *  `price_vender` que manda VENDER en `returned_items` es el precio del renglon, o sea, con la
 *  opcion prendida YA TRAE el ajuste adentro. Aplicarlo otra vez acreditaba de menos (descuento) o
 *  de mas (recargo) que lo que el cliente pago.
 *
 *  Cada caso se mide contra su CONTROL: la misma devolucion en una venta con la opcion apagada y los
 *  precios de lista, que da el monto que el cliente efectivamente pago. Los dos tienen que coincidir
 *  (mismo mecanismo que `11_Totales_con_la_opcion_sin_doble_descuento`).
 *
 *  Venta de 3 unidades, se devuelven 2. Original 100, con el 10 % de descuento adentro 90, con el
 *  10 % de recargo adentro 110, con los dos 99.
 *
 * @group descuentos_en_precios
 */
class Nota_de_credito_no_repite_el_ajuste_Test extends DescuentosEnPreciosTestCase
{
    /**
     * Arma una venta a cuenta corriente YA guardada con 3 unidades del articulo centinela al precio
     * dado y los flags dados, mas la cuenta del cliente. Los renglones se adjuntan directo: lo que
     * se prueba es la NC, no el alta.
     *
     * @param  float  $price
     * @param  int    $flag_descuentos
     * @param  int    $flag_recargos
     * @return array  ['venta', 'client', 'credit_account', 'articulo', 'descuento', 'recargo']
     */
    protected function escenario($price, $flag_descuentos, $flag_recargos)
    {
        $user = $this->comercio();

        $client = Client::create(['name' => 'zz Cliente NC descuentos '.uniqid(), 'user_id' => $user->id]);

        $credit_account = CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $client->id,
            'moneda_id'  => 1,
            'user_id'    => $user->id,
        ]);

        $articulo = $this->articulo_centinela();

        $venta = Sale::create([
            'user_id'                            => $user->id,
            'client_id'                          => $client->id,
            'moneda_id'                          => 1,
            'total'                              => $price * 3,
            'sub_total'                          => $price * 3,
            'terminada'                          => 1,
            'save_current_acount'                => 1,
            'omitir_en_cuenta_corriente'         => 0,
            'aplicar_descuentos_directo_a_items' => $flag_descuentos,
            'aplicar_recargos_directo_a_items'   => $flag_recargos,
        ]);

        $venta->articles()->attach($articulo->id, ['amount' => 3, 'price' => $price]);

        CurrentAcount::create([
            'detalle'           => 'Venta N°'.$venta->id,
            'debe'              => $price * 3,
            'saldo'             => $price * 3,
            'pagandose'         => 0,
            'status'            => 'sin_pagar',
            'client_id'         => $client->id,
            'sale_id'           => $venta->id,
            'credit_account_id' => $credit_account->id,
            'user_id'           => $user->id,
            'created_at'        => Carbon::now()->subDay(),
        ]);

        return [
            'venta'          => $venta,
            'client'         => $client,
            'credit_account' => $credit_account,
            'articulo'       => $articulo,
            'descuento'      => $this->descuento(),
            'recargo'        => $this->recargo(),
        ];
    }

    /**
     * Devuelve 2 unidades por el panel de VENDER y devuelve el `haber` de la NC que quedo.
     *
     * @param  array  $e                Del `escenario()`.
     * @param  float  $price            El `price_vender` de la venta y del renglon devuelto.
     * @param  int    $flag_descuentos
     * @param  int    $flag_recargos
     * @param  bool   $con_descuento   Si la venta lleva el descuento de 10 % en su lista.
     * @param  bool   $con_recargo     Si la venta lleva el recargo de 10 % en su lista.
     * @return float
     */
    protected function devolver_dos($e, $price, $flag_descuentos, $flag_recargos, $con_descuento, $con_recargo)
    {
        $payload = $this->payload_actualizar($e['venta'], [
            $this->item_vender('article', $e['articulo']->id, $price, false, 3),
        ], $price * 3, $flag_descuentos, $con_descuento ? [$this->descuento_del_payload($e['descuento'])] : [], [
            'aplicar_recargos_directo_a_items' => $flag_recargos,
            'surchages'                        => $con_recargo ? [$this->recargo_del_payload($e['recargo'])] : [],
            'save_nota_credito'                => 1,
            'nota_credito_description'         => 'NC de la suite de descuentos en precios',
            'returned_items'                   => [[
                'id'              => $e['articulo']->id,
                'is_article'      => true,
                'price_vender'    => $price,
                'returned_amount' => 2,
                'discount'        => null,
            ]],
        ]);

        $this->putJson('api/sale/'.$e['venta']->id, $payload)->assertStatus(200);

        $nota_credito = CurrentAcount::where('status', 'nota_credito')
                                    ->where('sale_id', $e['venta']->id)
                                    ->latest('id')
                                    ->first();

        $this->assertNotNull($nota_credito, 'El update con save_nota_credito no creo la nota de credito.');

        return (float) $nota_credito->haber;
    }

    /**
     * Test 1 — DESCUENTO adentro: precio 90 (original 100). La NC acredita 90 × 2 = 180 y no
     * 90 × 2 × 0,9 = 162. El control —precio de lista 100 y el 10 % de la lista— tambien da 180.
     *
     * @test
     */
    public function con_el_descuento_adentro_la_nc_no_lo_resta_de_nuevo()
    {
        $con_la_opcion = $this->escenario(90, 1, 0);
        $control       = $this->escenario(100, 0, 0);

        $this->assertEqualsWithDelta(180.00, $this->devolver_dos($con_la_opcion, 90, 1, 0, true, false), self::DELTA, 'La NC tiene que acreditar lo que el cliente pago (180), no el descuento restado de nuevo (162).');

        $this->assertEqualsWithDelta(180.00, $this->devolver_dos($control, 100, 0, 0, true, false), self::DELTA, 'Control: con la opcion apagada la NC resta el descuento de la lista y acredita lo mismo.');
    }

    /**
     * Test 2 — RECARGO adentro: precio 110 (original 100). La NC acredita 110 × 2 = 220 y no
     * 110 × 2 × 1,1 = 242 (el hallazgo 1 del 28/9). El control tambien da 220.
     *
     * @test
     */
    public function con_el_recargo_adentro_la_nc_no_lo_suma_de_nuevo()
    {
        $con_la_opcion = $this->escenario(110, 0, 1);
        $control       = $this->escenario(100, 0, 0);

        $this->assertEqualsWithDelta(220.00, $this->devolver_dos($con_la_opcion, 110, 0, 1, false, true), self::DELTA, 'La NC tiene que acreditar lo que el cliente pago (220), no el recargo sumado de nuevo (242).');

        $this->assertEqualsWithDelta(220.00, $this->devolver_dos($control, 100, 0, 0, false, true), self::DELTA, 'Control: con la opcion apagada la NC suma el recargo de la lista y acredita lo mismo.');
    }

    /**
     * Test 3 — DESCUENTO Y RECARGO adentro a la vez: precio 99 (100 → 90 → 99). La NC acredita
     * 99 × 2 = 198, ni 178,20 (restando el descuento de nuevo) ni 217,80 (sumando el recargo). El
     * control —lista de 100 con los dos ajustes al pie— da 198.
     *
     * @test
     */
    public function con_el_descuento_y_el_recargo_adentro_la_nc_no_repite_ninguno()
    {
        $con_las_dos = $this->escenario(99, 1, 1);
        $control     = $this->escenario(100, 0, 0);

        $this->assertEqualsWithDelta(198.00, $this->devolver_dos($con_las_dos, 99, 1, 1, true, true), self::DELTA);

        $this->assertEqualsWithDelta(198.00, $this->devolver_dos($control, 100, 0, 0, true, true), self::DELTA, 'Control: 200 menos 10 % mas 10 % = 198.');
    }

    /**
     * Test 4 — cada opcion gobierna SU PROPIO ajuste: con el descuento adentro y el recargo al pie,
     * la NC no resta el descuento pero SI suma el recargo: 90 × 2 × 1,1 = 198. Y al reves, con el
     * recargo adentro y el descuento al pie: 110 × 2 × 0,9 = 198.
     *
     * @test
     */
    public function cada_opcion_gobierna_su_propio_ajuste()
    {
        $solo_descuento_adentro = $this->escenario(90, 1, 0);
        $solo_recargo_adentro   = $this->escenario(110, 0, 1);

        $this->assertEqualsWithDelta(198.00, $this->devolver_dos($solo_descuento_adentro, 90, 1, 0, true, true), self::DELTA, 'Descuento adentro y recargo al pie: el recargo se suma.');

        $this->assertEqualsWithDelta(198.00, $this->devolver_dos($solo_recargo_adentro, 110, 0, 1, true, true), self::DELTA, 'Recargo adentro y descuento al pie: el descuento se resta.');
    }
}
