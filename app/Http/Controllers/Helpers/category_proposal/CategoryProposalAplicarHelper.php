<?php

namespace App\Http\Controllers\Helpers\category_proposal;

use App\Models\Article;
use App\Models\Category;
use App\Models\CategoryProposal;
use App\Models\CategoryProposalItem;
use App\Models\CategoryProposalNode;
use App\Models\CategoryProposalRun;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Elegir un sistema de categorías, volver atrás y revisar lo dudoso (misión categorizacion-tres-modelos,
 * 5/10/2026). Plan §4.2, §4.3 y §4.4. Contrato B del plan, §6.4, §6.5 y §6.7.
 *
 * Es lo único que escribe `categories`, `sub_categories` y `articles` en este flujo (a través de
 * CategoriaRealHelper y CategoryProposalEscrituraHelper). Nada de lo que hizo la skill toca el catálogo
 * hasta que el dueño elige.
 *
 * Todas las acciones devuelven un arreglo con `status` (el HTTP que corresponde) y, si es un error,
 * `error` (el código para la máquina) y `message` (el texto en español para mostrar). Los controladores
 * solo traducen eso a una respuesta JSON.
 *
 * 🔴 LAS CUATRO REGLAS QUE NO SE NEGOCIAN:
 *  1. TODO O NADA. Elegir, volver atrás, aprobar y rechazar son UNA transacción con `lockForUpdate()` sobre
 *     la fila de la corrida; el estado se lee de la fila bloqueada. Una corrida nunca queda a medio aplicar:
 *     si algo falla, no se escribió nada.
 *  2. IDEMPOTENTES. El segundo pedido idéntico (el doble clic) responde 200 con `ya_estaba` y no reaplica ni
 *     duplica categorías: espera el candado, lee que ya está hecho y sale.
 *  3. TENENCIA. Todo id que llega (corrida, propuesta, ítem, artículo) se cruza con el `user_id` del dueño
 *     antes de leer o escribir; el ajeno se contesta IGUAL que el inexistente (404). Toda categoría o
 *     subcategoría que termina escrita en un artículo se verifica contra el dueño.
 *  4. EL DATO ANTERIOR SE GUARDA ANTES DE ESCRIBIR. El UPDATE masivo de `articles` no deja historial: lo
 *     que tenía cada artículo (`prev_*`) se copia a su ítem, y de ahí sale "volver atrás".
 *
 * "Sin categoría" en el sistema es NULL, 0 o una categoría borrada (R1 §6): se decide con
 * CategoriaRealHelper::tiene_categoria_viva(), nunca con `is_null` pelado.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class CategoryProposalAplicarHelper
{
    // Por qué no se puede volver atrás (el valor de `motivo` de `puede_cambiar` y del 409).
    const MOTIVO_NO_ESTA_ELEGIDA     = 'no_esta_elegida';
    const MOTIVO_HAY_REVISIONES      = 'hay_revisiones';
    const MOTIVO_ARTICULOS_EDITADOS  = 'articulos_editados';
    const MOTIVO_CATEGORIAS_EDITADAS = 'categorias_editadas';

    // Qué pasó con cada id de un aprobar / rechazar (interno: el controlador lo traduce a HTTP).
    const CODIGO_PROCESADO            = 'procesado';
    const CODIGO_YA_ESTABA            = 'ya_estaba';
    const CODIGO_NO_ENCONTRADO        = 'no_encontrado';
    const CODIGO_TODAVIA_NO_ELEGIDA   = 'todavia_no_elegida';
    const CODIGO_FUERA_DE_ESTADO      = 'fuera_de_estado';
    const CODIGO_ARTICULO_INEXISTENTE = 'articulo_inexistente';
    const CODIGO_DESTINO_INEXISTENTE  = 'destino_inexistente';

    // ---------------------------------------------------------------------------------------------
    // Volver atrás: la pregunta (una sola fuente de verdad)
    // ---------------------------------------------------------------------------------------------

    /**
     * ¿Se puede volver atrás (cambiar de sistema) en esta corrida? Regla (b): solo mientras nadie haya
     * aprobado ni editado nada. UNA sola fuente de verdad: la comparte `volver_atras` y el payload de
     * `actual`, y la SPA solo dibuja lo que dice.
     *
     * Se puede solo si, en este orden:
     *  - la corrida está `elegida` (`no_esta_elegida`);
     *  - nadie aprobó ni rechazó ningún dudoso (`hay_revisiones`: `revision_iniciada_at` es NULL);
     *  - todos los artículos que el aplicar asignó siguen con EXACTAMENTE la categoría y subcategoría que
     *    les puso, y (en un sistema nuevo) los que el aplicar dejó sin categoría siguen sin ella
     *    (`articulos_editados`: alguien los tocó a mano, y volver atrás pisaría ese cambio);
     *  - las categorías y subcategorías que CREÓ el aplicar siguen existiendo, con su nombre y colgando de
     *    la misma categoría, y no tienen artículos que no sean de la corrida (`categorias_editadas`).
     *
     * @param  \App\Models\CategoryProposalRun $run
     * @return array  ['puede' => bool, 'motivo' => null|'no_esta_elegida'|'hay_revisiones'|'articulos_editados'|'categorias_editadas']
     */
    public static function puede_cambiar(CategoryProposalRun $run)
    {
        if ($run->estado !== CategoryProposalRun::ESTADO_ELEGIDA || is_null($run->propuesta_elegida_id)) {
            return ['puede' => false, 'motivo' => self::MOTIVO_NO_ESTA_ELEGIDA];
        }

        if (!is_null($run->revision_iniciada_at)) {
            return ['puede' => false, 'motivo' => self::MOTIVO_HAY_REVISIONES];
        }

        // El dueño de la corrida, como entero.
        $dueno_id = (int) $run->user_id;

        // La propuesta que se eligió, como entero.
        $proposal_id = (int) $run->propuesta_elegida_id;

        if (self::hay_articulos_editados($dueno_id, $proposal_id)) {
            return ['puede' => false, 'motivo' => self::MOTIVO_ARTICULOS_EDITADOS];
        }

        if (self::hay_categorias_editadas($dueno_id, $proposal_id)) {
            return ['puede' => false, 'motivo' => self::MOTIVO_CATEGORIAS_EDITADAS];
        }

        return ['puede' => true, 'motivo' => null];
    }

    /**
     * ¿Alguien tocó a mano algún artículo que el aplicar escribió?
     *
     * Dos comprobaciones, las dos con una sola consulta cada una (nada de artículo por artículo):
     *  (a) los ítems `aplicada`: el artículo tiene que seguir con el destino que el aplicar guardó en los
     *      nodos (`real_category_id` y, si hay, `real_sub_category_id`), comparado EXACTO (NULL contra NULL);
     *  (b) en un sistema NUEVO, los ítems `a_revisar` y `sin_asignar`: el aplicar dejó esos artículos sin
     *      categoría, así que si hoy tienen una categoría viva es porque alguien se la puso. En "mantener"
     *      el aplicar no los toca, y por eso no se miran.
     * Un artículo borrado no cuenta como editado.
     *
     * @param  int $dueno_id
     * @param  int $proposal_id  La propuesta elegida.
     * @return bool
     */
    protected static function hay_articulos_editados($dueno_id, $proposal_id)
    {
        // (a) Asignados que ya no están donde el aplicar los puso. `<=>` no se usa a propósito: la
        // comparación NULL-segura se escribe a mano para que sirva igual en MySQL 8 y en MariaDB 11.
        $asignados_movidos = DB::table('category_proposal_items as i')
            ->join('articles as a', 'a.id', '=', 'i.article_id')
            ->join('category_proposal_nodes as n', 'n.id', '=', 'i.node_id')
            ->leftJoin('category_proposal_nodes as s', 's.id', '=', 'i.sub_node_id')
            ->where('i.proposal_id', $proposal_id)
            ->where('i.user_id', $dueno_id)
            ->where('i.estado', CategoryProposalItem::ESTADO_APLICADA)
            ->where('a.user_id', $dueno_id)
            ->whereNull('a.deleted_at')
            ->where(function ($q) {
                $q->whereRaw('a.category_id IS NULL')
                  ->orWhereRaw('n.real_category_id IS NULL')
                  ->orWhereRaw('a.category_id <> n.real_category_id')
                  ->orWhereRaw('(a.sub_category_id IS NULL AND s.real_sub_category_id IS NOT NULL)')
                  ->orWhereRaw('(a.sub_category_id IS NOT NULL AND s.real_sub_category_id IS NULL)')
                  ->orWhereRaw('a.sub_category_id <> s.real_sub_category_id');
            })
            ->exists();

        if ($asignados_movidos) {
            return true;
        }

        // (b) Solo en un sistema nuevo: lo que el aplicar dejó sin categoría y hoy tiene una viva.
        $tipo = CategoryProposal::where('id', $proposal_id)->value('tipo');

        if ($tipo !== CategoryProposal::TIPO_NUEVA) {
            return false;
        }

        // Las categorías vivas del dueño (decenas o cientos de ids; SoftDeletes deja afuera las de la papelera).
        // 🔴 NO se resuelve con un JOIN a `categories`: `articles` no tiene índice por `category_id`, y con el JOIN
        // MySQL puede planear la consulta empezando por las categorías y recorriendo todos los artículos del dueño
        // por cada una (medido con 10.000 artículos: 4,5 segundos, en cada carga de la solapa). Con la lista de
        // ids, el plan natural es los ítems de la propuesta (índice por estado) y cada artículo por su PK.
        $categorias_vivas = Category::where('user_id', $dueno_id)->pluck('id')->all();

        if (empty($categorias_vivas)) {
            return false;
        }

        return DB::table('category_proposal_items as i')
            ->join('articles as a', 'a.id', '=', 'i.article_id')
            ->where('i.proposal_id', $proposal_id)
            ->where('i.user_id', $dueno_id)
            ->whereIn('i.estado', [CategoryProposalItem::ESTADO_A_REVISAR, CategoryProposalItem::ESTADO_SIN_ASIGNAR])
            ->where('a.user_id', $dueno_id)
            ->whereNull('a.deleted_at')
            ->whereIn('a.category_id', $categorias_vivas)
            ->exists();
    }

    /**
     * ¿Alguien tocó las categorías o subcategorías que CREÓ el aplicar (`real_creado = 1`)? Las
     * reutilizadas no se miran: eran del dueño de antes y no las debe nada este flujo.
     *
     * Se considera tocada si: ya no existe (la mandaron a la papelera), tiene otro nombre del que le puso
     * el aplicar, una subcategoría cuelga de otra categoría, o hay artículos vivos en ella que no sean los
     * que asignó el aplicar (alguien cargó o movió uno a mano).
     *
     * @param  int $dueno_id
     * @param  int $proposal_id  La propuesta elegida.
     * @return bool
     */
    protected static function hay_categorias_editadas($dueno_id, $proposal_id)
    {
        // Los nodos de la propuesta elegida que CREÓ el aplicar (solo las columnas que hacen falta).
        $nodos = CategoryProposalNode::where('proposal_id', $proposal_id)
            ->where('user_id', $dueno_id)
            ->where('real_creado', 1)
            ->get(['id', 'parent_id', 'nombre', 'real_category_id', 'real_sub_category_id']);

        if ($nodos->isEmpty()) {
            return false;
        }

        // Las categorías que creó el aplicar: [id real => el nombre con el que se creó].
        $categorias_creadas = [];

        // Las subcategorías que creó el aplicar: [id real => ['nombre' => el nombre con el que se creó, 'category_id' => su categoría]].
        $subcategorias_creadas = [];

        foreach ($nodos as $nodo) {
            if (is_null($nodo->parent_id)) {
                $categorias_creadas[(int) $nodo->real_category_id] = CategoriaRealHelper::nombre_para_crear($nodo->nombre);
            } else {
                $subcategorias_creadas[(int) $nodo->real_sub_category_id] = [
                    'nombre'      => CategoriaRealHelper::nombre_para_crear($nodo->nombre),
                    'category_id' => (int) $nodo->real_category_id,
                ];
            }
        }

        // Tienen que seguir existiendo y con el mismo nombre.
        if (!empty($categorias_creadas)) {
            // Las categorías creadas que siguen vivas (SoftDeletes deja afuera las de la papelera), por id con su nombre actual.
            $vivas = Category::where('user_id', $dueno_id)
                ->whereIn('id', array_keys($categorias_creadas))
                ->pluck('name', 'id');

            foreach ($categorias_creadas as $id => $nombre) {
                if (!isset($vivas[$id]) || $vivas[$id] !== $nombre) {
                    return true;
                }
            }
        }

        if (!empty($subcategorias_creadas)) {
            // Las subcategorías creadas que siguen vivas, por id (con su nombre y su categoría actuales).
            $vivas = SubCategory::where('user_id', $dueno_id)
                ->whereIn('id', array_keys($subcategorias_creadas))
                ->get(['id', 'name', 'category_id'])
                ->keyBy('id');

            foreach ($subcategorias_creadas as $id => $esperada) {
                if (!isset($vivas[$id])
                    || $vivas[$id]->name !== $esperada['nombre']
                    || (int) $vivas[$id]->category_id !== $esperada['category_id']) {
                    return true;
                }
            }
        }

        // Sin artículos vivos que no sean los que asignó el aplicar. Los asignados ya se comprobaron
        // arriba (siguen en su destino), así que cualquier otro artículo en estas categorías llegó a mano.
        $propios_del_aplicar = function ($q) use ($proposal_id) {
            $q->select(DB::raw(1))
                ->from('category_proposal_items as i')
                ->whereColumn('i.article_id', 'articles.id')
                ->where('i.proposal_id', $proposal_id)
                ->where('i.estado', CategoryProposalItem::ESTADO_APLICADA);
        };

        return Article::where('user_id', $dueno_id)
            ->whereNull('deleted_at')
            ->whereNotExists($propios_del_aplicar)
            ->where(function ($q) use ($categorias_creadas, $subcategorias_creadas) {
                if (!empty($categorias_creadas)) {
                    $q->orWhereIn('category_id', array_keys($categorias_creadas));
                }

                if (!empty($subcategorias_creadas)) {
                    $q->orWhereIn('sub_category_id', array_keys($subcategorias_creadas));
                }
            })
            ->exists();
    }

    // ---------------------------------------------------------------------------------------------
    // Elegir un sistema
    // ---------------------------------------------------------------------------------------------

    /**
     * Elegir (aplicar) un sistema de categorías. SINCRÓNICO: una transacción con candado sobre la
     * corrida, todo o nada (el tope de artículos por corrida acota el tiempo). Plan §4.2.
     *
     * Precondiciones, cada una con su código: corrida del dueño (404), `lista` (409 `no_esta_lista`; si ya
     * está `elegida` con la MISMA propuesta responde 200 idempotente, con otra 409 `ya_hay_una_elegida`),
     * propuesta de esa corrida (404) y, si la propuesta es `nueva`, que el dueño no use márgenes por
     * categoría ni Tienda Nube (422 `bloqueado_por_margenes` / `bloqueado_por_tienda_nube`).
     *
     * @param  int   $user_id                     El dueño de la sesión.
     * @param  int   $run_id
     * @param  mixed $propuesta_id                Lo que mandó la SPA (se valida la forma acá).
     * @param  mixed $eliminar_categorias_vacias  Lo que mandó la SPA (opcional, booleano).
     * @param  int   $auth_user_id                La persona que eligió (`users.id` de la sesión).
     * @param  bool  $acceso_maestro              Si eligió con la sesión del acceso maestro.
     * @return array  200: ['status', 'run', 'resultado', 'ya_estaba'?] · error: ['status', 'error', 'message', ...]
     */
    public static function elegir($user_id, $run_id, $propuesta_id, $eliminar_categorias_vacias, $auth_user_id, $acceso_maestro)
    {
        // El dueño del comercio como modelo (null si el id no existe).
        $dueno = self::dueno_del_comercio($user_id);

        // La corrida tiene que ser de este dueño: la ajena y la inexistente se contestan IGUAL, y ANTES de
        // mirar la forma del cuerpo (así un 422 nunca delata que el id existe).
        if (is_null($dueno) || !CategoryProposalRun::where('user_id', $dueno->id)->where('id', (int) $run_id)->exists()) {
            return self::error(404, 'no_encontrado', 'No se encontró esa propuesta de categorías.');
        }

        // El cuerpo del pedido ya validado y normalizado (o el detalle de lo que está mal).
        $forma = self::validar_eleccion($propuesta_id, $eliminar_categorias_vacias);

        if (!empty($forma['detalle'])) {
            return self::error_de_validacion($forma['detalle']);
        }

        // Lo que devuelve la transacción: el resultado o el error de dominio (en ambos casos se hace commit; un error no escribió nada).
        $resultado = DB::transaction(function () use ($dueno, $run_id, $forma, $auth_user_id, $acceso_maestro) {
            return self::elegir_con_candado($dueno, (int) $run_id, $forma['propuesta_id'], $forma['eliminar'], $auth_user_id, $acceso_maestro);
        });

        // Lo que la escritura masiva se saltea (precios, Tienda Nube) va DESPUÉS del commit.
        if ($resultado['status'] === 200 && !empty($resultado['efectos'])) {
            CategoryProposalEscrituraHelper::efectos_tras_el_commit(
                $dueno->id,
                $resultado['efectos']['article_ids'],
                $resultado['efectos']['usa_margenes']
            );
        }

        unset($resultado['efectos']);

        return $resultado;
    }

    /**
     * El cuerpo de `elegir`, ya adentro de la transacción: bloquea la corrida, comprueba las
     * precondiciones sobre la fila bloqueada y aplica.
     *
     * @param  \App\Models\User $dueno
     * @param  int  $run_id
     * @param  int  $propuesta_id
     * @param  bool $eliminar_categorias_vacias
     * @param  int  $auth_user_id
     * @param  bool $acceso_maestro
     * @return array
     */
    protected static function elegir_con_candado(User $dueno, $run_id, $propuesta_id, $eliminar_categorias_vacias, $auth_user_id, $acceso_maestro)
    {
        // El candado: dos pedidos a la vez (el doble clic) se serializan acá, y el segundo lee el estado
        // que dejó el primero. El estado se decide SOBRE ESTA FILA, no sobre la lectura de antes.
        $run = CategoryProposalRun::where('user_id', $dueno->id)
            ->where('id', $run_id)
            ->lockForUpdate()
            ->first();

        if (is_null($run)) {
            return self::error(404, 'no_encontrado', 'No se encontró esa propuesta de categorías.');
        }

        if ($run->estado === CategoryProposalRun::ESTADO_ELEGIDA) {
            // El mismo pedido por segunda vez: ya está hecho, no se reaplica nada.
            if ((int) $run->propuesta_elegida_id === (int) $propuesta_id) {
                return ['status' => 200, 'ya_estaba' => true, 'run' => $run, 'resultado' => $run->resultado];
            }

            return self::error(409, 'ya_hay_una_elegida', 'Ya elegiste otro sistema de categorías. Para cambiar de sistema primero tenés que volver atrás.');
        }

        if ($run->estado !== CategoryProposalRun::ESTADO_LISTA) {
            return self::error(409, 'no_esta_lista', 'Estas propuestas todavía no están listas para elegir, o ya no están disponibles.');
        }

        // La propuesta tiene que ser de ESTA corrida y de este dueño (la de otra corrida es un 404).
        $propuesta = CategoryProposal::where('run_id', $run->id)
            ->where('user_id', $dueno->id)
            ->where('id', $propuesta_id)
            ->first();

        if (is_null($propuesta)) {
            return self::error(404, 'no_encontrado', 'No se encontró ese sistema de categorías.');
        }

        // Si la propuesta es "Mantener las mías" (usa las categorías existentes) y no un sistema nuevo.
        $es_mantener = $propuesta->tipo === CategoryProposal::TIPO_MANTENER;

        // Regla (a): un sistema NUEVO reasigna categorías en bloque y, si el precio depende de la
        // categoría o el dueño usa Tienda Nube, no se ofrece. "Mantener" nunca se bloquea.
        $usa_margenes = false;

        if ($es_mantener) {
            $usa_margenes = CategoryMargenesHelper::usa_margenes_por_categoria($dueno->id)['usa'];
        } else {
            // El bloqueo del dueño para los sistemas nuevos (márgenes por categoría y Tienda Nube).
            $bloqueo = CategoryMargenesHelper::bloqueo_para($dueno->id);

            if ($bloqueo['nuevo_modelo_bloqueado']) {
                return self::error_de_bloqueo($bloqueo['motivos']);
            }
        }

        // Las categorías reales del dueño (índice cargado una sola vez; busca por nombre, reutiliza o crea).
        $real = new CategoriaRealHelper($dueno);

        // 1) Las categorías y subcategorías reales (buscar por nombre y reutilizar, o crear).
        $resolucion = self::resolver_nodos_del_aplicar($propuesta, $dueno, $real, $es_mantener);

        // 2) Los artículos: los dos campos juntos, con lo anterior guardado en cada ítem.
        $asignacion = self::asignar_articulos($propuesta, $dueno, $real, $es_mantener, $resolucion['destino_categoria'], $resolucion['destino_subcategoria']);

        // 3) Las categorías anteriores que quedaron vacías (solo en un sistema nuevo y si se pidió).
        $eliminadas = [];

        if (!$es_mantener && $eliminar_categorias_vacias) {
            $eliminadas = self::mandar_a_la_papelera_las_vacias($dueno, $resolucion['reales_categorias'], $resolucion['reales_subcategorias']);
        }

        // Cuántas CATEGORÍAS (no subcategorías) se mandaron a la papelera: es lo que muestra el resumen.
        $categorias_eliminadas = 0;

        foreach ($eliminadas as $fila) {
            if ($fila['tipo'] === 'categoria') {
                $categorias_eliminadas++;
            }
        }

        // El resumen de lo que hizo el aplicar (contrato B §6.4): se guarda en la corrida y se devuelve.
        $resultado = [
            'categorias_creadas'      => $resolucion['categorias_creadas'],
            'categorias_reutilizadas' => $resolucion['categorias_reutilizadas'],
            'subcategorias_creadas'   => $resolucion['subcategorias_creadas'],
            'articulos_asignados'     => $asignacion['aplicada'],
            'a_revisar'               => $asignacion['a_revisar'],
            'sin_asignar'             => $asignacion['sin_asignar'],
            'pierden_categoria'       => $asignacion['pierden_categoria'],
            'categorias_eliminadas'   => $categorias_eliminadas,
        ];

        // 4) La corrida queda elegida, con quién y cuándo, y el resumen.
        $run->estado                     = CategoryProposalRun::ESTADO_ELEGIDA;
        $run->propuesta_elegida_id       = $propuesta->id;
        $run->elegida_at                 = now();
        $run->elegida_por                = $auth_user_id;
        $run->elegida_con_acceso_maestro = (bool) $acceso_maestro;
        $run->eliminar_categorias_vacias = !$es_mantener && $eliminar_categorias_vacias;
        $run->categorias_eliminadas      = empty($eliminadas) ? null : $eliminadas;
        $run->resultado                  = $resultado;
        $run->revision_iniciada_at       = null;
        $run->save();

        return [
            'status'    => 200,
            'run'       => $run,
            'resultado' => $resultado,
            'efectos'   => [
                'article_ids'  => $asignacion['ids_escritos'],
                'usa_margenes' => $usa_margenes,
            ],
        ];
    }

    /**
     * Valida la forma del cuerpo de `elegir`: `propuesta_id` entero positivo y
     * `eliminar_categorias_vacias` booleano opcional. Se arma a mano (nada de `$request->validate()`):
     * el 422 de este contrato es `{"error":"validacion","message","detalle":{campo:[mensajes]}}`.
     *
     * @param  mixed $propuesta_id
     * @param  mixed $eliminar
     * @return array  ['detalle' => [campo => [mensajes]], 'propuesta_id' => int|null, 'eliminar' => bool]
     */
    protected static function validar_eleccion($propuesta_id, $eliminar)
    {
        // Los errores de forma por campo: [campo => [mensajes]].
        $detalle = [];

        // Los booleanos y los arreglos no son ids aunque `filter_var` los convierta (`true` daría 1).
        $id = null;

        if (is_null($propuesta_id) || is_bool($propuesta_id) || is_array($propuesta_id)) {
            $detalle['propuesta_id'] = ['Elegí qué sistema de categorías querés usar.'];
        } else {
            $id = filter_var($propuesta_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id === false) {
                $id = null;
                $detalle['propuesta_id'] = ['El sistema de categorías elegido no es válido.'];
            }
        }

        // Opcional: sin valor es "no".
        $quiere_eliminar = false;

        if (!is_null($eliminar)) {
            $quiere_eliminar = filter_var($eliminar, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if (is_null($quiere_eliminar)) {
                $quiere_eliminar = false;
                $detalle['eliminar_categorias_vacias'] = ['Tiene que ser verdadero o falso.'];
            }
        }

        return ['detalle' => $detalle, 'propuesta_id' => $id, 'eliminar' => $quiere_eliminar];
    }

    /**
     * Paso 1 del aplicar: ata cada nodo del sistema elegido a una categoría o subcategoría REAL.
     *
     * Solo se resuelven los nodos que tienen al menos un ítem `segura` (y las categorías padre de las
     * subcategorías que se necesitan): un nodo que solo tiene dudosos no se crea acá sino al aprobar el
     * primero, porque el menú de la tienda NO esconde las categorías vacías y mostraría "0 prod.".
     * En un sistema `nueva` se busca por nombre normalizado y se reutiliza o se crea; en `mantener` se usa
     * la categoría existente del nodo (verificada del dueño y viva), nunca se crea nada.
     *
     * Un nodo que no se puede resolver (nombre inusable, categoría existente que ya no existe) queda sin
     * destino y sus ítems terminan `sin_asignar`.
     *
     * @param  \App\Models\CategoryProposal             $propuesta
     * @param  \App\Models\User                         $dueno
     * @param  \App\Http\Controllers\Helpers\category_proposal\CategoriaRealHelper $real
     * @param  bool                                     $es_mantener
     * @return array  ['destino_categoria' => [node_id => id real], 'destino_subcategoria' => [node_id => id real],
     *                 'reales_categorias' => [id => true], 'reales_subcategorias' => [id => true],
     *                 'categorias_creadas' => n, 'categorias_reutilizadas' => n, 'subcategorias_creadas' => n]
     */
    protected static function resolver_nodos_del_aplicar(CategoryProposal $propuesta, User $dueno, CategoriaRealHelper $real, $es_mantener)
    {
        // Todos los nodos de la propuesta (hasta 400): por orden, para que las categorías se creen en el
        // orden del árbol y los `num` correlativos queden en ese orden.
        $nodos = CategoryProposalNode::where('proposal_id', $propuesta->id)
            ->where('user_id', $dueno->id)
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        // Cada nodo de la propuesta por su id, para encontrar a los padres de las subcategorías.
        $por_id = [];

        foreach ($nodos as $nodo) {
            $por_id[(int) $nodo->id] = $nodo;
        }

        // Qué nodos necesitan existir: los que tienen al menos un ítem seguro. Una consulta agrupada.
        $filas = CategoryProposalItem::where('proposal_id', $propuesta->id)
            ->where('user_id', $dueno->id)
            ->where('confianza', CategoryProposalItem::CONFIANZA_SEGURA)
            ->where('estado', CategoryProposalItem::ESTADO_PROPUESTA)
            ->whereNotNull('node_id')
            ->groupBy('node_id', 'sub_node_id')
            ->get(['node_id', 'sub_node_id']);

        // Los nodos-categoría que necesitan existir (tienen al menos un ítem seguro): [id del nodo => true].
        $necesita_categoria = [];

        // Los nodos-subcategoría que necesitan existir: [id del nodo => true].
        $necesita_subcategoria = [];

        foreach ($filas as $fila) {
            $necesita_categoria[(int) $fila->node_id] = true;

            if (!is_null($fila->sub_node_id)) {
                $necesita_subcategoria[(int) $fila->sub_node_id] = true;
            }
        }

        // Una subcategoría necesaria arrastra a su categoría (por si un ítem la nombró sin su padre).
        foreach (array_keys($necesita_subcategoria) as $sub_node_id) {
            if (isset($por_id[$sub_node_id]) && !is_null($por_id[$sub_node_id]->parent_id)) {
                $necesita_categoria[(int) $por_id[$sub_node_id]->parent_id] = true;
            }
        }

        // Lo que devuelve: el destino real de cada nodo resuelto y los contadores del resumen.
        $salida = [
            'destino_categoria'       => [],
            'destino_subcategoria'    => [],
            'reales_categorias'       => [],
            'reales_subcategorias'    => [],
            'categorias_creadas'      => 0,
            'categorias_reutilizadas' => 0,
            'subcategorias_creadas'   => 0,
        ];

        // Primero las categorías (las subcategorías necesitan a su padre ya resuelto).
        foreach ($nodos as $nodo) {
            if (!is_null($nodo->parent_id) || !isset($necesita_categoria[(int) $nodo->id])) {
                continue;
            }

            // La categoría real del nodo (creada o reutilizada), o null si no se puede resolver.
            $categoria = self::asegurar_categoria_del_nodo($nodo, $real, $es_mantener);

            if (is_null($categoria)) {
                continue;
            }

            $salida['destino_categoria'][(int) $nodo->id]  = $categoria['id'];
            $salida['reales_categorias'][$categoria['id']] = true;

            if ($categoria['creada']) {
                $salida['categorias_creadas']++;
            } else {
                $salida['categorias_reutilizadas']++;
            }
        }

        foreach ($nodos as $nodo) {
            if (is_null($nodo->parent_id) || !isset($necesita_subcategoria[(int) $nodo->id])) {
                continue;
            }

            // El padre tiene que haberse resuelto: sin categoría real no hay subcategoría real.
            if (!isset($salida['destino_categoria'][(int) $nodo->parent_id])) {
                continue;
            }

            // La subcategoría real del nodo dentro de la categoría de su padre, o null si no se puede resolver.
            $sub = self::asegurar_subcategoria_del_nodo($nodo, $salida['destino_categoria'][(int) $nodo->parent_id], $real, $es_mantener);

            if (is_null($sub)) {
                continue;
            }

            $salida['destino_subcategoria'][(int) $nodo->id] = $sub['id'];
            $salida['reales_subcategorias'][$sub['id']]      = true;

            if ($sub['creada']) {
                $salida['subcategorias_creadas']++;
            }
        }

        return $salida;
    }

    /**
     * Asegura la categoría REAL de un nodo-categoría y la deja atada en el nodo (`real_category_id` y
     * `real_creado`). Lo comparten el aplicar y el aprobar: la misma rutina en los dos.
     *
     * Si el nodo ya está atado a una categoría que sigue viva y es del dueño, se usa esa (aprobar un
     * segundo dudoso del mismo nodo no crea nada). Si no: en `mantener` se usa la categoría existente del
     * nodo (verificada), y en `nueva` se busca por nombre normalizado o se crea.
     *
     * @param  \App\Models\CategoryProposalNode $nodo
     * @param  \App\Http\Controllers\Helpers\category_proposal\CategoriaRealHelper $real
     * @param  bool $es_mantener
     * @return array|null  ['id' => int, 'creada' => bool] o null si el nodo no se puede resolver.
     */
    protected static function asegurar_categoria_del_nodo(CategoryProposalNode $nodo, CategoriaRealHelper $real, $es_mantener)
    {
        if (!is_null($nodo->real_category_id) && $real->categoria_es_del_dueno($nodo->real_category_id)) {
            return ['id' => (int) $nodo->real_category_id, 'creada' => false];
        }

        if ($es_mantener) {
            // La categoría real del nodo: en "mantener" la existente (si sigue viva y es del dueño); en un sistema nuevo, la que se busca por nombre o se crea.
            $resuelta = $real->categoria_es_del_dueno($nodo->existing_category_id)
                ? ['id' => (int) $nodo->existing_category_id, 'creada' => false]
                : null;
        } else {
            $resuelta = $real->buscar_o_crear_categoria($nodo->nombre);
        }

        if (is_null($resuelta)) {
            return null;
        }

        $nodo->real_category_id = $resuelta['id'];
        $nodo->real_creado      = $resuelta['creada'];
        $nodo->save();

        return $resuelta;
    }

    /**
     * Asegura la subcategoría REAL de un nodo-subcategoría dentro de la categoría real ya resuelta y la
     * deja atada en el nodo (`real_category_id`, `real_sub_category_id` y `real_creado`).
     *
     * @param  \App\Models\CategoryProposalNode $nodo
     * @param  int  $category_real_id  La categoría real del nodo padre.
     * @param  \App\Http\Controllers\Helpers\category_proposal\CategoriaRealHelper $real
     * @param  bool $es_mantener
     * @return array|null  ['id' => int, 'creada' => bool] o null si no se puede resolver.
     */
    protected static function asegurar_subcategoria_del_nodo(CategoryProposalNode $nodo, $category_real_id, CategoriaRealHelper $real, $es_mantener)
    {
        if (!is_null($nodo->real_sub_category_id) && $real->subcategoria_pertenece_a($nodo->real_sub_category_id, $category_real_id)) {
            return ['id' => (int) $nodo->real_sub_category_id, 'creada' => false];
        }

        if ($es_mantener) {
            // La subcategoría real del nodo: en "mantener" la existente (si sigue viva y cuelga de esa categoría); en un sistema nuevo, la que se busca por nombre o se crea.
            $resuelta = $real->subcategoria_pertenece_a($nodo->existing_sub_category_id, $category_real_id)
                ? ['id' => (int) $nodo->existing_sub_category_id, 'creada' => false]
                : null;
        } else {
            $resuelta = $real->buscar_o_crear_subcategoria($category_real_id, $nodo->nombre);
        }

        if (is_null($resuelta)) {
            return null;
        }

        $nodo->real_category_id     = (int) $category_real_id;
        $nodo->real_sub_category_id = $resuelta['id'];
        $nodo->real_creado          = $resuelta['creada'];
        $nodo->save();

        return $resuelta;
    }

    /**
     * Paso 2 del aplicar: escribe `articles.category_id` y `sub_category_id` (los dos juntos) de lo que
     * la skill dio por seguro, y deja el resto como corresponde. Por lotes de
     * `catalogo_ia.articulos_por_lote_de_escritura` ítems, agrupando los UPDATE por destino.
     *
     * Qué le pasa a cada ítem (solo los que están en `propuesta`):
     *  - `segura` con destino: queda `aplicada` y el artículo recibe su categoría. 🔴 En "mantener" solo
     *    si el artículo sigue SIN categoría viva: "mantener" completa, no pisa; si alguien ya le puso
     *    una, el ítem queda `a_revisar` y la decide el dueño.
     *  - `segura` sin destino (el nodo no se pudo resolver) y `ninguna`: `sin_asignar`.
     *  - `dudosa`: `a_revisar`.
     *  - En un sistema NUEVO, los que quedan `a_revisar` o `sin_asignar` dejan al artículo SIN categoría
     *    (NULL, nunca 0) hasta que alguien lo apruebe; si tenía una categoría viva, la pierde y se cuenta
     *    en `pierden_categoria`. En "mantener" esos artículos no se tocan.
     *  - Un artículo borrado o de otro dueño: su ítem no se toca.
     * El artículo que ya está donde tiene que estar no se reescribe (cada escritura mueve `updated_at`).
     *
     * @param  \App\Models\CategoryProposal $propuesta
     * @param  \App\Models\User             $dueno
     * @param  \App\Http\Controllers\Helpers\category_proposal\CategoriaRealHelper $real
     * @param  bool  $es_mantener
     * @param  array $destino_categoria     [node_id => id de categoría real]
     * @param  array $destino_subcategoria  [node_id => id de subcategoría real]
     * @return array  ['aplicada' => n, 'a_revisar' => n, 'sin_asignar' => n, 'pierden_categoria' => n, 'ids_escritos' => [article_id, ...]]
     */
    protected static function asignar_articulos(CategoryProposal $propuesta, User $dueno, CategoriaRealHelper $real, $es_mantener, array $destino_categoria, array $destino_subcategoria)
    {
        // De a cuántos ítems se trabaja por lote (un lote es una lectura de artículos y un UPDATE por destino).
        $tam = max(1, (int) config('catalogo_ia.articulos_por_lote_de_escritura'));

        // Los contadores del resumen: cuántos ítems quedaron en cada estado y cuántos artículos perdieron la categoría que tenían.
        $conteo = ['aplicada' => 0, 'a_revisar' => 0, 'sin_asignar' => 0, 'pierden_categoria' => 0];

        // Los artículos cuya categoría se escribió: son los que el recálculo de precios y Tienda Nube necesitan.
        $ids_escritos = [];

        // El último ítem procesado: se pagina por id, así el cambio de `estado` no mueve la página.
        $ultimo_id = 0;

        // Los ítems a actualizar (estado y valores previos), agrupados por esos tres valores. Se juntan de TODOS los
        // lotes y se escriben al final: la cantidad de UPDATE depende de cuántos grupos distintos hay y no de cuántos
        // lotes se leyeron (con 108 destinos y 10 lotes, escribir por lote eran 1.080 UPDATE en vez de 108).
        $grupos_de_items = [];

        // Los artículos a escribir, agrupados por destino (categoría y subcategoría), también de todos los lotes.
        // Cada artículo está en un solo ítem de la propuesta (UNIQUE proposal_id + article_id), así que escribir
        // al final no cambia lo que leen los lotes siguientes.
        $grupos_de_articulos = [];

        do {
            // El próximo lote de ítems de la propuesta que todavía están en `propuesta` (solo las columnas que hacen falta).
            $items = CategoryProposalItem::where('proposal_id', $propuesta->id)
                ->where('user_id', $dueno->id)
                ->where('estado', CategoryProposalItem::ESTADO_PROPUESTA)
                ->where('id', '>', $ultimo_id)
                ->orderBy('id')
                ->limit($tam)
                ->get(['id', 'article_id', 'node_id', 'sub_node_id', 'confianza']);

            if ($items->isEmpty()) {
                break;
            }

            // Cuántos ítems trajo este lote: si trae menos que el tamaño del lote, era el último.
            $cantidad = $items->count();
            $ultimo_id = (int) $items->last()->id;

            // Lo que tiene hoy cada artículo del lote (los borrados y los ajenos no aparecen).
            $articulos = CategoryProposalEscrituraHelper::leer_articulos($dueno->id, $items->pluck('article_id')->all());

            foreach ($items as $item) {
                // El artículo del ítem, como entero.
                $article_id = (int) $item->article_id;

                if (!isset($articulos[$article_id])) {
                    continue;
                }

                // Lo que tiene hoy el artículo: categoría y subcategoría.
                $actual = $articulos[$article_id];

                // Si el artículo ya tiene una categoría viva del dueño (NULL, 0 o una borrada cuentan como sin categoría).
                $tiene_viva = $real->tiene_categoria_viva($actual['category_id']);

                // La categoría real de destino del ítem (null si no la tiene resuelta o le falta la subcategoría).
                $categoria_destino = null;

                // La subcategoría real de destino (null si el ítem no pide ninguna).
                $sub_destino = null;

                if (!is_null($item->node_id) && isset($destino_categoria[(int) $item->node_id])) {
                    $categoria_destino = $destino_categoria[(int) $item->node_id];

                    if (!is_null($item->sub_node_id)) {
                        if (isset($destino_subcategoria[(int) $item->sub_node_id])) {
                            $sub_destino = $destino_subcategoria[(int) $item->sub_node_id];
                        } else {
                            // Pide una subcategoría que no se pudo resolver: no se aplica a medias.
                            $categoria_destino = null;
                        }
                    }
                }

                // Qué se le escribe al artículo: ['category_id' => .., 'sub_category_id' => ..] o `false` si no se toca.
                $escribir = false;

                // El estado que le toca al ítem: lo decide la confianza de la skill y si el destino se pudo resolver.
                $estado = CategoryProposalItem::ESTADO_SIN_ASIGNAR;

                if ($item->confianza === CategoryProposalItem::CONFIANZA_SEGURA && !is_null($categoria_destino)) {
                    if ($es_mantener && $tiene_viva) {
                        // "Mantener" completa, no pisa: el artículo ya tiene una categoría y la decide el dueño.
                        $estado = CategoryProposalItem::ESTADO_A_REVISAR;
                    } else {
                        $estado = CategoryProposalItem::ESTADO_APLICADA;

                        // Solo se reescribe si hoy no está exactamente ahí.
                        if (!self::mismo_destino($actual['category_id'], $categoria_destino) || !self::mismo_destino($actual['sub_category_id'], $sub_destino)) {
                            $escribir = ['category_id' => $categoria_destino, 'sub_category_id' => $sub_destino];
                        }
                    }
                } else {
                    $estado = $item->confianza === CategoryProposalItem::CONFIANZA_DUDOSA
                        ? CategoryProposalItem::ESTADO_A_REVISAR
                        : CategoryProposalItem::ESTADO_SIN_ASIGNAR;

                    // En un sistema nuevo el artículo queda sin categoría hasta que alguien lo apruebe.
                    if (!$es_mantener && $tiene_viva) {
                        $escribir = ['category_id' => null, 'sub_category_id' => null];
                        $conteo['pierden_categoria']++;
                    }
                }

                $conteo[$estado]++;

                // El ítem guarda lo que tenía el artículo ANTES de escribir.
                $clave_del_grupo = (is_null($actual['category_id']) ? 'N' : $actual['category_id']).'|'
                    .(is_null($actual['sub_category_id']) ? 'N' : $actual['sub_category_id']).'|'.$estado;

                if (!isset($grupos_de_items[$clave_del_grupo])) {
                    $grupos_de_items[$clave_del_grupo] = [
                        'estado'               => $estado,
                        'prev_category_id'     => $actual['category_id'],
                        'prev_sub_category_id' => $actual['sub_category_id'],
                        'ids'                  => [],
                    ];
                }

                $grupos_de_items[$clave_del_grupo]['ids'][] = (int) $item->id;

                if ($escribir !== false) {
                    CategoryProposalEscrituraHelper::sumar_a_grupo($grupos_de_articulos, $escribir['category_id'], $escribir['sub_category_id'], $article_id);
                    $ids_escritos[] = $article_id;
                }
            }

        } while ($cantidad >= $tam);

        // 1º los ítems (estado y valores previos), 2º los artículos: lo anterior queda guardado ANTES de escribir.
        // Cada grupo se parte en tandas del tamaño del lote: un `IN` de miles de ids se planifica peor.
        foreach ($grupos_de_items as $grupo) {
            foreach (array_chunk($grupo['ids'], $tam) as $tanda) {
                CategoryProposalItem::where('proposal_id', $propuesta->id)
                    ->where('user_id', $dueno->id)
                    ->whereIn('id', $tanda)
                    ->update([
                        'estado'               => $grupo['estado'],
                        'prev_category_id'     => $grupo['prev_category_id'],
                        'prev_sub_category_id' => $grupo['prev_sub_category_id'],
                    ]);
            }
        }

        CategoryProposalEscrituraHelper::escribir_destinos($dueno->id, array_values($grupos_de_articulos));

        $conteo['ids_escritos'] = $ids_escritos;

        return $conteo;
    }

    /**
     * Comparación EXACTA y NULL-segura de dos ids de categoría: NULL solo es igual a NULL (un 0 no es
     * un NULL: el sistema los trata igual al leer, pero al escribir NULL se quiere NULL).
     *
     * @param  int|null $a
     * @param  int|null $b
     * @return bool
     */
    protected static function mismo_destino($a, $b)
    {
        if (is_null($a) || is_null($b)) {
            return is_null($a) && is_null($b);
        }

        return (int) $a === (int) $b;
    }

    /**
     * Paso 3 del aplicar: manda a la papelera las categorías y subcategorías vivas del dueño que NO son
     * del sistema elegido y quedaron sin artículos. Plan §4.2.3.
     *
     * "Del sistema elegido" = las `real_*` de este aplicar (creadas o reutilizadas): esas no se tocan
     * aunque queden vacías. Una categoría está vacía si no tiene artículos vivos ni en ella ni en ninguna de
     * sus subcategorías. Una subcategoría suelta (aunque su categoría se conserve) también se manda a la
     * papelera si quedó vacía. Se respeta `La de siempre` (la tienda la esconde por nombre) y se usa
     * `->delete()` de Eloquent (soft delete, con su auditoría; no hay observers de `deleted`).
     *
     * @param  \App\Models\User $dueno
     * @param  array $reales_categorias    [id => true] de las categorías del sistema elegido.
     * @param  array $reales_subcategorias [id => true] de las subcategorías del sistema elegido.
     * @return array  [['tipo' => 'categoria'|'subcategoria', 'id' => n], ...] lo que se mandó a la papelera.
     */
    protected static function mandar_a_la_papelera_las_vacias(User $dueno, array $reales_categorias, array $reales_subcategorias)
    {
        // Las categorías que se podrían eliminar (no son del sistema elegido ni `La de siempre`): [id => ids de sus subcategorías].
        $categorias_candidatas = [];

        foreach (Category::where('user_id', $dueno->id)->get(['id', 'name']) as $categoria) {
            // El id de la categoría, como entero.
            $id = (int) $categoria->id;

            // La del sistema elegido y `La de siempre` se conservan.
            if (isset($reales_categorias[$id]) || CategoryProposalNombreHelper::clave_de($categoria->name) === CategoryProposalNombreHelper::CLAVE_RESERVADA) {
                continue;
            }

            $categorias_candidatas[$id] = [];
        }

        // Todas las subcategorías vivas del dueño con su categoría, menos las del sistema elegido.
        $subcategorias_candidatas = [];

        foreach (SubCategory::where('user_id', $dueno->id)->get(['id', 'category_id']) as $sub) {
            $id = (int) $sub->id;

            if (isset($reales_subcategorias[$id])) {
                continue;
            }

            $subcategorias_candidatas[$id] = (int) $sub->category_id;

            if (isset($categorias_candidatas[(int) $sub->category_id])) {
                $categorias_candidatas[(int) $sub->category_id][] = $id;
            }
        }

        // Los ids con artículos vivos, en dos consultas acotadas a los candidatos.
        $categorias_con_articulos = [];

        // Los ids de subcategoría que tienen artículos vivos: [id => posición] (resultado de `array_flip`).
        $subcategorias_con_articulos = [];

        if (!empty($categorias_candidatas)) {
            $categorias_con_articulos = array_flip(Article::where('user_id', $dueno->id)
                ->whereNull('deleted_at')
                ->whereIn('category_id', array_keys($categorias_candidatas))
                ->distinct()
                ->pluck('category_id')
                ->all());
        }

        if (!empty($subcategorias_candidatas)) {
            $subcategorias_con_articulos = array_flip(Article::where('user_id', $dueno->id)
                ->whereNull('deleted_at')
                ->whereIn('sub_category_id', array_keys($subcategorias_candidatas))
                ->distinct()
                ->pluck('sub_category_id')
                ->all());
        }

        // Qué se elimina: las subcategorías vacías y las categorías vacías con TODAS sus subcategorías vacías.
        $subcategorias_a_borrar = [];

        // Las categorías vacías que se mandan a la papelera: [id => true].
        $categorias_a_borrar = [];

        foreach ($subcategorias_candidatas as $sub_id => $category_id) {
            if (!isset($subcategorias_con_articulos[$sub_id])) {
                $subcategorias_a_borrar[$sub_id] = true;
            }
        }

        foreach ($categorias_candidatas as $category_id => $subs) {
            if (isset($categorias_con_articulos[$category_id])) {
                continue;
            }

            // Si alguna subcategoría de la categoría tiene artículos vivos (entonces la categoría no está vacía).
            $tiene_subs_con_articulos = false;

            foreach ($subs as $sub_id) {
                if (isset($subcategorias_con_articulos[$sub_id])) {
                    $tiene_subs_con_articulos = true;
                    break;
                }
            }

            if (!$tiene_subs_con_articulos) {
                $categorias_a_borrar[$category_id] = true;
            }
        }

        // Lo que se mandó a la papelera: [['tipo' => 'categoria'|'subcategoria', 'id' => n], ...].
        $eliminadas = [];

        // Primero las subcategorías, después las categorías.
        if (!empty($subcategorias_a_borrar)) {
            foreach (SubCategory::where('user_id', $dueno->id)->whereIn('id', array_keys($subcategorias_a_borrar))->get() as $sub) {
                $sub->delete();
                $eliminadas[] = ['tipo' => 'subcategoria', 'id' => (int) $sub->id];
            }
        }

        if (!empty($categorias_a_borrar)) {
            foreach (Category::where('user_id', $dueno->id)->whereIn('id', array_keys($categorias_a_borrar))->get() as $categoria) {
                $categoria->delete();
                $eliminadas[] = ['tipo' => 'categoria', 'id' => (int) $categoria->id];
            }
        }

        return $eliminadas;
    }

    // ---------------------------------------------------------------------------------------------
    // Volver atrás
    // ---------------------------------------------------------------------------------------------

    /**
     * Volver atrás: deshace la elección (cambiar de sistema). Plan §4.4. Solo si `puede_cambiar` lo
     * permite, y todo en una transacción con candado.
     *
     * Idempotente: si la corrida ya está `lista` (el segundo clic, o nunca se eligió) responde 200
     * `ya_estaba` sin tocar nada. Si no está elegida ni lista (preparando o descartada), 409
     * `no_se_puede_volver_atras` con motivo `no_esta_elegida`.
     *
     * Qué deshace: devuelve a cada artículo lo que tenía (`prev_*`), manda a la papelera las categorías y
     * subcategorías que creó el aplicar, restaura las que el aplicar mandó a la papelera, deja todos los
     * ítems en `propuesta`, desata los nodos (`real_*`) y la corrida vuelve a `lista`.
     *
     * @param  int $user_id  El dueño de la sesión.
     * @param  int $run_id
     * @return array  200: ['status', 'run', 'ya_estaba'?] · error: ['status', 'error', 'message', 'motivo'?]
     */
    public static function volver_atras($user_id, $run_id)
    {
        // El dueño del comercio como modelo (null si el id no existe).
        $dueno = self::dueno_del_comercio($user_id);

        if (is_null($dueno)) {
            return self::error(404, 'no_encontrado', 'No se encontró esa propuesta de categorías.');
        }

        // Lo que devuelve la transacción: la corrida deshecha o el error de dominio.
        $resultado = DB::transaction(function () use ($dueno, $run_id) {
            // El candado de la corrida: el estado y la pregunta `puede_cambiar` se leen de ESTA fila.
            $run = CategoryProposalRun::where('user_id', $dueno->id)
                ->where('id', (int) $run_id)
                ->lockForUpdate()
                ->first();

            if (is_null($run)) {
                return self::error(404, 'no_encontrado', 'No se encontró esa propuesta de categorías.');
            }

            // Ya está en `lista`: el estado que se pide ya se cumple (el segundo clic del mismo pedido).
            if ($run->estado === CategoryProposalRun::ESTADO_LISTA) {
                return ['status' => 200, 'ya_estaba' => true, 'run' => $run];
            }

            // El veredicto de `puede_cambiar`, leído de la corrida bloqueada.
            $puede = self::puede_cambiar($run);

            if (!$puede['puede']) {
                return self::error(409, 'no_se_puede_volver_atras', self::mensaje_del_motivo($puede['motivo']), ['motivo' => $puede['motivo']]);
            }

            return self::deshacer_la_eleccion($run, $dueno);
        });

        if ($resultado['status'] === 200 && !empty($resultado['efectos'])) {
            CategoryProposalEscrituraHelper::efectos_tras_el_commit($dueno->id, $resultado['efectos']['article_ids'], null);
        }

        unset($resultado['efectos']);

        return $resultado;
    }

    /**
     * El cuerpo de `volver_atras`, ya con el candado y la autorización de `puede_cambiar`.
     *
     * @param  \App\Models\CategoryProposalRun $run   La corrida bloqueada y `elegida`.
     * @param  \App\Models\User                $dueno
     * @return array
     */
    protected static function deshacer_la_eleccion(CategoryProposalRun $run, User $dueno)
    {
        // La propuesta que se eligió, como entero.
        $proposal_id = (int) $run->propuesta_elegida_id;

        // El tipo de la propuesta elegida (`nueva` o `mantener`).
        $tipo = CategoryProposal::where('id', $proposal_id)->value('tipo');

        // Si era "Mantener las mías": en ese caso el aplicar no tocó a los dudosos ni a los sin ubicar.
        $es_mantener = $tipo === CategoryProposal::TIPO_MANTENER;

        // 1) A cada artículo lo que tenía antes. Antes de mandar nada a la papelera: así las categorías
        //    creadas ya no tienen artículos cuando se las borra.
        $ids_restaurados = self::restaurar_articulos($proposal_id, $dueno, $es_mantener);

        // 2) Las categorías y subcategorías que CREÓ el aplicar van a la papelera (las reutilizadas no).
        $nodos_creados = CategoryProposalNode::where('proposal_id', $proposal_id)
            ->where('user_id', $dueno->id)
            ->where('real_creado', 1)
            ->get(['id', 'parent_id', 'real_category_id', 'real_sub_category_id']);

        // Los ids de las subcategorías que creó el aplicar.
        $subcategorias_creadas = [];

        // Los ids de las categorías que creó el aplicar.
        $categorias_creadas = [];

        foreach ($nodos_creados as $nodo) {
            if (is_null($nodo->parent_id)) {
                $categorias_creadas[] = (int) $nodo->real_category_id;
            } else {
                $subcategorias_creadas[] = (int) $nodo->real_sub_category_id;
            }
        }

        if (!empty($subcategorias_creadas)) {
            foreach (SubCategory::where('user_id', $dueno->id)->whereIn('id', $subcategorias_creadas)->get() as $sub) {
                $sub->delete();
            }
        }

        if (!empty($categorias_creadas)) {
            foreach (Category::where('user_id', $dueno->id)->whereIn('id', $categorias_creadas)->get() as $categoria) {
                $categoria->delete();
            }
        }

        // 3) Las que el aplicar mandó a la papelera vuelven.
        $categorias_a_restaurar = [];

        // Los ids de las subcategorías que el aplicar mandó a la papelera y ahora vuelven.
        $subcategorias_a_restaurar = [];

        if (is_array($run->categorias_eliminadas)) {
            foreach ($run->categorias_eliminadas as $fila) {
                if (!isset($fila['tipo']) || !isset($fila['id'])) {
                    continue;
                }

                if ($fila['tipo'] === 'categoria') {
                    $categorias_a_restaurar[] = (int) $fila['id'];
                } elseif ($fila['tipo'] === 'subcategoria') {
                    $subcategorias_a_restaurar[] = (int) $fila['id'];
                }
            }
        }

        if (!empty($categorias_a_restaurar)) {
            foreach (Category::onlyTrashed()->where('user_id', $dueno->id)->whereIn('id', $categorias_a_restaurar)->get() as $categoria) {
                $categoria->restore();
            }
        }

        if (!empty($subcategorias_a_restaurar)) {
            foreach (SubCategory::onlyTrashed()->where('user_id', $dueno->id)->whereIn('id', $subcategorias_a_restaurar)->get() as $sub) {
                $sub->restore();
            }
        }

        // 4) Los ítems vuelven a `propuesta` (sin valores previos ni revisor) y los nodos se desatan.
        CategoryProposalItem::where('proposal_id', $proposal_id)
            ->where('user_id', $dueno->id)
            ->update([
                'estado'               => CategoryProposalItem::ESTADO_PROPUESTA,
                'prev_category_id'     => null,
                'prev_sub_category_id' => null,
                'revisado_por'         => null,
                'revisado_at'          => null,
            ]);

        CategoryProposalNode::where('proposal_id', $proposal_id)
            ->where('user_id', $dueno->id)
            ->update([
                'real_category_id'     => null,
                'real_sub_category_id' => null,
                'real_creado'          => 0,
            ]);

        // 5) La corrida vuelve a `lista`, sin elección.
        $run->estado                     = CategoryProposalRun::ESTADO_LISTA;
        $run->propuesta_elegida_id       = null;
        $run->elegida_at                 = null;
        $run->elegida_por                = null;
        $run->elegida_con_acceso_maestro = false;
        $run->eliminar_categorias_vacias = false;
        $run->categorias_eliminadas      = null;
        $run->resultado                  = null;
        $run->revision_iniciada_at       = null;
        $run->save();

        return [
            'status'  => 200,
            'run'     => $run,
            'efectos' => ['article_ids' => $ids_restaurados],
        ];
    }

    /**
     * Devuelve a los artículos lo que tenían antes de elegir (`prev_*` de su ítem), por lotes y agrupando
     * los UPDATE por valor. Solo se reescribe el artículo que hoy es distinto de lo que tenía.
     *
     * Qué ítems se miran: los `aplicada` siempre y, en un sistema NUEVO, también los `a_revisar` y
     * `sin_asignar` (el aplicar los dejó sin categoría). En "mantener" el aplicar no tocó esos, así que
     * tampoco se restauran. `puede_cambiar` ya comprobó que ninguno fue editado a mano.
     *
     * @param  int              $proposal_id
     * @param  \App\Models\User $dueno
     * @param  bool             $es_mantener
     * @return array  Los ids de los artículos que se reescribieron.
     */
    protected static function restaurar_articulos($proposal_id, User $dueno, $es_mantener)
    {
        // De a cuántos ítems se trabaja por lote (un lote es una lectura de artículos y un UPDATE por destino).
        $tam = max(1, (int) config('catalogo_ia.articulos_por_lote_de_escritura'));

        // Los estados de ítem cuyos artículos hay que devolver a lo que tenían.
        $estados = [CategoryProposalItem::ESTADO_APLICADA];

        if (!$es_mantener) {
            $estados[] = CategoryProposalItem::ESTADO_A_REVISAR;
            $estados[] = CategoryProposalItem::ESTADO_SIN_ASIGNAR;
        }

        // Los artículos que se reescribieron, para el recálculo de precios y Tienda Nube.
        $ids_restaurados = [];

        // El último ítem procesado: se pagina por id, así el cambio de estado no mueve la página.
        $ultimo_id = 0;

        // Los artículos a reescribir, agrupados por el valor que se les devuelve, de TODOS los lotes: se escriben al
        // final, un UPDATE por valor (y no uno por valor y por lote).
        $grupos = [];

        do {
            // El próximo lote de ítems de la propuesta elegida con esos estados (solo las columnas que hacen falta).
            $items = CategoryProposalItem::where('proposal_id', $proposal_id)
                ->where('user_id', $dueno->id)
                ->whereIn('estado', $estados)
                ->where('id', '>', $ultimo_id)
                ->orderBy('id')
                ->limit($tam)
                ->get(['id', 'article_id', 'prev_category_id', 'prev_sub_category_id']);

            if ($items->isEmpty()) {
                break;
            }

            // Cuántos ítems trajo este lote: si trae menos que el tamaño del lote, era el último.
            $cantidad = $items->count();
            $ultimo_id = (int) $items->last()->id;

            // Lo que tiene hoy cada artículo del lote (los borrados y los ajenos no aparecen).
            $articulos = CategoryProposalEscrituraHelper::leer_articulos($dueno->id, $items->pluck('article_id')->all());

            foreach ($items as $item) {
                // El artículo del ítem, como entero.
                $article_id = (int) $item->article_id;

                if (!isset($articulos[$article_id])) {
                    continue;
                }

                // La categoría que tenía el artículo antes de elegir (NULL, 0 o un id).
                $antes_categoria = is_null($item->prev_category_id) ? null : (int) $item->prev_category_id;

                // La subcategoría que tenía el artículo antes de elegir.
                $antes_sub = is_null($item->prev_sub_category_id) ? null : (int) $item->prev_sub_category_id;

                if (self::mismo_destino($articulos[$article_id]['category_id'], $antes_categoria)
                    && self::mismo_destino($articulos[$article_id]['sub_category_id'], $antes_sub)) {
                    continue;
                }

                CategoryProposalEscrituraHelper::sumar_a_grupo($grupos, $antes_categoria, $antes_sub, $article_id);
                $ids_restaurados[] = $article_id;
            }
        } while ($cantidad >= $tam);

        CategoryProposalEscrituraHelper::escribir_destinos($dueno->id, array_values($grupos));

        return $ids_restaurados;
    }

    // ---------------------------------------------------------------------------------------------
    // Revisar lo dudoso: aprobar y rechazar
    // ---------------------------------------------------------------------------------------------

    /**
     * Aprobar UN ítem a revisar: el artículo recibe la categoría que sugería la skill. Plan §4.3.
     *
     * Idempotente: aprobar uno ya aprobado responde 200 `ya_estaba` sin reaplicar.
     *
     * @param  int $user_id       El dueño de la sesión.
     * @param  int $item_id
     * @param  int $auth_user_id  La persona que aprobó.
     * @return array  200: ['status', 'item' (la fila del contrato §6.6), 'run', 'ya_estaba'?] · error: ['status', 'error', 'message']
     */
    public static function aprobar($user_id, $item_id, $auth_user_id)
    {
        return self::revisar_uno('aprobar', $user_id, $item_id, $auth_user_id);
    }

    /**
     * Rechazar UN ítem a revisar: el artículo sigue sin categoría y pasa a la solapa "Sin categoría".
     * Idempotente como `aprobar`.
     *
     * @param  int $user_id
     * @param  int $item_id
     * @param  int $auth_user_id
     * @return array  Igual que `aprobar`.
     */
    public static function rechazar($user_id, $item_id, $auth_user_id)
    {
        return self::revisar_uno('rechazar', $user_id, $item_id, $auth_user_id);
    }

    /**
     * Aprobar o rechazar VARIOS ítems en un pedido (hasta `catalogo_ia.ids_por_lote_de_revision_maximo`).
     * Todo el lote es una transacción. Los ids de otro dueño, inexistentes o fuera de estado (ya
     * aprobados, rechazados, o de una corrida que no está elegida) se omiten y se cuentan, sin delatar
     * cuál es cuál.
     *
     * @param  string $accion       'aprobar' | 'rechazar'
     * @param  int    $user_id
     * @param  array  $ids          Ids de ítems, ya normalizados a enteros positivos.
     * @param  int    $auth_user_id
     * @return array  ['status' => 200, 'procesados' => n, 'omitidos' => m, 'run' => CategoryProposalRun|null]
     */
    public static function en_lote($accion, $user_id, array $ids, $auth_user_id)
    {
        // Qué pasó con cada id del lote y cuántos se procesaron.
        $resultado = self::revisar($accion, $user_id, $ids, $auth_user_id);

        return [
            'status'     => 200,
            'procesados' => $resultado['procesados'],
            'omitidos'   => count($resultado['por_id']) - $resultado['procesados'],
            'run'        => $resultado['run'],
        ];
    }

    /**
     * La corrida vigente (no descartada) del dueño, para devolver los conteos cuando un lote no tocó
     * ninguna corrida propia (todo omitido).
     *
     * @param  int $user_id
     * @return \App\Models\CategoryProposalRun|null
     */
    public static function corrida_vigente($user_id)
    {
        // El dueño del comercio como modelo (null si el id no existe).
        $dueno = self::dueno_del_comercio($user_id);

        if (is_null($dueno)) {
            return null;
        }

        return CategoryProposalRun::where('user_id', $dueno->id)->vigentes()->orderByDesc('id')->first();
    }

    /**
     * Aprobar o rechazar un solo ítem: es el lote de un id, traducido a los códigos del contrato.
     *
     * @param  string $accion
     * @param  int    $user_id
     * @param  int    $item_id
     * @param  int    $auth_user_id
     * @return array
     */
    protected static function revisar_uno($accion, $user_id, $item_id, $auth_user_id)
    {
        // El ítem como entero.
        $item_id = (int) $item_id;

        // El lote de un solo id: qué pasó con ese ítem.
        $resultado = self::revisar($accion, $user_id, [$item_id], $auth_user_id);

        // Qué pasó con ese ítem (uno de los CODIGO_*).
        $codigo = $resultado['por_id'][$item_id];

        switch ($codigo) {
            case self::CODIGO_PROCESADO:
            case self::CODIGO_YA_ESTABA:

                // La respuesta de éxito: la fila actualizada del ítem y la corrida (para los conteos).
                $respuesta = [
                    'status' => 200,
                    'item'   => self::fila_de_item(CategoryProposalItem::where('user_id', self::dueno_del_comercio($user_id)->id)->where('id', $item_id)->first()),
                    'run'    => $resultado['run'],
                ];

                if ($codigo === self::CODIGO_YA_ESTABA) {
                    $respuesta['ya_estaba'] = true;
                }

                return $respuesta;

            case self::CODIGO_TODAVIA_NO_ELEGIDA:
                return self::error(409, 'todavia_no_elegida', 'Todavía no elegiste un sistema de categorías: no hay nada para revisar.');

            case self::CODIGO_FUERA_DE_ESTADO:
                return self::error(409, 'no_esta_a_revisar', 'Este artículo ya no está esperando revisión.');

            case self::CODIGO_ARTICULO_INEXISTENTE:
                return self::error(409, 'articulo_inexistente', 'El artículo de esta fila ya no existe.');

            case self::CODIGO_DESTINO_INEXISTENTE:
                return self::error(409, 'categoria_inexistente', 'La categoría sugerida para este artículo ya no existe: no se puede aprobar. Podés rechazarlo.');

            default:
                return self::error(404, 'no_encontrado', 'No se encontró ese artículo de la revisión.');
        }
    }

    /**
     * El núcleo de aprobar y rechazar (uno o varios): UNA transacción con candado.
     *
     * @param  string $accion        'aprobar' | 'rechazar'
     * @param  int    $user_id
     * @param  array  $ids
     * @param  int    $auth_user_id
     * @return array  ['status' => 200, 'por_id' => [id => CODIGO_*], 'procesados' => n, 'run' => CategoryProposalRun|null]
     */
    protected static function revisar($accion, $user_id, array $ids, $auth_user_id)
    {
        // El dueño del comercio como modelo (null si el id no existe).
        $dueno = self::dueno_del_comercio($user_id);

        // Hasta que se demuestre lo contrario, todo id es "no encontrado": el ajeno y el inexistente son lo mismo.
        $por_id = [];

        foreach ($ids as $id) {
            $por_id[(int) $id] = self::CODIGO_NO_ENCONTRADO;
        }

        if (is_null($dueno) || empty($por_id)) {
            return ['status' => 200, 'por_id' => $por_id, 'procesados' => 0, 'run' => null];
        }

        // Lo que devuelve la transacción: qué pasó con cada id y la corrida.
        $resultado = DB::transaction(function () use ($accion, $dueno, $por_id, $auth_user_id) {
            return self::revisar_con_candado($accion, $dueno, $por_id, $auth_user_id);
        });

        if (!empty($resultado['efectos'])) {
            CategoryProposalEscrituraHelper::efectos_tras_el_commit($dueno->id, $resultado['efectos']['article_ids'], null);
        }

        unset($resultado['efectos']);

        return $resultado;
    }

    /**
     * El cuerpo de `revisar`, ya adentro de la transacción.
     *
     * Orden de los candados (siempre el mismo, para no entrar en deadlock con `elegir` y `volver_atras`):
     * primero las corridas, por id ascendente, y después los ítems, por id ascendente. El estado de cada
     * ítem se lee DESPUÉS de bloquearlo.
     *
     * @param  string $accion
     * @param  \App\Models\User $dueno
     * @param  array  $por_id        [id => CODIGO_NO_ENCONTRADO] de todos los ids pedidos.
     * @param  int    $auth_user_id
     * @return array
     */
    protected static function revisar_con_candado($accion, User $dueno, array $por_id, $auth_user_id)
    {
        // Los ids de ítems que se pidieron.
        $ids = array_keys($por_id);

        // Los ítems del dueño (sin candado todavía) para saber a qué corridas pertenecen.
        $previos = CategoryProposalItem::where('user_id', $dueno->id)->whereIn('id', $ids)->get(['id', 'proposal_id']);

        if ($previos->isEmpty()) {
            return ['status' => 200, 'por_id' => $por_id, 'procesados' => 0, 'run' => null];
        }

        // Las propuestas de esos ítems, por id (con su corrida y su tipo).
        $propuestas = CategoryProposal::where('user_id', $dueno->id)
            ->whereIn('id', $previos->pluck('proposal_id')->unique()->values()->all())
            ->get(['id', 'run_id', 'tipo'])
            ->keyBy('id');

        // Las corridas implicadas, en orden de id: es el orden en que se bloquean.
        $run_ids = $propuestas->pluck('run_id')->unique()->sort()->values()->all();

        // Candado de las corridas (en orden) y después de los ítems (en orden).
        $corridas = CategoryProposalRun::where('user_id', $dueno->id)
            ->whereIn('id', $run_ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Los ítems pedidos, ya bloqueados: su estado se lee de estas filas.
        $items = CategoryProposalItem::where('user_id', $dueno->id)
            ->whereIn('id', $previos->pluck('id')->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // El estado al que pasa el ítem con esta acción.
        $estado_final = $accion === 'aprobar' ? CategoryProposalItem::ESTADO_APROBADA : CategoryProposalItem::ESTADO_RECHAZADA;

        // Qué ítems se pueden procesar, por corrida; el resto queda con su código.
        $candidatos = [];

        // La primera corrida propia que apareció: de ahí salen los conteos de la respuesta.
        $corrida_vista = null;

        foreach ($items as $item) {
            // La propuesta del ítem.
            $propuesta = $propuestas->get($item->proposal_id);

            // La corrida de esa propuesta (ya bloqueada).
            $corrida = is_null($propuesta) ? null : $corridas->get($propuesta->run_id);

            if (is_null($corrida)) {
                continue;
            }

            if (is_null($corrida_vista)) {
                $corrida_vista = $corrida;
            }

            if ($corrida->estado !== CategoryProposalRun::ESTADO_ELEGIDA) {
                $por_id[(int) $item->id] = $corrida->estado === CategoryProposalRun::ESTADO_DESCARTADA
                    ? self::CODIGO_FUERA_DE_ESTADO
                    : self::CODIGO_TODAVIA_NO_ELEGIDA;
                continue;
            }

            // Un ítem de una propuesta que no es la elegida no se revisa.
            if ((int) $corrida->propuesta_elegida_id !== (int) $item->proposal_id) {
                $por_id[(int) $item->id] = self::CODIGO_FUERA_DE_ESTADO;
                continue;
            }

            // Ya está como se pide: el segundo clic. No se reaplica.
            if ($item->estado === $estado_final) {
                $por_id[(int) $item->id] = self::CODIGO_YA_ESTABA;
                continue;
            }

            if ($item->estado !== CategoryProposalItem::ESTADO_A_REVISAR) {
                $por_id[(int) $item->id] = self::CODIGO_FUERA_DE_ESTADO;
                continue;
            }

            $candidatos[(int) $corrida->id][] = $item;
        }

        // Cuántos ítems se procesaron.
        $procesados = 0;

        // Los artículos cuya categoría se escribió (para el recálculo de precios y Tienda Nube).
        $article_ids = [];

        // Las categorías reales del dueño (índice cargado una sola vez).
        $real = new CategoriaRealHelper($dueno);

        foreach ($candidatos as $run_id => $lista) {
            $corrida = $corridas->get($run_id);
            $propuesta = $propuestas->get($corrida->propuesta_elegida_id);

            if ($accion === 'aprobar') {
                // Qué ítems se procesaron y qué artículos se escribieron.
                $hechos = self::aprobar_items($lista, $propuesta, $dueno, $auth_user_id, $real, $por_id);
            } else {
                $hechos = self::rechazar_items($lista, $propuesta, $dueno, $auth_user_id, $por_id);
            }

            $procesados  += count($hechos['ids_de_items']);
            $article_ids = array_merge($article_ids, $hechos['ids_de_articulos_escritos']);

            // La primera acción de revisión de la corrida marca el fin del "todavía se puede cambiar de sistema".
            if (count($hechos['ids_de_items']) > 0 && is_null($corrida->revision_iniciada_at)) {
                $corrida->revision_iniciada_at = now();
                $corrida->save();
            }
        }

        return [
            'status'     => 200,
            'por_id'     => $por_id,
            'procesados' => $procesados,
            'run'        => $corrida_vista,
            'efectos'    => ['article_ids' => $article_ids],
        ];
    }

    /**
     * Aprueba los ítems candidatos de UNA propuesta: asegura el nodo real de cada uno (creándolo o
     * reutilizándolo recién ahora, con la misma rutina del aplicar), escribe la categoría del artículo y
     * deja el ítem `aprobada`. El valor previo del artículo ya está guardado desde el aplicar.
     *
     * 🔴 A diferencia del aplicar de "mantener", acá SÍ se pisa la categoría que el artículo tenga hoy: es
     * una acción explícita del dueño sobre ESE artículo, con la sugerencia a la vista.
     *
     * @param  array $items        Los ítems candidatos (modelos completos, ya bloqueados).
     * @param  \App\Models\CategoryProposal $propuesta
     * @param  \App\Models\User $dueno
     * @param  int   $auth_user_id
     * @param  \App\Http\Controllers\Helpers\category_proposal\CategoriaRealHelper $real
     * @param  array $por_id       Se modifica: el código de cada ítem.
     * @return array  ['ids_de_items' => [...], 'ids_de_articulos_escritos' => [...]]
     */
    protected static function aprobar_items(array $items, CategoryProposal $propuesta, User $dueno, $auth_user_id, CategoriaRealHelper $real, array &$por_id)
    {
        // Si la propuesta es "Mantener las mías" (usa las categorías existentes) y no un sistema nuevo.
        $es_mantener = $propuesta->tipo === CategoryProposal::TIPO_MANTENER;

        // Todos los nodos de la propuesta (hasta 400) y lo que tienen hoy los artículos de los ítems.
        $nodos = CategoryProposalNode::where('proposal_id', $propuesta->id)->where('user_id', $dueno->id)->get()->keyBy('id');

        // Los artículos de los ítems, para leer lo que tienen hoy.
        $article_ids = [];

        foreach ($items as $item) {
            $article_ids[] = (int) $item->article_id;
        }

        // Lo que tiene hoy cada artículo (los borrados y los ajenos no aparecen).
        $articulos = CategoryProposalEscrituraHelper::leer_articulos($dueno->id, $article_ids);

        // Los ítems que se procesaron.
        $ids_de_items = [];

        // Los artículos a escribir, agrupados por destino.
        $grupos = [];

        // Los artículos que de verdad cambiaron de categoría (los que ya estaban ahí no se reescriben).
        $escritos = [];

        foreach ($items as $item) {
            // El artículo del ítem, como entero.
            $article_id = (int) $item->article_id;

            if (!isset($articulos[$article_id])) {
                $por_id[(int) $item->id] = self::CODIGO_ARTICULO_INEXISTENTE;
                continue;
            }

            // La categoría y subcategoría reales del ítem (null si no se pueden resolver).
            $destino = self::destino_del_item($item, $nodos, $real, $es_mantener);

            if (is_null($destino)) {
                $por_id[(int) $item->id] = self::CODIGO_DESTINO_INEXISTENTE;
                continue;
            }

            $por_id[(int) $item->id] = self::CODIGO_PROCESADO;
            $ids_de_items[]          = (int) $item->id;

            if (!self::mismo_destino($articulos[$article_id]['category_id'], $destino['category_id'])
                || !self::mismo_destino($articulos[$article_id]['sub_category_id'], $destino['sub_category_id'])) {
                CategoryProposalEscrituraHelper::sumar_a_grupo($grupos, $destino['category_id'], $destino['sub_category_id'], $article_id);
                $escritos[] = $article_id;
            }
        }

        CategoryProposalEscrituraHelper::escribir_destinos($dueno->id, array_values($grupos));

        if (!empty($ids_de_items)) {
            CategoryProposalItem::where('user_id', $dueno->id)
                ->whereIn('id', $ids_de_items)
                ->update([
                    'estado'       => CategoryProposalItem::ESTADO_APROBADA,
                    'revisado_por' => $auth_user_id,
                    'revisado_at'  => now(),
                ]);
        }

        return ['ids_de_items' => $ids_de_items, 'ids_de_articulos_escritos' => $escritos];
    }

    /**
     * Rechaza los ítems candidatos de UNA propuesta: pasan a `rechazada` y el artículo no se toca (sigue
     * sin categoría).
     *
     * @param  array $items
     * @param  \App\Models\CategoryProposal $propuesta
     * @param  \App\Models\User $dueno
     * @param  int   $auth_user_id
     * @param  array $por_id  Se modifica: el código de cada ítem.
     * @return array  ['ids_de_items' => [...], 'ids_de_articulos_escritos' => []]
     */
    protected static function rechazar_items(array $items, CategoryProposal $propuesta, User $dueno, $auth_user_id, array &$por_id)
    {
        // Los ítems que se procesaron.
        $ids_de_items = [];

        foreach ($items as $item) {
            $por_id[(int) $item->id] = self::CODIGO_PROCESADO;
            $ids_de_items[]          = (int) $item->id;
        }

        CategoryProposalItem::where('user_id', $dueno->id)
            ->whereIn('id', $ids_de_items)
            ->update([
                'estado'       => CategoryProposalItem::ESTADO_RECHAZADA,
                'revisado_por' => $auth_user_id,
                'revisado_at'  => now(),
            ]);

        return ['ids_de_items' => $ids_de_items, 'ids_de_articulos_escritos' => []];
    }

    /**
     * El destino real de un ítem a aprobar: su categoría y (si la pide) su subcategoría, aseguradas con
     * la misma rutina del aplicar. Crea o reutiliza lo que haga falta (los nodos que solo tenían dudosos).
     *
     * @param  \App\Models\CategoryProposalItem $item
     * @param  \Illuminate\Support\Collection   $nodos  Los nodos de la propuesta por id.
     * @param  \App\Http\Controllers\Helpers\category_proposal\CategoriaRealHelper $real
     * @param  bool $es_mantener
     * @return array|null  ['category_id' => int, 'sub_category_id' => int|null] o null si no se puede resolver.
     */
    protected static function destino_del_item(CategoryProposalItem $item, $nodos, CategoriaRealHelper $real, $es_mantener)
    {
        if (is_null($item->node_id) || !isset($nodos[$item->node_id])) {
            return null;
        }

        // La categoría real del nodo (creada o reutilizada), o null si no se puede resolver.
        $categoria = self::asegurar_categoria_del_nodo($nodos[$item->node_id], $real, $es_mantener);

        if (is_null($categoria)) {
            return null;
        }

        // La subcategoría real (null si el ítem no pide ninguna).
        $sub_category_id = null;

        if (!is_null($item->sub_node_id)) {
            if (!isset($nodos[$item->sub_node_id])) {
                return null;
            }

            // La subcategoría real del nodo dentro de esa categoría, o null si no se puede resolver.
            $sub = self::asegurar_subcategoria_del_nodo($nodos[$item->sub_node_id], $categoria['id'], $real, $es_mantener);

            if (is_null($sub)) {
                return null;
            }

            $sub_category_id = $sub['id'];
        }

        return ['category_id' => $categoria['id'], 'sub_category_id' => $sub_category_id];
    }

    // ---------------------------------------------------------------------------------------------
    // La fila de un ítem (contrato §6.6) y utilidades
    // ---------------------------------------------------------------------------------------------

    /**
     * La fila de un ítem tal como la dibuja la solapa de revisión (contrato B §6.6). La usan las
     * respuestas de aprobar y rechazar; el listado paginado lo arma CategoryProposalLecturaHelper con las
     * mismas claves.
     *
     * `actual` trae los nombres de las categorías REALES solo cuando el ítem ya está asignado (`aplicada` o
     * `aprobada`); en el resto de los estados el artículo no tiene la categoría de la propuesta y va en null.
     *
     * @param  \App\Models\CategoryProposalItem $item
     * @return array  ['id', 'estado', 'confianza', 'motivo', 'articulo' => [...], 'sugerencia' => [...], 'actual' => [...]]
     */
    public static function fila_de_item(CategoryProposalItem $item)
    {
        // El artículo del ítem (solo lo que muestra la fila); null si ya no existe.
        $articulo = Article::where('user_id', $item->user_id)
            ->where('id', $item->article_id)
            ->first(['id', 'name', 'bar_code', 'provider_code']);

        // Los nodos que nombra el ítem (categoría y subcategoría).
        $nodo_ids = [];

        foreach ([$item->node_id, $item->sub_node_id] as $nodo_id) {
            if (!is_null($nodo_id)) {
                $nodo_ids[] = (int) $nodo_id;
            }
        }

        // Esos nodos por id (sin consulta si el ítem no nombra ninguno).
        $nodos = empty($nodo_ids)
            ? collect()
            : CategoryProposalNode::where('user_id', $item->user_id)->whereIn('id', $nodo_ids)->get()->keyBy('id');

        // El nodo-categoría del ítem.
        $nodo_categoria = is_null($item->node_id) ? null : $nodos->get($item->node_id);

        // El nodo-subcategoría del ítem.
        $nodo_sub = is_null($item->sub_node_id) ? null : $nodos->get($item->sub_node_id);

        // Los nombres reales de donde quedó el artículo (solo si el ítem ya está asignado).
        $actual = ['categoria' => null, 'subcategoria' => null];

        if (in_array($item->estado, CategoryProposalItem::ESTADOS_ASIGNADOS, true)) {
            if ($nodo_categoria && $nodo_categoria->real_category_id) {
                $actual['categoria'] = Category::where('user_id', $item->user_id)->where('id', $nodo_categoria->real_category_id)->value('name');
            }

            if ($nodo_sub && $nodo_sub->real_sub_category_id) {
                $actual['subcategoria'] = SubCategory::where('user_id', $item->user_id)->where('id', $nodo_sub->real_sub_category_id)->value('name');
            }
        }

        return [
            'id'         => (int) $item->id,
            'estado'     => $item->estado,
            'confianza'  => $item->confianza,
            'motivo'     => $item->motivo,
            'articulo'   => [
                'id'                  => (int) $item->article_id,
                'nombre'              => $articulo ? $articulo->name : null,
                'codigo_de_barras'    => $articulo ? $articulo->bar_code : null,
                'codigo_de_proveedor' => $articulo ? $articulo->provider_code : null,
            ],
            'sugerencia' => [
                'categoria'    => $nodo_categoria ? $nodo_categoria->nombre : null,
                'subcategoria' => $nodo_sub ? $nodo_sub->nombre : null,
            ],
            'actual'     => $actual,
        ];
    }

    /**
     * Normaliza y valida los ids de un aprobar / rechazar en lote: un arreglo de 1 a
     * `catalogo_ia.ids_por_lote_de_revision_maximo` enteros positivos (los repetidos se juntan). Se arma a
     * mano porque el 422 de este contrato es `{"error":"validacion","message","detalle":{campo:[mensajes]}}`.
     *
     * @param  mixed $ids  Lo que mandó la SPA en `ids`.
     * @return array  ['ids' => [int, ...], 'detalle' => [campo => [mensajes]]] (`detalle` vacío = válido)
     */
    public static function normalizar_ids_del_lote($ids)
    {
        // Cuántos ids acepta un pedido de lote.
        $maximo = (int) config('catalogo_ia.ids_por_lote_de_revision_maximo');

        if (!is_array($ids) || empty($ids)) {
            return ['ids' => [], 'detalle' => ['ids' => ['Mandá al menos un artículo para revisar.']]];
        }

        if (count($ids) > $maximo) {
            return ['ids' => [], 'detalle' => ['ids' => ['Se pueden revisar hasta '.$maximo.' artículos por vez.']]];
        }

        // Los ids ya validados, sin repetir: [id => id].
        $normalizados = [];

        foreach ($ids as $id) {
            // Los booleanos y los arreglos no son ids aunque `filter_var` los convierta (`true` daría 1).
            $entero = (is_bool($id) || is_array($id) || is_null($id))
                ? false
                : filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($entero === false) {
                return ['ids' => [], 'detalle' => ['ids' => ['Todos los ids tienen que ser números enteros positivos.']]];
            }

            $normalizados[$entero] = $entero;
        }

        return ['ids' => array_values($normalizados), 'detalle' => []];
    }

    /**
     * El 403 de quien no es el dueño ni el acceso maestro y quiere elegir, volver atrás o revisar.
     *
     * @return array
     */
    public static function solo_el_dueno()
    {
        return self::error(403, 'solo_el_dueno', 'Solo el dueño de la cuenta o el acceso maestro de ComercioCity puede hacer esto.');
    }

    /**
     * Un error de validación de forma ya armado (para los controladores que validan `ids`).
     *
     * @param  array $detalle  [campo => [mensajes]]
     * @return array
     */
    public static function validacion_fallida(array $detalle)
    {
        return self::error_de_validacion($detalle);
    }

    /**
     * El cuerpo JSON de un error: todo lo que devolvió el helper menos el `status` (que va en el HTTP).
     *
     * @param  array $resultado
     * @return array
     */
    public static function cuerpo_del_error(array $resultado)
    {
        unset($resultado['status']);

        return $resultado;
    }

    /**
     * El texto en español de cada motivo por el que no se puede volver atrás.
     *
     * @param  string $motivo
     * @return string
     */
    protected static function mensaje_del_motivo($motivo)
    {
        switch ($motivo) {
            case self::MOTIVO_HAY_REVISIONES:
                return 'Ya empezaste a revisar los artículos dudosos, así que no se puede cambiar de sistema.';

            case self::MOTIVO_ARTICULOS_EDITADOS:
                return 'Después de elegir se cambió a mano la categoría de algunos artículos. Para no pisar esos cambios, ya no se puede cambiar de sistema.';

            case self::MOTIVO_CATEGORIAS_EDITADAS:
                return 'Después de elegir se modificaron las categorías que se crearon (se renombraron, se movieron, se borraron o se les cargaron artículos). Ya no se puede cambiar de sistema.';

            default:
                return 'Todavía no elegiste un sistema de categorías: no hay nada que deshacer.';
        }
    }

    /**
     * El error de una propuesta `nueva` bloqueada: 422 con los motivos. Si hay algún motivo de márgenes
     * (R1 a R6) el código es `bloqueado_por_margenes`; si el único es Tienda Nube, `bloqueado_por_tienda_nube`.
     *
     * @param  array $motivos  [['codigo' => 'R1'|...|'tienda_nube', 'cantidad' => n], ...]
     * @return array
     */
    protected static function error_de_bloqueo(array $motivos)
    {
        // Si algún motivo del bloqueo es de márgenes por categoría (R1 a R6) y no solo de Tienda Nube.
        $por_margenes = false;

        foreach ($motivos as $motivo) {
            if ($motivo['codigo'] !== 'tienda_nube') {
                $por_margenes = true;
            }
        }

        if ($por_margenes) {
            return self::error(
                422,
                'bloqueado_por_margenes',
                'Tu cuenta usa márgenes o listas de precio por categoría: elegir un sistema nuevo movería precios. Por ahora solo podés mantener tus categorías y completar los artículos sin categoría.',
                ['motivos' => $motivos]
            );
        }

        return self::error(
            422,
            'bloqueado_por_tienda_nube',
            'Tu cuenta usa Tienda Nube: crear todas las categorías juntas puede trabar la sincronización. Por ahora solo podés mantener tus categorías y completar los artículos sin categoría.',
            ['motivos' => $motivos]
        );
    }

    /**
     * Un error de validación de forma: 422 `validacion` con el detalle por campo.
     *
     * @param  array $detalle  [campo => [mensajes]]
     * @return array
     */
    protected static function error_de_validacion(array $detalle)
    {
        return self::error(422, 'validacion', 'Los datos enviados no son válidos.', ['detalle' => $detalle]);
    }

    /**
     * Arma un error con la forma del contrato B: `{error, message}` y lo que se le sume.
     *
     * @param  int    $status
     * @param  string $codigo   Para la máquina.
     * @param  string $mensaje  Para mostrar.
     * @param  array  $extra    Campos adicionales (`motivo`, `motivos`, `detalle`).
     * @return array
     */
    protected static function error($status, $codigo, $mensaje, array $extra = [])
    {
        return array_merge(['status' => $status, 'error' => $codigo, 'message' => $mensaje], $extra);
    }

    /**
     * El dueño del comercio como modelo. Si llega el id de un empleado se resuelve a su dueño (la
     * SPA ya manda el dueño, esto es un cinturón).
     *
     * @param  int $user_id
     * @return \App\Models\User|null
     */
    protected static function dueno_del_comercio($user_id)
    {
        // El usuario que llegó (puede ser un empleado).
        $usuario = User::find((int) $user_id);

        if (is_null($usuario)) {
            return null;
        }

        if ($usuario->owner_id) {
            return User::find((int) $usuario->owner_id);
        }

        return $usuario;
    }
}
