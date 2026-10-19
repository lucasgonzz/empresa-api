<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\sale\DescuentosEnPreciosEsquemaHelper;
use App\Models\Article;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\Combo;
use App\Models\Discount;
use App\Models\PromocionVinoteca;
use App\Models\Sale;
use App\Models\Service;
use App\Models\Surchage;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Base comun de la suite de "descuentos adentro de los precios" (mision
 * descuentos-aplicar-al-precio-del-articulo, 8/10/2026). Es la hermana de
 * `Tests\Feature\RecargosEnPrecios\RecargosEnPreciosTestCase`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUE PRUEBA ESTA SUITE, EN UNA LINEA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Que la API PERSISTE y PROPAGA `price_sin_descuentos_de_venta` (el precio ORIGINAL de cada
 *  renglon, sin los descuentos ni los recargos de venta) en todos los caminos que escriben o
 *  copian renglones, y que con `aplicar_descuentos_directo_a_items` prendido NINGUNA cuenta del
 *  back (total de la venta, total del presupuesto, neto de la factura de ARCA, puntos, nota de
 *  credito) vuelve a restar el descuento que el precio de cada renglon ya trae adentro. La API no
 *  calcula esos precios: los calcula la SPA y los manda con `price_vender_sin_descuentos`. Por eso
 *  los numeros de los payloads estan escritos A MANO (original 100 con el 10 % de descuento
 *  adentro = 90), no calculados con ningun helper del sistema.
 *
 *  La invariante que se mide: NO NULL = el `price` del renglon tiene el descuento adentro y el
 *  valor es el precio original; NULL = no lo tiene. Toda afirmacion sobre la base se lee DIRECTO de
 *  la tabla del renglon con `DB::table()`, nunca por la relacion de Eloquent: si la relacion
 *  leyera mal, un test que la usara daria verde con la columna vacia.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LA PILA DE CAPAS (para leer los tests con descuento Y recargo a la vez)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *      original ──► [descuentos] ──► [recargos]        100 ──► 90 ──► 99   (10 % y 10 %)
 *
 *      price_sin_descuentos_de_venta = 100   (el original, debajo de las dos capas)
 *      price_sin_recargos_de_venta   =  90   (debajo de la capa de recargos, CON los descuentos)
 *      price                         =  99
 *
 * ⚠️ `SaleController::store()` tiene una guarda anti-duplicados de 5 segundos por comercio, cliente
 * y TOTAL: un test que crea dos ventas les tiene que dar totales distintos.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
abstract class DescuentosEnPreciosTestCase extends EmpresaTestCase
{
    /** Tolerancia de plata. */
    const DELTA = 0.01;

    /** El descuento de venta de toda la suite, en porcentaje. */
    const PORCENTAJE_DESCUENTO = 10;

    /** El recargo de venta de toda la suite, en porcentaje. */
    const PORCENTAJE_RECARGO = 10;

    /** Ids de `budget_statuses`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /** El nombre al que `con_el_flag_escondido()` corre el flag para que la guarda de esquema no lo encuentre. */
    const FLAG_ESCONDIDO_POR_EL_TEST = 'aplicar_descuentos_directo_a_items_escondido_por_el_test';

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
     * Un articulo PROPIO del test (prefijo "zz"), con el precio calculado por el camino real del
     * formulario. Para los tests que miden costo, variante o stock y no pueden depender de lo que
     * tenga el centinela del fixture. La transaccion del test lo revierte.
     *
     * @param  string  $nombre
     * @param  array   $atributos  Columnas a pisar (cost, stock...).
     * @return \App\Models\Article
     */
    protected function articulo_propio($nombre, $atributos = [])
    {
        $user_id = $this->comercio()->id;

        $article = Article::create(array_merge([
            'name'            => 'zz '.$nombre.' '.uniqid(),
            'user_id'         => $user_id,
            'cost'            => 100,
            'percentage_gain' => 50,
        ], $atributos));

        ArticleHelper::setFinalPrice(Article::find($article->id), $user_id);

        return $article->fresh();
    }

