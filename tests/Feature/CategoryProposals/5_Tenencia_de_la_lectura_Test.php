<?php

namespace Tests\Feature\CategoryProposals;

use App\Models\CategoryProposalItem;
use App\Models\CategoryProposalRun;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * La tenencia de la lectura de las propuestas de categorías para la SPA (misión
 * categorizacion-tres-modelos, 5/10/2026). Contrato B del plan, §6 y §4.7. Clase de error del 3/10/2026:
 * "un id del pedido se resuelve sin cruzarlo con el dueño" (en una base de 51 comercios, el ajeno está a
 * un id de distancia).
 *
 *   - Sin sesión, las cuatro rutas son 401.
 *   - Cada dueño ve SOLO lo suyo: con valores exactos, midiendo con un vecino que tiene datos propios.
 *   - Un id ajeno QUE EXISTE se contesta EXACTAMENTE igual que uno inexistente (404 `no_encontrado`, el
 *     mismo cuerpo): un inexistente no distingue un lector que mira por existencia de uno que mira por
 *     dueño, por eso el test usa el id de una corrida que sí existe.
 *   - Un empleado, con o sin `admin_access`, no ve ni toca nada: `resumen` y `actual` contestan 200 con la
 *     forma vacía y `visto` e `items` contestan 403. El acceso maestro sí puede.
 *   - La lectura no hace consultas de más: cuestan lo mismo con 30 que con 300 ítems.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Tenencia_de_la_lectura_Test extends CategoryProposalsTestCase
{
    use ArmaCorridasDeCategorias;

    /** El cuerpo del 404 de un id que no existe o no es mío. */
    const ID_INEXISTENTE = 987654321;

    /**
     * Las cuatro rutas de lectura de la SPA con el método (con un id de relleno donde va `{id}`).
     *
     * @return array  [método, url]
     */
    protected function rutas()
    {
        return [
            ['GET', 'api/category-proposal-runs/resumen'],
            ['GET', 'api/category-proposal-runs/actual'],
            ['PUT', 'api/category-proposal-runs/1/visto'],
            ['GET', 'api/category-proposal-runs/1/items'],
        ];
    }

    /**
     * El cuerpo de lo que contesta un empleado en `resumen`: nada, y `puede_gestionar` en falso.
     *
     * @return array
     */
    protected function resumen_de_quien_no_gestiona()
    {
        return [
            'hay_propuestas'       => false,
            'estado'               => null,
            'run_id'               => null,
            'pendientes_de_elegir' => false,
            'a_revisar'            => 0,
            'sin_ver'              => false,
            'badge'                => 0,
            'puede_gestionar'      => false,
        ];
    }

    /**
     * 🔴 Las rutas de lectura del router son EXACTAMENTE las de la matriz (una ruta nueva que nadie probó no
     * puede colarse) y todas piden sesión: sin ella, 401.
     *
     * @group categorias_ia
     * @test
     */
    public function las_rutas_de_lectura_piden_sesion_y_son_las_de_la_matriz()
    {
        $en_el_router = [];

        foreach (Route::getRoutes() as $route) {

            if (strpos($route->getActionName(), 'CategoryProposalRunController@') !== false) {

                $this->assertContains('auth:sanctum', $route->gatherMiddleware(), $route->uri().' perdió la autenticación.');

                foreach (array_diff($route->methods(), ['HEAD']) as $metodo) {

                    $en_el_router[] = $metodo.' '.preg_replace('/\{[^}]+\}/', '1', $route->uri());
                }
            }
        }

        $en_la_matriz = [];

        foreach ($this->rutas() as $ruta) {

            $en_la_matriz[] = $ruta[0].' '.$ruta[1];
        }

        sort($en_el_router);
        sort($en_la_matriz);

        $this->assertSame($en_la_matriz, $en_el_router, 'Las rutas del router no coinciden con la matriz de tenencia: declará la nueva y probala.');

        // Sin sesión: se olvidan los guards (el de sanctum cachea al usuario del setUp) y no se loguea a nadie.
        Auth::forgetGuards();

        foreach ($this->rutas() as $ruta) {

            $respuesta = $this->json($ruta[0], $ruta[1], [], ['Accept' => 'application/json']);

            $this->assertSame(401, $respuesta->getStatusCode(), $ruta[0].' '.$ruta[1].' sin sesión tenía que ser 401 y fue '.$respuesta->getStatusCode().': '.$respuesta->getContent());
        }

        $this->actuar_como($this->owner);
    }

    /**
     * 🔴 Cada dueño ve SOLO su corrida, con sus números exactos: el vecino tiene la suya (elegida, con
     * ítems a revisar) y no se mezcla nada, ni en `resumen`, ni en `actual`, ni en los nombres.
     *
     * @group categorias_ia
     * @test
     */
    public function cada_dueno_ve_solo_su_corrida_con_sus_numeros()
    {
        $mia    = $this->corrida_con_a('lista');
        $suya   = $this->corrida_elegida_para_revisar([], $this->vecino);

        // Como dueño del test: mi corrida lista, 1 de badge, sin ver.
        $this->assertSame([
            'hay_propuestas'       => true,
            'estado'               => 'lista',
            'run_id'               => (int) $mia['run']->id,
            'pendientes_de_elegir' => true,
            'a_revisar'            => 0,
            'sin_ver'              => true,
            'badge'                => 1,
            'puede_gestionar'      => true,
        ], $this->resumen());

        $actual = $this->actual();

        $this->assertSame((int) $mia['run']->id, $actual['run']['id']);
        $this->assertCount(1, $actual['propuestas']);
        $this->assertSame((int) $mia['propuestas']['A']['proposal']->id, $actual['propuestas'][0]['id']);
        $this->assertSame(6, $actual['propuestas'][0]['totales']['articulos']);
        $this->assertSame(['a_revisar' => 0, 'asignados' => 0, 'sin_categoria' => 0], $actual['conteos']);
        $this->assertStringNotContainsString('de '.$this->vecino->id, json_encode($actual), 'Se coló un artículo del vecino.');

        // Como el vecino: su corrida elegida, con sus 2 dudosos a revisar.
        $this->actuar_como($this->vecino);

        $this->assertSame([
            'hay_propuestas'       => true,
            'estado'               => 'elegida',
            'run_id'               => (int) $suya['run']->id,
            'pendientes_de_elegir' => false,
            'a_revisar'            => 2,
            'sin_ver'              => false,
            'badge'                => 2,
            'puede_gestionar'      => true,
        ], $this->resumen());

        $actual = $this->actual();

        $this->assertSame((int) $suya['run']->id, $actual['run']['id']);
        $this->assertSame(['a_revisar' => 2, 'asignados' => 3, 'sin_categoria' => 1], $actual['conteos']);
        $this->assertStringNotContainsString('de '.$this->owner->id, json_encode($actual), 'Se coló un artículo del dueño.');

        // Y sus ítems son solo los suyos.
        $items = $this->items($suya['run']->id, 'solapa=a_revisar')->assertStatus(200)->json();

        $this->assertSame(2, $items['models']['total']);
        $this->assertStringNotContainsString('de '.$this->owner->id, json_encode($items));

        // Cuando el dueño no tiene corrida y el vecino sí, el dueño no ve la del vecino.
        $this->actuar_como($this->owner);
        CategoryProposalRun::where('id', $mia['run']->id)->update(['estado' => 'descartada']);

        $this->assertFalse($this->resumen()['hay_propuestas']);
        $this->assertNull($this->actual()['run']);
    }

    /**
     * 🔴 Un id ajeno que EXISTE se contesta IGUAL que uno inexistente en las dos rutas con id: 404
     * `no_encontrado` con el mismo cuerpo, sin 403 que confirme que existe. Y la corrida ajena no se toca.
     * Una corrida descartada propia también es 404 (nunca se devuelve).
     *
     * @group categorias_ia
     * @test
     */
    public function un_id_ajeno_que_existe_es_404_igual_que_uno_inexistente()
    {
        $ajena = $this->corrida_elegida_para_revisar([], $this->vecino);
        $lista_ajena = $this->corrida_con_a('lista', $this->vecino);

        $descartada_propia = $this->corrida_con_a('descartada');

        $pedidos = function ($id) {

            return [
                $this->putJson('api/category-proposal-runs/'.$id.'/visto'),
                $this->getJson('api/category-proposal-runs/'.$id.'/items?solapa=a_revisar'),
                $this->getJson('api/category-proposal-runs/'.$id.'/items?solapa=sin_categoria&buscar=Articulo&per_page=100'),
            ];
        };

        $de_referencia = $pedidos(self::ID_INEXISTENTE);

        foreach ($de_referencia as $respuesta) {

            $respuesta->assertStatus(404);
        }

        $this->assertSame('no_encontrado', $de_referencia[0]->json()['error']);
        $this->assertSame(['error', 'message'], array_keys($de_referencia[0]->json()));

        foreach ([(int) $ajena['run']->id, (int) $lista_ajena['run']->id, (int) $descartada_propia['run']->id] as $id) {

            foreach ($pedidos($id) as $indice => $respuesta) {

                $this->assertSame(404, $respuesta->getStatusCode(), 'El id '.$id.' (pedido '.$indice.') tenía que ser 404: '.$respuesta->getContent());
                $this->assertSame($de_referencia[$indice]->json(), $respuesta->json(), 'El id '.$id.' contestó distinto que uno inexistente.');
            }
        }

        // La corrida del vecino no se tocó: ni vista ni cambiada.
        $this->assertNull(CategoryProposalRun::find($lista_ajena['run']->id)->visto_at);
        $this->assertSame('lista', CategoryProposalRun::find($lista_ajena['run']->id)->estado);
        $this->assertSame(
            6,
            CategoryProposalItem::where('user_id', $this->vecino->id)->where('proposal_id', $ajena['propuestas']['A']['proposal']->id)->count()
        );
    }

    /**
     * 🔴 Un empleado, CON o SIN `admin_access`, no ve ni toca las propuestas del dueño: `resumen` y `actual`
     * contestan 200 con la forma vacía (así la SPA carga sin ruido) y `visto` e `items` contestan 403
     * `solo_el_dueno`, aunque el dueño tenga una corrida. Un `admin_access` no alcanza: elegir y revisar
     * cambian el catálogo entero.
     *
     * @group categorias_ia
     * @test
     */
    public function un_empleado_con_o_sin_admin_access_no_ve_ni_toca_nada()
    {
        $corrida = $this->corrida_con_a('lista');
        $run     = $corrida['run'];

        foreach ([false, true] as $con_admin_access) {

            $empleado = $this->crear_empleado_de($this->owner, $con_admin_access);

            $this->actuar_como($empleado);

            $etiqueta = $con_admin_access ? 'con admin_access' : 'sin admin_access';

            // resumen y actual: 200 vacíos.
            $this->getJson('api/category-proposal-runs/resumen')->assertStatus(200)->assertExactJson($this->resumen_de_quien_no_gestiona());

            $actual = $this->actual();

            $this->assertNull($actual['run'], $etiqueta);
            $this->assertSame(['nuevo_modelo_bloqueado' => false, 'motivos' => []], $actual['bloqueo']);
            $this->assertSame([], $actual['advertencias']);
            $this->assertFalse($actual['tiene_categorias_previas']);
            $this->assertSame(['a_revisar' => 0, 'asignados' => 0, 'sin_categoria' => 0], $actual['conteos']);
            $this->assertSame([], $actual['propuestas']);
            $this->assertStringNotContainsString('Por rubro', json_encode($actual), $etiqueta);

            // visto e items: 403 solo_el_dueno, y nada cambia.
            $visto = $this->putJson('api/category-proposal-runs/'.$run->id.'/visto');

            $visto->assertStatus(403);
            $this->assertSame('solo_el_dueno', $visto->json()['error'], $etiqueta);
            $this->assertNotEmpty($visto->json()['message']);
            $this->assertNull($run->fresh()->visto_at, $etiqueta.': un empleado marcó la corrida como vista.');

            $items = $this->items($run->id, 'solapa=a_revisar');

            $items->assertStatus(403);
            $this->assertSame('solo_el_dueno', $items->json()['error'], $etiqueta);

            // Ni el id de una corrida que no existe cambia el 403: no se distingue.
            $this->assertSame(403, $this->items(self::ID_INEXISTENTE)->getStatusCode());
        }
    }

    /**
     * 🔴 El acceso maestro SÍ puede, aunque la sesión sea de un empleado: `puede_gestionar` en verdadero, ve la
     * corrida del comercio y puede marcarla vista.
     *
     * @group categorias_ia
     * @test
     */
    public function el_acceso_maestro_puede_aunque_la_sesion_sea_de_un_empleado()
    {
        $corrida  = $this->corrida_con_a('lista');
        $run      = $corrida['run'];
        $empleado = $this->crear_empleado_de($this->owner);

        $this->actuar_como($empleado, true);

        $resumen = $this->resumen();

        $this->assertTrue($resumen['puede_gestionar']);
        $this->assertSame((int) $run->id, $resumen['run_id']);
        $this->assertSame(1, $resumen['badge']);

        $actual = $this->actual();

        $this->assertSame((int) $run->id, $actual['run']['id']);
        $this->assertTrue($actual['run']['puede_gestionar']);
        $this->assertCount(1, $actual['propuestas']);

        $this->putJson('api/category-proposal-runs/'.$run->id.'/visto')->assertStatus(200)->assertExactJson(['ok' => true]);
        $this->assertNotNull($run->fresh()->visto_at);
    }

    /**
     * 🔴 Cantidad de consultas CONSTANTE: `resumen`, `actual` y `items` cuestan lo mismo con 30 que con 300
     * ítems (nada de una consulta por ítem ni por nodo), y ninguna lee `articles` entero ni la columna
     * `embedding`.
     *
     * @group categorias_ia
     * @test
     */
    public function la_lectura_cuesta_las_mismas_consultas_con_30_que_con_300_items()
    {
        $con_30  = $this->medir_lectura(30, 'Chico');
        $con_300 = $this->medir_lectura(300, 'Grande');

        $this->assertSame($con_30['resumen']['cantidad'], $con_300['resumen']['cantidad'], 'resumen: la cantidad de consultas depende de la cantidad de ítems.');
        $this->assertSame($con_30['actual']['cantidad'], $con_300['actual']['cantidad'], 'actual: la cantidad de consultas depende de la cantidad de ítems.');
        $this->assertSame($con_30['items']['cantidad'], $con_300['items']['cantidad'], 'items: la cantidad de consultas depende de la cantidad de ítems.');

        foreach ([$con_300['resumen'], $con_300['actual'], $con_300['items']] as $medicion) {

            foreach ($medicion['consultas'] as $consulta) {

                $this->assertStringNotContainsString('embedding', $consulta, 'Una consulta de la lectura leyó la columna embedding: '.$consulta);
                $this->assertSame(0, preg_match('/select \* from `articles`/i', $consulta), 'Un select * sobre articles: '.$consulta);
            }
        }

        // Y la página de ítems tiene límite: nunca trae los 300.
        $this->assertStringContainsString('limit', strtolower(implode(' ', $con_300['items']['consultas'])));
    }

    /**
     * Siembra una corrida de `$cantidad` ítems y mide cuántas consultas cuestan las tres lecturas.
     *
     * - `actual` se mide con la corrida LISTA (no llama a la función de "volver atrás", que es de otro
     *   helper y tiene su propio costo).
     * - `resumen` e `items` se miden con la corrida ELEGIDA y todos los ítems a revisar.
     * La corrida queda descartada al terminar, así la siguiente medición arranca limpia.
     *
     * @param  int    $cantidad
     * @param  string $prefijo
     * @return array  ['resumen' => [...], 'actual' => [...], 'items' => [...]] de `medir_consultas`.
     */
    protected function medir_lectura($cantidad, $prefijo)
    {
        $ids = $this->crear_articulos_en_masa($cantidad, null, $prefijo);

        // Los ítems reparten entre las dos categorías, con subcategoría, dudosos y ningunas.
        $variantes = [
            ['Bisagras', 'Comunes', 'segura'],
            ['Bisagras', 'De cierre suave', 'dudosa'],
            ['Correderas', null, 'segura'],
            ['Correderas', null, 'dudosa'],
            [null, null, 'ninguna'],
        ];

        $items = [];

        foreach ($ids as $i => $id) {

            $variante = $variantes[$i % count($variantes)];

            $items[] = [(object) ['id' => $id], $variante[0], $variante[1], $variante[2]];
        }

        $sembrado = $this->sembrar_corrida([
            'estado'     => 'lista',
            'propuestas' => ['A' => [
                'arbol' => ['Correderas' => [], 'Bisagras' => ['De cierre suave', 'Comunes']],
                'items' => $items,
            ]],
        ]);

        $run = $sembrado['run'];
        $a   = $sembrado['propuestas']['A']['proposal'];

        $actual = $this->medir_consultas(function () {
            $this->getJson('api/category-proposal-runs/actual')->assertStatus(200);
        });

        $run->estado               = 'elegida';
        $run->propuesta_elegida_id = $a->id;
        $run->save();

        CategoryProposalItem::where('proposal_id', $a->id)->update(['estado' => 'a_revisar']);

        $resumen = $this->medir_consultas(function () {
            $this->getJson('api/category-proposal-runs/resumen')->assertStatus(200);
        });

        $pagina = $this->medir_consultas(function () use ($run) {
            $this->getJson('api/category-proposal-runs/'.$run->id.'/items?solapa=a_revisar&per_page=25')->assertStatus(200);
        });

        $run->estado = 'descartada';
        $run->save();

        return ['resumen' => $resumen, 'actual' => $actual, 'items' => $pagina];
    }
}
