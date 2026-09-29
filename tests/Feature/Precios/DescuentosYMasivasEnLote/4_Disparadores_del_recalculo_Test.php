<?php

namespace Tests\Feature\Precios\DescuentosYMasivasEnLote;

use App\Jobs\ProcessSetFinalPrices;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\PriceChange;
use App\Models\PriceUpdateRun;
use App\Models\Provider;
use App\Models\ProviderDiscount;
use App\Models\ProviderPriceList;
use App\Models\SubCategory;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;

/**
 * Mision `recalculo-precios-motor-rapido` (28/9/2026) — los DISPARADORES del recalculo de precios.
 *
 * Que guardado despacha un recalculo en segundo plano, con que alcance, y cuales dejaron de
 * despacharlo. Es la mitad de la mision que no es velocidad del motor sino no hacer trabajo al
 * pedo: en Servian, casi todas las corridas de horas eran recalculos que no podian cambiar un solo
 * precio (Rejovot: 3 h 08 min para 21 articulos cambiados de 40.393).
 *
 * 🔴 LO QUE FIJA ESTE ARCHIVO:
 *
 *   1. Un cambio SOLO en los descuentos del proveedor NO encola nada: el precio lee las copias
 *      materializadas en `article_discounts`, no `provider_discounts`.
 *   2. Margen y modalidad "precio desde costo mas IVA" -> todo el proveedor. SOLO el dolar -> la
 *      interseccion con `cost_in_dollars = 1` (`from_dolar = true`). Siempre UNO por guardado.
 *   3. Lista de precios del proveedor -> solo los articulos de esa lista.
 *   4. Categoria/subcategoria -> UN recalculo en segundo plano y NINGUNO en el request.
 *   5. El `user_id` despachado es el del DUEÑO aunque guarde un empleado.
 *
 * La cola va con `Queue::fake()`: aca se mide QUE se despacha, no el recalculo (eso lo cubren los
 * tests del motor). Sin el fake, `phpunit.xml` pone la cola en `sync` y el recalculo correria entero
 * adentro del request, escondiendo justamente lo que el punto 4 tiene que probar.
 *
 * Los numeros son la especificacion. 🔴 Esta prohibido ajustar un valor esperado para que coincida
 * con lo que devuelve el sistema: si un test queda en rojo, se corrige el codigo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni #[...].
 *
 * @group costeo-precios
 */
