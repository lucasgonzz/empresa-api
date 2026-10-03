<?php

namespace Tests\Feature\Cheques;

use App\Models\Cheque;
use App\Models\CreditAccount;
use App\Models\Expense;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Misión cheques-solapa-endosados (2/10/2026) — lado API de la solapa "Endosado" del módulo de
 * cheques. Son dos cosas que la SPA nueva necesita de la API:
 *
 * 1. `GET cheque`: todo cheque RECIBIDO que salió de cartera (endosado a un proveedor o en un
 *    gasto) cae en `recibido.endosados` y en ningún otro estado, aunque además tenga una marca
 *    manual de cobrado/rechazado. Antes `index()` evaluaba la marca manual primero y ese cheque
 *    terminaba en Cobrados o Rechazados.
 * 2. `POST global-search/cheque` con el operador nuevo `in` de `extra_filters`
 *    (`{key: 'id', operator: 'in', value: [ids]}`): es como la SPA acota una búsqueda (filtro u
 *    orden de columna) a los cheques de la solapa donde está parado el usuario.
 *
 * Todo lo que escribe pasa por los endpoints reales: el cheque recibido nace de un cobro por
 * `POST api/current-acount/pago` y el endoso va por ese mismo endpoint (a proveedor) o por
 * `POST api/expense` (gasto), con el patrón de los tests 1 y 2 de esta carpeta. Lo único que se
 * inserta a mano son los estados que ningún endpoint deja (cobrado, rechazado, de otro dueño,
 * fechas de emisión elegidas a propósito), y se dice en cada caso.
 *
 * @group cheques
 */
