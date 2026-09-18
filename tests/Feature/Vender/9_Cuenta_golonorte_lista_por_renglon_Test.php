<?php

namespace Tests\Feature\Vender;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión vender-lista-obligatoria, tanda 2 (18/9/2026): la cuenta tipo golonorte, donde la lista
 * de precios viaja POR RENGLÓN y no por comprobante.
 *
 * EL CASO REAL: golonorte tiene `users.listas_de_precio = 0` y la extensión
 * `lista_de_precios_por_categoria` (márgenes por categoría, con listas por rango de cantidad).
 * Sus 2.377 ventas de 30 días (auditoría del 17/9/2026) tienen `sales.price_type_id` en null
 * (2.068) o en 0 (309), y la lista con la que se cobró cada línea va en
 * `article_sale.price_type_personalizado_id`, que la SPA setea por renglón
 * (`price_ranges.js::check_price_type_ranges()`) y prioriza sobre la lista de la venta
 * (`generals.js`). No venden a costo: el precio cobrado coincide con el pivote de esa lista.
 *
 * QUÉ FIJA Y POR QUÉ. Dos cosas que la regla nueva del 422 no puede romper:
 *  - la regla se ancla en `users.listas_de_precio` (`PriceTypeHelper::requiere_lista_de_precios()`),
 *    así que a esta cuenta NO la alcanza aunque tenga listas cargadas y extensión: alta y edición
 *    con `sales.price_type_id` null (o 0, que se guarda como null) siguen siendo 201/200;
 *  - la lista por renglón se persiste tal cual en el pivote —`SaleHelper::attachArticle()` pasa
 *    `price_type_personalizado_id` por `get_price_type_personalizado()`— y el 0 con el que nacen
 *    los ítems en Vender (`vender.js`, `deteccion_combos.js`, `vender/index.js`) NUNCA llega al
 *    pivote: se guarda null. Es el mismo criterio "0 es ninguna" de `sales.price_type_id`, y lo
 *    que `PuntosBaseHelper::lista_efectiva_del_renglon()` ya asume al leer las dos columnas.
 *
 * DatabaseTransactions (no RefreshDatabase): la base del slot está sembrada de antes. Listas y
 * artículos se crean adentro de la transacción con prefijo `zz`; la extensión se cuelga del
 * usuario 500 adentro de la transacción (creando la fila del catálogo si falta, con forceCreate
 * porque el modelo no declara $fillable, como tests/Feature/Precios/1 y Vender/4); y
 * `users.listas_de_precio` se restaura en tearDown. La venta que se edita se arma directo en la
 * base (molde de Vender/4), sin métodos de pago adjuntos porque el usuario 500 tiene cajas y una
 * venta ya cobrada no se puede editar.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Cuenta_golonorte_lista_por_renglon_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing (TestingFerreteriaSeeder). */
    const USER_ID = 500;

    /** @var string El slug con el que golonorte tiene la extensión (mismo que la SPA). */
    const EXTENCION_POR_CATEGORIA = 'lista_de_precios_por_categoria';

    /** @var int Efectivo, del catálogo global. Toda venta de contado lo manda (tanda 2, A7). */
    const METODO_EFECTIVO = 3;

    /** @var float Tolerancia de un centavo. */
    const DELTA = 0.01;

    /** @var int Precio del artículo A en la lista X. */
    const PRECIO_A_EN_X = 140;

    /** @var int Precio del artículo A en la lista Y. */
    const PRECIO_A_EN_Y = 110;

    /** @var int `articles.final_price` del artículo B, que se cobra sin lista por renglón. */
    const PRECIO_B_BASE = 90;

    /** @var int Cantidad de cada renglón. */
    const CANTIDAD = 1;

    /** @var \App\Models\User */
    protected $user;

    /** @var int|null Valor original de users.listas_de_precio, para dejarlo como estaba. */
    protected $listas_original = null;

    /** @var \App\Models\PriceType Una de las listas por categoría. */
    protected $lista_x;

    /** @var \App\Models\PriceType La otra. */
    protected $lista_y;

    /** @var \App\Models\Article Artículo con precio en las dos listas. */
    protected $article_a;

    /** @var \App\Models\Article Artículo sin lista por renglón: viaja con el 0 con el que nace el ítem. */
    protected $article_b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->listas_original = $this->user->listas_de_precio;

        $this->actingAs($this->user, 'web');

        /* La cuenta golonorte: flag apagado + extensión por categoría, con listas cargadas. */
        User::where('id', self::USER_ID)->update(['listas_de_precio' => 0]);

        $this->dar_extension_por_categoria();

        $this->lista_x = PriceType::create([
            'name'     => 'zz Lista X por categoria (golonorte)',
            'user_id'  => self::USER_ID,
            'position' => 5,
        ]);

        $this->lista_y = PriceType::create([
            'name'     => 'zz Lista Y por categoria (golonorte)',
            'user_id'  => self::USER_ID,
            'position' => 2,
        ]);

        /* Sin stock a propósito: acá se mide la lista por renglón, no el stock. */
        $this->article_a = Article::create([
            'name'        => 'zz Articulo A golonorte',
            'user_id'     => self::USER_ID,
            'final_price' => 100,
            'status'      => 'active',
        ]);

        $this->article_a->price_types()->attach($this->lista_x->id, ['final_price' => self::PRECIO_A_EN_X]);
        $this->article_a->price_types()->attach($this->lista_y->id, ['final_price' => self::PRECIO_A_EN_Y]);

        $this->article_b = Article::create([
            'name'        => 'zz Articulo B golonorte',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO_B_BASE,
            'status'      => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        if (!is_null($this->user) && !is_null($this->listas_original)) {
            User::where('id', self::USER_ID)->update(['listas_de_precio' => $this->listas_original]);
        }

        parent::tearDown();
    }

    /**
     * Le da a la cuenta la extensión de listas por categoría, creando la fila del catálogo si la
     * base del slot no la tiene sembrada. DatabaseTransactions revierte las dos filas.
     *
     * @return void
     */
    protected function dar_extension_por_categoria()
    {
        $extencion = ExtencionEmpresa::where('slug', self::EXTENCION_POR_CATEGORIA)->first();

        if (is_null($extencion)) {

            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::EXTENCION_POR_CATEGORIA,
                'name' => 'Lista de precios por categoria',
            ]);
        }

        if (!$this->user->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $this->user->extencions()->attach($extencion->id);
        }
    }

    /**
     * Renglón tal como lo manda Vender en esta cuenta: el precio ya resuelto por la lista del
     * rango y `price_type_personalizado_id` con el id de esa lista, o con el 0 con el que nace el
     * ítem cuando ningún rango aplica.
     *
     * @param  \App\Models\Article  $article
     * @param  float                $price_vender
     * @param  int                  $price_type_personalizado_id
     * @return array
     */
    protected function renglon($article, $price_vender, $price_type_personalizado_id)
    {
        return [
            'is_article'                  => true,
            'id'                          => $article->id,
            'name'                        => $article->name,
            'price_vender'                => $price_vender,
            'amount'                      => self::CANTIDAD,
            'price_type_personalizado_id' => $price_type_personalizado_id,
        ];
    }

    /**
     * Los dos renglones de los tests de alta: A con la lista X por renglón y B con el 0.
     *
     * @return array
     */
    protected function renglones_a_en_x_y_b_en_cero()
    {
        return [
            $this->renglon($this->article_a, self::PRECIO_A_EN_X, $this->lista_x->id),
            $this->renglon($this->article_b, self::PRECIO_B_BASE, 0),
        ];
    }

    /**
     * @param  array  $items
     * @return float
     */
    protected function total_de($items)
    {
        $total = 0;

        foreach ($items as $item) {
            $total += (float) $item['price_vender'] * (float) $item['amount'];
        }

        return $total;
    }

    /**
     * Payload de POST api/sale de una venta de mostrador, calcado de tests/Feature/Vender/4, con
     * `price_type_id` en null como lo manda la SPA de golonorte (`setPriceType()` no commitea
     * nada con el flag apagado).
     *
     * @param  array  $items
     * @param  array  $overrides
     * @return array
     */
    protected function payload_venta($items, $overrides = [])
    {
        $total = $this->total_de($items);

        return array_merge([
            'client_id'                         => null,
            'address_id'                        => null,
            'save_current_acount'               => 0,
            'omitir_en_cuenta_corriente'        => 0,
            'to_check'                          => 0,
            'price_type_id'                     => null,
            'current_acount_payment_method_id'  => self::METODO_EFECTIVO,
            'discounts_in_services'             => 1,
            'surchages_in_services'             => 1,
            'employee_id'                       => null,
            'sub_total'                         => $total,
            'total'                             => $total,
            'terminada'                         => 1,
            'seller_id'                         => null,
            'cantidad_cuotas'                   => null,
            'cuota_descuento'                   => 0,
            'cuota_recargo'                     => 0,
            'caja_id'                           => null,
            'afip_tipo_comprobante_id'          => null,
            'descuento'                         => null,
            'moneda_id'                         => 1,
            'discount_stock'                    => 0,
            'discounts'                         => [],
            'surchages'                         => [],
            'items'                             => $items,
        ], $overrides);
    }

    /**
     * Payload mínimo y válido de PUT api/sale/{id}, calcado de tests/Feature/Vender/4, con
     * `price_type_id` en null explícito: es lo que manda la SPA nueva en esta cuenta al editar.
     *
     * @param  array  $items
     * @param  array  $overrides
     * @return array
     */
    protected function payload_update($items, $overrides = [])
    {
        $total = $this->total_de($items);

        return array_merge([
            'client_id'                         => null,
            'save_current_acount'               => 0,
            'omitir_en_cuenta_corriente'        => 0,
            'to_check'                          => 0,
            'checked'                           => 0,
            'confirmed'                         => 0,
            'price_type_id'                     => null,
            'current_acount_payment_method_id'  => self::METODO_EFECTIVO,
            'discounts_in_services'             => 1,
            'surchages_in_services'             => 1,
            'discount_stock'                    => 0,
            'sub_total'                         => $total,
            'total'                             => $total,
            'moneda_id'                         => 1,
            'items'                             => $items,
            'discounts'                         => [],
            'surchages'                         => [],
            'returned_items'                    => [],
        ], $overrides);
    }

    /**
     * Venta golonorte ya guardada: sin lista de venta, con los dos renglones cobrados por la
     * lista X (la lista por renglón en el pivote). Directo a la base: lo que se mide es update().
     *
     * @return \App\Models\Sale
     */
    protected function venta_guardada_con_lista_por_renglon()
    {
        $total = (self::PRECIO_A_EN_X + self::PRECIO_A_EN_X) * self::CANTIDAD;

        $sale = Sale::create([
            'user_id'                    => self::USER_ID,
            'client_id'                  => null,
            'price_type_id'              => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'discount_stock'             => 0,
            'moneda_id'                  => 1,
            'sub_total'                  => $total,
            'total'                      => $total,
        ]);

        $sale->articles()->attach($this->article_a->id, [
            'amount'                      => self::CANTIDAD,
            'price'                       => self::PRECIO_A_EN_X,
            'cost'                        => 0,
            'price_type_personalizado_id' => $this->lista_x->id,
        ]);

        $sale->articles()->attach($this->article_b->id, [
            'amount'                      => self::CANTIDAD,
            'price'                       => self::PRECIO_A_EN_X,
            'cost'                        => 0,
            'price_type_personalizado_id' => $this->lista_x->id,
        ]);

        return $sale;
    }

    /**
     * El pivote de un artículo en una venta, leído con DB::table.
     *
     * @param  int  $sale_id
     * @param  int  $article_id
     * @return object|null
     */
    protected function pivote($sale_id, $article_id)
    {
        return DB::table('article_sale')
                    ->where('sale_id', $sale_id)
                    ->where('article_id', $article_id)
                    ->first();
    }

    /**
     * Las aserciones del pivote, compartidas: A guarda el id de la lista que viajó y su precio; B
     * guarda NULL (no 0) y su precio.
     *
     * @param  int  $sale_id
     * @param  int  $lista_de_a_esperada
     * @param  float  $precio_de_a_esperado
     * @return void
     */
    protected function assert_pivotes($sale_id, $lista_de_a_esperada, $precio_de_a_esperado)
    {
        $pivote_a = $this->pivote($sale_id, $this->article_a->id);

        $this->assertNotNull($pivote_a, 'El artículo A tiene que estar en article_sale.');
        $this->assertEquals(
            $lista_de_a_esperada,
            (int) $pivote_a->price_type_personalizado_id,
            'El pivote guarda la lista por renglón que viajó en el ítem.'
        );
        $this->assertEqualsWithDelta($precio_de_a_esperado, (float) $pivote_a->price, self::DELTA, 'El precio del renglón es el que resolvió la lista del rango.');

        $pivote_b = $this->pivote($sale_id, $this->article_b->id);

        $this->assertNotNull($pivote_b, 'El artículo B tiene que estar en article_sale.');
        $this->assertNull(
            $pivote_b->price_type_personalizado_id,
            'El 0 con el que nace el ítem NUNCA llega al pivote: se guarda null, no 0.'
        );
        $this->assertEqualsWithDelta(self::PRECIO_B_BASE, (float) $pivote_b->price, self::DELTA);
    }

    /**
     * 🔴 EL ALTA DE GOLONORTE: flag apagado, extensión por categoría, listas cargadas, la venta
     * sin lista y cada renglón con la suya. 201, `sales.price_type_id` null, el pivote de A con
     * la lista X y el de B con null (no con el 0 que viajó). Antes de pegar, el test confirma que
     * el criterio da false para esta cuenta: si diera true, el 201 no estaría midiendo nada.
     *
     * @group vender
     * @test
     */
    public function alta_sin_lista_de_venta_y_con_lista_por_renglon_guarda_el_id_en_el_pivote_y_null_donde_vino_cero()
    {
        $this->assertFalse(
            PriceTypeHelper::requiere_lista_de_precios(User::find(self::USER_ID)),
            'Con listas_de_precio = 0 el criterio tiene que dar false aunque haya listas y extensión: la regla se ancla en el flag.'
        );

        $response = $this->postJson('api/sale', $this->payload_venta($this->renglones_a_en_x_y_b_en_cero()));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);
        $this->assertNull($sale->price_type_id, 'La venta de golonorte no lleva lista de venta: null, como siempre.');

        $this->assert_pivotes($sale->id, $this->lista_x->id, self::PRECIO_A_EN_X);
    }

    /**
     * La forma exacta de las 309 ventas con 0: `price_type_id: 0` en la venta y la lista por
     * renglón en los ítems. 201, y el 0 de la venta se guarda como null (no como 0) sin tocar la
     * lista por renglón.
     *
     * @group vender
     * @test
     */
    public function alta_con_lista_cero_en_la_venta_la_guarda_como_null_y_conserva_la_lista_por_renglon()
    {
        $response = $this->postJson('api/sale', $this->payload_venta($this->renglones_a_en_x_y_b_en_cero(), [
            'price_type_id' => 0,
        ]));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);
        $this->assertNull($sale->price_type_id, 'El 0 no es una lista: se persiste como null.');

        $this->assert_pivotes($sale->id, $this->lista_x->id, self::PRECIO_A_EN_X);
    }

    /**
     * LA EDICIÓN de golonorte: PUT con `price_type_id` null explícito (lo que manda la SPA nueva
     * en esta cuenta) sobre una venta cuyos dos renglones tenían la lista X. A pasa a la lista Y
     * (otro rango) con su precio, y B viaja con el 0 con el que nace el ítem → queda null. La
     * venta sigue sin lista y no recibe ningún 422.
     *
     * @group vender
     * @test
     */
    public function edicion_reescribe_la_lista_por_renglon_y_el_cero_nunca_llega_al_pivote()
    {
        $venta = $this->venta_guardada_con_lista_por_renglon();

        $items = [
            $this->renglon($this->article_a, self::PRECIO_A_EN_Y, $this->lista_y->id),
            $this->renglon($this->article_b, self::PRECIO_B_BASE, 0),
        ];

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update($items));

        $response->assertStatus(200);

        $despues = Sale::find($venta->id);

        $this->assertNull($despues->price_type_id, 'Una cuenta sin listas edita con price_type_id null y la venta queda sin lista.');

        $this->assert_pivotes($venta->id, $this->lista_y->id, self::PRECIO_A_EN_Y);
    }

    /**
     * Y la edición desde la SPA anterior, SIN la clave `price_type_id`: mismo resultado. La lista
     * por renglón la manda el ítem, no el comprobante, así que la clave ausente no cambia nada
     * en esta cuenta.
     *
     * @group vender
     * @test
     */
    public function edicion_sin_la_clave_price_type_id_reescribe_igual_la_lista_por_renglon()
    {
        $venta = $this->venta_guardada_con_lista_por_renglon();

        $items = [
            $this->renglon($this->article_a, self::PRECIO_A_EN_Y, $this->lista_y->id),
            $this->renglon($this->article_b, self::PRECIO_B_BASE, 0),
        ];

        $payload = $this->payload_update($items);

        unset($payload['price_type_id']);

        $response = $this->putJson('api/sale/'.$venta->id, $payload);

        $response->assertStatus(200);

        $this->assertNull(Sale::find($venta->id)->price_type_id);

        $this->assert_pivotes($venta->id, $this->lista_y->id, self::PRECIO_A_EN_Y);
    }
}
