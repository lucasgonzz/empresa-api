<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Combo;
use App\Models\Discount;
use App\Models\IvaCondition;
use App\Models\PromocionVinoteca;
use App\Models\Sale;
use App\Models\Surchage;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Archivo 13 — un combo (o una promoción) se factura a SU precio, no al del último artículo.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL DEFECTO QUE ESTA SUITE FIJA (misión afip-combos-y-comision-mostrador, 4/11/2026)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `AfipItemCalculator::get_combo_iva()` pide el precio con `get_article_price_with_discounts()`,
 *  que lee `$afip_helper->article` — el renglón "en curso". Los artículos y los servicios se
 *  asignan a ese campo antes de calcularse; los combos y las promociones NO. Entonces el combo se
 *  liquidaba con el precio del ÚLTIMO artículo de la venta (y con sus banderas, `is_article`), y
 *  una venta de solo combos reventaba con «Trying to get property 'is_description' of non-object»
 *  porque el campo seguía en null.
 *
 *  Medido el 4/11/2026 con una venta de un combo ($48.521) y dos martillos ($18.876 c/u): total
 *  $86.273, y la factura salía por $37.752 — el combo se había facturado como un martillo más.
 *
 * Las reglas que se fijan (las mismas con las que el front arma `sales.total`, ver
 * `SaleHelper::getTotalSale()` y `vender_set_total.js`):
 *
 *   - el combo/promo se liquida a `pivot->price` por `pivot->amount`, IVA 21 % incluido;
 *   - los descuentos y recargos de la lista de la venta (`discounts`/`surchages`) caen sobre él;
 *   - el `descuento %` global de la venta cae SOLO sobre los artículos: ni sobre el combo ni sobre
 *     la promo;
 *   - una venta de solo combos o solo promociones no revienta;
 *   - el total forzado y el canje por puntos prorratean sobre un bruto que SÍ incluye al combo.
 *
 * ⚠️ NUNCA se llama a `make_afip_ticket()`: pegaría contra ARCA. Se arma el `AfipTicket` EN
 * MEMORIA y se llama `getImportes()`, que es lo que hace `SaleHelper::set_total_a_facturar()`.
 *
 * Los esperados están CALCULADOS A MANO, nunca contra el helper que está bajo prueba. Plata con
 * `assertEqualsWithDelta()`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group facturacion
 */
class Combos_y_promociones_se_facturan_a_su_precio_Test extends EmpresaTestCase
{
    /** Delta para comparaciones de plata. */
    const DELTA = 0.01;

    /** Un artículo al 21 %, con precio de venta con IVA. 2 x 1.210 = 2.420 ($2.000 de base). */
    const MARTILLO = 1210.00;

    /** Un combo, con IVA 21 % incluido (4.840 = 4.000 + 840). */
    const COMBO = 4840.00;

    /** Una promoción de vinoteca (3.025 = 2.500 + 525). */
    const PROMO = 3025.00;

    /**
     * Configuración fiscal EN MEMORIA de un Responsable inscripto.
     *
     * @return \App\Models\AfipInformation
     */
    protected function afip_information_ri()
    {
        $iva_condition = IvaCondition::where('name', 'Responsable inscripto')->first();

        $this->assertNotNull($iva_condition, 'Falta la iva_condition "Responsable inscripto" en la base de testing.');

        $afip_information = new AfipInformation();
        $afip_information->iva_condition_id = $iva_condition->id;
        $afip_information->punto_venta = 1;
        $afip_information->cuit = '20111111112';
        $afip_information->setRelation('iva_condition', $iva_condition);

        return $afip_information;
    }

    /**
     * Importes de una venta, sin red.
     *
     * @param  \App\Models\Sale  $sale
     * @return array  Resultado de AfipHelper::getImportes().
     */
    protected function importes_de($sale)
    {
        $afip_ticket = new AfipTicket();
        $afip_ticket->facturar_importe_personalizado = null;
        $afip_ticket->importe_personalizado_ivas_json = null;
        $afip_ticket->afip_tipo_comprobante_id = 1;
        $afip_ticket->cbte_tipo = 1;
        $afip_ticket->sale = Sale::find($sale->id);
        $afip_ticket->setRelation('afip_information', $this->afip_information_ri());

        $afip_helper = new AfipHelper($afip_ticket);

        return $afip_helper->getImportes();
    }