    /**
     * Un renglon de VENDER con `varios_precios`, con la forma con la que lo manda la SPA: el padre
     * con el precio de lista del articulo, la cantidad del formulario y `price_vender_personalizado`
     * vacio, mas lo que pida el test; y las filas tal cual las escribe el test, a mano.
     *
     * @param  \App\Models\Article  $articulo
     * @param  array  $filas  Las filas de `varios_precios`.
     * @param  array  $extra  Claves del padre a agregar o pisar.
     * @return array
     */
    protected function renglon_con_varios_precios($articulo, $filas, $extra = [])
    {
        return array_merge([
            'is_article'                 => true,
            'id'                         => $articulo->id,
            'name'                       => $articulo->name,
            'price_vender'               => (float) $articulo->final_price,
            'amount'                     => 1,
            'price_vender_personalizado' => '',
            'varios_precios'             => $filas,
        ], $extra);
    }

    /**
     * Las filas de `article_sale` de un articulo en una venta, leidas DIRECTO de la tabla y
     * ordenadas por precio, de menor a mayor.
     *
     * @param  int  $sale_id
     * @param  int  $article_id
     * @return \Illuminate\Support\Collection
     */
    protected function filas_de_la_venta($sale_id, $article_id)
    {
        return DB::table('article_sale')
                    ->where('sale_id', $sale_id)
                    ->where('article_id', $article_id)
                    ->orderBy('price')
                    ->get();
    }

    /**
     * Un descuento de venta del comercio.
     *
     * @param  float  $porcentaje
     * @return \App\Models\Discount
     */
    protected function descuento($porcentaje = self::PORCENTAJE_DESCUENTO)
    {
        return Discount::create([
            'name'       => 'zz Descuento suite descuentos en precios '.uniqid(),
            'percentage' => $porcentaje,
            'user_id'    => $this->comercio()->id,
        ]);
    }

    /**
     * Un recargo de venta del comercio.
     *
     * @return \App\Models\Surchage
     */
    protected function recargo()
    {
        return Surchage::create([
            'name'       => 'zz Recargo suite descuentos en precios '.uniqid(),
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
            'name'    => 'zz Servicio suite descuentos en precios '.uniqid(),
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
            'num'     => 9802,
            'name'    => 'zz Combo suite descuentos en precios '.uniqid(),
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
            'name'        => 'zz Promo suite descuentos en precios '.uniqid(),
            'stock'       => 100,
            'cost'        => 0,
            'final_price' => $price,
            'user_id'     => $this->comercio()->id,
        ]);
    }

    /**
     * El descuento como lo manda VENDER en `discounts` (venta y presupuesto).
     *
     * @param  \App\Models\Discount  $discount
     * @return array
     */
    protected function descuento_del_payload($discount)
    {
        return [
            'id'         => $discount->id,
            'percentage' => (float) $discount->percentage,
        ];
    }