class Disparadores_del_recalculo_Test extends EmpresaTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /* ==================================================================================
     * FIXTURES
     * ================================================================================== */

    /**
     * @return \App\Models\User
     */
    private function owner()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Proveedor propio de la suite, con margen, dolar y modalidad conocidos.
     *
     * @param  string $sufijo
     * @return \App\Models\Provider
     */
    private function proveedor_de_la_suite($sufijo = '')
    {
        $provider = Provider::create([
            'name'                    => 'zz Proveedor disparadores ' . $sufijo . uniqid(),
            'user_id'                 => $this->owner()->id,
            'percentage_gain'         => 50,
            'dolar'                   => 1000,
            'price_from_cost_mas_iva' => 0,
        ]);

        return $provider->fresh();
    }

    /**
     * Guarda el proveedor por el endpoint real, mandando lo que manda el formulario: todos los campos
     * con su valor actual, pisados por los que el test quiera cambiar.
     *
     * @param  \App\Models\Provider $provider
     * @param  array $cambios
     * @return \Illuminate\Testing\TestResponse
     */
    private function guardar_proveedor($provider, array $cambios = [])
    {
        $provider = $provider->fresh();

        $payload = array_merge([
            'name'                       => $provider->name,
            'phone'                      => $provider->phone,
            'address'                    => $provider->address,
            'email'                      => $provider->email,
            'razon_social'               => $provider->razon_social,
            'cuit'                       => $provider->cuit,
            'observations'               => $provider->observations,
            'location_id'                => $provider->location_id,
            'provincia_id'               => $provider->provincia_id,
            'iva_condition_id'           => $provider->iva_condition_id,
            'percentage_gain'            => $provider->percentage_gain,
            'dolar'                      => $provider->dolar,
            'porcentaje_comision_negro'  => $provider->porcentaje_comision_negro,
            'porcentaje_comision_blanco' => $provider->porcentaje_comision_blanco,
            'price_from_cost_mas_iva'    => $provider->price_from_cost_mas_iva,
        ], $cambios);

        return $this->putJson('api/provider/' . $provider->id, $payload);
    }

    /**
     * Los recalculos que quedaron encolados.
     *
     * @return \Illuminate\Support\Collection
     */
    private function recalculos()
    {
        return Queue::pushed(ProcessSetFinalPrices::class)->values();
    }

    /**
     * Afirma que se encolo EXACTAMENTE un recalculo, con estos argumentos.
     *
     * @param  string $columna
     * @param  int    $model_id
     * @param  bool   $from_dolar
     * @param  string $origen
     * @param  string|null $detalle
     * @param  string $mensaje
     * @return void
     */
    private function assert_un_solo_recalculo($columna, $model_id, $from_dolar, $origen, $detalle, $mensaje)
    {
        $recalculos = $this->recalculos();

        $this->assertCount(1, $recalculos, $mensaje . ' (tiene que encolarse UNO y solo uno)');

        $job = $recalculos->first();

        $this->assertSame((int) $this->owner()->id, (int) $job->user_id, $mensaje . ': el user_id tiene que ser el del dueño.');
        $this->assertSame($columna, $job->from_model_id, $mensaje . ': alcance.');
        $this->assertSame((int) $model_id, (int) $job->model_id, $mensaje . ': id del alcance.');
        $this->assertSame((bool) $from_dolar, (bool) $job->from_dolar, $mensaje . ': from_dolar.');
        $this->assertSame($origen, $job->origen, $mensaje . ': origen.');
        $this->assertSame($detalle, $job->origen_detalle, $mensaje . ': detalle del origen.');
    }

    /**
     * Empleado del comercio. Sin permisos especiales: las rutas de proveedor y categoria solo piden
     * sesion.
     *
     * @return \App\Models\User
     */
    private function empleado()
    {
        return User::create([
            'name'         => 'Empleado disparadores',
            'company_name' => 'Ferreteria disparadores',
            'email'        => 'disparadores-empleado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->owner()->id,
            'admin_access' => 1,
        ]);
    }

    /**
     * Prende la extension de listas por categoria para el dueño (dentro de la transaccion del test).
     *
     * @return void
     */
    private function prender_listas_por_categoria()
    {
        $extencion = ExtencionEmpresa::where('slug', 'lista_de_precios_por_categoria')->first();

        if (is_null($extencion)) {
            $this->markTestSkipped('La base de testing no tiene la extension lista_de_precios_por_categoria sembrada.');
        }

        $this->owner()->extencions()->syncWithoutDetaching([$extencion->id]);
    }

    /**
     * Articulo de la categoria con un `final_price` VIEJO a proposito: si algo lo recalculara en el
     * request, dejaria de ser 1. Es la forma de probar "ninguno sincronico" sin depender de cuanto
     * tendria que valer.
     *
     * @param  array $atributos
     * @return \App\Models\Article
     */
    private function articulo_con_precio_viejo(array $atributos)
    {
        $article = Article::create(array_merge([
            'name'            => 'zz Disparadores articulo ' . uniqid(),
            'user_id'         => $this->owner()->id,
            'cost'            => 1000,
            'percentage_gain' => 50,
        ], $atributos));

        DB::table('articles')->where('id', $article->id)->update(['final_price' => 1]);

        return $article->fresh();
    }

    /**
     * @param  \App\Models\Category $category
     * @param  array $cambios
     * @return \Illuminate\Testing\TestResponse
     */
    private function guardar_categoria($category, array $cambios = [])
    {
        $category = $category->fresh();

        return $this->putJson('api/category/' . $category->id, array_merge([
            'name'                      => $category->name,
            'image_url'                 => $category->image_url,
            'descripcion'               => $category->descripcion,
            'percentage_gain'           => $category->percentage_gain,
            'show_in_pdf_personalizado' => $category->show_in_pdf_personalizado,
            'price_types'               => [],
        ], $cambios));
    }

    /* ==================================================================================
     * 1. PROVEEDOR
     * ================================================================================== */

    /**
     * 🔴 El corazon de la decision 3 del plan: editar o borrar un descuento del proveedor y guardar
     * la ficha NO encola ningun recalculo. Antes lo encolaban dos caminos: un descuento tocado hace
     * menos de 2 minutos (`hubo_cambios_en_provider_discounts()`) y el flag `should_update_prices`
     * que prende el borrado de un descuento. Los dos se ejercitan aca, por los endpoints reales.
     *
     * @test
     */
    public function un_cambio_solo_en_los_descuentos_del_proveedor_no_encola_ningun_recalculo()
    {
        $provider = $this->proveedor_de_la_suite('descuentos');

        $editado = ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 10]);
        $borrado = ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 5]);

        /* Lo que hace la pantalla: edita uno (le renueva el updated_at) y borra otro. */
        $this->putJson('api/provider-discount/' . $editado->id, ['percentage' => 15])->assertStatus(200);
        $this->deleteJson('api/provider-discount/' . $borrado->id)->assertStatus(200);

        $this->assertEquals(1, (int) $provider->fresh()->should_update_prices, 'Precondicion: el borrado prende el flag.');

        /* Y guarda la ficha sin tocar margen, dolar ni modalidad. */
        $this->guardar_proveedor($provider)->assertStatus(200);

        $this->assertCount(
            0,
            $this->recalculos(),
            'Un cambio SOLO de descuentos del proveedor no puede encolar un recalculo: el precio lee '.
            'las copias de article_discounts, asi que ese recalculo no mueve un centavo.'
        );

        $this->assertEquals(
            0,
            (int) $provider->fresh()->should_update_prices,
            'El flag se sigue bajando al guardar, para que no quede prendido para siempre.'
        );
    }

    /**
     * @test
     */
    public function cambiar_el_margen_encola_un_recalculo_de_todo_el_proveedor()
    {
        $provider = $this->proveedor_de_la_suite('margen');

        $this->guardar_proveedor($provider, ['percentage_gain' => 60])->assertStatus(200);

        $this->assert_un_solo_recalculo('provider_id', $provider->id, false, 'proveedor', $provider->name, 'Margen');
    }

    /**
     * El margen que llega como numero y en la base es "50.00" NO es un cambio (comparacion suelta,
     * la misma de siempre).
     *
     * @test
     */
    public function el_mismo_margen_escrito_distinto_no_es_un_cambio()
    {
        $provider = $this->proveedor_de_la_suite('margen igual');

        $this->guardar_proveedor($provider, ['percentage_gain' => 50, 'dolar' => 1000])->assertStatus(200);

        $this->assertCount(0, $this->recalculos());
    }

    /**
     * 🔴 Decision 4 del plan: SOLO el dolar -> `from_dolar = true` sobre el alcance del proveedor, que
     * el productor lee como "solo los articulos del proveedor con cost_in_dollars = 1".
     *
     * @test
     */
    public function cambiar_solo_el_dolar_encola_un_recalculo_de_los_articulos_en_dolares_del_proveedor()
    {
        $provider = $this->proveedor_de_la_suite('dolar');

        $this->guardar_proveedor($provider, ['dolar' => 1250])->assertStatus(200);

        $this->assert_un_solo_recalculo('provider_id', $provider->id, true, 'proveedor', $provider->name, 'Solo dolar');
    }

    /**
     * Nuevo disparador: cambiar la modalidad "precio desde costo mas IVA" cambia el precio de todo el
     * proveedor y hasta hoy no recalculaba nada.
     *
     * @test
     */
    public function cambiar_la_modalidad_precio_desde_costo_mas_iva_encola_un_recalculo_de_todo_el_proveedor()
    {
        $provider = $this->proveedor_de_la_suite('modalidad');

        $this->guardar_proveedor($provider, ['price_from_cost_mas_iva' => 1])->assertStatus(200);

        $this->assert_un_solo_recalculo('provider_id', $provider->id, false, 'proveedor', $provider->name, 'Modalidad');
    }

    /**
     * La modalidad es un tilde: `false`, `0` y `'0'` son todos "apagado" contra el 0 de la base.
     * (null no se prueba: la columna es NOT NULL y el formulario nunca lo manda.)
     *
     * @test
     */
    public function la_modalidad_apagada_escrita_distinto_no_es_un_cambio()
    {
        $provider = $this->proveedor_de_la_suite('modalidad igual');

        foreach ([false, 0, '0'] as $apagada) {
            $this->guardar_proveedor($provider, ['price_from_cost_mas_iva' => $apagada])->assertStatus(200);
        }

        $this->assertCount(0, $this->recalculos());
    }

    /**
     * Margen y dolar en el mismo guardado: UN recalculo de todo el proveedor, que ya incluye a los
     * articulos en dolares. Nunca dos.
     *
     * @test
     */
    public function margen_y_dolar_juntos_encolan_un_solo_recalculo_de_todo_el_proveedor()
    {
        $provider = $this->proveedor_de_la_suite('margen y dolar');

        $this->guardar_proveedor($provider, ['percentage_gain' => 70, 'dolar' => 1300])->assertStatus(200);

        $this->assert_un_solo_recalculo('provider_id', $provider->id, false, 'proveedor', $provider->name, 'Margen y dolar');
    }

    /**
     * 🔴 El user_id despachado es el del DUEÑO aunque guarde un empleado: el productor filtra los
     * articulos por user_id, y con el del empleado no encontraria ninguno.
     *
     * @test
     */
    public function si_guarda_un_empleado_el_recalculo_se_despacha_con_el_dueno()
    {
        $provider = $this->proveedor_de_la_suite('empleado');

        $this->actingAs($this->empleado(), 'web');

        $this->guardar_proveedor($provider, ['percentage_gain' => 65])->assertStatus(200);

        $this->assert_un_solo_recalculo('provider_id', $provider->id, false, 'proveedor', $provider->name, 'Empleado');
    }

    /* ==================================================================================
     * 2. LISTA DE PRECIOS DEL PROVEEDOR
     * ================================================================================== */

    /**
     * Decision 5 del plan: el porcentaje de una lista del proveedor solo lo leen los articulos que
     * tienen esa lista. El recalculo va acotado a `provider_price_list_id`, con el nombre del
     * proveedor como detalle. Guardar la lista sin cambiar el porcentaje no encola nada.
     *
     * @test
     */
    public function cambiar_el_porcentaje_de_una_lista_del_proveedor_encola_un_recalculo_de_esa_lista()
    {
        $provider = $this->proveedor_de_la_suite('lista');

        $lista = ProviderPriceList::create([
            'name'        => 'zz Lista disparadores',
            'percentage'  => 10,
            'provider_id' => $provider->id,
        ]);

        /* Mismo porcentaje, escrito distinto ("10.00" en la base): no es un cambio. */
        $this->putJson('api/provider-price-list/' . $lista->id, [
            'name'        => 'zz Lista disparadores renombrada',
            'percentage'  => 10,
            'provider_id' => $provider->id,
        ])->assertStatus(200);

        $this->assertCount(0, $this->recalculos(), 'Renombrar la lista no mueve precios.');

        $this->putJson('api/provider-price-list/' . $lista->id, [
            'name'        => 'zz Lista disparadores renombrada',
            'percentage'  => 12,
            'provider_id' => $provider->id,
        ])->assertStatus(200);

        $this->assert_un_solo_recalculo(
            'provider_price_list_id',
            $lista->id,
            false,
            'proveedor',
            $provider->name,
            'Lista del proveedor'
        );
    }

    /* ==================================================================================
     * 3. CATEGORIA Y SUBCATEGORIA
     * ================================================================================== */

    /**
     * 🔴 Decision 6 del plan: con la extension de listas por categoria, guardar la categoria encola
     * UN recalculo acotado a la categoria y NO recalcula nada en el request — aunque ademas haya
     * cambiado el margen, que antes sumaba un SEGUNDO recalculo sincronico.
     *
     * @test
     */
    public function guardar_una_categoria_con_listas_por_categoria_encola_un_solo_recalculo_y_ninguno_en_el_request()
    {
        $this->prender_listas_por_categoria();

        $category = Category::create([
            'name'            => 'zz Categoria disparadores ' . uniqid(),
            'user_id'         => $this->owner()->id,
            'percentage_gain' => 10,
        ]);

        $article = $this->articulo_con_precio_viejo(['category_id' => $category->id]);

        $cambios_de_precio_antes = PriceChange::where('article_id', $article->id)->count();

        /* Las dos condiciones a la vez: extension prendida Y margen cambiado. */
        $this->guardar_categoria($category, ['percentage_gain' => 20])->assertStatus(200);

        $this->assert_un_solo_recalculo('category_id', $category->id, false, 'categoria', $category->name, 'Categoria');

        $this->assertEquals(
            1,
            (float) $article->fresh()->final_price,
            'Guardar la categoria no puede recalcular en el request: el precio tenia que quedar como estaba '.
            'hasta que corra el recalculo en segundo plano.'
        );

        $this->assertEquals($cambios_de_precio_antes, PriceChange::where('article_id', $article->id)->count());

        /*
         * Y el registro visible nace en el momento, con el origen nuevo: es lo que el usuario ve en la
         * pildora ("Se recalcularon por un cambio en una categoría · <nombre>").
         */
        $proceso = BackgroundProcess::where('user_id', $this->owner()->id)
                                        ->where('tipo', 'recalculo_precios')
                                        ->orderBy('id', 'DESC')
                                        ->first();

        $this->assertNotNull($proceso);
        $this->assertSame('Se recalcularon por un cambio en una categoría · ' . $category->name, $proceso->detalle);
    }

    /**
     * Sin la extension, cambiar el margen de la categoria tambien encola UNO y no recalcula en el
     * request. Hasta hoy lo recalculaba sincronico (`check_percetange_gain()`).
     *
     * @test
     */
    public function cambiar_el_margen_de_una_categoria_sin_la_extension_encola_un_recalculo_y_ninguno_en_el_request()
    {
        $category = Category::create([
            'name'            => 'zz Categoria margen ' . uniqid(),
            'user_id'         => $this->owner()->id,
            'percentage_gain' => 10,
        ]);

        $article = $this->articulo_con_precio_viejo(['category_id' => $category->id]);

        $this->guardar_categoria($category, ['percentage_gain' => 25])->assertStatus(200);

        $this->assert_un_solo_recalculo('category_id', $category->id, false, 'categoria', $category->name, 'Margen de categoria');

        $this->assertEquals(1, (float) $article->fresh()->final_price, 'Nada sincronico en el request.');
    }

    /**
     * Sin la extension y sin cambiar el margen (un renombre), guardar la categoria no mueve precios y
     * no encola nada.
     *
     * @test
     */
    public function renombrar_una_categoria_sin_la_extension_no_encola_nada()
    {
        $category = Category::create([
            'name'            => 'zz Categoria renombre ' . uniqid(),
            'user_id'         => $this->owner()->id,
            'percentage_gain' => 10,
        ]);

        $this->guardar_categoria($category, ['name' => 'zz Categoria renombrada ' . uniqid(), 'percentage_gain' => '10.00'])
             ->assertStatus(200);

        $this->assertCount(0, $this->recalculos());
    }

    /**
     * La subcategoria no tiene margen propio: con la extension, UN recalculo acotado a la
     * subcategoria; sin la extension, nada.
     *
     * @test
     */
    public function guardar_una_subcategoria_encola_un_recalculo_solo_con_la_extension()
    {
        $category = Category::create([
            'name'    => 'zz Categoria de la sub ' . uniqid(),
            'user_id' => $this->owner()->id,
        ]);

        $sub_category = SubCategory::create([
            'name'        => 'zz Subcategoria disparadores ' . uniqid(),
            'category_id' => $category->id,
            'user_id'     => $this->owner()->id,
        ]);

        $article = $this->articulo_con_precio_viejo([
            'category_id'     => $category->id,
            'sub_category_id' => $sub_category->id,
        ]);

        $payload = [
            'name'           => $sub_category->name,
            'category_id'    => $category->id,
            'show_in_vender' => 1,
            'image_url'      => null,
            'price_types'    => [],
        ];

        $this->putJson('api/sub-category/' . $sub_category->id, $payload)->assertStatus(200);

        $this->assertCount(0, $this->recalculos(), 'Sin la extension, guardar una subcategoria no mueve precios.');

        $this->prender_listas_por_categoria();

        $this->putJson('api/sub-category/' . $sub_category->id, $payload)->assertStatus(200);

        $this->assert_un_solo_recalculo(
            'sub_category_id',
            $sub_category->id,
            false,
            'categoria',
            $sub_category->name,
            'Subcategoria'
        );

        $this->assertEquals(1, (float) $article->fresh()->final_price, 'Nada sincronico en el request.');
    }

    /**
     * El empleado que guarda una categoria tambien despacha con el dueño.
     *
     * @test
     */
    public function si_un_empleado_guarda_la_categoria_el_recalculo_se_despacha_con_el_dueno()
    {
        $category = Category::create([
            'name'            => 'zz Categoria empleado ' . uniqid(),
            'user_id'         => $this->owner()->id,
            'percentage_gain' => 10,
        ]);

        $this->actingAs($this->empleado(), 'web');

        $this->guardar_categoria($category, ['percentage_gain' => 30])->assertStatus(200);

        $this->assert_un_solo_recalculo('category_id', $category->id, false, 'categoria', $category->name, 'Categoria por empleado');
    }

    /**
     * @test
     */
    public function el_origen_categoria_tiene_su_texto()
    {
        $run = new PriceUpdateRun(['origen' => 'categoria']);

        $this->assertSame('Se recalcularon por un cambio en una categoría', $run->origen_texto);
    }
}
