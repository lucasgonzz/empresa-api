<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\AfipHelper\AfipItemCalculator;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\puntos\PuntosBaseHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\AfipTicket;
use App\Models\Budget;
use App\Models\Sale;

/**
 * Archivo 11 — con `aplicar_descuentos_directo_a_items` NINGUNA cuenta del back vuelve a restar el
 * descuento que el precio de cada renglon ya trae adentro.
 *
 * Es la otra mitad de la mision: la API no calcula precios, pero SI hace cuentas con ellos, y cada
 * cuenta que restaba los descuentos de venta al pie tiene que saltearlos con la opcion prendida:
 *
 *  - `SaleHelper::getTotalSale()`        el total de la venta (los cuatro tipos de renglon);
 *  - `BudgetHelper::getTotal()`          el total del presupuesto (con el que `store()` valida);
 *  - `AfipItemCalculator`                el neto de la factura de ARCA y el bruto del que sale el canje;
 *  - `PuntosBaseHelper`                  la base de acumulacion de puntos.
 *
 * El mecanismo que se mide, siempre igual: se prueba la cuenta con la opcion prendida (precio 90 =
 * original 100 con el 10 % adentro) y la MISMA venta con la opcion apagada (precio 100 y el 10 % al
 * pie), y las dos tienen que dar lo mismo. Prender o apagar nunca decide SI el descuento se cobra.
 * Si alguien saca la guarda de una de esas cuentas, la primera da 81 donde la segunda da 90 y el
 * test lo dice.
 *
 * Las ventas se arman directo en la base (`Sale::create` + `attach`) y no por el endpoint: lo que se
 * mide es la cuenta, no la persistencia (esa la cubren los archivos 1 a 10).
 *
 * @group descuentos_en_precios
 */
class Totales_con_la_opcion_sin_doble_descuento_Test extends DescuentosEnPreciosTestCase
{
    /**
     * Una venta de mostrador armada directo en la base, con un descuento de venta del 10 %.
     *
     * @param  int    $flag     `aplicar_descuentos_directo_a_items`.
     * @param  array  $renglones  Lo que se adjunta: ['article' => precio, 'service' => precio, 'combo' => precio, 'promo' => precio]
     *                            (los que falten no se adjuntan). Cantidad 1 cada uno.
     * @param  array  $atributos  Columnas de la venta a pisar.
     * @return \App\Models\Sale
     */
    protected function venta_en_base($flag, $renglones, $atributos = [])
    {
        $sale = Sale::create(array_merge([
            'user_id'                            => $this->comercio()->id,
            'omitir_en_cuenta_corriente'         => 1,
            'save_current_acount'                => 0,
            'terminada'                          => 1,
            'moneda_id'                          => 1,
            'descuento'                          => 0,
            'discounts_in_services'              => 1,
            'surchages_in_services'              => 1,
            'aplicar_descuentos_directo_a_items' => $flag,
            'aplicar_recargos_directo_a_items'   => 0,
        ], $atributos));

        $sale->discounts()->attach($this->descuento()->id, ['percentage' => self::PORCENTAJE_DESCUENTO]);

        if (isset($renglones['article'])) {
            $sale->articles()->attach($this->articulo_centinela()->id, ['amount' => 1, 'price' => $renglones['article']]);
        }

        if (isset($renglones['service'])) {
            $sale->services()->attach($this->servicio(50)->id, ['amount' => 1, 'price' => $renglones['service']]);
        }

        if (isset($renglones['combo'])) {
            $sale->combos()->attach($this->combo(200)->id, ['amount' => 1, 'price' => $renglones['combo']]);
        }

        if (isset($renglones['promo'])) {
            $sale->promocion_vinotecas()->attach($this->promocion(300)->id, ['amount' => 1, 'price' => $renglones['promo']]);
        }

        return Sale::find($sale->id);
    }

    /**
     * `getTotalSale()` con todo recargado desde la base.
     *
     * @param  \App\Models\Sale  $sale
     * @return float
     */
    protected function total_de($sale)
    {
        return SaleHelper::getTotalSale(Sale::find($sale->id), true, true, false, true);
    }

    /**
     * Test 1 — los CUATRO tipos de renglon: con la opcion prendida el total es la suma de los
     * precios (ya descontados) y con la apagada, la suma de los de lista menos el 10 %. Dan lo
     * mismo: 585. Es la guarda que envuelve a los cuatro buckets —combos y promociones incluidos—.
     *
     * @test
     */
    public function getTotalSale_no_resta_el_descuento_dos_veces_en_ningun_tipo_de_renglon()
    {
        $prendida = $this->venta_en_base(1, ['article' => 90, 'service' => 45, 'combo' => 180, 'promo' => 270]);
        $apagada  = $this->venta_en_base(0, ['article' => 100, 'service' => 50, 'combo' => 200, 'promo' => 300]);

        $this->assertEqualsWithDelta(585.00, $this->total_de($prendida), self::DELTA, 'Opcion prendida: los precios ya traen el descuento, el pie no lo vuelve a restar (restado dos veces daria 526,50).');
        $this->assertEqualsWithDelta(585.00, $this->total_de($apagada), self::DELTA, 'Opcion apagada: el descuento se resta al pie, como siempre.');
    }