class Solapa_endosados_Test extends ChequesTestCase
{
    /**
     * Endosa un cheque recibido a un proveedor por el endpoint real del pago de cuenta corriente
     * (la misma fila que arma la SPA al elegir "Endosar un cheque recibido") y devuelve la copia
     * emitida que nace del endoso.
     *
     * @param Cheque $recibido
     * @param Provider $proveedor
     * @param CreditAccount $cuenta_proveedor
     * @return Cheque La copia emitida colgada del pago.
     */
    protected function endosar_a_proveedor(Cheque $recibido, Provider $proveedor, CreditAccount $cuenta_proveedor)
    {
        // Marca de agua de los movimientos de caja, para limpiar los que pudiera dejar el pago.
        $movimientos_antes = $this->max_id_movimiento_caja();

        $fila = $this->fila_de_pago($this->claves_de_endoso($recibido));

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]));

        $response->assertStatus(201);

        $this->cobros_cc_creados_por_escenarios[] = (int) $response->json('current_acount.id');
        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $copia = $this->copias_de($recibido)->first();

        $this->assertNotNull($copia, 'El endoso a proveedor tenía que dejar la copia emitida.');

        return $copia;
    }

    /**
     * Endosa un cheque recibido en un gasto por el endpoint real (`POST api/expense`) y devuelve
     * el gasto.
     *
     * @param Cheque $recibido
     * @return Expense
     */
    protected function endosar_en_gasto(Cheque $recibido)
    {
        // Marca de agua de los movimientos de caja, para limpiar los que pudiera dejar el gasto.
        $movimientos_antes = $this->max_id_movimiento_caja();

        $fila = $this->fila_de_gasto($this->claves_de_endoso($recibido));

        $response = $this->postJson('api/expense', $this->payload_de_gasto([$fila]));

        $response->assertStatus(201);

        $gasto = Expense::find((int) $response->json('model.id'));

        $this->gastos_creados_por_escenarios[] = $gasto->id;
        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        return $gasto;
    }

    /**
     * TODAS las solapas de `GET cheque` en las que aparece un cheque ("recibido.endosados",
     * "emitido.pendientes"...). A diferencia de `solapa_de()` —que devuelve la primera—, esto
     * permite afirmar que el cheque está en UNA sola: "en Endosado y no en Recibido" es
     * `['recibido.endosados']` exacto.
     *
     * @param int $cheque_id
     * @return array<int, string>
     */
    protected function apariciones_en_el_listado($cheque_id)
    {
        $response = $this->getJson('api/cheque');

        $response->assertStatus(200);

        // Cada "tipo.solapa" donde se encontró el cheque (un cheque bien agrupado tiene una sola).
        $apariciones = [];

        foreach ($response->json('models') as $tipo => $solapas) {
            foreach ($solapas as $solapa => $cheques) {
                foreach ($cheques as $cheque) {
                    if ((int) $cheque['id'] === (int) $cheque_id) {
                        $apariciones[] = $tipo . '.' . $solapa;
                    }
                }
            }
        }

        return $apariciones;
    }

    /**
     * Los ids de los cheques de una solapa de `GET cheque`.
     *
     * @param string $tipo 'recibido' | 'emitido'
     * @param string $solapa 'pendientes', 'endosados'...
     * @return array<int, int>
     */
    protected function ids_de_la_solapa($tipo, $solapa)
    {
        $response = $this->getJson('api/cheque');

        $response->assertStatus(200);

        $ids = [];

        foreach ($response->json('models.' . $tipo . '.' . $solapa) as $cheque) {
            $ids[] = (int) $cheque['id'];
        }

        return $ids;
    }

    /**
     * Busca cheques por `POST api/global-search/cheque` con el body que arma la SPA (listado por
     * defecto: sin texto, sin props) más los `extra_filters` y `filters` que se pidan. Corta el
     * test si la respuesta no es 200: un 500 por un valor raro es justamente lo que se vigila.
     *
     * `per_page` 200 (el tope del endpoint) para que ninguno de los cheques sembrados quede
     * fuera de la primera página.
     *
     * @param array $extra_filters Objetos { key, operator, value }.
     * @param array $filters Filtros/orden de columna, con la forma que manda la lupa del header.
     * @return array{ids: array<int, int>, total: int} Los ids EN EL ORDEN en que vinieron y el total.
     */
    protected function buscar_cheques(array $extra_filters = [], array $filters = [])
    {
        $response = $this->postJson('api/global-search/cheque?page=1', [
            'query_value'    => '',
            'props'          => [],
            'relation_props' => [],
            'extra_filters'  => $extra_filters,
            'filters'        => $filters,
            'conector'       => 'or',
            'per_page'       => 200,
        ]);

        $response->assertStatus(200);

        $ids = [];

        foreach ($response->json('models.data') as $cheque) {
            $ids[] = (int) $cheque['id'];
        }

        return ['ids' => $ids, 'total' => (int) $response->json('models.total')];
    }

    /**
     * El filtro extra `in` sobre el id, con el valor que se pida (tal cual, sin normalizar: los
     * tests de basura mandan cosas que no son ids a propósito).
     *
     * @param mixed $valor
     * @return array
     */
    protected function filtro_in_de_ids($valor)
    {
        return [['key' => 'id', 'operator' => 'in', 'value' => $valor]];
    }

    /**
     * El filtro de orden por fecha de emisión tal como lo manda la SPA al tocar la flecha de esa
     * columna (misma forma que en 1_Global_Search_Filtros_De_Columna_Test).
     *
     * @param string $direccion 'ASC' | 'DESC'
     * @return array
     */
    protected function orden_por_fecha_emision($direccion)
    {
        return [['key' => 'fecha_emision', 'type' => 'date', 'ordenar_de' => $direccion]];
    }

    /**
     * Cuatro recibidos en cartera con fechas de emisión elegidas para que el orden por fecha NO
     * coincida con el orden por defecto del endpoint (created_at y después id, ambos DESC):
     *
     *   a = 10/1  ·  b = 5/3  ·  c = 1/2  ·  d = 15/2   (se crean en ese orden: ids a < b < c < d)
     *
     * Por fecha ASC: a, c, d, b. Por defecto (id DESC): d, c, b, a. Los tests de orden usan
     * a, b y c como los "pedidos" y d como el cheque que NO se pide y tiene que quedar afuera.
     * Se insertan a mano porque la fecha de emisión la elige el test, no el cobro.
     *
     * @return array{0: Cheque, 1: Cheque, 2: Cheque, 3: Cheque}
     */
    protected function cuatro_cheques_con_fechas_distintas()
    {
        $a = $this->cheque_a_mano(['numero' => 'SOL-A-' . uniqid(), 'fecha_emision' => '2026-01-10']);
        $b = $this->cheque_a_mano(['numero' => 'SOL-B-' . uniqid(), 'fecha_emision' => '2026-03-05']);
        $c = $this->cheque_a_mano(['numero' => 'SOL-C-' . uniqid(), 'fecha_emision' => '2026-02-01']);
        $d = $this->cheque_a_mano(['numero' => 'SOL-D-' . uniqid(), 'fecha_emision' => '2026-02-15']);

        return [$a, $b, $c, $d];
    }

    // ------------------------------------------------------------------------------------------
    // GET api/cheque — qué cheque cae en recibido.endosados
    // ------------------------------------------------------------------------------------------

    /**
     * Caso 1 del plan: endoso a un proveedor.
     *
     * @test
     */
    public function un_recibido_endosado_a_un_proveedor_esta_en_endosados_y_en_ningun_otro_estado()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente solapa proveedor ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor solapa ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'SOL-P-' . substr(uniqid(), -5)]);

        // Antes del endoso está en cartera, en su estado de siempre (fecha de pago a 10 días).
        $this->assertEquals(['recibido.pendientes'], $this->apariciones_en_el_listado($recibido->id));

        $this->endosar_a_proveedor($recibido, $proveedor, $cuenta_proveedor);

        $this->assertEquals($proveedor->id, $recibido->fresh()->endosado_a_provider_id, 'El recibido tenía que quedar marcado al proveedor.');

        // En Endosado y en NINGÚN otro estado de ninguna de las dos solapas de primer nivel.
        $this->assertEquals(['recibido.endosados'], $this->apariciones_en_el_listado($recibido->id));

        // Y el listado le trae a la SPA el proveedor para mostrar en la columna de la solapa.
        $del_listado = $this->cheque_del_listado($recibido->id);

        $this->assertEquals($proveedor->id, $del_listado['endosado_a_provider']['id']);
        $this->assertNull($del_listado['endosado_en_expense']);
    }

    /**
     * Caso 2 del plan: endoso en un gasto.
     *
     * @test
     */
    public function un_recibido_endosado_en_un_gasto_esta_en_endosados_y_en_ningun_otro_estado()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente solapa gasto ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'SOL-G-' . substr(uniqid(), -5)]);

        $this->assertEquals(['recibido.pendientes'], $this->apariciones_en_el_listado($recibido->id));

        $gasto = $this->endosar_en_gasto($recibido);

        $this->assertEquals($gasto->id, $recibido->fresh()->endosado_en_expense_id, 'El recibido tenía que quedar marcado con el gasto.');

        $this->assertEquals(['recibido.endosados'], $this->apariciones_en_el_listado($recibido->id));

        // En el listado viaja el gasto, que es lo que la solapa muestra en vez del proveedor.
        $del_listado = $this->cheque_del_listado($recibido->id);

        $this->assertNull($del_listado['endosado_a_provider']);
        $this->assertEquals($gasto->id, $del_listado['endosado_en_expense']['id']);
    }

    /**
     * Caso 3 del plan (decisión 2): el endoso manda sobre la marca manual. Un recibido que salió
     * de cartera Y tiene `estado_manual` cobrado/rechazado va a Endosados, no a Cobrados ni a
     * Rechazados: es la única forma de que "todo endosado está en la solapa Endosado" sea cierto.
     *
     * 🔴 Este es el caso que falla con el `index()` anterior (que miraba `estado_manual` primero).
     *
     * Ningún endpoint deja un recibido endosado con marca manual (la UI no ofrece cobrar ni
     * rechazar un cheque fuera de cartera), pero la API no lo impide. Por eso el cheque se endosa
     * por el endpoint real y recién después se le pone la marca a mano, con un UPDATE directo.
     *
     * @test
     */
    public function el_endoso_manda_sobre_la_marca_manual_de_cobrado_o_rechazado()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente solapa marca ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor solapa marca ' . uniqid(), self::DEUDA_PROVEEDOR);

        // A: endosado a un proveedor y marcado cobrado. B: endosado en un gasto y marcado rechazado.
        $cobrado = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'SOL-MC-' . substr(uniqid(), -5)]);
        $rechazado = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'SOL-MR-' . substr(uniqid(), -5)]);

        $this->endosar_a_proveedor($cobrado, $proveedor, $cuenta_proveedor);
        $this->endosar_en_gasto($rechazado);

        // La marca manual, a mano: ningún endpoint la deja sobre un cheque ya endosado.
        Cheque::where('id', $cobrado->id)->update(['estado_manual' => 'cobrado']);
        Cheque::where('id', $rechazado->id)->update(['estado_manual' => 'rechazado']);

        // Prueba de que el escenario es el que se quiere: endosados Y con marca.
        $this->assertEquals('cobrado', $cobrado->fresh()->estado_manual);
        $this->assertEquals('rechazado', $rechazado->fresh()->estado_manual);
        $this->assertNotNull($cobrado->fresh()->endosado_a_provider_id);
        $this->assertNotNull($rechazado->fresh()->endosado_en_expense_id);

        $this->assertEquals(['recibido.endosados'], $this->apariciones_en_el_listado($cobrado->id), 'Endosado y cobrado: va a Endosados, no a Cobrados.');
        $this->assertEquals(['recibido.endosados'], $this->apariciones_en_el_listado($rechazado->id), 'Endosado y rechazado: va a Endosados, no a Rechazados.');
    }

    /**
     * Caso 4 del plan (decisión 1): la copia EMITIDA que nace del endoso sigue en Emitido, como
     * hasta hoy ("es esperado ver el mismo número de cheque en las dos solapas"), y no se cuela
     * en `recibido.endosados`. La condición del endoso es solo para `tipo = recibido`.
     *
     * @test
     */
    public function la_copia_emitida_de_un_endoso_sigue_en_emitido_y_no_entra_en_endosados()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente solapa copia ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor solapa copia ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'SOL-C-' . substr(uniqid(), -5)]);

        $copia = $this->endosar_a_proveedor($recibido, $proveedor, $cuenta_proveedor);

        $this->assertEquals('emitido', $copia->tipo);

        // La copia: en Emitido (fecha de pago a 10 días: pendientes) y en ninguna otra parte.
        $this->assertEquals(['emitido.pendientes'], $this->apariciones_en_el_listado($copia->id));

        // La solapa Endosados tiene al recibido y NO a la copia.
        $endosados = $this->ids_de_la_solapa('recibido', 'endosados');

        $this->assertContains($recibido->id, $endosados);
        $this->assertNotContains($copia->id, $endosados);

        // Si el proveedor la cobra (marca manual sobre la copia), sigue su propio ciclo en Emitido.
        Cheque::where('id', $copia->id)->update(['estado_manual' => 'cobrado']);

        $this->assertEquals(['emitido.cobrados'], $this->apariciones_en_el_listado($copia->id));
        $this->assertEquals(['recibido.endosados'], $this->apariciones_en_el_listado($recibido->id));
    }

    /**
     * Caso 5 del plan: regresión de los buckets. Mover el chequeo del endoso antes de la marca
     * manual no puede tocar a los recibidos que siguen en cartera: cada uno queda en el estado
     * que le corresponde por fecha o por su marca manual, y ninguno en Endosados.
     *
     * Dos nacen de un cobro real (pendiente y disponible); el resto se inserta a mano porque
     * depende de fechas pasadas o de una marca manual.
     *
     * @test
     */
    public function un_recibido_en_cartera_sigue_en_su_estado_de_siempre()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente solapa cartera ' . uniqid());

        $hoy = Carbon::today();

        // Pendiente: fecha de pago a futuro. Disponible para cobrar: fecha de pago hoy (le quedan 30 días).
        $pendiente = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['fecha_pago' => $hoy->copy()->addDays(5)->format('Y-m-d')]);
        $disponible = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['fecha_pago' => $hoy->format('Y-m-d')]);

        // Pronto a vencerse: pagaba hace 28 días, vence en 2. Vencido: pagaba hace 40 días.
        $pronto = $this->cheque_a_mano(['fecha_pago' => $hoy->copy()->subDays(28)->format('Y-m-d')]);
        $vencido = $this->cheque_a_mano(['fecha_pago' => $hoy->copy()->subDays(40)->format('Y-m-d')]);

        // Con marca manual y SIN endosar: la marca sigue mandando.
        $cobrado = $this->cheque_a_mano(['estado_manual' => 'cobrado']);
        $rechazado = $this->cheque_a_mano(['estado_manual' => 'rechazado']);

        $esperado = [
            [$pendiente, 'recibido.pendientes'],
            [$disponible, 'recibido.disponibles_para_cobrar'],
            [$pronto, 'recibido.pronto_a_vencerse'],
            [$vencido, 'recibido.vencidos'],
            [$cobrado, 'recibido.cobrados'],
            [$rechazado, 'recibido.rechazados'],
        ];

        foreach ($esperado as $par) {
            $this->assertEquals([$par[1]], $this->apariciones_en_el_listado($par[0]->id), 'El cheque ' . $par[0]->numero . ' tenía que estar solo en ' . $par[1] . '.');
        }

        // Ninguno de los que siguen en cartera se coló en Endosados.
        $endosados = $this->ids_de_la_solapa('recibido', 'endosados');

        foreach ($esperado as $par) {
            $this->assertNotContains($par[0]->id, $endosados);
        }
    }

    /**
     * La forma de la respuesta de `GET cheque` no cambia (contrato con la SPA, que en producción
     * corre con la API vieja o la nueva indistintamente): dos tipos, `recibido` con sus siete
     * estados (incluido `endosados`) y `emitido` con seis (sin `endosados`).
     *
     * @test
     */
    public function la_forma_de_la_respuesta_del_listado_no_cambia()
    {
        $response = $this->getJson('api/cheque');

        $response->assertStatus(200);

        $models = $response->json('models');

        $this->assertEquals(['recibido', 'emitido'], array_keys($models));
        $this->assertEquals(
            ['pendientes', 'disponibles_para_cobrar', 'pronto_a_vencerse', 'vencidos', 'cobrados', 'rechazados', 'endosados'],
            array_keys($models['recibido'])
        );
        $this->assertEquals(
            ['pendientes', 'disponibles_para_cobrar', 'pronto_a_vencerse', 'vencidos', 'cobrados', 'rechazados'],
            array_keys($models['emitido'])
        );
    }

    // ------------------------------------------------------------------------------------------
    // POST api/global-search/cheque — el operador `in` de extra_filters
    // ------------------------------------------------------------------------------------------

    /**
     * Caso 6 del plan: el filtro de orden de columna se aplica SOBRE la selección que manda `in`.
     * Devuelve solo los ids pedidos —el cheque d, que existe y es del dueño, queda afuera— y en el
     * orden de la columna: por fecha de emisión ASC a, c, b; DESC, al revés.
     *
     * Es el pedido de Lucas ("ordenar por fecha de emisión dentro de Recibido → Pendientes"):
     * la SPA manda los ids de la solapa y la flecha de la columna.
     *
     * @test
     */
    public function la_busqueda_con_in_devuelve_solo_los_ids_pedidos_en_el_orden_de_la_columna()
    {
        list($a, $b, $c, $d) = $this->cuatro_cheques_con_fechas_distintas();

        $pedidos = [$a->id, $b->id, $c->id];

        $asc = $this->buscar_cheques($this->filtro_in_de_ids($pedidos), $this->orden_por_fecha_emision('ASC'));

        $this->assertEquals([$a->id, $c->id, $b->id], $asc['ids'], 'Por fecha de emisión ASC: 10/1, 1/2, 5/3.');
        $this->assertEquals(3, $asc['total']);
        $this->assertNotContains($d->id, $asc['ids'], 'El cheque que no se pidió no puede venir.');

        $desc = $this->buscar_cheques($this->filtro_in_de_ids($pedidos), $this->orden_por_fecha_emision('DESC'));

        $this->assertEquals([$b->id, $c->id, $a->id], $desc['ids'], 'Por fecha de emisión DESC: 5/3, 1/2, 10/1.');
        $this->assertEquals(3, $desc['total']);
        $this->assertNotContains($d->id, $desc['ids']);

        // Sin `in`, el mismo orden trae también al d (y a los demás cheques del dueño): lo que
        // acota es el operador, no el orden. Si `in` se ignorara, los dos asserts de arriba fallan.
        $sin_in = $this->buscar_cheques([], $this->orden_por_fecha_emision('ASC'));

        $this->assertContains($d->id, $sin_in['ids']);
        $this->assertGreaterThan(3, $sin_in['total']);
    }

    /**
     * `in` es un AND más: se combina con un filtro de columna de la lupa (la intersección de la
     * selección de la solapa con lo que el usuario busca adentro).
     *
     * @test
     */
    public function la_busqueda_con_in_se_combina_con_un_filtro_de_columna()
    {
        // Marca única en el número de tres cheques, más uno que no la tiene.
        $marca = 'SOLX' . substr(uniqid(), -6);

        $x1 = $this->cheque_a_mano(['numero' => $marca . '-1']);
        $x2 = $this->cheque_a_mano(['numero' => $marca . '-2']);
        $x3 = $this->cheque_a_mano(['numero' => $marca . '-3']);
        $sin_marca = $this->cheque_a_mano(['numero' => 'OTRO-' . uniqid()]);

        // El filtro de la lupa pide los que contengan la marca (x1, x2 y x3); la selección de la
        // solapa es x1, x2 y el que no tiene marca. La intersección es x1 y x2: x3 cumple el
        // filtro pero no está en la selección, y sin_marca está en la selección pero no cumple el filtro.
        $filtro_de_texto = [
            ['key' => 'numero', 'type' => 'text', 'que_contenga' => $marca],
        ];

        $resultado = $this->buscar_cheques($this->filtro_in_de_ids([$x1->id, $x2->id, $sin_marca->id]), $filtro_de_texto);

        $this->assertEqualsCanonicalizing([$x1->id, $x2->id], $resultado['ids']);
        $this->assertEquals(2, $resultado['total']);
        $this->assertNotContains($x3->id, $resultado['ids'], 'Cumple el filtro de texto pero no está en la selección.');
        $this->assertNotContains($sin_marca->id, $resultado['ids'], 'Está en la selección pero no cumple el filtro de texto.');
    }

    /**
     * Caso 7a del plan: `in` con un array vacío significa NINGUNA fila, no "sin filtro". Es la
     * solapa vacía: la SPA manda `value: []` y tiene que ver una tabla vacía, no todos los cheques.
     *
     * @test
     */
    public function in_con_un_array_vacio_no_devuelve_ninguna_fila()
    {
        $this->cuatro_cheques_con_fechas_distintas();

        $resultado = $this->buscar_cheques($this->filtro_in_de_ids([]));

        $this->assertEquals([], $resultado['ids']);
        $this->assertEquals(0, $resultado['total'], 'Un `in` con array vacío tiene que dar 0 filas, no el listado completo.');
    }

    /**
     * Caso 7b del plan: `in` con ids de otro dueño no los devuelve. El scope por usuario del
     * endpoint (`user_id`) sigue mandando por encima de cualquier filtro extra: pasar el id de un
     * cheque ajeno no filtra "a favor", simplemente no lo encuentra.
     *
     * @test
     */
    public function in_con_ids_de_otro_dueno_no_los_devuelve()
    {
        list($a, $b) = $this->cuatro_cheques_con_fechas_distintas();

        // Un cheque de OTRO dueño, insertado a mano (ningún endpoint crea cheques ajenos).
        $otro_dueno = User::create([
            'name'     => 'Otro comercio solapa',
            'email'    => 'cheques-solapa-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $ajeno = $this->cheque_a_mano(['user_id' => $otro_dueno->id, 'numero' => 'SOL-AJENO-' . uniqid()]);

        // Pidiendo el propio y el ajeno: viene solo el propio.
        $mezclado = $this->buscar_cheques($this->filtro_in_de_ids([$a->id, $ajeno->id]));

        $this->assertEquals([$a->id], $mezclado['ids']);
        $this->assertEquals(1, $mezclado['total']);

        // Pidiendo solo el ajeno: no viene nada.
        $solo_ajeno = $this->buscar_cheques($this->filtro_in_de_ids([$ajeno->id]));

        $this->assertEquals([], $solo_ajeno['ids']);
        $this->assertEquals(0, $solo_ajeno['total']);
    }

    /**
     * Caso 7c del plan: los elementos que no son numéricos se descartan sin romper (nada de 500),
     * y los numéricos —incluso como string— siguen valiendo. Si después de descartar no queda
     * ninguno, vale la misma regla del array vacío: ninguna fila.
     *
     * @test
     */
    public function in_descarta_los_elementos_que_no_son_ids_sin_romper()
    {
        list($a, $b) = $this->cuatro_cheques_con_fechas_distintas();

        // Basura mezclada con ids válidos: texto, null, número con letras, un array adentro, un
        // booleano. El id del b viaja como string numérico ("57"), que es un id válido.
        $con_basura = [$a->id, 'abc', null, '12abc', [1], true, (string) $b->id];

        $resultado = $this->buscar_cheques($this->filtro_in_de_ids($con_basura), $this->orden_por_fecha_emision('ASC'));

        $this->assertEquals([$a->id, $b->id], $resultado['ids'], 'Quedan los dos ids válidos y se descarta el resto.');
        $this->assertEquals(2, $resultado['total']);

        // Solo basura: después de descartar queda vacío, o sea ninguna fila (no "sin filtro").
        $solo_basura = $this->buscar_cheques($this->filtro_in_de_ids(['abc', null, '12abc']));

        $this->assertEquals(0, $solo_basura['total'], 'Un `in` que queda vacío tras descartar la basura tiene que dar 0 filas.');
    }

    /**
     * Caso 7d del plan: si `value` no es un array el filtro se IGNORA (no restringe nada). Es lo
     * que hace una API vieja con un operador que no conoce, y lo que la SPA espera de una API
     * que no entiende `in`: nunca un error ni una tabla vacía por un valor mal formado.
     *
     * @test
     */
    public function in_con_un_valor_que_no_es_array_se_ignora()
    {
        list($a, $b, $c, $d) = $this->cuatro_cheques_con_fechas_distintas();

        $sin_filtro = $this->buscar_cheques();

        // Un entero suelto y un texto con comas: ninguno es un array.
        foreach ([(int) $a->id, $a->id . ',' . $b->id, 'abc'] as $valor) {

            $resultado = $this->buscar_cheques($this->filtro_in_de_ids($valor));

            $this->assertEquals($sin_filtro['total'], $resultado['total'], 'Con un `value` que no es array el filtro no restringe nada.');
            $this->assertContains($a->id, $resultado['ids']);
            $this->assertContains($d->id, $resultado['ids']);
        }
    }

    /**
     * Caso 8 del plan: `in` solo vale sobre columnas numéricas. Sobre una columna de texto
     * (`numero`) se ignora —igual que `>` y `<`— y no revienta.
     *
     * @test
     */
    public function in_sobre_una_columna_no_numerica_se_ignora()
    {
        list($a, $b, $c, $d) = $this->cuatro_cheques_con_fechas_distintas();

        $sin_filtro = $this->buscar_cheques();

        // Si `in` valiera sobre texto, esto traería solo al a: el d (que no está en la lista) quedaría afuera.
        $resultado = $this->buscar_cheques([['key' => 'numero', 'operator' => 'in', 'value' => [$a->numero]]]);

        $this->assertEquals($sin_filtro['total'], $resultado['total'], 'Sobre una columna de texto el `in` no restringe nada.');
        $this->assertContains($a->id, $resultado['ids']);
        $this->assertContains($d->id, $resultado['ids']);
    }

    /**
     * La `key` del `in` se valida contra el schema como en los demás operadores: una columna que
     * no existe se ignora, no llega a ningún SQL (nada de 500 por una key rara del request).
     *
     * @test
     */
    public function in_sobre_una_columna_que_no_existe_se_ignora()
    {
        list($a, $b, $c, $d) = $this->cuatro_cheques_con_fechas_distintas();

        $sin_filtro = $this->buscar_cheques();

        $resultado = $this->buscar_cheques([['key' => 'columna_que_no_existe', 'operator' => 'in', 'value' => [$a->id]]]);

        $this->assertEquals($sin_filtro['total'], $resultado['total'], 'Una key que no existe en el schema no restringe nada.');
        $this->assertContains($d->id, $resultado['ids']);
    }
}
