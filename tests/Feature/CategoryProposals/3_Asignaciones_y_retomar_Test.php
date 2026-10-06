<?php

namespace Tests\Feature\CategoryProposals;

use App\Models\Article;
use App\Models\CategoryProposal;
use App\Models\CategoryProposalItem;
use App\Models\CategoryProposalNode;
use App\Models\CategoryProposalRun;
use Illuminate\Support\Facades\DB;

/**
 * Las asignaciones de artículos y la corrida retomable (misión categorizacion-tres-modelos,
 * 5/10/2026). Contrato A, §5.4 a §5.8: `asignaciones`, `pendientes`, `listo`, `descartar`, `mostrar` y
 * `actual`.
 *
 * Lo que la skill carga son hasta 500 renglones por pedido y cualquiera puede venir mal: el servidor
 * guarda los que sirven, devuelve cada rechazo con su motivo (200) y NUNCA se cae por un renglón. Lo
 * que tiene que garantizar sin que nadie más lo vea:
 *   - un `articulo_id` de otro dueño, borrado, inactivo o inexistente no se guarda (los cuatro, igual);
 *   - reenviar es idempotente (el ítem se reemplaza, no se duplica);
 *   - una corrida que ya no está `preparando` no acepta nada (una propuesta que el dueño puede ver no
 *     se modifica en vivo);
 *   - el estado vive en el servidor: `pendientes` dice qué falta, y por eso un corte de la skill no
 *     pierde nada.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Asignaciones_y_retomar_Test extends CategoryProposalsTestCase
{
    use ArmaCorridasDeCategorias;

    /**
     * Arma un catálogo de `$articulos` artículos del dueño y una corrida con las propuestas A y B (de
     * ejemplo).
     *
     * @param  int $articulos
     * @return array  [ids de los artículos, run_id, el JSON de crear]
     */
    protected function preparar($articulos = 5)
    {
        $ids  = $this->crear_articulos_en_masa($articulos);
        $json = $this->crear_corrida_por_api();

        return [$ids, $json['run_id'], $json];
    }

    /**
     * POST de asignaciones a una propuesta de una corrida.
     *
     * @param  int    $run_id
     * @param  string $clave
     * @param  array  $asignaciones
     * @return \Illuminate\Testing\TestResponse
     */
    protected function asignar($run_id, $clave, array $asignaciones)
    {
        return $this->post_admin('categorias/propuestas/'.$run_id.'/asignaciones', ['propuesta' => $clave, 'asignaciones' => $asignaciones]);
    }

    /**
     * Un renglón de asignación.
     *
     * @param  mixed       $articulo_id
     * @param  string|null $categoria
     * @param  string|null $subcategoria
     * @param  string      $confianza
     * @param  string|null $motivo
     * @return array
     */
    protected function renglon($articulo_id, $categoria, $subcategoria = null, $confianza = 'segura', $motivo = null)
    {
        return [
            'articulo_id'  => $articulo_id,
            'categoria'    => $categoria,
            'subcategoria' => $subcategoria,
            'confianza'    => $confianza,
            'motivo'       => $motivo,
        ];
    }

    /**
     * El id de la propuesta de una corrida por su clave.
     *
     * @param  int    $run_id
     * @param  string $clave
     * @return int
     */
    protected function propuesta_id($run_id, $clave)
    {
        return (int) CategoryProposal::where('run_id', $run_id)->where('clave', $clave)->value('id');
    }

    /**
     * El id de un nodo de una propuesta por nombre de categoría (y, opcionalmente, de subcategoría).
     *
     * @param  int         $proposal_id
     * @param  string      $categoria
     * @param  string|null $subcategoria
     * @return int
     */
    protected function nodo_id($proposal_id, $categoria, $subcategoria = null)
    {
        $padre = CategoryProposalNode::where('proposal_id', $proposal_id)->whereNull('parent_id')->where('nombre', $categoria)->first();

        if (is_null($subcategoria)) {

            return (int) $padre->id;
        }

        return (int) CategoryProposalNode::where('proposal_id', $proposal_id)->where('parent_id', $padre->id)->where('nombre', $subcategoria)->value('id');
    }

    /**
     * Cuántos ítems hay en una propuesta.
     *
     * @param  int $proposal_id
     * @return int
     */
    protected function items_de($proposal_id)
    {
        return CategoryProposalItem::where('proposal_id', $proposal_id)->count();
    }

    // ------------------------------------------------------------------------------------------
    // asignaciones
    // ------------------------------------------------------------------------------------------

    /**
     * @group categorias_ia
     * @test
     */
    public function guarda_las_asignaciones_con_su_nodo_y_devuelve_el_progreso()
    {
        list($ids, $run_id) = $this->preparar(5);

        $respuesta = $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'Bisagras', 'De cierre suave', 'segura'),
            $this->renglon($ids[1], 'Correderas', null, 'dudosa', 'no queda claro si es de cajón o de puerta'),
            $this->renglon($ids[2], null, null, 'ninguna', 'nombre ambiguo'),
        ]);

        $respuesta->assertStatus(200)->assertExactJson([
            'recibidas'  => 3,
            'guardadas'  => 3,
            'rechazadas' => [],
            'progreso'   => ['A' => ['asignados' => 3, 'total' => 5]],
        ]);

        $a = $this->propuesta_id($run_id, 'A');

        $segura = CategoryProposalItem::where('proposal_id', $a)->where('article_id', $ids[0])->first();
        $dudosa = CategoryProposalItem::where('proposal_id', $a)->where('article_id', $ids[1])->first();
        $ninguna = CategoryProposalItem::where('proposal_id', $a)->where('article_id', $ids[2])->first();

        $this->assertSame($this->nodo_id($a, 'Bisagras'), (int) $segura->node_id);
        $this->assertSame($this->nodo_id($a, 'Bisagras', 'De cierre suave'), (int) $segura->sub_node_id);
        $this->assertSame('segura', $segura->confianza);
        $this->assertNull($segura->motivo);

        $this->assertSame($this->nodo_id($a, 'Correderas'), (int) $dudosa->node_id);
        $this->assertNull($dudosa->sub_node_id);
        $this->assertSame('dudosa', $dudosa->confianza);
        $this->assertSame('no queda claro si es de cajón o de puerta', $dudosa->motivo);

        $this->assertNull($ninguna->node_id);
        $this->assertNull($ninguna->sub_node_id);
        $this->assertSame('ninguna', $ninguna->confianza);
        $this->assertSame('nombre ambiguo', $ninguna->motivo);

        // Todo del dueño, en estado "propuesta" y sin lo que solo escribe el aplicar.
        foreach ([$segura, $dudosa, $ninguna] as $item) {

            $this->assertSame((int) $this->owner->id, (int) $item->user_id);
            $this->assertSame('propuesta', $item->estado);
            $this->assertNull($item->prev_category_id);
            $this->assertNull($item->prev_sub_category_id);
            $this->assertNull($item->revisado_por);
            $this->assertNull($item->revisado_at);
        }

        // La otra propuesta ni se enteró.
        $this->assertSame(0, $this->items_de($this->propuesta_id($run_id, 'B')));
    }

    /**
     * 🔴 Escribir asignaciones NO toca los artículos: ni su categoría ni su fecha de actualización (esa
     * fecha es el reloj de la sincronización de la SPA y de los embeddings).
     *
     * @group categorias_ia
     * @test
     */
    public function guardar_asignaciones_no_toca_los_articulos()
    {
        list($ids, $run_id) = $this->preparar(3);

        $antes = DB::table('articles')->whereIn('id', $ids)->orderBy('id')->get(['id', 'category_id', 'sub_category_id', 'updated_at'])->toArray();

        $this->asignar($run_id, 'A', [$this->renglon($ids[0], 'Bisagras', 'Comunes')])->assertStatus(200);

        $this->assertEquals($antes, DB::table('articles')->whereIn('id', $ids)->orderBy('id')->get(['id', 'category_id', 'sub_category_id', 'updated_at'])->toArray());
        $this->assertSame(0, DB::table('categories')->where('user_id', $this->owner->id)->count());
    }

    /**
     * Los nombres se comparan normalizados: sin mayúsculas, sin acentos y con los espacios colapsados.
     *
     * @group categorias_ia
     * @test
     */
    public function los_nombres_se_comparan_normalizados()
    {
        $ids  = $this->crear_articulos_en_masa(3);
        $json = $this->crear_corrida_por_api([[
            'clave' => 'A', 'tipo' => 'nueva', 'nombre' => 'Normalizada', 'arbol' => [
                ['nombre' => 'Ferretería y Herrajes', 'subcategorias' => ['Cerraduras Ñandú']],
                ['nombre' => 'Pinturas'],
            ],
        ]]);

        $run_id = $json['run_id'];

        $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'FERRETERIA   Y herrajes', 'cerraduras nandu'),
            $this->renglon($ids[1], '  ferretería y herrajes ', 'CERRADURAS ÑANDÚ'),
            $this->renglon($ids[2], 'pinturas'),
        ])->assertStatus(200)->assertJson(['guardadas' => 3, 'rechazadas' => []]);

        $a = $this->propuesta_id($run_id, 'A');

        $this->assertSame($this->nodo_id($a, 'Ferretería y Herrajes'), (int) CategoryProposalItem::where('proposal_id', $a)->where('article_id', $ids[0])->value('node_id'));
        $this->assertSame($this->nodo_id($a, 'Ferretería y Herrajes', 'Cerraduras Ñandú'), (int) CategoryProposalItem::where('proposal_id', $a)->where('article_id', $ids[1])->value('sub_node_id'));
    }

    /**
     * Con `ninguna` ("no pude ubicarlo") no hay categoría: se guarda sin nodos aunque el renglón traiga
     * nombres, y esos nombres no se validan (no hay nada que resolver).
     *
     * @group categorias_ia
     * @test
     */
    public function ninguna_se_guarda_sin_nodos_aunque_traiga_nombres()
    {
        list($ids, $run_id) = $this->preparar(2);

        $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'Bisagras', 'Comunes', 'ninguna', 'no sé'),
            $this->renglon($ids[1], 'Una categoria que no existe', 'Ni esta', 'ninguna'),
        ])->assertStatus(200)->assertJson(['guardadas' => 2, 'rechazadas' => []]);

        $a = $this->propuesta_id($run_id, 'A');

        foreach ($ids as $id) {

            $item = CategoryProposalItem::where('proposal_id', $a)->where('article_id', $id)->first();

            $this->assertNull($item->node_id);
            $this->assertNull($item->sub_node_id);
            $this->assertSame('ninguna', $item->confianza);
        }
    }

    /**
     * 🔴 Cada renglón malo se devuelve en `rechazadas` con su motivo y el resto SE GUARDA (200): un
     * renglón roto no tira el lote de 500.
     *
     * @group categorias_ia
     * @test
     */
    public function los_renglones_malos_se_rechazan_con_su_motivo_y_el_resto_se_guarda()
    {
        list($ids, $run_id) = $this->preparar(8);

        $respuesta = $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'Bisagras', 'Comunes'),                       // bien
            $this->renglon($ids[1], 'Foo', null),                                  // categoría inexistente
            $this->renglon($ids[2], 'Bisagras', 'Foo'),                            // subcategoría inexistente
            $this->renglon($ids[3], 'Correderas', 'Comunes'),                      // subcategoría de OTRA categoría
            $this->renglon($ids[4], 'Bisagras', null, 'quizas'),                   // confianza inválida
            $this->renglon($ids[5], null, null, 'segura'),                         // sin categoría y no es ninguna
            $this->renglon($ids[6], null, null, 'dudosa'),                         // ídem
            'esto no es un objeto',                                                // renglón que no es un objeto
            $this->renglon($ids[7], 'Correderas'),                                 // bien
        ]);

        $respuesta->assertStatus(200);

        $json = $respuesta->json();

        $this->assertSame(['recibidas', 'guardadas', 'rechazadas', 'progreso'], array_keys($json));
        $this->assertSame(9, $json['recibidas']);
        $this->assertSame(2, $json['guardadas']);
        $this->assertCount(7, $json['rechazadas']);
        $this->assertSame(['A' => ['asignados' => 2, 'total' => 8]], $json['progreso']);

        // Los rechazos con id de artículo por id; el renglón que ni siquiera es un objeto no tiene id.
        $motivos = [];
        $sin_id  = [];

        foreach ($json['rechazadas'] as $rechazada) {

            $this->assertSame(['articulo_id', 'motivo'], array_keys($rechazada));

            if (is_null($rechazada['articulo_id'])) {

                $sin_id[] = $rechazada['motivo'];
            } else {

                $motivos[$rechazada['articulo_id']] = $rechazada['motivo'];
            }
        }

        $this->assertSame("categoria_inexistente: 'Foo'", $motivos[$ids[1]]);
        $this->assertSame("subcategoria_inexistente: 'Foo'", $motivos[$ids[2]]);
        $this->assertSame("subcategoria_inexistente: 'Comunes'", $motivos[$ids[3]]);
        $this->assertStringStartsWith('confianza_invalida', $motivos[$ids[4]]);
        $this->assertStringStartsWith('categoria_requerida', $motivos[$ids[5]]);
        $this->assertStringStartsWith('categoria_requerida', $motivos[$ids[6]]);
        $this->assertCount(1, $sin_id);
        $this->assertStringStartsWith('asignacion_invalida', $sin_id[0]);

        // Solo se guardaron los dos buenos.
        $a = $this->propuesta_id($run_id, 'A');

        $this->assertSame([$ids[0], $ids[7]], CategoryProposalItem::where('proposal_id', $a)->orderBy('article_id')->pluck('article_id')->map(function ($id) { return (int) $id; })->all());
    }

    /**
     * 🔴 Un `articulo_id` de otro dueño, borrado, inactivo o inexistente se rechaza con la MISMA
     * respuesta (`articulo_no_encontrado`): no se confirma que exista. Y los mal formados, con
     * `articulo_id_invalido`.
     *
     * @group categorias_ia
     * @test
     */
    public function un_articulo_ajeno_borrado_inactivo_o_inexistente_se_rechaza_igual()
    {
        list($ids, $run_id) = $this->preparar(2);

        $ajeno     = $this->crear_articulo('Del vecino', [], $this->vecino);
        $borrado   = $this->crear_articulo('Borrado');
        $borrado->delete();
        $inactivo  = $this->crear_articulo('Fantasma', ['status' => 'inactive']);
        $inexistente = 987654321;

        $respuesta = $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'Bisagras'),
            $this->renglon($ajeno->id, 'Bisagras'),
            $this->renglon($borrado->id, 'Bisagras'),
            $this->renglon($inactivo->id, 'Bisagras'),
            $this->renglon($inexistente, 'Bisagras'),
            $this->renglon('abc', 'Bisagras'),
            $this->renglon(0, 'Bisagras'),
            $this->renglon(-4, 'Bisagras'),
            $this->renglon(null, 'Bisagras'),
            $this->renglon(12.5, 'Bisagras'),
            ['categoria' => 'Bisagras', 'confianza' => 'segura'],
        ]);

        $respuesta->assertStatus(200);

        $json = $respuesta->json();

        $this->assertSame(11, $json['recibidas']);
        $this->assertSame(1, $json['guardadas']);

        $por_id = [];
        $sin_id = [];

        foreach ($json['rechazadas'] as $rechazada) {

            if (is_null($rechazada['articulo_id'])) {

                $sin_id[] = $rechazada['motivo'];
            } else {

                $por_id[$rechazada['articulo_id']] = $rechazada['motivo'];
            }
        }

        foreach ([(int) $ajeno->id, (int) $borrado->id, (int) $inactivo->id, $inexistente] as $id) {

            $this->assertSame('articulo_no_encontrado', $por_id[$id], 'El artículo '.$id.' tenía que ser articulo_no_encontrado.');
        }

        // Los mal formados no tienen un id utilizable: salen con `articulo_id` nulo ('abc', 0, -4, null,
        // 12.5 y el renglón sin clave `articulo_id`).
        $this->assertCount(6, $sin_id);
        $this->assertSame(['articulo_id_invalido'], array_values(array_unique($sin_id)));

        // Nada de lo rechazado quedó guardado.
        $a = $this->propuesta_id($run_id, 'A');

        $this->assertSame([$ids[0]], CategoryProposalItem::where('proposal_id', $a)->pluck('article_id')->map(function ($id) { return (int) $id; })->all());
        $this->assertSame(0, CategoryProposalItem::where('article_id', $ajeno->id)->count());
    }

    /**
     * El mismo artículo dos veces en un pedido: gana el último (como un reenvío), y el primero sale
     * rechazado con `repetido_en_el_pedido`. `recibidas` siempre es `guardadas` + `rechazadas`.
     *
     * @group categorias_ia
     * @test
     */
    public function un_articulo_repetido_en_el_pedido_se_queda_con_el_ultimo_renglon()
    {
        list($ids, $run_id) = $this->preparar(1);

        $respuesta = $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'Bisagras', null, 'segura'),
            $this->renglon($ids[0], 'Correderas', null, 'dudosa'),
        ]);

        $json = $respuesta->assertStatus(200)->json();

        $this->assertSame(2, $json['recibidas']);
        $this->assertSame(1, $json['guardadas']);
        $this->assertSame([['articulo_id' => $ids[0], 'motivo' => 'repetido_en_el_pedido']], $json['rechazadas']);

        $a    = $this->propuesta_id($run_id, 'A');
        $item = CategoryProposalItem::where('proposal_id', $a)->where('article_id', $ids[0])->first();

        $this->assertSame($this->nodo_id($a, 'Correderas'), (int) $item->node_id);
        $this->assertSame('dudosa', $item->confianza);
        $this->assertSame(1, $this->items_de($a));
    }

    /**
     * 🔴 Reenviar es idempotente: el mismo lote dos veces deja las mismas filas (mismos ids de ítem), y un
     * reenvío con otros valores REEMPLAZA el ítem en vez de duplicarlo.
     *
     * @group categorias_ia
     * @test
     */
    public function reenviar_reemplaza_el_item_sin_duplicar()
    {
        list($ids, $run_id) = $this->preparar(3);

        $lote = [
            $this->renglon($ids[0], 'Bisagras', 'Comunes'),
            $this->renglon($ids[1], 'Correderas', null, 'dudosa', 'no queda claro'),
            $this->renglon($ids[2], null, null, 'ninguna'),
        ];

        $primera = $this->asignar($run_id, 'A', $lote)->assertStatus(200)->json();
        $a       = $this->propuesta_id($run_id, 'A');
        $ids_de_item = CategoryProposalItem::where('proposal_id', $a)->orderBy('article_id')->pluck('id')->all();

        $segunda = $this->asignar($run_id, 'A', $lote)->assertStatus(200)->json();

        $this->assertSame($primera, $segunda, 'El mismo pedido tiene que dar la misma respuesta.');
        $this->assertSame(3, $this->items_de($a));
        $this->assertSame($ids_de_item, CategoryProposalItem::where('proposal_id', $a)->orderBy('article_id')->pluck('id')->all(), 'Reenviar no puede crear filas nuevas.');

        // Un reenvío con otros valores reemplaza.
        $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'Correderas', null, 'dudosa', 'cambié de idea'),
            $this->renglon($ids[2], 'Bisagras', 'De cierre suave', 'segura'),
        ])->assertStatus(200)->assertJson(['guardadas' => 2, 'progreso' => ['A' => ['asignados' => 3, 'total' => 3]]]);

        $this->assertSame(3, $this->items_de($a));
        $this->assertSame($ids_de_item, CategoryProposalItem::where('proposal_id', $a)->orderBy('article_id')->pluck('id')->all());

        $cambiado = CategoryProposalItem::where('proposal_id', $a)->where('article_id', $ids[0])->first();

        $this->assertSame($this->nodo_id($a, 'Correderas'), (int) $cambiado->node_id);
        $this->assertNull($cambiado->sub_node_id, 'La subcategoría anterior no puede quedar colgada.');
        $this->assertSame('dudosa', $cambiado->confianza);
        $this->assertSame('cambié de idea', $cambiado->motivo);
        $this->assertSame('propuesta', $cambiado->estado);

        $de_ninguna_a_segura = CategoryProposalItem::where('proposal_id', $a)->where('article_id', $ids[2])->first();

        $this->assertSame('segura', $de_ninguna_a_segura->confianza);
        $this->assertSame($this->nodo_id($a, 'Bisagras', 'De cierre suave'), (int) $de_ninguna_a_segura->sub_node_id);
    }

    /**
     * El `motivo` largo se corta al largo de la columna en vez de rechazar el renglón.
     *
     * @group categorias_ia
     * @test
     */
    public function un_motivo_largo_se_corta_en_vez_de_rechazar_el_renglon()
    {
        list($ids, $run_id) = $this->preparar(1);

        $this->asignar($run_id, 'A', [$this->renglon($ids[0], 'Bisagras', null, 'dudosa', str_repeat('m', 400))])
            ->assertStatus(200)->assertJson(['guardadas' => 1, 'rechazadas' => []]);

        $this->assertSame(255, mb_strlen(CategoryProposalItem::where('article_id', $ids[0])->value('motivo')));
    }

    /**
     * 🔴 B-01 (verificador, 5/10/2026): el `motivo` no puede llevar `<` ni `>`. Corta el pedido ENTERO con
     * 422 `validacion` y el lugar exacto en `detalle` (`asignaciones.N.motivo`), y no guarda nada (ni los
     * renglones buenos): es una falla de quien escribió el texto y tiene que verse. Con `&`, comillas,
     * barras y acentos el mismo pedido entra.
     *
     * @group categorias_ia
     * @test
     */
    public function un_motivo_con_signos_de_html_corta_el_pedido_y_no_guarda_nada()
    {
        list($ids, $run_id) = $this->preparar(3);

        $respuesta = $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'Bisagras', null, 'segura'),
            $this->renglon($ids[1], 'Correderas', null, 'dudosa', '<img src=x onerror=alert(1)>'),
            $this->renglon($ids[2], 'Correderas', null, 'dudosa', 'mide 5 > 3'),
        ]);

        $respuesta->assertStatus(422);

        $json = $respuesta->json();

        $this->assertSame('validacion', $json['error']);
        $this->assertSame(['asignaciones.1.motivo', 'asignaciones.2.motivo'], array_keys($json['detalle']));
        $this->assertSame(0, $this->items_de($this->propuesta_id($run_id, 'A')), 'El pedido se corta entero: ni el renglón bueno se guarda.');

        // Sin esos signos el mismo pedido entra.
        $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'Bisagras'),
            $this->renglon($ids[1], 'Correderas', null, 'dudosa', 'Podría ser "cajón" & puerta / tirador'),
        ])->assertStatus(200)->assertJson(['guardadas' => 2, 'rechazadas' => []]);

        $this->assertSame('Podría ser "cajón" & puerta / tirador', CategoryProposalItem::where('article_id', $ids[1])->value('motivo'));
    }

    /**
     * El tope de 500 renglones por pedido: 501 es 422 y no se guarda NADA; 500 se guardan todos.
     *
     * @group categorias_ia
     * @test
     */
    public function el_tope_es_de_500_renglones_por_pedido()
    {
        $ids  = $this->crear_articulos_en_masa(500);
        $json = $this->crear_corrida_por_api();

        $run_id = $json['run_id'];

        $renglones = [];

        foreach ($ids as $id) {

            $renglones[] = $this->renglon($id, 'Bisagras', 'Comunes');
        }

        // 501: uno más que el tope (el último con un id cualquiera).
        $demasiados = array_merge($renglones, [$this->renglon(1, 'Bisagras')]);

        $respuesta = $this->asignar($run_id, 'A', $demasiados);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json()['error']);
        $this->assertArrayHasKey('asignaciones', $respuesta->json()['detalle']);
        $this->assertSame(0, $this->items_de($this->propuesta_id($run_id, 'A')));

        // 500: entran todos.
        $this->asignar($run_id, 'A', $renglones)
            ->assertStatus(200)
            ->assertJson(['recibidas' => 500, 'guardadas' => 500, 'rechazadas' => [], 'progreso' => ['A' => ['asignados' => 500, 'total' => 500]]]);

        $this->assertSame(500, $this->items_de($this->propuesta_id($run_id, 'A')));
    }

    /**
     * Los cuerpos mal armados son 422 `validacion` con su campo, y no se guarda nada.
     *
     * @group categorias_ia
     * @test
     */
    public function los_cuerpos_mal_armados_son_422()
    {
        list($ids, $run_id) = $this->preparar(2);

        $url = 'categorias/propuestas/'.$run_id.'/asignaciones';

        $casos = [
            ['sin propuesta', ['asignaciones' => [$this->renglon($ids[0], 'Bisagras')]], 'propuesta'],
            ['propuesta que no es texto', ['propuesta' => ['A'], 'asignaciones' => [$this->renglon($ids[0], 'Bisagras')]], 'propuesta'],
            ['propuesta que no existe en la corrida', ['propuesta' => 'C', 'asignaciones' => [$this->renglon($ids[0], 'Bisagras')]], 'propuesta'],
            ['sin asignaciones', ['propuesta' => 'A'], 'asignaciones'],
            ['asignaciones que no es una lista', ['propuesta' => 'A', 'asignaciones' => 'todas'], 'asignaciones'],
        ];

        foreach ($casos as $caso) {

            list($descripcion, $cuerpo, $campo) = $caso;

            $respuesta = $this->post_admin($url, $cuerpo);

            $this->assertSame(422, $respuesta->getStatusCode(), $descripcion.': '.$respuesta->getContent());
            $this->assertSame('validacion', $respuesta->json()['error'], $descripcion);
            $this->assertArrayHasKey($campo, $respuesta->json()['detalle'], $descripcion);
            $this->assertSame(0, CategoryProposalItem::where('user_id', $this->owner->id)->count(), $descripcion);
        }
    }

    /**
     * 🔴 Solo con la corrida en `preparando`: lista, elegida, aplicando o descartada son 409
     * `corrida_cerrada` y no se guarda nada (una propuesta que el dueño ya puede ver no se modifica en
     * vivo).
     *
     * @group categorias_ia
     * @test
     */
    public function una_corrida_que_no_esta_preparando_no_acepta_asignaciones()
    {
        list($ids, $run_id) = $this->preparar(2);

        foreach (['lista', 'elegida', 'aplicando', 'descartada'] as $estado) {

            CategoryProposalRun::where('id', $run_id)->update(['estado' => $estado]);

            $respuesta = $this->asignar($run_id, 'A', [$this->renglon($ids[0], 'Bisagras')]);

            $respuesta->assertStatus(409);

            $json = $respuesta->json();

            $this->assertSame('corrida_cerrada', $json['error'], $estado);
            $this->assertSame($run_id, $json['run_id']);
            $this->assertSame($estado, $json['estado']);
            $this->assertNotEmpty($json['message']);
            $this->assertSame(0, CategoryProposalItem::where('user_id', $this->owner->id)->count(), 'Con la corrida '.$estado.' se guardó algo.');
        }
    }

    /**
     * 🔴 Un `run_id` de otro comercio se contesta igual que uno inexistente (404 `no_encontrado`, el mismo
     * cuerpo) y no se escribe nada en la corrida ajena. Vale para las seis rutas con id.
     *
     * @group categorias_ia
     * @test
     */
    public function una_corrida_ajena_es_404_igual_que_una_inexistente_en_todas_las_rutas()
    {
        list($ids, $run_id) = $this->preparar(2);

        // La corrida del VECINO, con una propuesta, y un ítem suyo que no tiene que cambiar.
        $articulo_vecino = $this->crear_articulo('Del vecino', [], $this->vecino);
        $ajena = $this->sembrar_corrida([
            'estado'     => 'preparando',
            'propuestas' => ['A' => ['arbol' => ['Bisagras' => []], 'items' => [[$articulo_vecino, 'Bisagras', null, 'segura']]]],
        ], $this->vecino);

        $ajena_id    = (int) $ajena['run']->id;
        $inexistente = 987654321;

        $pedidos = function ($id) use ($ids) {

            return [
                ['GET',  'categorias/propuestas/'.$id, []],
                ['POST', 'categorias/propuestas/'.$id.'/asignaciones', ['propuesta' => 'A', 'asignaciones' => [$this->renglon($ids[0], 'Bisagras')]]],
                ['GET',  'categorias/propuestas/'.$id.'/pendientes?propuesta=A', []],
                ['POST', 'categorias/propuestas/'.$id.'/listo', ['forzar' => true]],
                ['POST', 'categorias/propuestas/'.$id.'/descartar', []],
            ];
        };

        foreach ($pedidos($ajena_id) as $indice => $pedido) {

            $ajeno_resp = $this->json($pedido[0], $this->url_admin($pedido[1]), $pedido[2], $this->cabeceras_de_admin());
            $inexist    = $this->json($pedido[0], $this->url_admin($pedidos($inexistente)[$indice][1]), $pedido[2], $this->cabeceras_de_admin());

            $this->assertSame(404, $ajeno_resp->getStatusCode(), $pedido[0].' '.$pedido[1].': '.$ajeno_resp->getContent());
            $this->assertSame(404, $inexist->getStatusCode());
            $this->assertSame($inexist->json(), $ajeno_resp->json(), 'Una corrida ajena tiene que contestar EXACTAMENTE lo mismo que una inexistente.');
            $this->assertSame('no_encontrado', $ajeno_resp->json()['error']);
        }

        // La corrida del vecino quedó intacta.
        $this->assertSame('preparando', CategoryProposalRun::find($ajena_id)->estado);
        $this->assertSame(1, CategoryProposalItem::where('user_id', $this->vecino->id)->count());
        $this->assertSame(0, CategoryProposalItem::where('user_id', $this->owner->id)->count());
    }

    // ------------------------------------------------------------------------------------------
    // "Mantener las mías"
    // ------------------------------------------------------------------------------------------

    /**
     * 🔴 La propuesta "mantener" solo acepta artículos SIN categoría viva (sin categoría de tres maneras:
     * NULL, 0 y categoría borrada) y solo categorías que el dueño ya tiene. Una propuesta nueva acepta
     * cualquier artículo.
     *
     * @group categorias_ia
     * @test
     */
    public function mantener_solo_acepta_articulos_sin_categoria_y_categorias_existentes()
    {
        $herrajes = $this->crear_categoria_real('Herrajes');
        $bisagras = $this->crear_subcategoria_real('Bisagras', $herrajes);
        $this->crear_categoria_real('Pinturas');
        $borrada  = $this->crear_categoria_real('Se borro');

        $sin_categoria = $this->crear_articulo('Sin categoria');
        $con_categoria = $this->crear_articulo('Con categoria', ['category_id' => $herrajes->id]);
        $con_borrada   = $this->crear_articulo('Con categoria borrada', ['category_id' => $borrada->id]);
        $con_cero      = $this->crear_articulo('Con cero', ['category_id' => 0]);
        $otro          = $this->crear_articulo('Otro sin categoria');
        $borrada->delete();

        $json = $this->crear_corrida_por_api([
            $this->propuestas_de_ejemplo()[0],
            ['clave' => 'mantener', 'tipo' => 'mantener', 'nombre' => 'Mantener mis categorias'],
        ]);

        $run_id = $json['run_id'];

        $respuesta = $this->asignar($run_id, 'mantener', [
            $this->renglon($sin_categoria->id, 'herrajes', 'BISAGRAS'),
            $this->renglon($con_categoria->id, 'Herrajes'),
            $this->renglon($con_borrada->id, 'Pinturas'),
            $this->renglon($con_cero->id, 'Una categoria nueva'),
            $this->renglon($otro->id, 'Se borro'),
        ]);

        $respuesta->assertStatus(200);

        $json = $respuesta->json();

        $this->assertSame(5, $json['recibidas']);
        $this->assertSame(2, $json['guardadas']);

        $motivos = [];

        foreach ($json['rechazadas'] as $rechazada) {

            $motivos[$rechazada['articulo_id']] = $rechazada['motivo'];
        }

        // El artículo con categoría viva sale rechazado, y las categorías que "mantener" no tiene
        // (una inventada y una que se borró antes de armar la corrida) también.
        $this->assertStringStartsWith('articulo_con_categoria', $motivos[(int) $con_categoria->id]);
        $this->assertSame("categoria_inexistente: 'Una categoria nueva'", $motivos[(int) $con_cero->id]);
        $this->assertSame("categoria_inexistente: 'Se borro'", $motivos[(int) $otro->id]);

        // Entraron el de siempre sin categoría y el que tenía una categoría borrada.
        $mantener = $this->propuesta_id($run_id, 'mantener');

        $this->assertSame(
            [(int) $sin_categoria->id, (int) $con_borrada->id],
            CategoryProposalItem::where('proposal_id', $mantener)->orderBy('article_id')->pluck('article_id')->map(function ($id) { return (int) $id; })->all()
        );

        // El nombre normalizado se resolvió al nodo de la categoría real y de su subcategoría real.
        $item = CategoryProposalItem::where('proposal_id', $mantener)->where('article_id', $sin_categoria->id)->first();

        $this->assertSame((int) $herrajes->id, (int) CategoryProposalNode::find($item->node_id)->existing_category_id);
        $this->assertSame((int) $bisagras->id, (int) CategoryProposalNode::find($item->sub_node_id)->existing_sub_category_id);

        // La propuesta nueva, en cambio, SÍ acepta el artículo que ya tiene categoría.
        $this->asignar($run_id, 'A', [$this->renglon($con_categoria->id, 'Bisagras', 'Comunes')])
            ->assertStatus(200)->assertJson(['guardadas' => 1, 'rechazadas' => []]);

        // El progreso de mantener cuenta SOLO los artículos sin categoría viva: sin, borrada, cero y otro.
        $this->assertSame(
            ['mantener' => ['asignados' => 2, 'total' => 4]],
            $this->asignar($run_id, 'mantener', [])->assertStatus(200)->json()['progreso']
        );
    }

    // ------------------------------------------------------------------------------------------
    // pendientes y retomar
    // ------------------------------------------------------------------------------------------

    /**
     * `pendientes` devuelve los artículos del dueño sin ítem en esa propuesta, con los campos exactos del
     * contrato, por id ascendente y con `quedan` total.
     *
     * @group categorias_ia
     * @test
     */
    public function pendientes_devuelve_lo_que_falta_con_los_campos_del_contrato()
    {
        $marca     = \App\Models\Brand::create(['name' => 'Hafele', 'user_id' => $this->owner->id]);
        $a1 = $this->crear_articulo('Bisagra 3 pulgadas', ['bar_code' => '7791111111111', 'provider_code' => 'BIS-3', 'brand_id' => $marca->id]);
        $a2 = $this->crear_articulo('Corredera', ['bar_code' => '  ', 'provider_code' => 'COR-1']);
        $a3 = $this->crear_articulo('Tornillo');
        $a4 = $this->crear_articulo('Pomo');

        // Lo que NO es del universo no aparece: borrado, inactivo, del vecino.
        $this->crear_articulo('Borrado')->delete();
        $this->crear_articulo('Fantasma', ['status' => 'inactive']);
        $this->crear_articulo('Del vecino', [], $this->vecino);

        $json   = $this->crear_corrida_por_api();
        $run_id = $json['run_id'];

        $respuesta = $this->get_admin('categorias/propuestas/'.$run_id.'/pendientes?propuesta=A');

        $respuesta->assertStatus(200);

        $cuerpo = $respuesta->json();

        $this->assertSame(['pendientes', 'quedan'], array_keys($cuerpo));
        $this->assertSame(4, $cuerpo['quedan']);
        $this->assertSame(
            [(int) $a1->id, (int) $a2->id, (int) $a3->id, (int) $a4->id],
            array_column($cuerpo['pendientes'], 'id'),
            'Por id ascendente.'
        );
        $this->assertSame(['id', 'nombre', 'codigo_de_barras', 'codigo_de_proveedor', 'marca'], array_keys($cuerpo['pendientes'][0]));
        $this->assertSame([
            'id'                  => (int) $a1->id,
            'nombre'              => 'Bisagra 3 pulgadas',
            'codigo_de_barras'    => '7791111111111',
            'codigo_de_proveedor' => 'BIS-3',
            'marca'               => 'Hafele',
        ], $cuerpo['pendientes'][0]);
        $this->assertSame(['id' => (int) $a2->id, 'nombre' => 'Corredera', 'codigo_de_barras' => null, 'codigo_de_proveedor' => 'COR-1', 'marca' => null], $cuerpo['pendientes'][1]);

        // Con límite: devuelve ese tanto y `quedan` sigue siendo el total que falta.
        $chico = $this->get_admin('categorias/propuestas/'.$run_id.'/pendientes?propuesta=A&limite=2')->json();

        $this->assertSame([(int) $a1->id, (int) $a2->id], array_column($chico['pendientes'], 'id'));
        $this->assertSame(4, $chico['quedan']);

        // Y un límite fuera de rango se acota al tope de configuración.
        config(['catalogo_ia.articulos_por_pagina_maximo' => 3]);

        $this->assertCount(3, $this->get_admin('categorias/propuestas/'.$run_id.'/pendientes?propuesta=A&limite=500')->json()['pendientes']);
    }

    /**
     * 🔴 La corrida es RETOMABLE: lo ya guardado deja de ser pendiente, y un corte de la skill no pierde
     * nada porque el estado vive en el servidor. Cada propuesta lleva su cuenta aparte.
     *
     * @group categorias_ia
     * @test
     */
    public function la_corrida_se_retoma_con_lo_que_falta()
    {
        list($ids, $run_id) = $this->preparar(5);

        // Primera "sesión" de la skill: carga 3 de 5 de la propuesta A y se corta.
        $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'Bisagras', 'Comunes'),
            $this->renglon($ids[1], 'Bisagras', 'De cierre suave'),
            $this->renglon($ids[2], 'Correderas'),
        ])->assertStatus(200);

        // Segunda sesión: pregunta qué falta.
        $faltan = $this->get_admin('categorias/propuestas/'.$run_id.'/pendientes?propuesta=A')->json();

        $this->assertSame(2, $faltan['quedan']);
        $this->assertSame([$ids[3], $ids[4]], array_column($faltan['pendientes'], 'id'));

        // La B no avanzó: le falta todo.
        $this->assertSame(5, $this->get_admin('categorias/propuestas/'.$run_id.'/pendientes?propuesta=B')->json()['quedan']);

        // Carga lo que faltaba.
        $this->asignar($run_id, 'A', [
            $this->renglon($ids[3], 'Correderas', null, 'dudosa'),
            $this->renglon($ids[4], null, null, 'ninguna'),
        ])->assertStatus(200)->assertJson(['progreso' => ['A' => ['asignados' => 5, 'total' => 5]]]);

        $terminada = $this->get_admin('categorias/propuestas/'.$run_id.'/pendientes?propuesta=A')->json();

        $this->assertSame(0, $terminada['quedan']);
        $this->assertSame([], $terminada['pendientes']);
    }

    /**
     * Los artículos que aparecen en el catálogo MIENTRAS la skill trabaja también son pendientes (el
     * `total` sigue cerrando), y los que se borran o desactivan dejan de serlo.
     *
     * @group categorias_ia
     * @test
     */
    public function el_catalogo_que_cambia_durante_la_corrida_se_refleja_en_los_pendientes()
    {
        list($ids, $run_id) = $this->preparar(3);

        $nuevo = $this->crear_articulo('Cargado despues');

        $faltan = $this->get_admin('categorias/propuestas/'.$run_id.'/pendientes?propuesta=A')->json();

        $this->assertSame(4, $faltan['quedan']);
        $this->assertContains((int) $nuevo->id, array_column($faltan['pendientes'], 'id'));

        Article::find($ids[0])->delete();
        Article::where('id', $ids[1])->update(['status' => 'inactive']);

        $faltan = $this->get_admin('categorias/propuestas/'.$run_id.'/pendientes?propuesta=A')->json();

        $this->assertSame(2, $faltan['quedan']);
        $this->assertSame([(int) $ids[2], (int) $nuevo->id], array_column($faltan['pendientes'], 'id'));
    }

    /**
     * En "mantener" los pendientes son solo los artículos sin categoría viva (los demás no entran en esa
     * propuesta), y salen de a medida que se asignan.
     *
     * @group categorias_ia
     * @test
     */
    public function en_mantener_los_pendientes_son_solo_los_articulos_sin_categoria()
    {
        $herrajes = $this->crear_categoria_real('Herrajes');

        $sin_categoria = $this->crear_articulo('Sin categoria');
        $this->crear_articulo('Con categoria', ['category_id' => $herrajes->id]);
        $con_cero = $this->crear_articulo('Con cero', ['category_id' => 0]);

        $json = $this->crear_corrida_por_api([
            ['clave' => 'mantener', 'tipo' => 'mantener', 'nombre' => 'Mantener mis categorias'],
            $this->propuestas_de_ejemplo()[0],
        ]);

        $run_id = $json['run_id'];

        $faltan = $this->get_admin('categorias/propuestas/'.$run_id.'/pendientes?propuesta=mantener')->json();

        $this->assertSame(2, $faltan['quedan']);
        $this->assertSame([(int) $sin_categoria->id, (int) $con_cero->id], array_column($faltan['pendientes'], 'id'));

        $this->asignar($run_id, 'mantener', [$this->renglon($sin_categoria->id, 'Herrajes')])->assertStatus(200);

        $this->assertSame(1, $this->get_admin('categorias/propuestas/'.$run_id.'/pendientes?propuesta=mantener')->json()['quedan']);

        // La nueva, en cambio, necesita los tres.
        $this->assertSame(3, $this->get_admin('categorias/propuestas/'.$run_id.'/pendientes?propuesta=A')->json()['quedan']);
    }

    /**
     * 🔴 B-14 (verificador, 5/10/2026): un parámetro que se espera escalar y llega como ARREGLO (`propuesta[]`,
     * `limite[]`, o `reemplazar` / `forzar` / `propuesta` como arreglo en el cuerpo) nunca da 500: o se lo
     * trata como si no estuviera (los números y los booleanos) o es un 422 (los textos).
     *
     * @group categorias_ia
     * @test
     */
    public function los_parametros_escalares_que_llegan_como_arreglo_no_dan_500()
    {
        list($ids, $run_id) = $this->preparar(2);

        $base = 'categorias/propuestas/'.$run_id.'/pendientes';

        // Query: la clave de la propuesta es texto (422); el límite, un número ((int) de un arreglo no tira).
        $this->get_admin($base.'?propuesta[]=A')->assertStatus(422);
        $this->assertCount(2, $this->get_admin($base.'?propuesta=A&limite[]=1')->assertStatus(200)->json()['pendientes'], 'Un `limite` en arreglo es el límite por defecto, no un 1.');
        $this->get_admin($base.'?propuesta[]=A&limite[]=5')->assertStatus(422);

        // Cuerpo: `reemplazar` y `forzar` como arreglo son "no" (el booleano de un arreglo es falso).
        $this->post_admin('categorias/propuestas', ['reemplazar' => ['x'], 'propuestas' => $this->propuestas_de_ejemplo()])
            ->assertStatus(409)->assertJson(['error' => 'ya_hay_una_propuesta']);

        $this->post_admin('categorias/propuestas/'.$run_id.'/listo', ['forzar' => ['x']])
            ->assertStatus(422)->assertJson(['error' => 'incompleto']);

        // La clave de la propuesta, las asignaciones y sus campos como arreglo: 422 o renglón rechazado.
        $this->post_admin('categorias/propuestas/'.$run_id.'/asignaciones', ['propuesta' => ['A'], 'asignaciones' => []])->assertStatus(422);

        $this->asignar($run_id, 'A', [
            ['articulo_id' => [$ids[0]], 'categoria' => 'Bisagras', 'confianza' => 'segura'],
            ['articulo_id' => $ids[1], 'categoria' => ['Bisagras'], 'subcategoria' => ['Comunes'], 'confianza' => ['segura'], 'motivo' => ['x']],
        ])->assertStatus(200)->assertJson(['guardadas' => 0]);

        $this->assertSame(0, $this->items_de($this->propuesta_id($run_id, 'A')));
    }

    /**
     * `pendientes` sin la propuesta o con una que la corrida no tiene es 422 `validacion`.
     *
     * @group categorias_ia
     * @test
     */
    public function pendientes_sin_propuesta_o_con_una_inexistente_es_422()
    {
        list($ids, $run_id) = $this->preparar(1);

        foreach (['', '?propuesta=', '?propuesta=Z', '?propuesta=C'] as $query) {

            $respuesta = $this->get_admin('categorias/propuestas/'.$run_id.'/pendientes'.$query);

            $respuesta->assertStatus(422);
            $this->assertSame('validacion', $respuesta->json()['error'], $query);
            $this->assertArrayHasKey('propuesta', $respuesta->json()['detalle'], $query);
        }
    }

    // ------------------------------------------------------------------------------------------
    // listo
    // ------------------------------------------------------------------------------------------

    /**
     * 🔴 `listo` con propuestas incompletas es 422 `incompleto` con `faltan` por propuesta, y la corrida
     * SIGUE `preparando`: el dueño no la ve.
     *
     * @group categorias_ia
     * @test
     */
    public function listo_incompleto_es_422_con_lo_que_falta_y_la_corrida_no_se_publica()
    {
        list($ids, $run_id) = $this->preparar(5);

        // A: 3 de 5. B: nada.
        $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'Bisagras'), $this->renglon($ids[1], 'Bisagras'), $this->renglon($ids[2], 'Correderas'),
        ])->assertStatus(200);

        $respuesta = $this->post_admin('categorias/propuestas/'.$run_id.'/listo');

        $respuesta->assertStatus(422);

        $json = $respuesta->json();

        $this->assertSame('incompleto', $json['error']);
        $this->assertSame(['A' => 2, 'B' => 5], $json['faltan']);
        $this->assertNotEmpty($json['message']);
        $this->assertSame('preparando', CategoryProposalRun::find($run_id)->estado);

        // Completar A no alcanza: falta B.
        $this->asignar($run_id, 'A', [$this->renglon($ids[3], 'Correderas'), $this->renglon($ids[4], 'Correderas')])->assertStatus(200);

        $this->assertSame(['B' => 5], $this->post_admin('categorias/propuestas/'.$run_id.'/listo')->assertStatus(422)->json()['faltan']);
    }

    /**
     * `forzar` publica aunque falten: los que faltan quedan sin ítem y el aplicar no los toca. La
     * respuesta trae los números de cada propuesta.
     *
     * @group categorias_ia
     * @test
     */
    public function listo_con_forzar_publica_aunque_falten_y_trae_los_numeros()
    {
        list($ids, $run_id) = $this->preparar(5);

        $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'Bisagras'),
            $this->renglon($ids[1], 'Bisagras', null, 'dudosa'),
            $this->renglon($ids[2], 'Correderas', null, 'dudosa'),
            $this->renglon($ids[3], null, null, 'ninguna'),
        ])->assertStatus(200);

        $this->asignar($run_id, 'B', [$this->renglon($ids[0], 'Muebles')])->assertStatus(200);

        foreach ([true, 'true', 1] as $forzar) {

            CategoryProposalRun::where('id', $run_id)->update(['estado' => 'preparando']);

            $this->post_admin('categorias/propuestas/'.$run_id.'/listo', ['forzar' => $forzar])
                ->assertStatus(200)
                ->assertExactJson([
                    'estado'     => 'lista',
                    'propuestas' => [
                        ['clave' => 'A', 'seguros' => 1, 'dudosos' => 2, 'sin_asignar' => 1],
                        ['clave' => 'B', 'seguros' => 1, 'dudosos' => 0, 'sin_asignar' => 0],
                    ],
                ]);
        }

        $this->assertSame('lista', CategoryProposalRun::find($run_id)->estado);

        // A los artículos que faltaron no se les inventó ningún ítem.
        $this->assertSame(4, $this->items_de($this->propuesta_id($run_id, 'A')));
        $this->assertSame(1, $this->items_de($this->propuesta_id($run_id, 'B')));
    }

    /**
     * Con todo cargado `listo` pasa sin `forzar`, y repetirlo es idempotente (200 con los mismos números).
     * Las demás corridas no se pueden publicar.
     *
     * @group categorias_ia
     * @test
     */
    public function listo_completo_pasa_y_repetirlo_es_idempotente()
    {
        list($ids, $run_id) = $this->preparar(2);

        foreach (['A', 'B'] as $clave) {

            $categoria = $clave === 'A' ? 'Bisagras' : 'Muebles';

            $this->asignar($run_id, $clave, [$this->renglon($ids[0], $categoria), $this->renglon($ids[1], null, null, 'ninguna')])->assertStatus(200);
        }

        $esperado = [
            'estado'     => 'lista',
            'propuestas' => [
                ['clave' => 'A', 'seguros' => 1, 'dudosos' => 0, 'sin_asignar' => 1],
                ['clave' => 'B', 'seguros' => 1, 'dudosos' => 0, 'sin_asignar' => 1],
            ],
        ];

        $this->post_admin('categorias/propuestas/'.$run_id.'/listo')->assertStatus(200)->assertExactJson($esperado);
        $this->post_admin('categorias/propuestas/'.$run_id.'/listo')->assertStatus(200)->assertExactJson($esperado);

        $this->assertSame('lista', CategoryProposalRun::find($run_id)->estado);

        foreach (['elegida', 'aplicando', 'descartada'] as $estado) {

            CategoryProposalRun::where('id', $run_id)->update(['estado' => $estado]);

            $respuesta = $this->post_admin('categorias/propuestas/'.$run_id.'/listo');

            $respuesta->assertStatus(409);
            $this->assertSame('corrida_cerrada', $respuesta->json()['error'], $estado);
            $this->assertSame($estado, CategoryProposalRun::find($run_id)->estado, 'Un `listo` rechazado no puede cambiar el estado.');
        }
    }

    /**
     * Un artículo cargado DESPUÉS de completar vuelve a dejar la corrida incompleta (retomable de punta a
     * punta), y uno borrado deja de hacer falta.
     *
     * @group categorias_ia
     * @test
     */
    public function un_articulo_nuevo_vuelve_a_dejar_la_corrida_incompleta()
    {
        list($ids, $run_id) = $this->preparar(2);

        foreach (['A' => 'Bisagras', 'B' => 'Muebles'] as $clave => $categoria) {

            $this->asignar($run_id, $clave, [$this->renglon($ids[0], $categoria), $this->renglon($ids[1], $categoria)])->assertStatus(200);
        }

        $nuevo = $this->crear_articulo('Cargado despues');

        $this->post_admin('categorias/propuestas/'.$run_id.'/listo')->assertStatus(422)->assertJson(['faltan' => ['A' => 1, 'B' => 1]]);

        $nuevo->delete();

        $this->post_admin('categorias/propuestas/'.$run_id.'/listo')->assertStatus(200);
    }

    // ------------------------------------------------------------------------------------------
    // descartar, mostrar, actual
    // ------------------------------------------------------------------------------------------

    /**
     * `descartar` da de baja la corrida (con su fecha), es idempotente, y después se puede crear otra sin
     * `reemplazar`. Una corrida elegida (o aplicándose) NO se descarta: 409 `corrida_elegida`.
     *
     * @group categorias_ia
     * @test
     */
    public function descartar_da_de_baja_y_una_elegida_no_se_descarta()
    {
        list($ids, $run_id) = $this->preparar(2);

        $this->post_admin('categorias/propuestas/'.$run_id.'/descartar')
            ->assertStatus(200)
            ->assertExactJson(['run_id' => $run_id, 'estado' => 'descartada']);

        $this->assertSame('descartada', CategoryProposalRun::find($run_id)->estado);
        $this->assertNotNull(CategoryProposalRun::find($run_id)->descartada_at);

        // Descartar otra vez: 200 y la misma marca.
        $marca = CategoryProposalRun::find($run_id)->descartada_at;

        $this->post_admin('categorias/propuestas/'.$run_id.'/descartar')->assertStatus(200);
        $this->assertEquals($marca, CategoryProposalRun::find($run_id)->descartada_at);

        // Ya se puede crear otra sin reemplazar.
        $nueva = $this->crear_corrida_por_api();

        $this->assertNotSame($run_id, $nueva['run_id']);

        // Una lista también se descarta.
        $this->post_admin('categorias/propuestas/'.$nueva['run_id'].'/listo', ['forzar' => true])->assertStatus(200);
        $this->post_admin('categorias/propuestas/'.$nueva['run_id'].'/descartar')->assertStatus(200);

        // Una elegida, no.
        foreach (['elegida', 'aplicando'] as $estado) {

            CategoryProposalRun::where('id', $nueva['run_id'])->update(['estado' => $estado, 'descartada_at' => null]);

            $respuesta = $this->post_admin('categorias/propuestas/'.$nueva['run_id'].'/descartar');

            $respuesta->assertStatus(409);
            $this->assertSame('corrida_elegida', $respuesta->json()['error']);
            $this->assertSame($estado, CategoryProposalRun::find($nueva['run_id'])->estado);
            $this->assertNull(CategoryProposalRun::find($nueva['run_id'])->descartada_at);
        }
    }

    /**
     * `mostrar` y `actual` dicen el estado y el avance con los campos del contrato, con valores exactos
     * (asignados, total, seguros, dudosos, sin_asignar) por propuesta.
     *
     * @group categorias_ia
     * @test
     */
    public function mostrar_y_actual_dicen_el_avance_de_cada_propuesta()
    {
        list($ids, $run_id, $creada) = $this->preparar(4);

        $this->asignar($run_id, 'A', [
            $this->renglon($ids[0], 'Bisagras'),
            $this->renglon($ids[1], 'Bisagras', null, 'dudosa'),
            $this->renglon($ids[2], null, null, 'ninguna'),
        ])->assertStatus(200);

        $a_id = $this->propuesta_id($run_id, 'A');
        $b_id = $this->propuesta_id($run_id, 'B');

        $esperado = [
            'run_id'          => $run_id,
            'estado'          => 'preparando',
            'articulos_total' => 4,
            'propuestas'      => [
                ['clave' => 'A', 'id' => $a_id, 'tipo' => 'nueva', 'asignados' => 3, 'total' => 4, 'seguros' => 1, 'dudosos' => 1, 'sin_asignar' => 1],
                ['clave' => 'B', 'id' => $b_id, 'tipo' => 'nueva', 'asignados' => 0, 'total' => 4, 'seguros' => 0, 'dudosos' => 0, 'sin_asignar' => 0],
            ],
        ];

        $this->get_admin('categorias/propuestas/'.$run_id)->assertStatus(200)->assertExactJson($esperado);
        $this->get_admin('categorias/propuestas/actual')->assertStatus(200)->assertExactJson($esperado);
    }

    /**
     * `actual` es la última corrida NO descartada; sin ninguna es 404 `sin_propuesta`.
     *
     * @group categorias_ia
     * @test
     */
    public function actual_es_la_ultima_no_descartada_o_404_sin_propuesta()
    {
        $respuesta = $this->get_admin('categorias/propuestas/actual');

        $respuesta->assertStatus(404);
        $this->assertSame('sin_propuesta', $respuesta->json()['error']);
        $this->assertNotEmpty($respuesta->json()['message']);

        // Solo una descartada: sigue sin haber propuesta.
        $descartada = CategoryProposalRun::create(['user_id' => $this->owner->id, 'estado' => 'descartada', 'origen' => 'skill']);

        $this->get_admin('categorias/propuestas/actual')->assertStatus(404);

        // La del vecino no es mía.
        CategoryProposalRun::create(['user_id' => $this->vecino->id, 'estado' => 'lista', 'origen' => 'skill']);

        $this->get_admin('categorias/propuestas/actual')->assertStatus(404);

        // Aparece la mía.
        $mia = CategoryProposalRun::create(['user_id' => $this->owner->id, 'estado' => 'lista', 'origen' => 'skill', 'articulos_total' => 0]);

        $this->assertSame((int) $mia->id, $this->get_admin('categorias/propuestas/actual')->assertStatus(200)->json()['run_id']);

        // `mostrar` de una descartada sí la muestra (es por id).
        $this->assertSame('descartada', $this->get_admin('categorias/propuestas/'.$descartada->id)->assertStatus(200)->json()['estado']);
    }
}
