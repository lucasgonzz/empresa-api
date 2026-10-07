<?php

namespace Tests\Feature\CategoryProposals;

use App\Models\Category;
use App\Models\CategoryProposalItem;
use App\Models\CategoryProposalNode;
use App\Models\CategoryProposalRun;
use App\Models\SubCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Revisar lo dudoso: aprobar y rechazar, de a uno y en lote (misión categorizacion-tres-modelos,
 * 5/10/2026). Contrato B §6.7 y plan §4.3.
 *
 * Qué protege:
 *  - Aprobar crea el nodo real RECIÉN ahí si hacía falta (las categorías que solo tenían dudosos no se
 *    crean al elegir) y lo reutiliza si ya existe: dos dudosos del mismo nodo nuevo crean UNA categoría.
 *  - Rechazar deja al artículo sin categoría (aparece en "Sin categoría").
 *  - La primera acción de revisión setea `revision_iniciada_at` (es la marca de la regla (b)) y las
 *    siguientes no la mueven.
 *  - El lote cuenta `procesados` y `omitidos` (los ajenos, inexistentes o fuera de estado se omiten sin
 *    distinguirse), con un tope de 500 ids.
 *  - La fila del ítem de la respuesta tiene EXACTAMENTE las claves del contrato §6.6.
 *  - A diferencia del aplicar de "mantener", aprobar SÍ pisa la categoría que el artículo tenga hoy:
 *    es una acción explícita del dueño sobre ese artículo, con la sugerencia a la vista.
 *
 * El doble pedido, el empleado y los ids ajenos están en `10_Doble_pedido_y_tenencia_de_la_eleccion_Test`.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Revisar_lo_dudoso_Test extends CategoryProposalsTestCase
{
    use AyudasDeLaEleccion;

    /**
     * Un sistema nuevo ya ELEGIDO con siete ítems: dos seguros que crean Bisagras / Comunes, cuatro
     * dudosos (uno en un nodo ya creado, uno en una subcategoría que no se creó, uno en una categoría que
     * no se creó y dos en la misma categoría y subcategoría que tampoco se crearon) y uno sin ubicar.
     *
     *   a1 Bisagras / Comunes ........ segura   → aplicada
     *   a2 Bisagras / Comunes ........ dudosa   → a revisar (nodo ya creado por el aplicar)
     *   a3 Bisagras / Raras .......... dudosa   → a revisar (la subcategoría no se creó)
     *   a4 Correderas ................ dudosa   → a revisar (la categoría no se creó)
     *   a5 Fijaciones / Tornillos .... dudosa   → a revisar (ni categoría ni subcategoría)
     *   a6 Fijaciones / Tornillos .... dudosa   → a revisar (el mismo nodo que a5)
     *   a7 sin categoría ............. ninguna  → sin asignar
     *
     * @return array  ['articulos' => [a1..a7], 'run', 'proposal', 'items' => [i1..i7], 'nodos']
     */
    protected function sistema_elegido_con_dudosos()
    {
        $articulos = [];

        foreach (['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7'] as $i => $nombre) {
            $articulos[] = $this->crear_articulo($nombre, [
                'bar_code'      => '77900000000'.$i,
                'provider_code' => 'PROV-'.$nombre,
            ]);
        }

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => [
                        'Bisagras'   => ['Comunes', 'Raras'],
                        'Correderas' => [],
                        'Fijaciones' => ['Tornillos'],
                    ],
                    'items' => [
                        [$articulos[0], 'Bisagras', 'Comunes', 'segura'],
                        [$articulos[1], 'Bisagras', 'Comunes', 'dudosa', 'puede ser una bisagra comun'],
                        [$articulos[2], 'Bisagras', 'Raras', 'dudosa', 'no se si es rara'],
                        [$articulos[3], 'Correderas', null, 'dudosa', 'parece una corredera'],
                        [$articulos[4], 'Fijaciones', 'Tornillos', 'dudosa', 'tornillo o bulon'],
                        [$articulos[5], 'Fijaciones', 'Tornillos', 'dudosa', 'tornillo o bulon'],
                        [$articulos[6], null, null, 'ninguna', 'nombre ambiguo'],
                    ],
                ],
            ],
        ]);

        $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['A']['proposal'], false)->assertStatus(200);

        return [
            'articulos' => $articulos,
            'run'       => $sembrado['run'],
            'proposal'  => $sembrado['propuestas']['A']['proposal'],
            'items'     => $sembrado['propuestas']['A']['items'],
            'nodos'     => $sembrado['propuestas']['A']['nodos'],
        ];
    }

    /**
     * Cuántas categorías y subcategorías vivas tiene el comercio del test.
     *
     * @return array  [categorias, subcategorias]
     */
    protected function cantidades()
    {
        return [
            Category::where('user_id', $this->owner->id)->count(),
            SubCategory::where('user_id', $this->owner->id)->count(),
        ];
    }

    /**
     * El ítem leído de la base.
     *
     * @param  \App\Models\CategoryProposalItem $item
     * @return \App\Models\CategoryProposalItem
     */
    protected function item_fresco($item)
    {
        return CategoryProposalItem::find($item->id);
    }

    // ---------------------------------------------------------------------------------------------
    // Aprobar
    // ---------------------------------------------------------------------------------------------

    /**
     * Aprobar un dudoso de un nodo que el aplicar ya creó: el artículo recibe la categoría y la
     * subcategoría, el ítem pasa a `aprobada` con quién y cuándo, y no se crea nada. La primera acción de
     * revisión marca `revision_iniciada_at` en la corrida.
     *
     * @test
     * @group categorias_ia
     */
    public function aprobar_un_dudoso_de_un_nodo_ya_creado_asigna_sin_crear_nada()
    {
        $s = $this->sistema_elegido_con_dudosos();
        $antes = $this->cantidades();

        $this->assertNull(CategoryProposalRun::find($s['run']->id)->revision_iniciada_at);

        $respuesta = $this->pedir_aprobar($s['items'][1]);

        $respuesta->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('item.estado', 'aprobada');
        $this->assertArrayHasKey('conteos', $respuesta->json());
        $this->assertNull($respuesta->json('ya_estaba'), 'La primera vez no es "ya estaba".');

        $this->assertSame($antes, $this->cantidades(), 'Los nodos ya estaban creados: no se crea nada.');

        $bisagras = $this->categorias_llamadas('Bisagras')->first();
        $comunes  = SubCategory::where('category_id', $bisagras->id)->where('name', 'Comunes')->first();
        $this->assertSame(['category_id' => $bisagras->id, 'sub_category_id' => $comunes->id], $this->categorias_de($s['articulos'][1]));

        $item = $this->item_fresco($s['items'][1]);
        $this->assertSame('aprobada', $item->estado);
        $this->assertSame($this->owner->id, (int) $item->revisado_por);
        $this->assertNotNull($item->revisado_at);

        $this->assertNotNull(CategoryProposalRun::find($s['run']->id)->revision_iniciada_at, 'La primera revisión marca la regla (b).');
    }

    /**
     * 🔴 Aprobar crea el nodo real RECIÉN AHÍ si hacía falta: la categoría que solo tenía dudosos
     * (`Correderas`) y la subcategoría que no se había creado (`Raras`, colgada de una categoría que sí).
     *
     * @test
     * @group categorias_ia
     */
    public function aprobar_crea_el_nodo_real_la_primera_vez()
    {
        $s = $this->sistema_elegido_con_dudosos();

        $this->assertSame(0, $this->categorias_llamadas('Correderas')->count(), 'Elegir no la creó.');
        $antes = $this->cantidades();

        // Una categoría nueva.
        $this->pedir_aprobar($s['items'][3])->assertStatus(200);

        $correderas = $this->categorias_llamadas('Correderas')->first();
        $this->assertNotNull($correderas, 'Aprobar la creó.');
        $this->assertSame($this->owner->id, (int) $correderas->user_id);
        $this->assertSame(['category_id' => $correderas->id, 'sub_category_id' => null], $this->categorias_de($s['articulos'][3]));

        $nodo = CategoryProposalNode::find($s['nodos']['Correderas']->id);
        $this->assertSame($correderas->id, (int) $nodo->real_category_id);
        $this->assertTrue($nodo->real_creado);

        // Una subcategoría nueva dentro de una categoría que el aplicar ya había creado.
        $this->pedir_aprobar($s['items'][2])->assertStatus(200);

        $bisagras = $this->categorias_llamadas('Bisagras')->first();
        $raras    = SubCategory::where('category_id', $bisagras->id)->where('name', 'Raras')->first();
        $this->assertNotNull($raras);
        $this->assertSame(['category_id' => $bisagras->id, 'sub_category_id' => $raras->id], $this->categorias_de($s['articulos'][2]));

        $nodo_sub = CategoryProposalNode::find($s['nodos']['Bisagras/Raras']->id);
        $this->assertSame($raras->id, (int) $nodo_sub->real_sub_category_id);
        $this->assertTrue($nodo_sub->real_creado);

        $this->assertSame([$antes[0] + 1, $antes[1] + 1], $this->cantidades(), 'Una categoría y una subcategoría, nada más.');
    }

    /**
     * 🔴 Dos dudosos del MISMO nodo que no se creó crean UNA categoría y UNA subcategoría, no dos: el
     * segundo reutiliza lo que acaba de crear el primero (el nodo ya quedó atado).
     *
     * @test
     * @group categorias_ia
     */
    public function dos_dudosos_del_mismo_nodo_nuevo_crean_una_sola_categoria()
    {
        $s = $this->sistema_elegido_con_dudosos();
        $antes = $this->cantidades();

        $this->pedir_aprobar($s['items'][4])->assertStatus(200);
        $this->pedir_aprobar($s['items'][5])->assertStatus(200);

        $this->assertSame([$antes[0] + 1, $antes[1] + 1], $this->cantidades());
        $this->assertSame(1, $this->categorias_llamadas('Fijaciones')->count());

        $fijaciones = $this->categorias_llamadas('Fijaciones')->first();
        $tornillos  = SubCategory::where('category_id', $fijaciones->id)->where('name', 'Tornillos')->first();

        foreach ([4, 5] as $i) {
            $this->assertSame(['category_id' => $fijaciones->id, 'sub_category_id' => $tornillos->id], $this->categorias_de($s['articulos'][$i]));
        }
    }

    /**
     * Aprobar y rechazar en LOTE el mismo caso: el lote resuelve cada nodo una sola vez.
     *
     * @test
     * @group categorias_ia
     */
    public function el_lote_tambien_crea_una_sola_categoria_por_nodo()
    {
        $s = $this->sistema_elegido_con_dudosos();
        $antes = $this->cantidades();

        $respuesta = $this->pedir_en_lote('aprobar', [$s['items'][4]->id, $s['items'][5]->id]);

        $respuesta->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertSame(2, $respuesta->json('procesados'));
        $this->assertSame(0, $respuesta->json('omitidos'));
        $this->assertArrayHasKey('conteos', $respuesta->json());

        $this->assertSame([$antes[0] + 1, $antes[1] + 1], $this->cantidades());

        $fijaciones = $this->categorias_llamadas('Fijaciones')->first();
        $this->assertSame($fijaciones->id, $this->categorias_de($s['articulos'][4])['category_id']);
        $this->assertSame($fijaciones->id, $this->categorias_de($s['articulos'][5])['category_id']);
        $this->assertSame('aprobada', $this->item_fresco($s['items'][4])->estado);
        $this->assertSame('aprobada', $this->item_fresco($s['items'][5])->estado);
    }

    /**
     * Aprobar un dudoso pisa la categoría que el artículo tenga hoy (alguien se la puso a mano mientras
     * estaba a revisar): es una acción explícita del dueño con la sugerencia a la vista. Distinto del
     * aplicar de "mantener", que completa y no pisa.
     *
     * @test
     * @group categorias_ia
     */
    public function aprobar_pisa_la_categoria_que_el_articulo_tenga_hoy()
    {
        $s = $this->sistema_elegido_con_dudosos();

        $otra = $this->categoria_real('Otra a mano');
        DB::table('articles')->where('id', $s['articulos'][1]->id)->update(['category_id' => $otra->id]);

        $this->pedir_aprobar($s['items'][1])->assertStatus(200);

        $bisagras = $this->categorias_llamadas('Bisagras')->first();
        $this->assertSame($bisagras->id, $this->categorias_de($s['articulos'][1])['category_id']);
    }

    /**
     * En "mantener", un ítem que quedó "a revisar" porque el artículo ya tenía categoría se puede aprobar:
     * el artículo pasa a la categoría existente que sugería la skill.
     *
     * @test
     * @group categorias_ia
     */
    public function aprobar_en_mantener_asigna_la_categoria_existente()
    {
        $herrajes   = $this->categoria_real('Herrajes');
        $fijaciones = $this->categoria_real('Fijaciones');
        $articulo   = $this->crear_articulo('Bisagra', ['category_id' => $fijaciones->id]);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'mantener' => [
                    'tipo'  => 'mantener',
                    'arbol' => ['Herrajes' => ['subs' => [], 'existing_category_id' => $herrajes->id]],
                    'items' => [[$articulo, 'Herrajes', null, 'segura']],
                ],
            ],
        ]);

        $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['mantener']['proposal'])->assertStatus(200);
        $this->assertSame('a_revisar', $this->item_fresco($sembrado['propuestas']['mantener']['items'][0])->estado, 'Ya tenía categoría: queda a revisar.');
        $this->assertSame($fijaciones->id, $this->categorias_de($articulo)['category_id']);

        $this->pedir_aprobar($sembrado['propuestas']['mantener']['items'][0])->assertStatus(200);

        $this->assertSame($herrajes->id, $this->categorias_de($articulo)['category_id']);
        $this->assertSame(2, $this->categorias_llamadas('Herrajes')->count() + $this->categorias_llamadas('Fijaciones')->count(), 'No se creó ninguna.');
    }

    /**
     * Aprobar un ítem de "mantener" con márgenes por categoría recalcula el precio del artículo en
     * segundo plano, después del commit (cambiarle la categoría a un artículo le mueve el precio).
     *
     * @test
     * @group categorias_ia
     */
    public function aprobar_con_margenes_por_categoria_recalcula_el_precio_del_articulo()
    {
        Queue::fake();

        $herrajes   = $this->categoria_real('Herrajes', null, ['percentage_gain' => 10]);
        $fijaciones = $this->categoria_real('Fijaciones');
        $articulo   = $this->crear_articulo('Bisagra', ['category_id' => $fijaciones->id]);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'mantener' => [
                    'tipo'  => 'mantener',
                    'arbol' => ['Herrajes' => ['subs' => [], 'existing_category_id' => $herrajes->id]],
                    'items' => [[$articulo, 'Herrajes', null, 'segura']],
                ],
            ],
        ]);

        $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['mantener']['proposal'])->assertStatus(200);
        $this->assertSame([], $this->ids_encolados_para_recalcular(), 'Elegir no asignó nada: el artículo ya tenía categoría.');

        $this->pedir_aprobar($sembrado['propuestas']['mantener']['items'][0])->assertStatus(200);

        $this->assertSame([$articulo->id], $this->ids_encolados_para_recalcular());
    }

    /**
     * Si la categoría que el aplicar había creado para un nodo se mandó a la papelera después, aprobar un
     * dudoso de ese nodo NO escribe la categoría borrada: resuelve el nodo de nuevo (la vuelve a crear) y lo
     * ata a la nueva.
     *
     * @test
     * @group categorias_ia
     */
    public function aprobar_no_escribe_una_categoria_que_ya_esta_en_la_papelera()
    {
        $s = $this->sistema_elegido_con_dudosos();

        $vieja = $this->categorias_llamadas('Bisagras')->first();
        $vieja->delete();

        $this->pedir_aprobar($s['items'][1])->assertStatus(200);

        $nueva = $this->categorias_llamadas('Bisagras')->first();
        $this->assertNotNull($nueva, 'Se volvió a crear.');
        $this->assertNotSame($vieja->id, $nueva->id);
        $this->assertSame($nueva->id, $this->categorias_de($s['articulos'][1])['category_id']);
        $this->assertSame($nueva->id, (int) CategoryProposalNode::find($s['nodos']['Bisagras']->id)->real_category_id);
    }

    /**
     * Aprobar un ítem de "mantener" cuya categoría existente ya no existe responde 409
     * `categoria_inexistente` y no toca nada: se puede rechazar.
     *
     * @test
     * @group categorias_ia
     */
    public function aprobar_un_item_de_mantener_con_la_categoria_borrada_da_409()
    {
        $herrajes   = $this->categoria_real('Herrajes');
        $fijaciones = $this->categoria_real('Fijaciones');
        $articulo   = $this->crear_articulo('Bisagra', ['category_id' => $fijaciones->id]);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'mantener' => [
                    'tipo'  => 'mantener',
                    'arbol' => ['Herrajes' => ['subs' => [], 'existing_category_id' => $herrajes->id]],
                    'items' => [[$articulo, 'Herrajes', null, 'segura']],
                ],
            ],
        ]);

        $this->pedir_elegir($sembrado['run'], $sembrado['propuestas']['mantener']['proposal'])->assertStatus(200);
        $herrajes->delete();

        $item = $sembrado['propuestas']['mantener']['items'][0];

        $this->pedir_aprobar($item)->assertStatus(409)->assertJsonPath('error', 'categoria_inexistente');
        $this->assertSame('a_revisar', $this->item_fresco($item)->estado);
        $this->assertSame($fijaciones->id, $this->categorias_de($articulo)['category_id']);

        $this->pedir_rechazar($item)->assertStatus(200)->assertJsonPath('item.estado', 'rechazada');
    }

    // ---------------------------------------------------------------------------------------------
    // Rechazar
    // ---------------------------------------------------------------------------------------------

    /**
     * Rechazar deja al artículo sin categoría (no se toca) y al ítem en `rechazada`, con quién y cuándo.
     *
     * @test
     * @group categorias_ia
     */
    public function rechazar_deja_el_articulo_sin_categoria()
    {
        $s = $this->sistema_elegido_con_dudosos();
        $antes = $this->cantidades();

        $respuesta = $this->pedir_rechazar($s['items'][1]);

        $respuesta->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('item.estado', 'rechazada');

        $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($s['articulos'][1]));
        $this->assertSame($antes, $this->cantidades(), 'Rechazar no crea nada.');

        $item = $this->item_fresco($s['items'][1]);
        $this->assertSame('rechazada', $item->estado);
        $this->assertSame($this->owner->id, (int) $item->revisado_por);
        $this->assertNotNull($item->revisado_at);
        $this->assertNotNull(CategoryProposalRun::find($s['run']->id)->revision_iniciada_at);

        // Rechazar un dudoso de un nodo que no se creó no lo crea.
        $this->pedir_rechazar($s['items'][3])->assertStatus(200);
        $this->assertSame(0, $this->categorias_llamadas('Correderas')->count());
    }

    /**
     * La primera acción de revisión marca `revision_iniciada_at` y las siguientes no lo mueven (aprobar o
     * rechazar, lo que venga primero).
     *
     * @test
     * @group categorias_ia
     */
    public function solo_la_primera_revision_marca_el_inicio()
    {
        $s = $this->sistema_elegido_con_dudosos();

        $this->pedir_rechazar($s['items'][1])->assertStatus(200);

        // Se le pone una fecha fija a la marca: la segunda acción no la puede tocar.
        DB::table('category_proposal_runs')->where('id', $s['run']->id)->update(['revision_iniciada_at' => '2026-01-02 03:04:05']);

        $this->pedir_aprobar($s['items'][2])->assertStatus(200);
        $this->assertSame('2026-01-02 03:04:05', DB::table('category_proposal_runs')->where('id', $s['run']->id)->value('revision_iniciada_at'));

        $this->pedir_en_lote('rechazar', [$s['items'][3]->id])->assertStatus(200);
        $this->assertSame('2026-01-02 03:04:05', DB::table('category_proposal_runs')->where('id', $s['run']->id)->value('revision_iniciada_at'));
    }

    /**
     * Un lote que no procesó nada (todo omitido) no cuenta como "empezar a revisar": la marca sigue en
     * NULL y todavía se puede cambiar de sistema.
     *
     * @test
     * @group categorias_ia
     */
    public function un_lote_sin_nada_procesado_no_marca_el_inicio_de_la_revision()
    {
        $s = $this->sistema_elegido_con_dudosos();

        // El ítem sin ubicar y el seguro no están "a revisar": se omiten.
        $respuesta = $this->pedir_en_lote('aprobar', [$s['items'][0]->id, $s['items'][6]->id, 999999999]);

        $respuesta->assertStatus(200);
        $this->assertSame(0, $respuesta->json('procesados'));
        $this->assertSame(3, $respuesta->json('omitidos'));
        $this->assertNull(CategoryProposalRun::find($s['run']->id)->revision_iniciada_at);
    }

    // ---------------------------------------------------------------------------------------------
    // Lote
    // ---------------------------------------------------------------------------------------------

    /**
     * Aprobar y rechazar en lote cuentan `procesados` y `omitidos`: los que no estaban "a revisar" (un
     * seguro ya asignado, uno sin ubicar, uno ya resuelto) y los inexistentes se omiten.
     *
     * @test
     * @group categorias_ia
     */
    public function el_lote_cuenta_procesados_y_omitidos()
    {
        $s = $this->sistema_elegido_con_dudosos();

        // Aprobar: dos a revisar + uno ya aplicado (omitido) + uno sin ubicar (omitido) + uno inexistente.
        $aprobar = $this->pedir_en_lote('aprobar', [
            $s['items'][1]->id, $s['items'][2]->id, $s['items'][0]->id, $s['items'][6]->id, 999999999,
        ]);

        $aprobar->assertStatus(200);
        $this->assertSame(2, $aprobar->json('procesados'));
        $this->assertSame(3, $aprobar->json('omitidos'));
        $this->assertSame('aprobada', $this->item_fresco($s['items'][1])->estado);
        $this->assertSame('aprobada', $this->item_fresco($s['items'][2])->estado);
        $this->assertSame('aplicada', $this->item_fresco($s['items'][0])->estado, 'El ya asignado no se toca.');
        $this->assertSame('sin_asignar', $this->item_fresco($s['items'][6])->estado);

        // Rechazar: uno a revisar + uno ya aprobado (omitido).
        $rechazar = $this->pedir_en_lote('rechazar', [$s['items'][3]->id, $s['items'][1]->id]);

        $rechazar->assertStatus(200);
        $this->assertSame(1, $rechazar->json('procesados'));
        $this->assertSame(1, $rechazar->json('omitidos'));
        $this->assertSame('rechazada', $this->item_fresco($s['items'][3])->estado);
        $this->assertSame('aprobada', $this->item_fresco($s['items'][1])->estado, 'El ya aprobado no pasa a rechazado.');
    }

    /**
     * La forma del cuerpo del lote: `ids` es un arreglo de 1 a 500 enteros positivos. Todo lo demás es 422
     * `validacion` con el detalle en `ids`. Los repetidos se juntan y los números como texto se aceptan.
     *
     * @test
     * @group categorias_ia
     */
    public function el_lote_valida_la_forma_de_los_ids()
    {
        $s = $this->sistema_elegido_con_dudosos();

        $invalidos = [
            'sin ids'          => [],
            'ids vacío'        => ['ids' => []],
            'ids no es lista'  => ['ids' => 'abc'],
            'id con letras'    => ['ids' => [$s['items'][1]->id, 'abc']],
            'id cero'          => ['ids' => [0]],
            'id negativo'      => ['ids' => [-4]],
            'id booleano'      => ['ids' => [true]],
            'id lista'         => ['ids' => [[1]]],
            'id decimal'       => ['ids' => [1.5]],
        ];

        foreach ($invalidos as $caso => $cuerpo) {
            $r = $this->postJson('api/category-proposal-items/aprobar-varios', $cuerpo);
            $r->assertStatus(422);
            $this->assertSame('validacion', $r->json('error'), $caso);
            $this->assertArrayHasKey('ids', $r->json('detalle'), $caso);
            $this->assertNotEmpty($r->json('message'), $caso);
        }

        // El tope: 500 pasan, 501 no.
        $tope = (int) config('catalogo_ia.ids_por_lote_de_revision_maximo');
        $this->assertSame(500, $tope);

        $demasiados = range(1000000000, 1000000000 + $tope);
        $this->postJson('api/category-proposal-items/rechazar-varios', ['ids' => $demasiados])->assertStatus(422)->assertJsonPath('error', 'validacion');

        $justos = array_slice($demasiados, 0, $tope);
        $ok = $this->postJson('api/category-proposal-items/rechazar-varios', ['ids' => $justos]);
        $ok->assertStatus(200);
        $this->assertSame($tope, $ok->json('omitidos'));

        // Nada de lo anterior tocó los ítems.
        $this->assertSame('a_revisar', $this->item_fresco($s['items'][1])->estado);

        // Los repetidos se juntan y los números como texto se aceptan.
        $r = $this->postJson('api/category-proposal-items/aprobar-varios', ['ids' => [(string) $s['items'][1]->id, $s['items'][1]->id, (string) $s['items'][2]->id]]);
        $r->assertStatus(200);
        $this->assertSame(2, $r->json('procesados'));
        $this->assertSame(0, $r->json('omitidos'));
    }

    // ---------------------------------------------------------------------------------------------
    // Errores de un ítem
    // ---------------------------------------------------------------------------------------------

    /**
     * Aprobar o rechazar un ítem que no está a revisar (ya asignado por el aplicar, o sin ubicar) responde
     * 409 `no_esta_a_revisar`; y si ya se había hecho exactamente lo mismo, 200 `ya_estaba`.
     *
     * @test
     * @group categorias_ia
     */
    public function un_item_fuera_de_estado_da_409_y_uno_ya_resuelto_da_200_ya_estaba()
    {
        $s = $this->sistema_elegido_con_dudosos();

        $this->pedir_aprobar($s['items'][0])->assertStatus(409)->assertJsonPath('error', 'no_esta_a_revisar');
        $this->pedir_rechazar($s['items'][6])->assertStatus(409)->assertJsonPath('error', 'no_esta_a_revisar');

        $this->pedir_aprobar($s['items'][1])->assertStatus(200);
        $this->pedir_aprobar($s['items'][1])->assertStatus(200)->assertJsonPath('ya_estaba', true);

        // Aprobado y rechazar después: no es lo mismo, y ya no está a revisar.
        $this->pedir_rechazar($s['items'][1])->assertStatus(409)->assertJsonPath('error', 'no_esta_a_revisar');
        $this->assertSame('aprobada', $this->item_fresco($s['items'][1])->estado);
    }

    /**
     * Mientras la corrida no está elegida (todavía, o porque se volvió atrás) no hay nada para revisar:
     * 409 `todavia_no_elegida`.
     *
     * @test
     * @group categorias_ia
     */
    public function sin_un_sistema_elegido_no_hay_nada_para_revisar()
    {
        $s = $this->sistema_elegido_con_dudosos();

        $this->pedir_volver_atras($s['run'])->assertStatus(200);

        $this->pedir_aprobar($s['items'][1])->assertStatus(409)->assertJsonPath('error', 'todavia_no_elegida');
        $this->pedir_rechazar($s['items'][1])->assertStatus(409)->assertJsonPath('error', 'todavia_no_elegida');

        // En lote se omite sin ruido.
        $lote = $this->pedir_en_lote('aprobar', [$s['items'][1]->id]);
        $lote->assertStatus(200);
        $this->assertSame(0, $lote->json('procesados'));
        $this->assertSame(1, $lote->json('omitidos'));
    }

    /**
     * Un artículo que se borró mientras estaba a revisar no se puede aprobar (409
     * `articulo_inexistente`); en lote se omite. El ítem queda como estaba.
     *
     * @test
     * @group categorias_ia
     */
    public function aprobar_un_articulo_borrado_da_409()
    {
        $s = $this->sistema_elegido_con_dudosos();

        $s['articulos'][1]->delete();
        $s['articulos'][2]->delete();

        $this->pedir_aprobar($s['items'][1])->assertStatus(409)->assertJsonPath('error', 'articulo_inexistente');
        $this->assertSame('a_revisar', $this->item_fresco($s['items'][1])->estado);

        $lote = $this->pedir_en_lote('aprobar', [$s['items'][2]->id, $s['items'][3]->id]);
        $this->assertSame(1, $lote->json('procesados'), 'Solo el que tiene artículo.');
        $this->assertSame(1, $lote->json('omitidos'));
        $this->assertSame('a_revisar', $this->item_fresco($s['items'][2])->estado);
    }

    // ---------------------------------------------------------------------------------------------
    // La fila del ítem (contrato §6.6)
    // ---------------------------------------------------------------------------------------------

    /**
     * La fila del ítem que devuelven aprobar y rechazar tiene EXACTAMENTE las claves del contrato §6.6, con
     * el nombre y los códigos del artículo, la sugerencia de la skill y, solo si ya está asignado, los
     * nombres reales de la categoría y subcategoría donde quedó.
     *
     * @test
     * @group categorias_ia
     */
    public function la_fila_del_item_tiene_el_formato_del_contrato()
    {
        $s = $this->sistema_elegido_con_dudosos();

        $aprobada = $this->pedir_aprobar($s['items'][1]);
        $aprobada->assertStatus(200);

        $item = $aprobada->json('item');
        $this->assertSame(['id', 'estado', 'confianza', 'motivo', 'articulo', 'sugerencia', 'actual'], array_keys($item));
        $this->assertSame(['id', 'nombre', 'codigo_de_barras', 'codigo_de_proveedor'], array_keys($item['articulo']));
        $this->assertSame(['categoria', 'subcategoria'], array_keys($item['sugerencia']));
        $this->assertSame(['categoria', 'subcategoria'], array_keys($item['actual']));

        $this->assertSame($s['items'][1]->id, $item['id']);
        $this->assertSame('aprobada', $item['estado']);
        $this->assertSame('dudosa', $item['confianza']);
        $this->assertSame('puede ser una bisagra comun', $item['motivo']);
        $this->assertSame($s['articulos'][1]->id, $item['articulo']['id']);
        $this->assertSame('a2', $item['articulo']['nombre']);
        $this->assertSame('779000000001', $item['articulo']['codigo_de_barras']);
        $this->assertSame('PROV-a2', $item['articulo']['codigo_de_proveedor']);
        $this->assertSame(['categoria' => 'Bisagras', 'subcategoria' => 'Comunes'], $item['sugerencia']);
        $this->assertSame(['categoria' => 'Bisagras', 'subcategoria' => 'Comunes'], $item['actual'], 'Aprobada: dónde quedó de verdad.');

        // Rechazada: el artículo no tiene la categoría sugerida, y `actual` va en null.
        $rechazada = $this->pedir_rechazar($s['items'][2]);
        $rechazada->assertStatus(200);
        $this->assertSame(['categoria' => 'Bisagras', 'subcategoria' => 'Raras'], $rechazada->json('item.sugerencia'));
        $this->assertSame(['categoria' => null, 'subcategoria' => null], $rechazada->json('item.actual'));
        $this->assertSame('rechazada', $rechazada->json('item.estado'));
    }
}
