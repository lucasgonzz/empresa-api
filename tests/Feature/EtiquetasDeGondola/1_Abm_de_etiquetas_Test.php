<?php

namespace Tests\Feature\EtiquetasDeGondola;

use App\Http\Controllers\Helpers\ArticleTicketDesignHelper;
use App\Models\ArticleTicketDesign;

/**
 * ABM `api/article-ticket-design` (misión disenos-etiquetas-gondola, 29/9/2026): el scoping por
 * dueño, el PUT parcial y la normalización del JSON del diseño.
 *
 * @group etiquetas_de_gondola
 */
class Abm_de_etiquetas_Test extends EtiquetasDeGondolaTestCase
{
    /** @test */
    public function el_index_trae_solo_los_del_dueno_ordenados_por_posicion_y_id()
    {
        $dueno = $this->crear_dueno();
        $otro = $this->crear_dueno();

        $b = $this->crear_diseno($dueno, 'B', ArticleTicketDesignHelper::diseno_actual(), 2);
        $a = $this->crear_diseno($dueno, 'A', ArticleTicketDesignHelper::diseno_actual(), 1);
        $c = $this->crear_diseno($dueno, 'C', ArticleTicketDesignHelper::diseno_actual(), 2);
        $this->crear_diseno($otro, 'Ajeno', ArticleTicketDesignHelper::diseno_actual(), 0);

        $this->actingAs($dueno, 'web');

        $respuesta = $this->getJson('api/article-ticket-design');

        $respuesta->assertStatus(200);

        $this->assertSame(array($a->id, $b->id, $c->id), array_column($respuesta->json('models'), 'id'));

        $modelo = $respuesta->json('models.0');
        $this->assertIsArray($modelo['diseno']);
        $this->assertSame(3, $modelo['diseno']['columnas']);
        $this->assertArrayHasKey('price_type_id', $modelo);
        $this->assertArrayHasKey('position', $modelo);
    }

    /** @test */
    public function un_empleado_ve_y_crea_los_disenos_del_dueno()
    {
        $dueno = $this->crear_dueno();
        $empleado = $this->crear_empleado($dueno);

        $del_dueno = $this->crear_diseno($dueno, 'Del dueño', ArticleTicketDesignHelper::diseno_actual());

        $this->actingAs($empleado, 'web');

        $index = $this->getJson('api/article-ticket-design');
        $index->assertStatus(200);
        $this->assertSame(array($del_dueno->id), array_column($index->json('models'), 'id'));

        $creado = $this->postJson('api/article-ticket-design', array('name' => 'Del empleado'));
        $creado->assertStatus(201);
        $this->assertSame($dueno->id, (int) $creado->json('model.user_id'));
    }

    /** @test */
    public function un_empleado_edita_y_borra_los_disenos_del_dueno()
    {
        $dueno = $this->crear_dueno();
        $empleado = $this->crear_empleado($dueno);

        $editable = $this->crear_diseno($dueno, 'Del dueño', ArticleTicketDesignHelper::diseno_actual());
        $borrable = $this->crear_diseno($dueno, 'Borrable', ArticleTicketDesignHelper::diseno_actual());

        $this->actingAs($empleado, 'web');

        $respuesta = $this->putJson('api/article-ticket-design/'.$editable->id, array('name' => 'Editado por el empleado'));
        $respuesta->assertStatus(200);
        $this->assertSame('Editado por el empleado', ArticleTicketDesign::find($editable->id)->name);
        $this->assertSame($dueno->id, (int) ArticleTicketDesign::find($editable->id)->user_id);

        $this->deleteJson('api/article-ticket-design/'.$borrable->id)->assertStatus(200);
        $this->assertNull(ArticleTicketDesign::find($borrable->id));
    }

    /** @test */
    public function la_posicion_se_acota_entre_0_y_un_millon()
    {
        $dueno = $this->crear_dueno();
        $this->actingAs($dueno, 'web');

        $enorme = $this->postJson('api/article-ticket-design', array('name' => 'Enorme', 'position' => 1e30));
        $enorme->assertStatus(201);
        $this->assertSame(1000000, $enorme->json('model.position'));

        $negativa = $this->postJson('api/article-ticket-design', array('name' => 'Negativa', 'position' => -5));
        $negativa->assertStatus(201);
        $this->assertSame(0, $negativa->json('model.position'));

        $id = $negativa->json('model.id');

        $this->putJson('api/article-ticket-design/'.$id, array('position' => '99999999999999999999'))->assertStatus(200);
        $this->assertSame(1000000, ArticleTicketDesign::find($id)->position);

        $this->putJson('api/article-ticket-design/'.$id, array('position' => -1))->assertStatus(200);
        $this->assertSame(0, ArticleTicketDesign::find($id)->position);
    }

