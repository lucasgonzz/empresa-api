<?php

namespace Tests\Feature\Vender;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión vender-lista-obligatoria (17/9/2026): una cuenta que trabaja con listas de precios no
 * puede guardar una venta sin lista, ni en el alta ni en la edición.
 *
 * EL CASO REAL: Trama (`trama2`, v4.0.23), `users.listas_de_precio = 1`, todo el margen en las
 * listas y el precio base del artículo = costo + IVA. Cuando la SPA no lograba resolver la lista
 * (el catálogo de `price_type` que no llegó, `limpiar_vender()` que la dejó en null) mandaba
 * `price_type_id: null`, `SaleController::store()` lo guardaba tal cual y la venta salía a costo
 * sin un solo error en ningún lado. Medido: 24 ventas de mostrador en 60 días, $77.809 de margen
 * perdido, ganancia ≈ $0.
 *
 * Lo que fijan estos tests, en dos mitades:
 *  - el RECHAZO (422 con `sin_lista_de_precios: true`, `sales` no crece, la venta editada queda
 *    como estaba), que es lo nuevo;
 *  - los casos en que NO se rechaza y que no pueden regresar: la lista del cliente rescata, la
 *    lista explícita se respeta, una cuenta sin listas (golonorte) sigue guardando null y el 0 como
 *    null, una cuenta con el flag pero sin listas cargadas o con la extensión de rangos no tiene
 *    qué exigir, y un PUT sin la clave (la SPA anterior) preserva la lista guardada.
 *
 * DatabaseTransactions (no RefreshDatabase): la base del slot está sembrada de antes. Las listas,
 * el artículo y el cliente se crean adentro de la transacción con prefijo `zz`, y
 * `users.listas_de_precio` se guarda en setUp y se restaura en tearDown además del rollback.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Venta_sin_lista_de_precios_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing (TestingFerreteriaSeeder). */
    const USER_ID = 500;

    /** @var string Mismo slug que la SPA y `PriceTypeHelper::EXTENCION_RANGOS`. */
    const EXTENCION_RANGOS = 'lista_de_precios_por_rango_de_cantidad_vendida';

    /** @var int Precio del renglón, ya resuelto por el front. */
    const PRECIO = 100;

    /** @var int Cantidad del renglón. */
    const CANTIDAD = 2;

    /** @var \App\Models\User */
    protected $user;

    /** @var int|null Valor original de users.listas_de_precio, para dejarlo como estaba. */
    protected $listas_original = null;

    /** @var \App\Models\PriceType Position ALTA: la que el front elige por defecto. */
    protected $lista_general;

    /** @var \App\Models\PriceType Position baja: la que se le asigna al cliente. */
    protected $lista_mostrador;

    /** @var \App\Models\Article */
    protected $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->listas_original = $this->user->listas_de_precio;

        $this->actingAs($this->user, 'web');

        $this->lista_general = PriceType::create([
            'name'     => 'zz General (venta sin lista)',
            'user_id'  => self::USER_ID,
            'position' => 9,
        ]);

        $this->lista_mostrador = PriceType::create([
            'name'     => 'zz Mostrador (venta sin lista)',
            'user_id'  => self::USER_ID,
            'position' => 1,
        ]);

        /*
         * Sin stock a propósito: `SaleHelper::usa_stock()` da false con stock null y el alta no
         * descuenta nada. Acá se mide la lista, no el stock.
         */
        $this->article = Article::create([
            'name'        => 'zz Articulo venta sin lista',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO,
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
     * Prende o apaga las listas de precio de la cuenta.
     *
     * Va por query y no por save(): el controlador lee al dueño fresco en cada request
     * (`UserHelper::user()` hace `User::find`), así que no hace falta tocar la instancia, y un
     * save() del User dispara observers que acá no aportan nada.
     *
     * @param  int  $usa  1 o 0
     * @return void
     */
    protected function cuenta_con_listas($usa)
    {
        User::where('id', self::USER_ID)->update(['listas_de_precio' => $usa]);
    }

    /**
     * Cliente propio del test, con la lista que le pidan (o ninguna), y con su cuenta en pesos.
     *
     * @param  int|null  $price_type_id
     * @return \App\Models\Client
     */
    protected function cliente($price_type_id)
    {
        $client = Client::create([
            'name'          => 'zz Cliente venta sin lista '.uniqid(),
            'user_id'       => self::USER_ID,
            'price_type_id' => $price_type_id,
        ]);

        /*
         * La cuenta en pesos se siembra aunque estas ventas no pasen por la cuenta corriente:
         * `CurrentAcountFromSaleHelper` la usa sin chequear null, y si alguien cambia el payload
         * para que la venta sí entre a la cuenta, el test tiene que seguir midiendo la lista y no
         * morir con "Trying to get property 'id' of non-object". Mismo apaño que
         * tests/Feature/Presupuestos/1 y Sales/9.
         */
        CreditAccount::firstOrCreate(
            ['model_name' => 'client', 'model_id' => $client->id, 'moneda_id' => 1],
            ['saldo' => 0, 'user_id' => self::USER_ID]
        );

        return $client;
    }

    /**
     * Le da a la cuenta la extensión de rangos, creando la fila del catálogo si la base del slot
     * no la tiene sembrada (`extencion_empresas` puede venir vacía). forceCreate porque el modelo
     * no declara $fillable (ver tests/Feature/Extenciones/1). DatabaseTransactions revierte todo.
     *
     * @return void
     */
    protected function dar_extension_de_rangos()
    {
        $extencion = ExtencionEmpresa::where('slug', self::EXTENCION_RANGOS)->first();

        if (is_null($extencion)) {

            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::EXTENCION_RANGOS,
                'name' => 'Lista de precios por rango de cantidad vendida',
            ]);
        }

        if (!$this->user->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $this->user->extencions()->attach($extencion->id);
        }
    }

    /**
     * El payload de POST api/sale tal como lo manda VENDER para una venta de mostrador: sin
     * cliente, un renglón ya preciado (`price_vender`) y `price_type_id` en null, que es
     * exactamente lo que mandaba la SPA de Trama cuando no había podido resolver la lista
     * (`price_type_id: state.price_type ? state.price_type.id : null`).
     *
     * El resto está calcado de tests/Feature/LimiteCredito/1, que es el molde de alta que pasa en
     * este slot con y sin cliente.
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload_venta($overrides = [])
    {
        $total = self::PRECIO * self::CANTIDAD;

        return array_merge([
            'client_id'                  => null,
            'address_id'                 => null,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'price_type_id'              => null,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'employee_id'                => null,
            'sub_total'                  => $total,
            'total'                      => $total,
            'terminada'                  => 1,
            'seller_id'                  => null,
            'cantidad_cuotas'            => null,
            'cuota_descuento'            => 0,
            'cuota_recargo'              => 0,
            'caja_id'                    => null,
            'afip_tipo_comprobante_id'   => null,
            'descuento'                  => null,
            'moneda_id'                  => 1,
            'discount_stock'             => 0,
            'discounts'                  => [],
            'surchages'                  => [],
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->article->id,
                    'name'         => $this->article->name,
                    'price_vender' => self::PRECIO,
                    'amount'       => self::CANTIDAD,
                ],
            ],
        ], $overrides);
    }

    /**
     * Venta con cliente que paga en el acto: no entra a la cuenta corriente
     * (`omitir_en_cuenta_corriente` + `save_current_acount` en 0, el mismo par que usa el caso de
     * mostrador de LimiteCredito/1), así que el test mide la lista y nada más.
     *
     * @param  \App\Models\Client  $client
     * @param  array               $overrides
     * @return array
     */
    protected function payload_venta_con_cliente($client, $overrides = [])
    {
        return $this->payload_venta(array_merge([
            'client_id'                  => $client->id,
            'omitir_en_cuenta_corriente' => 1,
            'save_current_acount'        => 0,
        ], $overrides));
    }

    /**
     * Venta de mostrador ya guardada, con lista, para los tests del PUT. Va directo a la base
     * (mismo molde que tests/Feature/Sales/9): lo que se mide es qué hace update() con la lista,
     * no el alta.
     *
     * @param  int|null  $price_type_id
     * @return \App\Models\Sale
     */
    protected function venta_guardada_con_lista($price_type_id)
    {
        return Sale::create([
            'user_id'                    => self::USER_ID,
            'client_id'                  => null,
            'price_type_id'              => $price_type_id,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'discount_stock'             => 0,
            'moneda_id'                  => 1,
            'sub_total'                  => self::PRECIO * self::CANTIDAD,
            'total'                      => self::PRECIO * self::CANTIDAD,
        ]);
    }

    /**
     * Payload mínimo y válido de PUT api/sale/{id}, calcado de tests/Feature/Vender/2 y Sales/9:
     * to_check/checked/confirmed/discounts_in_services/surchages_in_services son NOT NULL y
     * update() los asigna a secas; los items van siempre porque el update hace detach + attach.
     *
     * SIN `price_type_id` a propósito: cada test decide si la clave viaja y con qué. Ausente es lo
     * que manda la SPA anterior a esta misión.
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload_update($overrides = [])
    {
        $total = self::PRECIO * self::CANTIDAD;

        return array_merge([
            'client_id'                  => null,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'discount_stock'             => 0,
            'sub_total'                  => $total,
            'total'                      => $total,
            'moneda_id'                  => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->article->id,
                    'name'         => $this->article->name,
                    'price_vender' => self::PRECIO,
                    'amount'       => self::CANTIDAD,
                ],
            ],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ], $overrides);
    }

    /**
     * @return int
     */
    protected function cantidad_de_ventas()
    {
        return Sale::where('user_id', self::USER_ID)->count();
    }

    /**
     * Las aserciones del rechazo, compartidas: 422, la bandera que la SPA nueva lee, el texto que
     * la SPA vieja muestra en su catch genérico.
     *
     * @param  \Illuminate\Testing\TestResponse  $response
     * @return void
     */
    protected function assert_rechazo_sin_lista($response)
    {
        $response->assertStatus(422);

        $this->assertTrue(
            (bool) $response->json('sin_lista_de_precios'),
            'El 422 tiene que llevar sin_lista_de_precios: true, que es lo que distingue este rechazo en la SPA.'
        );

        $this->assertEquals(PriceTypeHelper::mensaje_sin_lista(), $response->json('message'));
    }

    /**
     * 🔴 EL CASO DE TRAMA: cuenta con listas, venta de mostrador (sin cliente) y sin lista. Antes
     * de esta misión el 201 salía igual y la venta quedaba a costo. Ahora es 422 y no se crea nada.
     *
     * @group vender
     * @test
     */
    public function cuenta_con_listas_sin_cliente_y_sin_lista_responde_422_y_no_crea_la_venta()
    {
        $this->cuenta_con_listas(1);

        $ventas_antes = $this->cantidad_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_venta());

        $this->assert_rechazo_sin_lista($response);

        $this->assertEquals(
            $ventas_antes,
            $this->cantidad_de_ventas(),
            'Un 422 no puede haber creado la venta: el rechazo va antes de la transacción.'
        );
    }

    /**
     * Con cliente pero SIN lista asignada el rescate del cliente no tiene de dónde sacar nada:
     * mismo 422. Es el otro tercio de las ventas de Trama (cliente cargado, lista vacía).
     *
     * @group vender
     * @test
     */
    public function cuenta_con_listas_con_cliente_sin_lista_y_sin_lista_responde_422()
    {
        $this->cuenta_con_listas(1);

        $client = $this->cliente(null);

        $ventas_antes = $this->cantidad_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_venta_con_cliente($client));

        $this->assert_rechazo_sin_lista($response);

        $this->assertEquals($ventas_antes, $this->cantidad_de_ventas());
    }

    /**
     * 🔴 El cliente con `price_type_id = 0` es el caso MÁS COMÚN de "cliente sin lista": el form
     * genérico de clientes nace en 0 (`src/models/client.js`) y `ClientController` lo guarda
     * pelado. Hasta esta misión el rescate copiaba ese 0 a la venta (`!is_null(0)` es true) y con
     * eso esquivaba cualquier chequeo. El resolvedor pasa la lista del cliente por el mismo
     * normalizador que el request: 0 es ninguna, y en una cuenta con listas es 422.
     *
     * @group vender
     * @test
     */
    public function con_cliente_con_lista_en_cero_y_sin_lista_responde_422()
    {
        $this->cuenta_con_listas(1);

        $client = $this->cliente(0);

        $ventas_antes = $this->cantidad_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_venta_con_cliente($client));

        $this->assert_rechazo_sin_lista($response);

        $this->assertEquals($ventas_antes, $this->cantidad_de_ventas());
    }

    /**
     * Y en una cuenta SIN listas el 0 del cliente tampoco se copia: la venta queda con null, no
     * con 0. Es de donde salían las 309 ventas con `price_type_id = 0` de golonorte (auditoría del
     * 17/9/2026): no de la SPA, que nunca manda un 0, sino de este rescate.
     *
     * @group vender
     * @test
     */
    public function cuenta_sin_listas_con_cliente_en_cero_guarda_null_y_no_cero()
    {
        $this->cuenta_con_listas(0);

        $client = $this->cliente(0);

        $response = $this->postJson('api/sale', $this->payload_venta_con_cliente($client));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNull($sale->price_type_id, 'El 0 del cliente no es una lista: no se copia, queda null.');
    }

    /**
     * El cero es "ninguna lista" escrito de otra forma (golonorte manda 0 en 309 ventas al mes, y
     * no es una lista): en una cuenta con listas se rechaza igual que el null.
     *
     * @group vender
     * @test
     */
    public function el_cero_se_lee_como_ninguna_lista_y_responde_422()
    {
        $this->cuenta_con_listas(1);

        $ventas_antes = $this->cantidad_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_venta(['price_type_id' => 0]));

        $this->assert_rechazo_sin_lista($response);

        $this->assertEquals($ventas_antes, $this->cantidad_de_ventas());
    }

    /**
     * El ÚNICO default que se aplica: la lista del cliente. Es lo que el front hubiera usado para
     * preciar (presupuesto → cliente → mayor position) y lo que store() ya hacía después del
     * create. La venta nace con la lista del cliente, no con la de mayor position.
     *
     * @group vender
     * @test
     */
    public function con_cliente_con_lista_la_venta_nace_con_la_lista_del_cliente()
    {
        $this->cuenta_con_listas(1);

        $client = $this->cliente($this->lista_mostrador->id);

        $response = $this->postJson('api/sale', $this->payload_venta_con_cliente($client));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);
        $this->assertEquals(
            $this->lista_mostrador->id,
            (int) $sale->price_type_id,
            'Sin lista en el request y con cliente con lista, la venta se lleva la del cliente.'
        );
    }

    /**
     * La lista explícita del request manda, aunque el cliente tenga otra: el front la eligió y
     * con ella preció los renglones.
     *
     * @group vender
     * @test
     */
    public function con_lista_explicita_la_venta_nace_con_esa_lista()
    {
        $this->cuenta_con_listas(1);

        $client = $this->cliente($this->lista_mostrador->id);

        $response = $this->postJson('api/sale', $this->payload_venta_con_cliente($client, [
            'price_type_id' => $this->lista_general->id,
        ]));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertEquals(
            $this->lista_general->id,
            (int) $sale->price_type_id,
            'La lista del request tiene prioridad sobre la del cliente.'
        );
    }

    /**
     * NO REGRESIÓN, caso golonorte: `listas_de_precio = 0` (márgenes por categoría, la lista viaja
     * por línea en `article_sale.price_type_personalizado_id`). Sin lista en la venta sigue siendo
     * 201 con null: la regla se ancla en el flag del dueño, no en que existan listas.
     *
     * @group vender
     * @test
     */
    public function cuenta_sin_listas_sigue_guardando_la_venta_sin_lista()
    {
        $this->cuenta_con_listas(0);

        $response = $this->postJson('api/sale', $this->payload_venta());

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNull($sale->price_type_id, 'Una cuenta sin listas guarda la venta sin lista, como siempre.');
    }

    /**
     * Y el 0 de golonorte se guarda como null: es "ninguna", no una lista con id 0.
     *
     * @group vender
     * @test
     */
    public function cuenta_sin_listas_guarda_el_cero_como_null()
    {
        $this->cuenta_con_listas(0);

        $response = $this->postJson('api/sale', $this->payload_venta(['price_type_id' => 0]));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNull($sale->price_type_id, 'El 0 no es una lista: se persiste como null.');
    }

    /**
     * Flag prendido pero SIN ninguna lista cargada (cuenta recién configurada): no hay qué exigir,
     * 201 con null. Las listas del usuario 500 se borran adentro de la transacción; no hay FK
     * sobre price_type_id, y el rollback las devuelve.
     *
     * @group vender
     * @test
     */
    public function cuenta_con_el_flag_pero_sin_listas_cargadas_no_exige_nada()
    {
        $this->cuenta_con_listas(1);

        PriceType::where('user_id', self::USER_ID)->delete();

        $this->assertFalse(
            PriceTypeHelper::requiere_lista_de_precios(User::find(self::USER_ID)),
            'Sin listas cargadas el criterio tiene que dar false; si da true, este test no mide el controlador.'
        );

        $response = $this->postJson('api/sale', $this->payload_venta());

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNull($sale->price_type_id);
    }

    /**
     * Con la extensión de rangos la lista se decide por la cantidad vendida de cada renglón y el
     * front no setea lista de venta a propósito: la cuenta queda afuera de la regla.
     *
     * @group vender
     * @test
     */
    public function cuenta_con_la_extension_de_rangos_no_exige_lista()
    {
        $this->cuenta_con_listas(1);

        $this->dar_extension_de_rangos();

        $response = $this->postJson('api/sale', $this->payload_venta());

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNull($sale->price_type_id);
    }

    /**
     * PUT con `price_type_id` nuevo: se persiste. Hasta esta misión update() no tocaba la columna
     * y la edición no podía cambiar la lista.
     *
     * @group vender
     * @test
     */
    public function put_con_lista_nueva_la_persiste()
    {
        $this->cuenta_con_listas(1);

        $venta = $this->venta_guardada_con_lista($this->lista_mostrador->id);

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'price_type_id' => $this->lista_general->id,
        ]));

        $response->assertStatus(200);

        $this->assertEquals(
            $this->lista_general->id,
            (int) Sale::find($venta->id)->price_type_id,
            'Un PUT con price_type_id tiene que cambiar la lista de la venta.'
        );
    }

    /**
     * 🔴 PUT SIN la clave (la SPA anterior a esta misión no la manda en la edición): la lista
     * guardada se preserva. Mismo patrón que `omitir_en_cuenta_corriente` en Sales/9: a secas,
     * cualquier venta editada desde esa SPA perdería su lista sin que nadie la tocara.
     *
     * @group vender
     * @test
     */
    public function put_sin_la_clave_preserva_la_lista_guardada()
    {
        $this->cuenta_con_listas(1);

        $venta = $this->venta_guardada_con_lista($this->lista_mostrador->id);

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update());

        $response->assertStatus(200);

        $guardado = Sale::find($venta->id)->price_type_id;

        $this->assertNotNull($guardado, 'El PUT sin la clave dejó la lista en null: la pisó.');
        $this->assertEquals($this->lista_mostrador->id, (int) $guardado);
    }

    /**
     * PUT con null explícito en una cuenta con listas: 422 y la venta queda EXACTAMENTE como
     * estaba. El `total` y las observaciones distintas del payload son la prueba de que no se
     * escribió nada: el rechazo va antes de la transacción.
     *
     * @group vender
     * @test
     */
    public function put_con_null_explicito_en_cuenta_con_listas_responde_422_y_no_toca_la_venta()
    {
        $this->cuenta_con_listas(1);

        $venta = $this->venta_guardada_con_lista($this->lista_mostrador->id);

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'price_type_id' => null,
            'total'         => 999,
            'sub_total'     => 999,
            'observations'  => 'no tendria que guardarse',
        ]));

        $this->assert_rechazo_sin_lista($response);

        $despues = Sale::find($venta->id);

        $this->assertEquals($this->lista_mostrador->id, (int) $despues->price_type_id, 'La lista no se tiene que haber tocado.');
        $this->assertEquals(self::PRECIO * self::CANTIDAD, (float) $despues->total, 'El total no se tiene que haber tocado.');
        $this->assertNull($despues->observations, 'Las observaciones no se tienen que haber tocado.');
    }

    /**
     * El mismo PUT con null en una cuenta SIN listas es válido: golonorte edita ventas sin lista
     * todos los días y no puede recibir un 422.
     *
     * @group vender
     * @test
     */
    public function put_con_null_explicito_en_cuenta_sin_listas_deja_la_venta_sin_lista()
    {
        $this->cuenta_con_listas(0);

        $venta = $this->venta_guardada_con_lista($this->lista_mostrador->id);

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'price_type_id' => null,
        ]));

        $response->assertStatus(200);

        $this->assertNull(Sale::find($venta->id)->price_type_id);
    }

    /**
     * El texto del 422 es lo que ve el vendedor con la SPA vieja (que muestra
     * `err.response.data.message` en su catch genérico): tiene que nombrar la lista de precios y
     * decir "recargá la página", que es a la vez lo que destraba el catálogo que no llegó y lo que
     * trae el bundle nuevo.
     *
     * @group vender
     * @test
     */
    public function el_mensaje_del_422_nombra_la_lista_y_pide_recargar_la_pagina()
    {
        $this->cuenta_con_listas(1);

        $response = $this->postJson('api/sale', $this->payload_venta());

        $response->assertStatus(422);

        $mensaje = (string) $response->json('message');

        $this->assertStringContainsString('lista de precios', $mensaje);
        $this->assertStringContainsString('recargá la página', $mensaje);
        $this->assertStringContainsString('la venta', $mensaje);
    }
}
