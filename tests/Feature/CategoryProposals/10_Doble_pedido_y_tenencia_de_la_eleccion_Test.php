<?php

namespace Tests\Feature\CategoryProposals;

use App\Models\Category;
use App\Models\CategoryProposalItem;
use App\Models\CategoryProposalRun;
use App\Models\SubCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * El doble pedido y la tenencia de elegir, volver atrás, aprobar y rechazar (misión
 * categorizacion-tres-modelos, 5/10/2026). Plan §2.2, §2.4 y §4.7, y R2 §4.6 (clases de error 2 y 3).
 *
 * Qué protege:
 *  - 🔴 EL SEGUNDO CLIC: dos pedidos seguidos de la misma acción aplican UNA sola vez (mismas filas,
 *    mismos contadores, una sola categoría creada, nada se reescribe) y el segundo responde 200 con
 *    `"ya_estaba": true`. Los pedidos de verdad concurrentes los serializa `lockForUpdate()` sobre la
 *    corrida y el estado se lee de la fila bloqueada: un test secuencial no los puede reproducir, pero sí
 *    prueba lo que importa del candado (que el segundo pedido lee el estado que dejó el primero).
 *  - 🔴 TENENCIA: un empleado (con o sin `admin_access`) no puede nada: 403 `solo_el_dueno`. Un id de otro
 *    comercio que EXISTE responde 404, con el mismo cuerpo que uno inexistente, en cada ruta. El acceso
 *    maestro sí puede y queda registrado, pero no entra a lo de otro comercio.
 *  - Un lote que mezcla ids propios, ajenos y fuera de estado cuenta `procesados` y `omitidos`.
 *  - Toda ruta de estos controladores contesta 401 sin sesión.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Doble_pedido_y_tenencia_de_la_eleccion_Test extends CategoryProposalsTestCase
{
    use AyudasDeLaEleccion;

    /**
     * Un sistema chico para un comercio: Bisagras / Comunes con a1 (segura), a2 dudosa en el mismo nodo, a3
     * dudosa en una categoría que no se crea al elegir (Correderas) y a4 sin ubicar. La corrida queda `lista`.
     *
     * @param  \App\Models\User|null $dueno
     * @return array  ['articulos', 'run', 'proposal', 'items', 'nodos']
     */
    protected function sistema_listo($dueno = null)
    {
        $articulos = $this->crear_articulos(['a1', 'a2', 'a3', 'a4'], $dueno);

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Bisagras' => ['Comunes'], 'Correderas' => []],
                    'items' => [
                        [$articulos[0], 'Bisagras', 'Comunes', 'segura'],
                        [$articulos[1], 'Bisagras', 'Comunes', 'dudosa', 'no queda claro'],
                        [$articulos[2], 'Correderas', null, 'dudosa', 'no queda claro'],
                        [$articulos[3], null, null, 'ninguna'],
                    ],
                ],
            ],
        ], $dueno);

        return [
            'articulos' => $articulos,
            'run'       => $sembrado['run'],
            'proposal'  => $sembrado['propuestas']['A']['proposal'],
            'items'     => $sembrado['propuestas']['A']['items'],
            'nodos'     => $sembrado['propuestas']['A']['nodos'],
        ];
    }

    /**
     * Lo que importa de la base de un comercio, para comparar antes y después de un pedido repetido:
     * categorías y subcategorías vivas, cada artículo con su categoría y su `updated_at`, y cada ítem con su
     * estado, sus valores previos y quién lo revisó.
     *
     * @param  array $s       El sistema.
     * @param  \App\Models\User|null $dueno
     * @return array
     */
    protected function foto($s, $dueno = null)
    {
        $dueno = is_null($dueno) ? $this->owner : $dueno;

        $articulos = [];

        foreach ($s['articulos'] as $articulo) {
            $articulos[$articulo->id] = array_merge($this->categorias_de($articulo), ['updated_at' => $this->actualizado_el($articulo)]);
        }

        $items = [];

        foreach ($s['items'] as $item) {
            $fila = CategoryProposalItem::find($item->id);
            $items[$item->id] = [$fila->estado, $fila->prev_category_id, $fila->prev_sub_category_id, $fila->revisado_por, (string) $fila->revisado_at];
        }

        return [
            'categorias'    => Category::where('user_id', $dueno->id)->orderBy('id')->pluck('name', 'id')->all(),
            'subcategorias' => SubCategory::where('user_id', $dueno->id)->orderBy('id')->pluck('name', 'id')->all(),
            'papelera'      => Category::onlyTrashed()->where('user_id', $dueno->id)->count(),
            'articulos'     => $articulos,
            'items'         => $items,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // El segundo clic
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Dos `elegir` seguidos aplican UNA sola vez: el segundo responde 200 con `ya_estaba`, el mismo
     * resumen, y no crea nada, no reescribe ningún artículo ni mueve la fecha de la elección. Aunque el
     * segundo pedido venga con otro `eliminar_categorias_vacias`.
     *
     * @test
     * @group categorias_ia
     */
    public function dos_elegir_seguidos_aplican_una_sola_vez()
    {
        $vieja = $this->categoria_real('Vieja');
        $s = $this->sistema_listo();
        $this->envejecer($s['articulos']);

        $primero = $this->pedir_elegir($s['run'], $s['proposal'], false);

        $primero->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertNull($primero->json('ya_estaba'));
        $this->assertSame(1, $primero->json('resultado.categorias_creadas'));
        $this->assertSame(1, $primero->json('resultado.subcategorias_creadas'));

        $foto = $this->foto($s);
        $elegida_at = CategoryProposalRun::find($s['run']->id)->elegida_at;

        // Un instante después: si el segundo pedido reaplicara, la fecha y los updated_at se moverían.
        sleep(1);

        $segundo = $this->pedir_elegir($s['run'], $s['proposal'], true);

        $segundo->assertStatus(200)->assertJsonPath('ya_estaba', true)->assertJsonPath('ok', true);
        $this->assertSame($primero->json('resultado'), $segundo->json('resultado'), 'El mismo resumen.');

        $this->assertSame($foto, $this->foto($s), 'No cambió ni una fila: una sola categoría creada, ningún artículo reescrito.');
        $this->assertSame(1, $this->categorias_llamadas('Bisagras')->count());
        $this->assertSame(0, $this->categorias_llamadas('Correderas')->count(), 'Correderas sigue sin crearse (solo tiene un dudoso).');
        $this->assertNotNull(Category::find($vieja->id), 'Y el segundo pedido, con eliminar=true, no eliminó nada.');

        $run = CategoryProposalRun::find($s['run']->id);
        $this->assertEquals($elegida_at, $run->elegida_at, 'La fecha de la elección no se movió.');
        $this->assertFalse($run->eliminar_categorias_vacias, 'Tampoco cambió lo que se pidió la primera vez.');
    }

    /**
     * Elegir OTRA propuesta cuando ya hay una elegida es 409 `ya_hay_una_elegida` (para cambiar hay que
     * volver atrás) y no cambia nada.
     *
     * @test
     * @group categorias_ia
     */
    public function elegir_otra_propuesta_despues_de_elegir_da_409()
    {
        $s = $this->sistema_listo();

        // Una segunda propuesta en la misma corrida.
        $otra = \App\Models\CategoryProposal::create([
            'run_id'  => $s['run']->id,
            'user_id' => $this->owner->id,
            'clave'   => 'B',
            'tipo'    => 'nueva',
            'nombre'  => 'Otra',
            'orden'   => 2,
        ]);

        $this->pedir_elegir($s['run'], $s['proposal'])->assertStatus(200);
        $foto = $this->foto($s);

        $this->pedir_elegir($s['run'], $otra)
            ->assertStatus(409)
            ->assertJsonPath('error', 'ya_hay_una_elegida');

        $this->assertSame($foto, $this->foto($s));
        $this->assertSame($s['proposal']->id, (int) CategoryProposalRun::find($s['run']->id)->propuesta_elegida_id);
    }

    /**
     * 🔴 Dos `aprobar` seguidos del mismo ítem aplican una sola vez: el segundo responde 200 con
     * `ya_estaba`, no crea otra categoría y no reescribe nada (ni el artículo ni quién revisó).
     *
     * @test
     * @group categorias_ia
     */
    public function dos_aprobar_seguidos_aplican_una_sola_vez()
    {
        $s = $this->sistema_listo();
        $this->pedir_elegir($s['run'], $s['proposal'])->assertStatus(200);

        // El dudoso de Correderas: aprobarlo CREA la categoría.
        $this->envejecer([$s['articulos'][2]]);

        $primero = $this->pedir_aprobar($s['items'][2]);
        $primero->assertStatus(200);
        $this->assertNull($primero->json('ya_estaba'));
        $this->assertSame(1, $this->categorias_llamadas('Correderas')->count());

        $foto = $this->foto($s);
        sleep(1);

        $segundo = $this->pedir_aprobar($s['items'][2]);

        $segundo->assertStatus(200)->assertJsonPath('ya_estaba', true)->assertJsonPath('item.estado', 'aprobada');
        $this->assertSame($primero->json('item'), $segundo->json('item'));
        $this->assertSame($foto, $this->foto($s), 'Ni otra categoría ni una escritura más.');
        $this->assertSame(1, $this->categorias_llamadas('Correderas')->count());
    }

    /**
     * Dos `rechazar` seguidos: el segundo es 200 `ya_estaba` y no reescribe `revisado_at`.
     *
     * @test
     * @group categorias_ia
     */
    public function dos_rechazar_seguidos_aplican_una_sola_vez()
    {
        $s = $this->sistema_listo();
        $this->pedir_elegir($s['run'], $s['proposal'])->assertStatus(200);

        $this->pedir_rechazar($s['items'][1])->assertStatus(200);
        $foto = $this->foto($s);
        sleep(1);

        $this->pedir_rechazar($s['items'][1])
            ->assertStatus(200)
            ->assertJsonPath('ya_estaba', true)
            ->assertJsonPath('item.estado', 'rechazada');

        $this->assertSame($foto, $this->foto($s));
    }

    /**
     * 🔴 Dos `volver atrás` seguidos: el segundo es 200 `ya_estaba` y NO restaura de nuevo. Se prueba con lo
     * que importa del doble clic: entre los dos pedidos el dueño categoriza un artículo a mano, y el segundo
     * no se lo pisa con el valor anterior.
     *
     * @test
     * @group categorias_ia
     */
    public function dos_volver_atras_seguidos_deshacen_una_sola_vez()
    {
        $s = $this->sistema_listo();
        $this->pedir_elegir($s['run'], $s['proposal'])->assertStatus(200);

        $primero = $this->pedir_volver_atras($s['run']);

        $primero->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertNull($primero->json('ya_estaba'));
        $this->assertSame('lista', CategoryProposalRun::find($s['run']->id)->estado);

        // El dueño categoriza a mano un artículo.
        $propia = $this->categoria_real('Propia a mano');
        DB::table('articles')->where('id', $s['articulos'][0]->id)->update(['category_id' => $propia->id]);
        $foto = $this->foto($s);

        $this->pedir_volver_atras($s['run'])
            ->assertStatus(200)
            ->assertJsonPath('ya_estaba', true)
            ->assertJsonPath('run.estado', 'lista');

        $this->assertSame($foto, $this->foto($s), 'El segundo no restauró nada.');
        $this->assertSame($propia->id, $this->categorias_de($s['articulos'][0])['category_id'], 'Lo categorizado a mano se respeta.');
    }

    // ---------------------------------------------------------------------------------------------
    // El empleado
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Un empleado no puede nada: ni elegir, ni volver atrás, ni aprobar, ni rechazar, ni en lote. Ni
     * siquiera con `admin_access`, que en el resto del sistema deja pasar: cambiar el catálogo entero es del
     * dueño o del acceso maestro. 403 `solo_el_dueno` y no se toca nada.
     *
     * @test
     * @group categorias_ia
     */
    public function un_empleado_no_puede_elegir_volver_atras_aprobar_ni_rechazar()
    {
        $s = $this->sistema_listo();

        $empleados = [
            'sin admin_access' => $this->crear_empleado_de($this->owner, false),
            'con admin_access' => $this->crear_empleado_de($this->owner, true),
        ];

        // Elegir, con la corrida lista.
        foreach ($empleados as $etiqueta => $empleado) {
            $this->actuar_como($empleado);

            $respuesta = $this->pedir_elegir($s['run'], $s['proposal'], true);

            $respuesta->assertStatus(403);
            $this->assertSame('solo_el_dueno', $respuesta->json('error'), $etiqueta);
            $this->assertNotEmpty($respuesta->json('message'), $etiqueta);
            $this->assertSame('lista', CategoryProposalRun::find($s['run']->id)->estado, 'El empleado '.$etiqueta.' no eligió nada.');
        }

        // El dueño elige, y los empleados intentan lo demás.
        $this->actuar_como($this->owner);
        $this->pedir_elegir($s['run'], $s['proposal'])->assertStatus(200);

        $foto = $this->foto($s);

        foreach ($empleados as $etiqueta => $empleado) {
            $this->actuar_como($empleado);

            $pedidos = [
                'volver-atras'    => $this->pedir_volver_atras($s['run']),
                'aprobar'         => $this->pedir_aprobar($s['items'][1]),
                'rechazar'        => $this->pedir_rechazar($s['items'][1]),
                'aprobar-varios'  => $this->pedir_en_lote('aprobar', [$s['items'][1]->id]),
                'rechazar-varios' => $this->pedir_en_lote('rechazar', [$s['items'][1]->id]),
            ];

            foreach ($pedidos as $ruta => $respuesta) {
                $respuesta->assertStatus(403);
                $this->assertSame('solo_el_dueno', $respuesta->json('error'), $ruta.' '.$etiqueta);
            }

            $this->assertSame($foto, $this->foto($s), 'El empleado '.$etiqueta.' no tocó nada.');
            $this->assertSame('elegida', CategoryProposalRun::find($s['run']->id)->estado);
            $this->assertNull(CategoryProposalRun::find($s['run']->id)->revision_iniciada_at);
        }
    }

    /**
     * El 403 del empleado le gana a un cuerpo mal armado y a un id que no existe: nunca delata nada.
     *
     * @test
     * @group categorias_ia
     */
    public function el_403_del_empleado_va_antes_que_la_validacion_y_la_busqueda()
    {
        $empleado = $this->crear_empleado_de($this->owner);
        $this->actuar_como($empleado);

        $this->postJson('api/category-proposal-runs/999999999/elegir', [])->assertStatus(403);
        $this->postJson('api/category-proposal-runs/999999999/volver-atras')->assertStatus(403);
        $this->postJson('api/category-proposal-items/999999999/aprobar')->assertStatus(403);
        $this->postJson('api/category-proposal-items/aprobar-varios', ['ids' => 'mal'])->assertStatus(403);
    }

    // ---------------------------------------------------------------------------------------------
    // Ids de otro comercio
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Un id de OTRO comercio que EXISTE responde 404 en cada ruta, con el MISMO cuerpo que un id que no
     * existe (un inexistente no distingue un lector por existencia de uno por dueño). Y no cambia nada de lo
     * del vecino. En un lote, lo ajeno se omite y se cuenta como cualquier otro id.
     *
     * @test
     * @group categorias_ia
     */
    public function un_id_ajeno_que_existe_da_404_igual_que_uno_inexistente_en_cada_ruta()
    {
        // El vecino elige su sistema: queda con ítems a revisar y una corrida elegida.
        $ajeno = $this->sistema_listo($this->vecino);
        $this->actuar_como($this->vecino);
        $this->pedir_elegir($ajeno['run'], $ajeno['proposal'])->assertStatus(200);

        // Y el dueño del test tiene su propio sistema, todavía sin elegir.
        $this->actuar_como($this->owner);
        $propio = $this->sistema_listo();
        $foto_del_vecino = $this->foto($ajeno, $this->vecino);

        $id_ajeno_de_item   = $ajeno['items'][1]->id;
        $id_inexistente     = 999999999;

        $pares = [
            'elegir'          => [
                $this->postJson('api/category-proposal-runs/'.$ajeno['run']->id.'/elegir', ['propuesta_id' => $ajeno['proposal']->id]),
                $this->postJson('api/category-proposal-runs/'.$id_inexistente.'/elegir', ['propuesta_id' => $ajeno['proposal']->id]),
            ],
            'volver-atras'    => [
                $this->postJson('api/category-proposal-runs/'.$ajeno['run']->id.'/volver-atras'),
                $this->postJson('api/category-proposal-runs/'.$id_inexistente.'/volver-atras'),
            ],
            'aprobar'         => [
                $this->postJson('api/category-proposal-items/'.$id_ajeno_de_item.'/aprobar'),
                $this->postJson('api/category-proposal-items/'.$id_inexistente.'/aprobar'),
            ],
            'rechazar'        => [
                $this->postJson('api/category-proposal-items/'.$id_ajeno_de_item.'/rechazar'),
                $this->postJson('api/category-proposal-items/'.$id_inexistente.'/rechazar'),
            ],
        ];

        foreach ($pares as $ruta => $par) {
            $par[0]->assertStatus(404);
            $this->assertSame('no_encontrado', $par[0]->json('error'), $ruta);
            $this->assertSame($par[1]->getStatusCode(), $par[0]->getStatusCode(), $ruta.': mismo estado que un inexistente.');
            $this->assertSame($par[1]->json('error'), $par[0]->json('error'), $ruta.': mismo código que un inexistente.');
        }

        // Una propuesta AJENA con una corrida propia: tampoco.
        $this->postJson('api/category-proposal-runs/'.$propio['run']->id.'/elegir', ['propuesta_id' => $ajeno['proposal']->id])
            ->assertStatus(404)
            ->assertJsonPath('error', 'no_encontrado');
        $this->assertSame('lista', CategoryProposalRun::find($propio['run']->id)->estado);

        // En lote: lo ajeno se omite igual que lo inexistente.
        $ajeno_en_lote = $this->pedir_en_lote('aprobar', [$id_ajeno_de_item]);
        $inexistente_en_lote = $this->pedir_en_lote('aprobar', [$id_inexistente]);
        $this->assertSame(0, $ajeno_en_lote->json('procesados'));
        $this->assertSame(1, $ajeno_en_lote->json('omitidos'));
        $this->assertSame($inexistente_en_lote->json('procesados'), $ajeno_en_lote->json('procesados'));
        $this->assertSame($inexistente_en_lote->json('omitidos'), $ajeno_en_lote->json('omitidos'));

        $rechazo = $this->pedir_en_lote('rechazar', [$id_ajeno_de_item]);
        $this->assertSame(0, $rechazo->json('procesados'));

        // Nada de lo del vecino se movió.
        $this->assertSame($foto_del_vecino, $this->foto($ajeno, $this->vecino));
        $this->assertSame('elegida', CategoryProposalRun::find($ajeno['run']->id)->estado);
        $this->assertSame('a_revisar', CategoryProposalItem::find($id_ajeno_de_item)->estado);
    }

    /**
     * El acceso maestro de OTRO comercio tampoco entra a lo del dueño: 404 igual (el maestro autentica como
     * un usuario real, y todo se acota por el dueño de esa sesión).
     *
     * @test
     * @group categorias_ia
     */
    public function el_acceso_maestro_no_entra_a_lo_de_otro_comercio()
    {
        $s = $this->sistema_listo();

        $this->actuar_como($this->vecino, true);

        $this->pedir_elegir($s['run'], $s['proposal'])->assertStatus(404);
        $this->pedir_volver_atras($s['run'])->assertStatus(404);
        $this->pedir_aprobar($s['items'][1])->assertStatus(404);
        $this->pedir_rechazar($s['items'][1])->assertStatus(404);

        $this->assertSame('lista', CategoryProposalRun::find($s['run']->id)->estado);
    }

    // ---------------------------------------------------------------------------------------------
    // Acceso maestro
    // ---------------------------------------------------------------------------------------------

    /**
     * El acceso maestro puede elegir y queda registrado (`elegida_con_acceso_maestro`). También cuando la
     * sesión maestra es de un EMPLEADO del comercio: sigue actuando sobre el dueño de esa sesión y deja
     * registrado a la persona que eligió.
     *
     * @test
     * @group categorias_ia
     */
    public function el_acceso_maestro_puede_elegir_y_queda_registrado()
    {
        // Con la sesión maestra del dueño.
        $s = $this->sistema_listo();
        $this->actuar_como($this->owner, true);

        $this->pedir_elegir($s['run'], $s['proposal'])->assertStatus(200);

        $run = CategoryProposalRun::find($s['run']->id);
        $this->assertTrue($run->elegida_con_acceso_maestro);
        $this->assertSame($this->owner->id, (int) $run->elegida_por);

        // Con la sesión maestra de un empleado del comercio (que sin ella no podría).
        $t = $this->sistema_listo($this->vecino);
        $empleado = $this->crear_empleado_de($this->vecino);

        $this->flushSession();
        $this->actuar_como($empleado);
        $this->pedir_elegir($t['run'], $t['proposal'])->assertStatus(403);

        $this->actuar_como($empleado, true);
        $this->pedir_elegir($t['run'], $t['proposal'])->assertStatus(200);

        $run = CategoryProposalRun::find($t['run']->id);
        $this->assertTrue($run->elegida_con_acceso_maestro);
        $this->assertSame($empleado->id, (int) $run->elegida_por, 'Queda registrada la persona de la sesión.');
        $this->assertSame(1, Category::where('user_id', $this->vecino->id)->where('name', 'Bisagras')->count(), 'Las categorías son del dueño, no del empleado.');
        $this->assertSame(0, Category::where('user_id', $empleado->id)->count());
    }

    /**
     * Sin sesión maestra, la elección del dueño queda marcada como no maestra.
     *
     * @test
     * @group categorias_ia
     */
    public function sin_sesion_maestra_la_eleccion_no_queda_marcada_como_maestra()
    {
        $s = $this->sistema_listo();

        $this->pedir_elegir($s['run'], $s['proposal'])->assertStatus(200);

        $this->assertFalse(CategoryProposalRun::find($s['run']->id)->elegida_con_acceso_maestro);
    }

    // ---------------------------------------------------------------------------------------------
    // Lote con ids mezclados
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Un lote que mezcla ids propios a revisar, uno propio ya asignado (fuera de estado), uno ajeno y
     * uno inexistente: procesa solo los propios a revisar, y `procesados` + `omitidos` suma lo que se pidió.
     *
     * @test
     * @group categorias_ia
     */
    public function un_lote_mezcla_ids_propios_ajenos_y_fuera_de_estado()
    {
        $propio = $this->sistema_listo();
        $this->pedir_elegir($propio['run'], $propio['proposal'])->assertStatus(200);

        $ajeno = $this->sistema_listo($this->vecino);
        $this->actuar_como($this->vecino);
        $this->pedir_elegir($ajeno['run'], $ajeno['proposal'])->assertStatus(200);
        $this->actuar_como($this->owner);

        $ids = [
            $propio['items'][1]->id,   // a revisar: se procesa
            $propio['items'][2]->id,   // a revisar: se procesa
            $propio['items'][0]->id,   // ya aplicado: fuera de estado
            $propio['items'][3]->id,   // sin asignar: fuera de estado
            $ajeno['items'][1]->id,    // de otro comercio, a revisar
            999999999,                 // no existe
        ];

        $respuesta = $this->pedir_en_lote('aprobar', $ids);

        $respuesta->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertSame(2, $respuesta->json('procesados'));
        $this->assertSame(4, $respuesta->json('omitidos'));
        $this->assertSame(count($ids), $respuesta->json('procesados') + $respuesta->json('omitidos'));
        $this->assertEqualsCanonicalizing(['a_revisar', 'asignados', 'sin_categoria'], array_keys($respuesta->json('conteos')));

        $this->assertSame('aprobada', CategoryProposalItem::find($propio['items'][1]->id)->estado);
        $this->assertSame('aprobada', CategoryProposalItem::find($propio['items'][2]->id)->estado);
        $this->assertSame('aplicada', CategoryProposalItem::find($propio['items'][0]->id)->estado);
        $this->assertSame('sin_asignar', CategoryProposalItem::find($propio['items'][3]->id)->estado);
        $this->assertSame('a_revisar', CategoryProposalItem::find($ajeno['items'][1]->id)->estado, 'El del vecino no se tocó.');
        $this->assertSame($this->vecino->id, (int) CategoryProposalItem::find($ajeno['items'][1]->id)->user_id);
    }

    // ---------------------------------------------------------------------------------------------
    // Sin sesión
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Toda ruta de estos controladores contesta 401 sin sesión: se MIDE con un pedido real (no mirando
     * el middleware, que un `withoutMiddleware` desarmaría sin que nadie se entere) y se exige que estén las
     * seis rutas esperadas, para que una nueva no quede sin probar.
     *
     * @test
     * @group categorias_ia
     */
    public function toda_ruta_de_elegir_y_revisar_contesta_401_sin_sesion()
    {
        $esperadas = [
            'POST api/category-proposal-runs/{id}/elegir',
            'POST api/category-proposal-runs/{id}/volver-atras',
            'POST api/category-proposal-items/aprobar-varios',
            'POST api/category-proposal-items/rechazar-varios',
            'POST api/category-proposal-items/{id}/aprobar',
            'POST api/category-proposal-items/{id}/rechazar',
        ];

        $en_el_router = [];

        foreach (Route::getRoutes() as $ruta) {
            if (!preg_match('/(^|\\\\)(CategoryProposalEleccionController|CategoryProposalItemController)@\w+$/', $ruta->getActionName())) {
                continue;
            }

            $metodos = array_values(array_diff($ruta->methods(), ['HEAD']));
            $en_el_router[implode('|', $metodos).' '.$ruta->uri()] = $ruta;
        }

        $this->assertEqualsCanonicalizing($esperadas, array_keys($en_el_router), 'Las rutas de estos controladores cambiaron: revisá la matriz de este test.');

        // Sin sesión: se olvidan los guards (Sanctum cachea al dueño del setUp) y no se loguea a nadie.
        Auth::forgetGuards();

        foreach ($en_el_router as $clave => $ruta) {
            $uri = preg_replace('/\{[^}]+\}/', '1', $ruta->uri());

            $respuesta = $this->json('POST', $uri, ['propuesta_id' => 1, 'ids' => [1]]);

            $this->assertSame(401, $respuesta->getStatusCode(), $clave.' sin sesión tenía que contestar 401.');
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Dos procesos reales: la skill vuelve a correr mientras el dueño elige
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 `crear` con `reemplazar` (la skill volviendo a correr) y `elegir` (el dueño tocando "Elegir este") a
     * la vez, en DOS PROCESOS REALES: no hay deadlock, y una corrida ya elegida no se descarta.
     *
     * La falla que protege (B-06 del verificador): `crear` tomaba la fila de `users` y después las corridas,
     * mientras `elegir` tomaba la corrida y recién después `users` (por el `num` de la primera categoría que
     * crea). Cada uno se quedaba con el candado que el otro esperaba: MySQL mataba a uno con el error 1213 (el
     * dueño veía "Server Error") y, sin el deadlock, `crear` descartaba una corrida que el dueño acababa de
     * elegir. Ahora todo el flujo toma los candados en el mismo orden (users del dueño, después la corrida) y
     * `crear` relee el estado ya bloqueado.
     *
     * Los dos casos, con los procesos esperándose de verdad (uno se queda con su candado puesto unos
     * segundos mientras el otro entra):
     *  1. `elegir` llega primero: termina 200, y `crear` responde 409 `ya_hay_una_elegida` sin tocar nada.
     *  2. `crear` llega primero: descarta la corrida lista y crea la nueva; `elegir` responde 409 `no_esta_lista`
     *     y no escribe nada.
     *
     * Este test COMMITEA sus datos (los hijos solo ven lo commiteado) y los borra al final.
     *
     * @test
     * @group categorias_ia
     * @group categorias_ia_procesos
     */
    public function crear_reemplazando_y_elegir_a_la_vez_no_dan_deadlock_ni_descartan_una_corrida_elegida()
    {
        // Los dos comercios del test: lo commiteado hay que borrarlo a mano.
        $a_limpiar = [(int) $this->owner->id, (int) $this->vecino->id];

        try {
            // Caso 1: ELEGIR llega primero. El comercio del test.
            $uno = $this->sistema_listo();

            // Lo sembrado y los dos comercios quedan commiteados: los procesos hijos solo ven eso.
            $this->salir_de_la_transaccion_del_test();

            $elegir = $this->iniciar_hijo('elegir', ['user' => $this->owner->id, 'run' => $uno['run']->id, 'propuesta' => $uno['proposal']->id, 'demora' => 4]);

            // `crear` se lanza recién cuando `elegir` avisa que ya tiene sus candados (no por un tiempo fijo).
            $this->assertTrue($this->esperar_que_el_hijo_tenga_el_candado($elegir), 'El proceso de elegir terminó sin tomar el candado.');

            $crear = $this->iniciar_hijo('crear', ['user' => $this->owner->id, 'demora' => 0]);

            $r_elegir = $this->terminar_hijo($elegir);
            $r_crear  = $this->terminar_hijo($crear);

            $this->assertArrayNotHasKey('sin_resultado', $r_elegir, 'El proceso de elegir no informó: '.json_encode($r_elegir));
            $this->assertArrayNotHasKey('sin_resultado', $r_crear, 'El proceso de crear no informó: '.json_encode($r_crear));
            $this->assertNull($r_elegir['excepcion'], 'Elegir no puede morir por un deadlock: '.json_encode($r_elegir));
            $this->assertNull($r_crear['excepcion'], 'Crear no puede morir por un deadlock: '.json_encode($r_crear));

            $this->assertSame(200, $r_elegir['status']);
            $this->assertSame(409, $r_crear['status'], 'Crear contra una corrida ya elegida: '.json_encode($r_crear));
            $this->assertSame('ya_hay_una_elegida', $r_crear['error']);

            $corrida = CategoryProposalRun::find($uno['run']->id);
            $this->assertSame('elegida', $corrida->estado, 'La corrida elegida no se descarta.');
            $this->assertSame($uno['proposal']->id, (int) $corrida->propuesta_elegida_id);
            $this->assertSame(1, CategoryProposalRun::where('user_id', $this->owner->id)->count(), 'Crear no dejó una corrida nueva.');
            $this->assertSame(1, $this->categorias_llamadas('Bisagras')->count(), 'Lo que creó elegir sigue en pie.');

            // Caso 2: CREAR llega primero, ahora en el comercio vecino.
            $dos = $this->sistema_listo($this->vecino);

            $crear = $this->iniciar_hijo('crear', ['user' => $this->vecino->id, 'demora' => 4]);

            // `elegir` se lanza recién cuando `crear` avisa que ya tiene el candado de `users` (no por un tiempo fijo).
            $this->assertTrue($this->esperar_que_el_hijo_tenga_el_candado($crear), 'El proceso de crear terminó sin tomar el candado.');

            $elegir = $this->iniciar_hijo('elegir', ['user' => $this->vecino->id, 'run' => $dos['run']->id, 'propuesta' => $dos['proposal']->id, 'demora' => 0]);

            $r_crear  = $this->terminar_hijo($crear);
            $r_elegir = $this->terminar_hijo($elegir);

            $this->assertArrayNotHasKey('sin_resultado', $r_crear, 'El proceso de crear no informó: '.json_encode($r_crear));
            $this->assertArrayNotHasKey('sin_resultado', $r_elegir, 'El proceso de elegir no informó: '.json_encode($r_elegir));
            $this->assertNull($r_crear['excepcion'], 'Crear no puede morir por un deadlock: '.json_encode($r_crear));
            $this->assertNull($r_elegir['excepcion'], 'Elegir no puede morir por un deadlock: '.json_encode($r_elegir));

            $this->assertSame(201, $r_crear['status'], 'Crear con reemplazar sobre una corrida lista: '.json_encode($r_crear));
            $this->assertSame(409, $r_elegir['status'], 'Elegir una corrida que acaban de reemplazar: '.json_encode($r_elegir));
            $this->assertSame('no_esta_lista', $r_elegir['error']);

            $this->assertSame('descartada', CategoryProposalRun::find($dos['run']->id)->estado, 'La que reemplazó crear.');
            $this->assertSame(1, CategoryProposalRun::where('user_id', $this->vecino->id)->where('estado', 'preparando')->count(), 'La corrida nueva de crear.');
            $this->assertSame(0, Category::where('user_id', $this->vecino->id)->count(), 'Elegir no creó nada en la corrida reemplazada.');
            $this->assertSame(['category_id' => null, 'sub_category_id' => null], $this->categorias_de($dos['articulos'][0]));
        } finally {
            foreach ($a_limpiar as $user_id) {
                $this->limpiar_comercio_commiteado($user_id);
            }
        }
    }
}
