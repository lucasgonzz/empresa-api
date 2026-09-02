<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\Client;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Exploración vender (1/9/2026) — invariantes I3 e I4.
 *
 * I4 — ACTUALIZAR una venta ajusta el stock por DIFERENCIA, no de nuevo:
 *   qty 5 → 8 mueve −3; 8 → 2 mueve +6; quitar el renglón lo devuelve entero; borrar la
 *   venta devuelve lo que quedaba y nada más. El único test que había del update
 *   (2_Actualizar_venta_Test) afirma un status 200 y ninguna cantidad: el camino estaba
 *   sin custodia.
 *
 * I3 — el precio de una venta guardada está CONGELADO:
 *   cambiar el precio del artículo (costo × 2 ⇒ final × 2) no toca ni el pivote ni el
 *   total de la venta ya guardada; y al actualizar la venta, el price_vender que manda el
 *   cliente se respeta — el servidor no lo "refresca" al precio vigente del artículo.
 *
 * Todo por diferencia contra una foto previa, con artículos y proveedor PROPIOS (los del
 * seeder tienen expectativas absolutas en otros specs y no se tocan).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Actualizar_venta_stock_y_precio_congelado_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $user = User::find(500);

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * @param \App\Models\User $user
     * @param string $nombre
     * @param array $atributos
     * @return \App\Models\Article
     */
    protected function crear_articulo($user, $nombre, $atributos)
    {
        $provider = Provider::firstOrCreate([
            'name'    => 'zz Proveedor Exploracion Ventas',
            'user_id' => $user->id,
        ]);

        $article = Article::create(array_merge([
            'name'        => $nombre,
            'user_id'     => $user->id,
            'provider_id' => $provider->id,
        ], $atributos));

        /* Fresco antes de recalcular: los defaults de la base (iva_id=2, aplicar_iva=1)
           tienen que entrar al cálculo, igual que en el camino real del formulario.
           Ver el comentario largo en Listado/3_Precio_final_sigue_al_costo_Test. */
        $article = Article::find($article->id);

        ArticleHelper::setFinalPrice($article, $user->id);

        return $article->fresh();
    }

    /**
     * El payload de una venta, con la misma forma que manda Vender (la de
     * 1_Crear_venta_Test/2_Actualizar_venta_Test, que es la del formulario real).
     *
     * @param \App\Models\User $user
     * @param array $items Cada uno: ['article' => Article, 'amount' => n, 'price' => p].
     * @param array $extra Claves a pisar (p. ej. id para el PUT).
     * @return array
     */
    protected function payload_venta($user, $items, $extra = [])
    {
        $cliente = Client::where('name', 'Cliente Cuenta Corriente')
            ->where('user_id', $user->id)
            ->first();

        if (is_null($cliente)) {
            $this->markTestSkipped('La base de testing no tiene el cliente "Cliente Cuenta Corriente".');
        }

        $address = Address::where('user_id', $user->id)->first();

        if (is_null($address)) {
            $this->markTestSkipped('La base de testing no tiene ninguna sucursal (Address).');
        }

        $total = 0;
        $items_payload = [];

        foreach ($items as $item) {
            $total += $item['price'] * $item['amount'];

            $items_payload[] = [
                'is_article'   => true,
                'id'           => $item['article']->id,
                'price_vender' => $item['price'],
                'amount'       => $item['amount'],
            ];
        }

        return array_merge([
            'client_id'                        => $cliente->id,
            'address_id'                       => $address->id,
            'save_current_acount'              => 1,
            'omitir_en_cuenta_corriente'       => 0,
            'to_check'                         => 0,
            'checked'                          => 0,
            'confirmed'                        => 0,
            'current_acount_payment_method_id' => 1,
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
            'items'                            => $items_payload,
        ], $extra);
    }

    /**
     * El stock actual del artículo, releído de la base.
     *
     * @param \App\Models\Article $article
     * @return float
     */
    protected function stock($article)
    {
        return (float) Article::find($article->id)->stock;
    }

    /**
     * I4: la cadena entera de ediciones, midiendo el stock después de cada una.
     *
     * @group exploracion-vender
     * @test
     */
    public function actualizar_la_venta_ajusta_el_stock_por_diferencia()
    {
        $user = $this->autenticar();

        $articulo_s = $this->crear_articulo($user, 'zz Exploracion stock S', [
            'cost'            => 100,
            'percentage_gain' => 50,
            'stock'           => 20,
        ]);

        $articulo_b = $this->crear_articulo($user, 'zz Exploracion stock B', [
            'cost'            => 100,
            'percentage_gain' => 50,
            'stock'           => 20,
        ]);

        /* Foto previa. El invariante entero se mide contra esto. */
        $s0 = $this->stock($articulo_s);
        $b0 = $this->stock($articulo_b);

        /* 1. La venta nace con 5 unidades de S. */
        $payload = $this->payload_venta($user, [
            ['article' => $articulo_s, 'amount' => 5, 'price' => 500],
        ]);

        $this->postJson('api/sale', $payload)->assertStatus(201);

        $venta = Sale::where('client_id', $payload['client_id'])->orderBy('id', 'DESC')->first();
        $this->assertNotNull($venta, 'El POST no dejó ninguna venta.');

        $this->assertEquals(
            $s0 - 5,
            $this->stock($articulo_s),
            'Guardar la venta con 5 unidades tenía que dejar el stock en previo − 5.'
        );

        /* 2. Subo la cantidad a 8: tiene que moverse SOLO la diferencia (−3). */
        $this->putJson('api/sale/' . $venta->id, $this->payload_venta($user, [
            ['article' => $articulo_s, 'amount' => 8, 'price' => 500],
        ], ['id' => $venta->id]))->assertStatus(200);

        $this->assertEquals(
            $s0 - 8,
            $this->stock($articulo_s),
            'Subir la cantidad de 5 a 8 tenía que dejar el stock en previo − 8 '
                . '(la edición mueve la DIFERENCIA, no descuenta la cantidad entera de nuevo).'
        );

        /* 3. La bajo a 2: vuelven 6. */
        $this->putJson('api/sale/' . $venta->id, $this->payload_venta($user, [
            ['article' => $articulo_s, 'amount' => 2, 'price' => 500],
        ], ['id' => $venta->id]))->assertStatus(200);

        $this->assertEquals(
            $s0 - 2,
            $this->stock($articulo_s),
            'Bajar la cantidad de 8 a 2 tenía que devolver 6 unidades.'
        );

        /* 4. Saco el renglón de S (queda B): S vuelve entero, B descuenta lo suyo. */
        $this->putJson('api/sale/' . $venta->id, $this->payload_venta($user, [
            ['article' => $articulo_b, 'amount' => 1, 'price' => 200],
        ], ['id' => $venta->id]))->assertStatus(200);

        $this->assertEquals(
            $s0,
            $this->stock($articulo_s),
            'Quitar el renglón en la edición tenía que devolver TODO el stock de ese artículo.'
        );

        $this->assertEquals(
            $b0 - 1,
            $this->stock($articulo_b),
            'El renglón agregado en la edición tenía que descontar su unidad.'
        );

        /* 5. Borro la venta: el renglón que ya no estaba (S) no recibe nada de más. */
        $this->deleteJson('api/sale/' . $venta->id)->assertStatus(200);

        $this->assertEquals(
            $s0,
            $this->stock($articulo_s),
            'Borrar la venta no tenía que tocar el stock de un renglón que ya no estaba.'
        );

        /* Lo que el borrado hace con el stock del renglón VIGENTE de un artículo con stock
           global está fijado — como defecto conocido — en el test de abajo. */
    }

    /**
     * 🔴 DEFECTO CONOCIDO, fijado a propósito (exploración 1/9/2026). Reportado, espera
     * decisión de Lucas — toca el total de la venta y la cuenta corriente en producción y
     * no se arregla solo.
     *
     * El modal "Actualizar precios" de una venta (PUT sale/update-prices/{id}) actualiza
     * el precio de los renglones... y NADA MÁS: `SaleHelper::updateItemsPrices()` pisa los
     * pivotes pero nadie recalcula `sales.total`, y `updateCurrentAcountsAndCommissions()`
     * recrea el movimiento de deuda leyendo ese total viejo. Con 3 renglones que pasan de
     * 500 a 600, la venta queda mostrando renglones por 1800 con un total de 1500, y la
     * deuda del cliente se recrea por 1500.
     *
     * El invariante que DEBERÍA cumplirse (y hoy no): total = suma de renglones = 1800, y
     * la cuenta corriente lo sigue. Cuando se corrija, las dos aserciones marcadas se van
     * a poner rojas: cambiarlas a 1800 y cerrar el hallazgo en la bitácora.
     *
     * @group exploracion-vender
     * @test
     */
    public function actualizar_precios_de_la_venta_hoy_no_recalcula_el_total_ni_la_deuda()
    {
        $user = $this->autenticar();

        $articulo = $this->crear_articulo($user, 'zz Exploracion update prices', [
            'cost'            => 100,
            'percentage_gain' => 50,
            'stock'           => 20,
        ]);

        $payload = $this->payload_venta($user, [
            ['article' => $articulo, 'amount' => 3, 'price' => 500],
        ]);

        $this->postJson('api/sale', $payload)->assertStatus(201);

        $venta = Sale::where('client_id', $payload['client_id'])->orderBy('id', 'DESC')->first();
        $this->assertNotNull($venta, 'El POST no dejó ninguna venta.');
        $this->assertEqualsWithDelta(1500.0, (float) $venta->total, 0.01, 'La venta no nació con total 1500.');

        $stock_previo = $this->stock($articulo);

        /* El endpoint del modal, con la misma forma que manda la SPA. */
        $this->putJson('api/sale/update-prices/' . $venta->id, [
            'items' => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => 600,
                ],
            ],
        ])->assertStatus(200);

        $venta = Sale::find($venta->id);
        $pivote = $venta->articles()->where('articles.id', $articulo->id)->first();

        $this->assertEqualsWithDelta(
            600.0,
            (float) $pivote->pivot->price,
            0.01,
            'El renglón no tomó el precio nuevo.'
        );

        /*
         * 🔴 Comportamiento REAL (defecto): el total queda en el valor previo (1500) con los
         * renglones sumando 1800. Cuando update-prices recalcule el total, cambiar a 1800.
         */
        $this->assertEqualsWithDelta(
            1500.0,
            (float) $venta->total,
            0.01,
            'El comportamiento conocido (defectuoso) cambió: si el total ya es 1800, '
                . 'update-prices empezó a recalcular el total — actualizar este test a 1800 '
                . 'y cerrar el hallazgo en la bitácora.'
        );

        $movimiento = \App\Models\CurrentAcount::where('sale_id', $venta->id)
            ->whereNull('haber')
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($movimiento, 'La venta con cliente CC tenía que tener su movimiento de deuda.');

        /*
         * 🔴 Comportamiento REAL (defecto): la deuda recreada lee sales.total viejo (1500).
         * Lo correcto sería 1800 — la deuda del cliente quedó 300 abajo de lo vendido.
         */
        $this->assertEqualsWithDelta(
            1500.0,
            (float) $movimiento->debe,
            0.01,
            'El comportamiento conocido (defectuoso) cambió: si la deuda ya es 1800, '
                . 'update-prices empezó a recrear la cuenta corriente con el total nuevo — '
                . 'actualizar este test a 1800 y cerrar el hallazgo en la bitácora.'
        );

        $this->assertEquals(
            $stock_previo,
            $this->stock($articulo),
            'Actualizar PRECIOS no tenía que mover el stock.'
        );

        $this->deleteJson('api/sale/' . $venta->id)->assertStatus(200);
    }

    /**
     * 🔴 DEFECTO CONOCIDO, fijado a propósito (exploración 1/9/2026). Reportado, espera
     * decisión de Lucas — toca stock en producción y no se arregla solo.
     *
     * Para un artículo con STOCK GLOBAL (sin filas en address_article — el estado en que
     * nace un artículo del alta rápida de Vender, y el que CheckGlobalStock existe para
     * soportar), vender y BORRAR la venta le PIERDE stock:
     *
     *  - La venta descuenta del stock global (CheckGlobalStock, camino correcto).
     *  - El borrado repone con to_address_id = la sucursal de la venta
     *    (DeleteSaleHelper::regresar_stock → ArticleHelper::resetStock), y CheckToAddress
     *    le ATTACHEA ese depósito al artículo con lo repuesto como cantidad. A partir de
     *    ahí count(addresses) > 0: CheckGlobalStock ya no aplica y
     *    setArticleStockFromAddresses PISA el stock global con la suma de depósitos.
     *
     *  Con stock 20, vender 1 y borrar la venta: 20 → 19 → **1**. Se pierden 18 unidades
     *  en silencio. La EDICIÓN de la venta no tiene el problema (repone sin to_address).
     *
     * Este test fija el comportamiento REAL de hoy: el día que se corrija se va a poner
     * rojo, y esa es la señal buscada. Lo correcto sería que el stock volviera a 20.
     *
     * @group exploracion-vender
     * @test
     */
    public function borrar_la_venta_de_un_articulo_con_stock_global_pisa_el_stock()
    {
        $user = $this->autenticar();

        $articulo = $this->crear_articulo($user, 'zz Exploracion stock global borrado', [
            'cost'            => 100,
            'percentage_gain' => 50,
            'stock'           => 20,
        ]);

        $payload = $this->payload_venta($user, [
            ['article' => $articulo, 'amount' => 1, 'price' => 300],
        ]);

        $this->postJson('api/sale', $payload)->assertStatus(201);

        $venta = Sale::where('client_id', $payload['client_id'])->orderBy('id', 'DESC')->first();

        $this->assertEquals(19.0, $this->stock($articulo), 'La venta tenía que descontar 1 del stock global.');

        $this->deleteJson('api/sale/' . $venta->id)->assertStatus(200);

        /*
         * 🔴 Comportamiento REAL (defecto): el stock queda en 1 — lo repuesto — en vez de
         * volver a 20. Cuando el borrado repare el stock global, cambiar esta aserción a 20.
         */
        $this->assertEquals(
            1.0,
            $this->stock($articulo),
            'El comportamiento conocido (defectuoso) cambió: si ahora el stock vuelve a 20, '
                . 'el defecto del borrado sobre stock global se corrigió — actualizar este test a 20 '
                . 'y cerrar el hallazgo en la bitácora de exploración.'
        );
    }

    /**
     * I3: el precio de la venta es un hecho histórico. Cambiarle el precio al artículo no
     * reescribe la venta; actualizar la venta respeta el price_vender que se manda.
     *
     * @group exploracion-vender
     * @test
     */
    public function cambiar_el_precio_del_articulo_no_reescribe_la_venta_guardada()
    {
        $user = $this->autenticar();

        $articulo = $this->crear_articulo($user, 'zz Exploracion precio congelado', [
            'cost'            => 1000,
            'percentage_gain' => 50,
            'stock'           => 20,
        ]);

        $articulo_b = $this->crear_articulo($user, 'zz Exploracion precio B', [
            'cost'            => 100,
            'percentage_gain' => 50,
            'stock'           => 20,
        ]);

        /* La venta guarda 3 unidades a 500: total 1500. */
        $payload = $this->payload_venta($user, [
            ['article' => $articulo, 'amount' => 3, 'price' => 500],
        ]);

        $this->postJson('api/sale', $payload)->assertStatus(201);

        $venta = Sale::where('client_id', $payload['client_id'])->orderBy('id', 'DESC')->first();
        $this->assertNotNull($venta, 'El POST no dejó ninguna venta.');

        $this->assertEqualsWithDelta(1500.0, (float) $venta->total, 0.01, 'La venta no nació con su total.');

        /* El artículo duplica su costo (y con él su precio final). */
        $final_previo = (float) $articulo->final_price;

        $modelo = Article::find($articulo->id);
        $modelo->cost = 2000;
        $modelo->save();
        ArticleHelper::setFinalPrice($modelo, $user->id);

        $this->assertGreaterThan(
            $final_previo * 1.9,
            (float) Article::find($articulo->id)->final_price,
            'El cambio de costo no movió el precio final del artículo: el resto del test no probaría nada.'
        );

        /* La venta guardada NO se entera: ni el pivote ni el total. */
        $venta = Sale::find($venta->id);
        $pivote = $venta->articles()->where('articles.id', $articulo->id)->first();

        $this->assertNotNull($pivote, 'La venta perdió su renglón.');
        $this->assertEqualsWithDelta(
            500.0,
            (float) $pivote->pivot->price,
            0.01,
            'El precio del renglón de una venta GUARDADA cambió al cambiar el precio del artículo.'
        );
        $this->assertEqualsWithDelta(
            1500.0,
            (float) $venta->total,
            0.01,
            'El total de una venta GUARDADA cambió al cambiar el precio del artículo.'
        );

        /*
         * Actualizo la venta agregando un renglón nuevo y MANTENIENDO el price_vender
         * original del viejo, que es lo que manda la SPA cuando el operador no tocó el
         * precio. El servidor lo tiene que respetar: si lo "refrescara" al precio vigente
         * del artículo (que ahora es el doble), editar una venta para agregarle un renglón
         * le cambiaría la plata a los renglones viejos en silencio.
         */
        $this->putJson('api/sale/' . $venta->id, $this->payload_venta($user, [
            ['article' => $articulo,   'amount' => 3, 'price' => 500],
            ['article' => $articulo_b, 'amount' => 1, 'price' => 200],
        ], ['id' => $venta->id]))->assertStatus(200);

        $venta = Sale::find($venta->id);
        $pivote = $venta->articles()->where('articles.id', $articulo->id)->first();

        $this->assertEqualsWithDelta(
            500.0,
            (float) $pivote->pivot->price,
            0.01,
            'Al actualizar la venta, el renglón viejo tenía que conservar su precio de 500 '
                . '(el servidor no puede refrescarlo al precio vigente del artículo).'
        );

        $this->assertEqualsWithDelta(
            1700.0,
            (float) $venta->total,
            0.01,
            'El total después de la edición tenía que ser 3×500 + 1×200 = 1700.'
        );

        /* Limpieza del stock comprometido: la venta se borra y todo vuelve. */
        $this->deleteJson('api/sale/' . $venta->id)->assertStatus(200);
    }
}
