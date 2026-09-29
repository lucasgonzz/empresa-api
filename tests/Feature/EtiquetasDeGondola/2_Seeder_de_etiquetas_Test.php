<?php

namespace Tests\Feature\EtiquetasDeGondola;

use App\Http\Controllers\Helpers\ArticleTicketDesignHelper;
use App\Models\ArticleTicketDesign;
use Illuminate\Support\Facades\Artisan;

/**
 * `ArticleTicketDesignSeeder` y el alta automática al crear una lista de precios (misión
 * disenos-etiquetas-gondola, 29/9/2026): nadie pierde la opción que hoy le aparece en el menú de
 * etiquetas del listado, y correrlo dos veces no duplica nada.
 *
 * @group etiquetas_de_gondola
 */
class Seeder_de_etiquetas_Test extends EtiquetasDeGondolaTestCase
{
    /**
     * @param  \App\Models\User  $dueno
     * @return \Illuminate\Support\Collection
     */
    protected function disenos_de($dueno)
    {
        return ArticleTicketDesign::where('user_id', $dueno->id)->orderBy('position')->orderBy('id')->get();
    }

    /**
     * @return void
     */
    protected function correr_seeder()
    {
        Artisan::call('db:seed', array('--class' => 'ArticleTicketDesignSeeder', '--force' => true));
    }

    /** @test */
    public function un_dueno_con_listas_recibe_un_diseno_por_lista_y_la_segunda_corrida_no_duplica()
    {
        $dueno = $this->crear_dueno(true);
        $empleado = $this->crear_empleado($dueno);

        $minorista = $this->crear_lista($dueno, 'Minorista', 2);
        $mayorista = $this->crear_lista($dueno, 'Mayorista', 1);

        $this->correr_seeder();
        $this->correr_seeder();

        $disenos = $this->disenos_de($dueno);

        $this->assertCount(2, $disenos);

        $this->assertSame('Mayorista', $disenos[0]->name);
        $this->assertSame($mayorista->id, $disenos[0]->price_type_id);
        $this->assertSame(1, $disenos[0]->position);
        $this->assertEquals(ArticleTicketDesignHelper::diseno_actual($mayorista->id), $disenos[0]->diseno);

        $this->assertSame('Minorista', $disenos[1]->name);
        $this->assertSame($minorista->id, $disenos[1]->price_type_id);
        $this->assertSame('precio_lista', $disenos[1]->diseno['elementos'][0]['tipo']);
        $this->assertSame($minorista->id, $disenos[1]->diseno['elementos'][0]['price_type_id']);

        $this->assertSame(0, ArticleTicketDesign::where('user_id', $empleado->id)->count());
    }

    /** @test */
    public function un_dueno_sin_listas_recibe_un_solo_diseno_generico()
    {
        $dueno = $this->crear_dueno(false);

        /* Tiene listas cargadas pero no trabaja con listas: igual es el genérico. */
        $this->crear_lista($dueno, 'Mayorista', 1);

        $this->correr_seeder();
        $this->correr_seeder();

        $disenos = $this->disenos_de($dueno);

        $this->assertCount(1, $disenos);
        $this->assertSame(ArticleTicketDesignHelper::NOMBRE_GENERICO, $disenos[0]->name);
        $this->assertNull($disenos[0]->price_type_id);
        $this->assertEquals(ArticleTicketDesignHelper::diseno_actual(null), $disenos[0]->diseno);
    }

    /** @test */
    public function un_dueno_con_listas_prendidas_pero_sin_ninguna_lista_recibe_el_generico()
    {
        $dueno = $this->crear_dueno(true);

        $this->assertSame(1, ArticleTicketDesignHelper::crear_disenos_del_sistema($dueno->id));
        $this->assertSame(0, ArticleTicketDesignHelper::crear_disenos_del_sistema($dueno->id));

        $disenos = $this->disenos_de($dueno);

        $this->assertCount(1, $disenos);
        $this->assertSame('precio_final', $disenos[0]->diseno['elementos'][0]['tipo']);
    }

    /** @test */
    public function el_generico_no_se_crea_si_el_dueno_ya_tiene_disenos()
    {
        $dueno = $this->crear_dueno(false);

        $this->crear_diseno($dueno, 'Propio', ArticleTicketDesignHelper::diseno_actual());

        $this->assertSame(0, ArticleTicketDesignHelper::crear_disenos_del_sistema($dueno->id));
        $this->assertCount(1, $this->disenos_de($dueno));
    }

