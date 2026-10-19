<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\sale\DescuentosEnPreciosEsquemaHelper;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 16 — el modal "Actualizar precios" (`PUT api/sale/update-prices/{id}`) de una SPA
 * ANTERIOR a la opcion no puede tocar una venta con los descuentos ADENTRO de los precios
 * (`SaleController::updatePrices()` -> `DescuentosEnPreciosEsquemaHelper::requiere_spa_nueva_en_update_prices()`).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL CASO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La SPA vieja abre el modal sobre una venta con el flag en 1 y manda los items SIN
 *  `price_vender_sin_descuentos`. `updateItemsPrices()` escribe el precio de lista —sin el
 *  descuento— y deja la base en NULL con el flag todavia en 1: el descuento se pierde para siempre y
 *  la factura sale por mas que el total. Es la misma clase de dano que el 409 de `update()` (archivo
 *  15), por otro endpoint.
 *
 *  Lo que se fija:
 *
 *   (a) flag en 1 + algun item SIN la clave           -> 409 y NADA cambia (renglones ni total);
 *   (b) la clave en TODOS los items, con numero o en null -> 200, como siempre;
 *   (c) flag en 0 o NULL + items sin la clave         -> 200: nada cambia para las ventas de hoy;
 *   (d) la columna del flag no existe todavia         -> el candado no se tiene en cuenta.
 *
 * @group descuentos_en_precios
 */
