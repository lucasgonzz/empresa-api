<?php

namespace Tests\Feature\RecargosEnPrecios;

use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\Combo;
use App\Models\PromocionVinoteca;
use App\Models\Sale;
use App\Models\Service;
use App\Models\Surchage;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Base comun de la suite de "recargos adentro de los precios, editable" (mision
 * recargos-en-precios-editable, 28/9/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUE PRUEBA ESTA SUITE, EN UNA LINEA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Que la API PERSISTE y PROPAGA `price_sin_recargos_de_venta` (el precio de cada renglon sin los
 *  recargos de venta) en todos los caminos que escriben o copian renglones. La API no calcula ese
 *  precio: lo calcula la SPA y lo manda en `price_vender_sin_recargos`. Por eso los numeros de los
 *  payloads estan escritos A MANO (base 100 con el 10 % adentro = 110), no calculados con ningun
 *  helper del sistema.
 *
 *  La invariante que se mide: NO NULL = el `price` del renglon tiene el recargo adentro y el valor
 *  es el precio sin el; NULL = no lo tiene. Toda afirmacion sobre la base se lee DIRECTO de la
 *  tabla del renglon con `DB::table()`, nunca por la relacion de Eloquent: si la relacion leyera
 *  mal, un test que la usara daria verde con la columna vacia.
 *
 * ⚠️ `SaleController::store()` tiene una guarda anti-duplicados de 5 segundos por comercio, cliente
 * y TOTAL: un test que crea dos ventas les tiene que dar totales distintos.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
abstract class RecargosEnPreciosTestCase extends EmpresaTestCase
{
    /** Tolerancia de plata. */
    const DELTA = 0.01;

    /** El recargo de venta de toda la suite, en porcentaje. */
    const PORCENTAJE_RECARGO = 10;

    /** Ids de `budget_statuses`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /**
     * `budget_statuses` puede venir vacia en la base del slot, y `BudgetHelper::checkStatus()` hace
     * `$budget->budget_status->name` sin chequear null. Se siembra con ids explicitos; la
     * transaccion del test lo revierte.
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
     * @return \App\Models\User
     */
    protected function comercio()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return $user;
    }

    /**
     * @return \App\Models\Client
     */
    protected function cliente_cc()
    {
        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CC)->first();

        $this->assertNotNull($client, 'Falta el cliente de cuenta corriente del fixture.');

        return $client;
    }

    /**
     * @return \App\Models\Article
     */
    protected function articulo_centinela()
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $this->assertNotNull($articulo, 'Falta el articulo centinela del fixture.');

        return $articulo;
    }

    /**
     * Un recargo de venta del comercio.
     *
     * @return \App\Models\Surchage
     */
    protected function recargo()
    {
        return Surchage::create([
            'name'       => 'zz Recargo suite recargos en precios '.uniqid(),
            'percentage' => self::PORCENTAJE_RECARGO,
            'user_id'    => $this->comercio()->id,
        ]);
    }

    /**
     * ⚠️ La tabla `services` viene vacia en la base del slot: el servicio se crea.
     *
     * @param  float  $price
     * @return \App\Models\Service
     */
    protected function servicio($price)
    {
        return Service::create([
            'name'    => 'zz Servicio suite recargos en precios '.uniqid(),
            'price'   => $price,
            'user_id' => $this->comercio()->id,
        ]);
    }

    /**
     * Un combo sin componentes con stock: la suite mide precios, no stock (con `discount_stock` en 0
     * `ComboHelper::discount_articles_stock()` ni entra).
     *
     * @param  float  $price
     * @return \App\Models\Combo
     */
    protected function combo($price)
    {
        return Combo::create([
            'num'     => 9801,
            'name'    => 'zz Combo suite recargos en precios '.uniqid(),
            'price'   => $price,
            'cost'    => 0,
            'user_id' => $this->comercio()->id,
        ]);
    }

    /**
     * Una promocion vinoteca con stock de sobra: `attachPromocionVinotecas()` le descuenta stock
     * siempre que la venta este confirmada.
     *
     * @param  float  $price
     * @return \App\Models\PromocionVinoteca
     */
    protected function promocion($price)
    {
        return PromocionVinoteca::create([
            'name'        => 'zz Promo suite recargos en precios '.uniqid(),
            'stock'       => 100,
            'cost'        => 0,
            'final_price' => $price,
            'user_id'     => $this->comercio()->id,
        ]);
    }

    /**
     * El recargo como lo manda VENDER en `surchages` (venta) o en `surchages` (presupuesto).
     *
     * @param  \App\Models\Surchage  $surchage
     * @return array
     */
    protected function recargo_del_payload($surchage)
    {
        return [
            'id'         => $surchage->id,
            'percentage' => self::PORCENTAJE_RECARGO,
        ];
    }

    /**
     * Renglon de VENDER. `$base` = el `price_vender_sin_recargos`; `false` = la clave NO viaja (la
     * SPA anterior a esta mision), que no es lo mismo que mandarla en null.
     *
     * @param  string      $tipo     'article' | 'service' | 'combo' | 'promocion_vinoteca'
     * @param  int         $id
     * @param  float       $price    El `price_vender`: el precio final que se guarda.
     * @param  float|null|false  $base
     * @param  float       $amount
     * @return array
     */
    protected function item_vender($tipo, $id, $price, $base, $amount = 1)
    {
        $item = [
            'is_'.$tipo    => true,
            'id'           => $id,
            'price_vender' => $price,
            'amount'       => $amount,
        ];

        if ($tipo === 'combo') {
            $item['articles'] = [];
        }

        if ($base !== false) {
            $item['price_vender_sin_recargos'] = $base;
        }

        return $item;
    }

    /**
     * Payload de POST api/sale de mostrador (sin cliente y sin cuenta corriente), sin metodo de
     * pago: un request que no habla del cobro no se rechaza (`PaymentMethodHelper`).
     *
     * @param  array  $items
     * @param  float  $total
     * @param  int    $flag       `aplicar_recargos_directo_a_items`.
     * @param  array  $surchages
     * @param  array  $overrides
     * @return array
     */
    protected function payload_venta($items, $total, $flag, $surchages, $overrides = [])
    {
        return array_merge([
            'client_id'                        => null,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 1,
            'to_check'                         => 0,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => $flag,
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
            'moneda_id'                        => 1,
            'discount_stock'                   => 0,
            'discounts'                        => [],
            'surchages'                        => $surchages,
            'items'                            => $items,
        ], $overrides);
    }

    /**
     * Payload de PUT api/sale/{id}. `to_check` / `checked` / `confirmed` y los `*_in_services` son
     * NOT NULL y `update()` los asigna a secas: faltar cualquiera revienta el update.
     *
     * @param  \App\Models\Sale  $sale
     * @param  array  $items
     * @param  float  $total
     * @param  int    $flag
     * @param  array  $surchages
     * @param  array  $overrides
     * @return array
     */
    protected function payload_actualizar($sale, $items, $total, $flag, $surchages, $overrides = [])
    {
        return array_merge([
            'client_id'                        => $sale->client_id,
            'save_current_acount'              => $sale->save_current_acount,
            'omitir_en_cuenta_corriente'       => $sale->omitir_en_cuenta_corriente,
            'to_check'                         => 0,
            'checked'                          => 0,
            'confirmed'                        => 0,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => $flag,
            'discount_stock'                   => 0,
            'sub_total'                        => $total,
            'total'                            => $total,
            'moneda_id'                        => 1,
            'items'                            => $items,
            'discounts'                        => [],
            'surchages'                        => $surchages,
            'returned_items'                   => [],
        ], $overrides);
    }

    /**
     * Crea una venta por el endpoint real y la devuelve leida de la base.
     *
     * @param  array  $payload
     * @return \App\Models\Sale
     */
    protected function crear_venta($payload)
    {
        $response = $this->postJson('api/sale', $payload);

        $response->assertStatus(201);

        $id = $response->json('model.id');

        $this->assertNotNull($id, 'POST api/sale no devolvio la venta creada.');

        return Sale::find($id);
    }

    /**
     * Payload de POST / PUT api/budget. Los cuatro arrays de renglones van PRESENTES: los
     * `attach*` de `BudgetHelper` hacen foreach sin chequear null (salvo combos).
     *
     * @param  float  $total
     * @param  int    $flag
     * @param  array  $surchages
     * @param  array  $renglones  ['articles' => [...], 'services' => [...], ...]
     * @param  array  $overrides
     * @return array
     */
    protected function payload_presupuesto($total, $flag, $surchages, $renglones, $overrides = [])
    {
        return array_merge([
            'client_id'                        => $this->cliente_cc()->id,
            'start_at'                         => null,
            'finish_at'                        => null,
            'observations'                     => null,
            'price_type_id'                    => null,
            'sale_status_id'                   => null,
            'discount_stock'                   => 0,
            'iva_aplicado'                     => 1,
            'total'                            => $total,
            'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
            'address_id'                       => null,
            'surchages_in_services'            => 1,
            'discounts_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => $flag,
            'moneda_id'                        => 1,
            'valor_dolar'                      => null,
            'discounts'                        => [],
            'surchages'                        => $surchages,
            'articles'                         => isset($renglones['articles']) ? $renglones['articles'] : [],
            'services'                         => isset($renglones['services']) ? $renglones['services'] : [],
            'promocion_vinotecas'              => isset($renglones['promocion_vinotecas']) ? $renglones['promocion_vinotecas'] : [],
            'combos'                           => isset($renglones['combos']) ? $renglones['combos'] : [],
        ], $overrides);
    }

    /**
     * Renglon de articulo de presupuesto. `$en_pivot` = como lo manda VENDER al ACTUALIZAR un
     * renglon que ya estaba cargado (y el form generico); si no, plano, como en el alta.
     * `$base` en `false` = la clave no viaja.
     *
     * @param  int    $article_id
     * @param  float  $price
     * @param  float|null|false  $base
     * @param  bool   $en_pivot
     * @param  float  $amount
     * @return array
     */
    protected function renglon_articulo_presupuesto($article_id, $price, $base, $en_pivot, $amount = 1)
    {
        $datos = [
            'amount'   => $amount,
            'bonus'    => null,
            'location' => null,
            'price'    => $price,
        ];

        if ($base !== false) {
            $datos['price_vender_sin_recargos'] = $base;
        }

        if ($en_pivot) {
            return [
                'id'     => $article_id,
                'status' => 'active',
                'pivot'  => $datos,
            ];
        }

        return array_merge([
            'id'     => $article_id,
            'status' => 'active',
        ], $datos);
    }

    /**
     * Renglon de servicio / promocion / combo de presupuesto: siempre bajo `pivot`
     * (`vender_presupuestos.js::get_services()` y hermanas).
     *
     * @param  int    $id
     * @param  float  $price
     * @param  float|null|false  $base
     * @param  float  $amount
     * @return array
     */
    protected function renglon_pivot_presupuesto($id, $price, $base, $amount = 1)
    {
        $pivot = [
            'amount' => $amount,
            'price'  => $price,
        ];

        if ($base !== false) {
            $pivot['price_vender_sin_recargos'] = $base;
        }

        return [
            'id'    => $id,
            'pivot' => $pivot,
        ];
    }

    /**
     * La base guardada en una fila de renglon, leida DIRECTO de la tabla.
     *
     * Devuelve el valor crudo (string decimal o null). Si hay mas de una fila para el mismo item,
     * falla: los tests que tienen dos filas usan `bases_de()`.
     *
     * @param  string  $tabla        p. ej. 'article_sale'
     * @param  string  $fk_padre     'sale_id' | 'budget_id'
     * @param  int     $padre_id
     * @param  string  $fk_item      'article_id' | 'service_id' | 'combo_id' | 'promocion_vinoteca_id'
     * @param  int     $item_id
     * @return object  La fila entera (price y price_sin_recargos_de_venta).
     */
    protected function fila($tabla, $fk_padre, $padre_id, $fk_item, $item_id)
    {
        $filas = DB::table($tabla)
                    ->where($fk_padre, $padre_id)
                    ->where($fk_item, $item_id)
                    ->get();

        $this->assertCount(1, $filas, 'Se esperaba UNA fila en '.$tabla.' para '.$fk_item.' = '.$item_id.'.');

        return $filas->first();
    }

    /**
     * Afirma precio y base de una fila de renglon.
     *
     * @param  object      $fila
     * @param  float       $price
     * @param  float|null  $base   null = se espera la columna en NULL.
     * @param  string      $que    Para el mensaje.
     * @return void
     */
    protected function assert_precio_y_base($fila, $price, $base, $que)
    {
        $this->assertEqualsWithDelta($price, (float) $fila->price, self::DELTA, $que.': el precio guardado no es el esperado.');

        if (is_null($base)) {
            $this->assertNull(
                $fila->price_sin_recargos_de_venta,
                $que.': la base tiene que quedar en NULL (el precio no tiene el recargo adentro).'
            );
            return;
        }

        $this->assertNotNull(
            $fila->price_sin_recargos_de_venta,
            $que.': la base no se guardo (quedo NULL).'
        );

        $this->assertEqualsWithDelta(
            $base,
            (float) $fila->price_sin_recargos_de_venta,
            0.000001,
            $que.': la base guardada no es la que mando la SPA.'
        );
    }
}
