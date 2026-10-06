<?php

namespace Tests\Feature\CategoryProposals;

use App\Http\Controllers\Helpers\category_proposal\CategoryMargenesHelper;
use App\Models\Brand;
use App\Models\CategoryProposalRun;
use App\Models\Provider;

/**
 * Lo que la skill LEE del catálogo del cliente antes de proponer (misión categorizacion-tres-modelos,
 * 5/10/2026): `GET admin-sync/catalogo/resumen` y `GET admin-sync/catalogo/articulos`. Contrato A, §5.1
 * y §5.2.
 *
 * Los nombres de los campos son contrato con la skill: una clave mal escrita no explota, la skill
 * simplemente diseña los sistemas con un cero. Por eso se afirman con `array_keys`, en orden, y los
 * valores se comparan exactos contra un catálogo sembrado a mano.
 *
 * Todo por dueño: cada test mete un comercio "vecino" con datos propios y mide que no se cuele nada (ni
 * artículos, ni categorías, ni nombres de una categoría ajena que un artículo apunte por datos sucios).
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Catalogo_para_la_skill_Test extends CategoryProposalsTestCase
{
    use ArmaCorridasDeCategorias;

    /**
     * El resumen de un catálogo recién cargado, como el de Doblep: sin categorías, subcategorías ni marcas,
     * todos con código de proveedor y ninguno con código de barras.
     *
     * @group categorias_ia
     * @test
     */
    public function el_resumen_de_un_catalogo_sin_categorias()
    {
        foreach (['Bisagra 3 pulgadas', 'Corredera telescopica', 'Tornillo fix', 'Pomo de cajon'] as $i => $nombre) {

            $this->crear_articulo($nombre, ['provider_code' => 'PROV-'.($i + 1)]);
        }

        // El vecino tiene lo suyo y no se tiene que mezclar.
        $this->crear_articulo('Articulo del vecino', ['provider_code' => 'X', 'bar_code' => '7790000000001'], $this->vecino);

        $respuesta = $this->get_admin('resumen');

        $respuesta->assertStatus(200);

        $json = $respuesta->json();

        $this->assertSame(
            ['dueno', 'articulos', 'categorias', 'marcas_total', 'bloqueo', 'advertencias', 'tope_articulos', 'propuesta_actual'],
            array_keys($json)
        );

        $this->assertSame(['id', 'nombre'], array_keys($json['dueno']));
        $this->assertSame((int) $this->owner->id, $json['dueno']['id']);
        $this->assertSame('Comercio de categorias', $json['dueno']['nombre']);

        $this->assertSame(
            ['total', 'con_categoria', 'sin_categoria', 'con_subcategoria', 'con_marca', 'con_codigo_de_barras', 'con_codigo_de_proveedor'],
            array_keys($json['articulos'])
        );
        $this->assertSame(
            ['total' => 4, 'con_categoria' => 0, 'sin_categoria' => 4, 'con_subcategoria' => 0, 'con_marca' => 0, 'con_codigo_de_barras' => 0, 'con_codigo_de_proveedor' => 4],
            $json['articulos']
        );

        $this->assertSame([], $json['categorias']);
        $this->assertSame(0, $json['marcas_total']);
        $this->assertSame(10000, $json['tope_articulos']);
        $this->assertNull($json['propuesta_actual']);
    }

    /**
     * Los totales y las categorías con valores exactos: categorías vivas con su cantidad de artículos, sus
     * subcategorías, "sin categoría" en sus tres formas (NULL, 0 y categoría borrada), códigos con espacios
     * que no cuentan, y nada del vecino.
     *
     * @group categorias_ia
     * @test
     */
    public function el_resumen_cuenta_categorias_subcategorias_marcas_y_codigos()
    {
        $bisagras  = $this->crear_categoria_real('Bisagras');
        $comunes   = $this->crear_subcategoria_real('Comunes', $bisagras);
        $suaves    = $this->crear_subcategoria_real('De cierre suave', $bisagras);
        $tornillos = $this->crear_categoria_real('Tornillos');
        $borrada   = $this->crear_categoria_real('Categoria borrada');
        $marca     = Brand::create(['name' => 'Hafele', 'user_id' => $this->owner->id]);
        $marca_vieja = Brand::create(['name' => 'Marca borrada despues', 'user_id' => $this->owner->id]);

        // Con categoría y subcategoría, con marca y código de barras.
        $this->crear_articulo('Bisagra comun', ['category_id' => $bisagras->id, 'sub_category_id' => $comunes->id, 'brand_id' => $marca->id, 'bar_code' => '7791111111111', 'provider_code' => 'B-1']);
        $this->crear_articulo('Bisagra suave 1', ['category_id' => $bisagras->id, 'sub_category_id' => $suaves->id, 'provider_code' => 'B-2']);
        $this->crear_articulo('Bisagra suave 2', ['category_id' => $bisagras->id, 'sub_category_id' => $suaves->id]);
        $this->crear_articulo('Tornillo', ['category_id' => $tornillos->id, 'bar_code' => '   ', 'provider_code' => '   ']);

        // Sin categoría de tres maneras: NULL, 0 y una categoría que se mandó a la papelera.
        $this->crear_articulo('Sin categoria NULL', []);
        $this->crear_articulo('Sin categoria cero', ['category_id' => 0, 'sub_category_id' => 0]);
        $articulo_de_borrada = $this->crear_articulo('Sin categoria borrada', ['category_id' => $borrada->id]);

        // Una marca que se borró de la tabla (el artículo conserva el id): no cuenta como marca.
        $this->crear_articulo('Marca borrada', ['brand_id' => $marca_vieja->id]);
        $marca_vieja->delete();

        $borrada->delete();

        // Lo del vecino.
        $categoria_vecina = $this->crear_categoria_real('Categoria del vecino', $this->vecino);
        $this->crear_articulo('Articulo vecino', ['category_id' => $categoria_vecina->id], $this->vecino);
        Brand::create(['name' => 'Marca vecina', 'user_id' => $this->vecino->id]);

        $json = $this->get_admin('resumen')->assertStatus(200)->json();

        $this->assertSame(
            ['total' => 8, 'con_categoria' => 4, 'sin_categoria' => 4, 'con_subcategoria' => 3, 'con_marca' => 1, 'con_codigo_de_barras' => 1, 'con_codigo_de_proveedor' => 2],
            $json['articulos']
        );

        // Las categorías por nombre, con sus subcategorías por nombre y sus cantidades. La borrada no está.
        $this->assertSame([
            [
                'id'            => (int) $bisagras->id,
                'nombre'        => 'Bisagras',
                'articulos'     => 3,
                'subcategorias' => [
                    ['id' => (int) $comunes->id, 'nombre' => 'Comunes', 'articulos' => 1],
                    ['id' => (int) $suaves->id, 'nombre' => 'De cierre suave', 'articulos' => 2],
                ],
            ],
            ['id' => (int) $tornillos->id, 'nombre' => 'Tornillos', 'articulos' => 1, 'subcategorias' => []],
        ], $json['categorias']);

        // Las marcas son las del dueño (la borrada ya no está en la tabla).
        $this->assertSame(1, $json['marcas_total']);
    }

    /**
     * El universo son los artículos del dueño ACTIVOS y sin borrar: los de la papelera, los `inactive` (los
     * fantasma que crean las compras) y los del vecino no cuentan.
     *
     * @group categorias_ia
     * @test
     */
    public function el_resumen_no_cuenta_borrados_ni_inactivos_ni_de_otro_dueno()
    {
        $this->crear_articulo('Vivo 1');
        $this->crear_articulo('Vivo 2');
        $this->crear_articulo('Borrado')->delete();
        $this->crear_articulo('Fantasma de una compra', ['status' => 'inactive']);
        $this->crear_articulo('Otro estado', ['status' => 'from_provider_order']);
        $this->crear_articulo('Vivo del vecino', [], $this->vecino);

        $this->assertSame(2, $this->get_admin('resumen')->json()['articulos']['total']);
    }

    /**
     * Un artículo con datos sucios (su categoría, subcategoría, marca y proveedor son de OTRO comercio) no
     * deja salir esos nombres: se comporta como sin categoría, sin subcategoría, sin marca y sin proveedor.
     *
     * @group categorias_ia
     * @test
     */
    public function un_articulo_que_apunta_a_datos_de_otro_comercio_no_filtra_sus_nombres()
    {
        $categoria_ajena = $this->crear_categoria_real('Categoria secreta del vecino', $this->vecino);
        $sub_ajena       = $this->crear_subcategoria_real('Sub secreta del vecino', $categoria_ajena);
        $marca_ajena     = Brand::create(['name' => 'Marca secreta del vecino', 'user_id' => $this->vecino->id]);
        $proveedor_ajeno = Provider::create(['name' => 'Proveedor secreto del vecino', 'user_id' => $this->vecino->id]);

        $articulo = $this->crear_articulo('Articulo con datos cruzados', [
            'category_id'     => $categoria_ajena->id,
            'sub_category_id' => $sub_ajena->id,
            'brand_id'        => $marca_ajena->id,
            'provider_id'     => $proveedor_ajeno->id,
        ]);

        $resumen = $this->get_admin('resumen')->json();

        $this->assertSame(1, $resumen['articulos']['sin_categoria']);
        $this->assertSame(0, $resumen['articulos']['con_subcategoria']);
        $this->assertSame(0, $resumen['articulos']['con_marca']);
        $this->assertSame([], $resumen['categorias']);

        $pagina = $this->get_admin('articulos')->assertStatus(200)->json();

        $this->assertSame([
            [
                'id'                  => (int) $articulo->id,
                'nombre'              => 'Articulo con datos cruzados',
                'codigo_de_barras'    => null,
                'codigo_de_proveedor' => null,
                'proveedor'           => null,
                'marca'               => null,
                'categoria_id'        => null,
                'categoria'           => null,
                'subcategoria_id'     => null,
                'subcategoria'        => null,
            ],
        ], $pagina['articulos']);

        $this->assertStringNotContainsString('secret', json_encode($pagina));
        $this->assertStringNotContainsString('secret', json_encode($resumen));
    }

    /**
     * `propuesta_actual` es la última corrida NO descartada, en la forma corta `{run_id, estado}`.
     *
     * @group categorias_ia
     * @test
     */
    public function el_resumen_dice_la_propuesta_actual()
    {
        $this->assertNull($this->get_admin('resumen')->json()['propuesta_actual']);

        $descartada = CategoryProposalRun::create(['user_id' => $this->owner->id, 'estado' => 'descartada', 'origen' => 'skill']);

        $this->assertNull($this->get_admin('resumen')->json()['propuesta_actual'], 'Una corrida descartada no es la propuesta actual.');

        $vecina = CategoryProposalRun::create(['user_id' => $this->vecino->id, 'estado' => 'lista', 'origen' => 'skill']);

        $this->assertNull($this->get_admin('resumen')->json()['propuesta_actual'], 'La corrida del vecino no es la mía.');

        $mia = CategoryProposalRun::create(['user_id' => $this->owner->id, 'estado' => 'lista', 'origen' => 'skill']);

        $this->assertSame(['run_id' => (int) $mia->id, 'estado' => 'lista'], $this->get_admin('resumen')->json()['propuesta_actual']);

        $mia->estado = 'elegida';
        $mia->save();

        $this->assertSame(['run_id' => (int) $mia->id, 'estado' => 'elegida'], $this->get_admin('resumen')->json()['propuesta_actual']);
    }

    /**
     * El bloqueo y las advertencias son lo que dice el helper de las reglas de plata (la skill solo los
     * lee, no los decide), y el tope sale de la configuración.
     *
     * @group categorias_ia
     * @test
     */
    public function el_resumen_trae_el_bloqueo_las_advertencias_y_el_tope_de_la_configuracion()
    {
        config(['catalogo_ia.tope_articulos' => 1234]);

        $json = $this->get_admin('resumen')->json();

        $this->assertSame(CategoryMargenesHelper::bloqueo_para($this->owner->id), $json['bloqueo']);
        $this->assertSame(['nuevo_modelo_bloqueado', 'motivos'], array_keys($json['bloqueo']));
        $this->assertSame(array_values(CategoryMargenesHelper::advertencias_para($this->owner->id)), $json['advertencias']);
        $this->assertSame(1234, $json['tope_articulos']);
    }

    /**
     * El inventario por cursor de id: de a páginas, por `id` ascendente, cada artículo UNA vez, con
     * `siguiente` nulo en la última y `total` constante.
     *
     * @group categorias_ia
     * @test
     */
    public function los_articulos_se_recorren_por_cursor_de_id_sin_repetir_ni_saltear()
    {
        $ids = $this->crear_articulos_en_masa(7);

        // Un artículo del vecino metido entre los del dueño: los ids no son contiguos.
        $this->crear_articulo('Del vecino', [], $this->vecino);

        $vistos   = [];
        $cursor   = 0;
        $paginas  = 0;
        $totales  = [];
        $siguiente = null;

        do {
            $respuesta = $this->get_admin('articulos?despues_de='.$cursor.'&limite=3')->assertStatus(200);
            $json      = $respuesta->json();

            $this->assertSame(['articulos', 'siguiente', 'total'], array_keys($json));

            foreach ($json['articulos'] as $articulo) {

                $vistos[] = $articulo['id'];
            }

            $totales[] = $json['total'];
            $siguiente = $json['siguiente'];
            $cursor    = is_null($siguiente) ? 0 : $siguiente;
            $paginas++;
        } while (!is_null($siguiente) && $paginas < 10);

        $this->assertSame($ids, $vistos, 'Tiene que salir cada artículo del dueño una vez, en orden de id.');
        $this->assertSame(3, $paginas);
        $this->assertSame([7, 7, 7], $totales, '`total` es el de todo el catálogo, no el de la página.');
        $this->assertNull($siguiente);
    }

    /**
     * El cursor es "id MAYOR a": `siguiente` de una página es el `despues_de` de la que sigue. Y una página
     * exacta (la cantidad de artículos igual al límite) no inventa una siguiente vacía.
     *
     * @group categorias_ia
     * @test
     */
    public function el_cursor_es_mayor_a_y_una_pagina_exacta_no_inventa_otra()
    {
        $ids = $this->crear_articulos_en_masa(4);

        $primera = $this->get_admin('articulos?limite=2')->json();

        $this->assertSame([$ids[0], $ids[1]], array_column($primera['articulos'], 'id'));
        $this->assertSame($ids[1], $primera['siguiente']);

        $segunda = $this->get_admin('articulos?despues_de='.$primera['siguiente'].'&limite=2')->json();

        $this->assertSame([$ids[2], $ids[3]], array_column($segunda['articulos'], 'id'));
        $this->assertNull($segunda['siguiente'], 'Con exactamente `limite` artículos que quedan no hay una página siguiente.');

        $despues_del_ultimo = $this->get_admin('articulos?despues_de='.$ids[3])->json();

        $this->assertSame([], $despues_del_ultimo['articulos']);
        $this->assertNull($despues_del_ultimo['siguiente']);
        $this->assertSame(4, $despues_del_ultimo['total']);
    }

    /**
     * Los campos de cada artículo, con valores exactos: nombres resueltos de categoría, subcategoría, marca y
     * proveedor, y los textos vacíos o de solo espacios como null.
     *
     * @group categorias_ia
     * @test
     */
    public function cada_articulo_trae_sus_campos_con_los_nombres_resueltos()
    {
        $categoria = $this->crear_categoria_real('Bisagras');
        $sub       = $this->crear_subcategoria_real('Comunes', $categoria);
        $marca     = Brand::create(['name' => 'Hafele', 'user_id' => $this->owner->id]);
        $proveedor = Provider::create(['name' => 'Distribuidora Norte', 'user_id' => $this->owner->id]);

        $completo = $this->crear_articulo('Bisagra 3 pulgadas', [
            'category_id'     => $categoria->id,
            'sub_category_id' => $sub->id,
            'brand_id'        => $marca->id,
            'provider_id'     => $proveedor->id,
            'bar_code'        => '7791111111111',
            'provider_code'   => 'BIS-3',
        ]);
        $pelado = $this->crear_articulo('Tornillo', ['bar_code' => '   ', 'provider_code' => '']);

        $json = $this->get_admin('articulos')->assertStatus(200)->json();

        $this->assertSame([
            [
                'id'                  => (int) $completo->id,
                'nombre'              => 'Bisagra 3 pulgadas',
                'codigo_de_barras'    => '7791111111111',
                'codigo_de_proveedor' => 'BIS-3',
                'proveedor'           => 'Distribuidora Norte',
                'marca'               => 'Hafele',
                'categoria_id'        => (int) $categoria->id,
                'categoria'           => 'Bisagras',
                'subcategoria_id'     => (int) $sub->id,
                'subcategoria'        => 'Comunes',
            ],
            [
                'id'                  => (int) $pelado->id,
                'nombre'              => 'Tornillo',
                'codigo_de_barras'    => null,
                'codigo_de_proveedor' => null,
                'proveedor'           => null,
                'marca'               => null,
                'categoria_id'        => null,
                'categoria'           => null,
                'subcategoria_id'     => null,
                'subcategoria'        => null,
            ],
        ], $json['articulos']);
    }

    /**
     * `solo_sin_categoria=1` deja solo los que no tienen categoría viva (NULL, 0 o categoría borrada), y el
     * `total` pasa a ser el de ese filtro.
     *
     * @group categorias_ia
     * @test
     */
    public function solo_sin_categoria_filtra_y_el_total_es_el_del_filtro()
    {
        $categoria = $this->crear_categoria_real('Bisagras');
        $borrada   = $this->crear_categoria_real('Borrada');

        $this->crear_articulo('Con categoria', ['category_id' => $categoria->id]);
        $nulo  = $this->crear_articulo('Sin categoria NULL');
        $cero  = $this->crear_articulo('Sin categoria cero', ['category_id' => 0]);
        $sucio = $this->crear_articulo('Con categoria borrada', ['category_id' => $borrada->id]);
        $borrada->delete();

        $todos = $this->get_admin('articulos?solo_sin_categoria=0')->json();
        $solos = $this->get_admin('articulos?solo_sin_categoria=1')->json();

        $this->assertSame(4, $todos['total']);
        $this->assertSame(3, $solos['total']);
        $this->assertSame([(int) $nulo->id, (int) $cero->id, (int) $sucio->id], array_column($solos['articulos'], 'id'));

        // `true` también se entiende.
        $this->assertSame(3, $this->get_admin('articulos?solo_sin_categoria=true')->json()['total']);
    }

    /**
     * Los límites se acotan en vez de rechazar: un límite que pasa el tope de configuración es el tope; uno
     * en cero, negativo o que no es número es el de por defecto; un cursor negativo es el principio.
     *
     * @group categorias_ia
     * @test
     */
    public function el_limite_y_el_cursor_se_acotan_en_vez_de_rechazarse()
    {
        $ids = $this->crear_articulos_en_masa(6);

        config(['catalogo_ia.articulos_por_pagina_maximo' => 4]);

        $this->assertCount(4, $this->get_admin('articulos?limite=1000')->json()['articulos'], 'Pasado el tope se usa el tope.');

        // Cero, negativo, vacío o que no es número: el límite por defecto (500), que acá queda
        // acotado por el tope de 4.
        foreach (['0', '-3', 'abc', ''] as $limite) {

            $this->assertCount(4, $this->get_admin('articulos?limite='.$limite)->assertStatus(200)->json()['articulos'], 'limite='.$limite);
        }

        // Con el tope normal, el límite por defecto alcanza para todo el catálogo chico.
        config(['catalogo_ia.articulos_por_pagina_maximo' => 1000]);

        $this->assertCount(6, $this->get_admin('articulos?limite=0')->json()['articulos']);

        // Un cursor negativo o que no es número es el principio.
        foreach (['-5', 'abc', ''] as $cursor) {

            $this->assertSame($ids[0], $this->get_admin('articulos?despues_de='.$cursor)->assertStatus(200)->json()['articulos'][0]['id'], 'despues_de='.$cursor);
        }
    }

    /**
     * 🔴 B-14 (verificador, 5/10/2026): `despues_de`, `limite` y `solo_sin_categoria` que llegan como ARREGLO
     * en la query string no dan 500: se tratan como si no estuvieran.
     *
     * @group categorias_ia
     * @test
     */
    public function los_parametros_de_articulos_con_forma_de_arreglo_no_dan_500()
    {
        $ids = $this->crear_articulos_en_masa(3);

        foreach (['despues_de[]=1', 'limite[]=5', 'solo_sin_categoria[]=1', 'despues_de[a]=1&limite[b]=2&solo_sin_categoria[c]=1'] as $query) {

            $respuesta = $this->get_admin('articulos?'.$query);

            $this->assertSame(200, $respuesta->getStatusCode(), $query.': '.$respuesta->getContent());
            $this->assertSame($ids, array_column($respuesta->json()['articulos'], 'id'), $query);
        }
    }

    /**
     * Cantidad de consultas constante: el resumen y las páginas cuestan lo mismo con 30 que con 300
     * artículos, y ninguna lee la columna `embedding` (29 KB por fila).
     *
     * @group categorias_ia
     * @test
     */
    public function las_consultas_no_crecen_con_el_catalogo_y_no_leen_el_embedding()
    {
        $this->crear_articulos_en_masa(30, null, 'Chico');

        $con_30 = $this->medir_consultas(function () {
            $this->get_admin('resumen')->assertStatus(200);
            $this->get_admin('articulos?limite=1000')->assertStatus(200);
        });

        $this->crear_articulos_en_masa(270, null, 'Grande');

        $con_300 = $this->medir_consultas(function () {
            $this->get_admin('resumen')->assertStatus(200);
            $this->get_admin('articulos?limite=1000')->assertStatus(200);
        });

        $this->assertSame($con_30['cantidad'], $con_300['cantidad'], 'La cantidad de consultas no puede depender de la cantidad de artículos.');

        foreach ($con_300['consultas'] as $consulta) {

            $this->assertStringNotContainsString('embedding', $consulta, 'Una consulta del catálogo leyó la columna embedding: '.$consulta);
            $this->assertSame(0, preg_match('/select \* from `articles`/i', $consulta), 'Un select * sobre articles: '.$consulta);
        }
    }
}
