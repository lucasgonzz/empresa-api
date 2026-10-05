<?php

namespace Tests\Feature\CategoryProposals;

use App\Http\Controllers\Helpers\category_proposal\CategoryMargenesHelper;
use App\Http\Controllers\Helpers\category_proposal\CategoryProposalAplicarHelper;
use App\Models\CategoryProposal;
use App\Models\CategoryProposalItem;
use App\Models\CategoryProposalRun;

/**
 * Lo que lee la SPA de las corridas de propuestas (misión categorizacion-tres-modelos, 5/10/2026).
 * Contrato B del plan, §6.1, §6.2, §6.3 y §6.6: `resumen` (el badge), `actual` (las tarjetas con sus
 * árboles y conteos), `visto` e `items` (la revisión paginada).
 *
 * Los nombres de los campos son contrato con la SPA y los valores se comparan EXACTOS contra corridas
 * sembradas a mano (con la fábrica `sembrar_corrida()` de la base, que escribe directo en las tablas): un
 * conteo mal hecho no da error, la tarjeta simplemente muestra un número equivocado.
 *
 * Lo que sale de otros helpers (el bloqueo por márgenes, las advertencias, si se puede volver atrás) se
 * compara contra lo que esos helpers dicen en ese momento: este test no decide esas reglas, solo que la
 * lectura las transporte tal cual.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Lectura_para_la_SPA_Test extends CategoryProposalsTestCase
{
    use ArmaCorridasDeCategorias;

    /** Los campos exactos de cada tarjeta de `actual`. */
    const CAMPOS_DE_TARJETA = ['id', 'clave', 'tipo', 'nombre', 'resumen', 'descripcion', 'orden', 'elegida', 'totales', 'arbol', 'advertencias'];

    // ------------------------------------------------------------------------------------------
    // resumen
    // ------------------------------------------------------------------------------------------

    /**
     * @group categorias_ia
     * @test
     */
    public function el_resumen_sin_corrida_es_el_vacio_del_dueno()
    {
        $this->getJson('api/category-proposal-runs/resumen')->assertStatus(200)->assertExactJson([
            'hay_propuestas'       => false,
            'estado'               => null,
            'run_id'               => null,
            'pendientes_de_elegir' => false,
            'a_revisar'            => 0,
            'sin_ver'              => false,
            'badge'                => 0,
            'puede_gestionar'      => true,
        ]);

        $this->assertSame(
            ['hay_propuestas', 'estado', 'run_id', 'pendientes_de_elegir', 'a_revisar', 'sin_ver', 'badge', 'puede_gestionar'],
            array_keys($this->resumen())
        );
    }

    /**
     * El badge en cada estado de la corrida: una `preparando` no existe para el dueño (es de la skill hasta
     * el `listo`: B-07), `lista` suma 1 y sigue sumando 1 aunque ya se haya visto (todavía hay que
     * elegir), `elegida` suma los dudosos a revisar, y una descartada no existe.
     *
     * @group categorias_ia
     * @test
     */
    public function el_resumen_en_cada_estado_de_la_corrida()
    {
        $sembrado = $this->corrida_con_a('preparando');
        $run      = $sembrado['run'];

        // 🔴 Preparando (CAMBIO DELIBERADO DE CONTRATO, B-07 del verificador, 5/10/2026): la skill todavía la
        // está cargando y es del equipo, no del dueño ("desde `listo` el dueño la ve", plan §5.6). Para él es
        // como si no hubiera nada: ni siquiera dice que existe.
        $this->assertSame([
            'hay_propuestas'       => false,
            'estado'               => null,
            'run_id'               => null,
            'pendientes_de_elegir' => false,
            'a_revisar'            => 0,
            'sin_ver'              => false,
            'badge'                => 0,
            'puede_gestionar'      => true,
        ], $this->resumen());

        // Lista: un sistema esperando que el dueño elija, y sin ver.
        $run->estado = 'lista';
        $run->save();

        $this->assertSame([
            'hay_propuestas'       => true,
            'estado'               => 'lista',
            'run_id'               => (int) $run->id,
            'pendientes_de_elegir' => true,
            'a_revisar'            => 0,
            'sin_ver'              => true,
            'badge'                => 1,
            'puede_gestionar'      => true,
        ], $this->resumen());

        // Vista: deja de estar "sin ver", pero el badge sigue en 1 hasta que elija.
        $this->putJson('api/category-proposal-runs/'.$run->id.'/visto')->assertStatus(200);

        $resumen = $this->resumen();

        $this->assertFalse($resumen['sin_ver']);
        $this->assertTrue($resumen['pendientes_de_elegir']);
        $this->assertSame(1, $resumen['badge']);

        // Elegida: el badge son los dudosos esperando revisión (solo los de la propuesta elegida).
        $a = $sembrado['propuestas']['A']['proposal'];

        $run->estado               = 'elegida';
        $run->propuesta_elegida_id = $a->id;
        $run->save();

        CategoryProposalItem::where('proposal_id', $a->id)->where('confianza', 'dudosa')->update(['estado' => 'a_revisar']);
        CategoryProposalItem::where('proposal_id', $a->id)->where('confianza', 'segura')->update(['estado' => 'aplicada']);

        $this->assertSame([
            'hay_propuestas'       => true,
            'estado'               => 'elegida',
            'run_id'               => (int) $run->id,
            'pendientes_de_elegir' => false,
            'a_revisar'            => 2,
            'sin_ver'              => false,
            'badge'                => 2,
            'puede_gestionar'      => true,
        ], $this->resumen());

        // Aprobar uno baja el badge.
        CategoryProposalItem::where('proposal_id', $a->id)->where('estado', 'a_revisar')->orderBy('id')->limit(1)->update(['estado' => 'aprobada']);

        $this->assertSame(1, $this->resumen()['badge']);

        // Una corrida descartada es como si no hubiera.
        $run->estado = 'descartada';
        $run->save();

        $this->assertFalse($this->resumen()['hay_propuestas']);
        $this->assertSame(0, $this->resumen()['badge']);
    }

    // ------------------------------------------------------------------------------------------
    // actual
    // ------------------------------------------------------------------------------------------

    /**
     * Sin corrida: `run: null`, el bloqueo (lo que dice el helper de márgenes), si ya tenía categorías, y todo
     * lo demás vacío o en cero.
     *
     * @group categorias_ia
     * @test
     */
    public function actual_sin_corrida()
    {
        $json = $this->actual();

        $this->assertSame(['run', 'bloqueo', 'advertencias', 'tiene_categorias_previas', 'conteos', 'propuestas'], array_keys($json));
        $this->assertNull($json['run']);
        $this->assertSame(CategoryMargenesHelper::bloqueo_para($this->owner->id), $json['bloqueo']);
        $this->assertSame([], $json['advertencias']);
        $this->assertFalse($json['tiene_categorias_previas']);
        $this->assertSame(['a_revisar' => 0, 'asignados' => 0, 'sin_categoria' => 0], $json['conteos']);
        $this->assertSame([], $json['propuestas']);

        // Con una categoría viva (las borradas y las del vecino no cuentan), `tiene_categorias_previas`.
        $this->crear_categoria_real('Borrada')->delete();
        $this->crear_categoria_real('Del vecino', $this->vecino);

        $this->assertFalse($this->actual()['tiene_categorias_previas']);

        $this->crear_categoria_real('Mia');

        $this->assertTrue($this->actual()['tiene_categorias_previas']);
    }

    /**
     * 🔴 CAMBIO DELIBERADO DE CONTRATO (B-07 del verificador, 5/10/2026): con la corrida `preparando` `actual`
     * contesta `run: null` y nada más, igual que sin corrida (antes devolvía el `run` con `propuestas: []`
     * y la SPA mostraba "Estamos preparando tus propuestas"). Mientras la skill la carga es del equipo,
     * no del dueño: plan §5.6, "desde `listo` el dueño la ve". Las tarjetas existen en la base y no
     * se ven.
     *
     * @group categorias_ia
     * @test
     */
    public function actual_no_muestra_la_corrida_que_la_skill_todavia_esta_preparando()
    {
        $this->crear_articulos_en_masa(2);
        $this->crear_categoria_real('Una categoria del dueno');

        $this->crear_corrida_por_api();

        $json = $this->actual();

        $this->assertSame(['run', 'bloqueo', 'advertencias', 'tiene_categorias_previas', 'conteos', 'propuestas'], array_keys($json));
        $this->assertNull($json['run']);
        $this->assertSame(CategoryMargenesHelper::bloqueo_para($this->owner->id), $json['bloqueo']);
        $this->assertSame([], $json['advertencias']);
        $this->assertTrue($json['tiene_categorias_previas']);
        $this->assertSame(['a_revisar' => 0, 'asignados' => 0, 'sin_categoria' => 0], $json['conteos']);
        $this->assertSame([], $json['propuestas']);
    }

    /**
     * 🔴 B-07: la corrida aparece para el dueño recién con el `listo`, de punta a punta con la ingesta real:
     * mientras está `preparando` el resumen, `actual`, `visto` e `items` no la conocen (404 `no_encontrado`
     * en los dos que llevan id, el mismo cuerpo que un id inexistente); con `listo` pasa a verse, sin ver y
     * con su badge.
     *
     * @group categorias_ia
     * @test
     */
    public function la_corrida_aparece_para_el_dueno_recien_con_el_listo()
    {
        $this->crear_articulos_en_masa(2);

        $creada = $this->crear_corrida_por_api();
        $run_id = $creada['run_id'];

        $this->assertFalse($this->resumen()['hay_propuestas']);
        $this->assertNull($this->actual()['run']);

        $inexistente = $this->putJson('api/category-proposal-runs/987654321/visto');

        $inexistente->assertStatus(404);
        $this->assertSame($inexistente->json(), $this->putJson('api/category-proposal-runs/'.$run_id.'/visto')->assertStatus(404)->json());
        $this->assertSame(
            $this->getJson('api/category-proposal-runs/987654321/items')->assertStatus(404)->json(),
            $this->items($run_id, 'solapa=a_revisar')->assertStatus(404)->json()
        );
        $this->assertNull(CategoryProposalRun::find($run_id)->visto_at);

        // Con el ok de `listo` (la skill lo da con el visto bueno de Lucas) el dueño la ve.
        $this->post_admin('categorias/propuestas/'.$run_id.'/listo', ['forzar' => true])->assertStatus(200);

        $resumen = $this->resumen();

        $this->assertTrue($resumen['hay_propuestas']);
        $this->assertSame('lista', $resumen['estado']);
        $this->assertSame((int) $run_id, $resumen['run_id']);
        $this->assertTrue($resumen['sin_ver']);
        $this->assertSame(1, $resumen['badge']);

        $actual = $this->actual();

        $this->assertSame((int) $run_id, $actual['run']['id']);
        $this->assertSame(['A', 'B'], array_column($actual['propuestas'], 'clave'));

        $this->putJson('api/category-proposal-runs/'.$run_id.'/visto')->assertStatus(200)->assertExactJson(['ok' => true]);
        $this->assertNotNull(CategoryProposalRun::find($run_id)->visto_at);
    }

    /**
     * 🔴 La corrida `lista` con una propuesta nueva: el objeto `run`, los totales y el árbol con valores
     * exactos (categorías y subcategorías por nombre, con `articulos`, `dudosos` y `suman` null).
     *
     * @group categorias_ia
     * @test
     */
    public function actual_trae_la_tarjeta_nueva_con_sus_totales_y_su_arbol()
    {
        $sembrado = $this->corrida_con_a('lista');
        $run      = $sembrado['run'];
        $a        = $sembrado['propuestas']['A'];
        $nodos    = $a['nodos'];

        $json = $this->actual();

        // El objeto `run`.
        $this->assertSame([
            'id'                      => (int) $run->id,
            'estado'                  => 'lista',
            'articulos_total'         => 6,
            'creada_at'               => $run->fresh()->created_at->format('Y-m-d H:i:s'),
            'elegida_at'              => null,
            'propuesta_elegida_id'    => null,
            'puede_gestionar'         => true,
            'puede_cambiar'           => false,
            'motivo_no_puede_cambiar' => null,
            'resultado'               => null,
        ], $json['run']);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $json['run']['creada_at']);

        // La tarjeta, con los campos exactos.
        $this->assertCount(1, $json['propuestas']);

        $tarjeta = $json['propuestas'][0];

        $this->assertSame(self::CAMPOS_DE_TARJETA, array_keys($tarjeta));
        $this->assertSame((int) $a['proposal']->id, $tarjeta['id']);
        $this->assertSame('A', $tarjeta['clave']);
        $this->assertSame('nueva', $tarjeta['tipo']);
        $this->assertSame('Por rubro', $tarjeta['nombre']);
        $this->assertSame('Resumen de A', $tarjeta['resumen']);
        $this->assertSame('Descripcion de A', $tarjeta['descripcion']);
        $this->assertSame(1, $tarjeta['orden']);
        $this->assertFalse($tarjeta['elegida']);

        $this->assertSame([
            'categorias'        => 2,
            'subcategorias'     => 2,
            'articulos'         => 6,
            'seguros'           => 3,
            'dudosos'           => 2,
            'sin_asignar'       => 1,
            'pierden_categoria' => 0,
        ], $tarjeta['totales']);

        // El árbol, ordenado por nombre aunque se haya cargado al revés.
        $this->assertSame([
            [
                'id'            => (int) $nodos['Bisagras']->id,
                'nombre'        => 'Bisagras',
                'articulos'     => 3,
                'dudosos'       => 1,
                'suman'         => null,
                'subcategorias' => [
                    ['id' => (int) $nodos['Bisagras/Comunes']->id, 'nombre' => 'Comunes', 'articulos' => 1, 'dudosos' => 0, 'suman' => null],
                    ['id' => (int) $nodos['Bisagras/De cierre suave']->id, 'nombre' => 'De cierre suave', 'articulos' => 1, 'dudosos' => 0, 'suman' => null],
                ],
            ],
            [
                'id'            => (int) $nodos['Correderas']->id,
                'nombre'        => 'Correderas',
                'articulos'     => 2,
                'dudosos'       => 1,
                'suman'         => null,
                'subcategorias' => [],
            ],
        ], $tarjeta['arbol']);

        // Sin elegir, los conteos de la revisión son cero.
        $this->assertSame(['a_revisar' => 0, 'asignados' => 0, 'sin_categoria' => 0], $json['conteos']);
    }

    /**
     * 🔴 `pierden_categoria`: los artículos que HOY tienen una categoría viva y cuyo ítem es dudoso o
     * ninguna (al elegir una propuesta nueva quedan sin categoría hasta que se aprueben). Un ítem seguro no
     * cuenta (cambia de categoría pero no la pierde), ni una categoría borrada, ni un artículo de otro
     * comercio.
     *
     * @group categorias_ia
     * @test
     */
    public function pierden_categoria_cuenta_solo_dudosos_y_ninguna_que_hoy_tienen_categoria_viva()
    {
        $viva    = $this->crear_categoria_real('Vieja');
        $borrada = $this->crear_categoria_real('Vieja borrada');

        $sembrado = $this->corrida_con_a('lista', null, [
            1 => ['category_id' => $viva->id],      // segura con categoría: NO cuenta
            3 => ['category_id' => $viva->id],      // dudosa con categoría viva: cuenta
            5 => ['category_id' => $borrada->id],   // dudosa con categoría borrada: no cuenta
            6 => ['category_id' => $viva->id],      // ninguna con categoría viva: cuenta
        ]);

        $borrada->delete();

        $this->assertSame(2, $this->actual()['propuestas'][0]['totales']['pierden_categoria']);

        // Una vez elegida ya no se calcula: los dudosos quedaron sin categoría y el número vive en `resultado`.
        $run = $sembrado['run'];
        $run->estado               = 'elegida';
        $run->propuesta_elegida_id = $sembrado['propuestas']['A']['proposal']->id;
        $run->save();

        $this->assertSame(0, $this->actual()['propuestas'][0]['totales']['pierden_categoria']);
    }

    /**
     * 🔴 La tarjeta "Mantener las mías": `articulos` es lo que YA tiene la categoría real (solo artículos
     * activos y sin borrar del dueño), `suman` los ítems seguros que se le agregarían y `dudosos` los
     * dudosos. Y no hay artículos que pierdan categoría.
     *
     * @group categorias_ia
     * @test
     */
    public function actual_trae_mantener_con_lo_que_ya_tiene_y_lo_que_suma()
    {
        $herrajes = $this->crear_categoria_real('Herrajes');
        $bisagras = $this->crear_subcategoria_real('Bisagras', $herrajes);
        $pinturas = $this->crear_categoria_real('Pinturas');

        // Lo que ya tienen las categorías reales (y lo que NO cuenta: borrado, inactivo, del vecino).
        $this->crear_articulo('Existente 1', ['category_id' => $herrajes->id, 'sub_category_id' => $bisagras->id]);
        $this->crear_articulo('Existente 2', ['category_id' => $herrajes->id]);
        $this->crear_articulo('Borrado', ['category_id' => $herrajes->id])->delete();
        $this->crear_articulo('Fantasma', ['category_id' => $herrajes->id, 'status' => 'inactive']);
        $this->crear_articulo('Del vecino', ['category_id' => $herrajes->id, 'sub_category_id' => $bisagras->id], $this->vecino);

        // Los artículos sin categoría que la skill propone ubicar.
        $n1 = $this->crear_articulo('Nuevo 1');
        $n2 = $this->crear_articulo('Nuevo 2');
        $n3 = $this->crear_articulo('Nuevo 3');
        $n4 = $this->crear_articulo('Nuevo 4');

        $sembrado = $this->sembrar_corrida([
            'estado'     => 'lista',
            'propuestas' => [
                'mantener' => [
                    'tipo'   => 'mantener',
                    'nombre' => 'Mantener mis categorias',
                    'arbol'  => [
                        'Herrajes' => ['subs' => ['Bisagras'], 'existing_category_id' => $herrajes->id, 'existing_subs' => ['Bisagras' => $bisagras->id]],
                        'Pinturas' => ['subs' => [], 'existing_category_id' => $pinturas->id],
                    ],
                    'items'  => [
                        [$n1, 'Herrajes', 'Bisagras', 'segura'],
                        [$n2, 'Herrajes', null, 'segura'],
                        [$n3, 'Pinturas', null, 'dudosa'],
                        [$n4, null, null, 'ninguna'],
                    ],
                ],
            ],
        ]);

        $tarjeta = $this->actual()['propuestas'][0];
        $nodos   = $sembrado['propuestas']['mantener']['nodos'];

        $this->assertSame('mantener', $tarjeta['clave']);
        $this->assertSame('mantener', $tarjeta['tipo']);

        $this->assertSame([
            'categorias'        => 2,
            'subcategorias'     => 1,
            'articulos'         => 4,
            'seguros'           => 2,
            'dudosos'           => 1,
            'sin_asignar'       => 1,
            'pierden_categoria' => 0,
        ], $tarjeta['totales']);

        $this->assertSame([
            [
                'id'            => (int) $nodos['Herrajes']->id,
                'nombre'        => 'Herrajes',
                'articulos'     => 2,
                'dudosos'       => 0,
                'suman'         => 2,
                'subcategorias' => [
                    ['id' => (int) $nodos['Herrajes/Bisagras']->id, 'nombre' => 'Bisagras', 'articulos' => 1, 'dudosos' => 0, 'suman' => 1],
                ],
            ],
            [
                'id'            => (int) $nodos['Pinturas']->id,
                'nombre'        => 'Pinturas',
                'articulos'     => 0,
                'dudosos'       => 1,
                'suman'         => 0,
                'subcategorias' => [],
            ],
        ], $tarjeta['arbol']);
    }

    /**
     * Las tarjetas salen en su orden, con la elegida marcada, y las advertencias son lo que dicen los
     * helpers: las del nivel superior son la UNIÓN de las genéricas y las de cada tipo de tarjeta, y cada
     * tarjeta trae las suyas (las genéricas más las de su tipo).
     *
     * @group categorias_ia
     * @test
     */
    public function las_tarjetas_salen_en_orden_con_la_elegida_marcada_y_sus_advertencias()
    {
        $herrajes = $this->crear_categoria_real('Herrajes');
        $articulo = $this->crear_articulo('Uno');

        $sembrado = $this->sembrar_corrida([
            'estado'     => 'lista',
            'propuestas' => [
                'A'        => ['arbol' => ['Bisagras' => []], 'items' => [[$articulo, 'Bisagras', null, 'segura']]],
                'B'        => ['arbol' => ['Muebles' => []], 'items' => [[$articulo, 'Muebles', null, 'segura']]],
                'mantener' => [
                    'tipo'  => 'mantener',
                    'arbol' => ['Herrajes' => ['subs' => [], 'existing_category_id' => $herrajes->id]],
                    'items' => [[$articulo, 'Herrajes', null, 'segura']],
                ],
            ],
        ]);

        $json = $this->actual();

        $this->assertSame(['A', 'B', 'mantener'], array_column($json['propuestas'], 'clave'));
        $this->assertSame([1, 2, 3], array_column($json['propuestas'], 'orden'));
        $this->assertSame([false, false, false], array_column($json['propuestas'], 'elegida'));

        // Las advertencias.
        $genericas = array_values(CategoryMargenesHelper::advertencias_para($this->owner->id));
        $de_nueva  = array_values(CategoryMargenesHelper::advertencias_para($this->owner->id, 'nueva'));
        $de_mant   = array_values(CategoryMargenesHelper::advertencias_para($this->owner->id, 'mantener'));

        $union = function (array $a, array $b) {
            return array_values(array_unique(array_merge($a, $b)));
        };

        $this->assertSame($union($genericas, $de_nueva), $json['propuestas'][0]['advertencias']);
        $this->assertSame($union($genericas, $de_nueva), $json['propuestas'][1]['advertencias']);
        $this->assertSame($union($genericas, $de_mant), $json['propuestas'][2]['advertencias']);
        $this->assertSame($union($union($genericas, $de_nueva), $de_mant), $json['advertencias']);

        // Una vez elegida la B, ella es la marcada.
        $run = $sembrado['run'];
        $run->estado               = 'elegida';
        $run->propuesta_elegida_id = $sembrado['propuestas']['B']['proposal']->id;
        $run->save();

        $json = $this->actual();

        $this->assertSame([false, true, false], array_column($json['propuestas'], 'elegida'));
        $this->assertSame((int) $sembrado['propuestas']['B']['proposal']->id, $json['run']['propuesta_elegida_id']);
    }

    /**
     * 🔴 La corrida `elegida`: el `run` con su `resultado`, `elegida_at`, y `puede_cambiar` y
     * `motivo_no_puede_cambiar` salidos de LA MISMA función que usa "volver atrás"; y los conteos de la
     * revisión de la propuesta elegida.
     *
     * @group categorias_ia
     * @test
     */
    public function actual_de_una_corrida_elegida_trae_el_resultado_los_conteos_y_si_se_puede_cambiar()
    {
        $sembrado = $this->corrida_con_a('elegida');
        $run      = $sembrado['run'];
        $a        = $sembrado['propuestas']['A']['proposal'];

        $resultado = ['categorias_creadas' => 2, 'categorias_reutilizadas' => 0, 'subcategorias_creadas' => 2, 'articulos_asignados' => 3, 'a_revisar' => 2, 'sin_asignar' => 1, 'pierden_categoria' => 0, 'categorias_eliminadas' => 0];

        $run->propuesta_elegida_id = $a->id;
        $run->elegida_at           = '2026-10-05 15:30:00';
        $run->elegida_por          = $this->owner->id;
        $run->resultado            = $resultado;
        $run->save();

        // Los estados de los ítems después de elegir: 3 aplicados, 2 a revisar y 1 sin asignar.
        $items = CategoryProposalItem::where('proposal_id', $a->id)->orderBy('id')->get();

        $estados = ['aplicada', 'aplicada', 'a_revisar', 'aplicada', 'a_revisar', 'sin_asignar'];

        foreach ($items as $i => $item) {

            $item->estado = $estados[$i];
            $item->save();
        }

        $json = $this->actual();

        $this->assertSame('elegida', $json['run']['estado']);
        $this->assertSame('2026-10-05 15:30:00', $json['run']['elegida_at']);
        $this->assertSame((int) $a->id, $json['run']['propuesta_elegida_id']);
        $this->assertSame($resultado, $json['run']['resultado']);

        // `puede_cambiar` / `motivo`: lo que dice la función que comparte con "volver atrás".
        $evaluacion = CategoryProposalAplicarHelper::puede_cambiar($run->fresh());

        $this->assertSame((bool) $evaluacion['puede'], $json['run']['puede_cambiar']);
        $this->assertSame($evaluacion['puede'] ? null : $evaluacion['motivo'], $json['run']['motivo_no_puede_cambiar']);

        $this->assertSame(['a_revisar' => 2, 'asignados' => 3, 'sin_categoria' => 1], $json['conteos']);
        $this->assertTrue($json['propuestas'][0]['elegida']);
    }

    /**
     * Una corrida descartada nunca se devuelve: es como si no hubiera.
     *
     * @group categorias_ia
     * @test
     */
    public function actual_no_devuelve_una_corrida_descartada()
    {
        $this->corrida_con_a('descartada');

        $json = $this->actual();

        $this->assertNull($json['run']);
        $this->assertSame([], $json['propuestas']);
    }

    /**
     * Con varias corridas, `actual` es la vigente más nueva (la descartada vieja no estorba).
     *
     * @group categorias_ia
     * @test
     */
    public function actual_es_la_vigente_mas_nueva()
    {
        $this->corrida_con_a('descartada');
        $vigente = $this->corrida_con_a('lista');

        $this->assertSame((int) $vigente['run']->id, $this->actual()['run']['id']);
    }

    // ------------------------------------------------------------------------------------------
    // visto
    // ------------------------------------------------------------------------------------------

    /**
     * `visto` marca `visto_at` solo con la corrida `lista` y solo la primera vez, y contesta
     * `{"ok": true}`. Con la corrida `preparando` (todavía de la skill: B-07) es un 404 `no_encontrado` y
     * no marca nada.
     *
     * @group categorias_ia
     * @test
     */
    public function visto_marca_la_corrida_lista_una_sola_vez()
    {
        $sembrado = $this->corrida_con_a('preparando');
        $run      = $sembrado['run'];

        $this->putJson('api/category-proposal-runs/'.$run->id.'/visto')->assertStatus(404)->assertJson(['error' => 'no_encontrado']);
        $this->assertNull($run->fresh()->visto_at, 'Con la corrida preparando no se marca como vista.');

        $run->estado = 'lista';
        $run->save();

        $this->putJson('api/category-proposal-runs/'.$run->id.'/visto')->assertStatus(200)->assertExactJson(['ok' => true]);

        $primera = $run->fresh()->visto_at;

        $this->assertNotNull($primera);

        // La segunda vez no pisa la marca.
        CategoryProposalRun::where('id', $run->id)->update(['visto_at' => '2026-01-01 10:00:00']);

        $this->putJson('api/category-proposal-runs/'.$run->id.'/visto')->assertStatus(200)->assertExactJson(['ok' => true]);

        $this->assertSame('2026-01-01 10:00:00', $run->fresh()->visto_at->format('Y-m-d H:i:s'));

        // Una elegida contesta ok y no toca nada.
        $run->estado   = 'elegida';
        $run->visto_at = null;
        $run->save();

        $this->putJson('api/category-proposal-runs/'.$run->id.'/visto')->assertStatus(200)->assertExactJson(['ok' => true]);
        $this->assertNull($run->fresh()->visto_at);
    }

    // ------------------------------------------------------------------------------------------
    // items (la revisión)
    // ------------------------------------------------------------------------------------------

    /**
     * 🔴 Cada solapa trae SUS estados y los conteos de las tres: A revisar (a_revisar), Asignados (aplicada y
     * aprobada) y Sin categoría (sin_asignar y rechazada). La fila tiene la forma exacta del contrato.
     *
     * @group categorias_ia
     * @test
     */
    public function cada_solapa_trae_sus_estados_y_los_conteos_de_las_tres()
    {
        $sembrado = $this->corrida_elegida_para_revisar();
        $run      = $sembrado['run'];
        $items    = $sembrado['items'];

        // Una rechazada más, para que "Sin categoría" junte los dos estados.
        $extra = $this->crear_articulo('Rechazado');
        CategoryProposalItem::create([
            'proposal_id' => $sembrado['propuestas']['A']['proposal']->id,
            'user_id'     => $this->owner->id,
            'article_id'  => $extra->id,
            'node_id'     => $sembrado['propuestas']['A']['nodos']['Correderas']->id,
            'confianza'   => 'dudosa',
            'estado'      => 'rechazada',
        ]);

        $esperados = [
            'a_revisar'     => [$items[3]->id, $items[5]->id],
            'asignados'     => [$items[1]->id, $items[2]->id, $items[4]->id],
            'sin_categoria' => [$items[6]->id, CategoryProposalItem::where('article_id', $extra->id)->value('id')],
        ];

        foreach ($esperados as $solapa => $ids) {

            $respuesta = $this->items($run->id, 'solapa='.$solapa)->assertStatus(200);

            $json = $respuesta->json();

            $this->assertSame(['models', 'conteos'], array_keys($json), $solapa);
            $this->assertSame(['a_revisar' => 2, 'asignados' => 3, 'sin_categoria' => 2], $json['conteos'], $solapa);
            $this->assertSame(array_map('intval', $ids), array_column($json['models']['data'], 'id'), 'Solapa '.$solapa.'.');
            $this->assertSame(count($ids), $json['models']['total']);
        }

        // Sin `solapa` es la de a revisar.
        $this->assertSame(
            [(int) $items[3]->id, (int) $items[5]->id],
            array_column($this->items($run->id)->assertStatus(200)->json()['models']['data'], 'id')
        );
    }

    /**
     * La fila de un ítem, con valores exactos: el artículo, la sugerencia con los nombres de la propuesta y
     * `actual` con los nombres de la categoría y subcategoría que el artículo tiene HOY.
     *
     * 🔴 CONTRATO (cambio aditivo de B-13/M-2, 5/10/2026): `actual` se informa en los ítems aplicados y
     * aprobados (ya tienen la categoría del sistema) y TAMBIÉN en los a revisar (el artículo todavía no
     * tiene la sugerida pero puede tener una propia, y es la que "Aprobar" va a pisar); va null en la
     * solapa "Sin categoría" (sin_asignar y rechazada) y cuando la categoría o subcategoría del artículo
     * está borrada. Antes los a revisar mandaban siempre null.
     *
     * @group categorias_ia
     * @test
     */
    public function la_fila_de_un_item_trae_el_articulo_la_sugerencia_y_lo_actual()
    {
        $real     = $this->crear_categoria_real('Bisagras reales');
        $real_sub = $this->crear_subcategoria_real('Comunes reales', $real);
        $borrada  = $this->crear_categoria_real('Categoria que se borro');

        $sembrado = $this->corrida_elegida_para_revisar([
            1 => ['category_id' => $real->id, 'sub_category_id' => $real_sub->id, 'bar_code' => '7791111111111', 'provider_code' => 'BIS-1'],
            3 => ['category_id' => $real->id, 'sub_category_id' => $real_sub->id],   // a revisar con categoría y subcategoría vivas
            5 => ['category_id' => $borrada->id],                                     // a revisar con una categoría que se borró
            6 => ['category_id' => $real->id, 'sub_category_id' => $real_sub->id],   // sin asignar, aunque el artículo tenga categoría
        ]);

        $borrada->delete();

        $run   = $sembrado['run'];
        $items = $sembrado['items'];
        $art1  = $sembrado['articulos'][1];

        // El ítem aplicado: sugerencia de la propuesta y categoría real del artículo.
        $fila = $this->items($run->id, 'solapa=asignados')->assertStatus(200)->json()['models']['data'][0];

        $this->assertSame(['id', 'estado', 'confianza', 'motivo', 'articulo', 'sugerencia', 'actual'], array_keys($fila));
        $this->assertSame([
            'id'         => (int) $items[1]->id,
            'estado'     => 'aplicada',
            'confianza'  => 'segura',
            'motivo'     => null,
            'articulo'   => [
                'id'                  => (int) $art1->id,
                'nombre'              => 'Articulo 1 de '.$this->owner->id,
                'codigo_de_barras'    => '7791111111111',
                'codigo_de_proveedor' => 'BIS-1',
            ],
            'sugerencia' => ['categoria' => 'Bisagras', 'subcategoria' => 'Comunes'],
            'actual'     => ['categoria' => 'Bisagras reales', 'subcategoria' => 'Comunes reales'],
        ], $fila);

        // El ítem a revisar: sugiere una categoría y `actual` muestra la que el artículo tiene hoy (es la
        // que "Aprobar" va a pisar).
        $filas = $this->items($run->id, 'solapa=a_revisar')->assertStatus(200)->json()['models']['data'];

        $a_revisar = $filas[0];

        $this->assertSame((int) $items[3]->id, $a_revisar['id']);
        $this->assertSame('a_revisar', $a_revisar['estado']);
        $this->assertSame('dudosa', $a_revisar['confianza']);
        $this->assertSame('no queda claro', $a_revisar['motivo']);
        $this->assertSame(['categoria' => 'Bisagras', 'subcategoria' => null], $a_revisar['sugerencia']);
        $this->assertSame(['categoria' => 'Bisagras reales', 'subcategoria' => 'Comunes reales'], $a_revisar['actual']);

        // Otro a revisar, pero la categoría del artículo se borró: no hay nada vivo que mostrar.
        $this->assertSame((int) $items[5]->id, $filas[1]['id']);
        $this->assertSame(['categoria' => null, 'subcategoria' => null], $filas[1]['actual']);

        // Un a revisar cuyo artículo no tiene categoría: null (lo que había antes de este cambio).
        $sin_categoria_previa = $this->crear_articulo('A revisar sin categoria');

        CategoryProposalItem::create([
            'proposal_id' => $sembrado['propuestas']['A']['proposal']->id,
            'user_id'     => $this->owner->id,
            'article_id'  => $sin_categoria_previa->id,
            'node_id'     => $sembrado['propuestas']['A']['nodos']['Correderas']->id,
            'confianza'   => 'dudosa',
            'estado'      => 'a_revisar',
        ]);

        $ultima = array_slice($this->items($run->id, 'solapa=a_revisar')->json()['models']['data'], -1)[0];

        $this->assertSame($sin_categoria_previa->id, $ultima['articulo']['id']);
        $this->assertSame(['categoria' => null, 'subcategoria' => null], $ultima['actual']);

        // La solapa "Sin categoría" (sin asignar y rechazada) no muestra categoría actual aunque el artículo
        // la tenga: esa solapa promete artículos sin categoría.
        $rechazado = $this->crear_articulo('Rechazado con categoria', ['category_id' => $real->id]);

        CategoryProposalItem::create([
            'proposal_id' => $sembrado['propuestas']['A']['proposal']->id,
            'user_id'     => $this->owner->id,
            'article_id'  => $rechazado->id,
            'node_id'     => $sembrado['propuestas']['A']['nodos']['Correderas']->id,
            'confianza'   => 'dudosa',
            'estado'      => 'rechazada',
        ]);

        $sin_categoria = $this->items($run->id, 'solapa=sin_categoria')->assertStatus(200)->json()['models']['data'];

        $this->assertCount(2, $sin_categoria);

        foreach ($sin_categoria as $fila_sin_categoria) {

            $this->assertSame(['categoria' => null, 'subcategoria' => null], $fila_sin_categoria['actual'], 'Estado '.$fila_sin_categoria['estado'].'.');
        }

        // El sin asignar no sugiere nada.
        $sin = $sin_categoria[0];

        $this->assertSame((int) $items[6]->id, $sin['id']);
        $this->assertSame(['categoria' => null, 'subcategoria' => null], $sin['sugerencia']);
        $this->assertSame('ambiguo', $sin['motivo']);
    }

    /**
     * El paginado se hace en el servidor: 25 por defecto, y 25, 50 o 100 a pedido (cualquier otro valor es
     * 25); trae la página, el total y los conteos.
     *
     * @group categorias_ia
     * @test
     */
    public function los_items_se_paginan_de_a_25_50_o_100()
    {
        $sembrado = $this->corrida_elegida_para_revisar();
        $run      = $sembrado['run'];
        $a        = $sembrado['propuestas']['A']['proposal'];

        // 120 ítems a revisar más (los seis de arriba ya tienen 2).
        $articulos = $this->crear_articulos_en_masa(120, null, 'Lote');

        foreach (array_chunk($articulos, 50) as $grupo) {

            $filas = [];

            foreach ($grupo as $id) {

                $filas[] = ['proposal_id' => $a->id, 'user_id' => $this->owner->id, 'article_id' => $id, 'node_id' => null, 'sub_node_id' => null, 'confianza' => 'dudosa', 'motivo' => null, 'estado' => 'a_revisar', 'created_at' => now(), 'updated_at' => now()];
            }

            \Illuminate\Support\Facades\DB::table('category_proposal_items')->insert($filas);
        }

        // 122 a revisar en total: por defecto, 25 por página.
        $pagina1 = $this->items($run->id, 'solapa=a_revisar')->assertStatus(200)->json();

        $this->assertCount(25, $pagina1['models']['data']);
        $this->assertSame(122, $pagina1['models']['total']);
        $this->assertSame(1, $pagina1['models']['current_page']);
        $this->assertSame(5, $pagina1['models']['last_page']);
        $this->assertSame(25, $pagina1['models']['per_page']);
        $this->assertSame(122, $pagina1['conteos']['a_revisar']);

        $this->assertCount(25, $this->items($run->id, 'solapa=a_revisar&page=2')->json()['models']['data']);
        $this->assertCount(22, $this->items($run->id, 'solapa=a_revisar&page=5')->json()['models']['data']);
        $this->assertCount(0, $this->items($run->id, 'solapa=a_revisar&page=6')->json()['models']['data']);

        $this->assertCount(50, $this->items($run->id, 'solapa=a_revisar&per_page=50')->json()['models']['data']);
        $this->assertCount(100, $this->items($run->id, 'solapa=a_revisar&per_page=100')->json()['models']['data']);

        foreach ([7, 30, 1000, 0, -5] as $raro) {

            $this->assertSame(25, $this->items($run->id, 'solapa=a_revisar&per_page='.$raro)->json()['models']['per_page'], 'per_page='.$raro);
        }

        // Páginas distintas no repiten filas.
        $ids_1 = array_column($this->items($run->id, 'solapa=a_revisar&per_page=50&page=1')->json()['models']['data'], 'id');
        $ids_2 = array_column($this->items($run->id, 'solapa=a_revisar&per_page=50&page=2')->json()['models']['data'], 'id');

        $this->assertSame([], array_intersect($ids_1, $ids_2));
    }

    /**
     * `buscar` filtra por nombre, código de barras y código de proveedor del artículo, y un `%` o un `_`
     * escrito por la persona es un carácter, no un comodín.
     *
     * @group categorias_ia
     * @test
     */
    public function buscar_filtra_por_nombre_codigo_de_barras_y_codigo_de_proveedor()
    {
        $sembrado = $this->corrida_elegida_para_revisar([
            3 => ['bar_code' => '7790003333333', 'provider_code' => 'PROV-TRES'],
            5 => ['provider_code' => 'otroXcodigo'],
        ]);

        $run = $sembrado['run'];

        $ids = function ($buscar) use ($run) {

            return array_column($this->items($run->id, 'solapa=a_revisar&buscar='.urlencode($buscar))->assertStatus(200)->json()['models']['data'], 'id');
        };

        $item_3 = (int) $sembrado['items'][3]->id;
        $item_5 = (int) $sembrado['items'][5]->id;

        $this->assertSame([$item_3], $ids('Articulo 3'));
        $this->assertSame([$item_3], $ids('7790003333'));
        $this->assertSame([$item_3], $ids('prov-tres'));
        $this->assertSame([$item_5], $ids('otroXcod'));
        $this->assertSame([$item_3, $item_5], $ids('Articulo'));
        $this->assertSame([], $ids('no existe esto'));

        // 🔴 `_` y `%` son comodines de LIKE: escritos por la persona son un carácter. Sin escapar,
        // 'otro_codigo' matchearía 'otroXcodigo' y '%' matchearía todo.
        $this->assertSame([], $ids('otro_codigo'));
        $this->assertSame([], $ids('%'));
        $this->assertSame([], $ids('Articul_ 3'));

        // La búsqueda no cambia los conteos de las solapas.
        $this->assertSame(2, $this->items($run->id, 'solapa=a_revisar&buscar=Articulo+3')->json()['conteos']['a_revisar']);
    }

    /**
     * Los ítems son SOLO de la propuesta elegida: los de las otras tarjetas no aparecen ni cuentan.
     *
     * @group categorias_ia
     * @test
     */
    public function los_items_son_solo_de_la_propuesta_elegida()
    {
        $sembrado = $this->corrida_elegida_para_revisar();
        $run      = $sembrado['run'];

        // Otra tarjeta de la misma corrida con ítems a revisar.
        $b = CategoryProposal::create(['run_id' => $run->id, 'user_id' => $this->owner->id, 'clave' => 'B', 'tipo' => 'nueva', 'nombre' => 'Otra', 'orden' => 2]);

        CategoryProposalItem::create(['proposal_id' => $b->id, 'user_id' => $this->owner->id, 'article_id' => $sembrado['articulos'][1]->id, 'confianza' => 'dudosa', 'estado' => 'a_revisar']);

        $json = $this->items($run->id, 'solapa=a_revisar')->assertStatus(200)->json();

        $this->assertSame(2, $json['models']['total']);
        $this->assertSame(2, $json['conteos']['a_revisar']);
    }

    /**
     * Los errores de `items`: la corrida sin propuesta elegida es 409 `todavia_no_elegida`, una solapa que no
     * existe es 422 `validacion` con su detalle.
     *
     * @group categorias_ia
     * @test
     */
    public function los_errores_de_items_traen_su_codigo()
    {
        $lista = $this->corrida_con_a('lista');

        $respuesta = $this->items($lista['run']->id, 'solapa=a_revisar');

        $respuesta->assertStatus(409);
        $this->assertSame('todavia_no_elegida', $respuesta->json()['error']);
        $this->assertNotEmpty($respuesta->json()['message']);

        $elegida = $this->corrida_elegida_para_revisar();

        $respuesta = $this->items($elegida['run']->id, 'solapa=otra');

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json()['error']);
        $this->assertArrayHasKey('solapa', $respuesta->json()['detalle']);
    }

    /**
     * 🔴 B-14 (verificador, 5/10/2026): `solapa` y `buscar` que llegan como ARREGLO en la query string
     * (`solapa[]=x`, `buscar[]=x`) son 422 `validacion` con su campo en `detalle`, no un 500 "Array to
     * string conversion". `page[]` y `per_page[]` siguen dando 200 (un `(int)` de un arreglo no tira), y
     * un `buscar=` vacío es "sin búsqueda".
     *
     * @group categorias_ia
     * @test
     */
    public function solapa_y_buscar_con_forma_de_arreglo_son_422_y_no_500()
    {
        $sembrado = $this->corrida_elegida_para_revisar();
        $run      = $sembrado['run'];

        $casos = [
            'solapa[]'          => ['solapa[]=a_revisar', ['solapa']],
            'solapa[a] y [b]'   => ['solapa[a]=x&solapa[b]=y', ['solapa']],
            'buscar[]'          => ['solapa=a_revisar&buscar[]=x', ['buscar']],
            'las dos a la vez'  => ['solapa[]=a_revisar&buscar[]=x', ['solapa', 'buscar']],
            'solapa vacía'      => ['solapa=', ['solapa']],
        ];

        foreach ($casos as $descripcion => $caso) {

            list($query, $campos) = $caso;

            $respuesta = $this->items($run->id, $query);

            $this->assertSame(422, $respuesta->getStatusCode(), $descripcion.': '.$respuesta->getContent());

            $json = $respuesta->json();

            $this->assertSame('validacion', $json['error'], $descripcion);
            $this->assertNotEmpty($json['message'], $descripcion);
            $this->assertSame($campos, array_keys($json['detalle']), $descripcion);
        }

        // Los números como arreglo y la búsqueda vacía no rompen nada.
        $this->items($run->id, 'solapa=a_revisar&page[]=2')->assertStatus(200);
        $this->items($run->id, 'solapa=a_revisar&per_page[]=50')->assertStatus(200)->assertJsonPath('models.per_page', 25);
        $this->items($run->id, 'solapa=a_revisar&buscar=')->assertStatus(200)->assertJsonPath('models.total', 2);
    }

    /**
     * Una corrida `preparando` no existe para el dueño (B-07): sus ítems son un 404 `no_encontrado`, no un
     * 409 "todavía no elegida" (que confirmaría que hay una corrida en preparación).
     *
     * @group categorias_ia
     * @test
     */
    public function una_corrida_preparando_no_tiene_items_para_el_dueno()
    {
        $preparando = $this->corrida_con_a('preparando');

        $this->items($preparando['run']->id)->assertStatus(404)->assertJson(['error' => 'no_encontrado']);
    }
}
