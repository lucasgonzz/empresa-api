<?php

namespace App\Http\Controllers\Helpers\category_proposal;

use App\Models\Category;
use App\Models\CategoryProposal;
use App\Models\CategoryProposalItem;
use App\Models\CategoryProposalRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lo que ve la SPA de una corrida de propuestas de categorías: el badge de Alertas, la corrida
 * vigente con sus tarjetas, árboles y conteos, los ítems paginados de la revisión y la marca de
 * "vista" (misión categorizacion-tres-modelos, 5/10/2026). Contrato B del plan, §6.
 *
 * `run_payload` y `conteos` son las firmas que consume el otro constructor (API-2 las usa en las
 * respuestas de `elegir` y `volver_atras`): se conservan tal cual las dejó la base.
 *
 * 🔴 TODO por dueño. El dueño llega del controlador (`$this->userId()`: para un empleado es su
 * `owner_id`) y cada consulta lleva su `user_id`. Un id de corrida que llega en la ruta se cruza
 * SIEMPRE con ese dueño: el de otro comercio se contesta igual que uno inexistente (404
 * `no_encontrado`, nunca un 403 que confirme que existe). Una corrida `descartada` no se ve nunca.
 *
 * 🔴 NINGÚN `withAll()`: una corrida de 10.000 artículos tiene miles de ítems y cientos de nodos. Todo
 * sale con consultas agrupadas de cantidad CONSTANTE (no crece con la cantidad de ítems) y con
 * `select` explícito; los ítems de la revisión se paginan en el servidor.
 *
 * Forma de las respuestas que pueden fallar: `['status' => int, 'body' => array]`, igual que la
 * ingesta; el controlador solo las traduce a HTTP. Los errores son
 * `{"error": "<código>", "message": "<texto para mostrar>"}`.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class CategoryProposalLecturaHelper
{
    /** Los tamaños de página que ofrece la revisión (25 por defecto). */
    const POR_PAGINA_PERMITIDAS = [25, 50, 100];
    const POR_PAGINA_DEFECTO    = 25;

    /** La solapa que se abre si el pedido no dice cuál. */
    const SOLAPA_POR_DEFECTO = 'a_revisar';

    /**
     * Las tres solapas de la revisión y los estados de ítem que agrupa cada una. El nombre de la solapa
     * es también la clave de su conteo.
     */
    const SOLAPAS = [
        'a_revisar'     => CategoryProposalItem::ESTADOS_A_REVISAR,
        'asignados'     => CategoryProposalItem::ESTADOS_ASIGNADOS,
        'sin_categoria' => CategoryProposalItem::ESTADOS_SIN_CATEGORIA,
    ];

    // ------------------------------------------------------------------------------------------
    // Respuestas y utilidades
    // ------------------------------------------------------------------------------------------

    /**
     * El resultado que el controlador traduce a HTTP.
     *
     * @param  int   $status
     * @param  array $body
     * @return array  ['status' => int, 'body' => array]
     */
    protected static function respuesta($status, array $body)
    {
        return ['status' => $status, 'body' => $body];
    }

    /**
     * Un error con la forma del contrato B: `{"error": código, "message": texto, ...extra}`.
     *
     * @param  int    $status
     * @param  string $codigo
     * @param  string $mensaje
     * @param  array  $extra
     * @return array
     */
    protected static function error($status, $codigo, $mensaje, array $extra = [])
    {
        return self::respuesta($status, array_merge(['error' => $codigo, 'message' => $mensaje], $extra));
    }

    /**
     * El 404 de una corrida que no existe o es de otro comercio (las dos, la MISMA respuesta).
     *
     * @return array
     */
    public static function no_encontrado()
    {
        return self::error(404, 'no_encontrado', 'No se encontró esa propuesta de categorías.');
    }

    /**
     * El 403 de quien no es el dueño ni el acceso maestro y quiere ver o tocar una propuesta.
     *
     * @return array
     */
    public static function solo_el_dueno()
    {
        return self::error(403, 'solo_el_dueno', 'Solo el dueño de la cuenta o el acceso maestro de ComercioCity puede hacer esto.');
    }

    /**
     * Una fecha para el JSON como `2026-10-05 12:00:00` (en la zona de la app), o null.
     * Se formatea a mano: `toArray()` serializa las fechas con `Z` y el cliente las corre tres horas.
     *
     * @param  \Carbon\Carbon|string|null $fecha
     * @return string|null
     */
    protected static function fecha($fecha)
    {
        if (is_null($fecha) || $fecha === '') {

            return null;
        }

        return ($fecha instanceof Carbon ? $fecha : Carbon::parse($fecha))->format('Y-m-d H:i:s');
    }

    /**
     * La corrida vigente del dueño: la última que no está descartada.
     *
     * @param  int $owner_id
     * @return \App\Models\CategoryProposalRun|null
     */
    public static function corrida_vigente($owner_id)
    {
        return CategoryProposalRun::where('user_id', (int) $owner_id)
            ->vigentes()
            ->orderBy('id', 'desc')
            ->first();
    }

    // ------------------------------------------------------------------------------------------
    // run, conteos
    // ------------------------------------------------------------------------------------------

    /**
     * El objeto `run` del contrato B (§6.2): id, estado, articulos_total, creada_at, elegida_at,
     * propuesta_elegida_id, puede_gestionar, puede_cambiar, motivo_no_puede_cambiar y resultado.
     *
     * 🔴 Refleja el estado ACTUAL del modelo que se le pasa: quien lo llama después de cambiar la corrida
     * (elegir, volver atrás) tiene que haberla refrescado.
     *
     * `puede_cambiar` y `motivo_no_puede_cambiar` salen de la MISMA función que usa "volver atrás"
     * (`CategoryProposalAplicarHelper::puede_cambiar`): una sola fuente de verdad, la SPA solo dibuja.
     * Solo tiene sentido con la corrida ELEGIDA; en cualquier otro estado no hay nada que cambiar y
     * van `false` y `null` (el motivo `no_esta_elegida` no se muestra: no es un motivo que el dueño
     * pueda entender).
     *
     * @param  \App\Models\CategoryProposalRun $run
     * @return array
     */
    public static function run_payload(CategoryProposalRun $run)
    {
        // Si se puede cambiar de sistema y, si no, por qué (solo con la corrida elegida).
        $puede_cambiar = false;
        $motivo        = null;

        if ($run->estado === CategoryProposalRun::ESTADO_ELEGIDA) {

            // Lo que dice la función que comparte con "volver atrás".
            $evaluacion    = CategoryProposalAplicarHelper::puede_cambiar($run);
            $puede_cambiar = !empty($evaluacion['puede']);
            $motivo        = $puede_cambiar ? null : (isset($evaluacion['motivo']) ? $evaluacion['motivo'] : null);
        }

        return [
            'id'                      => (int) $run->id,
            'estado'                  => (string) $run->estado,
            'articulos_total'         => (int) $run->articulos_total,
            'creada_at'               => self::fecha($run->created_at),
            'elegida_at'              => self::fecha($run->elegida_at),
            'propuesta_elegida_id'    => is_null($run->propuesta_elegida_id) ? null : (int) $run->propuesta_elegida_id,
            'puede_gestionar'         => (bool) CategoryProposalAccesoHelper::puede_gestionar(),
            'puede_cambiar'           => $puede_cambiar,
            'motivo_no_puede_cambiar' => $motivo,
            'resultado'               => $run->resultado,
        ];
    }

    /**
     * Los conteos de las tres solapas de la revisión, de la propuesta elegida de la corrida. Una sola
     * consulta agrupada por estado. Sin propuesta elegida, todo en cero.
     *
     * @param  \App\Models\CategoryProposalRun $run
     * @return array  ['a_revisar' => n, 'asignados' => n, 'sin_categoria' => n]
     */
    public static function conteos(CategoryProposalRun $run)
    {
        // Los tres conteos arrancan en cero (sin propuesta elegida no hay nada que contar).
        $conteos = ['a_revisar' => 0, 'asignados' => 0, 'sin_categoria' => 0];

        if (empty($run->propuesta_elegida_id)) {

            return $conteos;
        }

        // Una fila por estado de ítem con su cantidad.
        $filas = DB::table('category_proposal_items')
            ->where('proposal_id', (int) $run->propuesta_elegida_id)
            ->where('user_id', (int) $run->user_id)
            ->groupBy('estado')
            ->selectRaw('estado, COUNT(*) AS n')
            ->get();

        foreach ($filas as $fila) {

            foreach (self::SOLAPAS as $solapa => $estados) {

                if (in_array($fila->estado, $estados, true)) {

                    $conteos[$solapa] += (int) $fila->n;
                }
            }
        }

        return $conteos;
    }

    // ------------------------------------------------------------------------------------------
    // resumen (badge)
    // ------------------------------------------------------------------------------------------

    /**
     * GET category-proposal-runs/resumen — lo pide el login y alimenta el badge de Alertas (§6.1).
     *
     * `badge = (estado == 'lista' ? 1 : 0) + a_revisar`: un sistema esperando que el dueño elija vale 1
     * y cada dudoso por revisar suma uno. `sin_ver` avisa que la corrida lista todavía no se abrió.
     * Para quien no es el dueño ni el acceso maestro todo va en falso/0 y `puede_gestionar: false`.
     *
     * @param  int  $owner_id
     * @param  bool $puede_gestionar  Si la sesión es la del dueño o el acceso maestro.
     * @return array
     */
    public static function resumen($owner_id, $puede_gestionar)
    {
        // La respuesta de quien no tiene nada que mostrar (sin corrida o sin permiso).
        $vacio = [
            'hay_propuestas'       => false,
            'estado'               => null,
            'run_id'               => null,
            'pendientes_de_elegir' => false,
            'a_revisar'            => 0,
            'sin_ver'              => false,
            'badge'                => 0,
            'puede_gestionar'      => (bool) $puede_gestionar,
        ];

        if (!$puede_gestionar) {

            return $vacio;
        }

        // La corrida vigente del dueño.
        $run = self::corrida_vigente($owner_id);

        if (is_null($run)) {

            return $vacio;
        }

        // Una corrida lista es un sistema esperando que el dueño elija.
        $esta_lista = $run->estado === CategoryProposalRun::ESTADO_LISTA;

        // Los dudosos esperando revisión, solo de la propuesta que el dueño eligió.
        $a_revisar = 0;

        if ($run->estado === CategoryProposalRun::ESTADO_ELEGIDA && !empty($run->propuesta_elegida_id)) {

            $a_revisar = (int) CategoryProposalItem::where('proposal_id', (int) $run->propuesta_elegida_id)
                ->where('user_id', (int) $owner_id)
                ->where('estado', CategoryProposalItem::ESTADO_A_REVISAR)
                ->count();
        }

        return [
            'hay_propuestas'       => true,
            'estado'               => (string) $run->estado,
            'run_id'               => (int) $run->id,
            'pendientes_de_elegir' => $esta_lista,
            'a_revisar'            => $a_revisar,
            'sin_ver'              => $esta_lista && is_null($run->visto_at),
            'badge'                => ($esta_lista ? 1 : 0) + $a_revisar,
            'puede_gestionar'      => true,
        ];
    }

    // ------------------------------------------------------------------------------------------
    // actual (tarjetas)
    // ------------------------------------------------------------------------------------------

    /**
     * GET category-proposal-runs/actual — la corrida vigente con sus tarjetas, árboles y conteos (§6.2).
     *
     * - Quien no es el dueño ni el acceso maestro recibe la forma vacía (`run: null`) y nada más: no
     *   se calcula ni se filtra nada.
     * - Sin corrida: `run: null`, el bloqueo, si ya tiene categorías, y todo lo demás en cero/vacío.
     * - Corrida `preparando`: el `run` con `propuestas: []` (la SPA muestra "Estamos preparando tus
     *   propuestas"). Una `descartada` nunca se devuelve.
     *
     * `advertencias` del nivel superior es la UNIÓN de las que valen para las tarjetas que hay (las
     * genéricas más las de cada tipo): la SPA las muestra en el cartel de confirmar. Cada tarjeta trae
     * además las suyas (`propuestas[].advertencias`), por si el cartel quiere mostrar solo las que
     * corresponden a la tarjeta elegida (por ejemplo, el cambio de URLs de la tienda no corresponde a
     * "Mantener las mías").
     *
     * @param  int  $owner_id
     * @param  bool $puede_gestionar
     * @return array
     */
    public static function actual($owner_id, $puede_gestionar)
    {
        // Los conteos de la revisión en cero.
        $ceros = ['a_revisar' => 0, 'asignados' => 0, 'sin_categoria' => 0];

        if (!$puede_gestionar) {

            return [
                'run'                      => null,
                'bloqueo'                  => ['nuevo_modelo_bloqueado' => false, 'motivos' => []],
                'advertencias'             => [],
                'tiene_categorias_previas' => false,
                'conteos'                  => $ceros,
                'propuestas'               => [],
            ];
        }

        // El bloqueo de los sistemas nuevos (lo decide el helper de las reglas de plata).
        $bloqueo = CategoryMargenesHelper::bloqueo_para($owner_id);

        // "Ya tenía categorías": alguna categoría viva (el modelo Category excluye las borradas).
        $tiene_categorias_previas = Category::where('user_id', (int) $owner_id)->exists();

        $run = self::corrida_vigente($owner_id);

        if (is_null($run)) {

            return [
                'run'                      => null,
                'bloqueo'                  => $bloqueo,
                'advertencias'             => [],
                'tiene_categorias_previas' => $tiene_categorias_previas,
                'conteos'                  => $ceros,
                'propuestas'               => [],
            ];
        }

        // Las tarjetas y las advertencias que se van a devolver.
        $propuestas   = [];
        $advertencias = [];

        // Una corrida que la skill todavía está cargando no muestra tarjetas.
        if ($run->estado !== CategoryProposalRun::ESTADO_PREPARANDO) {

            $propuestas = self::propuestas_payload($run);

            // Las advertencias, una vez por tipo de tarjeta que haya (cada llamada son varias
            // consultas del otro helper): las genéricas valen para todas.
            $genericas = array_values(CategoryMargenesHelper::advertencias_para($owner_id));
            $por_tipo  = [];

            foreach ($propuestas as $indice => $tarjeta) {

                // El tipo de esta tarjeta (nueva o mantener).
                $tipo = $tarjeta['tipo'];

                if (!isset($por_tipo[$tipo])) {

                    $por_tipo[$tipo] = array_values(CategoryMargenesHelper::advertencias_para($owner_id, $tipo));
                }

                $propuestas[$indice]['advertencias'] = array_values(array_unique(array_merge($genericas, $por_tipo[$tipo])));
            }

            $advertencias = $genericas;

            foreach ($por_tipo as $codigos) {

                $advertencias = array_merge($advertencias, $codigos);
            }

            $advertencias = array_values(array_unique($advertencias));
        }

        return [
            'run'                      => self::run_payload($run),
            'bloqueo'                  => $bloqueo,
            'advertencias'             => $advertencias,
            'tiene_categorias_previas' => $tiene_categorias_previas,
            'conteos'                  => self::conteos($run),
            'propuestas'               => $propuestas,
        ];
    }

    /**
     * Las tarjetas de una corrida con sus totales y árboles (§6.2), ordenadas por `orden`.
     *
     * Cantidad de consultas CONSTANTE: las propuestas, todos sus nodos, los conteos de ítems agrupados
     * por (propuesta, nodo, subnodo, confianza), la cantidad de artículos que ya tiene cada categoría
     * real (solo "mantener") y los que perderían su categoría (solo propuestas nuevas de una corrida
     * lista). No crece con la cantidad de ítems.
     *
     * @param  \App\Models\CategoryProposalRun $run
     * @return array
     */
    public static function propuestas_payload(CategoryProposalRun $run)
    {
        // El id del dueño de la corrida.
        $owner_id = (int) $run->user_id;

        // Las tarjetas de la corrida, en su orden.
        $propuestas = CategoryProposal::where('run_id', $run->id)
            ->where('user_id', $owner_id)
            ->orderBy('orden')
            ->orderBy('id')
            ->get(['id', 'clave', 'tipo', 'nombre', 'resumen', 'descripcion', 'orden']);

        if ($propuestas->isEmpty()) {

            return [];
        }

        // Los ids de las tarjetas, para las consultas de abajo.
        $ids = $propuestas->pluck('id')->all();

        // Todos los nodos de todas las propuestas, agrupados por propuesta.
        $nodos = DB::table('category_proposal_nodes')
            ->whereIn('proposal_id', $ids)
            ->where('user_id', $owner_id)
            ->get(['id', 'proposal_id', 'parent_id', 'nombre', 'clave_nombre', 'existing_category_id', 'existing_sub_category_id']);

        // Los nodos agrupados por la propuesta a la que pertenecen.
        $nodos_de = [];

        foreach ($nodos as $nodo) {

            $nodos_de[(int) $nodo->proposal_id][] = $nodo;
        }

        // Lo que cae en cada nodo, por confianza: de una sola consulta agrupada salen los totales de
        // cada propuesta y los de cada categoría y subcategoría.
        $filas = DB::table('category_proposal_items')
            ->whereIn('proposal_id', $ids)
            ->where('user_id', $owner_id)
            ->groupBy('proposal_id', 'node_id', 'sub_node_id', 'confianza')
            ->selectRaw('proposal_id, node_id, sub_node_id, confianza, COUNT(*) AS n')
            ->get();

        // Los acumuladores: totales por propuesta y lo que cae en cada categoría y subcategoría.
        $totales  = [];
        $por_nodo = [];
        $por_sub  = [];

        foreach ($ids as $id) {

            $totales[(int) $id] = ['seguros' => 0, 'dudosos' => 0, 'sin_asignar' => 0];
        }

        foreach ($filas as $fila) {

            // La propuesta y la cantidad de esta fila agrupada.
            $proposal_id = (int) $fila->proposal_id;
            $n           = (int) $fila->n;

            if ($fila->confianza === CategoryProposalItem::CONFIANZA_SEGURA) {

                $totales[$proposal_id]['seguros'] += $n;
            } elseif ($fila->confianza === CategoryProposalItem::CONFIANZA_DUDOSA) {

                $totales[$proposal_id]['dudosos'] += $n;
            } else {

                $totales[$proposal_id]['sin_asignar'] += $n;
            }

            // Un ítem `ninguna` no cae en ningún nodo: solo los seguros y dudosos cuentan en un árbol.
            if ($fila->confianza === CategoryProposalItem::CONFIANZA_NINGUNA) {

                continue;
            }

            if (!is_null($fila->node_id)) {

                self::sumar_en_nodo($por_nodo[$proposal_id], (int) $fila->node_id, $fila->confianza, $n);
            }

            if (!is_null($fila->sub_node_id)) {

                self::sumar_en_nodo($por_sub[$proposal_id], (int) $fila->sub_node_id, $fila->confianza, $n);
            }
        }

        // En "Mantener las mías": cuántos artículos tiene hoy cada categoría y subcategoría reales.
        $existentes = self::articulos_de_las_categorias_reales($owner_id, $propuestas, $nodos_de);

        // Los artículos que perderían su categoría al elegir una propuesta nueva (solo tiene sentido
        // antes de elegir: después, los dudosos ya quedaron sin categoría).
        $pierden = [];

        if ($run->estado === CategoryProposalRun::ESTADO_LISTA) {

            $pierden = self::pierden_categoria($owner_id, $propuestas);
        }

        // Las tarjetas ya armadas.
        $tarjetas = [];

        foreach ($propuestas as $proposal) {

            // Datos de esta tarjeta: su id, si es "mantener" y sus nodos.
            $id        = (int) $proposal->id;
            $es_mantener = $proposal->tipo === CategoryProposal::TIPO_MANTENER;
            $nodos_p   = isset($nodos_de[$id]) ? $nodos_de[$id] : [];

            // Cuántos de sus nodos son categorías (los demás son subcategorías).
            $cantidad_de_categorias = 0;

            foreach ($nodos_p as $nodo) {

                if (is_null($nodo->parent_id)) {

                    $cantidad_de_categorias++;
                }
            }

            // Los totales por confianza de esta tarjeta.
            $t = $totales[$id];

            $tarjetas[] = [
                'id'          => $id,
                'clave'       => (string) $proposal->clave,
                'tipo'        => (string) $proposal->tipo,
                'nombre'      => (string) $proposal->nombre,
                'resumen'     => $proposal->resumen,
                'descripcion' => $proposal->descripcion,
                'orden'       => (int) $proposal->orden,
                'elegida'     => !empty($run->propuesta_elegida_id) && (int) $run->propuesta_elegida_id === $id,
                'totales'     => [
                    'categorias'        => $cantidad_de_categorias,
                    'subcategorias'     => count($nodos_p) - $cantidad_de_categorias,
                    'articulos'         => $t['seguros'] + $t['dudosos'] + $t['sin_asignar'],
                    'seguros'           => $t['seguros'],
                    'dudosos'           => $t['dudosos'],
                    'sin_asignar'       => $t['sin_asignar'],
                    'pierden_categoria' => isset($pierden[$id]) ? $pierden[$id] : 0,
                ],
                'arbol'       => self::arbol_de(
                    $nodos_p,
                    isset($por_nodo[$id]) ? $por_nodo[$id] : [],
                    isset($por_sub[$id]) ? $por_sub[$id] : [],
                    $es_mantener,
                    $existentes
                ),
            ];
        }

        return $tarjetas;
    }

    /**
     * Suma `$n` ítems de una confianza al acumulador de un nodo: `articulos` (seguros + dudosos),
     * `dudosos` y `suman` (los seguros: lo que se le agregaría a la categoría real en "mantener").
     *
     * @param  array|null &$acumulador  proposal -> node_id => ['articulos', 'dudosos', 'suman'] (se crea si no existe).
     * @param  int        $node_id
     * @param  string     $confianza
     * @param  int        $n
     * @return void
     */
    protected static function sumar_en_nodo(&$acumulador, $node_id, $confianza, $n)
    {
        if (!isset($acumulador[$node_id])) {

            $acumulador[$node_id] = ['articulos' => 0, 'dudosos' => 0, 'suman' => 0];
        }

        $acumulador[$node_id]['articulos'] += $n;

        if ($confianza === CategoryProposalItem::CONFIANZA_DUDOSA) {

            $acumulador[$node_id]['dudosos'] += $n;
        } else {

            $acumulador[$node_id]['suman'] += $n;
        }
    }

    /**
     * Cuántos artículos del universo tiene hoy cada categoría y subcategoría real a la que apuntan
     * los nodos de las propuestas "mantener". Dos consultas agrupadas (y ninguna si no hay "mantener").
     *
     * @param  int $owner_id
     * @param  \Illuminate\Support\Collection $propuestas
     * @param  array $nodos_de  proposal_id => [nodos]
     * @return array  ['categorias' => category_id => n, 'subcategorias' => sub_category_id => n]
     */
    protected static function articulos_de_las_categorias_reales($owner_id, $propuestas, array $nodos_de)
    {
        // Las categorías y subcategorías reales a las que apuntan los nodos de "mantener".
        $categoria_ids = [];
        $sub_ids       = [];

        foreach ($propuestas as $proposal) {

            if ($proposal->tipo !== CategoryProposal::TIPO_MANTENER || !isset($nodos_de[(int) $proposal->id])) {

                continue;
            }

            foreach ($nodos_de[(int) $proposal->id] as $nodo) {

                if (!is_null($nodo->existing_sub_category_id)) {

                    $sub_ids[(int) $nodo->existing_sub_category_id] = true;
                } elseif (!is_null($nodo->existing_category_id)) {

                    $categoria_ids[(int) $nodo->existing_category_id] = true;
                }
            }
        }

        // Lo que se devuelve: cantidad de artículos por categoría real y por subcategoría real.
        $resultado = ['categorias' => [], 'subcategorias' => []];

        if (!empty($categoria_ids)) {

            $resultado['categorias'] = CategoryProposalCatalogoHelper::articulos_del_dueno($owner_id)
                ->whereIn('a.category_id', array_keys($categoria_ids))
                ->groupBy('a.category_id')
                ->selectRaw('a.category_id AS id, COUNT(*) AS n')
                ->pluck('n', 'id')
                ->all();
        }

        if (!empty($sub_ids)) {

            $resultado['subcategorias'] = CategoryProposalCatalogoHelper::articulos_del_dueno($owner_id)
                ->whereIn('a.sub_category_id', array_keys($sub_ids))
                ->groupBy('a.sub_category_id')
                ->selectRaw('a.sub_category_id AS id, COUNT(*) AS n')
                ->pluck('n', 'id')
                ->all();
        }

        return $resultado;
    }

    /**
     * Cuántos artículos PERDERÍAN su categoría actual si el dueño elige cada propuesta nueva: los que
     * hoy tienen una categoría viva y cuyo ítem es dudoso o ninguna (al elegir, esos quedan sin
     * categoría hasta que se aprueben). Las propuestas "mantener" no lo hacen: solo aceptan artículos
     * que ya estaban sin categoría. Una consulta agrupada.
     *
     * @param  int $owner_id
     * @param  \Illuminate\Support\Collection $propuestas
     * @return array  proposal_id => cantidad (solo las que tienen alguno)
     */
    protected static function pierden_categoria($owner_id, $propuestas)
    {
        // Los ids de las propuestas nuevas.
        $ids = [];

        foreach ($propuestas as $proposal) {

            if ($proposal->tipo === CategoryProposal::TIPO_NUEVA) {

                $ids[] = (int) $proposal->id;
            }
        }

        if (empty($ids)) {

            return [];
        }

        // Los ítems dudosos o ninguna cuyo artículo tiene hoy una categoría viva, contados por propuesta.
        $filas = DB::table('category_proposal_items as i')
            ->join('articles as a', function ($join) {
                $join->on('a.id', '=', 'i.article_id')
                    ->on('a.user_id', '=', 'i.user_id')
                    ->whereNull('a.deleted_at');
            })
            ->join('categories as c', function ($join) {
                $join->on('c.id', '=', 'a.category_id')
                    ->on('c.user_id', '=', 'a.user_id')
                    ->whereNull('c.deleted_at');
            })
            ->whereIn('i.proposal_id', $ids)
            ->where('i.user_id', $owner_id)
            ->whereIn('i.confianza', [CategoryProposalItem::CONFIANZA_DUDOSA, CategoryProposalItem::CONFIANZA_NINGUNA])
            ->groupBy('i.proposal_id')
            ->selectRaw('i.proposal_id AS id, COUNT(*) AS n')
            ->get();

        // proposal_id => cantidad.
        $pierden = [];

        foreach ($filas as $fila) {

            $pierden[(int) $fila->id] = (int) $fila->n;
        }

        return $pierden;
    }

    /**
     * El árbol de una tarjeta: `[{id, nombre, articulos, dudosos, suman, subcategorias: [...]}]`, con
     * categorías y subcategorías ordenadas por nombre (como las ordena la tienda).
     *
     * - Propuesta nueva: `articulos` = ítems seguros + dudosos que caen en el nodo; `dudosos` = los
     *   dudosos; `suman` = null.
     * - "Mantener las mías": `articulos` = los que YA tiene la categoría real; `suman` = ítems seguros
     *   que se le agregarían; `dudosos` = los dudosos.
     *
     * @param  array $nodos        Los nodos de la propuesta (filas de `category_proposal_nodes`).
     * @param  array $por_nodo     node_id => ['articulos', 'dudosos', 'suman'] de las categorías.
     * @param  array $por_sub      sub node_id => ['articulos', 'dudosos', 'suman'].
     * @param  bool  $es_mantener
     * @param  array $existentes   Lo que devuelve `articulos_de_las_categorias_reales`.
     * @return array
     */
    public static function arbol_de(array $nodos, array $por_nodo, array $por_sub, $es_mantener, array $existentes)
    {
        // Un nodo sin ítems cuenta cero.
        $ceros = ['articulos' => 0, 'dudosos' => 0, 'suman' => 0];

        // Las categorías y, aparte, las subcategorías agrupadas por su padre.
        $categorias = [];
        $subs_de    = [];

        foreach ($nodos as $nodo) {

            if (is_null($nodo->parent_id)) {

                $categorias[] = $nodo;
            } else {

                $subs_de[(int) $nodo->parent_id][] = $nodo;
            }
        }

        // Por nombre normalizado y, a igual nombre, por id: el orden de la tienda y estable.
        $comparar = function ($a, $b) {

            $orden = strcmp((string) $a->clave_nombre, (string) $b->clave_nombre);

            return $orden !== 0 ? $orden : ((int) $a->id - (int) $b->id);
        };

        usort($categorias, $comparar);

        // El árbol que se devuelve.
        $arbol = [];

        foreach ($categorias as $categoria) {

            // Las subcategorías de esta categoría, ordenadas.
            $subs = isset($subs_de[(int) $categoria->id]) ? $subs_de[(int) $categoria->id] : [];

            usort($subs, $comparar);

            // Las subcategorías ya armadas.
            $hijas = [];

            foreach ($subs as $sub) {

                $hijas[] = self::nodo_de_arbol(
                    $sub,
                    isset($por_sub[(int) $sub->id]) ? $por_sub[(int) $sub->id] : $ceros,
                    $es_mantener,
                    $es_mantener && !is_null($sub->existing_sub_category_id) && isset($existentes['subcategorias'][(int) $sub->existing_sub_category_id])
                        ? (int) $existentes['subcategorias'][(int) $sub->existing_sub_category_id] : 0,
                    []
                );
            }

            $arbol[] = self::nodo_de_arbol(
                $categoria,
                isset($por_nodo[(int) $categoria->id]) ? $por_nodo[(int) $categoria->id] : $ceros,
                $es_mantener,
                $es_mantener && !is_null($categoria->existing_category_id) && isset($existentes['categorias'][(int) $categoria->existing_category_id])
                    ? (int) $existentes['categorias'][(int) $categoria->existing_category_id] : 0,
                $hijas
            );
        }

        return $arbol;
    }

    /**
     * Un nodo del árbol de una tarjeta, con los números según el tipo de propuesta.
     *
     * @param  object $nodo
     * @param  array  $conteo       ['articulos', 'dudosos', 'suman'] de los ítems que caen en el nodo.
     * @param  bool   $es_mantener
     * @param  int    $ya_tiene     Artículos que ya tiene la categoría real (solo "mantener").
     * @param  array  $subcategorias  Los hijos ya armados (vacío en las subcategorías).
     * @return array
     */
    protected static function nodo_de_arbol($nodo, array $conteo, $es_mantener, $ya_tiene, array $subcategorias)
    {
        // Los números del nodo según el tipo de propuesta.
        $fila = [
            'id'        => (int) $nodo->id,
            'nombre'    => (string) $nodo->nombre,
            'articulos' => $es_mantener ? (int) $ya_tiene : (int) $conteo['articulos'],
            'dudosos'   => (int) $conteo['dudosos'],
            'suman'     => $es_mantener ? (int) $conteo['suman'] : null,
        ];

        // Solo las categorías llevan la lista (las subcategorías son hojas).
        if (is_null($nodo->parent_id)) {

            $fila['subcategorias'] = $subcategorias;
        }

        return $fila;
    }

    // ------------------------------------------------------------------------------------------
    // visto
    // ------------------------------------------------------------------------------------------

    /**
     * PUT category-proposal-runs/{id}/visto — el dueño abrió la solapa con la corrida lista (§6.3).
     *
     * Marca `visto_at` solo si la corrida está `lista` y todavía no estaba vista: con la corrida en
     * preparación no se marca (las tarjetas no se vieron) y repetirlo no cambia nada. En cualquier
     * caso contesta `{"ok": true}` si la corrida es del dueño.
     *
     * @param  int $owner_id
     * @param  int $run_id
     * @return array
     */
    public static function marcar_visto($owner_id, $run_id)
    {
        // La corrida vigente del dueño (la ajena, la inexistente y la descartada dan lo mismo: null).
        $run = CategoryProposalRun::where('user_id', (int) $owner_id)
            ->vigentes()
            ->where('id', (int) $run_id)
            ->first(['id']);

        if (is_null($run)) {

            return self::no_encontrado();
        }

        // UPDATE condicional: dos pedidos a la vez no pisan la primera marca.
        CategoryProposalRun::where('id', $run->id)
            ->where('user_id', (int) $owner_id)
            ->where('estado', CategoryProposalRun::ESTADO_LISTA)
            ->whereNull('visto_at')
            ->update(['visto_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        return self::respuesta(200, ['ok' => true]);
    }

    // ------------------------------------------------------------------------------------------
    // items (la revisión)
    // ------------------------------------------------------------------------------------------

    /**
     * GET category-proposal-runs/{id}/items?solapa=&page=&per_page=&buscar= — los ítems de la
     * revisión de la propuesta ELEGIDA, de una solapa, paginados en el servidor (§6.6).
     *
     * Orden de los chequeos: corrida del dueño (404, sin distinguir una ajena de una inexistente) →
     * solapa válida (422) → propuesta elegida (409 `todavia_no_elegida`).
     *
     * @param  int    $owner_id
     * @param  int    $run_id
     * @param  string $solapa
     * @param  int    $pagina
     * @param  int    $por_pagina  25, 50 o 100 (cualquier otro valor es 25).
     * @param  string $buscar      Por nombre, código de barras y código de proveedor del artículo.
     * @return array
     */
    public static function items($owner_id, $run_id, $solapa, $pagina, $por_pagina, $buscar)
    {
        // La corrida vigente del dueño (la ajena, la inexistente y la descartada dan lo mismo: null).
        $run = CategoryProposalRun::where('user_id', (int) $owner_id)
            ->vigentes()
            ->where('id', (int) $run_id)
            ->first();

        if (is_null($run)) {

            return self::no_encontrado();
        }

        if (!array_key_exists($solapa, self::SOLAPAS)) {

            return self::error(422, 'validacion', 'Los datos enviados no son válidos. La solapa tiene que ser a_revisar, asignados o sin_categoria.', [
                'detalle' => ['solapa' => ['La solapa tiene que ser a_revisar, asignados o sin_categoria.']],
            ]);
        }

        if (empty($run->propuesta_elegida_id)) {

            return self::error(409, 'todavia_no_elegida', 'Todavía no se eligió un sistema de categorías: no hay nada para revisar.');
        }

        return self::respuesta(200, self::items_paginados($run, $solapa, $pagina, $por_pagina, $buscar));
    }

    /**
     * La página de ítems de una solapa y los conteos de las tres.
     *
     * Una sola consulta con LEFT JOIN al artículo, a los dos nodos (la sugerencia) y a la categoría y
     * subcategoría reales del artículo: sin N+1. Los LEFT JOIN a categorías cruzan el `user_id` y
     * excluyen las borradas.
     *
     * `actual` trae los nombres reales SOLO en los ítems aplicados o aprobados (los demás todavía no
     * tienen la categoría que sugiere el sistema).
     *
     * @param  \App\Models\CategoryProposalRun $run  Con una propuesta elegida.
     * @param  string $solapa
     * @param  int    $pagina
     * @param  int    $por_pagina
     * @param  string $buscar
     * @return array  ['models' => paginador, 'conteos' => [...]]
     */
    public static function items_paginados(CategoryProposalRun $run, $solapa, $pagina, $por_pagina, $buscar = '')
    {
        // El id del dueño de la corrida.
        $owner_id = (int) $run->user_id;

        // Los ítems de la propuesta elegida con todo lo que necesita la fila: artículo, sugerencia y categoría actual.
        $consulta = DB::table('category_proposal_items as i')
            ->leftJoin('articles as a', function ($join) {
                $join->on('a.id', '=', 'i.article_id')->on('a.user_id', '=', 'i.user_id');
            })
            ->leftJoin('category_proposal_nodes as n', 'n.id', '=', 'i.node_id')
            ->leftJoin('category_proposal_nodes as sn', 'sn.id', '=', 'i.sub_node_id')
            ->leftJoin('categories as c', function ($join) {
                $join->on('c.id', '=', 'a.category_id')->on('c.user_id', '=', 'a.user_id')->whereNull('c.deleted_at');
            })
            ->leftJoin('sub_categories as s', function ($join) {
                $join->on('s.id', '=', 'a.sub_category_id')->on('s.user_id', '=', 'a.user_id')->whereNull('s.deleted_at');
            })
            ->where('i.proposal_id', (int) $run->propuesta_elegida_id)
            ->where('i.user_id', $owner_id)
            ->whereIn('i.estado', self::SOLAPAS[$solapa]);

        // El texto que buscó la persona, sin espacios en los bordes.
        $buscar = trim((string) $buscar);

        if ($buscar !== '') {

            // El patrón se escapa: un `%` o un `_` que escribe la persona es un carácter, no un comodín.
            $patron = '%'.addcslashes($buscar, '%_\\').'%';

            $consulta->where(function ($condicion) use ($patron) {
                $condicion->where('a.name', 'like', $patron)
                    ->orWhere('a.bar_code', 'like', $patron)
                    ->orWhere('a.provider_code', 'like', $patron);
            });
        }

        // La página de ítems, por id ascendente, con el paginador de Laravel.
        $paginador = $consulta
            ->orderBy('i.id')
            ->paginate(self::por_pagina($por_pagina), [
                'i.id', 'i.estado', 'i.confianza', 'i.motivo', 'i.article_id',
                'a.name as articulo_nombre', 'a.bar_code as articulo_barras', 'a.provider_code as articulo_proveedor',
                'n.nombre as sugerida_categoria', 'sn.nombre as sugerida_subcategoria',
                'c.name as actual_categoria', 's.name as actual_subcategoria',
            ], 'page', max(1, (int) $pagina));

        $paginador->setCollection($paginador->getCollection()->map(function ($fila) {
            return self::payload_de_item($fila);
        }));

        return [
            'models'  => $paginador,
            'conteos' => self::conteos($run),
        ];
    }

    /**
     * El tamaño de página: uno de 25, 50 o 100; cualquier otro valor es 25.
     *
     * @param  int $por_pagina
     * @return int
     */
    protected static function por_pagina($por_pagina)
    {
        return in_array((int) $por_pagina, self::POR_PAGINA_PERMITIDAS, true) ? (int) $por_pagina : self::POR_PAGINA_DEFECTO;
    }

    /**
     * La fila de un ítem para la SPA (§6.6).
     *
     * @param  object $fila  Una fila de la consulta de `items_paginados`.
     * @return array
     */
    public static function payload_de_item($fila)
    {
        // Los nombres reales solo cuando el ítem ya se aplicó o se aprobó.
        $esta_asignado = in_array($fila->estado, CategoryProposalItem::ESTADOS_ASIGNADOS, true);

        return [
            'id'        => (int) $fila->id,
            'estado'    => (string) $fila->estado,
            'confianza' => (string) $fila->confianza,
            'motivo'    => $fila->motivo,
            'articulo'  => [
                'id'                  => (int) $fila->article_id,
                'nombre'              => is_null($fila->articulo_nombre) ? null : (string) $fila->articulo_nombre,
                'codigo_de_barras'    => CategoryProposalCatalogoHelper::texto_o_null($fila->articulo_barras),
                'codigo_de_proveedor' => CategoryProposalCatalogoHelper::texto_o_null($fila->articulo_proveedor),
            ],
            'sugerencia' => [
                'categoria'    => $fila->sugerida_categoria,
                'subcategoria' => $fila->sugerida_subcategoria,
            ],
            'actual'    => [
                'categoria'    => $esta_asignado ? $fila->actual_categoria : null,
                'subcategoria' => $esta_asignado ? $fila->actual_subcategoria : null,
            ],
        ];
    }
}