    /**
     * Venta de mostrador armada directo en la base.
     *
     * @param  array  $overrides
     * @return \App\Models\Sale
     */
    protected function venta($overrides = [])
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return Sale::create(array_merge([
            'user_id'                            => $user->id,
            'client_id'                          => null,
            'omitir_en_cuenta_corriente'         => 0,
            'save_current_acount'                => 0,
            'terminada'                          => 1,
            'is_cerrada'                         => 0,
            'sub_total'                          => 0,
            'total'                              => 0,
            'moneda_id'                          => 1,
            'descuento'                          => 0,
            'aplicar_descuentos_directo_a_items' => 0,
            'aplicar_recargos_directo_a_items'   => 0,
        ], $overrides));
    }

    /**
     * Engancha el martillo del fixture (IVA 21 %) a la venta.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float             $amount
     * @param  float             $price
     * @return void
     */
    protected function martillo($sale, $amount, $price = self::MARTILLO)
    {
        $articulo = $this->articulo('Martillo acero');

        $this->assertNotNull($articulo, 'Falta el artículo "Martillo acero" del fixture.');
        $this->assertNotNull($articulo->iva, 'El artículo "Martillo acero" no tiene IVA.');

        $sale->articles()->attach($articulo->id, [
            'amount'         => $amount,
            'price'          => $price,
            'iva_percentage' => $articulo->iva->percentage,
        ]);
    }

    /**
     * Engancha un combo nuevo a la venta.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float             $amount
     * @param  float             $price
     * @return \App\Models\Combo
     */
    protected function combo($sale, $amount, $price = self::COMBO)
    {
        $combo = Combo::create([
            'num'     => 9100 + $sale->id,
            'name'    => 'zz Combo de la suite de facturación',
            'price'   => $price,
            'cost'    => 0,
            'user_id' => $sale->user_id,
        ]);

        $sale->combos()->attach($combo->id, ['amount' => $amount, 'price' => $price]);

        return $combo;
    }

    /**
     * Engancha una promoción de vinoteca nueva a la venta.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float             $amount
     * @param  float             $price
     * @return \App\Models\PromocionVinoteca
     */
    protected function promo($sale, $amount, $price = self::PROMO)
    {
        $promo = PromocionVinoteca::create([
            'name'        => 'zz Promo de la suite de facturación',
            'stock'       => 100,
            'cost'        => 0,
            'final_price' => $price,
            'user_id'     => $sale->user_id,
        ]);

        $sale->promocion_vinotecas()->attach($promo->id, ['amount' => $amount, 'price' => $price]);

        return $promo;
    }

    /**
     * Un descuento de lista del 10 % ya enganchado a la venta.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float             $porcentaje
     * @return void
     */
    protected function descuento_de_lista($sale, $porcentaje)
    {
        $descuento = Discount::create([
            'name'       => 'zz Descuento de la suite de facturación',
            'percentage' => $porcentaje,
            'user_id'    => $sale->user_id,
        ]);

        $sale->discounts()->attach($descuento->id, ['percentage' => $porcentaje]);
    }

    /**
     * Un recargo de lista ya enganchado a la venta.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float             $porcentaje
     * @return void
     */
    protected function recargo_de_lista($sale, $porcentaje)
    {
        $recargo = Surchage::create([
            'name'       => 'zz Recargo de la suite de facturación',
            'percentage' => $porcentaje,
            'user_id'    => $sale->user_id,
        ]);

        $sale->surchages()->attach($recargo->id, ['percentage' => $porcentaje]);
    }

    /**
     * Las dos igualdades que ARCA exige sobre cualquier comprobante.
     *
     * @param  array  $importes
     * @return void
     */
    protected function assert_desglose_cierra($importes)
    {
        $this->assertEqualsWithDelta(
            $importes['total'],
            $importes['gravado'] + $importes['iva'] + $importes['exento'] + $importes['neto_no_gravado'],
            self::DELTA,
            'ImpNeto + ImpIVA + ImpOpEx + ImpTotConc tiene que dar ImpTotal'
        );
    }

    /**
     * Test 1 — EL CASO MEDIDO: artículos + combo. Antes de arreglar, el combo se facturaba al
     * precio del martillo (1.210) y el comprobante salía por 3.630 en vez de 7.260.
     *
     *     Martillo  1.210 x 2 = 2.420
     *     Combo     4.840 x 1 = 4.840
     *                           ─────
     *                           7.260   (base 6.000 + IVA 1.260)
     *
     * @group facturacion
     * @test
     */
    public function una_venta_con_articulos_y_un_combo_factura_el_combo_a_su_precio()
    {
        $sale = $this->venta(['sub_total' => 7260, 'total' => 7260]);
        $this->martillo($sale, 2);
        $this->combo($sale, 1);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(7260.00, $importes['total'], self::DELTA, 'el combo se factura a su precio, no al del último artículo');
        $this->assertEqualsWithDelta(6000.00, $importes['gravado'], self::DELTA);
        $this->assertEqualsWithDelta(1260.00, $importes['iva'], self::DELTA);
        $this->assertEqualsWithDelta(6000.00, $importes['ivas']['21']['BaseImp'], self::DELTA);
        $this->assertEqualsWithDelta(1260.00, $importes['ivas']['21']['Importe'], self::DELTA);
        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 2 — una venta de SOLO combos. Antes reventaba con «Trying to get property
     * 'is_description' of non-object»: no había ningún artículo previo que prestara su precio.
     *
     * @group facturacion
     * @test
     */
    public function una_venta_de_solo_combos_se_factura_sin_reventar()
    {
        $sale = $this->venta(['sub_total' => 9680, 'total' => 9680]);
        $this->combo($sale, 2);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(9680.00, $importes['total'], self::DELTA, '2 combos x 4.840');
        $this->assertEqualsWithDelta(8000.00, $importes['gravado'], self::DELTA);
        $this->assertEqualsWithDelta(1680.00, $importes['iva'], self::DELTA);
        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 3 — lo mismo con una promoción de vinoteca (la misma familia: se calcula por la misma
     * función y tampoco se asignaba al renglón en curso).
     *
     * @group facturacion
     * @test
     */
    public function una_venta_de_solo_promociones_se_factura_a_su_precio()
    {
        $sale = $this->venta(['sub_total' => 6050, 'total' => 6050]);
        $this->promo($sale, 2);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(6050.00, $importes['total'], self::DELTA, '2 promos x 3.025');
        $this->assertEqualsWithDelta(5000.00, $importes['gravado'], self::DELTA);
        $this->assertEqualsWithDelta(1050.00, $importes['iva'], self::DELTA);
        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 4 — artículo + combo + promoción juntos: cada uno a su precio.
     *
     *     Martillo 1.210 x 1 + Combo 4.840 x 2 + Promo 3.025 x 1 = 1.210 + 9.680 + 3.025 = 13.915
     *
     * @group facturacion
     * @test
     */
    public function articulo_combo_y_promocion_juntos_suman_cada_uno_a_su_precio()
    {
        $sale = $this->venta(['sub_total' => 13915, 'total' => 13915]);
        $this->martillo($sale, 1);
        $this->combo($sale, 2);
        $this->promo($sale, 1);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(13915.00, $importes['total'], self::DELTA);
        $this->assertEqualsWithDelta(11500.00, $importes['gravado'], self::DELTA);
        $this->assertEqualsWithDelta(2415.00, $importes['iva'], self::DELTA);
        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 5 — el descuento de LISTA de la venta (10 %) cae sobre el combo, igual que en el front:
     * (2.420 + 4.840) x 0,90 = 6.534. Y la factura coincide con lo que calcula `getTotalSale()`.
     *
     * @group facturacion
     * @test
     */
    public function el_descuento_de_lista_de_la_venta_cae_tambien_sobre_el_combo()
    {
        $sale = $this->venta(['sub_total' => 7260, 'total' => 6534]);
        $this->martillo($sale, 2);
        $this->combo($sale, 1);
        $this->descuento_de_lista($sale, 10);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(6534.00, $importes['total'], self::DELTA, '(2.420 + 4.840) x 0,90');
        $this->assertEqualsWithDelta(
            (float) SaleHelper::getTotalSale(Sale::find($sale->id), true, true, false, true),
            $importes['total'],
            self::DELTA,
            'lo facturado tiene que ser lo que calcula el sistema como total de la venta'
        );
        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 6 — el recargo de lista (10 %) también: (2.420 + 4.840) x 1,10 = 7.986.
     *
     * @group facturacion
     * @test
     */
    public function el_recargo_de_lista_de_la_venta_cae_tambien_sobre_el_combo()
    {
        $sale = $this->venta(['sub_total' => 7260, 'total' => 7986]);
        $this->martillo($sale, 2);
        $this->combo($sale, 1);
        $this->recargo_de_lista($sale, 10);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(7986.00, $importes['total'], self::DELTA, '(2.420 + 4.840) x 1,10');
        $this->assertEqualsWithDelta(
            (float) SaleHelper::getTotalSale(Sale::find($sale->id), true, true, false, true),
            $importes['total'],
            self::DELTA
        );
        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 7 — el «descuento %» GLOBAL (`sales.descuento`) cae solo sobre los artículos, como en el
     * front y en `getTotalSale()`: 2.420 x 0,80 + 4.840 = 6.776. Aplicarlo al combo daría 5.808.
     *
     * @group facturacion
     * @test
     */
    public function el_descuento_porcentual_global_cae_solo_sobre_los_articulos()
    {
        $sale = $this->venta(['sub_total' => 7260, 'total' => 6776, 'descuento' => 20]);
        $this->martillo($sale, 2);
        $this->combo($sale, 1);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(6776.00, $importes['total'], self::DELTA, '2.420 x 0,80 + 4.840: el combo no lleva el descuento %');
        $this->assertEqualsWithDelta(
            (float) SaleHelper::getTotalSale(Sale::find($sale->id), true, true, false, true),
            $importes['total'],
            self::DELTA
        );
        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 8 — con la opción de descuentos adentro del precio, el combo ya trae el descuento y no
     * se vuelve a restar (la guarda que ya respetan los artículos).
     *
     * @group facturacion
     * @test
     */
    public function con_descuentos_adentro_del_precio_el_combo_no_se_descuenta_dos_veces()
    {
        $sale = $this->venta(['sub_total' => 7260, 'total' => 6534, 'aplicar_descuentos_directo_a_items' => 1]);
        // Los precios ya traen el 10 %: 1.089 x 2 + 4.356 = 6.534.
        $this->martillo($sale, 2, 1089.00);
        $this->combo($sale, 1, 4356.00);
        $this->descuento_de_lista($sale, 10);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(6534.00, $importes['total'], self::DELTA, 'el descuento va adentro del precio: no se resta otra vez');
    }

    /**
     * Test 9 — total forzado: el factor se calcula sobre un bruto que incluye al combo, así que la
     * factura cae EXACTO en `sales.total` (7.260 - 260 = 7.000).
     *
     * @group facturacion
     * @test
     */
    public function el_total_forzado_prorratea_sobre_un_bruto_que_incluye_al_combo()
    {
        $sale = $this->venta(['sub_total' => 7260, 'total' => 7000, 'forzar_total_monto' => -260.00]);
        $this->martillo($sale, 2);
        $this->combo($sale, 1);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(7000.00, $importes['total'], self::DELTA, 'la factura tiene que dar el total forzado');
        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 10 — canje de puntos: el porcentaje se saca de un bruto que incluye al combo, y la
     * factura baja EXACTAMENTE los pesos canjeados (7.260 - 726 = 6.534).
     *
     * @group facturacion
     * @test
     */
    public function el_canje_de_puntos_prorratea_sobre_un_bruto_que_incluye_al_combo()
    {
        $sale = $this->venta(['sub_total' => 7260, 'total' => 6534, 'puntos_canjeados' => 726, 'descuento_puntos' => 726.00]);
        $this->martillo($sale, 2);
        $this->combo($sale, 1);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(6534.00, $importes['total'], self::DELTA, 'el canje de 726 baja la factura en 726');
        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 11 — venta en dólares: el precio del combo está en USD y se cotiza una sola vez.
     * USD 4,84 x 1.000 = $4.840.
     *
     * @group facturacion
     * @test
     */
    public function el_combo_en_dolares_se_cotiza_una_sola_vez()
    {
        $sale = $this->venta(['sub_total' => 4.84, 'total' => 4.84, 'moneda_id' => 2, 'valor_dolar' => 1000]);
        $this->combo($sale, 1, 4.84);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(4840.00, $importes['total'], self::DELTA, 'USD 4,84 a $1.000');
    }
}