    /** @test */
    public function crear_sin_diseno_arranca_con_el_de_siempre_con_precio_final()
    {
        $dueno = $this->crear_dueno();
        $this->actingAs($dueno, 'web');

        $respuesta = $this->postJson('api/article-ticket-design', array('name' => '  Mostrador  '));

        $respuesta->assertStatus(201);
        $this->assertSame('Mostrador', $respuesta->json('model.name'));
        $this->assertNull($respuesta->json('model.price_type_id'));
        $this->assertEquals(ArticleTicketDesignHelper::diseno_actual(null), $respuesta->json('model.diseno'));
        $this->assertSame('precio_final', $respuesta->json('model.diseno.elementos.0.tipo'));

        /* El segundo va detrás del primero. */
        $segundo = $this->postJson('api/article-ticket-design', array('name' => 'Otro'));
        $this->assertGreaterThan($respuesta->json('model.position'), $segundo->json('model.position'));
    }

    /** @test */
    public function el_nombre_es_obligatorio_y_de_hasta_120_caracteres()
    {
        $dueno = $this->crear_dueno();
        $this->actingAs($dueno, 'web');

        $this->postJson('api/article-ticket-design', array())->assertStatus(422);
        $this->postJson('api/article-ticket-design', array('name' => '   '))->assertStatus(422);
        $this->postJson('api/article-ticket-design', array('name' => str_repeat('a', 121)))->assertStatus(422);
        $this->postJson('api/article-ticket-design', array('name' => str_repeat('ñ', 120)))->assertStatus(201);

        $diseno = $this->crear_diseno($dueno, 'Con nombre', ArticleTicketDesignHelper::diseno_actual());

        $vacio = $this->putJson('api/article-ticket-design/'.$diseno->id, array('name' => ''));
        $vacio->assertStatus(422);
        $this->assertNotEmpty($vacio->json('message'));

        $this->assertSame('Con nombre', ArticleTicketDesign::find($diseno->id)->name);
    }

    /** @test */
    public function un_diseno_que_no_es_un_objeto_es_422()
    {
        $dueno = $this->crear_dueno();
        $this->actingAs($dueno, 'web');

        $this->postJson('api/article-ticket-design', array('name' => 'X', 'diseno' => 'hola'))->assertStatus(422);

        $diseno = $this->crear_diseno($dueno, 'X', ArticleTicketDesignHelper::diseno_actual());
        $this->putJson('api/article-ticket-design/'.$diseno->id, array('diseno' => 5))->assertStatus(422);
    }

    /** @test */
    public function el_put_es_parcial()
    {
        $dueno = $this->crear_dueno();
        $this->actingAs($dueno, 'web');

        $original = ArticleTicketDesignHelper::diseno_actual();
        $diseno = $this->crear_diseno($dueno, 'Viejo', $original, 3);

        $respuesta = $this->putJson('api/article-ticket-design/'.$diseno->id, array('name' => 'Nuevo'));

        $respuesta->assertStatus(200);
        $this->assertSame('Nuevo', $respuesta->json('model.name'));
        $this->assertEquals($original, $respuesta->json('model.diseno'));
        $this->assertSame(3, $respuesta->json('model.position'));

        $otro_diseno = $original;
        $otro_diseno['columnas'] = 2;

        $respuesta = $this->putJson('api/article-ticket-design/'.$diseno->id, array('diseno' => $otro_diseno, 'position' => 7));

        $respuesta->assertStatus(200);
        $this->assertSame('Nuevo', $respuesta->json('model.name'));
        $this->assertSame(2, $respuesta->json('model.diseno.columnas'));
        $this->assertSame(7, $respuesta->json('model.position'));

        /* null explícito vuelve al diseño de siempre. */
        $respuesta = $this->putJson('api/article-ticket-design/'.$diseno->id, array('diseno' => null));
        $this->assertEquals($original, $respuesta->json('model.diseno'));
    }

