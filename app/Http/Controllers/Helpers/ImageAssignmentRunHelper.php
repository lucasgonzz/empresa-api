<?php

namespace App\Http\Controllers\Helpers;

use App\Events\ArticleBatchImagesProcessed;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Models\Article;
use App\Models\ArticleImageSearchAttempt;
use App\Models\BackgroundProcess;
use App\Models\Image;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\User;
use App\Services\ImageSearch\ImageSearchProviderFactory;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La lógica de negocio de las asignaciones inteligentes de imágenes (misión
 * imagenes-catalogo-completo, 27/9/2026). ImageAssignmentRunController solo traduce a HTTP.
 *
 * - Crear una asignación (selección, asistente o todo el catálogo): la fila de la corrida, un item
 *   por artículo EN EL ORDEN pedido, el registro visible `imagenes_automaticas` en `pendiente` con
 *   referencia a la corrida, y el job despachado con afterCommit().
 * - La selección de "todo el catálogo" (§3 del plan): activos, sin imagen, sin imágenes a revisar,
 *   sin "ya buscado sin éxito" de los últimos 90 días, primero los publicados en la tienda, después
 *   los que tienen stock, después el resto, hasta el tope.
 * - Los payloads del contrato con la SPA (§5.1 y §5.2), los conteos por estado, el resumen del badge.
 * - Aprobar / rechazar / quitar, detener / reanudar, el cierre de una corrida (evento de Pusher y
 *   registro visible) y la purga de las viejas.
 *
 * 🔴 Todo por dueño: cada consulta filtra por `user_id` y un id de otro comercio es "no existe".
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class ImageAssignmentRunHelper
{
    /**
     * Tipo del registro visible: el mismo de siempre, así la píldora de procesos no cambia. Se toma
     * de ImagenesAutomaticasHelper para que los dos no puedan quedar distintos.
     */
    const TIPO_DE_PROCESO = ImagenesAutomaticasHelper::TIPO_DE_PROCESO;

    /** Título del registro visible de las asignaciones por selección y del asistente. */
    const TITULO_DE_PROCESO = 'Imágenes automáticas';

    /** Título del registro visible de "todo el catálogo". */
    const TITULO_DE_PROCESO_CATALOGO = 'Imágenes de todo el catálogo';

    /** Clave de sesión que pone AuthController::login() en el login maestro. */
    const CLAVE_DE_SESION_MAESTRO = 'master_login_bypass_user_last_activity';

    /** Un artículo buscado sin éxito no se vuelve a buscar en el catálogo durante estos días. */
    const DIAS_SIN_VOLVER_A_BUSCAR = 90;

    /**
     * Motivos de no asignada que cuentan como "ya se buscó y no había imagen". Los de error
     * (error_de_busqueda, error_interno, sin_cupo, articulo_borrado) NO: esos no dicen nada del
     * producto y hay que volver a intentarlos. sin_datos tampoco: no gasta búsquedas, y si le
     * cargaron un nombre desde entonces, esta vez sí se puede buscar.
     */
    const MOTIVOS_YA_BUSCADO_SIN_EXITO = ['sin_resultados', 'imagenes_chicas', 'no_descargables', 'no_corresponden'];

    /** Asignaciones terminadas de más de estos días, sin nada a revisar, se borran. */
    const DIAS_PARA_PURGAR = 180;

    /** Paginado de los listados de la SPA (contrato §5.3). */
    const POR_PAGINA_DEFECTO = 25;
    const POR_PAGINA_MINIMO  = 10;
    const POR_PAGINA_MAXIMO  = 100;

    /** Cuántos ids acepta como mucho un aprobar/rechazar en lote (una página es de 100 como mucho). */
    const MAXIMO_EN_LOTE = 200;

    /**
     * Al reanudar, un artículo "procesando" vuelve a la fila solo si no se tocó hace más de estos
     * minutos. El timeout de un tramo es de 5 minutos: uno más nuevo lo está terminando un tramo
     * VIVO (el caso de detener y reanudar enseguida), y devolverlo haría que otro tramo lo procese
     * de nuevo en paralelo.
     */
    const MINUTOS_PARA_DAR_POR_MUERTO_UN_ARTICULO = 10;

    /** Estimación que se muestra antes de lanzar el catálogo (plan §3). */
    const BUSQUEDAS_ESTIMADAS_POR_ARTICULO = 1.5;
    const SEGUNDOS_ESTIMADOS_POR_ARTICULO  = 8;
    const USD_POR_MIL_BUSQUEDAS            = 1.0;
    const USD_DE_IA_POR_ARTICULO           = 0.005;

    /** Solapas del detalle y los estados que muestra cada una (contrato §5.1). */
    const SOLAPAS = [
        'no_asignadas' => ImageAssignmentItem::ESTADOS_NO_ASIGNADAS,
        'a_revisar'    => ImageAssignmentItem::ESTADOS_A_REVISAR,
        'asignadas'    => ImageAssignmentItem::ESTADOS_ASIGNADAS,
    ];

    /** Nombre que se muestra como "lanzada por" en las de todo el catálogo (solo el acceso maestro las lanza). */
    const LANZADA_POR_ACCESO_MAESTRO = 'ComercioCity';

    /* ----------------------------------------------------------------------------------------
     * Crear
     * -------------------------------------------------------------------------------------- */

    /**
     * Crea una asignación y la manda a procesar.
     *
     * Los artículos se toman EN EL ORDEN recibido (sin repetidos) y solo los del dueño que existen:
     * uno ajeno o borrado no entra. El registro visible nace `pendiente` acá, al encolar (en el
     * shared el worker pasa una vez por minuto y hasta entonces la persona no vería nada).
     *
     * 🔴 El despacho va con afterCommit() EXPLÍCITO: desde el asistente esto corre adentro de la
     * transacción de EjecutorAccionesIaHelper, y un worker libre (redis, en el VPS) podría tomar el
     * job antes del commit y no encontrar la asignación. El default de config no alcanza: en redis
     * `after_commit` está en false. Mismo criterio que tenía ImagenesAutomaticasHelper::encolar().
     *
     * @param  \App\Models\User $owner
     * @param  array            $article_ids
     * @param  string           $origen        catalogo | seleccion | asistente
     * @param  int|null         $auth_user_id  Quién la lanzó.
     * @param  array            $opciones      proveedor (serper|google; default: el que le toca al dueño),
     *                                         aplica_tope_diario (bool; default true).
     * @return \App\Models\ImageAssignmentRun
     */
    public static function crear(User $owner, array $article_ids, $origen, $auth_user_id = null, array $opciones = [])
    {
        // Ids enteros, sin repetidos, en el orden recibido.
        $ids   = [];
        $vistos = [];

        foreach ($article_ids as $article_id) {
            $id = (int) $article_id;

            if ($id > 0 && !isset($vistos[$id])) {
                $vistos[$id] = true;
                $ids[]       = $id;
            }
        }

        // Snapshot de nombre y código: el diagnóstico tiene que mostrar con qué datos se buscó.
        $articulos = [];

        foreach (array_chunk($ids, 1000) as $lote) {
            $filas = Article::where('user_id', $owner->id)
                ->whereIn('id', $lote)
                ->get(['id', 'name', 'bar_code']);

            foreach ($filas as $fila) {
                $articulos[(int) $fila->id] = $fila;
            }
        }

        $validos = [];

        foreach ($ids as $id) {
            if (isset($articulos[$id])) {
                $validos[] = $id;
            }
        }

        // La tabla de diagnóstico vieja se sigue purgando sola, aunque las asignaciones nuevas no
        // escriben ahí (la SPA vieja cacheada todavía la consulta).
        try {
            ArticleImageSearchAttempt::purge_old((int) $owner->id, 30);
        } catch (\Throwable $e) {
            Log::warning('[ImagenesInteligentes] No se pudo purgar article_image_search_attempts: '.$e->getMessage());
        }

        self::purgar_viejas((int) $owner->id);

        $proveedor = isset($opciones['proveedor']) ? (string) $opciones['proveedor'] : ImageSearchProviderFactory::nombre_para($owner);
        $aplica    = array_key_exists('aplica_tope_diario', $opciones) ? (bool) $opciones['aplica_tope_diario'] : true;
        $total     = count($validos);

        return DB::transaction(function () use ($owner, $origen, $auth_user_id, $proveedor, $aplica, $total, $validos, $articulos) {
            $run = ImageAssignmentRun::create([
                'user_id'            => (int) $owner->id,
                'auth_user_id'       => is_null($auth_user_id) ? null : (int) $auth_user_id,
                'uuid'               => (string) Str::uuid(),
                'origen'             => (string) $origen,
                'proveedor'          => $proveedor,
                'status'             => ImageAssignmentRun::STATUS_PENDIENTE,
                'total_articulos'    => $total,
                'aplica_tope_diario' => $aplica,
            ]);

            $ahora = Carbon::now();
            $filas = [];
            $orden = 0;

            foreach ($validos as $id) {
                $orden++;
                $articulo = $articulos[$id];

                $filas[] = [
                    'run_id'           => (int) $run->id,
                    'user_id'          => (int) $owner->id,
                    'article_id'       => $id,
                    'article_name'     => is_null($articulo->name) ? null : mb_substr((string) $articulo->name, 0, 255),
                    'article_bar_code' => is_null($articulo->bar_code) ? null : mb_substr((string) $articulo->bar_code, 0, 100),
                    'orden'            => $orden,
                    'status'           => ImageAssignmentItem::STATUS_PENDIENTE,
                    'created_at'       => $ahora,
                    'updated_at'       => $ahora,
                ];

                if (count($filas) >= 500) {
                    DB::table('image_assignment_items')->insert($filas);
                    $filas = [];
                }
            }

            if (!empty($filas)) {
                DB::table('image_assignment_items')->insert($filas);
            }

            $es_catalogo = $origen === ImageAssignmentRun::ORIGEN_CATALOGO;

            $proceso = BackgroundProcessHelper::iniciar((int) $owner->id, self::TIPO_DE_PROCESO, $es_catalogo ? self::TITULO_DE_PROCESO_CATALOGO : self::TITULO_DE_PROCESO, [
                'auth_user_id' => $auth_user_id,
                'total'        => $total,
                'unidad'       => 'artículos',
                'detalle'      => $es_catalogo ? $total.' artículos (todo el catálogo)' : $total.' artículos',
                'status'       => BackgroundProcess::STATUS_PENDIENTE,
                'etapa'        => 'En espera del procesador',
                'referencia'   => $run,
            ]);

            if (!is_null($proceso)) {
                $run->background_process_id = (int) $proceso->id;
                $run->save();
            }

            ProcessImageAssignmentRunJob::dispatch((int) $run->id)->afterCommit();

            return $run;
        });
    }

    /* ----------------------------------------------------------------------------------------
     * Todo el catálogo
     * -------------------------------------------------------------------------------------- */

    /**
     * ¿La sesión actual entró por el login maestro? Lo pone AuthController::login() (y la
     * transferencia de versión) en la sesión; "todo el catálogo" y detener / reanudar son solo para
     * esa sesión (decisión de Lucas, 27/9/2026).
     *
     * @return bool
     */
    public static function es_acceso_maestro()
    {
        return (bool) session(self::CLAVE_DE_SESION_MAESTRO, false);
    }

    /**
     * Los números de la selección del catálogo (sin traer ids).
     *
     * @param  int $owner_id
     * @return array  sin_imagen, excluidos_pendientes_de_revision, excluidos_ya_buscados, candidatos.
     */
    public static function conteos_del_catalogo($owner_id)
    {
        $base = self::base_del_catalogo($owner_id);

        $sin_imagen = (clone $base)->count();

        $pendientes_de_revision = (clone $base)->whereExists(self::subconsulta_a_revisar($owner_id))->count();

        $ya_buscados = (clone $base)
            ->whereNotExists(self::subconsulta_a_revisar($owner_id))
            ->whereExists(self::subconsulta_ya_buscado_sin_exito($owner_id))
            ->count();

        return [
            'sin_imagen'                       => (int) $sin_imagen,
            'excluidos_pendientes_de_revision' => (int) $pendientes_de_revision,
            'excluidos_ya_buscados'            => (int) $ya_buscados,
            'candidatos'                       => max(0, (int) $sin_imagen - (int) $pendientes_de_revision - (int) $ya_buscados),
        ];
    }

    /**
     * Los ids del catálogo a buscar, en orden de prioridad y hasta el tope: primero los publicados
     * en la tienda (`online`), después los que tienen stock, después el resto por id. Así, con más
     * artículos que el tope, lo primero que gana imagen es lo que ve el cliente final.
     *
     * @param  int $owner_id
     * @param  int $tope
     * @return array
     */
    public static function ids_del_catalogo($owner_id, $tope)
    {
        return self::base_del_catalogo($owner_id)
            ->whereNotExists(self::subconsulta_a_revisar($owner_id))
            ->whereNotExists(self::subconsulta_ya_buscado_sin_exito($owner_id))
            ->orderByRaw('COALESCE(articles.online, 0) DESC')
            ->orderByRaw('CASE WHEN articles.stock > 0 THEN 1 ELSE 0 END DESC')
            ->orderBy('articles.id')
            ->limit(max(1, (int) $tope))
            ->pluck('articles.id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }

    /**
     * El tope de artículos por lanzamiento del catálogo (config imagenes_inteligentes.tope_catalogo).
     *
     * @return int
     */
    public static function tope_del_catalogo()
    {
        return max(1, (int) config('services.imagenes_inteligentes.tope_catalogo', 5000));
    }

    /**
     * La previa que ve el acceso maestro antes de lanzar (contrato §5.4).
     *
     * @param  \App\Models\User $owner
     * @return array
     */
    public static function previa_del_catalogo(User $owner)
    {
        $tope    = self::tope_del_catalogo();
        $conteos = self::conteos_del_catalogo((int) $owner->id);

        $a_buscar  = min($tope, $conteos['candidatos']);
        $busquedas = (int) round($a_buscar * self::BUSQUEDAS_ESTIMADAS_POR_ARTICULO);

        $configurado = ImageSearchProviderFactory::serper_configurado();

        $activa = self::corrida_de_catalogo_activa((int) $owner->id);

        return [
            'sin_imagen'                       => $conteos['sin_imagen'],
            'excluidos_pendientes_de_revision' => $conteos['excluidos_pendientes_de_revision'],
            'excluidos_ya_buscados'            => $conteos['excluidos_ya_buscados'],
            'a_buscar'                         => $a_buscar,
            'tope'                             => $tope,
            'quedan_para_otra_corrida'         => max(0, $conteos['candidatos'] - $a_buscar),
            'proveedor_configurado'            => $configurado,
            'proveedor'                        => $configurado ? ImageAssignmentRun::PROVEEDOR_SERPER : null,
            'estimacion'                       => [
                'busquedas'     => $busquedas,
                'minutos'       => (int) round($a_buscar * self::SEGUNDOS_ESTIMADOS_POR_ARTICULO / 60),
                'usd_busquedas' => round($busquedas / 1000 * self::USD_POR_MIL_BUSQUEDAS, 2),
                'usd_ia'        => round($a_buscar * self::USD_DE_IA_POR_ARTICULO, 2),
            ],
            'corrida_activa'                   => is_null($activa) ? null : self::payload_de_asignacion($activa),
        ];
    }

    /**
     * Lanza "buscar imágenes para todo el catálogo".
     *
     * 🔴 Se serializa por dueño con un lockForUpdate sobre su fila de users: sin eso, dos clics
     * seguidos pasaban los dos el "¿hay otra activa?" y quedaban dos corridas de catálogo
     * buscando lo mismo (y pagando dos veces).
     *
     * @param  \App\Models\User $owner
     * @param  int|null         $auth_user_id
     * @return array  ['status' => 201|422, 'message' => string|null, 'run' => ImageAssignmentRun|null]
     */
    public static function crear_del_catalogo(User $owner, $auth_user_id = null)
    {
        if (!ImageSearchProviderFactory::serper_configurado()) {
            return [
                'status'  => 422,
                'message' => 'Para buscar imágenes de todo el catálogo hace falta la clave de Serper (SERPER_API_KEY) en el servidor de este cliente.',
                'run'     => null,
            ];
        }

        return DB::transaction(function () use ($owner, $auth_user_id) {
            User::where('id', $owner->id)->lockForUpdate()->first();

            if (!is_null(self::corrida_de_catalogo_activa((int) $owner->id))) {
                return [
                    'status'  => 422,
                    'message' => 'Ya hay una búsqueda de todo el catálogo en curso: esperá a que termine o detenela.',
                    'run'     => null,
                ];
            }

            $ids = self::ids_del_catalogo((int) $owner->id, self::tope_del_catalogo());

            if (empty($ids)) {
                return [
                    'status'  => 422,
                    'message' => 'No hay artículos para buscar: todos los activos tienen imagen, tienen una imagen esperando revisión o ya se buscaron sin éxito en los últimos '.self::DIAS_SIN_VOLVER_A_BUSCAR.' días.',
                    'run'     => null,
                ];
            }

            $run = self::crear($owner, $ids, ImageAssignmentRun::ORIGEN_CATALOGO, $auth_user_id, [
                'proveedor'          => ImageAssignmentRun::PROVEEDOR_SERPER,
                // El acceso maestro no le come el cupo del día al dueño (decisión del plan §6.2).
                'aplica_tope_diario' => false,
            ]);

            return ['status' => 201, 'message' => null, 'run' => $run];
        });
    }

    /**
     * La corrida de catálogo en curso del dueño, si hay.
     *
     * @param  int $owner_id
     * @return \App\Models\ImageAssignmentRun|null
     */
    public static function corrida_de_catalogo_activa($owner_id)
    {
        return ImageAssignmentRun::where('user_id', (int) $owner_id)
            ->where('origen', ImageAssignmentRun::ORIGEN_CATALOGO)
            ->activas()
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Artículos activos del dueño sin ninguna imagen (el universo del catálogo).
     *
     * @param  int $owner_id
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function base_del_catalogo($owner_id)
    {
        return Article::where('articles.user_id', (int) $owner_id)
            ->where('articles.status', 'active')
            ->whereDoesntHave('images');
    }

    /**
     * EXISTS: el artículo tiene una imagen esperando revisión en alguna asignación del dueño.
     *
     * @param  int $owner_id
     * @return \Closure
     */
    protected static function subconsulta_a_revisar($owner_id)
    {
        return function ($query) use ($owner_id) {
            $query->select(DB::raw(1))
                ->from('image_assignment_items')
                ->where('image_assignment_items.user_id', (int) $owner_id)
                ->whereColumn('image_assignment_items.article_id', 'articles.id')
                ->where('image_assignment_items.status', ImageAssignmentItem::STATUS_A_REVISAR);
        };
    }

    /**
     * EXISTS: el artículo se buscó sin éxito en los últimos DIAS_SIN_VOLVER_A_BUSCAR días (no hubo
     * imagen que sirviera, alguien rechazó la propuesta o quitó la que se había asignado).
     *
     * @param  int $owner_id
     * @return \Closure
     */
    protected static function subconsulta_ya_buscado_sin_exito($owner_id)
    {
        $desde = Carbon::now()->subDays(self::DIAS_SIN_VOLVER_A_BUSCAR);

        return function ($query) use ($owner_id, $desde) {
            $query->select(DB::raw(1))
                ->from('image_assignment_items')
                ->where('image_assignment_items.user_id', (int) $owner_id)
                ->whereColumn('image_assignment_items.article_id', 'articles.id')
                ->where('image_assignment_items.procesado_at', '>=', $desde)
                ->where(function ($condicion) {
                    $condicion->where(function ($no_asignada) {
                        $no_asignada->where('image_assignment_items.status', ImageAssignmentItem::STATUS_NO_ASIGNADA)
                            ->whereIn('image_assignment_items.motivo', self::MOTIVOS_YA_BUSCADO_SIN_EXITO);
                    })->orWhereIn('image_assignment_items.status', [
                        ImageAssignmentItem::STATUS_RECHAZADA,
                        ImageAssignmentItem::STATUS_QUITADA,
                    ]);
                });
        };
    }

    /* ----------------------------------------------------------------------------------------
     * Listados y payloads
     * -------------------------------------------------------------------------------------- */

    /**
     * El listado de Alertas → Imágenes: las asignaciones del dueño, más nuevas primero, paginadas.
     *
     * @param  int $owner_id
     * @param  int $pagina
     * @param  int $por_pagina
     * @return array  ['models' => paginador de RunPayload, 'resumen' => resumen()]
     */
    public static function listado($owner_id, $pagina, $por_pagina)
    {
        $paginador = ImageAssignmentRun::where('user_id', (int) $owner_id)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->paginate(self::por_pagina($por_pagina), ['*'], 'page', max(1, (int) $pagina));

        $runs    = $paginador->getCollection();
        $ids     = $runs->pluck('id')->all();
        $conteos = self::conteos_de($ids);
        $nombres = self::nombres_de_usuarios($runs->pluck('auth_user_id')->all());

        $paginador->setCollection($runs->map(function ($run) use ($conteos, $nombres) {
            return self::payload_de_asignacion($run, $conteos[$run->id], $nombres);
        }));

        return [
            'models'  => $paginador,
            'resumen' => self::resumen($owner_id),
        ];
    }

    /**
     * El resumen liviano del badge de Alertas.
     *
     * @param  int $owner_id
     * @return array  ['a_revisar' => int, 'sin_ver' => int, 'en_proceso' => int]
     */
    public static function resumen($owner_id)
    {
        return [
            'a_revisar'  => (int) ImageAssignmentItem::where('user_id', (int) $owner_id)
                ->where('status', ImageAssignmentItem::STATUS_A_REVISAR)
                ->count(),
            // Las que ya salieron de proceso y nadie abrió todavía.
            'sin_ver'    => (int) ImageAssignmentRun::where('user_id', (int) $owner_id)
                ->whereIn('status', [ImageAssignmentRun::STATUS_TERMINADA, ImageAssignmentRun::STATUS_DETENIDA, ImageAssignmentRun::STATUS_FALLIDA])
                ->whereNull('visto_at')
                ->count(),
            'en_proceso' => (int) ImageAssignmentRun::where('user_id', (int) $owner_id)
                ->activas()
                ->count(),
        ];
    }

    /**
     * Los artículos de una solapa del detalle, paginados en el servidor.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @param  string $solapa      no_asignadas | a_revisar | asignadas
     * @param  int    $pagina
     * @param  int    $por_pagina  Se acota a 10..100 (25 por defecto).
     * @param  string $buscar      Por nombre o código (sobre el snapshot del item).
     * @return array  ['models' => paginador de ItemPayload, 'conteos' => conteos]
     */
    public static function items_de(ImageAssignmentRun $run, $solapa, $pagina, $por_pagina, $buscar = '')
    {
        $query = ImageAssignmentItem::where('run_id', $run->id)
            ->where('user_id', $run->user_id)
            ->whereIn('status', self::SOLAPAS[$solapa]);

        $buscar = trim((string) $buscar);

        if ($buscar !== '') {
            $patron = '%'.addcslashes($buscar, '%_\\').'%';

            $query->where(function ($condicion) use ($patron) {
                $condicion->where('article_name', 'like', $patron)
                    ->orWhere('article_bar_code', 'like', $patron);
            });
        }

        $paginador = $query->orderBy('orden')
            ->orderBy('id')
            ->paginate(self::por_pagina($por_pagina), ['*'], 'page', max(1, (int) $pagina));

        $items   = $paginador->getCollection();
        $nombres = self::nombres_de_usuarios($items->pluck('revisado_por')->all());

        $paginador->setCollection($items->map(function ($item) use ($nombres) {
            return self::payload_de_item($item, $nombres);
        }));

        return [
            'models'  => $paginador,
            'conteos' => self::conteos_de([$run->id])[$run->id],
        ];
    }

    /**
     * Conteos por grupo de estados de varias asignaciones, en una sola consulta agrupada.
     *
     * @param  array $run_ids
     * @return array  run_id => ['asignadas', 'a_revisar', 'no_asignadas', 'pendientes']
     */
    public static function conteos_de(array $run_ids)
    {
        $conteos = [];

        foreach ($run_ids as $run_id) {
            $conteos[(int) $run_id] = ['asignadas' => 0, 'a_revisar' => 0, 'no_asignadas' => 0, 'pendientes' => 0];
        }

        if (empty($conteos)) {
            return $conteos;
        }

        $filas = DB::table('image_assignment_items')
            ->select('run_id', 'status', DB::raw('COUNT(*) as cantidad'))
            ->whereIn('run_id', array_keys($conteos))
            ->groupBy('run_id', 'status')
            ->get();

        foreach ($filas as $fila) {
            $grupo = self::grupo_de_estado((string) $fila->status);

            if (!is_null($grupo)) {
                $conteos[(int) $fila->run_id][$grupo] += (int) $fila->cantidad;
            }
        }

        return $conteos;
    }

    /**
     * RunPayload (contrato §5.1).
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @param  array|null $conteos  Los de conteos_de() para esta corrida (null = se calculan).
     * @param  array      $nombres  id => nombre de usuario (null = se busca).
     * @return array
     */
    public static function payload_de_asignacion(ImageAssignmentRun $run, $conteos = null, $nombres = null)
    {
        if (is_null($conteos)) {
            $conteos = self::conteos_de([$run->id])[$run->id];
        }

        if (is_null($nombres)) {
            $nombres = self::nombres_de_usuarios([$run->auth_user_id]);
        }

        $procesados = (int) $run->procesados;

        return [
            'id'                     => (int) $run->id,
            'uuid'                   => (string) $run->uuid,
            'origen'                 => (string) $run->origen,
            'proveedor'              => (string) $run->proveedor,
            'status'                 => (string) $run->status,
            'motivo_estado'          => $run->motivo_estado,
            'total_articulos'        => (int) $run->total_articulos,
            'procesados'             => $procesados,
            'conteos'                => $conteos,
            'busquedas'              => (int) $run->busquedas,
            'busquedas_por_articulo' => $procesados > 0 ? round($run->busquedas / $procesados, 2) : null,
            'busquedas_por_criterio' => [
                'codigo_de_barras' => (int) $run->busquedas_codigo,
                'nombre'           => (int) $run->busquedas_nombre,
            ],
            'validaciones_ia'        => (int) $run->validaciones_ia,
            'lanzada_por'            => self::lanzada_por($run, $nombres),
            'es_de_catalogo'         => $run->origen === ImageAssignmentRun::ORIGEN_CATALOGO,
            'created_at'             => self::fecha($run->created_at),
            'started_at'             => self::fecha($run->started_at),
            'finished_at'            => self::fecha($run->finished_at),
            'last_progress_at'       => self::fecha($run->last_progress_at),
            'visto'                  => !is_null($run->visto_at),
            'trabada'                => $run->esta_trabada(),
        ];
    }

    /**
     * ItemPayload (contrato §5.2).
     *
     * @param  \App\Models\ImageAssignmentItem $item
     * @param  array|null $nombres  id => nombre (para revisado_por; null = se busca).
     * @return array
     */
    public static function payload_de_item(ImageAssignmentItem $item, $nombres = null)
    {
        if (is_null($nombres)) {
            $nombres = self::nombres_de_usuarios([$item->revisado_por]);
        }

        $meta   = is_array($item->imagen_meta) ? $item->imagen_meta : null;
        $imagen = null;

        if (!is_null($meta)) {
            $ia = isset($meta['ia']) && is_array($meta['ia']) ? $meta['ia'] : [];

            $imagen = [
                'ancho'              => isset($meta['ancho']) ? (int) $meta['ancho'] : null,
                'alto'               => isset($meta['alto']) ? (int) $meta['alto'] : null,
                'fondo_blanco'       => isset($meta['fondo_blanco']) ? (bool) $meta['fondo_blanco'] : null,
                'fondo_blanco_ratio' => isset($meta['fondo_blanco_ratio']) ? (float) $meta['fondo_blanco_ratio'] : null,
                'dominio'            => isset($meta['dominio']) ? (string) $meta['dominio'] : null,
                'pagina'             => isset($meta['pagina']) ? (string) $meta['pagina'] : null,
                'ia'                 => [
                    'veredicto' => isset($ia['veredicto']) ? (string) $ia['veredicto'] : 'sin_evaluar',
                    'confianza' => isset($ia['confianza']) ? $ia['confianza'] : null,
                    'problemas' => isset($ia['problemas']) && is_array($ia['problemas']) ? array_values($ia['problemas']) : [],
                    'motivo'    => isset($ia['motivo']) ? (string) $ia['motivo'] : null,
                ],
            ];
        }

        $revisor = is_null($item->revisado_por) ? null : (int) $item->revisado_por;

        return [
            'id'               => (int) $item->id,
            'article_id'       => (int) $item->article_id,
            'article_name'     => $item->article_name,
            'article_bar_code' => $item->article_bar_code,
            'status'           => (string) $item->status,
            'motivo'           => $item->motivo,
            'motivo_detalle'   => $item->motivo_detalle,
            'criterio_usado'   => $item->criterio_usado,
            'busquedas'        => (int) $item->busquedas,
            'validaciones_ia'  => (int) $item->validaciones_ia,
            'imagen_url'       => $item->imagen_url,
            'imagen'           => $imagen,
            'avisos'           => !is_null($meta) && isset($meta['avisos']) && is_array($meta['avisos']) ? array_values($meta['avisos']) : [],
            'diagnostico'      => is_array($item->diagnostico) ? $item->diagnostico : [],
            'revisado_por'     => !is_null($revisor) && isset($nombres[$revisor]) ? $nombres[$revisor] : null,
            'revisado_at'      => self::fecha($item->revisado_at),
            'procesado_at'     => self::fecha($item->procesado_at),
        ];
    }

    /**
     * Marca como vista una asignación que ya salió de proceso. Una en curso no se marca: quien la
     * mira a mitad de camino todavía no vio el resultado.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @return \App\Models\ImageAssignmentRun
     */
    public static function marcar_vista(ImageAssignmentRun $run)
    {
        if ($run->salio_de_proceso() && is_null($run->visto_at)) {
            $run->visto_at = Carbon::now();
            $run->save();
        }

        return $run;
    }

    /* ----------------------------------------------------------------------------------------
     * Revisión: aprobar, rechazar, quitar
     * -------------------------------------------------------------------------------------- */

    /**
     * Aprueba una imagen "a revisar": el archivo imgcand_* pasa a un nombre definitivo, se crea la
     * fila de `images` y Tienda Nube se entera. Recién ahí la tienda la ve.
     *
     * @param  int      $owner_id
     * @param  int      $item_id
     * @param  int|null $auth_user_id  Quién aprueba.
     * @return array  ['status' => 200|404|422, 'message' => string|null, 'item' => ImageAssignmentItem|null]
     */
    public static function aprobar($owner_id, $item_id, $auth_user_id = null)
    {
        return DB::transaction(function () use ($owner_id, $item_id, $auth_user_id) {
            $item = ImageAssignmentItem::where('user_id', (int) $owner_id)
                ->where('id', (int) $item_id)
                ->lockForUpdate()
                ->first();

            if (is_null($item)) {
                return self::respuesta(404, 'No se encontró esa imagen.');
            }

            if ($item->status !== ImageAssignmentItem::STATUS_A_REVISAR) {
                return self::respuesta(422, 'Esta imagen ya no está esperando revisión.', $item);
            }

            $article = Article::where('id', $item->article_id)
                ->where('user_id', (int) $owner_id)
                ->first();

            if (is_null($article)) {
                self::borrar_candidata($item->imagen_archivo);

                $item->fill([
                    'status'         => ImageAssignmentItem::STATUS_NO_ASIGNADA,
                    'motivo'         => 'articulo_borrado',
                    'motivo_detalle' => 'Se iba a aprobar la imagen, pero el artículo ya no existe.',
                    'imagen_url'     => null,
                    'imagen_archivo' => null,
                    'revisado_por'   => self::entero_o_null($auth_user_id),
                    'revisado_at'    => Carbon::now(),
                ]);
                $item->save();

                return self::respuesta(422, 'El artículo ya no existe: no se puede aprobar su imagen.', $item);
            }

            $candidata = self::nombre_de_candidata_valido($item->imagen_archivo);

            if (is_null($candidata) || !Storage::disk('public')->exists($candidata)) {
                $item->fill([
                    'status'         => ImageAssignmentItem::STATUS_NO_ASIGNADA,
                    'motivo'         => 'error_interno',
                    'motivo_detalle' => 'La imagen propuesta ya no estaba en el servidor cuando se quiso aprobar.',
                    'imagen_url'     => null,
                    'imagen_archivo' => null,
                    'revisado_por'   => self::entero_o_null($auth_user_id),
                    'revisado_at'    => Carbon::now(),
                ]);
                $item->save();

                return self::respuesta(422, 'La imagen propuesta ya no está en el servidor: volvé a buscarle imagen a este artículo.', $item);
            }

            // A nombre definitivo ANTES de asignar, como las de categorías: el prefijo imgcand_ es
            // el de las candidatas, no el de una imagen real del artículo.
            $definitivo = time().rand(1, 100000).'.webp';

            if (!Storage::disk('public')->move($candidata, $definitivo)) {
                return self::respuesta(422, 'No se pudo mover la imagen en el servidor. Probá de nuevo en un momento.', $item);
            }

            $url = ApiUrlHelper::storage($definitivo);

            $imagen = Image::create([
                'hosting_url'    => $url,
                'imageable_id'   => $article->id,
                'imageable_type' => 'article',
            ]);

            // Igual que una asignada por el motor (ver ArticleImageAssignmentEngine): sin apagar
            // timestamps, para que el sync incremental del front baje el artículo con su imagen.
            $article->needs_sync_with_tn = true;
            $article->save();

            TiendaNubeSyncArticleService::add_article_to_sync($article);

            // Ya es una imagen asignada: sus avisos pasan a ser los de una asignada (contrato §5.2:
            // solo "Fondo no blanco"). Por qué había ido a revisar queda en `motivo` y en el
            // diagnóstico; "aprobada por X" lo dice revisado_por.
            $meta = is_array($item->imagen_meta) ? $item->imagen_meta : [];
            $meta['avisos'] = isset($meta['fondo_blanco']) && $meta['fondo_blanco'] === false ? ['Fondo no blanco'] : [];

            $item->fill([
                'status'         => ImageAssignmentItem::STATUS_APROBADA,
                'image_id'       => (int) $imagen->id,
                'imagen_url'     => $url,
                'imagen_archivo' => $definitivo,
                'imagen_meta'    => $meta,
                'revisado_por'   => self::entero_o_null($auth_user_id),
                'revisado_at'    => Carbon::now(),
            ]);
            $item->save();

            return self::respuesta(200, null, $item);
        });
    }

    /**
     * Rechaza una imagen "a revisar": se borra el archivo y el artículo queda en "No asignadas".
     *
     * @param  int      $owner_id
     * @param  int      $item_id
     * @param  int|null $auth_user_id
     * @return array
     */
    public static function rechazar($owner_id, $item_id, $auth_user_id = null)
    {
        return DB::transaction(function () use ($owner_id, $item_id, $auth_user_id) {
            $item = ImageAssignmentItem::where('user_id', (int) $owner_id)
                ->where('id', (int) $item_id)
                ->lockForUpdate()
                ->first();

            if (is_null($item)) {
                return self::respuesta(404, 'No se encontró esa imagen.');
            }

            if ($item->status !== ImageAssignmentItem::STATUS_A_REVISAR) {
                return self::respuesta(422, 'Esta imagen ya no está esperando revisión.', $item);
            }

            self::borrar_candidata($item->imagen_archivo);

            $quien = self::nombres_de_usuarios([$auth_user_id]);
            $quien = !is_null($auth_user_id) && isset($quien[(int) $auth_user_id]) ? $quien[(int) $auth_user_id] : null;

            $item->fill([
                'status'         => ImageAssignmentItem::STATUS_RECHAZADA,
                'motivo'         => 'rechazada',
                'motivo_detalle' => 'Se propuso una imagen y '.(is_null($quien) ? 'se rechazó' : 'la rechazó '.$quien).'.',
                'imagen_url'     => null,
                'imagen_archivo' => null,
                // Sin imagen no hay datos de imagen que mostrar (lo que se vio sigue en el diagnóstico).
                'imagen_meta'    => null,
                'revisado_por'   => self::entero_o_null($auth_user_id),
                'revisado_at'    => Carbon::now(),
            ]);
            $item->save();

            return self::respuesta(200, null, $item);
        });
    }

    /**
     * Quita una imagen ya asignada (o aprobada). Reusa ImageController::deleteImageModel() tal
     * cual —el mismo borrado que el botón de la ficha del artículo—, así Tienda Nube, Mercado Libre
     * y las vinculaciones de inventario se enteran igual que siempre.
     *
     * @param  int      $owner_id
     * @param  int      $item_id
     * @param  int|null $auth_user_id
     * @return array
     */
    public static function quitar($owner_id, $item_id, $auth_user_id = null)
    {
        return DB::transaction(function () use ($owner_id, $item_id, $auth_user_id) {
            $item = ImageAssignmentItem::where('user_id', (int) $owner_id)
                ->where('id', (int) $item_id)
                ->lockForUpdate()
                ->first();

            if (is_null($item)) {
                return self::respuesta(404, 'No se encontró esa imagen.');
            }

            if (!in_array($item->status, ImageAssignmentItem::ESTADOS_ASIGNADAS, true)) {
                return self::respuesta(422, 'Solo se puede quitar una imagen asignada.', $item);
            }

            $imagen = is_null($item->image_id) ? null : Image::find($item->image_id);

            // Si alguien ya la borró desde la ficha, no hay nada que borrar: solo se marca.
            if (!is_null($imagen)) {
                try {
                    (new ImageController())->deleteImageModel('article', (int) $item->article_id, (int) $imagen->id);
                } catch (\Throwable $e) {
                    Log::warning('[ImagenesInteligentes] No se pudo quitar la imagen.', [
                        'item_id' => $item->id,
                        'error'   => $e->getMessage(),
                    ]);

                    return self::respuesta(422, 'No se pudo quitar la imagen: '.Str::limit($e->getMessage(), 200, '…'), $item);
                }
            }

            $quien = self::nombres_de_usuarios([$auth_user_id]);
            $quien = !is_null($auth_user_id) && isset($quien[(int) $auth_user_id]) ? $quien[(int) $auth_user_id] : null;

            $item->fill([
                'status'         => ImageAssignmentItem::STATUS_QUITADA,
                'motivo'         => 'quitada',
                'motivo_detalle' => 'Se había asignado una imagen y '.(is_null($quien) ? 'se quitó' : 'la quitó '.$quien).'.',
                'imagen_url'     => null,
                'imagen_archivo' => null,
                'imagen_meta'    => null,
                'image_id'       => null,
                'revisado_por'   => self::entero_o_null($auth_user_id),
                'revisado_at'    => Carbon::now(),
            ]);
            $item->save();

            return self::respuesta(200, null, $item);
        });
    }

    /**
     * Aprueba o rechaza varias de una vez. Cada una va por su lado (una que falla no frena a las
     * demás) y se devuelve cuántas salieron y por qué falló cada una de las otras.
     *
     * @param  string   $accion        'aprobar' | 'rechazar'
     * @param  int      $owner_id
     * @param  mixed    $ids
     * @param  int|null $auth_user_id
     * @return array  ['hechos' => int, 'fallidos' => [['id', 'message']]]
     */
    public static function en_lote($accion, $owner_id, $ids, $auth_user_id = null)
    {
        $hechos   = 0;
        $fallidos = [];
        $vistos   = [];

        foreach (is_array($ids) ? $ids : [] as $id) {
            $id = (int) $id;

            if ($id <= 0 || isset($vistos[$id])) {
                continue;
            }

            $vistos[$id] = true;

            try {
                $resultado = $accion === 'aprobar'
                    ? self::aprobar($owner_id, $id, $auth_user_id)
                    : self::rechazar($owner_id, $id, $auth_user_id);
            } catch (\Throwable $e) {
                Log::warning('[ImagenesInteligentes] Falló '.$accion.' en lote.', ['item_id' => $id, 'error' => $e->getMessage()]);

                $resultado = self::respuesta(500, 'No se pudo '.$accion.': error interno.');
            }

            if ($resultado['status'] === 200) {
                $hechos++;
            } else {
                $fallidos[] = ['id' => $id, 'message' => (string) $resultado['message']];
            }
        }

        return ['hechos' => $hechos, 'fallidos' => $fallidos];
    }

    /* ----------------------------------------------------------------------------------------
     * Detener, reanudar, cerrar
     * -------------------------------------------------------------------------------------- */

    /**
     * Detiene una asignación en curso (acceso maestro). El tramo que esté trabajando termina el
     * artículo que tiene entre manos, ve el estado y corta.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @return array  ['status' => 200|422, 'message' => string|null]
     */
    public static function detener(ImageAssignmentRun $run)
    {
        if (!$run->esta_activa()) {
            return ['status' => 422, 'message' => 'La asignación no está en proceso.'];
        }

        if (!self::terminar($run, ImageAssignmentRun::STATUS_DETENIDA, 'Se detuvo desde el acceso maestro. Los artículos que faltaban quedaron pendientes: se puede reanudar.')) {
            return ['status' => 422, 'message' => 'La asignación ya había terminado.'];
        }

        return ['status' => 200, 'message' => null];
    }

    /**
     * Reanuda una asignación detenida, fallida o trabada (acceso maestro): los artículos que habían
     * quedado a medias vuelven a pendiente, se abre un registro visible nuevo si el anterior ya se
     * cerró, y se despacha un tramo.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @return array  ['status' => 200|422, 'message' => string|null]
     */
    public static function reanudar(ImageAssignmentRun $run)
    {
        return DB::transaction(function () use ($run) {
            /*
             * 🔴 La decisión se toma sobre la fila BLOQUEADA, no sobre la copia que llegó: si un
             * tramo la terminó entre que se leyó y ahora, reanudarla con la copia vieja la dejaba en
             * proceso con un registro visible huérfano. El cierre de un tramo (terminar()) es un
             * UPDATE sobre esta misma fila, así que espera a que esto termine (o esto a él).
             */
            $run = ImageAssignmentRun::where('id', $run->id)->lockForUpdate()->first();

            $trabada = !is_null($run) && $run->esta_trabada();

            if (is_null($run) || (!$trabada && !in_array($run->status, [ImageAssignmentRun::STATUS_DETENIDA, ImageAssignmentRun::STATUS_FALLIDA], true))) {
                return ['status' => 422, 'message' => 'Solo se puede reanudar una asignación detenida, fallida o que parece trabada.'];
            }

            $ahora = Carbon::now();

            // Lo que un worker muerto dejó a medias vuelve a la fila (conserva sus intentos: un
            // artículo que ya hizo caer dos veces el proceso queda como error_interno). Solo lo que
            // no se tocó hace rato: uno reciente lo está terminando un tramo vivo (ver la constante).
            DB::table('image_assignment_items')
                ->where('run_id', $run->id)
                ->where('status', ImageAssignmentItem::STATUS_PROCESANDO)
                ->where('updated_at', '<', $ahora->copy()->subMinutes(self::MINUTOS_PARA_DAR_POR_MUERTO_UN_ARTICULO))
                ->update(['status' => ImageAssignmentItem::STATUS_PENDIENTE, 'updated_at' => $ahora]);

            $pendientes = ImageAssignmentItem::where('run_id', $run->id)
                ->where('status', ImageAssignmentItem::STATUS_PENDIENTE)
                ->count();

            $run->fill([
                // La trabada sigue "en proceso" (el tramo nuevo la toma así); las otras vuelven a esperar al worker.
                'status'                     => $trabada ? ImageAssignmentRun::STATUS_EN_PROCESO : ImageAssignmentRun::STATUS_PENDIENTE,
                'motivo_estado'              => null,
                'finished_at'                => null,
                'fallos_consecutivos'        => 0,
                'errores_proveedor_seguidos' => 0,
                'last_progress_at'           => $ahora,
                'visto_at'                   => null,
            ]);

            $proceso = is_null($run->background_process_id) ? null : BackgroundProcess::find($run->background_process_id);

            if (is_null($proceso) || $proceso->esta_terminado()) {
                $nuevo = BackgroundProcessHelper::iniciar((int) $run->user_id, self::TIPO_DE_PROCESO, $run->origen === ImageAssignmentRun::ORIGEN_CATALOGO ? self::TITULO_DE_PROCESO_CATALOGO : self::TITULO_DE_PROCESO, [
                    'auth_user_id' => $run->auth_user_id,
                    'total'        => $pendientes,
                    'unidad'       => 'artículos',
                    'detalle'      => $pendientes.' artículos (reanudada)',
                    'status'       => BackgroundProcess::STATUS_PENDIENTE,
                    'etapa'        => 'En espera del procesador',
                    'referencia'   => $run,
                ]);

                if (!is_null($nuevo)) {
                    $run->background_process_id = (int) $nuevo->id;
                }
            }

            $run->save();

            ProcessImageAssignmentRunJob::dispatch((int) $run->id)->afterCommit();

            return ['status' => 200, 'message' => null];
        });
    }

    /**
     * Saca una asignación de proceso (terminada, detenida o fallida), UNA sola vez: el cambio de
     * estado es atómico y solo el que lo consigue avisa. Después emite el evento de siempre
     * (ArticleBatchImagesProcessed, payload chico) y cierra el registro visible.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @param  string      $status        terminada | detenida | fallida
     * @param  string|null $motivo        Por qué terminó así (para mostrar).
     * @param  bool        $cupo_agotado  true si el corte fue por el cupo diario.
     * @return bool  true si esta llamada la cerró.
     */
    public static function terminar(ImageAssignmentRun $run, $status, $motivo = null, $cupo_agotado = false)
    {
        $ahora = Carbon::now();

        $cambiadas = DB::table('image_assignment_runs')
            ->where('id', $run->id)
            ->whereIn('status', [ImageAssignmentRun::STATUS_PENDIENTE, ImageAssignmentRun::STATUS_EN_PROCESO])
            ->update([
                'status'        => (string) $status,
                'motivo_estado' => $motivo,
                'finished_at'   => $ahora,
                'updated_at'    => $ahora,
            ]);

        if ($cambiadas === 0) {
            return false;
        }

        $run->refresh();

        self::avisar_salida_de_proceso($run, (bool) $cupo_agotado);

        return true;
    }

    /**
     * El aviso de fin de corrida: el evento de Pusher de siempre y el cierre del registro visible.
     *
     * 🔴 El evento lleva SOLO contadores y el uuid (el constructor de siempre con los arrays
     * vacíos): Pusher corta en 10.240 bytes y un array por artículo lo pasaba con 40 artículos (ver
     * ArticleBatchImagesProcessed::broadcastWith()). El detalle lo pide la SPA por uuid.
     *
     * `skipped` son las procesadas sin imagen (no_asignada, rechazada, quitada) y `skipped_by_quota`
     * las que no llegaron a procesarse por el cupo: separadas, para que la SPA vieja no las sume dos
     * veces.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @param  bool $cupo_agotado
     * @return void
     */
    public static function avisar_salida_de_proceso(ImageAssignmentRun $run, $cupo_agotado = false)
    {
        $por_estado = DB::table('image_assignment_items')
            ->select('status', DB::raw('COUNT(*) as cantidad'))
            ->where('run_id', $run->id)
            ->groupBy('status')
            ->pluck('cantidad', 'status')
            ->all();

        $cantidad = function ($estados) use ($por_estado) {
            $suma = 0;

            foreach ($estados as $estado) {
                $suma += isset($por_estado[$estado]) ? (int) $por_estado[$estado] : 0;
            }

            return $suma;
        };

        $asignadas    = $cantidad(ImageAssignmentItem::ESTADOS_ASIGNADAS);
        $a_revisar    = $cantidad(ImageAssignmentItem::ESTADOS_A_REVISAR);
        $sin_procesar = $cantidad([ImageAssignmentItem::STATUS_SIN_PROCESAR]);
        $sin_imagen   = $cantidad([ImageAssignmentItem::STATUS_NO_ASIGNADA, ImageAssignmentItem::STATUS_RECHAZADA, ImageAssignmentItem::STATUS_QUITADA]);

        // Un aviso perdido es aceptable; voltear el cierre de la corrida por Pusher, no.
        try {
            event(new ArticleBatchImagesProcessed(
                (int) $run->user_id,
                $asignadas,
                $sin_imagen,
                [],
                $a_revisar,
                [],
                (bool) $cupo_agotado,
                $sin_procesar,
                [],
                (string) $run->uuid,
                []
            ));
        } catch (\Throwable $e) {
            Log::warning('[ImagenesInteligentes] No se pudo emitir ArticleBatchImagesProcessed.', [
                'run_id' => $run->id,
                'error'  => $e->getMessage(),
            ]);
        }

        $resultado = [
            'asignadas'    => $asignadas,
            'a_revisar'    => $a_revisar,
            'no_asignadas' => $sin_imagen + $sin_procesar,
            'busquedas'    => (int) $run->busquedas,
        ];

        if ($run->status === ImageAssignmentRun::STATUS_FALLIDA) {
            BackgroundProcessHelper::fallar($run->background_process_id, (string) ($run->motivo_estado ?: 'La asignación de imágenes falló.'), $resultado);

            return;
        }

        if ($run->status === ImageAssignmentRun::STATUS_DETENIDA) {
            $etapa = 'Detenida';
        } elseif ($cupo_agotado) {
            $etapa = 'Se agotó el cupo diario de búsquedas';
        } else {
            $etapa = 'Terminado';
        }

        BackgroundProcessHelper::completar($run->background_process_id, $resultado, $etapa);
    }

    /**
     * Borra las asignaciones terminadas hace más de DIAS_PARA_PURGAR días que no tienen nada
     * esperando revisión (esas se quedan: todavía hay una decisión pendiente). Nunca voltea a quien
     * la llama.
     *
     * @param  int $owner_id
     * @return int  Asignaciones borradas.
     */
    public static function purgar_viejas($owner_id)
    {
        try {
            $ids = ImageAssignmentRun::where('user_id', (int) $owner_id)
                ->whereIn('status', [ImageAssignmentRun::STATUS_TERMINADA, ImageAssignmentRun::STATUS_DETENIDA, ImageAssignmentRun::STATUS_FALLIDA])
                ->where('finished_at', '<', Carbon::now()->subDays(self::DIAS_PARA_PURGAR))
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('image_assignment_items')
                        ->whereColumn('image_assignment_items.run_id', 'image_assignment_runs.id')
                        ->where('image_assignment_items.status', ImageAssignmentItem::STATUS_A_REVISAR);
                })
                ->pluck('id')
                ->all();

            foreach (array_chunk($ids, 200) as $lote) {
                DB::table('image_assignment_items')->whereIn('run_id', $lote)->delete();
                DB::table('image_assignment_runs')->whereIn('id', $lote)->delete();
            }

            return count($ids);
        } catch (\Throwable $e) {
            Log::warning('[ImagenesInteligentes] No se pudieron purgar asignaciones viejas: '.$e->getMessage());

            return 0;
        }
    }

    /* ----------------------------------------------------------------------------------------
     * Internos
     * -------------------------------------------------------------------------------------- */

    /**
     * A qué grupo del contrato pertenece un estado de item.
     *
     * @param  string $status
     * @return string|null
     */
    protected static function grupo_de_estado($status)
    {
        if (in_array($status, ImageAssignmentItem::ESTADOS_ASIGNADAS, true)) {
            return 'asignadas';
        }

        if (in_array($status, ImageAssignmentItem::ESTADOS_A_REVISAR, true)) {
            return 'a_revisar';
        }

        if (in_array($status, ImageAssignmentItem::ESTADOS_NO_ASIGNADAS, true)) {
            return 'no_asignadas';
        }

        if (in_array($status, ImageAssignmentItem::ESTADOS_PENDIENTES, true)) {
            return 'pendientes';
        }

        return null;
    }

    /**
     * "Lanzada por": ComercioCity para las del catálogo (solo las lanza el acceso maestro, que entra
     * con el usuario del dueño: mostrar el nombre del dueño diría que la lanzó él), y el nombre de
     * quien la pidió para el resto.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @param  array $nombres
     * @return string|null
     */
    protected static function lanzada_por(ImageAssignmentRun $run, array $nombres)
    {
        if ($run->origen === ImageAssignmentRun::ORIGEN_CATALOGO) {
            return self::LANZADA_POR_ACCESO_MAESTRO;
        }

        $id = is_null($run->auth_user_id) ? null : (int) $run->auth_user_id;

        return !is_null($id) && isset($nombres[$id]) ? $nombres[$id] : null;
    }

    /**
     * Nombres de varios usuarios en una consulta.
     *
     * @param  array $ids
     * @return array  id => nombre
     */
    protected static function nombres_de_usuarios(array $ids)
    {
        $limpios = [];

        foreach ($ids as $id) {
            if (!is_null($id) && (int) $id > 0) {
                $limpios[(int) $id] = true;
            }
        }

        if (empty($limpios)) {
            return [];
        }

        $nombres = [];

        foreach (User::whereIn('id', array_keys($limpios))->get(['id', 'name']) as $usuario) {
            $nombres[(int) $usuario->id] = (string) $usuario->name;
        }

        return $nombres;
    }

    /**
     * El nombre de la candidata si tiene la forma exacta de una (imgcand_<uuid>.webp); null si no.
     * Nunca se mueve ni se borra un archivo cuyo nombre no sea el de una candidata.
     *
     * @param  mixed $archivo
     * @return string|null
     */
    protected static function nombre_de_candidata_valido($archivo)
    {
        $nombre = basename(trim((string) $archivo));

        if (!preg_match('/^'.ImageAssignmentItem::PREFIJO_CANDIDATA.'[a-f0-9-]{36}\.webp$/', $nombre)) {
            return null;
        }

        return $nombre;
    }

    /**
     * Borra el archivo de una candidata (si es una candidata).
     *
     * @param  mixed $archivo
     * @return void
     */
    protected static function borrar_candidata($archivo)
    {
        $nombre = self::nombre_de_candidata_valido($archivo);

        if (!is_null($nombre)) {
            try {
                Storage::disk('public')->delete($nombre);
            } catch (\Throwable $e) {
                Log::warning('[ImagenesInteligentes] No se pudo borrar la candidata '.$nombre.': '.$e->getMessage());
            }
        }
    }

    /**
     * Acota el tamaño de página a 10..100 (25 por defecto).
     *
     * @param  mixed $por_pagina
     * @return int
     */
    protected static function por_pagina($por_pagina)
    {
        $por_pagina = (int) $por_pagina;

        if ($por_pagina <= 0) {
            return self::POR_PAGINA_DEFECTO;
        }

        return max(self::POR_PAGINA_MINIMO, min(self::POR_PAGINA_MAXIMO, $por_pagina));
    }

    /**
     * @param  int         $status
     * @param  string|null $message
     * @param  \App\Models\ImageAssignmentItem|null $item
     * @return array
     */
    protected static function respuesta($status, $message = null, $item = null)
    {
        return ['status' => (int) $status, 'message' => $message, 'item' => $item];
    }

    /**
     * @param  mixed $valor
     * @return int|null
     */
    protected static function entero_o_null($valor)
    {
        return is_null($valor) || (int) $valor <= 0 ? null : (int) $valor;
    }

    /**
     * @param  mixed $fecha
     * @return string|null  ISO 8601 con zona (mismo formato que BackgroundProcessHelper).
     */
    protected static function fecha($fecha)
    {
        return is_null($fecha) ? null : Carbon::parse($fecha)->toIso8601String();
    }
}