    /**
     * El recargo como lo manda VENDER en `surchages` (venta y presupuesto).
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
     * Renglon de VENDER.
     *
     * @param  string      $tipo     'article' | 'service' | 'combo' | 'promocion_vinoteca'
     * @param  int         $id
     * @param  float       $price    El `price_vender`: el precio final que se guarda.
     * @param  float|null|false  $base  El `price_vender_sin_descuentos`; `false` = la clave NO viaja
     *                                  (la SPA anterior a esta mision), que no es lo mismo que mandarla
     *                                  en null.
     * @param  float       $amount
     * @param  float|null|false  $base_recargos  El `price_vender_sin_recargos`; `false` = no viaja.
     * @return array
     */
    protected function item_vender($tipo, $id, $price, $base, $amount = 1, $base_recargos = false)
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
            $item['price_vender_sin_descuentos'] = $base;
        }

        if ($base_recargos !== false) {
            $item['price_vender_sin_recargos'] = $base_recargos;
        }

        return $item;
    }

    /**
     * Payload de POST api/sale de mostrador (sin cliente y sin cuenta corriente), sin metodo de
     * pago: un request que no habla del cobro no se rechaza (`PaymentMethodHelper`).
     *
     * @param  array  $items
     * @param  float  $total
     * @param  int    $flag       `aplicar_descuentos_directo_a_items`.
     * @param  array  $discounts  Los descuentos de venta (`descuento_del_payload()`).
     * @param  array  $overrides  Cualquier clave a pisar (por ejemplo `surchages`).
     * @return array
     */
    protected function payload_venta($items, $total, $flag, $discounts, $overrides = [])
    {
        return array_merge([
            'client_id'                          => null,
            'address_id'                         => null,
            'save_current_acount'                => 0,
            'omitir_en_cuenta_corriente'         => 1,
            'to_check'                           => 0,
            'discounts_in_services'              => 1,
            'surchages_in_services'              => 1,
            'aplicar_descuentos_directo_a_items' => $flag,
            'aplicar_recargos_directo_a_items'   => 0,
            'employee_id'                        => null,
            'sub_total'                          => $total,
            'total'                              => $total,
            'terminada'                          => 1,
            'seller_id'                          => null,
            'cantidad_cuotas'                    => null,
            'cuota_descuento'                    => 0,
            'cuota_recargo'                      => 0,
            'caja_id'                            => null,
            'afip_tipo_comprobante_id'           => null,
            'descuento'                          => null,
            'moneda_id'                          => 1,
            'discount_stock'                     => 0,
            'discounts'                          => $discounts,
            'surchages'                          => [],
            'items'                              => $items,
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
     * @param  array  $discounts
     * @param  array  $overrides
     * @return array
     */
    protected function payload_actualizar($sale, $items, $total, $flag, $discounts, $overrides = [])
    {
        return array_merge([
            'client_id'                          => $sale->client_id,
            'save_current_acount'                => $sale->save_current_acount,
            'omitir_en_cuenta_corriente'         => $sale->omitir_en_cuenta_corriente,
            'to_check'                           => 0,
            'checked'                            => 0,
            'confirmed'                          => 0,
            'discounts_in_services'              => 1,
            'surchages_in_services'              => 1,
            'aplicar_descuentos_directo_a_items' => $flag,
            'aplicar_recargos_directo_a_items'   => 0,
            'discount_stock'                     => 0,
            'sub_total'                          => $total,
            'total'                              => $total,
            'moneda_id'                          => 1,
            'items'                              => $items,
            'discounts'                          => $discounts,
            'surchages'                          => [],
            'returned_items'                     => [],
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
     * @param  int    $flag       `aplicar_descuentos_directo_a_items`.
     * @param  array  $discounts
     * @param  array  $renglones  ['articles' => [...], 'services' => [...], ...]
     * @param  array  $overrides
     * @return array
     */
    protected function payload_presupuesto($total, $flag, $discounts, $renglones, $overrides = [])
    {
        return array_merge([
            'client_id'                          => $this->cliente_cc()->id,
            'start_at'                           => null,
            'finish_at'                          => null,
            'observations'                       => null,
            'price_type_id'                      => null,
            'sale_status_id'                     => null,
            'discount_stock'                     => 0,
            'iva_aplicado'                       => 1,
            'total'                              => $total,
            'budget_status_id'                   => self::ESTADO_SIN_CONFIRMAR,
            'address_id'                         => null,
            'surchages_in_services'              => 1,
            'discounts_in_services'              => 1,
            'aplicar_descuentos_directo_a_items' => $flag,
            'aplicar_recargos_directo_a_items'   => 0,
            'moneda_id'                          => 1,
            'valor_dolar'                        => null,
            'discounts'                          => $discounts,
            'surchages'                          => [],
            'articles'                           => isset($renglones['articles']) ? $renglones['articles'] : [],
            'services'                           => isset($renglones['services']) ? $renglones['services'] : [],
            'promocion_vinotecas'                => isset($renglones['promocion_vinotecas']) ? $renglones['promocion_vinotecas'] : [],
            'combos'                             => isset($renglones['combos']) ? $renglones['combos'] : [],
        ], $overrides);
    }

    /**
     * Renglon de articulo de presupuesto. `$en_pivot` = como lo manda VENDER al ACTUALIZAR un
     * renglon que ya estaba cargado (y el form generico); si no, plano, como en el alta.
     * `$base` en `false` = la clave no viaja.
     *
     * @param  int    $article_id
     * @param  float  $price
     * @param  float|null|false  $base           `price_vender_sin_descuentos`.
     * @param  bool   $en_pivot
     * @param  float  $amount
     * @param  float|null|false  $base_recargos  `price_vender_sin_recargos`.
     * @return array
     */
    protected function renglon_articulo_presupuesto($article_id, $price, $base, $en_pivot, $amount = 1, $base_recargos = false)
    {
        $datos = [
            'amount'   => $amount,
            'bonus'    => null,
            'location' => null,
            'price'    => $price,
        ];

        if ($base !== false) {
            $datos['price_vender_sin_descuentos'] = $base;
        }

        if ($base_recargos !== false) {
            $datos['price_vender_sin_recargos'] = $base_recargos;
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
     * @param  float|null|false  $base           `price_vender_sin_descuentos`.
     * @param  float  $amount
     * @param  float|null|false  $base_recargos  `price_vender_sin_recargos`.
     * @return array
     */
    protected function renglon_pivot_presupuesto($id, $price, $base, $amount = 1, $base_recargos = false)
    {
        $pivot = [
            'amount' => $amount,
            'price'  => $price,
        ];

        if ($base !== false) {
            $pivot['price_vender_sin_descuentos'] = $base;
        }

        if ($base_recargos !== false) {
            $pivot['price_vender_sin_recargos'] = $base_recargos;
        }

        return [
            'id'    => $id,
            'pivot' => $pivot,
        ];
    }

    /**
     * La fila de un renglon, leida DIRECTO de la tabla.
     *
     * Si hay mas de una fila para el mismo item, falla: los tests que tienen dos filas usan
     * `filas_de_la_venta()` o su propia consulta.
     *
     * @param  string  $tabla        p. ej. 'article_sale'
     * @param  string  $fk_padre     'sale_id' | 'budget_id'
     * @param  int     $padre_id
     * @param  string  $fk_item      'article_id' | 'service_id' | 'combo_id' | 'promocion_vinoteca_id'
     * @param  int     $item_id
     * @return object  La fila entera (price y las dos bases).
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
     * Afirma precio y bases de una fila de renglon.
     *
     * @param  object      $fila
     * @param  float       $price
     * @param  float|null  $base           `price_sin_descuentos_de_venta`; null = se espera NULL.
     * @param  string      $que            Para el mensaje.
     * @param  float|null|false  $base_recargos  `price_sin_recargos_de_venta`; `false` = no se mira,
     *                                           null = se espera NULL.
     * @return void
     */
    protected function assert_precio_y_base($fila, $price, $base, $que, $base_recargos = false)
    {
        $this->assertEqualsWithDelta($price, (float) $fila->price, self::DELTA, $que.': el precio guardado no es el esperado.');

        $this->assert_columna($fila->price_sin_descuentos_de_venta, $base, $que.' / base de descuentos');

        if ($base_recargos !== false) {
            $this->assert_columna($fila->price_sin_recargos_de_venta, $base_recargos, $que.' / base de recargos');
        }
    }

    /**
     * Corre el cuerpo con `aplicar_descuentos_directo_a_items` ESCONDIDO en `sales` y `budgets`: la
     * base de un cliente que recibio el codigo y todavia no corrio la migracion (la ventana del
     * deploy). Misma tecnica que `tests/Feature/DescuentosEnPrecios/7`:
     *
     *   1. el DDL de MySQL hace COMMIT IMPLICITO de la sesion que lo corre: el RENAME va por una
     *      conexion PDO aparte, para no cerrar la transaccion del test;
     *   2. en MySQL 8 `information_schema` responde con el snapshot de la transaccion REPEATABLE READ
     *      abierta: se cierra la transaccion del trait ANTES del rename y se abre otra despues. Sin
     *      eso la guarda seguiria viendo la columna y el test daria VERDE EN FALSO.
     *
     * Se RENOMBRA y no se borra: si algo se corta a la mitad, la columna sigue entera bajo el otro
     * nombre y `restaurar_flag_si_quedo_escondido()` (que cada clase que use esto llama en su
     * `setUp()`) la devuelve sola en la corrida siguiente.
     *
     * ⚠️ Todo lo que el cuerpo necesite crear (descuentos, servicios...) se crea ADENTRO del
     * cuerpo: lo creado antes del rollback se perderia con el.
     *
     * @param  \Closure  $cuerpo
     * @return void
     */
    protected function con_el_flag_escondido($cuerpo)
    {
        DB::rollBack();

        $this->renombrar_el_flag(DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG, self::FLAG_ESCONDIDO_POR_EL_TEST);

        DB::beginTransaction();

        try {

            /* Control del escenario: sin esta asercion, un test que no lograra esconder la columna pasaria igual. */
            foreach (DescuentosEnPreciosEsquemaHelper::TABLAS_FLAG as $tabla) {
                $this->assertFalse(
                    DescuentosEnPreciosEsquemaHelper::hay_columna_flag($tabla),
                    'El escenario no se armo: la guarda sigue viendo el flag en '.$tabla.'.'
                );
            }

            $cuerpo();

        } finally {

            DB::rollBack();

            $this->renombrar_el_flag(self::FLAG_ESCONDIDO_POR_EL_TEST, DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG);

            /* Para que el rollback del tearDown del trait tenga una transaccion que cerrar. */
            DB::beginTransaction();
        }
    }

    /**
     * Renombra el flag en `sales` y `budgets` por una conexion PDO aparte contra la MISMA base.
     * Donde la columna no esta con el nombre `$de`, no hace nada.
     *
     * @param  string  $de
     * @param  string  $a
     * @return void
     */
    protected function renombrar_el_flag($de, $a)
    {
        $config = config('database.connections.'.config('database.default'));

        $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];

        $pdo = new \PDO($dsn, $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        /* Sin esto, un metadata lock cuelga la suite: el default de MySQL para MDL es 1 año. */
        $pdo->exec('SET SESSION lock_wait_timeout = 10');

        foreach (DescuentosEnPreciosEsquemaHelper::TABLAS_FLAG as $tabla) {

            $existe = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ".
                "AND TABLE_NAME = '".$tabla."' AND COLUMN_NAME = '".$de."'"
            )->fetchColumn();

            if ((int) $existe === 0) {
                continue;
            }

            $pdo->exec('ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.$de.'` TO `'.$a.'`');
        }

        DescuentosEnPreciosEsquemaHelper::olvidar();
    }

    /**
     * Red de seguridad: si una corrida anterior se corto con el flag escondido, se devuelve.
     *
     * @return void
     */
    protected function restaurar_flag_si_quedo_escondido()
    {
        $this->renombrar_el_flag(self::FLAG_ESCONDIDO_POR_EL_TEST, DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG);
    }

    /**
     * Afirma el valor de una columna de base: NULL o un numero con seis decimales de tolerancia.
     *
     * @param  mixed       $guardado
     * @param  float|null  $esperado
     * @param  string      $que
     * @return void
     */
    protected function assert_columna($guardado, $esperado, $que)
    {
        if (is_null($esperado)) {
            $this->assertNull($guardado, $que.': tiene que quedar en NULL (el precio no tiene ese ajuste adentro).');
            return;
        }

        $this->assertNotNull($guardado, $que.': no se guardo (quedo NULL).');

        $this->assertEqualsWithDelta($esperado, (float) $guardado, 0.000001, $que.': no es la que mando la SPA.');
    }
}
