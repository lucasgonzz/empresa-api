<?php

namespace Tests\Feature\Presupuestos;

use App\Models\Article;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\Combo;
use App\Models\CreditAccount;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\Surchage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Combos en el circuito de presupuestos (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * EL BUG QUE FIJAN ESTOS TESTS: un presupuesto no sabia guardar combos. La tabla `budget_combo` no
 * existia y `BudgetController` no leia ninguna clave `combos`. Como el combo SI entra en el `total`
 * que la SPA manda en el payload (`vender_set_total.js`), cargar un combo en VENDER con "Guardar
 * como presupuesto" tildado terminaba en un 500: `BudgetHelper::getTotal()` sumaba articulos,
 * promos y servicios, no encontraba el combo, y la diferencia contra `$budget->total` se pasaba del
 * margen de 3 de `BudgetController::store()`.
 *
 * O sea que el vendedor no veia "el combo no se guardo": veia "El total del presupuesto no
 * corresponde con los productos ingresados", que no explica nada.
 *
 * Los dos tests que valen mas que el resto:
 *
 *  - `un_total_mal_declarado_con_combo_se_sigue_rechazando`: sin el, "el total no se rechaza" se
 *    podria cumplir tambien apagando la validacion. Con el, la unica forma de que los dos pasen es
 *    que `getTotal()` este sumando el combo de verdad.
 *  - `confirmar_descuenta_el_stock_de_los_componentes_y_no_el_del_combo`: un combo NO tiene stock
 *    propio, es una receta. Ese es el unico punto donde el molde de `promocion_vinoteca` no se
 *    copia, y es el que mas facil se hace mal.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada de antes y un refresh
 * la vaciaria. Mismo criterio que los otros dos archivos de esta carpeta.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class Combos_en_presupuestos_Test extends TestCase
{
    use DatabaseTransactions;

    /** Ids de `budget_statuses`, tabla global sembrada por `BudgetStatusSeeder`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /** Precio unitario del combo, tal como viaja en `budget_combo.price`. */
    const PRECIO_COMBO = 500;

    /** Cantidad de combos del renglon. */
    const CANTIDAD_COMBO = 3;

    /** Unidades del articulo componente que lleva UN combo (`article_combo.amount`). */
    const UNIDADES_POR_COMBO = 2;

    /** Stock con el que arranca el articulo componente. */
    const STOCK_INICIAL = 20;

    /** Slug de la extension que gatea `BudgetController::duplicate()`. */
    const EXTENCION_DUPLICAR = 'duplicar_presupuestos';

    /**
     * ⚠️ `budget_statuses` puede venir vacia en la base del slot (medido el 21/8/2026). Se siembra
     * con ids explicitos para no depender del auto-increment; `DatabaseTransactions` lo revierte.
     *
     * Sin estas filas `BudgetHelper::checkStatus()` revienta en vez de fallar: hace
     * `$budget->budget_status->name` sin chequear null.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $estados = [
            Self::ESTADO_SIN_CONFIRMAR => 'Sin confirmar',
            Self::ESTADO_CONFIRMADO    => 'Confirmado',
        ];

        foreach ($estados as $id => $name) {

            $existente = BudgetStatus::find($id);

            if (is_null($existente)) {

                $estado = new BudgetStatus();
                $estado->id = $id;
                $estado->name = $name;
                $estado->save();
            }
        }
    }

    /**
     * Autentica al usuario de testing, o saltea el test si la base no lo tiene sembrado.
     *
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
     * Cliente del usuario de testing, con su `credit_account` en pesos.
     *
     * El fixture trae `credit_accounts` para los PROVEEDORES y ninguna para los clientes, y
     * `CurrentAcountFromSaleHelper::crear_current_acount()` la usa sin chequear null: sin esta fila
     * el test de confirmar revienta. Mismo apaño que en los otros dos archivos de esta carpeta.
     *
     * @return \App\Models\Client
     */
    protected function cliente_de_testing()
    {
        $client = Client::where('user_id', 500)->first();

        if (is_null($client)) {
            $this->markTestSkipped('La base de testing no tiene ningun cliente del usuario 500.');
        }

        $credit_account = CreditAccount::where('model_name', 'client')
                                        ->where('model_id', $client->id)
                                        ->where('moneda_id', 1)
                                        ->first();

        if (is_null($credit_account)) {

            CreditAccount::create([
                'model_name' => 'client',
                'model_id'   => $client->id,
                'moneda_id'  => 1,
                'saldo'      => 0,
                'user_id'    => 500,
            ]);
        }

        return $client;
    }

    /**
     * Articulo componente del combo, con stock.
     *
     * El stock NO puede quedar en null: `sale\ComboHelper::usa_stock()` saltea los articulos sin
     * stock, asi que un componente en null haria pasar el test de descuento sin descontar nada.
     *
     * @return \App\Models\Article
     */
    protected function articulo_de_testing()
    {
        return Article::create([
            'name'       => 'zz Componente de combo '.uniqid(),
            'user_id'    => 500,
            'costo_real' => 50,
            'stock'      => Self::STOCK_INICIAL,
        ]);
    }

    /**
     * Combo de un solo componente, con `UNIDADES_POR_COMBO` unidades adentro.
     *
     * @param \App\Models\Article $article
     * @return \App\Models\Combo
     */
    protected function combo_de_testing($article)
    {
        $combo = Combo::create([
            'num'     => 9500,
            'name'    => 'zz Combo de presupuesto '.uniqid(),
            'price'   => Self::PRECIO_COMBO,
            'cost'    => 200,
            'user_id' => 500,
        ]);

        $combo->articles()->attach($article->id, ['amount' => Self::UNIDADES_POR_COMBO]);

        return $combo;
    }

    /**
     * Le da al usuario de testing la extension que gatea `duplicate()`, creando la fila del
     * catalogo si la base del slot no la tiene sembrada.
     *
     * Sin la extension, `duplicate()` corta en 403 antes de tocar nada y el test no mediria nada.
     * Mismo apaño que en `2_Presupuesto_Recargos_Directo_A_Items_Test`.
     *
     * @return void
     */
    protected function dar_extension_duplicar()
    {
        $extencion = ExtencionEmpresa::where('slug', Self::EXTENCION_DUPLICAR)->first();

        if (is_null($extencion)) {

            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => Self::EXTENCION_DUPLICAR,
                'name' => 'Duplicar presupuestos',
            ]);
        }

        $user = User::find(500);

        if (!$user->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $user->extencions()->attach($extencion->id);
        }
    }

    /**
     * Stock del articulo leido de la base, nunca del modelo en memoria.
     *
     * @param \App\Models\Article|int $articulo
     * @return float|null
     */
    protected function stock($articulo)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        $stock = DB::table('articles')->where('id', $id)->value('stock');

        return is_null($stock) ? null : (float) $stock;
    }

    /**
     * Filas de `budget_combo` de un presupuesto.
     *
     * Se lee con DB::table y no por la relacion: lo que interesa es lo que quedo escrito en el
     * pivote, no lo que Eloquent devuelve despues de pasarlo por el modelo.
     *
     * @param int $budget_id
     * @return \Illuminate\Support\Collection
     */
    protected function filas_del_pivote($budget_id)
    {
        return DB::table('budget_combo')->where('budget_id', $budget_id)->get();
    }

    /**
     * Payload de POST api/budget en la forma exacta que manda VENDER.
     *
     * La clave `combos` es la que hasta esta mision no existia, y va calcada de
     * `get_promocion_vinotecas()` de la SPA: `{ id, pivot: { amount, price } }`.
     *
     * @param \App\Models\Client $client
     * @param array $combos Renglones ya armados (o [] para el caso sin combos).
     * @param float $total Total declarado del presupuesto.
     * @param array $overrides
     * @return array
     */
    protected function payload_crear($client, $combos, $total, $overrides = [])
    {
        return array_merge([
            'client_id'              => $client->id,
            'start_at'               => null,
            'finish_at'              => null,
            'observations'           => null,
            'price_type_id'          => null,
            'sale_status_id'         => null,
            'discount_stock'         => 0,
            'iva_aplicado'           => 1,
            'total'                  => $total,
            'budget_status_id'       => Self::ESTADO_SIN_CONFIRMAR,
            'address_id'             => null,
            'surchages_in_services'  => 1,
            'discounts_in_services'  => 1,
            'aplicar_recargos_directo_a_items' => 0,
            'moneda_id'              => 1,
            'valor_dolar'            => null,
            'discounts'              => [],
            'surchages'              => [],
            'services'               => [],
            'promocion_vinotecas'    => [],
            'articles'               => [],
            'combos'                 => $combos,
        ], $overrides);
    }

    /**
     * Renglon de combo en la forma del contrato SPA → API.
     *
     * @param \App\Models\Combo $combo
     * @param float $amount
     * @param float|null $price
     * @return array
     */
    protected function renglon_combo($combo, $amount, $price = null)
    {
        return [
            'id'    => $combo->id,
            'pivot' => [
                'amount' => $amount,
                'price'  => is_null($price) ? Self::PRECIO_COMBO : $price,
            ],
        ];
    }

    /**
     * Payload minimo y valido para PUT api/budget/{id}.
     *
     * Los arrays van vacios pero PRESENTES: `attachArticles`/`attachServices`/
     * `attachPromocionVinotecas` hacen foreach sin chequear null. `combos` se omite a proposito en
     * los overrides cuando el test mide justamente la clave ausente.
     *
     * @param \App\Models\Budget $budget
     * @param array $overrides
     * @return array
     */
    protected function payload_actualizar($budget, $overrides = [])
    {
        return array_merge([
            'client_id'             => $budget->client_id,
            'start_at'              => null,
            'finish_at'             => null,
            'observations'          => 'actualizado por el test',
            'total'                 => $budget->total,
            'budget_status_id'      => $budget->budget_status_id,
            'address_id'            => null,
            'surchages_in_services' => 1,
            'discounts_in_services' => 1,
            'moneda_id'             => 1,
            'sale_status_id'        => null,
            'discount_stock'        => 0,
            'iva_aplicado'          => 1,
            'articles'              => [],
            'services'              => [],
            'promocion_vinotecas'   => [],
            'discounts'             => [],
            'surchages'             => [],
        ], $overrides);
    }

    /**
     * 🔴 El caso que hoy termina en 500: un presupuesto cuyo unico item es un combo.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function store_con_un_combo_lo_guarda_en_el_pivote_y_no_rechaza_el_total()
    {
        $this->autenticar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $combo = $this->combo_de_testing($article);

        $total = Self::PRECIO_COMBO * Self::CANTIDAD_COMBO;

        $payload = $this->payload_crear(
            $client,
            [$this->renglon_combo($combo, Self::CANTIDAD_COMBO)],
            $total
        );

        $budget_id = $this->post('api/budget', $payload)
                            ->assertStatus(201)
                            ->json('model.id');

        $filas = $this->filas_del_pivote($budget_id);

        $this->assertCount(1, $filas, 'El combo tiene que haber quedado en budget_combo.');

        $fila = $filas->first();

        $this->assertEquals($combo->id, (int) $fila->combo_id);
        $this->assertEquals(Self::CANTIDAD_COMBO, (float) $fila->amount);
        $this->assertEquals(Self::PRECIO_COMBO, (float) $fila->price);
    }

    /**
     * 🔴 El control negativo del test de arriba.
     *
     * "El total no se rechaza" se podria cumplir tambien apagando la validacion. Este test deja una
     * sola explicacion posible para que los dos pasen: `getTotal()` esta sumando el combo de verdad.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function un_total_mal_declarado_con_combo_se_sigue_rechazando()
    {
        $this->autenticar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $combo = $this->combo_de_testing($article);

        /* El total declarado se lleva un combo de menos: 1000 contra los 1500 que suman los tres. */
        $total_mal = Self::PRECIO_COMBO * (Self::CANTIDAD_COMBO - 1);

        /* Marca unica: la base del slot ya tiene presupuestos y un filtro por `total` pescaria ajenos. */
        $marca = 'zz combo total mal '.uniqid();

        $payload = $this->payload_crear(
            $client,
            [$this->renglon_combo($combo, Self::CANTIDAD_COMBO)],
            $total_mal,
            ['observations' => $marca]
        );

        $this->post('api/budget', $payload)->assertStatus(500);

        $this->assertEquals(
            0,
            Budget::where('observations', $marca)->count(),
            'El rollback tiene que haber dejado el presupuesto sin crear.'
        );
    }

    /**
     * El recargo se le aplica al combo con la misma regla que a los articulos y las promos.
     *
     * Va con `aplicar_recargos_directo_a_items` APAGADO, que es el 99% de los presupuestos: el
     * precio del pivot no trae el recargo adentro, asi que `getTotal()` lo suma. Si el bucket nuevo
     * se hubiera escrito sin esa parte, este total no cerraria.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function el_recargo_del_presupuesto_tambien_alcanza_al_combo()
    {
        $this->autenticar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $combo = $this->combo_de_testing($article);

        $surchage = Surchage::create([
            'name'       => 'zz Recargo combos '.uniqid(),
            'percentage' => 10,
            'user_id'    => 500,
        ]);

        $sub_total = Self::PRECIO_COMBO * Self::CANTIDAD_COMBO;
        $total_con_recargo = $sub_total * 1.1;

        $payload = $this->payload_crear(
            $client,
            [$this->renglon_combo($combo, Self::CANTIDAD_COMBO)],
            $total_con_recargo,
            [
                'surchages' => [[
                    'id'         => $surchage->id,
                    'percentage' => 10,
                ]],
            ]
        );

        $this->post('api/budget', $payload)->assertStatus(201);
    }

    /**
     * 🔴 EL PUNTO DONDE EL MOLDE DE `promocion_vinoteca` NO SE COPIA.
     *
     * Una promo de vinoteca es un articulo virtual con stock propio y se descuenta a si misma. Un
     * combo es una receta y no tiene stock: lo que se descuenta es el de cada componente, por la
     * cantidad de combos. 3 combos x 2 unidades = 6, y el componente tiene que quedar en 14.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function confirmar_descuenta_el_stock_de_los_componentes_y_no_el_del_combo()
    {
        $this->autenticar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $combo = $this->combo_de_testing($article);

        $total = Self::PRECIO_COMBO * Self::CANTIDAD_COMBO;

        $payload = $this->payload_crear(
            $client,
            [$this->renglon_combo($combo, Self::CANTIDAD_COMBO)],
            $total,
            ['discount_stock' => 1]
        );

        $budget_id = $this->post('api/budget', $payload)
                            ->assertStatus(201)
                            ->json('model.id');

        $this->assertEquals(
            Self::STOCK_INICIAL,
            $this->stock($article),
            'Guardar el presupuesto no puede mover stock: la venta todavia no existe.'
        );

        $this->post('api/budget/'.$budget_id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget_id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');

        $fila_venta = DB::table('combo_sale')->where('sale_id', $sale->id)->get();

        $this->assertCount(1, $fila_venta, 'El combo tiene que haber viajado a combo_sale.');
        $this->assertEquals($combo->id, (int) $fila_venta->first()->combo_id);
        $this->assertEquals(Self::CANTIDAD_COMBO, (float) $fila_venta->first()->amount);
        $this->assertEquals(Self::PRECIO_COMBO, (float) $fila_venta->first()->price);

        $descontado = Self::UNIDADES_POR_COMBO * Self::CANTIDAD_COMBO;

        $this->assertEquals(
            Self::STOCK_INICIAL - $descontado,
            $this->stock($article),
            'Confirmar tiene que descontar UNIDADES_POR_COMBO x CANTIDAD_COMBO del componente.'
        );

        $movimientos = DB::table('stock_movements')
                            ->where('article_id', $article->id)
                            ->where('sale_id', $sale->id)
                            ->get();

        $this->assertCount(1, $movimientos, 'Tiene que haber UN movimiento de stock por el combo.');
        $this->assertEquals(-$descontado, (float) $movimientos->first()->amount);
    }

    /**
     * El gate de `discount_stock` que ya trae `sale\ComboHelper::discount_articles_stock()`.
     *
     * No es un caso de borde inventado: la auditoria de stock del 5/9/2026 encontro exactamente el
     * bug inverso en VENDER (el combo descontaba aunque la venta tuviera `discount_stock` en 0, y
     * el borrado solo devolvia con el flag en 1). Reusar ese helper en vez de escribir un descuento
     * nuevo es lo que evita repetirlo del lado de los presupuestos.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function confirmar_sin_descuento_de_stock_no_toca_los_componentes()
    {
        $this->autenticar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $combo = $this->combo_de_testing($article);

        $total = Self::PRECIO_COMBO * Self::CANTIDAD_COMBO;

        $payload = $this->payload_crear(
            $client,
            [$this->renglon_combo($combo, Self::CANTIDAD_COMBO)],
            $total,
            ['discount_stock' => 0]
        );

        $budget_id = $this->post('api/budget', $payload)
                            ->assertStatus(201)
                            ->json('model.id');

        $this->post('api/budget/'.$budget_id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget_id)->first();

        $this->assertNotNull($sale);

        $this->assertCount(
            1,
            DB::table('combo_sale')->where('sale_id', $sale->id)->get(),
            'El combo viaja a la venta igual: lo que cambia es el stock, no el renglon.'
        );

        $this->assertEquals(
            Self::STOCK_INICIAL,
            $this->stock($article),
            'Con discount_stock en 0 el combo no puede tocar el stock del componente.'
        );
    }

    /**
     * Agregar un combo por PUT y despues sacarlo.
     *
     * El segundo PUT manda `combos: []`, que es lo que manda VENDER cuando el vendedor saco el
     * ultimo combo del remito: la clave PRESENTE y vacia significa "no hay combos", y detacha.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function update_agrega_un_combo_y_despues_lo_saca()
    {
        $this->autenticar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $combo = $this->combo_de_testing($article);

        $budget = Budget::create([
            'user_id'               => 500,
            'client_id'             => $client->id,
            'budget_status_id'      => Self::ESTADO_SIN_CONFIRMAR,
            'total'                 => 0,
            'discount_stock'        => 0,
            'discounts_in_services' => 1,
            'surchages_in_services' => 1,
        ]);

        $this->assertCount(0, $this->filas_del_pivote($budget->id));

        $con_combo = $this->payload_actualizar($budget, [
            'total'  => Self::PRECIO_COMBO * Self::CANTIDAD_COMBO,
            'combos' => [$this->renglon_combo($combo, Self::CANTIDAD_COMBO)],
        ]);

        $this->put('api/budget/'.$budget->id, $con_combo)->assertStatus(200);

        $filas = $this->filas_del_pivote($budget->id);

        $this->assertCount(1, $filas, 'El PUT tiene que haber agregado el combo.');
        $this->assertEquals(Self::CANTIDAD_COMBO, (float) $filas->first()->amount);

        $budget->refresh();

        $sin_combo = $this->payload_actualizar($budget, [
            'total'  => 0,
            'combos' => [],
        ]);

        $this->put('api/budget/'.$budget->id, $sin_combo)->assertStatus(200);

        $this->assertCount(
            0,
            $this->filas_del_pivote($budget->id),
            'Mandar combos vacio tiene que detachar el combo.'
        );
    }

    /**
     * 🔴 Compatibilidad hacia atras: una empresa-spa vieja no manda la clave `combos`.
     *
     * Dos cosas no pueden pasar con la clave ausente: que el guardado se caiga (las tres claves
     * hermanas SI se caen — `foreach (null)` lo convierte Laravel en ErrorException), y que se le
     * borren al vendedor los combos que el presupuesto ya tenia.
     *
     * Lo segundo importa mas alla de la ventana de despliegue: `BudgetController::update()` lo pegan
     * DOS frentes, VENDER y el form generico del modulo Presupuestos, y basta que uno de los dos no
     * maneje combos para que editar por ahi los borre en silencio. Es el mismo criterio que
     * `attachArticles()` ya aplica con `name_vender_personalizado`.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function un_put_sin_la_clave_combos_no_rompe_ni_borra_los_combos_guardados()
    {
        $this->autenticar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $combo = $this->combo_de_testing($article);

        $budget = Budget::create([
            'user_id'               => 500,
            'client_id'             => $client->id,
            'budget_status_id'      => Self::ESTADO_SIN_CONFIRMAR,
            'total'                 => Self::PRECIO_COMBO * Self::CANTIDAD_COMBO,
            'discount_stock'        => 0,
            'discounts_in_services' => 1,
            'surchages_in_services' => 1,
        ]);

        $budget->combos()->attach($combo->id, [
            'amount' => Self::CANTIDAD_COMBO,
            'price'  => Self::PRECIO_COMBO,
        ]);

        /* El payload NO lleva la clave `combos`: es el de la SPA vieja, tal cual. */
        $payload = $this->payload_actualizar($budget);

        $this->put('api/budget/'.$budget->id, $payload)->assertStatus(200);

        $filas = $this->filas_del_pivote($budget->id);

        $this->assertCount(
            1,
            $filas,
            'La clave ausente no puede borrar el combo que el presupuesto ya tenia.'
        );
        $this->assertEquals(Self::CANTIDAD_COMBO, (float) $filas->first()->amount);
    }

    /**
     * Duplicar un presupuesto con combo.
     *
     * Sin el attach en `BudgetDuplicarHelper`, el duplicado copia el `total` del origen (con el
     * combo adentro) y `getTotal()` no lo encuentra: el 500 del alta, otra vez, en otro endpoint.
     * Por eso el assert del 201 no es decorativo, es la mitad del test.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function duplicar_un_presupuesto_se_lleva_el_combo()
    {
        $this->autenticar();
        $this->dar_extension_duplicar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $combo = $this->combo_de_testing($article);

        $total = Self::PRECIO_COMBO * Self::CANTIDAD_COMBO;

        $payload = $this->payload_crear(
            $client,
            [$this->renglon_combo($combo, Self::CANTIDAD_COMBO)],
            $total
        );

        $origen_id = $this->post('api/budget', $payload)
                            ->assertStatus(201)
                            ->json('model.id');

        $duplicado_id = $this->post('api/budget/'.$origen_id.'/duplicate')
                            ->assertStatus(201)
                            ->json('model.id');

        $this->assertNotEquals($origen_id, $duplicado_id, 'El duplicado tiene que ser otro presupuesto.');

        $filas = $this->filas_del_pivote($duplicado_id);

        $this->assertCount(1, $filas, 'El duplicado tiene que llevarse el combo.');
        $this->assertEquals($combo->id, (int) $filas->first()->combo_id);
        $this->assertEquals(Self::CANTIDAD_COMBO, (float) $filas->first()->amount);
        $this->assertEquals(Self::PRECIO_COMBO, (float) $filas->first()->price);

        $this->assertCount(
            1,
            $this->filas_del_pivote($origen_id),
            'El origen no se tiene que haber tocado.'
        );
    }

    /**
     * No-regresion: un presupuesto SIN combos se sigue guardando exactamente como antes.
     *
     * Va con un articulo adentro a proposito. El caso sin nada no probaria gran cosa: lo que
     * interesa es que el bucket nuevo de `getTotal()` no le haya cambiado el numero al camino de
     * siempre, que es por donde pasan casi todos los presupuestos.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function un_presupuesto_sin_combos_se_sigue_guardando_igual()
    {
        $this->autenticar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();

        $precio = 120;
        $cantidad = 4;

        $payload = $this->payload_crear($client, [], $precio * $cantidad, [
            'articles' => [[
                'id'                          => $article->id,
                'status'                      => $article->status,
                'cost_in_dollars'             => null,
                'name'                        => $article->name,
                'name_vender_personalizado'   => null,
                'amount'                      => $cantidad,
                'price'                       => $precio,
                'costo_real'                  => 50,
                'unidades_individuales'       => null,
                'presentacion'                => null,
                'price_type_personalizado_id' => null,
                'bonus'                       => null,
                'location'                    => null,
            ]],
        ]);

        $budget_id = $this->post('api/budget', $payload)
                            ->assertStatus(201)
                            ->json('model.id');

        $this->assertCount(
            0,
            $this->filas_del_pivote($budget_id),
            'Un presupuesto sin combos no puede escribir en budget_combo.'
        );

        $budget = Budget::find($budget_id);

        $this->assertEquals($precio * $cantidad, (float) $budget->total);
    }
}