class Spa_vieja_no_actualiza_precios_de_una_venta_con_descuentos_adentro_Test extends DescuentosEnPreciosTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_flag_si_quedo_escondido();
    }

    /**
     * Crea por el endpoint una venta de mostrador con un articulo y un servicio. Con el flag en 1 los
     * renglones van descontados (90 y 45, originales 100 y 50); con 0 o `null` van a precio de lista
     * (100 y 50) y el 10 % queda al pie. `null` manda la venta SIN la clave, como la SPA vieja.
     *
     * @param  int|null  $flag
     * @param  int       $cantidad  Para darle a cada venta de un test un total distinto (la guarda
     *                              anti-duplicados de `store()` es por comercio, cliente y total).
     * @return array  ['sale', 'articulo', 'servicio']
     */
    protected function venta($flag, $cantidad = 1)
    {
        $articulo  = $this->articulo_centinela();
        $servicio  = $this->servicio(50);
        $descuento = $this->descuento();

        $precio_articulo = $flag ? 90 : 100;
        $precio_servicio = $flag ? 45 : 50;

        $payload = $this->payload_venta([
            $this->item_vender('article', $articulo->id, $precio_articulo, $flag ? 100 : false, $cantidad),
            $this->item_vender('service', $servicio->id, $precio_servicio, $flag ? 50 : false, $cantidad),
        ], 135.00 * $cantidad, (int) $flag, [$this->descuento_del_payload($descuento)], ['sub_total' => ($precio_articulo + $precio_servicio) * $cantidad]);

        if (is_null($flag)) {
            unset($payload['aplicar_descuentos_directo_a_items']);
        }

        return ['sale' => $this->crear_venta($payload), 'articulo' => $articulo, 'servicio' => $servicio];
    }

    /**
     * Un item del modal. `$con_clave` en `false` = la clave NO viaja (SPA vieja); si no, `$base` es
     * el valor que lleva (un numero o null).
     *
     * @param  string      $tipo   'article' | 'service'
     * @param  int         $id
     * @param  float       $precio
     * @param  bool        $con_clave
     * @param  float|null  $base
     * @return array
     */
    protected function item_del_modal($tipo, $id, $precio, $con_clave, $base = null)
    {
        $item = [
            'is_'.$tipo    => true,
            'id'           => $id,
            'price_vender' => $precio,
        ];

        if ($con_clave) {
            $item['price_vender_sin_descuentos'] = $base;
        }

        return $item;
    }

    /**
     * Lo que importa de la venta, leido DIRECTO de la base: total, flag, y los dos renglones con sus
     * bases.
     *
     * @param  array  $v  De `venta()`.
     * @return array
     */
    protected function foto($v)
    {
        $articulo = DB::table('article_sale')->where('sale_id', $v['sale']->id)->where('article_id', $v['articulo']->id)->first();
        $servicio = DB::table('sale_service')->where('sale_id', $v['sale']->id)->where('service_id', $v['servicio']->id)->first();

        return [
            'total'    => (float) DB::table('sales')->where('id', $v['sale']->id)->value('total'),
            'flag'     => DB::table('sales')->where('id', $v['sale']->id)->value(DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG),
            'articulo' => [(float) $articulo->price, $articulo->price_sin_descuentos_de_venta],
            'servicio' => [(float) $servicio->price, $servicio->price_sin_descuentos_de_venta],
        ];
    }

    /**
     * Test (a) — flag en 1 y los items SIN la clave: 409 con el mensaje para el vendedor, y no cambia
     * nada. Tambien alcanza con que falte en UN solo item.
     *
     * @test
     */
    public function una_venta_con_descuentos_adentro_rechaza_el_modal_de_una_spa_vieja()
    {
        $v = $this->venta(1);

        $antes = $this->foto($v);

        $this->assertSame([90.0, '100.000000'], $antes['articulo'], 'Control: el articulo nace descontado con su base.');

        /* Todos los items sin la clave: el modal viejo propone el precio de lista, sin descuento. */
        $response = $this->putJson('api/sale/update-prices/'.$v['sale']->id, ['items' => [
            $this->item_del_modal('article', $v['articulo']->id, 100, false),
            $this->item_del_modal('service', $v['servicio']->id, 50, false),
        ]]);

        $response->assertStatus(409);

        $this->assertTrue((bool) $response->json('requiere_spa_nueva'));
        $this->assertStringContainsString('descuentos aplicados directamente en los precios', (string) $response->json('message'));
        $this->assertStringContainsString('Recarga la pagina', (string) $response->json('message'));

        $this->assertSame($antes, $this->foto($v), 'El rechazo no puede tocar ni los renglones ni el total.');

        /* Con la clave en el articulo pero NO en el servicio: un solo item sin la clave alcanza. */
        $this->putJson('api/sale/update-prices/'.$v['sale']->id, ['items' => [
            $this->item_del_modal('article', $v['articulo']->id, 108, true, 120),
            $this->item_del_modal('service', $v['servicio']->id, 50, false),
        ]])->assertStatus(409);

        $this->assertSame($antes, $this->foto($v), 'Ni siquiera el item que traia la clave se escribe: se corta antes de tocar nada.');
    }

    /**
     * Test (b) — la clave en TODOS los items pasa, tanto con un numero como en null (la SPA nueva
     * manda null cuando el precio nuevo no lleva descuento adentro).
     *
     * @test
     */
    public function con_la_clave_en_todos_los_items_pasa_con_numero_y_en_null()
    {
        $con_numero = $this->venta(1, 1);
        $con_null   = $this->venta(1, 2);

        $this->putJson('api/sale/update-prices/'.$con_numero['sale']->id, ['items' => [
            $this->item_del_modal('article', $con_numero['articulo']->id, 108, true, 120),
            $this->item_del_modal('service', $con_numero['servicio']->id, 54, true, 60),
        ]])->assertStatus(200);

        $foto = $this->foto($con_numero);

        $this->assertSame([108.0, '120.000000'], $foto['articulo']);
        $this->assertSame([54.0, '60.000000'], $foto['servicio']);

        $this->putJson('api/sale/update-prices/'.$con_null['sale']->id, ['items' => [
            $this->item_del_modal('article', $con_null['articulo']->id, 100, true, null),
            $this->item_del_modal('service', $con_null['servicio']->id, 50, true, null),
        ]])->assertStatus(200);

        $foto = $this->foto($con_null);

        $this->assertSame([100.0, null], $foto['articulo'], 'Un null explicito es una SPA nueva: se escribe el precio con la base en NULL.');
        $this->assertSame([50.0, null], $foto['servicio']);
    }

    /**
     * Test (c) — una venta con el flag en 0 o en NULL (todas las de hoy) no cambia: el modal viejo
     * sigue andando sin la clave.
     *
     * @test
     */
    public function las_ventas_sin_la_opcion_prendida_siguen_actualizando_precios_sin_la_clave()
    {
        $en_cero = $this->venta(0, 1);
        $en_null = $this->venta(null, 2);

        $this->assertNull($this->foto($en_null)['flag'], 'Control: la venta sin la clave queda con la columna en NULL.');

        foreach ([$en_cero, $en_null] as $v) {

            $this->putJson('api/sale/update-prices/'.$v['sale']->id, ['items' => [
                $this->item_del_modal('article', $v['articulo']->id, 120, false),
                $this->item_del_modal('service', $v['servicio']->id, 60, false),
            ]])->assertStatus(200);

            $this->assertSame([120.0, null], $this->foto($v)['articulo']);
        }
    }

    /**
     * Test (d) — con la columna del flag todavia sin crear (ventana del deploy) el candado no se tiene
     * en cuenta: ninguna venta puede tener el flag en 1.
     *
     * @test
     */
    public function sin_la_columna_el_candado_no_se_tiene_en_cuenta()
    {
        $self = $this;

        $this->con_el_flag_escondido(function () use ($self) {

            $articulo = $self->articulo_centinela();

            $sale = $self->crear_venta($self->payload_venta([
                $self->item_vender('article', $articulo->id, 100, false),
            ], 90.00, 0, [$self->descuento_del_payload($self->descuento())], ['sub_total' => 100]));

            $self->putJson('api/sale/update-prices/'.$sale->id, ['items' => [
                $self->item_del_modal('article', $articulo->id, 120, false),
            ]])->assertStatus(200);

            $self->assertEqualsWithDelta(120, (float) DB::table('article_sale')->where('sale_id', $sale->id)->value('price'), self::DELTA);

            /* El helper mira la guarda ANTES que el modelo: ni con un atributo en 1 en memoria frena. */
            $en_memoria = \App\Models\Sale::find($sale->id);
            $en_memoria->aplicar_descuentos_directo_a_items = 1;

            $self->assertFalse(
                DescuentosEnPreciosEsquemaHelper::requiere_spa_nueva_en_update_prices($en_memoria, [['id' => $articulo->id]]),
                'Sin la columna no hay nada que proteger.'
            );
        });
    }

    /**
     * Test (e) — el helper no se confunde con lo que no es un renglon: sin items, o con items que no
     * son un array, no hay nada que frenar.
     *
     * @test
     */
    public function sin_items_o_con_items_que_no_son_arrays_no_hay_nada_que_frenar()
    {
        $v = $this->venta(1);

        $sale = \App\Models\Sale::find($v['sale']->id);

        $this->assertFalse(DescuentosEnPreciosEsquemaHelper::requiere_spa_nueva_en_update_prices($sale, null));
        $this->assertFalse(DescuentosEnPreciosEsquemaHelper::requiere_spa_nueva_en_update_prices($sale, []));
        $this->assertFalse(DescuentosEnPreciosEsquemaHelper::requiere_spa_nueva_en_update_prices($sale, ['basura', 7]));
        $this->assertTrue(DescuentosEnPreciosEsquemaHelper::requiere_spa_nueva_en_update_prices($sale, [['id' => 1]]));
    }
}
