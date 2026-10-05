<?php

namespace Tests\Feature\CategoryProposals;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CategoryProposal;
use App\Models\CategoryProposalItem;
use App\Models\CategoryProposalNode;
use App\Models\CategoryProposalRun;
use Illuminate\Support\Facades\DB;

/**
 * La ingesta de propuestas por `POST admin-sync/catalogo/categorias/propuestas` (misión
 * categorizacion-tres-modelos, 5/10/2026). Contrato A, §5.3.
 *
 * Cubre lo que la skill puede mandar mal y lo que el servidor tiene que garantizar sin que nadie se
 * entere de otra manera: las validaciones y los topes (cada rechazo con su campo en `detalle` y SIN
 * dejar una corrida a medias), `La de siempre`, los nombres repetidos normalizados, `reemplazar` y los
 * dos 409, el tope de artículos y la propuesta "mantener" que el servidor arma con las categorías
 * existentes. Y que NADA de esto toca `categories` ni `articles` ni deja filas de auditoría.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Ingesta_de_propuestas_Test extends CategoryProposalsTestCase
{
    use ArmaCorridasDeCategorias;

    /**
     * El cuerpo de `crear` con las propuestas dadas.
     *
     * @param  array $propuestas
     * @param  bool  $reemplazar
     * @return array
     */
    protected function cuerpo(array $propuestas, $reemplazar = false)
    {
        return ['reemplazar' => $reemplazar, 'propuestas' => $propuestas];
    }

    /**
     * Cuántas corridas tiene el dueño en cualquier estado.
     *
     * @param  \App\Models\User|null $dueno
     * @return int
     */
    protected function corridas_de($dueno = null)
    {
        $dueno = is_null($dueno) ? $this->owner : $dueno;

        return CategoryProposalRun::where('user_id', $dueno->id)->count();
    }

    /**
     * Un árbol de `$categorias` categorías con `$subs` subcategorías cada una (nombres únicos).
     *
     * @param  int $categorias
     * @param  int $subs
     * @return array
     */
    protected function arbol_grande($categorias, $subs)
    {
        $arbol = [];

        for ($c = 1; $c <= $categorias; $c++) {

            $hijas = [];

            for ($s = 1; $s <= $subs; $s++) {

                $hijas[] = 'Sub '.$c.'-'.$s;
            }

            $arbol[] = ['nombre' => 'Categoria '.$c, 'subcategorias' => $hijas];
        }

        return $arbol;
    }

    // ------------------------------------------------------------------------------------------
    // El camino feliz
    // ------------------------------------------------------------------------------------------

    /**
     * @group categorias_ia
     * @test
     */
    public function crea_la_corrida_con_sus_propuestas_y_arboles_y_devuelve_los_ids_reales()
    {
        $this->crear_articulos_en_masa(3);

        $respuesta = $this->post_admin('categorias/propuestas', $this->cuerpo($this->propuestas_de_ejemplo(true)));

        $respuesta->assertStatus(201);

        $json = $respuesta->json();

        $this->assertSame(['run_id', 'estado', 'propuestas'], array_keys($json));
        $this->assertSame('preparando', $json['estado']);

        // La corrida: del dueño, preparando, de la skill y con los artículos que había.
        $run = CategoryProposalRun::find($json['run_id']);

        $this->assertNotNull($run);
        $this->assertSame((int) $this->owner->id, (int) $run->user_id);
        $this->assertSame('preparando', $run->estado);
        $this->assertSame('skill', $run->origen);
        $this->assertSame(3, (int) $run->articulos_total);
        $this->assertNull($run->propuesta_elegida_id);
        $this->assertNull($run->visto_at);

        // Las tarjetas, en el orden en que se mandaron.
        $this->assertCount(2, $json['propuestas']);

        $filas = CategoryProposal::where('run_id', $run->id)->orderBy('orden')->get();

        $this->assertSame(['A', 'B'], $filas->pluck('clave')->all());
        $this->assertSame([1, 2], $filas->pluck('orden')->map(function ($o) { return (int) $o; })->all());
        $this->assertSame('Por rubro, como en góndola', $filas[0]->nombre);
        $this->assertSame('Las categorías de una ferretería de barrio.', $filas[0]->resumen);
        $this->assertSame('Se basa en cómo se ordena la góndola de una ferretería.', $filas[0]->descripcion);
        $this->assertSame('nueva', $filas[0]->tipo);
        $this->assertNull($filas[1]->descripcion);
        $this->assertSame((int) $this->owner->id, (int) $filas[0]->user_id);

        // La respuesta: cada tarjeta con su id y su árbol con los ids de los nodos.
        $this->assertSame(['clave', 'id', 'tipo', 'categorias'], array_keys($json['propuestas'][0]));
        $this->assertSame((int) $filas[0]->id, $json['propuestas'][0]['id']);
        $this->assertSame('A', $json['propuestas'][0]['clave']);
        $this->assertSame('nueva', $json['propuestas'][0]['tipo']);

        $categorias_a = $json['propuestas'][0]['categorias'];

        $this->assertSame(['nombre', 'id', 'subcategorias'], array_keys($categorias_a[0]));
        $this->assertSame(['Bisagras', 'Correderas'], array_column($categorias_a, 'nombre'));
        $this->assertSame(['De cierre suave', 'Comunes'], array_column($categorias_a[0]['subcategorias'], 'nombre'));
        $this->assertSame(['nombre', 'id'], array_keys($categorias_a[0]['subcategorias'][0]));
        $this->assertSame([], $categorias_a[1]['subcategorias']);

        // Cada id de la respuesta es el del nodo que dice ser, bien enganchado a su padre.
        $bisagras = CategoryProposalNode::find($categorias_a[0]['id']);
        $suave    = CategoryProposalNode::find($categorias_a[0]['subcategorias'][0]['id']);
        $comunes  = CategoryProposalNode::find($categorias_a[0]['subcategorias'][1]['id']);

        $this->assertSame('Bisagras', $bisagras->nombre);
        $this->assertNull($bisagras->parent_id);
        $this->assertSame((int) $filas[0]->id, (int) $bisagras->proposal_id);
        $this->assertSame('bisagras', $bisagras->clave_nombre);
        $this->assertSame(1, (int) $bisagras->orden);
        $this->assertSame('De cierre suave', $suave->nombre);
        $this->assertSame((int) $bisagras->id, (int) $suave->parent_id);
        $this->assertSame('de cierre suave', $suave->clave_nombre);
        $this->assertSame(1, (int) $suave->orden);
        $this->assertSame('Comunes', $comunes->nombre);
        $this->assertSame((int) $bisagras->id, (int) $comunes->parent_id);
        $this->assertSame(2, (int) $comunes->orden);

        // Una propuesta nueva no apunta a nada real.
        foreach ([$bisagras, $suave, $comunes] as $nodo) {

            $this->assertNull($nodo->existing_category_id);
            $this->assertNull($nodo->existing_sub_category_id);
            $this->assertNull($nodo->real_category_id);
            $this->assertNull($nodo->real_sub_category_id);
            $this->assertFalse((bool) $nodo->real_creado);
            $this->assertSame((int) $this->owner->id, (int) $nodo->user_id);
        }

        // Total de nodos: A tiene 2 categorías y 2 subcategorías; B, 2 y 1.
        $this->assertSame(4, CategoryProposalNode::where('proposal_id', $filas[0]->id)->count());
        $this->assertSame(3, CategoryProposalNode::where('proposal_id', $filas[1]->id)->count());
    }

    /**
     * 🔴 Crear una corrida NO toca `categories` ni `articles`: solo las cuatro tablas de propuestas. Las
     * categorías reales se crean recién cuando el dueño elige.
     *
     * @group categorias_ia
     * @test
     */
    public function crear_no_toca_categories_ni_articles()
    {
        $ids = $this->crear_articulos_en_masa(3);

        $categorias_antes = DB::table('categories')->count();
        $sub_antes        = DB::table('sub_categories')->count();
        $articulos_antes  = DB::table('articles')->whereIn('id', $ids)->orderBy('id')->get(['id', 'category_id', 'sub_category_id', 'updated_at'])->toArray();

        $this->crear_corrida_por_api();

        $this->assertSame($categorias_antes, DB::table('categories')->count());
        $this->assertSame($sub_antes, DB::table('sub_categories')->count());
        $this->assertEquals($articulos_antes, DB::table('articles')->whereIn('id', $ids)->orderBy('id')->get(['id', 'category_id', 'sub_category_id', 'updated_at'])->toArray());
    }

    /**
     * La clave de comparación de cada nodo es el nombre normalizado (minúsculas, sin acentos, espacios
     * colapsados): es con lo que después se resuelven los nombres de las asignaciones.
     *
     * @group categorias_ia
     * @test
     */
    public function guarda_el_nombre_tal_cual_y_su_clave_normalizada()
    {
        $json = $this->crear_corrida_por_api([[
            'clave'  => 'A',
            'tipo'   => 'nueva',
            'nombre' => 'Normalizada',
            'arbol'  => [
                ['nombre' => 'Ferretería   y  Herrajes', 'subcategorias' => ['Cerraduras ÑANDÚ']],
                ['nombre' => 'Pinturas'],
            ],
        ]]);

        $nodo = CategoryProposalNode::find($json['propuestas'][0]['categorias'][0]['id']);
        $sub  = CategoryProposalNode::find($json['propuestas'][0]['categorias'][0]['subcategorias'][0]['id']);

        $this->assertSame('Ferretería   y  Herrajes', $nodo->nombre);
        $this->assertSame('ferreteria y herrajes', $nodo->clave_nombre);
        $this->assertSame('Cerraduras ÑANDÚ', $sub->nombre);
        $this->assertSame('cerraduras nandu', $sub->clave_nombre);
    }

    /**
     * 🔴 Ni las tablas de propuestas dejan filas de auditoría (la ingesta puede escribir cientos de nodos y
     * miles de ítems) ni dejan de auditarse los modelos de negocio: el control es que una `Category`
     * creada con Eloquent SÍ deja fila, así que el listener anda en este test.
     *
     * @group categorias_ia
     * @test
     */
    public function las_tablas_de_propuestas_no_dejan_filas_de_auditoria()
    {
        $this->crear_articulos_en_masa(2);

        $id_inicial = (int) AuditLog::max('id');

        $json = $this->crear_corrida_por_api();

        $run_id = $json['run_id'];

        $this->post_admin('categorias/propuestas/'.$run_id.'/descartar')->assertStatus(200);

        $clases = [CategoryProposalRun::class, CategoryProposal::class, CategoryProposalNode::class, CategoryProposalItem::class];

        $this->assertSame(
            0,
            AuditLog::where('id', '>', $id_inicial)->whereIn('auditable_type', $clases)->count(),
            'Las tablas de propuestas son staging: no se auditan.'
        );

        // El control: un modelo de negocio sí deja fila.
        $this->crear_categoria_real('Categoria de control');

        $this->assertGreaterThan(
            0,
            AuditLog::where('id', '>', $id_inicial)->where('auditable_type', Category::class)->count(),
            'El control tiene que auditar: si no, el test no prueba nada.'
        );
    }

    // ------------------------------------------------------------------------------------------
    // Validaciones y topes
    // ------------------------------------------------------------------------------------------

    /**
     * 🔴 Cada cuerpo mal armado se rechaza con 422 `validacion`, con el campo en `detalle`, y NO deja una
     * corrida a medio crear.
     *
     * @group categorias_ia
     * @test
     */
    public function los_cuerpos_mal_armados_son_422_con_su_campo_y_no_dejan_nada()
    {
        $buena = $this->propuestas_de_ejemplo()[0];

        $con = function (array $cambios) use ($buena) {
            return array_merge($buena, $cambios);
        };

        $texto_largo = function ($largo) {
            return str_repeat('x', $largo);
        };

        // [descripción del caso, cuerpo, campo que tiene que figurar en `detalle`]
        $casos = [
            ['sin propuestas', ['reemplazar' => false], 'propuestas'],
            ['propuestas vacío', $this->cuerpo([]), 'propuestas'],
            ['propuestas no es una lista', ['propuestas' => 'A'], 'propuestas'],
            ['cinco propuestas', $this->cuerpo([
                $con(['clave' => 'A']), $con(['clave' => 'B']), $con(['clave' => 'C']), $con(['clave' => 'A']), $con(['clave' => 'B']),
            ]), 'propuestas'],
            ['propuesta que no es un objeto', $this->cuerpo(['A']), 'propuestas.0'],
            ['clave inválida', $this->cuerpo([$con(['clave' => 'Z'])]), 'propuestas.0.clave'],
            ['clave en minúscula', $this->cuerpo([$con(['clave' => 'a'])]), 'propuestas.0.clave'],
            ['clave repetida', $this->cuerpo([$con(['clave' => 'A']), $con(['clave' => 'A'])]), 'propuestas.1.clave'],
            ['tipo inválido', $this->cuerpo([$con(['tipo' => 'otra'])]), 'propuestas.0.tipo'],
            ['clave mantener con tipo nueva', $this->cuerpo([$con(['clave' => 'mantener', 'tipo' => 'nueva'])]), 'propuestas.0.clave'],
            ['clave A con tipo mantener', $this->cuerpo([['clave' => 'A', 'tipo' => 'mantener', 'nombre' => 'Mantener']]), 'propuestas.0.clave'],
            ['nombre vacío', $this->cuerpo([$con(['nombre' => ''])]), 'propuestas.0.nombre'],
            ['nombre de más de 120', $this->cuerpo([$con(['nombre' => $texto_largo(121)])]), 'propuestas.0.nombre'],
            ['resumen de más de 255', $this->cuerpo([$con(['resumen' => $texto_largo(256)])]), 'propuestas.0.resumen'],
            ['resumen que no es texto', $this->cuerpo([$con(['resumen' => ['a']])]), 'propuestas.0.resumen'],
            ['descripción de más de 10000', $this->cuerpo([$con(['descripcion' => $texto_largo(10001)])]), 'propuestas.0.descripcion'],
            ['nueva sin árbol', $this->cuerpo([['clave' => 'A', 'tipo' => 'nueva', 'nombre' => 'Sin arbol']]), 'propuestas.0.arbol'],
            ['árbol vacío', $this->cuerpo([$con(['arbol' => []])]), 'propuestas.0.arbol'],
            ['árbol de un solo nodo', $this->cuerpo([$con(['arbol' => [['nombre' => 'Sola']]])]), 'propuestas.0.arbol'],
            ['más de 400 nodos', $this->cuerpo([$con(['arbol' => $this->arbol_grande(101, 3)])]), 'propuestas.0.arbol'],
            ['categoría que no es un objeto', $this->cuerpo([$con(['arbol' => ['Bisagras', 'Correderas']])]), 'propuestas.0.arbol.0'],
            ['categoría sin nombre', $this->cuerpo([$con(['arbol' => [['subcategorias' => ['x']], ['nombre' => 'Otra']]])]), 'propuestas.0.arbol.0.nombre'],
            ['nombre de categoría que no es texto', $this->cuerpo([$con(['arbol' => [['nombre' => 5], ['nombre' => 'Otra']]])]), 'propuestas.0.arbol.0.nombre'],
            ['nombre de categoría de más de 128', $this->cuerpo([$con(['arbol' => [['nombre' => $texto_largo(129)], ['nombre' => 'Otra']]])]), 'propuestas.0.arbol.0.nombre'],
            ['nombre de subcategoría de más de 128', $this->cuerpo([$con(['arbol' => [['nombre' => 'Cat', 'subcategorias' => [$texto_largo(129)]], ['nombre' => 'Otra']]])]), 'propuestas.0.arbol.0.subcategorias.0'],
            ['subcategorías que no es una lista', $this->cuerpo([$con(['arbol' => [['nombre' => 'Cat', 'subcategorias' => 'x'], ['nombre' => 'Otra']]])]), 'propuestas.0.arbol.0.subcategorias'],
            ['subcategoría vacía', $this->cuerpo([$con(['arbol' => [['nombre' => 'Cat', 'subcategorias' => ['']], ['nombre' => 'Otra']]])]), 'propuestas.0.arbol.0.subcategorias.0'],
            ['categoría repetida', $this->cuerpo([$con(['arbol' => [['nombre' => 'Bisagras'], ['nombre' => 'Bisagras']]])]), 'propuestas.0.arbol.1.nombre'],
            ['categoría repetida sin mayúsculas ni acentos', $this->cuerpo([$con(['arbol' => [['nombre' => 'Ferretería'], ['nombre' => 'FERRETERIA']]])]), 'propuestas.0.arbol.1.nombre'],
            ['categoría repetida con espacios de más', $this->cuerpo([$con(['arbol' => [['nombre' => 'Puertas y ventanas'], ['nombre' => 'puertas   y   ventanas']]])]), 'propuestas.0.arbol.1.nombre'],
            ['subcategoría repetida en la misma categoría', $this->cuerpo([$con(['arbol' => [['nombre' => 'Cat', 'subcategorias' => ['Comunes', 'comúnes']], ['nombre' => 'Otra']]])]), 'propuestas.0.arbol.0.subcategorias.1'],
            ['La de siempre como categoría', $this->cuerpo([$con(['arbol' => [['nombre' => 'La de siempre'], ['nombre' => 'Otra']]])]), 'propuestas.0.arbol.0.nombre'],
            ['LA  DE SIEMPRE como categoría', $this->cuerpo([$con(['arbol' => [['nombre' => 'Otra'], ['nombre' => 'LA  DE   SIEMPRE']]])]), 'propuestas.0.arbol.1.nombre'],
            ['La de siempre como subcategoría', $this->cuerpo([$con(['arbol' => [['nombre' => 'Cat', 'subcategorias' => ['la de siempre']], ['nombre' => 'Otra']]])]), 'propuestas.0.arbol.0.subcategorias.0'],
            ['mantener con árbol', $this->cuerpo([['clave' => 'mantener', 'tipo' => 'mantener', 'nombre' => 'Mantener', 'arbol' => [['nombre' => 'X']]]]), 'propuestas.0.arbol'],

            // B-01 (verificador, 5/10/2026): ni `<` ni `>` en los NOMBRES (XSS guardado).
            ['nombre de la propuesta con una etiqueta', $this->cuerpo([$con(['nombre' => '<img src=x onerror=alert(document.domain)>'])]), 'propuestas.0.nombre'],
            ['nombre de la propuesta con solo un >', $this->cuerpo([$con(['nombre' => 'Por rubro > por uso'])]), 'propuestas.0.nombre'],
            ['nombre de la propuesta mantener con una etiqueta', $this->cuerpo([['clave' => 'mantener', 'tipo' => 'mantener', 'nombre' => 'Mantener <b>mis</b> categorias']]), 'propuestas.0.nombre'],
            ['nombre de categoría con una etiqueta', $this->cuerpo([$con(['arbol' => [['nombre' => 'Bisagras <b>negrita</b>'], ['nombre' => 'Otra']]])]), 'propuestas.0.arbol.0.nombre'],
            ['nombre de categoría con solo un <', $this->cuerpo([$con(['arbol' => [['nombre' => 'Otra'], ['nombre' => 'Menos de 5 <mm']]])]), 'propuestas.0.arbol.1.nombre'],
            ['nombre de subcategoría con una etiqueta', $this->cuerpo([$con(['arbol' => [['nombre' => 'Cat', 'subcategorias' => ['<script>alert(1)</script>']], ['nombre' => 'Otra']]])]), 'propuestas.0.arbol.0.subcategorias.0'],
            ['nombre de subcategoría con solo un >', $this->cuerpo([$con(['arbol' => [['nombre' => 'Cat', 'subcategorias' => ['Comunes', 'Mas de 3 >mm']], ['nombre' => 'Otra']]])]), 'propuestas.0.arbol.0.subcategorias.1'],
        ];

        foreach ($casos as $caso) {

            list($descripcion, $cuerpo, $campo) = $caso;

            $respuesta = $this->post_admin('categorias/propuestas', $cuerpo);

            $this->assertSame(422, $respuesta->getStatusCode(), $descripcion.': '.$respuesta->getContent());

            $json = $respuesta->json();

            $this->assertSame('validacion', $json['error'], $descripcion);
            $this->assertNotEmpty($json['message'], $descripcion);
            $this->assertArrayHasKey('detalle', $json, $descripcion);
            $this->assertArrayHasKey($campo, $json['detalle'], $descripcion.': el detalle no tiene '.$campo.': '.$respuesta->getContent());
            $this->assertNotEmpty($json['detalle'][$campo], $descripcion);

            $this->assertSame(0, $this->corridas_de(), $descripcion.': un cuerpo mal armado dejó una corrida.');
            $this->assertSame(0, CategoryProposal::where('user_id', $this->owner->id)->count(), $descripcion);
            $this->assertSame(0, CategoryProposalNode::where('user_id', $this->owner->id)->count(), $descripcion);
        }
    }

    /**
     * 🔴 B-01 (verificador, 5/10/2026): el `resumen` y la `descripcion` SÍ pueden llevar `<` y `>` (la SPA los
     * muestra con interpolación, que escapa) y llegan tal cual a `actual`; en los NOMBRES, los demás signos
     * (`&`, comillas, barras, acentos) son válidos: solo `<` y `>` abren una etiqueta.
     *
     * @group categorias_ia
     * @test
     */
    public function el_resumen_y_la_descripcion_pueden_llevar_signos_de_html_y_los_nombres_otros_signos()
    {
        $this->crear_articulos_en_masa(2);

        $json = $this->crear_corrida_por_api([[
            'clave'       => 'A',
            'tipo'        => 'nueva',
            'nombre'      => 'Pinturas & barnices "premium" / línea \'pro\'',
            'resumen'     => 'Lo que sigue es <b>texto</b>, no HTML.',
            'descripcion' => 'Se basa en <rubros> de ferretería: 5 > 3 y 2 < 4.',
            'arbol'       => [
                ['nombre' => 'Pinturas & esmaltes', 'subcategorias' => ['Látex 100% (interior)', 'Sintético "brillante"']],
                ['nombre' => 'Tornillos / bulones'],
            ],
        ]]);

        $proposal = CategoryProposal::find($json['propuestas'][0]['id']);

        $this->assertSame('Lo que sigue es <b>texto</b>, no HTML.', $proposal->resumen);
        $this->assertSame('Se basa en <rubros> de ferretería: 5 > 3 y 2 < 4.', $proposal->descripcion);
        $this->assertSame('Pinturas & barnices "premium" / línea \'pro\'', $proposal->nombre);
        $this->assertSame(['Pinturas & esmaltes', 'Tornillos / bulones'], array_column($json['propuestas'][0]['categorias'], 'nombre'));
    }

    /**
     * Los bordes que SÍ se aceptan: exactamente 400 nodos, un nombre de exactamente 128 caracteres, dos
     * nodos, la misma subcategoría bajo dos categorías distintas y una subcategoría que se llama como su
     * categoría (son niveles distintos).
     *
     * @group categorias_ia
     * @test
     */
    public function los_bordes_de_los_topes_se_aceptan()
    {
        // Exactamente 400 nodos: 100 categorías con 3 subcategorías cada una.
        $json = $this->crear_corrida_por_api([[
            'clave' => 'A', 'tipo' => 'nueva', 'nombre' => 'Grande', 'arbol' => $this->arbol_grande(100, 3),
        ]], true);

        $proposal_id = $json['propuestas'][0]['id'];

        $this->assertSame(400, CategoryProposalNode::where('proposal_id', $proposal_id)->count());
        $this->assertCount(100, $json['propuestas'][0]['categorias']);
        $this->assertCount(3, $json['propuestas'][0]['categorias'][99]['subcategorias']);

        // Un nombre de exactamente 128 caracteres, y de 120 en la propuesta.
        $largo = str_repeat('n', 128);

        $json = $this->crear_corrida_por_api([[
            'clave' => 'A', 'tipo' => 'nueva', 'nombre' => str_repeat('p', 120), 'arbol' => [['nombre' => $largo], ['nombre' => 'Otra']],
        ]], true);

        $this->assertSame($largo, CategoryProposalNode::find($json['propuestas'][0]['categorias'][0]['id'])->nombre);

        // Dos nodos, la misma subcategoría bajo dos categorías, y una subcategoría con el nombre de su categoría.
        $json = $this->crear_corrida_por_api([[
            'clave' => 'A', 'tipo' => 'nueva', 'nombre' => 'Mismos nombres', 'arbol' => [
                ['nombre' => 'Bisagras', 'subcategorias' => ['Comunes', 'Bisagras']],
                ['nombre' => 'Correderas', 'subcategorias' => ['Comunes']],
            ],
        ]], true);

        $this->assertCount(5, CategoryProposalNode::where('proposal_id', $json['propuestas'][0]['id'])->get());

        $json = $this->crear_corrida_por_api([[
            'clave' => 'A', 'tipo' => 'nueva', 'nombre' => 'Dos nodos', 'arbol' => [['nombre' => 'Uno'], ['nombre' => 'Dos']],
        ]], true);

        $this->assertCount(2, CategoryProposalNode::where('proposal_id', $json['propuestas'][0]['id'])->get());
    }

    /**
     * Un cuerpo sin `tipo` es una propuesta nueva (el default de la columna), y `reemplazar` se entiende
     * como booleano en sus formas habituales.
     *
     * @group categorias_ia
     * @test
     */
    public function el_tipo_por_defecto_es_nueva()
    {
        $json = $this->crear_corrida_por_api([[
            'clave' => 'A', 'nombre' => 'Sin tipo', 'arbol' => [['nombre' => 'Uno'], ['nombre' => 'Dos']],
        ]]);

        $this->assertSame('nueva', $json['propuestas'][0]['tipo']);
        $this->assertSame('nueva', CategoryProposal::find($json['propuestas'][0]['id'])->tipo);
    }

    /**
     * 🔴 El tope de artículos: con más artículos que `catalogo_ia.tope_articulos` la corrida NO se crea y
     * la respuesta es 422 `catalogo_muy_grande` con los números. Con exactamente el tope, sí.
     *
     * @group categorias_ia
     * @test
     */
    public function un_catalogo_mas_grande_que_el_tope_no_crea_la_corrida()
    {
        config(['catalogo_ia.tope_articulos' => 2]);

        $this->crear_articulos_en_masa(3);

        // Los borrados, los inactivos y los del vecino no cuentan para el tope.
        $this->crear_articulo('Borrado')->delete();
        $this->crear_articulo('Fantasma', ['status' => 'inactive']);
        $this->crear_articulos_en_masa(5, $this->vecino, 'Vecino');

        $respuesta = $this->post_admin('categorias/propuestas', $this->cuerpo($this->propuestas_de_ejemplo()));

        $respuesta->assertStatus(422);

        $json = $respuesta->json();

        $this->assertSame('catalogo_muy_grande', $json['error']);
        $this->assertSame(3, $json['articulos_total']);
        $this->assertSame(2, $json['tope_articulos']);
        $this->assertNotEmpty($json['message']);
        $this->assertSame(0, $this->corridas_de());

        // Con exactamente el tope, sí.
        config(['catalogo_ia.tope_articulos' => 3]);

        $this->post_admin('categorias/propuestas', $this->cuerpo($this->propuestas_de_ejemplo()))->assertStatus(201);
    }

    // ------------------------------------------------------------------------------------------
    // reemplazar y los dos 409
    // ------------------------------------------------------------------------------------------

    /**
     * Con una corrida en curso, crear otra sin `reemplazar` es 409 `ya_hay_una_propuesta` con el id y el
     * estado de la que está, y no se crea nada.
     *
     * @group categorias_ia
     * @test
     */
    public function con_una_corrida_en_curso_crear_otra_sin_reemplazar_es_409()
    {
        $primera = $this->crear_corrida_por_api();

        $respuesta = $this->post_admin('categorias/propuestas', $this->cuerpo($this->propuestas_de_ejemplo()));

        $respuesta->assertStatus(409);

        $json = $respuesta->json();

        $this->assertSame('ya_hay_una_propuesta', $json['error']);
        $this->assertSame($primera['run_id'], $json['run_id']);
        $this->assertSame('preparando', $json['estado']);
        $this->assertNotEmpty($json['message']);

        $this->assertSame(1, $this->corridas_de());
        $this->assertSame('preparando', CategoryProposalRun::find($primera['run_id'])->estado);

        // Con la corrida ya en `lista` es lo mismo, con su estado.
        $this->post_admin('categorias/propuestas/'.$primera['run_id'].'/listo', ['forzar' => true])->assertStatus(200);

        $otra = $this->post_admin('categorias/propuestas', $this->cuerpo($this->propuestas_de_ejemplo()));

        $otra->assertStatus(409);
        $this->assertSame('ya_hay_una_propuesta', $otra->json()['error']);
        $this->assertSame('lista', $otra->json()['estado']);
    }

    /**
     * Con `reemplazar` la corrida vieja (preparando o lista) pasa a `descartada` y queda UNA sola vigente.
     *
     * @group categorias_ia
     * @test
     */
    public function reemplazar_descarta_la_vieja_y_deja_una_sola_vigente()
    {
        $vieja = $this->crear_corrida_por_api();

        $nueva = $this->crear_corrida_por_api(null, true);

        $this->assertNotSame($vieja['run_id'], $nueva['run_id']);

        $vieja_fila = CategoryProposalRun::find($vieja['run_id']);

        $this->assertSame('descartada', $vieja_fila->estado);
        $this->assertNotNull($vieja_fila->descartada_at);
        $this->assertSame('preparando', CategoryProposalRun::find($nueva['run_id'])->estado);

        $this->assertSame(1, CategoryProposalRun::where('user_id', $this->owner->id)->vigentes()->count());

        // Con la vieja en `lista` también.
        $this->post_admin('categorias/propuestas/'.$nueva['run_id'].'/listo', ['forzar' => true])->assertStatus(200);

        $tercera = $this->crear_corrida_por_api(null, true);

        $this->assertSame('descartada', CategoryProposalRun::find($nueva['run_id'])->estado);
        $this->assertSame(1, CategoryProposalRun::where('user_id', $this->owner->id)->vigentes()->count());
        $this->assertSame((int) $tercera['run_id'], (int) CategoryProposalRun::where('user_id', $this->owner->id)->vigentes()->value('id'));
    }

    /**
     * `reemplazar` se entiende también como "true" y como 1; cualquier otra cosa (false, 0, ausente) es no
     * reemplazar.
     *
     * @group categorias_ia
     * @test
     */
    public function reemplazar_se_entiende_como_booleano_en_sus_formas_habituales()
    {
        $this->crear_corrida_por_api();

        foreach ([false, 0, '0', 'no', null] as $no) {

            $this->post_admin('categorias/propuestas', ['reemplazar' => $no, 'propuestas' => $this->propuestas_de_ejemplo()])
                ->assertStatus(409);
        }

        foreach (['true', 1] as $si) {

            $this->post_admin('categorias/propuestas', ['reemplazar' => $si, 'propuestas' => $this->propuestas_de_ejemplo()])
                ->assertStatus(201);
        }

        $this->assertSame(1, CategoryProposalRun::where('user_id', $this->owner->id)->vigentes()->count());
    }

    /**
     * 🔴 Con una corrida ELEGIDA es siempre 409 `ya_hay_una_elegida`, con o sin `reemplazar`: el dueño ya
     * decidió y tocó su catálogo. La elegida queda intacta.
     *
     * @group categorias_ia
     * @test
     */
    public function una_corrida_elegida_nunca_se_reemplaza()
    {
        $elegida = CategoryProposalRun::create(['user_id' => $this->owner->id, 'estado' => 'elegida', 'origen' => 'skill']);

        foreach ([false, true] as $reemplazar) {

            $respuesta = $this->post_admin('categorias/propuestas', $this->cuerpo($this->propuestas_de_ejemplo(), $reemplazar));

            $respuesta->assertStatus(409);

            $json = $respuesta->json();

            $this->assertSame('ya_hay_una_elegida', $json['error']);
            $this->assertSame((int) $elegida->id, $json['run_id']);
            $this->assertSame('elegida', $json['estado']);
        }

        $this->assertSame('elegida', CategoryProposalRun::find($elegida->id)->estado);
        $this->assertSame(1, $this->corridas_de());

        // Una corrida que se está aplicando en este momento tampoco se pisa.
        $elegida->estado = 'aplicando';
        $elegida->save();

        $this->post_admin('categorias/propuestas', $this->cuerpo($this->propuestas_de_ejemplo(), true))->assertStatus(409);
        $this->assertSame('aplicando', CategoryProposalRun::find($elegida->id)->estado);
    }

    /**
     * Una corrida descartada no estorba, y la corrida de otro comercio ni estorba ni se descarta.
     *
     * @group categorias_ia
     * @test
     */
    public function las_descartadas_y_las_del_vecino_no_estorban()
    {
        CategoryProposalRun::create(['user_id' => $this->owner->id, 'estado' => 'descartada', 'origen' => 'skill']);
        $del_vecino = CategoryProposalRun::create(['user_id' => $this->vecino->id, 'estado' => 'lista', 'origen' => 'skill']);

        $this->post_admin('categorias/propuestas', $this->cuerpo($this->propuestas_de_ejemplo()))->assertStatus(201);

        // Reemplazar tampoco toca la del vecino.
        $this->crear_corrida_por_api(null, true);

        $this->assertSame('lista', CategoryProposalRun::find($del_vecino->id)->estado);
        $this->assertNull(CategoryProposalRun::find($del_vecino->id)->descartada_at);
    }

    /**
     * 🔴 Un rechazo NO deja la corrida vieja descartada: si `reemplazar` viene con una propuesta
     * "mantener" que no se puede armar (el comercio no tiene categorías), la corrida anterior sigue
     * vigente y no se crea nada.
     *
     * @group categorias_ia
     * @test
     */
    public function un_rechazo_con_reemplazar_no_descarta_la_corrida_vieja()
    {
        $vieja = $this->crear_corrida_por_api();

        $respuesta = $this->post_admin('categorias/propuestas', $this->cuerpo([
            ['clave' => 'mantener', 'tipo' => 'mantener', 'nombre' => 'Mantener mis categorias'],
        ], true));

        $respuesta->assertStatus(422);

        $this->assertSame('preparando', CategoryProposalRun::find($vieja['run_id'])->estado);
        $this->assertNull(CategoryProposalRun::find($vieja['run_id'])->descartada_at);
        $this->assertSame(1, $this->corridas_de());
    }

    // ------------------------------------------------------------------------------------------
    // "Mantener las mías"
    // ------------------------------------------------------------------------------------------

    /**
     * "Mantener" solo se acepta si el dueño tiene categorías vivas: sin ellas no hay nada que mantener.
     *
     * @group categorias_ia
     * @test
     */
    public function mantener_sin_categorias_vivas_es_422()
    {
        // Las categorías del vecino y las borradas no cuentan.
        $this->crear_categoria_real('Del vecino', $this->vecino);
        $this->crear_categoria_real('Borrada')->delete();

        $respuesta = $this->post_admin('categorias/propuestas', $this->cuerpo([
            ['clave' => 'mantener', 'tipo' => 'mantener', 'nombre' => 'Mantener mis categorias'],
        ]));

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json()['error']);
        $this->assertArrayHasKey('propuestas', $respuesta->json()['detalle']);
        $this->assertSame(0, $this->corridas_de());
    }

    /**
     * 🔴 El servidor arma los nodos de "Mantener las mías" con las categorías y subcategorías VIVAS del
     * dueño, cada nodo atado a su id real. Se saltea lo que no se puede usar (La de siempre, borradas, las
     * de otro comercio) y los nombres repetidos (gana la más vieja), y queda ordenado por nombre.
     *
     * @group categorias_ia
     * @test
     */
    public function mantener_arma_los_nodos_con_las_categorias_existentes()
    {
        $herrajes   = $this->crear_categoria_real('Herrajes');
        $bisagras   = $this->crear_subcategoria_real('Bisagras', $herrajes);
        $cierres    = $this->crear_subcategoria_real('Cierres', $herrajes);
        $repetida   = $this->crear_subcategoria_real('  bisagras ', $herrajes);
        $sub_borrada = $this->crear_subcategoria_real('Sub borrada', $herrajes);
        $sub_borrada->delete();

        $pinturas = $this->crear_categoria_real('Pinturas');

        // No entran: repetida más nueva (y sus hijas), La de siempre (y sus hijas), borrada, del vecino.
        $duplicada = $this->crear_categoria_real('herrajes ');
        $this->crear_subcategoria_real('Hija de la duplicada', $duplicada);
        $de_siempre = $this->crear_categoria_real('La de siempre');
        $this->crear_subcategoria_real('Hija de la de siempre', $de_siempre);
        $this->crear_categoria_real('Borrada')->delete();
        $this->crear_categoria_real('Categoria del vecino', $this->vecino);

        $json = $this->crear_corrida_por_api([
            $this->propuestas_de_ejemplo()[0],
            ['clave' => 'mantener', 'tipo' => 'mantener', 'nombre' => 'Mantener mis categorias', 'descripcion' => 'Se queda lo que ya tenés.'],
        ]);

        $this->assertSame(['A', 'mantener'], array_column($json['propuestas'], 'clave'));
        $this->assertSame('mantener', $json['propuestas'][1]['tipo']);

        // El árbol de la respuesta: por nombre, con los ids de los NODOS (no los de las categorías reales).
        $arbol = $json['propuestas'][1]['categorias'];

        $this->assertSame(['Herrajes', 'Pinturas'], array_column($arbol, 'nombre'));
        $this->assertSame(['Bisagras', 'Cierres'], array_column($arbol[0]['subcategorias'], 'nombre'));
        $this->assertSame([], $arbol[1]['subcategorias']);

        $nodo_herrajes = CategoryProposalNode::find($arbol[0]['id']);
        $nodo_bisagras = CategoryProposalNode::find($arbol[0]['subcategorias'][0]['id']);
        $nodo_cierres  = CategoryProposalNode::find($arbol[0]['subcategorias'][1]['id']);
        $nodo_pinturas = CategoryProposalNode::find($arbol[1]['id']);

        // Cada nodo apunta a su categoría real; las subcategorías, además, a la de su padre.
        $this->assertSame((int) $herrajes->id, (int) $nodo_herrajes->existing_category_id);
        $this->assertNull($nodo_herrajes->existing_sub_category_id);
        $this->assertSame((int) $herrajes->id, (int) $nodo_bisagras->existing_category_id);
        $this->assertSame((int) $bisagras->id, (int) $nodo_bisagras->existing_sub_category_id);
        $this->assertSame((int) $herrajes->id, (int) $nodo_cierres->existing_category_id);
        $this->assertSame((int) $cierres->id, (int) $nodo_cierres->existing_sub_category_id);
        $this->assertSame((int) $pinturas->id, (int) $nodo_pinturas->existing_category_id);

        $this->assertSame('herrajes', $nodo_herrajes->clave_nombre);
        $this->assertSame((int) $nodo_herrajes->id, (int) $nodo_bisagras->parent_id);
        $this->assertNull($nodo_herrajes->parent_id);

        // 2 categorías + 2 subcategorías, ni un nodo más.
        $this->assertSame(4, CategoryProposalNode::where('proposal_id', $json['propuestas'][1]['id'])->count());

        // Y nada de lo real cambió.
        $this->assertSame(0, (int) $nodo_herrajes->real_creado);
        $this->assertNull($nodo_herrajes->real_category_id);
    }

    /**
     * "Mantener" puede ir sola en la corrida (una sola propuesta es válida), y no tiene mínimo de nodos.
     *
     * @group categorias_ia
     * @test
     */
    public function mantener_puede_ser_la_unica_propuesta_y_no_tiene_minimo_de_nodos()
    {
        $this->crear_categoria_real('Unica');

        $json = $this->crear_corrida_por_api([
            ['clave' => 'mantener', 'tipo' => 'mantener', 'nombre' => 'Mantener mis categorias'],
        ]);

        $this->assertCount(1, $json['propuestas']);
        $this->assertCount(1, $json['propuestas'][0]['categorias']);
        $this->assertSame('Unica', $json['propuestas'][0]['categorias'][0]['nombre']);
    }

    /**
     * Las cuatro tarjetas a la vez (A, B, C y mantener) son el máximo y se aceptan.
     *
     * @group categorias_ia
     * @test
     */
    public function las_cuatro_tarjetas_a_la_vez_se_aceptan()
    {
        $this->crear_categoria_real('Existente');

        $base = $this->propuestas_de_ejemplo()[0];

        $json = $this->crear_corrida_por_api([
            array_merge($base, ['clave' => 'A']),
            array_merge($base, ['clave' => 'B', 'nombre' => 'Segunda']),
            array_merge($base, ['clave' => 'C', 'nombre' => 'Tercera']),
            ['clave' => 'mantener', 'tipo' => 'mantener', 'nombre' => 'Mantener mis categorias'],
        ]);

        $this->assertSame(['A', 'B', 'C', 'mantener'], array_column($json['propuestas'], 'clave'));
        $this->assertSame([1, 2, 3, 4], CategoryProposal::where('run_id', $json['run_id'])->orderBy('orden')->pluck('orden')->map(function ($o) { return (int) $o; })->all());
    }

    // ------------------------------------------------------------------------------------------
    // Tenencia
    // ------------------------------------------------------------------------------------------

    /**
     * 🔴 La corrida se crea para el dueño que resuelve `admin-sync` (`app.USER_ID`), con sus artículos y sus
     * categorías: la otra cuenta no recibe nada, y una corrida del dueño no impide que el vecino cree la suya.
     *
     * @group categorias_ia
     * @test
     */
    public function la_corrida_es_del_dueno_de_user_id_y_no_se_mezcla_con_el_vecino()
    {
        $this->crear_articulos_en_masa(2);
        $this->crear_articulos_en_masa(5, $this->vecino, 'Vecino');
        $this->crear_categoria_real('Del dueno');

        $mia = $this->crear_corrida_por_api();

        $this->como_dueno_de_admin_sync($this->vecino);

        // El vecino puede crear la suya aunque yo tenga una en curso: son comercios distintos.
        $suya = $this->crear_corrida_por_api();

        $this->assertNotSame($mia['run_id'], $suya['run_id']);
        $this->assertSame((int) $this->owner->id, (int) CategoryProposalRun::find($mia['run_id'])->user_id);
        $this->assertSame((int) $this->vecino->id, (int) CategoryProposalRun::find($suya['run_id'])->user_id);
        $this->assertSame(2, (int) CategoryProposalRun::find($mia['run_id'])->articulos_total);
        $this->assertSame(5, (int) CategoryProposalRun::find($suya['run_id'])->articulos_total);

        // Todo lo que se escribió lleva el dueño correcto.
        foreach ([$mia['run_id'] => $this->owner, $suya['run_id'] => $this->vecino] as $run_id => $dueno) {

            $proposal_ids = CategoryProposal::where('run_id', $run_id)->pluck('id')->all();

            $this->assertSame(0, CategoryProposal::where('run_id', $run_id)->where('user_id', '<>', $dueno->id)->count());
            $this->assertSame(0, CategoryProposalNode::whereIn('proposal_id', $proposal_ids)->where('user_id', '<>', $dueno->id)->count());
        }

        // "Mantener" del vecino no ve la categoría del dueño.
        $this->post_admin('categorias/propuestas', $this->cuerpo([
            ['clave' => 'mantener', 'tipo' => 'mantener', 'nombre' => 'Mantener mis categorias'],
        ], true))->assertStatus(422);
    }
}
