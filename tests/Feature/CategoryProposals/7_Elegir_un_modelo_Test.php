<?php

namespace Tests\Feature\CategoryProposals;

use App\Http\Controllers\Helpers\category_proposal\CategoriaRealHelper;
use App\Http\Controllers\Helpers\category_proposal\CategoryProposalEscrituraHelper;
use App\Models\Category;
use App\Models\CategoryProposalItem;
use App\Models\CategoryProposalNode;
use App\Models\CategoryProposalRun;
use App\Models\SubCategory;
use App\Models\SyncToTNArticle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Elegir un sistema de categorías: `POST category-proposal-runs/{id}/elegir` (misión
 * categorizacion-tres-modelos, 5/10/2026). Contrato B §6.4 y plan §4.2.
 *
 * Es el único momento en que la categorización con IA toca `categories` y `articles`, y lo hace en
 * bloque: por eso este archivo mide, rama por rama, el dato que SOLO esa rama escribe (clase de error
 * "una escritura repartida por ramas se prueba con un test por rama", 5/9/2026):
 *  - sistema nuevo: crea y REUTILIZA por nombre normalizado (acentos, mayúsculas, espacios), con la más
 *    vieja ganando, sin tocar `La de siempre`, y sin crear las categorías que solo tienen dudosos;
 *  - lo seguro se asigna con categoría y subcategoría JUNTAS, lo dudoso va a "a revisar" y lo que la
 *    skill no pudo ubicar a "sin asignar", y esos artículos quedan SIN categoría (NULL, no 0);
 *  - cada ítem guarda lo que el artículo tenía antes (`prev_*`) y `updated_at` se mueve solo en lo que
 *    cambió;
 *  - "mantener": usa las categorías existentes, no crea nada y no pisa la que el artículo ya tiene;
 *  - eliminar las categorías anteriores que quedan vacías (solo en un sistema nuevo);
 *  - la regla (a): márgenes por categoría y Tienda Nube bloquean un sistema nuevo y "mantener" sigue andando;
 *  - tenencia: nada de lo de otro comercio se toca; y todo o nada: si algo falla, no queda nada a medias.
 *
 * El doble pedido, el empleado y el acceso maestro están en `10_Doble_pedido_y_tenencia_de_la_eleccion_Test`.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Elegir_un_modelo_Test extends CategoryProposalsTestCase
{
    use AyudasDeLaEleccion;

    /**
     * El sistema de ejemplo de casi todos los tests: cinco artículos y una propuesta A con
     * Bisagras (Comunes, De cierre suave) y Correderas. Tres ítems seguros, uno dudoso y uno sin ubicar.
     *
     * @return array  ['articulos' => [Article x5], 'run', 'proposal', 'items' => [Item x5], 'nodos', 'sembrado']
     */
    protected function sistema_basico()
    {
        $articulos = $this->crear_articulos([
            'Bisagra comun',
            'Bisagra de cierre suave',
            'Corredera telescopica',
            'Herraje raro',
            'Cosa sin nombre claro',
        ]);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'nombre' => 'Por rubro, como en góndola',
                    'arbol'  => ['Bisagras' => ['Comunes', 'De cierre suave'], 'Correderas' => []],
                    'items'  => [
                        [$articulos[0], 'Bisagras', 'Comunes', 'segura'],
                        [$articulos[1], 'Bisagras', 'De cierre suave', 'segura'],
                        [$articulos[2], 'Correderas', null, 'segura'],
                        [$articulos[3], 'Bisagras', null, 'dudosa', 'puede ser bisagra o herraje'],
                        [$articulos[4], null, null, 'ninguna', 'nombre ambiguo'],
                    ],
                ],
            ],
        ]);

        return [
            'articulos' => $articulos,
            'run'       => $sembrado['run'],
            'proposal'  => $sembrado['propuestas']['A']['proposal'],
            'items'     => $sembrado['propuestas']['A']['items'],
            'nodos'     => $sembrado['propuestas']['A']['nodos'],
            'sembrado'  => $sembrado,
        ];
    }

    /**
     * El estado de un ítem leído de la base.
     *
     * @param  \App\Models\CategoryProposalItem $item
     * @return string
     */
    protected function estado_de($item)
    {
        return CategoryProposalItem::find($item->id)->estado;
    }

    /**
     * Cuántas categorías vivas y cuántas subcategorías vivas tiene el comercio.
     *
     * @param  \App\Models\User|null $dueno
     * @return array  [categorias, subcategorias]
     */
    protected function cantidades_de_categorias($dueno = null)
    {
        $dueno = is_null($dueno) ? $this->owner : $dueno;

        return [
            Category::where('user_id', $dueno->id)->count(),
            SubCategory::where('user_id', $dueno->id)->count(),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Sistema nuevo: crear y asignar
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 El caso central: un sistema nuevo crea sus categorías y subcategorías, asigna SOLO lo seguro
     * (categoría y subcategoría juntas), deja lo dudoso en "a revisar" y lo que no se pudo ubicar en
     * "sin asignar" — esos dos con el artículo SIN categoría (NULL, no 0) — y deja atado cada nodo a su
     * categoría real.
     *
     * @test
     * @group categorias_ia
     */
    public function un_sistema_nuevo_crea_las_categorias_y_asigna_solo_lo_seguro()
    {
        $s = $this->sistema_basico();

        $respuesta = $this->pedir_elegir($s['run'], $s['proposal'], false);

        $respuesta->assertStatus(200)->assertJsonPath('ok', true);

        $esperado = [
            'categorias_creadas'      => 2,
            'categorias_reutilizadas' => 0,
            'subcategorias_creadas'   => 2,
            'articulos_asignados'     => 3,
            'a_revisar'               => 1,
            'sin_asignar'             => 1,
            'pierden_categoria'       => 0,
            'categorias_eliminadas'   => 0,
        ];
        $this->assertSame($esperado, $respuesta->json('resultado'));
        $this->assertSame([2, 2], $this->cantidades_de_categorias());

        $bisagras   = $this->categorias_llamadas('Bisagras')->first();
        $correderas = $this->categorias_llamadas('Correderas')->first();
        $comunes    = SubCategory::where('category_id', $bisagras->id)->where('name', 'Comunes')->first();
        $suave      = SubCategory::where('category_id', $bisagras->id)->where('name', 'De cierre suave')->first();

        $this->assertNotNull($bisagras);
        $this->assertNotNull($correderas);
        $this->assertNotNull($comunes);
        $this->assertNotNull($suave);
        $this->assertSame($this->owner->id, (int) $bisagras->user_id, 'Las categorías creadas son del dueño.');
        $this->assertSame($this->owner->id, (int) $comunes->user_id);

        // Lo seguro: categoría y subcategoría juntas.
        $this->assertSame(['category_id' => $bisagras->id, 'sub_category_id' => $comunes->id], $this->categorias_de($s['articulos'][0]));
        $this->assertSame(['category_id' => $bisagras->id, 'sub_category_id' => $suave->id], $this->categorias_de($s['articulos'][1]));
        $this->assertSame(['category_id' => $correderas->id, 'sub_category_id' => null], $this->categorias_de($s['articulos'][2]));

        // Lo dudoso y lo que no se pudo ubicar: sin categoría, y NULL (no 0).
        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($s['articulos'][3]));
        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($s['articulos'][4]));

        // Los ítems pasan al estado que corresponde.
        $this->assertSame('aplicada', $this->estado_de($s['items'][0]));
        $this->assertSame('aplicada', $this->estado_de($s['items'][1]));
        $this->assertSame('aplicada', $this->estado_de($s['items'][2]));
        $this->assertSame('a_revisar', $this->estado_de($s['items'][3]));
        $this->assertSame('sin_asignar', $this->estado_de($s['items'][4]));

        // Cada nodo queda atado a su categoría real, y creado por este aplicar.
        $nodo_bisagras = CategoryProposalNode::find($s['nodos']['Bisagras']->id);
        $this->assertSame($bisagras->id, (int) $nodo_bisagras->real_category_id);
        $this->assertTrue($nodo_bisagras->real_creado);
        $nodo_comunes = CategoryProposalNode::find($s['nodos']['Bisagras/Comunes']->id);
        $this->assertSame($bisagras->id, (int) $nodo_comunes->real_category_id);
        $this->assertSame($comunes->id, (int) $nodo_comunes->real_sub_category_id);
        $this->assertTrue($nodo_comunes->real_creado);

        // La corrida queda elegida, con quién y cuándo, y el resumen guardado.
        $run = CategoryProposalRun::find($s['run']->id);
        $this->assertSame('elegida', $run->estado);
        $this->assertSame($s['proposal']->id, (int) $run->propuesta_elegida_id);
        $this->assertSame($this->owner->id, (int) $run->elegida_por);
        $this->assertFalse($run->elegida_con_acceso_maestro);
        $this->assertNotNull($run->elegida_at);
        $this->assertSame($esperado, $run->resultado);
        $this->assertNull($run->revision_iniciada_at, 'Todavía nadie revisó nada.');
    }

    /**
     * Las categorías que ya tenía el comercio se REUTILIZAN por nombre normalizado (sin acentos, sin
     * mayúsculas, con los espacios colapsados): no se duplica ni la categoría ni la subcategoría.
     *
     * @test
     * @group categorias_ia
     */
    public function reutiliza_por_nombre_normalizado_sin_duplicar()
    {
        $articulo = $this->crear_articulo('Bisagra comun');
        $otro     = $this->crear_articulo('Cerradura');
        $ferreteria = $this->categoria_real('Ferretería');
        $bisagras   = $this->subcategoria_real('Bisagras', $ferreteria);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    // El mismo nombre, escrito distinto: sin tilde, en mayúsculas, con espacios de más. Y una
                    // subcategoría que no existe, para ver que se crea COLGADA de la categoría reutilizada.
                    'arbol' => ['  FERRETERIA ' => ['bisagras', 'Cerraduras']],
                    'items' => [
                        [$articulo, '  FERRETERIA ', 'bisagras', 'segura'],
                        [$otro, '  FERRETERIA ', 'Cerraduras', 'segura'],
                    ],
                ],
            ],
        ]);

        $respuesta = $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal'], false);

        $respuesta->assertStatus(200);
        $this->assertSame([1, 2], $this->cantidades_de_categorias(), 'La categoría y Bisagras se reutilizaron; solo se creó Cerraduras.');
        $this->assertSame(0, $respuesta->json('resultado.categorias_creadas'));
        $this->assertSame(1, $respuesta->json('resultado.categorias_reutilizadas'));
        $this->assertSame(1, $respuesta->json('resultado.subcategorias_creadas'));
        $this->assertSame(['category_id' => $ferreteria->id, 'sub_category_id' => $bisagras->id], $this->categorias_de($articulo));

        $cerraduras = SubCategory::where('user_id', $this->owner->id)->where('name', 'Cerraduras')->first();
        $this->assertSame($ferreteria->id, (int) $cerraduras->category_id, 'Se creó colgada de la categoría que ya existía.');
        $this->assertSame(['category_id' => $ferreteria->id, 'sub_category_id' => $cerraduras->id], $this->categorias_de($otro));

        $nodos = $sembrado['propuestas']['A']['nodos'];
        $this->assertFalse(CategoryProposalNode::find($nodos['  FERRETERIA ']->id)->real_creado, 'La reutilizó, no la creó.');
        $this->assertFalse(CategoryProposalNode::find($nodos['  FERRETERIA /bisagras']->id)->real_creado);
        $this->assertTrue(CategoryProposalNode::find($nodos['  FERRETERIA /Cerraduras']->id)->real_creado);
    }

    /**
     * Con categorías repetidas por nombre en el catálogo del dueño gana la MÁS VIEJA (menor id).
     *
     * @test
     * @group categorias_ia
     */
    public function con_categorias_repetidas_por_nombre_gana_la_mas_vieja()
    {
        $articulo = $this->crear_articulo('Tornillo');
        $vieja    = $this->categoria_real('Tornillos');
        $nueva    = $this->categoria_real('TORNILLOS ');

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Tornillos' => []],
                    'items' => [[$articulo, 'Tornillos', null, 'segura']],
                ],
            ],
        ]);

        $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal'])->assertStatus(200);

        $this->assertSame($vieja->id, $this->categorias_de($articulo)['category_id']);
        $this->assertLessThan($nueva->id, $vieja->id);
        $this->assertSame([2, 0], $this->cantidades_de_categorias(), 'No se creó una tercera.');
    }

    /**
     * Una subcategoría se identifica por categoría Y nombre: el mismo nombre bajo OTRA categoría es otra
     * subcategoría, y no se reutiliza.
     *
     * @test
     * @group categorias_ia
     */
    public function la_misma_subcategoria_bajo_otra_categoria_es_otra_subcategoria()
    {
        $articulo = $this->crear_articulo('Bisagra comun');
        $otra     = $this->categoria_real('Otra');
        $de_otra  = $this->subcategoria_real('Comunes', $otra);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Bisagras' => ['Comunes']],
                    'items' => [[$articulo, 'Bisagras', 'Comunes', 'segura']],
                ],
            ],
        ]);

        $respuesta = $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal']);

        $respuesta->assertStatus(200);
        $this->assertSame(1, $respuesta->json('resultado.subcategorias_creadas'));

        $bisagras = $this->categorias_llamadas('Bisagras')->first();
        $nueva    = SubCategory::where('category_id', $bisagras->id)->where('name', 'Comunes')->first();

        $this->assertNotNull($nueva);
        $this->assertNotSame($de_otra->id, $nueva->id);
        $this->assertSame(['category_id' => $bisagras->id, 'sub_category_id' => $nueva->id], $this->categorias_de($articulo));
    }

    /**
     * 🔴 Nunca se crea ni se reutiliza la categoría `La de siempre`: la tienda la esconde por nombre,
     * así que un artículo ahí desaparece del catálogo público. La ingesta ya la rechaza; si igual llegara
     * un nodo con ese nombre, el aplicar no lo usa y deja el ítem sin asignar.
     *
     * @test
     * @group categorias_ia
     */
    public function nunca_crea_ni_reutiliza_la_de_siempre()
    {
        $articulo = $this->crear_articulo('Cosa');
        $de_siempre = $this->categoria_real('La de siempre');

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['La de siempre' => []],
                    'items' => [[$articulo, 'La de siempre', null, 'segura']],
                ],
            ],
        ]);

        $respuesta = $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal']);

        $respuesta->assertStatus(200);
        $this->assertSame(1, $this->categorias_llamadas('La de siempre')->count(), 'No se creó otra.');
        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($articulo), 'Y no recibió el artículo.');
        $this->assertSame('sin_asignar', $this->estado_de($sembrado['propuestas']['A']['items'][0]));
        $this->assertSame(1, $respuesta->json('resultado.sin_asignar'));
        $this->assertSame(0, $respuesta->json('resultado.categorias_creadas'));
        $this->assertSame(0, $respuesta->json('resultado.categorias_reutilizadas'));
        $this->assertSame($de_siempre->id, $this->categorias_llamadas('La de siempre')->first()->id);

        // Y sin una previa, tampoco se crea (el vecino no tiene ninguna).
        $otro = $this->crear_articulo('Otra cosa', [], $this->vecino);
        $segundo = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['LA  DE SIEMPRE' => []],
                    'items' => [[$otro, 'LA  DE SIEMPRE', null, 'segura']],
                ],
            ],
        ], $this->vecino);

        $this->actuar_como($this->vecino);
        $this->pedir_elegir($segundo['run'], $segundo['propuestas']['A']['proposal'])->assertStatus(200);
        $this->assertSame(0, Category::where('user_id', $this->vecino->id)->count(), 'No se crea `La de siempre`.');
    }

    /**
     * Las categorías y subcategorías que solo tienen ítems DUDOSOS no se crean al elegir: se crean al
     * aprobar el primero. El menú de la tienda no esconde las categorías vacías y mostraría "0 prod.".
     *
     * @test
     * @group categorias_ia
     */
    public function los_nodos_que_solo_tienen_dudosos_no_crean_categoria()
    {
        $articulos = $this->crear_articulos(['Bisagra comun', 'Bisagra rara', 'Cosa de fijacion']);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Bisagras' => ['Comunes', 'Raras'], 'Fijaciones' => []],
                    'items' => [
                        [$articulos[0], 'Bisagras', 'Comunes', 'segura'],
                        [$articulos[1], 'Bisagras', 'Raras', 'dudosa'],
                        [$articulos[2], 'Fijaciones', null, 'dudosa'],
                    ],
                ],
            ],
        ]);

        $respuesta = $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal']);

        $respuesta->assertStatus(200);
        $this->assertSame(1, $respuesta->json('resultado.categorias_creadas'));
        $this->assertSame(1, $respuesta->json('resultado.subcategorias_creadas'));
        $this->assertSame(2, $respuesta->json('resultado.a_revisar'));

        $this->assertSame(1, $this->categorias_llamadas('Bisagras')->count());
        $this->assertSame(0, $this->categorias_llamadas('Fijaciones')->count(), 'Un nodo con solo dudosos no crea la categoría.');
        $this->assertSame(0, SubCategory::where('user_id', $this->owner->id)->where('name', 'Raras')->count(), 'Ni la subcategoría.');
        $this->assertSame([1, 1], $this->cantidades_de_categorias());

        // Y esos nodos quedan sin atar a nada real.
        $nodo_fijaciones = CategoryProposalNode::find($sembrado['propuestas']['A']['nodos']['Fijaciones']->id);
        $this->assertNull($nodo_fijaciones->real_category_id);
        $this->assertFalse($nodo_fijaciones->real_creado);
        $nodo_raras = CategoryProposalNode::find($sembrado['propuestas']['A']['nodos']['Bisagras/Raras']->id);
        $this->assertNull($nodo_raras->real_sub_category_id);
    }

    /**
     * Cada ítem guarda lo que tenía su artículo ANTES de escribir (`prev_*`): una categoría viva, el 0 que
     * deja el borrado de una categoría, NULL y una categoría que ya está en la papelera. Y `updated_at` (el
     * reloj del sync incremental de la SPA y de los embeddings) se mueve solo en los artículos que cambiaron:
     * el que ya estaba donde tiene que estar no se reescribe.
     *
     * @test
     * @group categorias_ia
     */
    public function guarda_lo_anterior_de_cada_articulo_y_toca_updated_at_solo_de_lo_que_cambia()
    {
        $vieja     = $this->categoria_real('Vieja');
        $sub_vieja = $this->subcategoria_real('Sub vieja', $vieja);
        $borrada   = $this->categoria_real('Borrada');
        $borrada->delete();
        $ya_esta   = $this->categoria_real('Bisagras');

        $a1 = $this->crear_articulo('a1', ['category_id' => $vieja->id, 'sub_category_id' => $sub_vieja->id]);
        $a2 = $this->crear_articulo('a2', ['category_id' => 0, 'sub_category_id' => 0]);
        $a3 = $this->crear_articulo('a3');
        $a4 = $this->crear_articulo('a4', ['category_id' => $borrada->id]);
        $a5 = $this->crear_articulo('a5', ['category_id' => $ya_esta->id]);
        $this->envejecer([$a1, $a2, $a3, $a4, $a5]);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Bisagras' => ['Comunes'], 'Correderas' => []],
                    'items' => [
                        [$a1, 'Bisagras', 'Comunes', 'segura'],
                        [$a2, 'Bisagras', 'Comunes', 'segura'],
                        [$a3, 'Correderas', null, 'segura'],
                        [$a4, 'Correderas', null, 'segura'],
                        [$a5, 'Bisagras', null, 'segura'],
                    ],
                ],
            ],
        ]);

        $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal'])->assertStatus(200);

        $items = $sembrado['propuestas']['A']['items'];
        $prev  = function ($item) {
            $fila = CategoryProposalItem::find($item->id);

            return [
                is_null($fila->prev_category_id) ? null : (int) $fila->prev_category_id,
                is_null($fila->prev_sub_category_id) ? null : (int) $fila->prev_sub_category_id,
            ];
        };

        $this->assertSame([$vieja->id, $sub_vieja->id], $prev($items[0]));
        $this->assertSame([0, 0], $prev($items[1]), 'El 0 que deja un borrado se guarda tal cual.');
        $this->assertSame([null, null], $prev($items[2]));
        $this->assertSame([$borrada->id, null], $prev($items[3]), 'Una categoría en la papelera también se guarda tal cual.');
        $this->assertSame([$ya_esta->id, null], $prev($items[4]));

        foreach ([0, 1, 2, 3, 4] as $i) {
            $this->assertSame('aplicada', $this->estado_de($items[$i]));
        }

        // `updated_at`: se movió en los que cambiaron...
        foreach ([$a1, $a2, $a3, $a4] as $articulo) {
            $this->assertNotSame('2020-01-01 00:00:00', $this->actualizado_el($articulo), 'Se tocó el updated_at de '.$articulo->name);
        }

        // ...y no en el que ya estaba en su destino.
        $this->assertSame('2020-01-01 00:00:00', $this->actualizado_el($a5), 'El que ya estaba en su destino no se reescribe.');
    }

    /**
     * 🔴 Se escriben los DOS campos juntos: un artículo que tenía categoría y subcategoría y recibe una
     * categoría sin subcategoría queda con la subcategoría en NULL (no con la vieja, que cuelga de otra
     * categoría); y uno con una subcategoría que no era de su categoría (dato que el sistema ya sufre) se
     * corrige.
     *
     * @test
     * @group categorias_ia
     */
    public function escribe_categoria_y_subcategoria_juntas()
    {
        $vieja     = $this->categoria_real('Vieja');
        $sub_vieja = $this->subcategoria_real('Sub vieja', $vieja);
        $otra      = $this->categoria_real('Otra');
        $de_otra   = $this->subcategoria_real('Sub de otra', $otra);

        $a1 = $this->crear_articulo('a1', ['category_id' => $vieja->id, 'sub_category_id' => $sub_vieja->id]);
        // Inconsistente: su subcategoría es de OTRA categoría que la suya.
        $a2 = $this->crear_articulo('a2', ['category_id' => $vieja->id, 'sub_category_id' => $de_otra->id]);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Correderas' => [], 'Bisagras' => ['Comunes']],
                    'items' => [
                        [$a1, 'Correderas', null, 'segura'],
                        [$a2, 'Bisagras', 'Comunes', 'segura'],
                    ],
                ],
            ],
        ]);

        $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal'])->assertStatus(200);

        $correderas = $this->categorias_llamadas('Correderas')->first();
        $bisagras   = $this->categorias_llamadas('Bisagras')->first();
        $comunes    = SubCategory::where('category_id', $bisagras->id)->where('name', 'Comunes')->first();

        $this->assertSame(['category_id' => $correderas->id, 'sub_category_id' => null], $this->categorias_de($a1));
        $this->assertSame(['category_id' => $bisagras->id, 'sub_category_id' => $comunes->id], $this->categorias_de($a2));
    }

    /**
     * En un sistema NUEVO, lo dudoso y lo que no se pudo ubicar deja al artículo SIN categoría (NULL) y,
     * si tenía una viva, la pierde y se cuenta en `pierden_categoria`. Uno que ya estaba sin categoría
     * (el 0 de un borrado) no se toca ni se cuenta.
     *
     * @test
     * @group categorias_ia
     */
    public function en_un_sistema_nuevo_dudosos_y_ninguna_dejan_al_articulo_sin_categoria()
    {
        $vieja = $this->categoria_real('Vieja');

        $a1 = $this->crear_articulo('a1', ['category_id' => $vieja->id]);
        $a2 = $this->crear_articulo('a2', ['category_id' => $vieja->id]);
        $a3 = $this->crear_articulo('a3');
        $a4 = $this->crear_articulo('a4');
        $a5 = $this->crear_articulo('a5', ['category_id' => 0, 'sub_category_id' => 0]);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Bisagras' => []],
                    'items' => [
                        [$a1, 'Bisagras', null, 'dudosa', 'no queda claro'],
                        [$a2, null, null, 'ninguna', 'nombre ambiguo'],
                        [$a3, 'Bisagras', null, 'segura'],
                        [$a4, null, null, 'ninguna'],
                        [$a5, 'Bisagras', null, 'dudosa'],
                    ],
                ],
            ],
        ]);

        $respuesta = $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal']);

        $respuesta->assertStatus(200);
        $this->assertSame(1, $respuesta->json('resultado.articulos_asignados'));
        $this->assertSame(2, $respuesta->json('resultado.a_revisar'));
        $this->assertSame(2, $respuesta->json('resultado.sin_asignar'));
        $this->assertSame(2, $respuesta->json('resultado.pierden_categoria'), 'Solo a1 y a2 tenían una categoría viva.');

        $sin_categoria = ['category_id' => null, 'sub_category_id' => null];
        $this->assertSame($sin_categoria, $this->categorias_de($a1));
        $this->assertSame($sin_categoria, $this->categorias_de($a2));
        $this->assertSame(['category_id' => 0, 'sub_category_id' => 0], $this->categorias_de($a5), 'Ya estaba sin categoría: no se toca.');

        // Lo que tenían antes queda guardado para poder volver atrás.
        $fila = CategoryProposalItem::find($sembrado['propuestas']['A']['items'][0]->id);
        $this->assertSame($vieja->id, (int) $fila->prev_category_id);
        $this->assertSame('a_revisar', $fila->estado);
    }

    // ---------------------------------------------------------------------------------------------
    // "Mantener las mías"
    // ---------------------------------------------------------------------------------------------

    /**
     * La propuesta "mantener" arma sus nodos con las categorías que el dueño ya tiene: al elegirla no
     * se crea NADA, solo se completan los artículos sin categoría.
     *
     * @return array  ['articulos', 'run', 'proposal', 'items', 'herrajes', 'bisagras', 'fijaciones']
     */
    protected function sistema_mantener()
    {
        $herrajes   = $this->categoria_real('Herrajes');
        $bisagras   = $this->subcategoria_real('Bisagras', $herrajes);
        $fijaciones = $this->categoria_real('Fijaciones');

        $articulos = $this->crear_articulos(['Bisagra comun', 'Tornillo', 'Herraje raro', 'Sin nombre']);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'mantener' => [
                    'tipo'   => 'mantener',
                    'nombre' => 'Mantener mis categorías',
                    'arbol'  => [
                        'Herrajes'   => ['subs' => ['Bisagras'], 'existing_category_id' => $herrajes->id, 'existing_subs' => ['Bisagras' => $bisagras->id]],
                        'Fijaciones' => ['subs' => [], 'existing_category_id' => $fijaciones->id],
                    ],
                    'items'  => [
                        [$articulos[0], 'Herrajes', 'Bisagras', 'segura'],
                        [$articulos[1], 'Fijaciones', null, 'segura'],
                        [$articulos[2], 'Herrajes', null, 'dudosa', 'no queda claro'],
                        [$articulos[3], null, null, 'ninguna'],
                    ],
                ],
            ],
        ]);

        return [
            'articulos'  => $articulos,
            'run'        => $sembrado['run'],
            'proposal'   => $sembrado['propuestas']['mantener']['proposal'],
            'items'      => $sembrado['propuestas']['mantener']['items'],
            'nodos'      => $sembrado['propuestas']['mantener']['nodos'],
            'herrajes'   => $herrajes,
            'bisagras'   => $bisagras,
            'fijaciones' => $fijaciones,
        ];
    }

    /**
     * "Mantener" usa las categorías existentes: no crea ninguna categoría ni subcategoría, asigna lo
     * seguro con la categoría real de cada nodo y no toca lo demás (esos artículos ya estaban sin categoría).
     *
     * @test
     * @group categorias_ia
     */
    public function mantener_usa_las_categorias_existentes_y_no_crea_nada()
    {
        $s = $this->sistema_mantener();
        $antes = $this->cantidades_de_categorias();

        $respuesta = $this->pedir_elegir($s['run'], $s['proposal']);

        $respuesta->assertStatus(200);
        $this->assertSame($antes, $this->cantidades_de_categorias(), 'No se crea nada.');
        $this->assertSame([
            'categorias_creadas'      => 0,
            'categorias_reutilizadas' => 2,
            'subcategorias_creadas'   => 0,
            'articulos_asignados'     => 2,
            'a_revisar'               => 1,
            'sin_asignar'             => 1,
            'pierden_categoria'       => 0,
            'categorias_eliminadas'   => 0,
        ], $respuesta->json('resultado'));

        $this->assertSame(['category_id' => $s['herrajes']->id, 'sub_category_id' => $s['bisagras']->id], $this->categorias_de($s['articulos'][0]));
        $this->assertSame(['category_id' => $s['fijaciones']->id, 'sub_category_id' => null], $this->categorias_de($s['articulos'][1]));
        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($s['articulos'][2]));
        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($s['articulos'][3]));

        // Los nodos quedan atados a las categorías de siempre, y ninguna la creó este aplicar.
        $nodo = CategoryProposalNode::find($s['nodos']['Herrajes']->id);
        $this->assertSame($s['herrajes']->id, (int) $nodo->real_category_id);
        $this->assertFalse($nodo->real_creado);
    }

    /**
     * 🔴 "Mantener" COMPLETA, no pisa: un artículo que ya tiene una categoría viva (alguien se la puso
     * después de que la skill clasificó) no se toca; su ítem queda "a revisar" para que decida el dueño.
     *
     * @test
     * @group categorias_ia
     */
    public function mantener_no_pisa_la_categoria_que_el_articulo_ya_tiene()
    {
        $s = $this->sistema_mantener();

        // Después de la ingesta, alguien le puso a mano la categoría Fijaciones al artículo del primer ítem
        // (que la skill había ubicado en Herrajes / Bisagras).
        DB::table('articles')->where('id', $s['articulos'][0]->id)->update(['category_id' => $s['fijaciones']->id]);

        $respuesta = $this->pedir_elegir($s['run'], $s['proposal']);

        $respuesta->assertStatus(200);
        $this->assertSame(['category_id' => $s['fijaciones']->id, 'sub_category_id' => null], $this->categorias_de($s['articulos'][0]), 'La categoría que ya tenía se respeta.');
        $this->assertSame('a_revisar', $this->estado_de($s['items'][0]));
        $this->assertSame('aplicada', $this->estado_de($s['items'][1]));
        $this->assertSame(1, $respuesta->json('resultado.articulos_asignados'));
        $this->assertSame(2, $respuesta->json('resultado.a_revisar'), 'El dudoso y el que ya tenía categoría.');

        // Lo que tenía queda guardado en su ítem.
        $this->assertSame($s['fijaciones']->id, (int) CategoryProposalItem::find($s['items'][0]->id)->prev_category_id);
    }

    /**
     * Una categoría existente que ya no existe (la borraron después de la ingesta) o que es de OTRO
     * comercio no se usa jamás: sus ítems quedan "sin asignar" y los artículos no se tocan. Todo `*_id`
     * que termina escrito se verifica contra el dueño.
     *
     * @test
     * @group categorias_ia
     */
    public function mantener_con_una_categoria_borrada_o_ajena_deja_los_items_sin_asignar()
    {
        $borrada = $this->categoria_real('Borrada');
        $ajena   = $this->categoria_real('Ajena', $this->vecino);
        $propia  = $this->categoria_real('Propia');
        $sub_de_otra = $this->subcategoria_real('Sub de otra', $propia);

        $articulos = $this->crear_articulos(['a1', 'a2', 'a3', 'a4']);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'mantener' => [
                    'tipo'  => 'mantener',
                    'arbol' => [
                        'Borrada' => ['subs' => [], 'existing_category_id' => $borrada->id],
                        'Ajena'   => ['subs' => [], 'existing_category_id' => $ajena->id],
                        'Propia'  => ['subs' => ['Sub fantasma'], 'existing_category_id' => $propia->id, 'existing_subs' => ['Sub fantasma' => 999999]],
                        'Otra'    => ['subs' => ['Sub de otra'], 'existing_category_id' => $this->categoria_real('Otra propia')->id, 'existing_subs' => ['Sub de otra' => $sub_de_otra->id]],
                    ],
                    'items' => [
                        [$articulos[0], 'Borrada', null, 'segura'],
                        [$articulos[1], 'Ajena', null, 'segura'],
                        [$articulos[2], 'Propia', 'Sub fantasma', 'segura'],
                        [$articulos[3], 'Otra', 'Sub de otra', 'segura'],
                    ],
                ],
            ],
        ]);

        $borrada->delete();

        $respuesta = $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['mantener']['proposal']);

        $respuesta->assertStatus(200);
        $this->assertSame(0, $respuesta->json('resultado.articulos_asignados'));
        $this->assertSame(4, $respuesta->json('resultado.sin_asignar'));
        // Las categorías de `Propia` y `Otra propia` sí se resolvieron; lo que no se resolvió es lo demás.
        $this->assertSame(2, $respuesta->json('resultado.categorias_reutilizadas'));

        foreach ($articulos as $articulo) {
            $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($articulo), $articulo->name.' no se toca.');
        }

        foreach ($sembrado['propuestas']['mantener']['items'] as $item) {
            $this->assertSame('sin_asignar', $this->estado_de($item));
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Eliminar las categorías anteriores que queden vacías
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 `eliminar_categorias_vacias`: a la papelera van SOLO las categorías y subcategorías del dueño que no
     * son del sistema elegido y que quedan sin artículos vivos (ni en la categoría ni en sus
     * subcategorías). Se conservan las que siguen teniendo artículos aunque no estén en la propuesta, las
     * subcategorías con artículos de una categoría que se conserva, `La de siempre` y las del sistema.
     *
     * @test
     * @group categorias_ia
     */
    public function eliminar_categorias_vacias_manda_a_la_papelera_solo_las_que_quedan_vacias()
    {
        $v1 = $this->categoria_real('V1 vacia');
        $v2 = $this->categoria_real('V2 con articulo suelto');
        $v3 = $this->categoria_real('V3 con subcategorias');
        $s3a = $this->subcategoria_real('S3a vacia', $v3);
        $s3b = $this->subcategoria_real('S3b con articulo', $v3);
        $v4 = $this->categoria_real('V4 vacia con sub');
        $s4 = $this->subcategoria_real('S4 vacia', $v4);
        $de_siempre = $this->categoria_real('La de siempre');
        $v5 = $this->categoria_real('V5 que se va a vaciar');

        // Artículos que NO están en la propuesta y se quedan donde estaban.
        $this->crear_articulo('suelto en V2', ['category_id' => $v2->id]);
        // Este lleva la subcategoría pero no la categoría (el sistema permite ese dato): V3 no tiene artículos
        // propios, y se conserva igual porque una de sus subcategorías sí.
        $this->crear_articulo('suelto en S3b', ['category_id' => null, 'sub_category_id' => $s3b->id]);
        // El que sí está en la propuesta: sale de V5 hacia Bisagras, y V5 queda vacía.
        $z = $this->crear_articulo('z', ['category_id' => $v5->id]);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Bisagras' => []],
                    'items' => [[$z, 'Bisagras', null, 'segura']],
                ],
            ],
        ]);

        $respuesta = $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal'], true);

        $respuesta->assertStatus(200);
        $this->assertSame(3, $respuesta->json('resultado.categorias_eliminadas'), 'V1, V4 y V5.');

        $en_la_papelera = Category::onlyTrashed()->where('user_id', $this->owner->id)->pluck('id')->sort()->values()->all();
        $this->assertSame([$v1->id, $v4->id, $v5->id], $en_la_papelera);

        $subs_en_la_papelera = SubCategory::onlyTrashed()->where('user_id', $this->owner->id)->pluck('id')->sort()->values()->all();
        $this->assertSame([$s3a->id, $s4->id], $subs_en_la_papelera, 'La vacía de una categoría que se conserva y la de una que se elimina.');

        // Siguen vivas: con artículos, con subcategorías con artículos, La de siempre y la creada.
        foreach ([$v2, $v3, $de_siempre] as $categoria) {
            $this->assertNotNull(Category::find($categoria->id), $categoria->name.' se conserva.');
        }
        $this->assertNotNull(SubCategory::find($s3b->id));
        $this->assertSame(1, $this->categorias_llamadas('Bisagras')->count());

        // Lo que se mandó a la papelera queda anotado en la corrida, para poder volver atrás.
        $anotado = CategoryProposalRun::find($sembrado['run']->id)->categorias_eliminadas;
        $esperado = [
            ['tipo' => 'subcategoria', 'id' => $s3a->id],
            ['tipo' => 'subcategoria', 'id' => $s4->id],
            ['tipo' => 'categoria', 'id' => $v1->id],
            ['tipo' => 'categoria', 'id' => $v4->id],
            ['tipo' => 'categoria', 'id' => $v5->id],
        ];
        $ordenar = function (array $filas) {
            usort($filas, function ($a, $b) {
                return strcmp($a['tipo'].'-'.str_pad($a['id'], 12, '0', STR_PAD_LEFT), $b['tipo'].'-'.str_pad($b['id'], 12, '0', STR_PAD_LEFT));
            });

            return $filas;
        };
        $this->assertSame($ordenar($esperado), $ordenar($anotado));
        $this->assertTrue(CategoryProposalRun::find($sembrado['run']->id)->eliminar_categorias_vacias);
    }

    /**
     * Sin pedirlo no se elimina nada, y en "mantener" tampoco aunque se pida: ahí las categorías son las
     * del dueño y se conservan todas.
     *
     * @test
     * @group categorias_ia
     */
    public function sin_pedirlo_o_en_mantener_no_se_elimina_ninguna_categoria()
    {
        // Sistema nuevo sin pedir eliminar: la categoría vacía se conserva.
        $vacia = $this->categoria_real('Vacia');
        $a1    = $this->crear_articulo('a1');

        $primero = $this->sembrar_corrida([
            'propuestas' => ['A' => ['arbol' => ['Bisagras' => []], 'items' => [[$a1, 'Bisagras', null, 'segura']]]],
        ]);

        $this->pedir_elegir($primero['run'], $primero['propuestas']['A']['proposal'], false)->assertStatus(200);

        $this->assertNotNull(Category::find($vacia->id), 'No se pidió eliminar.');
        $this->assertSame(0, Category::onlyTrashed()->where('user_id', $this->owner->id)->count());
        $this->assertNull(CategoryProposalRun::find($primero['run']->id)->categorias_eliminadas);
        $this->assertFalse(CategoryProposalRun::find($primero['run']->id)->eliminar_categorias_vacias);

        // "Mantener" pidiendo eliminar: en el comercio del vecino, una categoría que es la del nodo y otra vacía
        // que no está en la propuesta. Ninguna se elimina.
        $del_nodo = $this->categoria_real('Del nodo', $this->vecino);
        $otra_vacia = $this->categoria_real('Otra vacia', $this->vecino);
        $a2 = $this->crear_articulo('a2', [], $this->vecino);

        $segundo = $this->sembrar_corrida([
            'propuestas' => [
                'mantener' => [
                    'tipo'  => 'mantener',
                    'arbol' => ['Del nodo' => ['subs' => [], 'existing_category_id' => $del_nodo->id]],
                    'items' => [[$a2, 'Del nodo', null, 'segura']],
                ],
            ],
        ], $this->vecino);

        $this->actuar_como($this->vecino);
        $respuesta = $this->pedir_elegir($segundo['run'], $segundo['propuestas']['mantener']['proposal'], true);

        $respuesta->assertStatus(200);
        $this->assertSame(0, $respuesta->json('resultado.categorias_eliminadas'));
        $this->assertSame(0, Category::onlyTrashed()->where('user_id', $this->vecino->id)->count());
        $this->assertNotNull(Category::find($otra_vacia->id), 'En "mantener" las categorías del dueño se conservan todas.');
        $this->assertFalse(CategoryProposalRun::find($segundo['run']->id)->eliminar_categorias_vacias, 'La corrida no queda marcada como que lo pidió.');
    }

    // ---------------------------------------------------------------------------------------------
    // Tenencia y alcance
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Nada de otro comercio se toca. Un ítem que apunta a un artículo ajeno (dato que no debería
     * existir) no lo escribe y queda sin procesar; y eliminar las categorías vacías mira SOLO los artículos
     * del dueño: el artículo del vecino con el mismo id de categoría conserva su `category_id`.
     *
     * @test
     * @group categorias_ia
     */
    public function los_articulos_de_otro_comercio_no_se_tocan()
    {
        $vacia = $this->categoria_real('Vacia para el dueno');
        $propio = $this->crear_articulo('Propio');

        // Un artículo del vecino que (dato inconsistente) apunta a la categoría del dueño.
        $del_vecino = $this->crear_articulo('Del vecino', ['category_id' => $vacia->id], $this->vecino);
        // Otro del vecino, sin categoría, al que un ítem del dueño apunta por error.
        $ajeno_sin_categoria = $this->crear_articulo('Ajeno sin categoria', [], $this->vecino);
        $this->envejecer([$del_vecino, $ajeno_sin_categoria]);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Bisagras' => []],
                    'items' => [
                        [$propio, 'Bisagras', null, 'segura'],
                        [$ajeno_sin_categoria, 'Bisagras', null, 'segura'],
                    ],
                ],
            ],
        ]);

        $respuesta = $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal'], true);

        $respuesta->assertStatus(200);
        $this->assertSame(1, $respuesta->json('resultado.articulos_asignados'), 'Solo el propio.');

        // El artículo ajeno ni se escribió ni cambió su ítem.
        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($ajeno_sin_categoria));
        $this->assertSame('propuesta', $this->estado_de($sembrado['propuestas']['A']['items'][1]));
        $this->assertSame('2020-01-01 00:00:00', $this->actualizado_el($ajeno_sin_categoria));

        // La categoría vacía PARA EL DUEÑO se mandó a la papelera, y el artículo del vecino no se enteró.
        $this->assertNull(Category::find($vacia->id));
        $this->assertSame($vacia->id, $this->categorias_de($del_vecino)['category_id']);
        $this->assertSame('2020-01-01 00:00:00', $this->actualizado_el($del_vecino));
    }

    /**
     * 🔴 Los artículos que no están en la propuesta quedan EXACTAMENTE como estaban (valores y
     * `updated_at`), y `articulos_total` de la corrida no cambia.
     *
     * @test
     * @group categorias_ia
     */
    public function los_articulos_fuera_de_la_propuesta_quedan_como_estaban()
    {
        $vieja = $this->categoria_real('Vieja');
        $con_categoria = $this->crear_articulo('Fuera con categoria', ['category_id' => $vieja->id]);
        $sin_categoria = $this->crear_articulo('Fuera sin categoria');
        $this->envejecer([$con_categoria, $sin_categoria]);

        $s = $this->sistema_basico();
        $total_antes = (int) CategoryProposalRun::find($s['run']->id)->articulos_total;

        $this->pedir_elegir($s['run'], $s['proposal'], true)->assertStatus(200);

        $this->assertSame(['category_id' => $vieja->id, 'sub_category_id' => null], $this->categorias_de($con_categoria));
        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($sin_categoria));
        $this->assertSame('2020-01-01 00:00:00', $this->actualizado_el($con_categoria));
        $this->assertSame('2020-01-01 00:00:00', $this->actualizado_el($sin_categoria));
        $this->assertSame($total_antes, (int) CategoryProposalRun::find($s['run']->id)->articulos_total);
        $this->assertSame(5, $total_antes);

        // Y sus ítems no existen: elegir no inventa nada para ellos.
        $this->assertSame(0, CategoryProposalItem::where('article_id', $con_categoria->id)->count());
    }

    /**
     * Un artículo que se borró después de la ingesta no se escribe (no hay a quién) y su ítem queda sin
     * procesar; el resto se aplica igual.
     *
     * @test
     * @group categorias_ia
     */
    public function un_articulo_borrado_despues_de_la_ingesta_no_se_toca()
    {
        $s = $this->sistema_basico();

        $s['articulos'][0]->delete();

        $respuesta = $this->pedir_elegir($s['run'], $s['proposal']);

        $respuesta->assertStatus(200);
        $this->assertSame(2, $respuesta->json('resultado.articulos_asignados'), 'Los otros dos seguros.');
        $this->assertSame('propuesta', $this->estado_de($s['items'][0]));
        $this->assertSame('aplicada', $this->estado_de($s['items'][1]));
        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($s['articulos'][0]));
    }

    // ---------------------------------------------------------------------------------------------
    // La regla (a): márgenes por categoría y Tienda Nube
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Regla (a): con margen por categoría un sistema NUEVO responde 422 `bloqueado_por_margenes` con
     * los motivos, y NO escribe nada (ni categorías, ni artículos, ni estado). "Mantener" sigue andando.
     *
     * @test
     * @group categorias_ia
     */
    public function la_regla_a_bloquea_un_sistema_nuevo_con_margenes_y_deja_pasar_mantener()
    {
        Queue::fake();

        $con_margen = $this->categoria_real('Con margen', null, ['percentage_gain' => 12]);
        $s = $this->sistema_basico();
        $antes = $this->cantidades_de_categorias();

        $respuesta = $this->pedir_elegir($s['run'], $s['proposal']);

        $respuesta->assertStatus(422)
            ->assertJsonPath('error', 'bloqueado_por_margenes')
            ->assertJsonPath('motivos', [['codigo' => 'R1', 'cantidad' => 1]]);
        $this->assertNotEmpty($respuesta->json('message'));

        // No se escribió nada.
        $this->assertSame($antes, $this->cantidades_de_categorias());
        foreach ($s['articulos'] as $articulo) {
            $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($articulo));
        }
        $this->assertSame('lista', CategoryProposalRun::find($s['run']->id)->estado);
        $this->assertSame('propuesta', $this->estado_de($s['items'][0]));
        $this->assertSame([], $this->ids_encolados_para_recalcular());

        // La extensión de listas por categoría bloquea igual, aunque no haya ni un porcentaje.
        Category::where('id', $con_margen->id)->update(['percentage_gain' => null]);
        $this->dar_extension('lista_de_precios_por_categoria');
        $segundo = $this->pedir_elegir($s['run'], $s['proposal']);
        $segundo->assertStatus(422)->assertJsonPath('error', 'bloqueado_por_margenes');
        $this->assertSame([['codigo' => 'R2', 'cantidad' => 1]], $segundo->json('motivos'));
    }

    /**
     * "Mantener" con márgenes por categoría SÍ se puede, y recalcula los precios de lo que asignó, en
     * segundo plano y recién después del commit: completar un artículo con una categoría con margen le
     * mueve el precio. Solo los artículos que se asignaron, no los a revisar.
     *
     * @test
     * @group categorias_ia
     */
    public function mantener_con_margenes_recalcula_los_precios_de_lo_que_asigno()
    {
        Queue::fake();

        $s = $this->sistema_mantener();
        Category::where('id', $s['herrajes']->id)->update(['percentage_gain' => 10]);

        $respuesta = $this->pedir_elegir($s['run'], $s['proposal']);

        $respuesta->assertStatus(200);

        $esperados = [$s['articulos'][0]->id, $s['articulos'][1]->id];
        sort($esperados);
        $this->assertSame($esperados, $this->ids_encolados_para_recalcular(), 'Solo los dos que se asignaron.');
    }

    /**
     * Sin márgenes por categoría no se recalcula ningún precio (ninguno depende de la categoría): el motor
     * no se gasta en vano.
     *
     * @test
     * @group categorias_ia
     */
    public function sin_margenes_por_categoria_no_se_recalculan_precios()
    {
        Queue::fake();

        // "Mantener" sin márgenes: asigna artículos pero ningún precio depende de la categoría.
        $mantener = $this->sistema_mantener();
        $this->pedir_elegir($mantener['run'], $mantener['proposal'])->assertStatus(200);
        $this->assertSame([], $this->ids_encolados_para_recalcular());

        // Un sistema nuevo tampoco: sin márgenes por categoría no hay nada que recalcular.
        $nuevo = $this->sistema_basico();
        $this->pedir_elegir($nuevo['run'], $nuevo['proposal'])->assertStatus(200);
        $this->assertSame([], $this->ids_encolados_para_recalcular());
    }

    /**
     * Regla (a) con Tienda Nube: crear cada categoría dispara un POST sincrónico a la API de TN y elegir es
     * un solo pedido, así que un sistema NUEVO responde 422 `bloqueado_por_tienda_nube`. "Mantener" sigue
     * andando y, después del commit, marca para sincronizar con TN los artículos asignados que ya están en TN.
     *
     * @test
     * @group categorias_ia
     */
    public function la_regla_a_con_tienda_nube_bloquea_lo_nuevo_y_mantener_sigue_andando()
    {
        $this->dar_extension('usa_tienda_nube');

        $s = $this->sistema_basico();

        $respuesta = $this->pedir_elegir($s['run'], $s['proposal']);

        $respuesta->assertStatus(422)
            ->assertJsonPath('error', 'bloqueado_por_tienda_nube')
            ->assertJsonPath('motivos', [['codigo' => 'tienda_nube', 'cantidad' => 1]]);
        $this->assertSame('lista', CategoryProposalRun::find($s['run']->id)->estado);
        $this->assertSame(0, $this->categorias_llamadas('Bisagras')->count());

        // Con margen además: manda el código de márgenes y los dos motivos.
        $this->categoria_real('Con margen', null, ['percentage_gain' => 5]);
        $doble = $this->pedir_elegir($s['run'], $s['proposal']);
        $doble->assertStatus(422)->assertJsonPath('error', 'bloqueado_por_margenes');
        $this->assertSame(
            [['codigo' => 'R1', 'cantidad' => 1], ['codigo' => 'tienda_nube', 'cantidad' => 1]],
            $doble->json('motivos')
        );
    }

    /**
     * 🔴 B-11 del verificador: Tienda Nube prendida SOLO por la variable de entorno (sin la extensión) bloquea un
     * sistema NUEVO igual que con la extensión. Antes el bloqueo no la veía y cada categoría que creaba el
     * aplicar disparaba a los observers de Tienda Nube DENTRO de la transacción (2 categorías = 4 pedidos de red,
     * con el candado de `users` tomado). Ahora responde 422 `bloqueado_por_tienda_nube`, no crea ninguna categoría
     * y no sale ningún pedido.
     *
     * @test
     * @group categorias_ia
     */
    public function tienda_nube_prendida_solo_por_el_env_bloquea_lo_nuevo_y_no_sale_ningun_pedido()
    {
        // Cualquier pedido de red a Tienda Nube durante el test queda registrado (y contestado) por el fake.
        Http::fake(['*' => Http::response(['id' => 777], 200)]);

        $s = $this->sistema_basico();

        // Cuántas categorías llegaron a crearse desde acá: el observer de Tienda Nube corre al crear cada una.
        // El contador se registra DESPUÉS de sembrar, que también crea categorías.
        $categorias_creadas = 0;

        Event::listen('eloquent.created: '.Category::class, function ($categoria) use (&$categorias_creadas) {
            $categorias_creadas++;
        });

        // La variable prendida y el dueño SIN la extensión `usa_tienda_nube`.
        $anterior = $this->prender_tienda_nube();

        try {
            $respuesta = $this->pedir_elegir($s['run'], $s['proposal']);
        } finally {
            $this->restaurar_tienda_nube($anterior);
        }

        $respuesta->assertStatus(422)
            ->assertJsonPath('error', 'bloqueado_por_tienda_nube')
            ->assertJsonPath('motivos', [['codigo' => 'tienda_nube', 'cantidad' => 1]]);

        $this->assertSame(0, $categorias_creadas, 'No se creó ninguna categoría.');
        Http::assertNothingSent();
        $this->assertSame('lista', CategoryProposalRun::find($s['run']->id)->estado);
        $this->assertSame(0, $this->categorias_llamadas('Bisagras')->count());
    }

    /**
     * "Mantener" con Tienda Nube: lo que se asigna se marca para sincronizar (solo los artículos que ya
     * están en TN o disponibles para TN, y solo los asignados), después del commit.
     *
     * @test
     * @group categorias_ia
     */
    public function mantener_con_tienda_nube_marca_los_articulos_asignados_para_sincronizar()
    {
        $this->dar_extension('usa_tienda_nube');

        $s = $this->sistema_mantener();

        // El primero y el tercero están disponibles para TN; el segundo no. El tercero es dudoso: no se asigna.
        $s['articulos'][0]->update(['disponible_tienda_nube' => 1]);
        $s['articulos'][2]->update(['disponible_tienda_nube' => 1]);

        $anterior = $this->prender_tienda_nube();

        try {
            $respuesta = $this->pedir_elegir($s['run'], $s['proposal']);
        } finally {
            $this->restaurar_tienda_nube($anterior);
        }

        $respuesta->assertStatus(200);

        $this->assertSame(1, SyncToTNArticle::where('article_id', $s['articulos'][0]->id)->where('status', 'pendiente')->count(), 'Asignado y en TN: se sincroniza.');
        $this->assertSame(0, SyncToTNArticle::where('article_id', $s['articulos'][1]->id)->count(), 'Asignado pero sin TN: no.');
        $this->assertSame(0, SyncToTNArticle::where('article_id', $s['articulos'][2]->id)->count(), 'En TN pero no asignado (dudoso): no.');
    }

    // ---------------------------------------------------------------------------------------------
    // Todo o nada, consultas y contrato
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 TODO O NADA: si algo falla a mitad de aplicar, no queda nada a medias — ni la primera categoría
     * que ya se había creado, ni los artículos, ni el estado de la corrida, ni un recálculo encolado.
     *
     * @test
     * @group categorias_ia
     */
    public function la_eleccion_es_todo_o_nada()
    {
        Queue::fake();

        $s = $this->sistema_basico();
        $antes = $this->cantidades_de_categorias();

        // La segunda categoría que se crea explota (el observer de Tienda Nube, por ejemplo, lo hace).
        Event::listen('eloquent.creating: '.Category::class, function ($categoria) {
            if ($categoria->name === 'Correderas') {
                throw new \RuntimeException('Falla simulada al crear la segunda categoría.');
            }
        });

        try {
            $estado = $this->pedir_elegir($s['run'], $s['proposal'])->getStatusCode();
        } catch (\RuntimeException $e) {
            $estado = 500;
        }

        $this->assertSame(500, $estado);

        $this->assertSame($antes, $this->cantidades_de_categorias(), 'Ni la primera categoría quedó creada.');
        foreach ($s['articulos'] as $articulo) {
            $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($articulo));
        }

        $run = CategoryProposalRun::find($s['run']->id);
        $this->assertSame('lista', $run->estado);
        $this->assertNull($run->propuesta_elegida_id);
        $this->assertNull($run->resultado);

        foreach ($s['items'] as $item) {
            $this->assertSame('propuesta', $this->estado_de($item));
        }

        $nodo = CategoryProposalNode::find($s['nodos']['Bisagras']->id);
        $this->assertNull($nodo->real_category_id, 'Los nodos tampoco quedaron atados.');
        $this->assertSame([], $this->ids_encolados_para_recalcular());
    }

    /**
     * La cantidad de consultas de elegir no crece con la cantidad de artículos: los UPDATE van por lote y
     * por destino, no por artículo (clase de error "un índice que crece", pero acá para la escritura en
     * bloque). Mismo sistema con 30 y con 300 artículos: mismas consultas.
     *
     * @test
     * @group categorias_ia
     */
    public function las_consultas_no_crecen_con_la_cantidad_de_articulos()
    {
        $armar = function ($dueno, $cantidad) {
            $articulos = [];
            for ($i = 0; $i < $cantidad; $i++) {
                $articulos[] = $this->crear_articulo('Artículo '.$i, [], $dueno);
            }

            $items = [];
            foreach ($articulos as $i => $articulo) {
                // Mismo reparto en las dos corridas: la mitad a una categoría, la mitad a otra con subcategoría.
                $items[] = ($i % 2 === 0)
                    ? [$articulo, 'Bisagras', null, 'segura']
                    : [$articulo, 'Correderas', 'Telescópicas', 'segura'];
            }

            return $this->sembrar_corrida([
                'propuestas' => [
                    'A' => [
                        'arbol' => ['Bisagras' => [], 'Correderas' => ['Telescópicas']],
                        'items' => $items,
                    ],
                ],
            ], $dueno);
        };

        $chico  = $armar($this->owner, 30);
        $grande = $armar($this->vecino, 300);

        $consultas_chico = $this->cantidad_de_consultas(function () use ($chico) {
            $this->pedir_elegir($chico['run'], $chico['propuestas']['A']['proposal'])->assertStatus(200);
        });

        $this->actuar_como($this->vecino);

        $consultas_grande = $this->cantidad_de_consultas(function () use ($grande) {
            $this->pedir_elegir($grande['run'], $grande['propuestas']['A']['proposal'])->assertStatus(200);
        });

        $this->assertSame($consultas_chico, $consultas_grande, 'Elegir hace las mismas consultas con 30 y con 300 artículos.');

        // Y las dos aplicaron todo lo que tenían.
        $this->assertSame(30, CategoryProposalItem::where('proposal_id', $chico['propuestas']['A']['proposal']->id)->where('estado', 'aplicada')->count());
        $this->assertSame(300, CategoryProposalItem::where('proposal_id', $grande['propuestas']['A']['proposal']->id)->where('estado', 'aplicada')->count());
    }

    /**
     * Los errores de contrato, cada uno con su código y su HTTP: corrida no lista (409), propuesta de
     * otra corrida o inexistente (404), cuerpo mal armado (422 con `detalle`). Y un id AJENO con el cuerpo
     * mal armado responde 404, no 422: la forma del cuerpo nunca delata que el id existe.
     *
     * @test
     * @group categorias_ia
     */
    public function los_errores_del_contrato_tienen_su_codigo_y_su_http()
    {
        $s = $this->sistema_basico();

        // Corrida todavía preparando: 409 no_esta_lista.
        $preparando = $this->sembrar_corrida([
            'estado'     => 'preparando',
            'propuestas' => ['A' => ['arbol' => ['Bisagras' => []], 'items' => []]],
        ]);
        $this->pedir_elegir($preparando['run'], $preparando['propuestas']['A']['proposal'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'no_esta_lista');

        // La propuesta de OTRA corrida del mismo dueño: 404.
        $this->pedir_elegir($s['run'], $preparando['propuestas']['A']['proposal'])
            ->assertStatus(404)
            ->assertJsonPath('error', 'no_encontrado');

        // Una propuesta que no existe: 404.
        $this->postJson('api/category-proposal-runs/'.$s['run']->id.'/elegir', ['propuesta_id' => 999999999])
            ->assertStatus(404)
            ->assertJsonPath('error', 'no_encontrado');

        // Cuerpo mal armado: 422 validacion con el detalle por campo.
        foreach ([[], ['propuesta_id' => 'abc'], ['propuesta_id' => true], ['propuesta_id' => 0], ['propuesta_id' => [1]]] as $cuerpo) {
            $r = $this->postJson('api/category-proposal-runs/'.$s['run']->id.'/elegir', $cuerpo);
            $r->assertStatus(422)->assertJsonPath('error', 'validacion');
            $this->assertArrayHasKey('propuesta_id', $r->json('detalle'));
            $this->assertNotEmpty($r->json('message'));
        }

        $r = $this->postJson('api/category-proposal-runs/'.$s['run']->id.'/elegir', ['propuesta_id' => $s['proposal']->id, 'eliminar_categorias_vacias' => 'quizas']);
        $r->assertStatus(422)->assertJsonPath('error', 'validacion');
        $this->assertArrayHasKey('eliminar_categorias_vacias', $r->json('detalle'));

        // Nada de lo de arriba aplicó nada.
        $this->assertSame('lista', CategoryProposalRun::find($s['run']->id)->estado);

        // El id de una corrida AJENA con el cuerpo mal armado: 404, igual que uno inexistente.
        $ajena = $this->sembrar_corrida(['propuestas' => ['A' => ['arbol' => ['X' => []], 'items' => []]]], $this->vecino);
        $this->postJson('api/category-proposal-runs/'.$ajena['run']->id.'/elegir', [])->assertStatus(404);
        $this->postJson('api/category-proposal-runs/999999999/elegir', [])->assertStatus(404);
    }

    /**
     * Las categorías se crean "como el sistema": con el `num` correlativo DEL DUEÑO y, si el dueño tiene las
     * extensiones, con sus listas de precio por categoría y sus rangos armados. Se le pasa el dueño
     * explícito porque donde no hay sesión esos helpers toman el usuario de la sesión y no hacen nada, sin
     * avisar: acá la sesión es la de OTRO comercio y igual se crean las del dueño.
     *
     * @test
     * @group categorias_ia
     */
    public function las_categorias_se_crean_como_el_sistema_con_el_dueno_explicito()
    {
        $this->dar_extension('lista_de_precios_por_categoria');
        $this->dar_extension('lista_de_precios_por_rango_de_cantidad_vendida');

        $minorista = DB::table('price_types')->insertGetId(['name' => 'Minorista', 'user_id' => $this->owner->id, 'num' => 1, 'created_at' => '2026-01-01 10:00:00']);
        $mayorista = DB::table('price_types')->insertGetId(['name' => 'Mayorista', 'user_id' => $this->owner->id, 'num' => 2, 'created_at' => '2026-01-01 11:00:00']);
        // Una lista de otro comercio no se cuelga de nuestras categorías.
        DB::table('price_types')->insert(['name' => 'Del vecino', 'user_id' => $this->vecino->id, 'num' => 1, 'created_at' => '2026-01-01 10:00:00']);

        // Una categoría previa del dueño con num 7 y otra del vecino con num 90: el correlativo es por dueño.
        $this->categoria_real('Previa', null, ['num' => 7]);
        $this->categoria_real('Del vecino', $this->vecino, ['num' => 90]);

        // La sesión es la del vecino (sin extensiones): el helper tiene que usar al dueño que le pasan.
        $this->actuar_como($this->vecino);

        $real = new CategoriaRealHelper(User::find($this->owner->id));

        $categoria = $real->buscar_o_crear_categoria('Bisagras');
        $this->assertTrue($categoria['creada']);

        $fila = Category::find($categoria['id']);
        $this->assertSame($this->owner->id, (int) $fila->user_id);
        $this->assertSame(8, (int) $fila->num, 'El correlativo sigue al del dueño (7), no al del vecino (90).');

        // Las listas de precio del dueño, con porcentaje NULL (una categoría nueva no inventa márgenes).
        $pivotes = DB::table('category_price_type')->where('category_id', $categoria['id'])->orderBy('price_type_id')->get();
        $this->assertSame([$minorista, $mayorista], $pivotes->pluck('price_type_id')->map(function ($id) { return (int) $id; })->all());
        $this->assertSame([null, null], $pivotes->pluck('percentage')->all());

        // Los rangos por cantidad: una fila por lista, a nombre del dueño y sin mínimo ni máximo.
        $rangos = DB::table('category_price_type_ranges')->where('category_id', $categoria['id'])->orderBy('price_type_id')->get();
        $this->assertCount(2, $rangos);
        $this->assertSame([$this->owner->id, $this->owner->id], $rangos->pluck('user_id')->map(function ($id) { return (int) $id; })->all());
        $this->assertSame([null, null], $rangos->pluck('min')->all());

        // La subcategoría también arma sus listas, y el siguiente num sale correlativo.
        $sub = $real->buscar_o_crear_subcategoria($categoria['id'], 'Comunes');
        $this->assertTrue($sub['creada']);
        $this->assertSame(2, DB::table('price_type_sub_category')->where('sub_category_id', $sub['id'])->count());

        $otra = $real->buscar_o_crear_categoria('Correderas');
        $this->assertSame(9, (int) Category::find($otra['id'])->num);

        // Y buscar de nuevo (con otras mayúsculas) reutiliza: no crea otra ni arma listas dos veces.
        $de_nuevo = $real->buscar_o_crear_categoria('  BISAGRAS ');
        $this->assertFalse($de_nuevo['creada']);
        $this->assertSame($categoria['id'], $de_nuevo['id']);
        $this->assertSame(2, DB::table('category_price_type')->where('category_id', $categoria['id'])->count());

        // Una categoría de OTRO comercio no sirve de padre de una subcategoría.
        $ajena = $this->categoria_real('Ajena', $this->vecino);
        $this->assertNull($real->buscar_o_crear_subcategoria($ajena->id, 'Comunes'));
        $this->assertSame(0, SubCategory::where('category_id', $ajena->id)->count());
    }

    /**
     * Quedan registrados quién eligió, con qué sesión y qué pidió (`eliminar_categorias_vacias`).
     *
     * @test
     * @group categorias_ia
     */
    public function la_corrida_registra_quien_eligio_y_lo_que_pidio()
    {
        $s = $this->sistema_basico();

        $this->pedir_elegir($s['run'], $s['proposal'], true)->assertStatus(200);

        $run = CategoryProposalRun::find($s['run']->id);
        $this->assertSame($this->owner->id, (int) $run->elegida_por);
        $this->assertTrue($run->eliminar_categorias_vacias);
        $this->assertFalse($run->elegida_con_acceso_maestro);
    }

    // ---------------------------------------------------------------------------------------------
    // Lotes, escritura en bloque y propuestas vacías
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Elegir recorre VARIOS lotes sin saltearse ni repetir a nadie: con un lote de 4 ítems y 25 artículos
     * (uno de ellos borrado después de la ingesta) cada artículo termina donde corresponde, cada ítem en su
     * estado y con lo que tenía antes guardado. Con el lote de verdad (1000) los otros tests nunca dan
     * más de una vuelta al bucle: este es el que prueba la paginación.
     *
     * @test
     * @group categorias_ia
     */
    public function elegir_recorre_varios_lotes_sin_saltearse_ni_repetir_articulos()
    {
        config(['catalogo_ia.articulos_por_lote_de_escritura' => 4]);

        $e = $this->sembrar_veinticinco();

        // El artículo 7 (un seguro de Correderas) se borra antes de elegir.
        $e['articulos'][7]->delete();

        $respuesta = $this->pedir_elegir($e['run'], $e['proposal']);

        $respuesta->assertStatus(200);
        $this->assertSame([
            'categorias_creadas'      => 2,
            'categorias_reutilizadas' => 0,
            'subcategorias_creadas'   => 1,
            'articulos_asignados'     => 14,
            'a_revisar'               => 5,
            'sin_asignar'             => 5,
            'pierden_categoria'       => 4,
            'categorias_eliminadas'   => 0,
        ], $respuesta->json('resultado'));

        $bisagras   = $this->categorias_llamadas('Bisagras')->first();
        $correderas = $this->categorias_llamadas('Correderas')->first();
        $comunes    = SubCategory::where('category_id', $bisagras->id)->where('name', 'Comunes')->first();

        foreach ($e['articulos'] as $i => $articulo) {
            $fila = CategoryProposalItem::find($e['items'][$i]->id);

            // El borrado no se procesó: su ítem sigue como estaba.
            if ($i === 7) {
                $this->assertSame('propuesta', $fila->estado);
                $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($articulo));
                continue;
            }

            switch ($i % 5) {
                case 0:
                    $estado = 'sin_asignar';
                    $destino = ['category_id' => null, 'sub_category_id' => null];
                    break;

                case 1:
                    $estado = 'a_revisar';
                    $destino = ['category_id' => null, 'sub_category_id' => null];
                    break;

                default:
                    $estado = 'aplicada';
                    $destino = ($i % 2 === 0)
                        ? ['category_id' => $bisagras->id, 'sub_category_id' => $comunes->id]
                        : ['category_id' => $correderas->id, 'sub_category_id' => null];
            }

            $this->assertSame($estado, $fila->estado, 'Ítem del artículo '.$i);
            $this->assertSame($destino, $this->categorias_de($articulo), 'Artículo '.$i);

            // Lo que tenía antes: la categoría `Vieja` los múltiplos de tres, nada el resto.
            $this->assertSame(($i % 3 === 0) ? $e['vieja']->id : null, is_null($fila->prev_category_id) ? null : (int) $fila->prev_category_id, 'prev del artículo '.$i);
            $this->assertNull($fila->prev_sub_category_id);
        }
    }

    /**
     * 🔴 La escritura en bloque nunca toca un artículo de otro comercio ni uno borrado, aunque le pasen su
     * id: es la segunda traba (la primera es no leerlos), y se prueba directo porque desde afuera, mientras
     * la primera funcione, nadie la ve. También recorre los lotes de a pocos.
     *
     * @test
     * @group categorias_ia
     */
    public function la_escritura_en_bloque_no_toca_articulos_ajenos_ni_borrados()
    {
        $propios = $this->crear_articulos(['p1', 'p2', 'p3', 'p4', 'p5']);
        $ajeno   = $this->crear_articulo('Ajeno', [], $this->vecino);
        $borrado = $this->crear_articulo('Borrado');
        $borrado->delete();
        $destino = $this->categoria_real('Destino');

        $todos = array_merge($propios, [$ajeno, $borrado]);
        $this->envejecer($todos);

        config(['catalogo_ia.articulos_por_lote_de_escritura' => 2]);

        $grupos = [];

        foreach ($todos as $articulo) {
            CategoryProposalEscrituraHelper::sumar_a_grupo($grupos, $destino->id, null, $articulo->id);
        }

        $escritos = CategoryProposalEscrituraHelper::escribir_destinos($this->owner->id, array_values($grupos));

        $this->assertSame(5, $escritos, 'Solo los cinco propios y vivos, en tres lotes.');

        foreach ($propios as $articulo) {
            $this->assertSame(['category_id' => $destino->id, 'sub_category_id' => null], $this->categorias_de($articulo));
            $this->assertNotSame('2020-01-01 00:00:00', $this->actualizado_el($articulo));
        }

        foreach ([$ajeno, $borrado] as $articulo) {
            $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($articulo), $articulo->name.' no se toca.');
            $this->assertSame('2020-01-01 00:00:00', $this->actualizado_el($articulo));
        }
    }

    /**
     * Una propuesta sin ítems se puede elegir: no crea nada, no toca ningún artículo y el resumen es todo
     * ceros. (Es lo que pasa si la skill publica con `forzar` una propuesta a la que no le cargó nada.)
     *
     * @test
     * @group categorias_ia
     */
    public function una_propuesta_sin_items_se_elige_sin_efectos()
    {
        $vieja = $this->categoria_real('Vieja');
        $suelto = $this->crear_articulo('Suelto', ['category_id' => $vieja->id]);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => ['A' => ['arbol' => ['Bisagras' => []], 'items' => []]],
        ]);

        $respuesta = $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal'], true);

        $respuesta->assertStatus(200);
        $this->assertSame([
            'categorias_creadas'      => 0,
            'categorias_reutilizadas' => 0,
            'subcategorias_creadas'   => 0,
            'articulos_asignados'     => 0,
            'a_revisar'               => 0,
            'sin_asignar'             => 0,
            'pierden_categoria'       => 0,
            'categorias_eliminadas'   => 0,
        ], $respuesta->json('resultado'));

        $this->assertSame([1, 0], $this->cantidades_de_categorias());
        $this->assertSame(['category_id' => $vieja->id, 'sub_category_id' => null], $this->categorias_de($suelto));
        $this->assertSame('elegida', CategoryProposalRun::find($sembrado['run']->id)->estado);
    }

    /**
     * 🔴 "Eliminar vacías" (viene tildado por defecto en el modal) NO manda a la papelera una categoría o
     * subcategoría vieja que un nodo del sistema elegido todavía necesita, aunque ese nodo solo tenga dudosos
     * y por eso no se haya resuelto al elegir (B-03 del verificador). Si la mandara, al aprobar el primer
     * dudoso el nodo no la encontraría y crearía OTRA con el mismo nombre y otro id: la original perdería su
     * imagen, su descripción y su `num` sin que el cartel lo dijera.
     *
     * Se compara por nombre normalizado (el nodo se llama FERRETERIA y la categoría Ferretería), vale para
     * categorías y para subcategorías, y una subcategoría protegida protege a su categoría. Lo que no
     * coincide con ningún nodo y quedó vacío sí se manda a la papelera (el control).
     *
     * Y al aprobar el primer dudoso se REUTILIZA la original: mismo id, con su imagen y su descripción.
     *
     * @test
     * @group categorias_ia
     */
    public function eliminar_vacias_no_manda_a_la_papelera_una_categoria_que_un_nodo_todavia_necesita()
    {
        // Dos categorías viejas con identidad propia (imagen y descripción), una de ellas con una subcategoría.
        $pinturas   = $this->categoria_real('Pinturas', null, ['image_url' => 'pinturas.png', 'descripcion' => 'Las pinturas de la casa']);
        $ferreteria = $this->categoria_real('Ferretería', null, ['image_url' => 'ferreteria.png', 'descripcion' => 'Todo para ferretería']);
        $tornillos  = $this->subcategoria_real('Tornillos', $ferreteria);

        // El control: una categoría vieja que ningún nodo nombra y que queda vacía (con una subcategoría vacía).
        $sin_uso     = $this->categoria_real('Sin uso en el sistema nuevo');
        $sub_sin_uso = $this->subcategoria_real('Sub sin uso', $sin_uso);

        // Una categoría vieja que ningún nodo nombra, pero cuya subcategoría SÍ la nombra un nodo (Herrajes / Bulones):
        // la subcategoría protegida protege a su categoría, que si no quedaría borrada con la subcategoría viva colgando.
        $cajon   = $this->categoria_real('Cajon de sastre');
        $bulones = $this->subcategoria_real('Bulones', $cajon);

        $latex    = $this->crear_articulo('Latex 4L', ['category_id' => $pinturas->id]);
        $tornillo = $this->crear_articulo('Tornillo 8mm', ['category_id' => $ferreteria->id, 'sub_category_id' => $tornillos->id]);
        $bulon    = $this->crear_articulo('Bulon', ['category_id' => $cajon->id, 'sub_category_id' => $bulones->id]);
        $martillo = $this->crear_articulo('Martillo');

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    // Los nodos que solo tienen dudosos (Pinturas, Ferretería / Tornillos y Herrajes / Bulones) no se
                    // resuelven al elegir.
                    'arbol' => ['Pinturas' => [], 'FERRETERIA' => ['tornillos'], 'Herrajes' => ['BULONES'], 'Herramientas' => []],
                    'items' => [
                        [$latex, 'Pinturas', null, 'dudosa', 'no queda claro'],
                        [$tornillo, 'FERRETERIA', 'tornillos', 'dudosa', 'no queda claro'],
                        [$bulon, 'Herrajes', 'BULONES', 'dudosa', 'no queda claro'],
                        [$martillo, 'Herramientas', null, 'segura'],
                    ],
                ],
            ],
        ]);

        $respuesta = $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal'], true);

        $respuesta->assertStatus(200);
        $this->assertSame(1, $respuesta->json('resultado.categorias_eliminadas'), 'Solo la que ningún nodo nombra.');

        // En la papelera, solo el control (con su subcategoría).
        $this->assertSame([$sin_uso->id], Category::onlyTrashed()->where('user_id', $this->owner->id)->pluck('id')->all());
        $this->assertSame([$sub_sin_uso->id], SubCategory::onlyTrashed()->where('user_id', $this->owner->id)->pluck('id')->all());

        // Las que un nodo todavía necesita siguen vivas, con su identidad.
        foreach ([$pinturas, $ferreteria] as $categoria) {
            $this->assertNotNull(Category::find($categoria->id), $categoria->name.' sigue viva.');
        }
        $this->assertNotNull(SubCategory::find($tornillos->id));

        // La categoría que ningún nodo nombra se conserva porque su subcategoría sí está nombrada.
        $this->assertNotNull(Category::find($cajon->id), 'La subcategoría protegida protege a su categoría.');
        $this->assertNotNull(SubCategory::find($bulones->id));

        // Los artículos dudosos quedaron sin categoría (es un sistema nuevo), así que las originales están vacías.
        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($latex));

        // Aprobar el primer dudoso REUTILIZA la original: mismo id, con su imagen y su descripción.
        $items = $sembrado['propuestas']['A']['items'];

        $this->pedir_aprobar($items[0])->assertStatus(200);

        $viva = $this->categorias_llamadas('Pinturas');
        $this->assertSame(1, $viva->count(), 'No se recreó con otro id.');
        $this->assertSame($pinturas->id, $viva->first()->id);
        $this->assertSame('pinturas.png', $viva->first()->image_url);
        $this->assertSame('Las pinturas de la casa', $viva->first()->descripcion);
        $this->assertSame(['category_id' => $pinturas->id, 'sub_category_id' => null], $this->categorias_de($latex));

        $nodo = CategoryProposalNode::find($sembrado['propuestas']['A']['nodos']['Pinturas']->id);
        $this->assertSame($pinturas->id, (int) $nodo->real_category_id);
        $this->assertFalse($nodo->real_creado, 'La reutilizó, no la creó.');

        // Lo mismo con la categoría y la subcategoría que se nombran con otras mayúsculas y sin tilde.
        $this->pedir_aprobar($items[1])->assertStatus(200);

        $this->assertSame(1, $this->categorias_llamadas('Ferretería')->count());
        $this->assertSame(1, SubCategory::where('user_id', $this->owner->id)->where('name', 'Tornillos')->count());
        $this->assertSame(['category_id' => $ferreteria->id, 'sub_category_id' => $tornillos->id], $this->categorias_de($tornillo));
        $this->assertSame('ferreteria.png', Category::find($ferreteria->id)->image_url);
    }

    /**
     * Si el dudoso se RECHAZA, la categoría vieja que el nodo necesitaba sigue existiendo (vacía): lo único
     * que se pierde es la asignación, no la categoría del comercio.
     *
     * @test
     * @group categorias_ia
     */
    public function si_se_rechaza_el_dudoso_la_categoria_vieja_que_el_nodo_necesitaba_sigue_existiendo()
    {
        $pinturas = $this->categoria_real('Pinturas', null, ['image_url' => 'pinturas.png']);
        $latex    = $this->crear_articulo('Latex 4L', ['category_id' => $pinturas->id]);
        $martillo = $this->crear_articulo('Martillo');

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Pinturas' => [], 'Herramientas' => []],
                    'items' => [
                        [$latex, 'Pinturas', null, 'dudosa', 'no queda claro'],
                        [$martillo, 'Herramientas', null, 'segura'],
                    ],
                ],
            ],
        ]);

        $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal'], true)->assertStatus(200);
        $this->pedir_rechazar($sembrado['propuestas']['A']['items'][0])->assertStatus(200);

        $this->assertNotNull(Category::find($pinturas->id), 'La categoría original sigue en pie.');
        $this->assertSame('pinturas.png', Category::find($pinturas->id)->image_url);
        $this->assertSame(0, Category::onlyTrashed()->where('user_id', $this->owner->id)->count());
    }
}
