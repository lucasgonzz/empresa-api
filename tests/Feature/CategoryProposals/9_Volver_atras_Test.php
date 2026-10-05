<?php

namespace Tests\Feature\CategoryProposals;

use App\Http\Controllers\Helpers\category_proposal\CategoryProposalAplicarHelper;
use App\Models\Article;
use App\Models\Category;
use App\Models\CategoryProposalItem;
use App\Models\CategoryProposalNode;
use App\Models\CategoryProposalRun;
use App\Models\SubCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Volver atrás (cambiar de sistema): `POST category-proposal-runs/{id}/volver-atras` (misión
 * categorizacion-tres-modelos, 5/10/2026). Contrato B §6.5 y plan §4.4 (regla (b)).
 *
 * Qué protege:
 *  - Cuándo se puede (`puede_cambiar`, la UNA sola función que comparten `volver_atras` y el payload de
 *    `actual`) y cada motivo por el que no: `hay_revisiones`, `articulos_editados` y `categorias_editadas`.
 *  - Que deshace TODO lo que hizo el aplicar: devuelve a cada artículo lo que tenía (NULL, 0, una
 *    categoría viva o una que ya estaba en la papelera), manda a la papelera lo que el aplicar creó,
 *    restaura lo que el aplicar mandó a la papelera y deja ítems y nodos como antes de elegir.
 *  - Que después se puede elegir OTRA propuesta (o la misma de nuevo).
 *  - Que "mantener" no borra las categorías del dueño ni pisa lo que se categorizó a mano.
 *
 * El doble pedido y la tenencia están en `10_Doble_pedido_y_tenencia_de_la_eleccion_Test`.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Volver_atras_Test extends CategoryProposalsTestCase
{
    use AyudasDeLaEleccion;

    /**
     * Un comercio con una categoría previa, cuatro artículos y dos sistemas (A y B) en la misma corrida.
     *
     *   a1 en Vieja / Sub vieja ... A: Bisagras/Comunes (segura)    B: Herrajes (segura)
     *   a2 con 0 y 0 .............. A: Bisagras/Comunes (dudosa)    B: Herrajes (dudosa)
     *   a3 sin categoría .......... A: Correderas (segura)          B: Herrajes (segura)
     *   a4 en Vieja ............... A: ninguna                      B: ninguna
     *
     * @return array
     */
    protected function escenario()
    {
        $vieja     = $this->categoria_real('Vieja');
        $sub_vieja = $this->subcategoria_real('Sub vieja', $vieja);

        $a1 = $this->crear_articulo('a1', ['category_id' => $vieja->id, 'sub_category_id' => $sub_vieja->id]);
        $a2 = $this->crear_articulo('a2', ['category_id' => 0, 'sub_category_id' => 0]);
        $a3 = $this->crear_articulo('a3');
        $a4 = $this->crear_articulo('a4', ['category_id' => $vieja->id]);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Bisagras' => ['Comunes'], 'Correderas' => []],
                    'items' => [
                        [$a1, 'Bisagras', 'Comunes', 'segura'],
                        [$a2, 'Bisagras', 'Comunes', 'dudosa', 'no queda claro'],
                        [$a3, 'Correderas', null, 'segura'],
                        [$a4, null, null, 'ninguna'],
                    ],
                ],
                'B' => [
                    'arbol' => ['Herrajes' => []],
                    'items' => [
                        [$a1, 'Herrajes', null, 'segura'],
                        [$a2, 'Herrajes', null, 'dudosa', 'no queda claro'],
                        [$a3, 'Herrajes', null, 'segura'],
                        [$a4, null, null, 'ninguna'],
                    ],
                ],
            ],
        ]);

        return [
            'vieja'     => $vieja,
            'sub_vieja' => $sub_vieja,
            'articulos' => [$a1, $a2, $a3, $a4],
            'run'       => $sembrado['run'],
            'a'         => $sembrado['propuestas']['A'],
            'b'         => $sembrado['propuestas']['B'],
        ];
    }

    /**
     * Un sistema chico ya ELEGIDO (sin categorías previas, sin eliminar nada), para los tests de cada
     * motivo de rechazo: Bisagras / Comunes con a1, Correderas con a2 y un dudoso en a3.
     *
     * @return array  ['articulos', 'run', 'proposal', 'items', 'bisagras', 'comunes', 'correderas']
     */
    protected function sistema_elegido_simple()
    {
        $articulos = $this->crear_articulos(['a1', 'a2', 'a3']);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Bisagras' => ['Comunes'], 'Correderas' => []],
                    'items' => [
                        [$articulos[0], 'Bisagras', 'Comunes', 'segura'],
                        [$articulos[1], 'Correderas', null, 'segura'],
                        [$articulos[2], 'Bisagras', null, 'dudosa', 'no queda claro'],
                    ],
                ],
            ],
        ]);

        $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal'])->assertStatus(200);

        $bisagras = $this->categorias_llamadas('Bisagras')->first();

        return [
            'articulos'  => $articulos,
            'run'        => $sembrado['run'],
            'proposal'   => $sembrado['propuestas']['A']['proposal'],
            'items'      => $sembrado['propuestas']['A']['items'],
            'bisagras'   => $bisagras,
            'comunes'    => SubCategory::where('category_id', $bisagras->id)->where('name', 'Comunes')->first(),
            'correderas' => $this->categorias_llamadas('Correderas')->first(),
        ];
    }

    /**
     * Lo que dice `puede_cambiar` de la corrida, leída fresca de la base.
     *
     * @param  \App\Models\CategoryProposalRun $run
     * @return array
     */
    protected function puede_cambiar($run)
    {
        return CategoryProposalAplicarHelper::puede_cambiar(CategoryProposalRun::find($run->id));
    }

    /**
     * El 409 de "no se puede volver atrás" con su motivo, y la comprobación de que no se tocó nada.
     *
     * @param  array  $s       El sistema elegido.
     * @param  string $motivo
     * @return void
     */
    protected function afirmar_que_no_se_puede_volver_atras($s, $motivo)
    {
        $this->assertSame(['puede' => false, 'motivo' => $motivo], $this->puede_cambiar($s['run']), 'puede_cambiar dice lo mismo que el 409.');

        $respuesta = $this->pedir_volver_atras($s['run']);

        $respuesta->assertStatus(409);
        $this->assertSame('no_se_puede_volver_atras', $respuesta->json('error'));
        $this->assertSame($motivo, $respuesta->json('motivo'));
        $this->assertNotEmpty($respuesta->json('message'));

        $run = CategoryProposalRun::find($s['run']->id);
        $this->assertSame('elegida', $run->estado, 'No se deshizo nada.');
        $this->assertSame($s['proposal']->id, (int) $run->propuesta_elegida_id);
        $this->assertSame('aplicada', CategoryProposalItem::find($s['items'][0]->id)->estado);
    }

    // ---------------------------------------------------------------------------------------------
    // Permitido y lo que deshace
    // ---------------------------------------------------------------------------------------------

    /**
     * Justo después de elegir se puede volver atrás: la corrida vuelve a `lista`, sin elección, sin
     * resumen y sin la marca de revisión.
     *
     * @test
     * @group categorias_ia
     */
    public function se_puede_volver_atras_justo_despues_de_elegir()
    {
        $e = $this->escenario();

        $this->pedir_elegir($e['run'], $e['a']['proposal'], true)->assertStatus(200);
        $this->assertSame(['puede' => true, 'motivo' => null], $this->puede_cambiar($e['run']));

        $respuesta = $this->pedir_volver_atras($e['run']);

        $respuesta->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertNull($respuesta->json('ya_estaba'));
        $this->assertSame($e['run']->id, $respuesta->json('run.id'));
        $this->assertSame('lista', $respuesta->json('run.estado'));

        $run = CategoryProposalRun::find($e['run']->id);
        $this->assertSame('lista', $run->estado);
        $this->assertNull($run->propuesta_elegida_id);
        $this->assertNull($run->elegida_at);
        $this->assertNull($run->elegida_por);
        $this->assertFalse($run->elegida_con_acceso_maestro);
        $this->assertFalse($run->eliminar_categorias_vacias);
        $this->assertNull($run->categorias_eliminadas);
        $this->assertNull($run->resultado);
        $this->assertNull($run->revision_iniciada_at);
    }

    /**
     * 🔴 Deshace TODO: cada artículo recupera lo que tenía (una categoría viva con su subcategoría, el 0 de
     * un borrado, NULL), las categorías que creó el aplicar van a la papelera, las que el aplicar mandó a la
     * papelera vuelven, los ítems quedan en `propuesta` sin valores previos y los nodos sin atar.
     *
     * @test
     * @group categorias_ia
     */
    public function restaura_lo_anterior_manda_a_la_papelera_lo_creado_y_restaura_lo_eliminado()
    {
        $e = $this->escenario();
        [$a1, $a2, $a3, $a4] = $e['articulos'];

        // Pidiendo eliminar las vacías: `Vieja` y `Sub vieja` quedan sin artículos y se van a la papelera.
        $this->pedir_elegir($e['run'], $e['a']['proposal'], true)->assertStatus(200);

        $this->assertNull(Category::find($e['vieja']->id), 'El aplicar mandó Vieja a la papelera.');
        $this->assertNull(SubCategory::find($e['sub_vieja']->id));
        $this->assertSame(1, $this->categorias_llamadas('Bisagras')->count());

        $this->pedir_volver_atras($e['run'])->assertStatus(200);

        // Cada artículo, como estaba.
        $this->assertSame(['category_id' => $e['vieja']->id, 'sub_category_id' => $e['sub_vieja']->id], $this->categorias_de($a1));
        $this->assertSame(['category_id' => 0, 'sub_category_id' => 0], $this->categorias_de($a2), 'El 0 vuelve como 0 (no se normaliza a NULL).');
        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($a3));
        $this->assertSame(['category_id' => $e['vieja']->id, 'sub_category_id' => null], $this->categorias_de($a4), 'El que el aplicar dejó en NULL vuelve a su categoría.');

        // Lo que se creó, a la papelera; lo que se eliminó, restaurado (con el mismo id).
        $this->assertSame(0, $this->categorias_llamadas('Bisagras')->count());
        $this->assertSame(0, $this->categorias_llamadas('Correderas')->count());
        $this->assertSame(0, SubCategory::where('user_id', $this->owner->id)->where('name', 'Comunes')->count());
        $this->assertSame(2, Category::onlyTrashed()->where('user_id', $this->owner->id)->count(), 'Bisagras y Correderas.');
        $this->assertNotNull(Category::find($e['vieja']->id), 'Vieja vuelve.');
        $this->assertNotNull(SubCategory::find($e['sub_vieja']->id), 'Y su subcategoría.');

        // Ítems y nodos como antes de elegir.
        foreach ($e['a']['items'] as $item) {
            $fila = CategoryProposalItem::find($item->id);
            $this->assertSame('propuesta', $fila->estado);
            $this->assertNull($fila->prev_category_id);
            $this->assertNull($fila->prev_sub_category_id);
            $this->assertNull($fila->revisado_por);
            $this->assertNull($fila->revisado_at);
        }

        foreach ($e['a']['nodos'] as $nodo) {
            $fila = CategoryProposalNode::find($nodo->id);
            $this->assertNull($fila->real_category_id);
            $this->assertNull($fila->real_sub_category_id);
            $this->assertFalse($fila->real_creado);
        }
    }

    /**
     * Elegir y volver atrás dejan los datos del comercio EXACTAMENTE como estaban: las mismas categorías
     * vivas, las mismas subcategorías y cada artículo con la misma categoría y subcategoría.
     *
     * @test
     * @group categorias_ia
     */
    public function elegir_y_volver_atras_dejan_los_datos_como_estaban()
    {
        $e = $this->escenario();
        $foto = function () use ($e) {
            $articulos = [];
            foreach ($e['articulos'] as $articulo) {
                $articulos[$articulo->id] = $this->categorias_de($articulo);
            }

            return [
                'articulos'     => $articulos,
                'categorias'    => Category::where('user_id', $this->owner->id)->orderBy('id')->pluck('name', 'id')->all(),
                'subcategorias' => SubCategory::where('user_id', $this->owner->id)->orderBy('id')->pluck('name', 'id')->all(),
            ];
        };

        $antes = $foto();

        $this->pedir_elegir($e['run'], $e['a']['proposal'], true)->assertStatus(200);
        $this->assertNotSame($antes, $foto(), 'Elegir sí cambió cosas.');

        $this->pedir_volver_atras($e['run'])->assertStatus(200);

        $this->assertSame($antes, $foto());
    }

    /**
     * Después de volver atrás se puede elegir OTRA propuesta, y también la misma de nuevo. Lo que creó la
     * primera elección sigue en la papelera (no reaparece ni se reutiliza), y la segunda crea lo suyo.
     *
     * @test
     * @group categorias_ia
     */
    public function se_puede_volver_a_elegir_otra_propuesta_o_la_misma()
    {
        $e = $this->escenario();
        [$a1, $a2, $a3, $a4] = $e['articulos'];

        $this->pedir_elegir($e['run'], $e['a']['proposal'])->assertStatus(200);
        $bisagras_de_antes = $this->categorias_llamadas('Bisagras')->first();

        $this->pedir_volver_atras($e['run'])->assertStatus(200);

        // Otra propuesta.
        $this->pedir_elegir($e['run'], $e['b']['proposal'])->assertStatus(200);

        $herrajes = $this->categorias_llamadas('Herrajes')->first();
        $this->assertNotNull($herrajes);
        $this->assertSame(0, $this->categorias_llamadas('Bisagras')->count(), 'La de la primera elección sigue en la papelera.');
        $this->assertSame($herrajes->id, $this->categorias_de($a1)['category_id']);
        $this->assertSame($herrajes->id, $this->categorias_de($a3)['category_id']);
        $this->assertSame($e['b']['proposal']->id, (int) CategoryProposalRun::find($e['run']->id)->propuesta_elegida_id);

        // Y de nuevo la primera: crea Bisagras OTRA VEZ (la de la papelera no se reutiliza).
        $this->pedir_volver_atras($e['run'])->assertStatus(200);
        $this->assertSame(0, $this->categorias_llamadas('Herrajes')->count());

        $this->pedir_elegir($e['run'], $e['a']['proposal'])->assertStatus(200);
        $nueva = $this->categorias_llamadas('Bisagras');
        $this->assertSame(1, $nueva->count());
        $this->assertNotSame($bisagras_de_antes->id, $nueva->first()->id);
        $this->assertSame($nueva->first()->id, $this->categorias_de($a1)['category_id']);
    }

    // ---------------------------------------------------------------------------------------------
    // Cada motivo por el que NO se puede
    // ---------------------------------------------------------------------------------------------

    /**
     * `hay_revisiones`: en cuanto alguien aprobó o rechazó un dudoso, ya no se puede cambiar de sistema.
     *
     * @test
     * @group categorias_ia
     */
    public function no_se_puede_si_ya_hay_revisiones()
    {
        $s = $this->sistema_elegido_simple();

        $this->assertSame(['puede' => true, 'motivo' => null], $this->puede_cambiar($s['run']));

        $this->pedir_rechazar($s['items'][2])->assertStatus(200);

        $this->afirmar_que_no_se_puede_volver_atras($s, 'hay_revisiones');
    }

    /**
     * `articulos_editados`: alguien cambió a mano la categoría, la subcategoría o las dos de un artículo
     * que el aplicar asignó. Volver atrás pisaría ese cambio.
     *
     * @test
     * @group categorias_ia
     */
    public function no_se_puede_si_se_edito_un_articulo_asignado()
    {
        $s = $this->sistema_elegido_simple();
        $otra = $this->categoria_real('Otra a mano');

        // La categoría.
        Article::where('id', $s['articulos'][1]->id)->update(['category_id' => $otra->id]);
        $this->afirmar_que_no_se_puede_volver_atras($s, 'articulos_editados');

        // Se deja como estaba: ahora se puede.
        Article::where('id', $s['articulos'][1]->id)->update(['category_id' => $s['correderas']->id]);
        $this->assertSame(['puede' => true, 'motivo' => null], $this->puede_cambiar($s['run']));

        // Solo la subcategoría.
        Article::where('id', $s['articulos'][0]->id)->update(['sub_category_id' => null]);
        $this->afirmar_que_no_se_puede_volver_atras($s, 'articulos_editados');

        Article::where('id', $s['articulos'][0]->id)->update(['sub_category_id' => $s['comunes']->id]);
        $this->assertSame(['puede' => true, 'motivo' => null], $this->puede_cambiar($s['run']));

        // Una categoría a un artículo que debía tener subcategoría, pero con la subcategoría correcta.
        Article::where('id', $s['articulos'][0]->id)->update(['category_id' => $otra->id]);
        $this->afirmar_que_no_se_puede_volver_atras($s, 'articulos_editados');
    }

    /**
     * 🔴 `articulos_editados` también por los artículos que el aplicar dejó SIN categoría (dudosos y sin
     * ubicar) en un sistema nuevo: si alguien le puso una a mano, volver atrás se la pisaría con la
     * anterior. Es la lectura estricta de "nadie editó nada a mano".
     *
     * @test
     * @group categorias_ia
     */
    public function no_se_puede_si_se_categorizo_a_mano_un_dudoso_de_un_sistema_nuevo()
    {
        $s = $this->sistema_elegido_simple();
        $otra = $this->categoria_real('Otra a mano');

        // El dudoso quedó sin categoría; alguien se la pone.
        Article::where('id', $s['articulos'][2]->id)->update(['category_id' => $otra->id]);

        $this->afirmar_que_no_se_puede_volver_atras($s, 'articulos_editados');
    }

    /**
     * Un artículo borrado después de elegir no cuenta como "editado": no impide volver atrás, y los demás se
     * restauran igual (al borrado no se le escribe).
     *
     * @test
     * @group categorias_ia
     */
    public function un_articulo_borrado_despues_de_elegir_no_impide_volver_atras()
    {
        $e = $this->escenario();
        [$a1, $a2, $a3, $a4] = $e['articulos'];

        $this->pedir_elegir($e['run'], $e['a']['proposal'])->assertStatus(200);

        $a1->delete();

        $this->assertSame(['puede' => true, 'motivo' => null], $this->puede_cambiar($e['run']));
        $this->pedir_volver_atras($e['run'])->assertStatus(200);

        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($a3), 'Los demás sí se restauran.');
        $this->assertSame(['category_id' => $e['vieja']->id, 'sub_category_id' => null], $this->categorias_de($a4));
    }

    /**
     * `categorias_editadas`: la categoría o subcategoría que CREÓ el aplicar se renombró.
     *
     * @test
     * @group categorias_ia
     */
    public function no_se_puede_si_se_renombro_una_categoria_creada()
    {
        $s = $this->sistema_elegido_simple();

        Category::where('id', $s['bisagras']->id)->update(['name' => 'Bisagras y herrajes']);
        $this->afirmar_que_no_se_puede_volver_atras($s, 'categorias_editadas');

        // Volver al nombre original: se puede de nuevo.
        Category::where('id', $s['bisagras']->id)->update(['name' => 'Bisagras']);
        $this->assertSame(['puede' => true, 'motivo' => null], $this->puede_cambiar($s['run']));

        // Una subcategoría creada, renombrada.
        SubCategory::where('id', $s['comunes']->id)->update(['name' => 'Comunes y raras']);
        $this->afirmar_que_no_se_puede_volver_atras($s, 'categorias_editadas');
    }

    /**
     * `categorias_editadas`: la categoría que creó el aplicar se mandó a la papelera (a mano, desde el ABM).
     *
     * @test
     * @group categorias_ia
     */
    public function no_se_puede_si_se_borro_una_categoria_creada()
    {
        $s = $this->sistema_elegido_simple();

        $s['correderas']->delete();

        $this->afirmar_que_no_se_puede_volver_atras($s, 'categorias_editadas');
    }

    /**
     * `categorias_editadas`: alguien cargó un artículo NUEVO en una categoría que creó el aplicar (un
     * artículo que no es de la corrida).
     *
     * @test
     * @group categorias_ia
     */
    public function no_se_puede_si_se_cargo_un_articulo_nuevo_en_una_categoria_creada()
    {
        $s = $this->sistema_elegido_simple();

        $this->crear_articulo('Nuevo cargado a mano', ['category_id' => $s['bisagras']->id]);

        $this->afirmar_que_no_se_puede_volver_atras($s, 'categorias_editadas');
    }

    /**
     * `categorias_editadas`: lo mismo con una subcategoría creada (un artículo nuevo con esa subcategoría).
     *
     * @test
     * @group categorias_ia
     */
    public function no_se_puede_si_se_cargo_un_articulo_nuevo_en_una_subcategoria_creada()
    {
        $s = $this->sistema_elegido_simple();

        $this->crear_articulo('Nuevo cargado a mano', ['category_id' => null, 'sub_category_id' => $s['comunes']->id]);

        $this->afirmar_que_no_se_puede_volver_atras($s, 'categorias_editadas');
    }

    /**
     * `categorias_editadas`: una subcategoría creada se movió a otra categoría (re-parentar).
     *
     * @test
     * @group categorias_ia
     */
    public function no_se_puede_si_se_movio_una_subcategoria_creada()
    {
        $s = $this->sistema_elegido_simple();

        SubCategory::where('id', $s['comunes']->id)->update(['category_id' => $s['correderas']->id]);

        $this->afirmar_que_no_se_puede_volver_atras($s, 'categorias_editadas');
    }

    /**
     * `no_esta_elegida`: sobre una corrida que todavía se está preparando (o que se descartó) no hay nada
     * que deshacer: 409 con ese motivo. `puede_cambiar` también dice que no a una lista.
     *
     * @test
     * @group categorias_ia
     */
    public function sobre_una_corrida_que_no_esta_elegida_ni_lista_no_hay_nada_que_deshacer()
    {
        foreach (['preparando', 'descartada'] as $estado) {
            $sembrado = $this->sembrar_corrida([
                'estado'     => $estado,
                'propuestas' => ['A' => ['arbol' => ['X' => []], 'items' => []]],
            ]);

            $respuesta = $this->pedir_volver_atras($sembrado['run']);

            $respuesta->assertStatus(409);
            $this->assertSame('no_se_puede_volver_atras', $respuesta->json('error'));
            $this->assertSame('no_esta_elegida', $respuesta->json('motivo'), $estado);
            $this->assertSame(['puede' => false, 'motivo' => 'no_esta_elegida'], $this->puede_cambiar($sembrado['run']));
        }

        // Una lista que nunca se eligió: no es "elegida", así que `puede_cambiar` dice que no, pero el pedido
        // es idempotente (el estado que se pide ya se cumple) y responde 200 sin tocar nada.
        $lista = $this->sembrar_corrida(['propuestas' => ['A' => ['arbol' => ['X' => []], 'items' => []]]]);
        $this->assertSame(['puede' => false, 'motivo' => 'no_esta_elegida'], $this->puede_cambiar($lista['run']));
        $this->pedir_volver_atras($lista['run'])->assertStatus(200)->assertJsonPath('ya_estaba', true);
        $this->assertSame('lista', CategoryProposalRun::find($lista['run']->id)->estado);
    }

    /**
     * El 409 trae `error`, `message` (en español) y `motivo`, y nada más: es lo que la SPA dibuja.
     *
     * @test
     * @group categorias_ia
     */
    public function el_409_tiene_la_forma_del_contrato()
    {
        $s = $this->sistema_elegido_simple();
        $this->pedir_rechazar($s['items'][2])->assertStatus(200);

        $respuesta = $this->pedir_volver_atras($s['run']);

        $respuesta->assertStatus(409);
        $this->assertSame(['error', 'message', 'motivo'], array_keys($respuesta->json()));
        $this->assertSame('no_se_puede_volver_atras', $respuesta->json('error'));
        $this->assertSame('hay_revisiones', $respuesta->json('motivo'));
        $this->assertStringContainsString('revisar', $respuesta->json('message'));
    }

    // ---------------------------------------------------------------------------------------------
    // "Mantener", precios
    // ---------------------------------------------------------------------------------------------

    /**
     * Volver atrás de "mantener": devuelve a NULL solo lo que el aplicar asignó, no borra ninguna categoría
     * del dueño (el aplicar no creó nada) y no toca los artículos que el aplicar tampoco tocó: si alguien
     * categorizó a mano un dudoso, lo suyo se respeta y no impide volver atrás.
     *
     * @test
     * @group categorias_ia
     */
    public function volver_atras_de_mantener_no_borra_categorias_ni_pisa_lo_que_no_toco_el_aplicar()
    {
        $herrajes   = $this->categoria_real('Herrajes');
        $fijaciones = $this->categoria_real('Fijaciones');
        $articulos  = $this->crear_articulos(['a1', 'a2', 'a3']);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'mantener' => [
                    'tipo'  => 'mantener',
                    'arbol' => [
                        'Herrajes'   => ['subs' => [], 'existing_category_id' => $herrajes->id],
                        'Fijaciones' => ['subs' => [], 'existing_category_id' => $fijaciones->id],
                    ],
                    'items' => [
                        [$articulos[0], 'Herrajes', null, 'segura'],
                        [$articulos[1], 'Fijaciones', null, 'segura'],
                        [$articulos[2], 'Herrajes', null, 'dudosa', 'no queda claro'],
                    ],
                ],
            ],
        ]);

        $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['mantener']['proposal'])->assertStatus(200);

        // El dueño categoriza a mano el dudoso (que el aplicar no tocó).
        Article::where('id', $articulos[2]->id)->update(['category_id' => $fijaciones->id]);

        $this->assertSame(['puede' => true, 'motivo' => null], $this->puede_cambiar($sembrado['run']));
        $this->pedir_volver_atras($sembrado['run'])->assertStatus(200);

        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($articulos[0]));
        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($articulos[1]));
        $this->assertSame(['category_id' => $fijaciones->id, 'sub_category_id' => null], $this->categorias_de($articulos[2]), 'Lo categorizado a mano se respeta.');

        $this->assertNotNull(Category::find($herrajes->id), 'Las categorías del dueño siguen.');
        $this->assertNotNull(Category::find($fijaciones->id));
        $this->assertSame(0, Category::onlyTrashed()->where('user_id', $this->owner->id)->count());
    }

    /**
     * Volver atrás con márgenes por categoría recalcula el precio de los artículos que devolvió a su
     * categoría anterior (cambiar la categoría mueve el precio también al deshacer), en segundo plano y
     * después del commit.
     *
     * @test
     * @group categorias_ia
     */
    public function volver_atras_con_margenes_por_categoria_recalcula_los_precios()
    {
        Queue::fake();

        $herrajes = $this->categoria_real('Herrajes', null, ['percentage_gain' => 10]);
        $articulos = $this->crear_articulos(['a1', 'a2']);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'mantener' => [
                    'tipo'  => 'mantener',
                    'arbol' => ['Herrajes' => ['subs' => [], 'existing_category_id' => $herrajes->id]],
                    'items' => [
                        [$articulos[0], 'Herrajes', null, 'segura'],
                        [$articulos[1], 'Herrajes', null, 'segura'],
                    ],
                ],
            ],
        ]);

        $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['mantener']['proposal'])->assertStatus(200);

        // Se vacía lo encolado por elegir para medir solo lo de volver atrás.
        Queue::fake();
        $this->assertSame([], $this->ids_encolados_para_recalcular());

        $this->pedir_volver_atras($sembrado['run'])->assertStatus(200);

        $esperados = [$articulos[0]->id, $articulos[1]->id];
        sort($esperados);
        $this->assertSame($esperados, $this->ids_encolados_para_recalcular());
    }

    /**
     * La función `puede_cambiar` es la que usa el payload de `actual` (API-1): sobre una corrida elegida y
     * limpia dice que sí, y se vuelve `false` con el motivo en cuanto algo cambia; sobre una `lista`, una
     * `preparando` o una `descartada` dice `no_esta_elegida`. Mismos valores que el 409.
     *
     * @test
     * @group categorias_ia
     */
    public function puede_cambiar_da_el_mismo_veredicto_que_el_pedido()
    {
        $s = $this->sistema_elegido_simple();

        $this->assertSame(['puede' => true, 'motivo' => null], $this->puede_cambiar($s['run']));

        $this->pedir_volver_atras($s['run'])->assertStatus(200);
        $this->assertSame(['puede' => false, 'motivo' => 'no_esta_elegida'], $this->puede_cambiar($s['run']));
    }

    /**
     * 🔴 Volver atrás recorre VARIOS lotes sin saltearse a nadie: con un lote de 4 ítems y 25 artículos (uno
     * borrado después de elegir), cada artículo recupera lo que tenía, los ítems vuelven a `propuesta` y las
     * categorías que creó el aplicar van a la papelera.
     *
     * @test
     * @group categorias_ia
     */
    public function volver_atras_recorre_varios_lotes_sin_saltearse_a_nadie()
    {
        config(['catalogo_ia.articulos_por_lote_de_escritura' => 4]);

        $e = $this->sembrar_veinticinco();

        $this->pedir_elegir($e['run'], $e['proposal'])->assertStatus(200);

        // Un artículo se borra después de elegir (el 7, un seguro).
        $e['articulos'][7]->delete();

        $this->assertSame(['puede' => true, 'motivo' => null], $this->puede_cambiar($e['run']));
        $this->pedir_volver_atras($e['run'])->assertStatus(200);

        foreach ($e['articulos'] as $i => $articulo) {
            // Los múltiplos de tres tenían `Vieja`; el resto, nada. El borrado no se toca (queda como lo dejó elegir).
            if ($i === 7) {
                continue;
            }

            $esperado = ($i % 3 === 0)
                ? ['category_id' => $e['vieja']->id, 'sub_category_id' => null]
                : ['category_id' => null, 'sub_category_id' => null];

            $this->assertSame($esperado, $this->categorias_de($articulo), 'Artículo '.$i);
        }

        foreach ($e['items'] as $i => $item) {
            $fila = CategoryProposalItem::find($item->id);
            $this->assertSame('propuesta', $fila->estado, 'Ítem '.$i);
            $this->assertNull($fila->prev_category_id);
        }

        $this->assertSame(0, $this->categorias_llamadas('Bisagras')->count());
        $this->assertSame(0, $this->categorias_llamadas('Correderas')->count());
        $this->assertSame(2, Category::onlyTrashed()->where('user_id', $this->owner->id)->count());
        $this->assertNotNull(Category::find($e['vieja']->id));
    }
}
