<?php

namespace Tests\Feature\Vender;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\puntos\PuntosConfigHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\Discount;
use App\Models\ExtencionEmpresa;
use App\Models\MovimientoPunto;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\SaleStatus;
use App\Models\SistemaDePuntos;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión vender-lista-obligatoria, tanda 2 (18/9/2026): el contrato del payload OFFLINE de
 * Vender contra `POST api/sale`.
 *
 * EL CASO REAL. Una venta hecha sin conexión se guarda en IndexedDB con el objeto `sale_data` de
 * `mixins/vender/guardar_venta/index.js::guardar_venta_offline()` y, cuando vuelve la red,
 * `offline/sync_sales.js` lo postea a `/api/sale` tal cual (sacándole `id` y `created_at`
 * locales). `SaleController::store()` lo lee igual que al POST online
 * (`store/vender/vender.js`, acción `vender`), así que TODO lo que no viaje en ese objeto lo
 * defaultea el back. Hasta la tanda 1 faltaban nueve claves: `discount_stock` e `iva_aplicado`
 * volvían a 1 aunque el vendedor los hubiera apagado, `aplicar_recargos_directo_a_items` quedaba
 * null (y `getTotalSale()` volvía a sumar los recargos), `puntos_canjeados` no viajaba (el cliente
 * cobraba el descuento y conservaba los puntos), y `sale_status_id`, `price_description`,
 * `send_mail` y el `log` se perdían. La tanda 1 las espejó en la SPA; este archivo fija el
 * contrato del lado del back para que no se vuelva a abrir.
 *
 * QUÉ FIJA. Los dos payloads se arman acá con las claves EXACTAS y en el mismo orden que los dos
 * archivos de la SPA (extraídas con `grep` de `vender.js:1168-1250` y de
 * `guardar_venta/index.js:271-372`; el offline es un superconjunto: suma `cuotas`,
 * `forma_de_pago` y `permiso_existente`, que el back no persiste en `sales`). Con el MISMO estado
 * de Vender, el POST offline tiene que dejar en la base la MISMA venta que el online: todas las
 * columnas de `sales` salvo las de identidad y tiempo, los pivotes de artículos, descuentos y
 * métodos de pago. Y por separado, las tres cosas que el plan nombra: el canje de puntos viaja y
 * se aplica igual que online, y sin lista en una cuenta con listas el payload offline recibe el
 * mismo 422 (lo que `sync_sales.js` ahora muestra sin descartar la venta).
 *
 * Los valores del estado son NO-default a propósito (`discount_stock` 0, `iva_aplicado` 0,
 * `aplicar_recargos_directo_a_items` 1, `surchages_in_services` 0, un estado de venta, un
 * `log`, días de alerta personalizados): si el back defaulteara una clave que falta, la
 * comparación se pone roja; con los defaults, pasaría igual sin medir nada.
 *
 * DatabaseTransactions (no RefreshDatabase): la base del slot está sembrada de antes. Todo el
 * fixture se crea adentro de la transacción con prefijo `zz` y `users.listas_de_precio` se
 * restaura en tearDown. `PuntosConfigHelper` memoiza extensión y programa en estáticas que
 * sobreviven al rollback, así que se limpian en setUp y tearDown (como tests/Feature/Puntos).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Payload_offline_con_lista_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing (TestingFerreteriaSeeder). */
    const USER_ID = 500;

    /** @var int Efectivo, del catálogo global. */
    const METODO_EFECTIVO = 3;

    /** @var float Tolerancia de un centavo. */
    const DELTA = 0.01;

    /** @var int `articles.final_price`: el precio base. */
    const PRECIO_BASE = 100;

    /** @var int Precio del artículo en la lista: el que se cobra. */
    const PRECIO_LISTA = 150;

    /** @var int Cantidad del renglón. */
    const CANTIDAD = 2;

    /** @var int Descuento de venta, en porcentaje. 300 − 10 % = 270. */
    const PORCENTAJE_DESCUENTO = 10;

    /** @var int Días de alerta personalizados, un valor que no es el default (null). */
    const DIAS_ALERTA = 7;

    /** @var int El punto vale $100 en el test del canje, para que los números sean legibles. */
    const VALOR_PUNTO = 100;

    /** @var int Puntos que se canjean. 15 × 100 = $1.500, dentro del tope del 20 % de $10.000. */
    const PUNTOS_CANJEADOS = 15;

    /** @var int Precio del renglón en el test del canje: alto para que el canje entre en el tope. */
    const PRECIO_PARA_CANJE = 10000;

    /**
     * Columnas de `sales` que NO se comparan entre la venta online y la offline: identidad y
     * tiempo. Todo lo demás tiene que ser idéntico.
     *
     * @var array
     */
    const COLUMNAS_PROPIAS = ['id', 'num', 'created_at', 'updated_at', 'terminada_at'];

    /** @var \App\Models\User */
    protected $user;

    /** @var int|null Valor original de users.listas_de_precio, para dejarlo como estaba. */
    protected $listas_original = null;

    /** @var \App\Models\PriceType */
    protected $lista;

    /** @var \App\Models\Article */
    protected $article;

    /** @var \App\Models\Discount */
    protected $discount;

    /** @var \App\Models\SaleStatus Un estado de venta propio, para que `sale_status_id` no sea null. */
    protected $estado_de_venta;

    protected function setUp(): void
    {
        parent::setUp();

        PuntosConfigHelper::limpiar_memo();

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->listas_original = $this->user->listas_de_precio;

        $this->actingAs($this->user, 'web');

        $this->lista = PriceType::create([
            'name'     => 'zz General (payload offline)',
            'user_id'  => self::USER_ID,
            'position' => 9,
        ]);

        /* Sin stock a propósito: acá se compara lo persistido, no el stock. */
        $this->article = Article::create([
            'name'        => 'zz Articulo payload offline',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO_BASE,
            'status'      => 'active',
        ]);

        $this->article->price_types()->attach($this->lista->id, ['final_price' => self::PRECIO_LISTA]);

        $this->discount = Discount::create([
            'name'       => 'zz Descuento payload offline',
            'percentage' => self::PORCENTAJE_DESCUENTO,
            'user_id'    => self::USER_ID,
        ]);

        $this->estado_de_venta = SaleStatus::create([
            'name'     => 'zz Estado payload offline',
            'position' => 1,
            'user_id'  => self::USER_ID,
        ]);
    }

    protected function tearDown(): void
    {
        if (!is_null($this->user) && !is_null($this->listas_original)) {
            User::where('id', self::USER_ID)->update(['listas_de_precio' => $this->listas_original]);
        }

        PuntosConfigHelper::limpiar_memo();

        parent::tearDown();
    }

    /**
     * Prende o apaga las listas de precio de la cuenta (por query: el controlador lee al dueño
     * fresco en cada request).
     *
     * @param  int  $usa  1 o 0
     * @return void
     */
    protected function cuenta_con_listas($usa)
    {
        User::where('id', self::USER_ID)->update(['listas_de_precio' => $usa]);
    }

    /**
     * El renglón como vive en `state.vender.items` (con las claves con las que nace el ítem en
     * Vender, además del precio resuelto por la lista).
     *
     * @param  float  $price_vender
     * @return array
     */
    protected function renglon($price_vender)
    {
        return [
            'is_article'                  => true,
            'id'                          => $this->article->id,
            'name'                        => $this->article->name,
            'final_price'                 => self::PRECIO_BASE,
            'price_vender'                => $price_vender,
            'amount'                      => self::CANTIDAD,
            'article_variant_id'          => 0,
            'price_type_personalizado_id' => 0,
        ];
    }

    /**
     * El ESTADO de Vender con el que se arman los dos payloads: un solo lugar, para que el online
     * y el offline salgan del mismo origen, igual que en la SPA (los dos leen `state.vender`).
     * Valores no-default a propósito (ver el docblock de la clase).
     *
     * @param  array  $overrides
     * @return array
     */
    protected function estado_de_vender($overrides = [])
    {
        $sub_total = self::PRECIO_LISTA * self::CANTIDAD;
        $total = $sub_total - $sub_total * self::PORCENTAJE_DESCUENTO / 100;

        return array_merge([
            'save_afip_ticket'                 => false,
            'items'                            => [$this->renglon(self::PRECIO_LISTA)],
            'client_id'                        => null,
            'discounts'                        => [[
                'id'         => $this->discount->id,
                'name'       => $this->discount->name,
                'percentage' => self::PORCENTAJE_DESCUENTO,
                'user_id'    => self::USER_ID,
            ]],
            'surchages'                        => [],
            'save_current_acount'              => 1,
            'make_current_acount_pago'         => false,
            'sale_type_id'                     => null,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 0,
            'current_acount_payment_method_id' => self::METODO_EFECTIVO,
            'afip_information_id'              => null,
            'employee_id'                      => null,
            'address_id'                       => null,
            'to_check'                         => 0,
            'checked'                          => 0,
            'confirmed'                        => 0,
            'observations'                     => 'zz observaciones del payload offline',
            'omitir_en_cuenta_corriente'       => 0,
            'numero_orden_de_compra'           => 'OC-zz-offline-10',
            'selected_payment_methods'         => [],
            'discount_percentage'              => null,
            'discount_amount'                  => null,
            'sub_total'                        => $sub_total,
            'price_type_id'                    => $this->lista->id,
            'total'                            => $total,
            'seller_id'                        => null,
            'cuota_id'                         => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'monto_credito_real'               => null,
            'caja_id'                          => null,
            'moneda_id'                        => 1,
            'valor_dolar'                      => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'forzar_total_monto'               => null,
            'puntos_canjeados'                 => null,
            'descuento_puntos'                 => null,
            'fecha_entrega'                    => null,
            'incoterms'                        => null,
            'forma_de_pago'                    => null,
            'permiso_existente'                => null,
            'observations_ocultas'             => 'zz ocultas del payload offline',
            'aplicar_recargos_directo_a_items' => 1,
            'sale_status_id'                   => $this->estado_de_venta->id,
            'discount_stock'                   => 0,
            'iva_aplicado'                     => 0,
            'price_description'                => json_encode(['SubTotal: $300', 'APLICANDO DESCUENTOS DE VENTA', 'Total venta: $270']),
            'send_mail'                        => false,
            'dias_alerta_venta_no_cobrada_personalizado' => self::DIAS_ALERTA,
            'log'                              => [
                [
                    'event_key'        => 'sale_submit_attempt',
                    'source_component' => 'vender/action_vender',
                    'before'           => null,
                    'after'            => ['items_count' => 1, 'client_id' => null, 'total' => $total],
                    'diff'             => null,
                ],
            ],
        ], $overrides);
    }

    /**
     * Las claves del POST online, en el orden exacto de `store/vender/vender.js` (acción
     * `vender`). `sale_type_id` aparece dos veces en el objeto de la SPA; acá una, que es lo que
     * el JSON termina llevando.
     *
     * @return array
     */
    protected function claves_online()
    {
        return [
            'save_afip_ticket', 'items', 'client_id', 'discounts', 'surchages', 'save_current_acount',
            'make_current_acount_pago', 'sale_type_id', 'discounts_in_services', 'surchages_in_services',
            'current_acount_payment_method_id', 'afip_information_id', 'employee_id', 'address_id',
            'to_check', 'checked', 'confirmed', 'observations', 'omitir_en_cuenta_corriente',
            'numero_orden_de_compra', 'selected_payment_methods', 'discount_percentage', 'discount_amount',
            'sub_total', 'price_type_id', 'total', 'seller_id', 'cuota_id', 'cantidad_cuotas',
            'cuota_descuento', 'cuota_recargo', 'monto_credito_real', 'caja_id', 'moneda_id', 'valor_dolar',
            'afip_tipo_comprobante_id', 'descuento', 'forzar_total_monto', 'puntos_canjeados',
            'descuento_puntos', 'fecha_entrega', 'incoterms', 'observations_ocultas',
            'aplicar_recargos_directo_a_items', 'sale_status_id', 'discount_stock', 'iva_aplicado',
            'price_description', 'send_mail', 'dias_alerta_venta_no_cobrada_personalizado', 'log',
        ];
    }

    /**
     * Las claves del objeto `sale_data` de `guardar_venta_offline()`, en su orden exacto, menos
     * `id` y `created_at` que `sync_sales.js` le saca antes de postear.
     *
     * @return array
     */
    protected function claves_offline()
    {
        return [
            'save_afip_ticket', 'items', 'client_id', 'discounts', 'surchages', 'save_current_acount',
            'make_current_acount_pago', 'sale_type_id', 'discounts_in_services', 'surchages_in_services',
            'current_acount_payment_method_id', 'afip_information_id', 'employee_id', 'address_id',
            'to_check', 'checked', 'confirmed', 'observations', 'omitir_en_cuenta_corriente',
            'numero_orden_de_compra', 'selected_payment_methods', 'discount_percentage', 'discount_amount',
            'sub_total', 'price_type_id', 'total', 'seller_id', 'cuota_id', 'cantidad_cuotas', 'cuotas',
            'cuota_descuento', 'cuota_recargo', 'monto_credito_real', 'caja_id', 'afip_tipo_comprobante_id',
            'incoterms', 'forma_de_pago', 'permiso_existente', 'moneda_id', 'valor_dolar', 'descuento',
            'forzar_total_monto', 'fecha_entrega', 'observations_ocultas',
            'dias_alerta_venta_no_cobrada_personalizado', 'aplicar_recargos_directo_a_items',
            'puntos_canjeados', 'descuento_puntos', 'sale_status_id', 'discount_stock', 'iva_aplicado',
            'price_description', 'send_mail', 'log',
        ];
    }

    /**
     * Arma un payload tomando del estado las claves pedidas, en ese orden. `cuotas` es la única
     * que no es una clave del estado con el mismo nombre: la SPA la manda con el valor de
     * `cantidad_cuotas` (prompt 266).
     *
     * @param  array  $estado
     * @param  array  $claves
     * @return array
     */
    protected function armar_payload($estado, $claves)
    {
        $payload = [];

        foreach ($claves as $clave) {

            if ($clave === 'cuotas') {
                $payload['cuotas'] = $estado['cantidad_cuotas'];
                continue;
            }

            $payload[$clave] = array_key_exists($clave, $estado) ? $estado[$clave] : null;
        }

        return $payload;
    }

    /**
     * @param  array  $estado
     * @return array
     */
    protected function payload_online($estado)
    {
        return $this->armar_payload($estado, $this->claves_online());
    }

    /**
     * @param  array  $estado
     * @return array
     */
    protected function payload_offline($estado)
    {
        return $this->armar_payload($estado, $this->claves_offline());
    }

    /**
     * Corre `created_at` de una venta 10 segundos para atrás: `SaleController::venta_ya_cread()`
     * debounea (200 sin cuerpo) una venta con el mismo user/client/employee/total de los últimos
     * 5 segundos, y acá se postean dos iguales seguidas a propósito.
     *
     * @param  int  $sale_id
     * @return void
     */
    protected function envejecer_venta($sale_id)
    {
        Sale::where('id', $sale_id)->update(['created_at' => Carbon::now()->subSeconds(10)]);
    }

    /**
     * La fila cruda de `sales`, sin las columnas propias de cada venta.
     *
     * @param  int  $sale_id
     * @return array
     */
    protected function fila_de_sales($sale_id)
    {
        $fila = (array) DB::table('sales')->where('id', $sale_id)->first();

        foreach (self::COLUMNAS_PROPIAS as $columna) {
            unset($fila[$columna]);
        }

        return $fila;
    }

    /**
     * Los pivotes de una venta que la comparación mira, sin ids ni timestamps.
     *
     * @param  int  $sale_id
     * @return array
     */
    protected function pivotes_de($sale_id)
    {
        $articulos = DB::table('article_sale')
                        ->where('sale_id', $sale_id)
                        ->orderBy('article_id')
                        ->get(['article_id', 'amount', 'price', 'cost', 'price_sin_iva', 'iva_percentage', 'discount', 'price_type_personalizado_id', 'ganancia'])
                        ->map(function ($fila) { return (array) $fila; })
                        ->all();

        $descuentos = DB::table('discount_sale')
                        ->where('sale_id', $sale_id)
                        ->orderBy('discount_id')
                        ->get(['discount_id', 'percentage'])
                        ->map(function ($fila) { return (array) $fila; })
                        ->all();

        $metodos = DB::table('current_acount_payment_method_sale')
                        ->where('sale_id', $sale_id)
                        ->orderBy('current_acount_payment_method_id')
                        ->get(['current_acount_payment_method_id', 'amount', 'discount_percentage', 'discount_amount', 'caja_id'])
                        ->map(function ($fila) { return (array) $fila; })
                        ->all();

        return [
            'article_sale'                     => $articulos,
            'discount_sale'                    => $descuentos,
            'current_acount_payment_method_sale' => $metodos,
        ];
    }

    /**
     * Le da a la cuenta la extensión de puntos (creando la fila del catálogo si falta) y un
     * programa activo, con el memo del helper limpio. Molde: tests/Feature/Puntos/PuntosTestCase.
     *
     * @return \App\Models\SistemaDePuntos
     */
    protected function programa_de_puntos()
    {
        $extencion = ExtencionEmpresa::where('slug', PuntosConfigHelper::SLUG_EXTENCION)->first();

        if (is_null($extencion)) {

            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => PuntosConfigHelper::SLUG_EXTENCION,
                'name' => 'Sistema de puntos para clientes',
            ]);
        }

        if (!$this->user->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $this->user->extencions()->attach($extencion->id);
        }

        $sistema = SistemaDePuntos::create([
            'user_id'           => self::USER_ID,
            'nombre'            => 'zz Programa payload offline',
            'activo'            => 1,
            'puntos_cada'       => 1000,
            'puntos_por_tramo'  => 1,
            'valor_punto'       => self::VALOR_PUNTO,
            'vencimiento_meses' => 12,
            'minimo_canje'      => 10,
            'tope_porcentaje'   => 20,
        ]);

        PuntosConfigHelper::limpiar_memo();

        return $sistema;
    }

    /**
     * Cliente propio con su cuenta en pesos y un lote de puntos disponibles.
     *
     * @param  \App\Models\SistemaDePuntos  $sistema
     * @param  float                        $puntos
     * @return \App\Models\Client
     */
    protected function cliente_con_puntos($sistema, $puntos)
    {
        $client = Client::create([
            'name'    => 'zz Cliente payload offline '.uniqid(),
            'user_id' => self::USER_ID,
        ]);

        CreditAccount::firstOrCreate(
            ['model_name' => 'client', 'model_id' => $client->id, 'moneda_id' => 1],
            ['saldo' => 0, 'user_id' => self::USER_ID]
        );

        MovimientoPunto::create([
            'user_id'              => self::USER_ID,
            'client_id'            => $client->id,
            'sistema_de_puntos_id' => $sistema->id,
            'tipo'                 => 'ganados',
            'puntos'               => $puntos,
            'sale_id'              => null,
            'price_type_id'        => 0,
            'monto_base'           => null,
            'detalle'              => 'Lote sembrado por el test',
            'vence_at'             => Carbon::now()->addMonths(12),
            'consumido'            => 0,
            'employee_id'          => null,
        ]);

        return $client;
    }

    /**
     * 🔴 EL CONTRATO: el mismo estado de Vender, posteado con la forma online y con la forma
     * offline, deja dos ventas IDÉNTICAS en la base (todas las columnas de `sales` menos
     * identidad y tiempo, y los tres pivotes). Y las nueve claves que la tanda 1 espejó quedan
     * en la venta offline con el valor que el vendedor eligió, no con el default del back.
     *
     * @group vender
     * @test
     */
    public function el_payload_offline_deja_la_misma_venta_que_el_online()
    {
        $this->cuenta_con_listas(1);

        /* Guarda del contrato entre los dos moldes de este archivo: si alguien suma una clave al online y no al offline, se ve acá. */
        $this->assertEquals(
            [],
            array_values(array_diff($this->claves_online(), $this->claves_offline())),
            'Toda clave del POST online tiene que estar también en el payload offline (guardar_venta_offline).'
        );

        $estado = $this->estado_de_vender();

        $online = $this->postJson('api/sale', $this->payload_online($estado));

        $online->assertStatus(201);

        $venta_online = Sale::find($online->json('model.id'));

        $this->assertNotNull($venta_online);

        $this->envejecer_venta($venta_online->id);

        $offline = $this->postJson('api/sale', $this->payload_offline($estado));

        $offline->assertStatus(201);

        $venta_offline = Sale::find($offline->json('model.id'));

        $this->assertNotNull($venta_offline);
        $this->assertNotEquals($venta_online->id, $venta_offline->id, 'Son dos ventas distintas (el debounce no se comió la segunda).');

        /* 1. Todas las columnas de sales, una por una, para que el diff diga cuál difiere. */
        $fila_online  = $this->fila_de_sales($venta_online->id);
        $fila_offline = $this->fila_de_sales($venta_offline->id);

        $this->assertEquals(array_keys($fila_online), array_keys($fila_offline));

        foreach ($fila_online as $columna => $valor) {
            $this->assertEquals(
                $valor,
                $fila_offline[$columna],
                'La columna sales.'.$columna.' difiere entre la venta online y la offline.'
            );
        }

        /* 2. Los pivotes. */
        $this->assertEquals($this->pivotes_de($venta_online->id), $this->pivotes_de($venta_offline->id), 'Los pivotes de la venta offline tienen que ser los mismos que los de la online.');

        /* 3. Las nueve claves de la tanda 1, con el valor elegido y no el default. */
        $this->assertEquals($this->lista->id, (int) $venta_offline->price_type_id, 'La lista viaja y se persiste.');
        $this->assertEqualsWithDelta($estado['total'], (float) $venta_offline->total, self::DELTA);
        $this->assertEquals(0, (int) $venta_offline->discount_stock, 'discount_stock apagado por el vendedor: no vuelve a 1.');
        $this->assertEquals(0, (int) $venta_offline->iva_aplicado, 'iva_aplicado apagado por el vendedor: no vuelve a 1.');
        $this->assertEquals(1, (int) $venta_offline->aplicar_recargos_directo_a_items, 'aplicar_recargos_directo_a_items no queda null.');
        $this->assertEquals($this->estado_de_venta->id, (int) $venta_offline->sale_status_id, 'El estado de venta no se pierde.');
        $this->assertEquals($estado['price_description'], $venta_offline->price_description, 'La descripción del cálculo del precio no se pierde.');
        $this->assertEquals(0, (int) $venta_offline->send_mail);
        $this->assertEquals($estado['log'], $venta_offline->log, 'El log de auditoría de Vender no se pierde.');
        $this->assertEquals(self::DIAS_ALERTA, (int) $venta_offline->dias_alerta_venta_no_cobrada_personalizado);
        $this->assertEquals(0, (int) $venta_offline->surchages_in_services);
        $this->assertEquals('zz observaciones del payload offline', $venta_offline->observations);
        $this->assertEquals('zz ocultas del payload offline', $venta_offline->observations_ocultas);
        $this->assertEquals('OC-zz-offline-10', $venta_offline->numero_orden_de_compra);

        $renglon = DB::table('article_sale')->where('sale_id', $venta_offline->id)->first();

        $this->assertEqualsWithDelta(self::PRECIO_LISTA, (float) $renglon->price, self::DELTA, 'El renglón se cobró a precio de lista.');
    }

    /**
     * 🔴 EL CANJE DE PUNTOS por el camino offline: hasta la tanda 1 `puntos_canjeados` no viajaba
     * en `sale_data`, `PuntosCanjeHelper::aplicar()` salía sin descontar nada y el cliente
     * cobraba el descuento (el `total` ya viene neteado) conservando los puntos. Con el mismo
     * estado, el POST offline deja las mismas dos columnas y el mismo movimiento negativo que el
     * online.
     *
     * @group vender
     * @test
     */
    public function el_canje_de_puntos_viaja_en_el_payload_offline_y_se_aplica_igual_que_online()
    {
        $this->cuenta_con_listas(1);

        $sistema = $this->programa_de_puntos();

        /* Saldo para DOS canjes de 15: el online y el offline salen del mismo cliente. */
        $cliente = $this->cliente_con_puntos($sistema, self::PUNTOS_CANJEADOS * 2 + 10);

        $descuento = self::PUNTOS_CANJEADOS * self::VALOR_PUNTO;
        $bruto = self::PRECIO_PARA_CANJE * self::CANTIDAD;

        $estado = $this->estado_de_vender([
            'items'                      => [$this->renglon(self::PRECIO_PARA_CANJE)],
            'discounts'                  => [],
            'client_id'                  => $cliente->id,
            'omitir_en_cuenta_corriente' => 1,
            'sub_total'                  => $bruto,
            'total'                      => $bruto - $descuento,
            'puntos_canjeados'           => self::PUNTOS_CANJEADOS,
            'descuento_puntos'           => $descuento,
        ]);

        $online = $this->postJson('api/sale', $this->payload_online($estado));

        $online->assertStatus(201);

        $venta_online = Sale::find($online->json('model.id'));

        $this->envejecer_venta($venta_online->id);

        $offline = $this->postJson('api/sale', $this->payload_offline($estado));

        $offline->assertStatus(201);

        $venta_offline = Sale::find($offline->json('model.id'));

        $this->assertNotEquals($venta_online->id, $venta_offline->id);

        foreach ([$venta_online, $venta_offline] as $venta) {

            $etiqueta = ($venta->id === $venta_offline->id) ? 'offline' : 'online';

            $this->assertEqualsWithDelta(self::PUNTOS_CANJEADOS, (float) $venta->puntos_canjeados, self::DELTA, 'puntos_canjeados en la venta '.$etiqueta);
            $this->assertEqualsWithDelta($descuento, (float) $venta->descuento_puntos, self::DELTA, 'descuento_puntos en la venta '.$etiqueta);
            $this->assertEqualsWithDelta($bruto - $descuento, (float) $venta->total, self::DELTA, 'El total neteado en la venta '.$etiqueta);

            $canjes = MovimientoPunto::where('sale_id', $venta->id)->where('tipo', 'canjeados')->get();

            $this->assertCount(1, $canjes, 'Un movimiento canjeados para la venta '.$etiqueta);
            $this->assertEqualsWithDelta(-self::PUNTOS_CANJEADOS, (float) $canjes->first()->puntos, self::DELTA, 'El canje se guarda en negativo en la venta '.$etiqueta);
        }

        $this->assertEquals($this->fila_de_sales($venta_online->id), $this->fila_de_sales($venta_offline->id), 'Con canje, la venta offline sigue siendo idéntica a la online.');
    }

    /**
     * Sin lista en una cuenta con listas, el payload offline recibe el mismo 422 que el online y
     * no crea nada. Es lo que `sync_sales.js` muestra ahora con la venta identificada, sin
     * borrarla de IndexedDB: el back no puede completar la lista porque los renglones ya vienen
     * preciados sin ella.
     *
     * @group vender
     * @test
     */
    public function sin_lista_en_cuenta_con_listas_el_payload_offline_responde_422()
    {
        $this->cuenta_con_listas(1);

        $ventas_antes = Sale::where('user_id', self::USER_ID)->count();

        $response = $this->postJson('api/sale', $this->payload_offline($this->estado_de_vender([
            'price_type_id' => null,
        ])));

        $response->assertStatus(422);
        $this->assertTrue((bool) $response->json('sin_lista_de_precios'), 'La bandera que sync_sales.js muestra con la venta identificada.');
        $this->assertEquals(PriceTypeHelper::mensaje_sin_lista(), $response->json('message'));

        $this->assertEquals($ventas_antes, Sale::where('user_id', self::USER_ID)->count(), 'Un 422 no puede haber creado la venta.');
    }
}
