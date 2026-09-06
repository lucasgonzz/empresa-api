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
     * El endpoint del modal "Actualizar precios" (PUT sale/update-prices/{id}) es el PASO 1
     * de un flujo de DOS pasos — aclarado por Lucas el 2/9/2026, después de que la
     * exploración del 1/9 lo reportara como defecto:
     *
     *   1. El modal confirma → este endpoint pisa el `price` de los pivotes → la SPA carga
     *      la venta en Vender (`setPreviusSale`, update-prices/Index.vue).
     *   2. El operador guarda en Vender → PUT api/sale/{id} → recién ahí se recalculan el
     *      total y la cuenta corriente (ese paso lo custodia el test de precio congelado de
     *      esta misma clase, que verifica total y pivotes tras el PUT de venta).
     *
     * Lo que este test fija es el estado INTERMEDIO, y por qué importa: el paso 1 PERSISTE.
     * Si el operador no completa el paso 2 (cierra Vender, se corta la sesión), la venta
     * queda con renglones nuevos (suman 1800) y total y deuda viejos (1500) — ese estado a
     * mitad de camino es alcanzable y queda guardado. Reportado como ventana de abandono;
     * si algún día el paso 1 deja de persistir (o pasa a recalcular), estas aserciones se
     * ponen rojas y hay que releer el flujo completo.
     *
     * @group exploracion-vender
     * @test
     */
    public function actualizar_precios_de_la_venta_persiste_renglones_sin_recalcular_total_ni_deuda()
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
         * Estado intermedio del paso 1: el total queda en el valor previo (1500) con los
         * renglones ya persistidos sumando 1800. El recálculo llega recién con el guardado
         * en Vender (paso 2). Si esta aserción se pone roja, el paso 1 cambió de contrato.
         */
        $this->assertEqualsWithDelta(
            1500.0,
            (float) $venta->total,
            0.01,
            'El paso 1 de update-prices cambió de contrato: ahora recalcula el total. '
                . 'Releer el flujo de dos pasos y actualizar este test y la bitácora.'
        );

        $movimiento = \App\Models\CurrentAcount::where('sale_id', $venta->id)
            ->whereNull('haber')
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($movimiento, 'La venta con cliente CC tenía que tener su movimiento de deuda.');

        /*
         * Ídem: la deuda recreada por el paso 1 lee sales.total viejo (1500). En el flujo
         * completo la corrige el paso 2; en un flujo abandonado queda 300 abajo de lo vendido.
         */
        $this->assertEqualsWithDelta(
            1500.0,
            (float) $movimiento->debe,
            0.01,
            'El paso 1 de update-prices cambió de contrato: ahora recrea la deuda con el '
                . 'total nuevo. Releer el flujo de dos pasos y actualizar este test y la bitácora.'
        );

        $this->assertEquals(
            $stock_previo,
            $this->stock($articulo),
            'Actualizar PRECIOS no tenía que mover el stock.'
        );

        $this->deleteJson('api/sale/' . $venta->id)->assertStatus(200);
    }

    /**
     * ✅ CORREGIDO en la auditoría de stock del 5/9/2026 (CheckToAddress ya no abre el primer
     * depósito de un artículo con stock global por una devolución). Este test fijaba el defecto
     * a propósito desde la exploración del 1/9/2026, con la instrucción de cambiar la aserción a
     * 20 el día que se corrigiera: ese día llegó, y la aserción de abajo es la del comportamiento
     * correcto. Lo que sigue es la descripción del defecto tal como estaba, para entender qué
     * custodia este test.
     *
     * Para un artículo con STOCK GLOBAL (sin filas en address_article), vender y BORRAR la
     * venta le PERDÍA stock.
     *
     * CÓMO SE LLEGA A ESE ESTADO POR LA INTERFAZ — medido el 2/9/2026 contra el endpoint
     * real, después de que Lucas señalara (con razón) que el modal de movimiento de stock
     * NO sirve para esto: ese modal EXIGE depósito cuando la cuenta tiene alguno
     * (listado/modals/stock-movement/Form.vue:223, `check()` → "Indique el deposito").
     *
     * El camino que SÍ lo produce es la **actualización masiva del listado sobre el campo
     * Stock**: `MasiveUpdateHelper::apply_form_change()` escribe `$model->stock` y hace
     * save() sin tocar `address_article` en ningún momento. Medido con
     * `PUT api/update/article` + `set_stock`: el artículo queda con 20 en la columna y CERO
     * filas de depósito. `stock` es una prop numérica común del modelo
     * (empresa-spa/src/models/article.js:510), así que está en el modal de la masiva.
     *
     * También llegan a este estado los datos anteriores a que la cuenta usara depósitos.
     *
     *  - La venta descuenta del stock global (CheckGlobalStock, camino correcto).
     *  - El borrado repone con to_address_id = la sucursal de la venta
     *    (DeleteSaleHelper::regresar_stock → ArticleHelper::resetStock), y CheckToAddress
     *    le ATTACHEA ese depósito al artículo con lo repuesto como cantidad. A partir de
     *    ahí count(addresses) > 0: CheckGlobalStock ya no aplica y
     *    setArticleStockFromAddresses PISA el stock global con la suma de depósitos.
     *
     *  Con stock 20, vender 1 y borrar la venta: 20 → 19 → **1**. Se perdían 18 unidades
     *  en silencio. La EDICIÓN de la venta no tenía el problema (repone sin to_address).
     *
     * Ahora el borrado repone sobre el stock global y el artículo sigue sin depósitos.
     *
     * @group exploracion-vender
     * @test
     */
    public function borrar_la_venta_de_un_articulo_con_stock_global_repone_el_stock()
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
         * Hasta el 5/9/2026 acá se afirmaba 1.0 (lo repuesto pisaba el stock global). El borrado
         * ahora repone sobre el stock global: 20 → 19 → 20, y el artículo sigue sin depósitos.
         */
        $this->assertEquals(
            20.0,
            $this->stock($articulo),
            'Borrar la venta tenía que devolver el stock global a 20 (era el defecto de la '
                . 'exploración del 1/9/2026: el borrado abría un depósito y pisaba el global con 1).'
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