    /** @test */
    public function un_diseno_ajeno_da_404_en_show_put_y_delete()
    {
        $dueno = $this->crear_dueno();
        $otro = $this->crear_dueno();

        $ajeno = $this->crear_diseno($otro, 'Ajeno', ArticleTicketDesignHelper::diseno_actual());

        $this->actingAs($dueno, 'web');

        $this->getJson('api/article-ticket-design/'.$ajeno->id)->assertStatus(404);
        $this->putJson('api/article-ticket-design/'.$ajeno->id, array('name' => 'Pisado'))->assertStatus(404);
        $this->deleteJson('api/article-ticket-design/'.$ajeno->id)->assertStatus(404);
        $this->getJson('api/article-ticket-design/999999999')->assertStatus(404);

        $this->assertSame('Ajeno', ArticleTicketDesign::find($ajeno->id)->name);
    }

    /** @test */
    public function se_puede_borrar_un_diseno_propio()
    {
        $dueno = $this->crear_dueno();
        $this->actingAs($dueno, 'web');

        $diseno = $this->crear_diseno($dueno, 'Borrable', ArticleTicketDesignHelper::diseno_actual());

        $this->deleteJson('api/article-ticket-design/'.$diseno->id)->assertStatus(200);

        $this->assertNull(ArticleTicketDesign::find($diseno->id));
    }

    /** @test */
    public function la_normalizacion_acota_recorta_y_descarta()
    {
        $dueno = $this->crear_dueno(true);
        $otro = $this->crear_dueno(true);

        $lista_propia = $this->crear_lista($dueno, 'Mayorista', 1);
        $lista_ajena = $this->crear_lista($otro, 'Ajena', 1);

        $elementos = array(
            /* Tipo desconocido y costos: afuera. */
            $this->elemento('costo'),
            array('tipo' => 'nombre', 'x' => -5, 'y' => 'abc', 'w' => 500, 'h' => 1, 'tamano' => 999, 'alineacion' => 'Z', 'id' => 'repetido', 'extra' => 'se ignora'),
            /* id repetido e id inválido: se regeneran. */
            $this->elemento('marca', array('id' => 'repetido')),
            $this->elemento('categoria', array('id' => "Mayúsculas\n")),
            /* Se sale por la derecha y por abajo: se corre hacia adentro. */
            $this->elemento('proveedor', array('x' => 45, 'y' => 18, 'w' => 20, 'h' => 5)),
            /* Pegado al borde y de menos de 2 mm. */
            $this->elemento('stock', array('x' => 49.9, 'y' => 30, 'w' => 1, 'h' => 0)),
            $this->elemento('precio_lista', array('price_type_id' => $lista_propia->id, 'rotulo' => 'true')),
            $this->elemento('precio_lista', array('price_type_id' => $lista_ajena->id)),
            $this->elemento('precio_lista', array()),
            $this->elemento('texto_fijo', array('texto' => '  '.str_repeat('x', 300).'  ', 'tamano' => 1)),
        );

        $this->actingAs($dueno, 'web');

        $respuesta = $this->postJson('api/article-ticket-design', array(
            'name'   => 'Normalizado',
            'diseno' => array(
                'version'   => 99,
                'columnas'  => 9,
                'filas'     => 0,
                'alto_mm'   => 22.456,
                'marco'     => 'false',
                'elementos' => $elementos,
                'otra_cosa' => array(1, 2, 3),
            ),
        ));

        $respuesta->assertStatus(201);

        $diseno = $respuesta->json('model.diseno');

        $this->assertSame(array('version', 'columnas', 'filas', 'alto_mm', 'marco', 'elementos'), array_keys($diseno));
        $this->assertSame(1, $diseno['version']);
        $this->assertSame(4, $diseno['columnas']);
        $this->assertSame(1, $diseno['filas']);
        $this->assertEquals(22.5, $diseno['alto_mm']);
        $this->assertFalse($diseno['marco']);

        $tipos = array_column($diseno['elementos'], 'tipo');
        $this->assertSame(array('nombre', 'marca', 'categoria', 'proveedor', 'stock', 'precio_lista', 'texto_fijo'), $tipos);

        list($nombre, $marca, $categoria, $proveedor, $stock, $precio_lista, $texto_fijo) = $diseno['elementos'];

        /* 4 columnas -> etiqueta de 50 mm de ancho; alto 22,5. */
        $this->assertEquals(0, $nombre['x']);
        $this->assertEquals(0, $nombre['y']);
        $this->assertEquals(50, $nombre['w']);
        $this->assertEquals(2, $nombre['h']);
        $this->assertEquals(120, $nombre['tamano']);
        $this->assertSame('L', $nombre['alineacion']);
        $this->assertArrayNotHasKey('extra', $nombre);
        $this->assertArrayNotHasKey('rotulo', $nombre);
        $this->assertArrayNotHasKey('price_type_id', $nombre);

        $ids = array_column($diseno['elementos'], 'id');
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame('repetido', $nombre['id']);
        $this->assertNotSame('repetido', $marca['id']);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,40}$/', $categoria['id']);