    /**
     * Test 2 — un servicio SIN "descuentos en servicios" no lleva el descuento ni adentro ni al pie,
     * con la opcion prendida o apagada: 90 + 50 + 180 + 270 = 590.
     *
     * @test
     */
    public function un_servicio_sin_descuentos_en_servicios_no_se_descuenta_en_ninguna_de_las_dos_formas()
    {
        $prendida = $this->venta_en_base(1, ['article' => 90, 'service' => 50, 'combo' => 180, 'promo' => 270], ['discounts_in_services' => 0]);
        $apagada  = $this->venta_en_base(0, ['article' => 100, 'service' => 50, 'combo' => 200, 'promo' => 300], ['discounts_in_services' => 0]);

        $this->assertEqualsWithDelta(590.00, $this->total_de($prendida), self::DELTA);
        $this->assertEqualsWithDelta(590.00, $this->total_de($apagada), self::DELTA);
    }

    /**
     * Test 3 — la opcion es solo de la lista de Descuentos: el descuento GLOBAL (`sales.descuento`,
     * el "descuento %" que cae solo sobre los articulos) y el recargo al pie siguen aplicandose.
     * Articulo 90 con el 20 % global = 72, mas el servicio 45, el combo 180 y la promo 270 = 567; con
     * un recargo de 10 % al pie (sin la opcion de recargos), 623,70.
     *
     * @test
     */
    public function el_descuento_global_y_el_recargo_al_pie_siguen_aplicandose()
    {
        $venta = $this->venta_en_base(1, ['article' => 90, 'service' => 45, 'combo' => 180, 'promo' => 270], ['descuento' => 20]);

        $this->assertEqualsWithDelta(567.00, $this->total_de($venta), self::DELTA, 'El descuento global cae sobre el articulo aunque la lista de descuentos vaya adentro.');

        $venta->surchages()->attach($this->recargo()->id, ['percentage' => self::PORCENTAJE_RECARGO]);

        $this->assertEqualsWithDelta(623.70, $this->total_de($venta), self::DELTA, 'El recargo al pie se suma como siempre.');

        /* Con la opcion de recargos TAMBIEN prendida, el recargo ya esta en los precios y no se suma. */
        $venta->aplicar_recargos_directo_a_items = 1;
        $venta->save();

        $this->assertEqualsWithDelta(567.00, $this->total_de($venta), self::DELTA);
    }

    /**
     * Test 4 — el presupuesto: `getTotal()` con la opcion prendida es la suma de los precios, y los
     * mismos precios con la opcion apagada se descuentan de nuevo (526,50): la guarda es LA que
     * decide. El alta (que valida contra `getTotal()` con margen de 3) ya lo prueba por el lado de
     * que da 201; aca se mide el numero en los cuatro buckets y en el servicio sin descuento.
     *
     * @test
     */
    public function getTotal_del_presupuesto_no_resta_el_descuento_dos_veces()
    {
        $articulo = $this->articulo_centinela();
        $servicio = $this->servicio(50);
        $combo    = $this->combo(200);
        $promo    = $this->promocion(300);
        $descuento = $this->descuento();

        $response = $this->postJson('api/budget', $this->payload_presupuesto(585.00, 1, [$this->descuento_del_payload($descuento)], [
            'articles'            => [$this->renglon_articulo_presupuesto($articulo->id, 90, 100, false)],
            'services'            => [$this->renglon_pivot_presupuesto($servicio->id, 45, 50)],
            'combos'              => [$this->renglon_pivot_presupuesto($combo->id, 180, 200)],
            'promocion_vinotecas' => [$this->renglon_pivot_presupuesto($promo->id, 270, 300)],
        ]));

        $response->assertStatus(201);

        $budget = Budget::withAll()->find($response->json('model.id'));

        $this->assertEqualsWithDelta(585.00, BudgetHelper::getTotal($budget), self::DELTA, 'Opcion prendida: los cuatro buckets ya traen el descuento.');

        /* El mismo presupuesto, con el flag apagado en memoria: los mismos precios se descontarian de nuevo. */
        $budget->aplicar_descuentos_directo_a_items = 0;

        $this->assertEqualsWithDelta(526.50, BudgetHelper::getTotal($budget), self::DELTA, 'Es lo que pasaria sin la guarda: el descuento restado dos veces.');
    }