    /** @test */
    public function a_un_empleado_no_se_le_crea_nada()
    {
        $dueno = $this->crear_dueno(false);
        $empleado = $this->crear_empleado($dueno);

        $this->assertSame(0, ArticleTicketDesignHelper::crear_disenos_del_sistema($empleado->id));
        $this->assertSame(0, ArticleTicketDesign::where('user_id', $empleado->id)->count());
    }

    /** @test */
    public function crear_una_lista_por_la_api_crea_su_diseno_una_sola_vez()
    {
        $dueno = $this->crear_dueno(true);

        $this->actingAs($dueno, 'web');

        $respuesta = $this->postJson('api/price-type', array(
            'name'       => 'Revendedor',
            'percentage' => 20,
            'position'   => 4,
            /* Lo que manda el ABM de listas del SPA: attachModels() recorre estos dos. */
            'categories'     => array(),
            'sub_categories' => array(),
            'childrens'      => array(),
        ));

        $respuesta->assertStatus(201);

        $lista_id = (int) $respuesta->json('model.id');

        $disenos = $this->disenos_de($dueno);

        $this->assertCount(1, $disenos);
        $this->assertSame('Revendedor', $disenos[0]->name);
        $this->assertSame($lista_id, $disenos[0]->price_type_id);
        $this->assertSame(4, $disenos[0]->position);
        $this->assertEquals(ArticleTicketDesignHelper::diseno_actual($lista_id), $disenos[0]->diseno);

        /* Ni el helper de nuevo ni el seeder lo duplican. */
        $lista = \App\Models\PriceType::find($lista_id);
        /* crear_diseno_de_lista() devuelve el diseño creado, o null si no hacía falta. */
        $this->assertNull(ArticleTicketDesignHelper::crear_diseno_de_lista($lista));
        $this->correr_seeder();

        $this->assertCount(1, $this->disenos_de($dueno));
    }

    /**
     * La importación de clientes (`ClientImport` -> `LocalImportHelper::savePriceType()`) y la demo
     * crean listas con `PriceType::create()` directo, sin pasar por el controller: el diseño lo
     * tiene que crear el observer del modelo.
     *
     * @test
     */
    public function una_lista_creada_por_fuera_del_controller_recibe_su_diseno()
    {
        $dueno = $this->crear_dueno(true);

        $lista = $this->crear_lista($dueno, 'Importada', 3);

        $disenos = $this->disenos_de($dueno);

        $this->assertCount(1, $disenos);
        $this->assertSame('Importada', $disenos[0]->name);
        $this->assertSame($lista->id, $disenos[0]->price_type_id);
        $this->assertSame(3, $disenos[0]->position);
        $this->assertEquals(ArticleTicketDesignHelper::diseno_actual($lista->id), $disenos[0]->diseno);

        /* Sin listas prendidas, crear una lista no crea diseño. */
        $sin_listas = $this->crear_dueno(false);
        $this->crear_lista($sin_listas, 'Suelta', 1);
        $this->assertCount(0, $this->disenos_de($sin_listas));
    }

    /**
     * Una lista de un dueño que no existe (dato roto de una importación) se crea igual: el
     * observer no puede romper el alta.
     *
     * @test
     */
    public function el_observer_no_rompe_el_alta_de_una_lista_huerfana()
    {
        $lista = \App\Models\PriceType::create(array(
            'num' => 1, 'name' => 'Huérfana', 'percentage' => 10, 'position' => 1, 'user_id' => 999999999,
        ));

        $this->assertNotNull(\App\Models\PriceType::find($lista->id));
        $this->assertSame(0, ArticleTicketDesign::where('price_type_id', $lista->id)->count());
    }

    /**
     * Una `position` enorme en la lista (la columna del diseño es int) se acota.
     *
     * @test
     */
    public function la_posicion_de_una_lista_enorme_se_acota_en_su_diseno()
    {
        $dueno = $this->crear_dueno(true);

        $lista = $this->crear_lista($dueno, 'Lejos', 1);
        ArticleTicketDesign::where('price_type_id', $lista->id)->delete();

        $lista->position = 5000000;
        $this->assertNotNull(ArticleTicketDesignHelper::crear_diseno_de_lista($lista));

        $this->assertSame(ArticleTicketDesignHelper::POSICION_MAXIMA, $this->disenos_de($dueno)[0]->position);
    }