        /*
         * Se sale por la derecha y por abajo: se CORRE hacia adentro conservando su tamaño
         * (x = 50 - 20, y = 22,5 - 5), igual que encerrar_en_la_etiqueta del editor del SPA.
         */
        $this->assertEquals(30, $proveedor['x']);
        $this->assertEquals(20, $proveedor['w']);
        $this->assertEquals(17.5, $proveedor['y']);
        $this->assertEquals(5, $proveedor['h']);

        /* Pegado al borde y de menos de 2 mm: queda de 2 mm, corrido hacia adentro. */
        $this->assertEquals(48, $stock['x']);
        $this->assertEquals(2, $stock['w']);
        $this->assertEquals(20.5, $stock['y']);
        $this->assertEquals(2, $stock['h']);

        $this->assertSame($lista_propia->id, $precio_lista['price_type_id']);
        $this->assertTrue($precio_lista['rotulo']);

        $this->assertSame(200, mb_strlen($texto_fijo['texto']));
        $this->assertEquals(5, $texto_fijo['tamano']);
    }

    /**
     * Un campo más grande que la etiqueta se achica a la etiqueta entera y queda en 0.
     *
     * @test
     */
    public function un_campo_mas_grande_que_la_etiqueta_se_achica()
    {
        $dueno = $this->crear_dueno();

        $diseno = ArticleTicketDesignHelper::diseno_actual();
        $diseno['elementos'] = array($this->elemento('nombre', array('x' => 10, 'y' => 5, 'w' => 80, 'h' => 50)));

        list($valido, $normalizado) = ArticleTicketDesignHelper::normalizar_diseno($diseno, $dueno->id);

        $this->assertTrue($valido);
        $this->assertEquals(0, $normalizado['elementos'][0]['x']);
        $this->assertEquals(66.7, $normalizado['elementos'][0]['w']);
        $this->assertEquals(0, $normalizado['elementos'][0]['y']);
        $this->assertEquals(40, $normalizado['elementos'][0]['h']);
    }

    /** @test */
    public function el_tope_es_de_40_campos()
    {
        $dueno = $this->crear_dueno();
        $this->actingAs($dueno, 'web');

        $elementos = array();

        for ($i = 0; $i < 50; $i++) {
            $elementos[] = $this->elemento('texto_fijo', array('id' => 't'.$i, 'texto' => 'T'.$i));
        }

        $diseno = ArticleTicketDesignHelper::diseno_actual();
        $diseno['elementos'] = $elementos;

        $respuesta = $this->postJson('api/article-ticket-design', array('name' => 'Muchos', 'diseno' => $diseno));

        $respuesta->assertStatus(201);
        $this->assertCount(40, $respuesta->json('model.diseno.elementos'));
        $this->assertSame('t39', $respuesta->json('model.diseno.elementos.39.id'));
    }

    /** @test */
    public function el_diseno_de_siempre_normalizado_queda_igual()
    {
        $dueno = $this->crear_dueno(true);
        $lista = $this->crear_lista($dueno, 'Minorista', 1);

        list($valido, $sin_lista) = ArticleTicketDesignHelper::normalizar_diseno(ArticleTicketDesignHelper::diseno_actual(), $dueno->id);
        $this->assertTrue($valido);
        $this->assertEquals(ArticleTicketDesignHelper::diseno_actual(), $sin_lista);

        list($valido, $con_lista) = ArticleTicketDesignHelper::normalizar_diseno(ArticleTicketDesignHelper::diseno_actual($lista->id), $dueno->id);
        $this->assertTrue($valido);
        $this->assertEquals(ArticleTicketDesignHelper::diseno_actual($lista->id), $con_lista);
    }
}