    /**
     * Test 5 — la factura de ARCA: el precio con descuentos de un renglon (`AfipItemCalculator`) es
     * el MISMO con la opcion prendida (precio 90) y apagada (precio 100 y el 10 % de la lista); con
     * la apagada sigue restando, que es la no-regresion.
     *
     * @test
     */
    public function el_neto_de_la_factura_no_resta_el_descuento_dos_veces()
    {
        $prendida = $this->venta_en_base(1, ['article' => 90]);
        $apagada  = $this->venta_en_base(0, ['article' => 100]);

        $this->assertEqualsWithDelta(90.00, $this->precio_de_factura($prendida), self::DELTA, 'Prendida: el renglon se factura al precio que ya trae el descuento.');
        $this->assertEqualsWithDelta(90.00, $this->precio_de_factura($apagada), self::DELTA, 'Apagada: el calculador sigue restando el descuento de la lista.');
    }

    /**
     * El precio con descuentos y recargos del primer renglon de la venta, como lo arma
     * `AfipItemCalculator`, con el contexto que le da `NewSalePdf`.
     *
     * @param  \App\Models\Sale  $sale
     * @return float
     */
    protected function precio_de_factura($sale)
    {
        $afip_helper = $this->afip_helper_de($sale);

        $item = $sale->articles->first();
        $item->is_article = true;

        $afip_helper->article = $item;

        return (float) $afip_helper->getArticlePriceWithDiscounts();
    }

    /**
     * El `AfipHelper` en memoria, sin persistir ningun ticket.
     *
     * @param  \App\Models\Sale  $sale
     * @return \App\Http\Controllers\Helpers\AfipHelper
     */
    protected function afip_helper_de($sale)
    {
        $sale = Sale::find($sale->id);

        return new AfipHelper(new AfipTicket(['cbte_letra' => 'A', 'cbte_tipo' => 1]), $sale->articles, $sale->services, $this->comercio(), $sale);
    }

    /**
     * Test 6 — el BRUTO de la factura (el total facturable ANTES del canje de puntos): el canje de
     * $18 es el 10 % del bruto cuando el bruto es 180 (dos renglones de 90 con el descuento
     * adentro), y NO el 11,11 % que saldria si el bruto restara el descuento de nuevo (162).
     *
     * @test
     */
    public function el_bruto_de_la_factura_no_resta_el_descuento_dos_veces()
    {
        $sale = $this->venta_en_base(1, ['article' => 90, 'service' => 90], ['descuento_puntos' => 18]);

        $porcentaje = (new AfipItemCalculator($this->afip_helper_de($sale)))->get_porcentaje_descuento_puntos();

        $this->assertEqualsWithDelta(10.0, $porcentaje, 0.0001, 'El canje de 18 sobre un bruto de 180 es el 10 %. Un 11,11 % es el bruto con el descuento restado de nuevo.');
    }

    /**
     * Test 7 — los puntos: el factor de la base de acumulacion es 1 con la opcion prendida (el
     * descuento ya esta en el precio de cada renglon, que es lo que suma la base) y 0,9 apagada. Y
     * la guarda de recargos es independiente: con el recargo al pie el factor sube a 1,1.
     *
     * @test
     */
    public function la_base_de_puntos_no_resta_el_descuento_dos_veces()
    {
        $prendida = $this->venta_en_base(1, ['article' => 90]);
        $apagada  = $this->venta_en_base(0, ['article' => 100]);

        $this->assertEqualsWithDelta(1.0, PuntosBaseHelper::factor_descuentos_de_venta($this->con_ajustes($prendida)), 0.000001);
        $this->assertEqualsWithDelta(0.9, PuntosBaseHelper::factor_descuentos_de_venta($this->con_ajustes($apagada)), 0.000001);

        $prendida->surchages()->attach($this->recargo()->id, ['percentage' => self::PORCENTAJE_RECARGO]);

        $this->assertEqualsWithDelta(1.1, PuntosBaseHelper::factor_descuentos_de_venta($this->con_ajustes($prendida)), 0.000001, 'El recargo al pie sigue entrando: cada opcion gobierna su propio ajuste.');
    }

    /**
     * La venta con `discounts` y `surchages` cargados, como los carga `PuntosAcumulacionHelper`.
     *
     * @param  \App\Models\Sale  $sale
     * @return \App\Models\Sale
     */
    protected function con_ajustes($sale)
    {
        $sale = Sale::find($sale->id);

        $sale->load('discounts', 'surchages');

        return $sale;
    }
}