    /**
     * 🔴 Un dueño con función de impresión propia (golonorte) no recibe diseños del sistema, ni
     * del seeder ni al crear una lista: su menú de etiquetas queda exactamente como hoy.
     *
     * @test
     */
    public function un_dueno_con_funcion_de_impresion_propia_no_recibe_disenos()
    {
        $con_listas = $this->crear_dueno(true);
        $sin_listas = $this->crear_dueno(false);

        foreach (array($con_listas, $sin_listas) as $dueno) {
            $dueno->article_ticket_print_function = 'golonorte';
            $dueno->save();
        }

        $lista = $this->crear_lista($con_listas, 'Mayorista', 1);

        $this->correr_seeder();

        $this->assertCount(0, $this->disenos_de($con_listas));
        $this->assertCount(0, $this->disenos_de($sin_listas));
        $this->assertNull(ArticleTicketDesignHelper::crear_diseno_de_lista($lista));
        $this->assertSame(0, ArticleTicketDesignHelper::crear_disenos_del_sistema($sin_listas->id));
    }

    /**
     * Lista borrada -> se borra el diseño que el sistema le generó; los del usuario quedan.
     *
     * @test
     */
    public function borrar_una_lista_borra_su_diseno_y_no_los_del_usuario()
    {
        $dueno = $this->crear_dueno(true);

        $lista = $this->crear_lista($dueno, 'Mayorista', 1);
        $otra = $this->crear_lista($dueno, 'Minorista', 2);
        $del_usuario = $this->crear_diseno($dueno, 'Mío', ArticleTicketDesignHelper::diseno_actual($lista->id));

        $this->assertCount(3, $this->disenos_de($dueno));

        $lista->delete();

        $quedan = $this->disenos_de($dueno);

        $this->assertSame(0, ArticleTicketDesign::where('price_type_id', $lista->id)->count());
        $esperados = array($del_usuario->id, ArticleTicketDesign::where('price_type_id', $otra->id)->value('id'));
        sort($esperados);

        $this->assertSame($esperados, $quedan->pluck('id')->sort()->values()->all());
    }

    /**
     * Borrar la lista por la API (`PriceTypeController@destroy`) también se lleva su diseño.
     *
     * @test
     */
    public function borrar_una_lista_por_la_api_borra_su_diseno()
    {
        $dueno = $this->crear_dueno(true);
        $lista = $this->crear_lista($dueno, 'Mayorista', 1);

        $this->actingAs($dueno, 'web');

        $this->deleteJson('api/price-type/'.$lista->id)->assertStatus(200);

        $this->assertCount(0, $this->disenos_de($dueno));
    }

    /**
     * Lista renombrada -> su diseño toma el nombre nuevo, salvo que el usuario ya lo haya
     * renombrado a mano.
     *
     * @test
     */
    public function renombrar_una_lista_renombra_su_diseno_si_no_lo_cambio_el_usuario()
    {
        $dueno = $this->crear_dueno(true);

        $lista = $this->crear_lista($dueno, 'Mayorista', 1);
        $otra = $this->crear_lista($dueno, 'Minorista', 2);

        ArticleTicketDesign::where('price_type_id', $otra->id)->update(array('name' => 'Mi etiqueta'));

        $lista->name = 'Mayorista 2026';
        $lista->save();

        $otra->name = 'Minorista 2026';
        $otra->save();

        /* Cambiar otra cosa que no es el nombre no toca nada. */
        $lista->position = 5;
        $lista->save();

        $this->assertSame('Mayorista 2026', ArticleTicketDesign::where('price_type_id', $lista->id)->value('name'));
        $this->assertSame('Mi etiqueta', ArticleTicketDesign::where('price_type_id', $otra->id)->value('name'));
    }

    /** @test */
    public function crear_una_lista_sin_trabajar_con_listas_no_crea_diseno()
    {
        $dueno = $this->crear_dueno(false);

        $this->actingAs($dueno, 'web');

        $this->postJson('api/price-type', array(
            'name' => 'Suelta', 'percentage' => 5, 'position' => 1,
            'categories' => array(), 'sub_categories' => array(), 'childrens' => array(),
        ))
             ->assertStatus(201);

        $this->assertCount(0, $this->disenos_de($dueno));
    }
}
