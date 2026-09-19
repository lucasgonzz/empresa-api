<?php

namespace App\Jobs;

use App\Events\ChatIaMensajeActualizado;
use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\CategoriaImagenHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaImagenCategoriaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\BackgroundProcess;
use App\Models\Category;
use App\Services\CategoriaImagenValidacionService;
use App\Services\Traits\BusquedaDeImagenesEnGoogle;
use App\Services\Traits\GoogleSearchHelpers;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Busca en Google una imagen para cada categoría pedida, la verifica por visión y la asigna
 * sola cuando está seguro; lo dudoso vuelve por la misma conversación del asistente como una
 * tarjeta con la imagen candidata (misión asistente-masivas-imagenes-y-remito, 19/9/2026,
 * plan §4.4 y §4.5).
 *
 * Por categoría, en orden:
 *   1. Si el contador del día llegó a la cuota → la categoría (y las que siguen) van a
 *      `sin_cuota` y el loop corta. Es el mismo contador que usan los artículos.
 *   2. q1 = "productos de <nombre> fondo blanco" con `imgDominantColor = white`;
 *      q2 = "<nombre> productos" sin extras, solo si q1 no dio `usar`. Con buscar_como, el
 *      texto de la persona va tal cual (q1 le suma "fondo blanco").
 *   3. Por candidata (máximo MAX_CANDIDATAS_POR_QUERY): prefiltro de texto → descarga y recorte
 *      (renombrada a `catcand_<uuid>.webp`, para poder purgarla) → veredicto por visión.
 *      `usar` asigna y borra el resto; `dudosa` guarda la PRIMERA (archivo + url + motivo) y
 *      borra las demás; `descartar` borra.
 *   4. Sin `usar`: con dudosa → `dudosas`; sin nada → `sin_resultado`.
 *
 * 🔴 Todo lo que escribe este job va por `owner_id` explícito: en el worker no hay Auth, y
 * `UserHelper::userId()` ahí devuelve el USER_ID de config, o sea otro comercio.
 *
 * 🔴 El mensaje de cierre y sus tarjetas se escriben DESPUÉS de completar el registro visible y
 * en su propio try/catch: las imágenes ya están asignadas, y un aviso que falla no puede
 * volcar una corrida que terminó (clase "el aviso que voltea la operación que ya terminó").
 *
 * El trait BusquedaDeImagenesEnGoogle lee `$this->user_id`, `$this->google_api_key` y `$this->cx`:
 * por eso el dueño se guarda también como `user_id`.
 */
class ProcessCategoryImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, GoogleSearchHelpers, BusquedaDeImagenesEnGoogle;

    /** @var int Sin reintentos: un reintento volvería a gastar cuota de Google y llamadas de visión. */
    public $tries = 1;

    /** @var int 30 minutos, igual que el job de artículos: cada candidata es una llamada de visión. */
    public $timeout = 1800;

    /**
     * Máximo de candidatas de Google que se evalúan por query. Son 5 y no 3 (lo que usa el job
     * de artículos) porque el piso de tamaño de abajo descarta gratis los thumbnails, que en una
     * búsqueda de rubro suelen ser la mitad de los resultados bajables: con 3, dos o tres
     * categorías por corrida se quedaban sin ninguna candidata que llegara a la visión.
     */
    const MAX_CANDIDATAS_POR_QUERY = 5;

    /**
     * Lado menor mínimo, en píxeles, para que una candidata llegue a la visión. 250 deja pasar
     * cualquier foto de producto real y deja afuera los thumbnails de Google (100-160 px).
     */
    const MIN_LADO_PX = 250;

    /** Tipo del registro visible (contrato §5). La constante de la SPA está en tipos.js. */
    const TIPO_PROCESO = 'imagenes_categorias';

    /** Título del registro visible. */
    const TITULO_PROCESO = 'Imágenes de categorías';

    /** Hasta cuántos nombres se listan en el mensaje de cierre antes del "+N más". */
    const NOMBRES_EN_EL_MENSAJE = 10;

    /** @var int Dueño del comercio. */
    protected $owner_id;

    /** @var int Alias de $owner_id para el trait BusquedaDeImagenesEnGoogle (lee $this->user_id). */
    protected $user_id;

    /** @var int|null La persona que lo pidió (auth_user_id del registro visible). */
    protected $auth_user_id;

    /** @var int Conversación del asistente donde se escribe el cierre. */
    protected $ai_conversation_id;

    /** @var array [['id' => int, 'buscar_como' => string|null], ...] */
    protected $categorias;

    /** @var string Clave de Google Custom Search. */
    protected $google_api_key;

    /** @var string Id del motor de búsqueda personalizado. */
    protected $cx;

    /** @var int Cuota diaria de búsquedas del dueño. */
    protected $google_cuota;

    /**
     * @var string 'sin_imagen' | 'todas'. Con 'sin_imagen' una categoría que al momento de
     * procesarla YA tiene imagen (alguien la cargó a mano entre la tarjeta y el worker) se saltea
     * en vez de pisarla. Default 'sin_imagen': un job encolado antes de este cambio se deserializa
     * sin la property y toma el camino que no reemplaza nada.
     */
    protected $alcance = 'sin_imagen';

    /**
     * @var int|null Id del registro visible que abrió quien encoló (pendiente). Con él se retoma
     * ESE registro y no "el último activo del tipo", que con dos tandas seguidas del mismo dueño
     * era el de la otra tanda. Null (job viejo) → se cae al último activo, como antes.
     */
    protected $background_process_id = null;

    /**
     * @param int         $owner_id
     * @param int|null    $auth_user_id
     * @param int         $ai_conversation_id
     * @param array       $categorias  [['id' => int, 'buscar_como' => string|null], ...]
     * @param string      $google_api_key
     * @param string      $cx
     * @param int         $google_cuota
     * @param string      $alcance                'sin_imagen' | 'todas' (ver la property).
     * @param int|null    $background_process_id  El registro visible pendiente que abrió el encolado.
     */
    public function __construct(
        int $owner_id,
        $auth_user_id,
        int $ai_conversation_id,
        array $categorias,
        string $google_api_key,
        string $cx,
        int $google_cuota,
        $alcance = 'sin_imagen',
        $background_process_id = null
    ) {
        $this->owner_id           = $owner_id;
        $this->user_id            = $owner_id;
        $this->auth_user_id       = is_null($auth_user_id) ? null : (int) $auth_user_id;
        $this->ai_conversation_id = $ai_conversation_id;
        $this->categorias         = array_values($categorias);
        $this->google_api_key     = $google_api_key;
        $this->cx                 = $cx;
        $this->google_cuota       = $google_cuota;
        $this->alcance            = $alcance === 'todas' ? 'todas' : 'sin_imagen';
        $this->background_process_id = is_null($background_process_id) ? null : (int) $background_process_id;
    }

    /**
     * @return void
     */
    public function handle()
    {
        CategoriaImagenHelper::purgar_candidatas_viejas();

        // Una sola instancia: el contador de llamadas a la IA (max_calls_batch) es por corrida.
        $validador = new CategoriaImagenValidacionService();

        $counter = $this->get_or_create_counter();

        $total = count($this->categorias);

        Log::info(sprintf(
            '[ImagenesCategorias] Inicio (%d categorías), owner %d, api_key ...%s, contador del día %d de %d',
            $total,
            $this->owner_id,
            substr($this->google_api_key, -8),
            (int) $counter->counter,
            $this->google_cuota
        ));

        $proceso = $this->retomar_o_abrir_proceso_visible([
            'total'   => $total,
            'unidad'  => 'categorías',
            'detalle' => $total.' categorías',
            'etapa'   => 'Buscando imágenes',
        ]);

        /** Nombres por resultado, para el mensaje de cierre. */
        $asignadas     = [];
        $sin_resultado = [];
        $sin_cuota     = [];
        $ya_tenian     = [];

        /** Una por categoría dudosa: category_id, nombre, archivo, url, motivo, buscar_como. */
        $dudosas = [];

        $recorridas = 0;

        foreach ($this->categorias as $indice => $pedido) {
            $recorridas++;

            BackgroundProcessHelper::incrementar($proceso, 1, [
                'etapa'     => 'Categoría '.$recorridas.' de '.$total,
                'resultado' => $this->resultado_parcial($asignadas, $dudosas, $sin_resultado, $sin_cuota),
            ]);

            $categoria = Category::where('user_id', $this->owner_id)
                ->where('id', isset($pedido['id']) ? (int) $pedido['id'] : 0)
                ->first();

            if (is_null($categoria)) {
                Log::info('[ImagenesCategorias] La categoría ya no existe; se saltea.', ['pedido' => $pedido]);
                continue;
            }

            $buscar_como = isset($pedido['buscar_como']) ? trim((string) $pedido['buscar_como']) : '';

            /*
             * Con alcance 'sin_imagen' la tanda es "llenar huecos": si la categoría ya tiene imagen
             * cuando el worker llega (la cargaron a mano entre la tarjeta y ahora, o dos tandas se
             * pisaron), NO se reemplaza. Se cuenta aparte y el cierre lo dice.
             */
            if ($this->alcance === 'sin_imagen' && trim((string) $categoria->image_url) !== '') {
                $ya_tenian[] = $categoria->name;

                Log::info('[ImagenesCategorias] La categoría ya tiene imagen; con alcance sin_imagen se saltea.', [
                    'category_id' => (int) $categoria->id,
                ]);
                continue;
            }

            if ($counter->counter >= $this->google_cuota) {
                // Esta y todas las que siguen: no hay con qué buscar hasta mañana.
                $sin_cuota[] = $categoria->name;

                foreach (array_slice($this->categorias, $indice + 1) as $restante) {
                    $nombre = Category::where('user_id', $this->owner_id)
                        ->where('id', isset($restante['id']) ? (int) $restante['id'] : 0)
                        ->value('name');

                    if (!is_null($nombre)) {
                        $sin_cuota[] = $nombre;
                    }
                }

                Log::info('[ImagenesCategorias] Cuota agotada, se corta el procesamiento.', [
                    'owner_id' => $this->owner_id,
                    'counter'  => (int) $counter->counter,
                    'cuota'    => $this->google_cuota,
                ]);
                break;
            }

            $resultado = $this->procesar_categoria($categoria, $buscar_como, $counter, $validador);

            if ($resultado['estado'] === 'asignada') {
                $asignadas[] = $categoria->name;
            } elseif ($resultado['estado'] === 'dudosa') {
                $dudosas[] = $resultado['dudosa'];
            } else {
                $sin_resultado[] = $categoria->name;
            }
        }

        $resultado_final = $this->resultado_parcial($asignadas, $dudosas, $sin_resultado, $sin_cuota);

        BackgroundProcessHelper::completar(
            $proceso,
            $resultado_final,
            count($sin_cuota) ? 'Se agotó la cuota diaria de búsquedas' : 'Terminado'
        );

        Log::info('[ImagenesCategorias] Finalizado.', array_merge(['owner_id' => $this->owner_id], $resultado_final));

        $this->escribir_cierre_en_la_conversacion($asignadas, $dudosas, $sin_resultado, $sin_cuota, $ya_tenian);
    }

    /**
     * Cierra en fallo el registro visible cuando el job muere (excepción del handle() o muerte
     * sin catch: OOM, timeout, worker reiniciado). failed() corre sobre la instancia deserializada
     * del payload, así que nada de lo que handle() guardó en memoria existe acá: se busca por tipo
     * y dueño.
     *
     * @param  \Throwable|null $e
     * @return void
     */
    public function failed($e)
    {
        $motivo = !is_null($e) && $e->getMessage() !== ''
            ? $e->getMessage()
            : 'El proceso se interrumpió sin dejar traza (probable falta de memoria, timeout o worker reiniciado).';

        BackgroundProcessHelper::fallar(BackgroundProcessHelper::ultimo_activo($this->owner_id, self::TIPO_PROCESO), $motivo);
    }

    /**
     * El pipeline de UNA categoría: hasta dos queries, hasta tres candidatas por query.
     *
     * @param  \App\Models\Category               $categoria
     * @param  string                             $buscar_como
     * @param  \App\Models\GeocoderCounter        $counter
     * @param  CategoriaImagenValidacionService   $validador
     * @return array  ['estado' => 'asignada'|'dudosa'|'sin_resultado', 'dudosa' => array|null]
     */
    protected function procesar_categoria(Category $categoria, $buscar_como, $counter, CategoriaImagenValidacionService $validador)
    {
        $termino = $buscar_como !== '' ? $buscar_como : trim((string) $categoria->name);

        /*
         * q1 pide fondo blanco a Google (imgDominantColor) además de decirlo en el texto; q2 es
         * el término sin el filtro de color, por si ese filtro deja afuera la única foto buena
         * del rubro.
         *
         * 🔴 EL NOMBRE DE LA CATEGORÍA NO VA PELADO: va como "productos de <nombre>". Medido el
         * 19/9/2026 con la primera versión ("<nombre> producto fondo blanco" / "<nombre>"): para
         * "Bazar" Google devolvió fachadas de bazares, logos y calles; para "Jardín", fotos de
         * jardines reales — la visión las descartó todas (bien) y las dos categorías quedaron sin
         * imagen. El nombre de una categoría es un RUBRO, no un producto, y buscado solo trae el
         * lugar o la escena; "productos de <rubro>" trae los artículos que se venden en ese rubro,
         * que es lo que una imagen de categoría tiene que mostrar. Cuando la persona dijo cómo
         * buscar (buscar_como), su texto va tal cual: ya eligió ella el término.
         */
        $base_q1 = $buscar_como !== '' ? $termino : 'productos de '.$termino;
        $base_q2 = $buscar_como !== '' ? $termino : $termino.' productos';

        $queries = [
            ['query' => $base_q1.' fondo blanco', 'extras' => ['imgDominantColor' => 'white']],
            ['query' => $base_q2, 'extras' => []],
        ];

        /** La primera dudosa de esta categoría (archivo, url, motivo), o null. */
        $dudosa = null;

        foreach ($queries as $numero => $busqueda) {
            if ($counter->counter >= $this->google_cuota) {
                /*
                 * Se acabó la cuota entre q1 y q2. Si q1 dejó una dudosa, se le muestra a la
                 * persona (algo es mejor que nada); si no, la categoría queda sin resultado y
                 * el loop de arriba va a cortar en la próxima vuelta.
                 */
                Log::info('[ImagenesCategorias] Cuota agotada antes de la query '.($numero + 1).'.', [
                    'category_id' => (int) $categoria->id,
                ]);
                break;
            }

            $resultado = $this->fetch_google_image_results($busqueda['query'], $counter, $busqueda['extras']);

            $items = $resultado['items'];

            Log::info(sprintf(
                '[ImagenesCategorias] Categoría #%d "%s": query %d ("%s") → %s',
                $categoria->id,
                $categoria->name,
                $numero + 1,
                $busqueda['query'],
                is_null($items) ? 'error Google: '.($resultado['api_error'] ?: 'desconocido') : count($items).' items'
            ));

            if (is_null($items) || empty($items)) {
                continue;
            }

            $posicion = 0;

            foreach ($items as $item) {
                $posicion++;

                if ($posicion > self::MAX_CANDIDATAS_POR_QUERY) {
                    break;
                }

                $veredicto = $this->evaluar_candidata($categoria, $item, $posicion, $validador);

                if (is_null($veredicto)) {
                    continue;
                }

                if ($veredicto['veredicto'] === 'usar') {
                    // A nombre definitivo ANTES de asignar: un `catcand_*` asignado lo borraría la
                    // purga de tres días (ver CategoriaImagenHelper::promover_candidata).
                    $definitiva = CategoriaImagenHelper::promover_candidata($veredicto['archivo']);

                    if (is_null($definitiva)) {
                        // Rarísimo (el disco no dejó renombrar): se trata como descartada antes
                        // que dejar una categoría apuntando a un archivo que se va a purgar.
                        $this->borrar_archivo($veredicto['archivo']);
                        continue;
                    }

                    CategoriaImagenHelper::asignar($categoria, $definitiva['url']);

                    if (!is_null($dudosa)) {
                        $this->borrar_archivo($dudosa['archivo']);
                    }

                    Log::info('[ImagenesCategorias] Imagen asignada.', [
                        'category_id' => (int) $categoria->id,
                        'archivo'     => $definitiva['archivo'],
                    ]);

                    return ['estado' => 'asignada', 'dudosa' => null];
                }

                if ($veredicto['veredicto'] === 'dudosa' && is_null($dudosa)) {
                    $dudosa = [
                        'category_id' => (int) $categoria->id,
                        'nombre'      => (string) $categoria->name,
                        'archivo'     => $veredicto['archivo'],
                        'url'         => $veredicto['url'],
                        'motivo'      => $veredicto['motivo'],
                        'buscar_como' => $buscar_como !== '' ? $buscar_como : null,
                    ];
                    continue;
                }

                // `descartar`, o una segunda dudosa: no se guarda nada de esto.
                $this->borrar_archivo($veredicto['archivo']);
            }
        }

        if (!is_null($dudosa)) {
            return ['estado' => 'dudosa', 'dudosa' => $dudosa];
        }

        return ['estado' => 'sin_resultado', 'dudosa' => null];
    }

    /**
     * Prefiltro → descarga → renombre a candidata → veredicto por visión, para UN item de Google.
     * Devuelve null si la candidata se descartó antes de llegar a la IA (prefiltro o descarga).
     *
     * @param  \App\Models\Category             $categoria
     * @param  array                            $item
     * @param  int                              $posicion
     * @param  CategoriaImagenValidacionService $validador
     * @return array|null  ['veredicto', 'archivo', 'url', 'motivo']
     */
    protected function evaluar_candidata(Category $categoria, array $item, $posicion, CategoriaImagenValidacionService $validador)
    {
        $prefiltro = $validador->prefilter($item);

        if ($prefiltro['rejected']) {
            Log::info(sprintf('[ImagenesCategorias] Categoría #%d, candidata %d: %s', $categoria->id, $posicion, $prefiltro['reason']));

            return null;
        }

        $link = isset($item['link']) ? (string) $item['link'] : '';

        $descarga = $link !== '' ? $this->download_crop_and_save($link) : ['url' => null, 'failure' => 'http', 'http_status' => null];

        // Mismo fallback que el job de artículos: si el sitio no deja bajar el link directo, el
        // thumbnail de Google casi siempre se deja.
        if ($descarga['url'] === null && !empty($item['image']['thumbnailLink'])) {
            $descarga = $this->download_crop_and_save((string) $item['image']['thumbnailLink']);
        }

        if ($descarga['url'] === null) {
            Log::info(sprintf('[ImagenesCategorias] Categoría #%d, candidata %d: no se pudo descargar (%s).', $categoria->id, $posicion, (string) $descarga['failure']));

            return null;
        }

        $candidata = $this->renombrar_a_candidata($descarga['url']);

        if (is_null($candidata)) {
            return null;
        }

        $binario = @file_get_contents($candidata['ruta']);

        if ($binario === false || $binario === '') {
            $this->borrar_archivo($candidata['archivo']);

            return null;
        }

        /*
         * 🔴 PISO DE TAMAÑO ANTES DE GASTAR UNA LLAMADA DE VISIÓN. Medido el 19/9/2026 con la
         * primera corrida real: cuando el link directo no se deja bajar (403 de Shutterstock y
         * parecidos) el fallback al thumbnail de Google devuelve imágenes de 100-160 px, y la
         * visión las dio por "usar" igual — una de 109 px con marca de agua de un banco de
         * imágenes quedó como imagen de la categoría Bazar. Una imagen de categoría se ve en la
         * portada de la tienda: abajo de MIN_LADO_PX no sirve aunque sea "correcta", y la visión
         * no mide píxeles. Se descarta acá, gratis, y sigue la próxima candidata.
         */
        $dimensiones = @getimagesizefromstring($binario);
        $lado_menor  = is_array($dimensiones) ? min((int) $dimensiones[0], (int) $dimensiones[1]) : 0;

        if ($lado_menor < self::MIN_LADO_PX) {
            Log::info(sprintf(
                '[ImagenesCategorias] Categoría #%d, candidata %d: descartada sin analizar, muy chica (%dx%d, el mínimo es %d px).',
                $categoria->id,
                $posicion,
                is_array($dimensiones) ? (int) $dimensiones[0] : 0,
                is_array($dimensiones) ? (int) $dimensiones[1] : 0,
                self::MIN_LADO_PX
            ));

            $this->borrar_archivo($candidata['archivo']);

            return null;
        }

        $validacion = $validador->validar_para_categoria($binario, (string) $categoria->name, $this->owner_id, (int) $categoria->id);

        Log::info(sprintf(
            '[ImagenesCategorias] Categoría #%d, candidata %d: veredicto %s (evaluada: %s) — %s',
            $categoria->id,
            $posicion,
            $validacion['veredicto'],
            $validacion['evaluated'] ? 'sí' : 'no',
            $validacion['motivo']
        ));

        return [
            'veredicto' => $validacion['veredicto'],
            'archivo'   => $candidata['archivo'],
            'url'       => $candidata['url'],
            'motivo'    => $validacion['motivo'],
        ];
    }

    /**
     * Renombra el archivo que dejó download_crop_and_save() a `catcand_<uuid>.webp`. El prefijo es
     * lo que permite purgar las candidatas que nadie usó (CategoriaImagenHelper::purgar_candidatas_viejas)
     * sin tocar las imágenes reales de la carpeta, que se llaman `<time><rand>.webp`.
     *
     * @param  string $url_publica  Lo que devolvió download_crop_and_save().
     * @return array|null  ['archivo' => nombre, 'ruta' => absoluta, 'url' => pública]
     */
    protected function renombrar_a_candidata($url_publica)
    {
        $original = basename((string) parse_url($url_publica, PHP_URL_PATH));
        $ruta_original = storage_path('app/public/'.$original);

        if ($original === '' || !is_file($ruta_original)) {
            return null;
        }

        $archivo = CategoriaImagenHelper::PREFIJO_CANDIDATA.(string) Str::uuid().'.webp';
        $ruta    = storage_path('app/public/'.$archivo);

        if (!@rename($ruta_original, $ruta)) {
            @unlink($ruta_original);

            return null;
        }

        return ['archivo' => $archivo, 'ruta' => $ruta, 'url' => ApiUrlHelper::storage($archivo)];
    }

    /**
     * @param  string $archivo  Nombre de la candidata.
     * @return void
     */
    protected function borrar_archivo($archivo)
    {
        $ruta = CategoriaImagenHelper::ruta_de_candidata($archivo);

        if (!is_null($ruta) && is_file($ruta)) {
            @unlink($ruta);
        }
    }

    /**
     * El registro visible de esta corrida, retomando el `pendiente` que dejó el encolado
     * (PropuestaImagenesCategoriasIaHelper::ejecutar) o abriendo uno si no hay. Solo se retoma
     * un `pendiente`: uno `en_proceso` es de otra corrida.
     *
     * @param  array $opciones  total, unidad, detalle, etapa.
     * @return \App\Models\BackgroundProcess|null
     */
    protected function retomar_o_abrir_proceso_visible(array $opciones)
    {
        $pendiente = null;

        // Primero el registro que abrió quien encoló (por id); recién si no vino o ya no está
        // activo, el último activo del tipo (jobs encolados antes de que viajara el id).
        if (!is_null($this->background_process_id)) {
            $pendiente = BackgroundProcess::where('user_id', $this->owner_id)
                ->where('id', $this->background_process_id)
                ->where('tipo', self::TIPO_PROCESO)
                ->first();
        }

        if (is_null($pendiente) || $pendiente->status !== BackgroundProcess::STATUS_PENDIENTE) {
            $pendiente = BackgroundProcessHelper::ultimo_activo($this->owner_id, self::TIPO_PROCESO);
        }

        if (!is_null($pendiente) && $pendiente->status === BackgroundProcess::STATUS_PENDIENTE) {
            $opciones['forzar_broadcast'] = true;

            return BackgroundProcessHelper::avanzar($pendiente, 0, $opciones);
        }

        return BackgroundProcessHelper::iniciar($this->owner_id, self::TIPO_PROCESO, self::TITULO_PROCESO, array_merge($opciones, [
            'auth_user_id' => $this->auth_user_id,
        ]));
    }

    /**
     * Los cinco contadores del contrato §5.
     *
     * @param  array $asignadas
     * @param  array $dudosas
     * @param  array $sin_resultado
     * @param  array $sin_cuota
     * @return array
     */
    protected function resultado_parcial(array $asignadas, array $dudosas, array $sin_resultado, array $sin_cuota)
    {
        return [
            'procesadas'    => count($asignadas) + count($dudosas) + count($sin_resultado),
            'asignadas'     => count($asignadas),
            'dudosas'       => count($dudosas),
            'sin_resultado' => count($sin_resultado),
            'sin_cuota'     => count($sin_cuota),
        ];
    }

    /**
     * El mensaje de cierre (plan §4.5) más una tarjeta por dudosa, en la conversación desde la
     * que se pidió. Protegido entero: ver el docblock de la clase.
     *
     * @param  array $asignadas
     * @param  array $dudosas
     * @param  array $sin_resultado
     * @param  array $sin_cuota
     * @return void
     */
    protected function escribir_cierre_en_la_conversacion(array $asignadas, array $dudosas, array $sin_resultado, array $sin_cuota, array $ya_tenian = [])
    {
        try {
            $conversation = AiConversation::where('id', $this->ai_conversation_id)
                ->where('user_id', $this->owner_id)
                ->first();

            if (is_null($conversation)) {
                Log::info('[ImagenesCategorias] La conversación ya no existe; no hay dónde escribir el cierre.', [
                    'ai_conversation_id' => $this->ai_conversation_id,
                ]);

                return;
            }

            $mensaje = AiMessage::create([
                'ai_conversation_id'   => $conversation->id,
                'rol'                  => 'assistant',
                'canal'                => AiMessage::CANAL_SISTEMA,
                'tipo'                 => AiMessage::TIPO_TEXTO,
                'estado'               => 'listo',
                'acciones_habilitadas' => true,
                'contenido'            => $this->texto_de_cierre($asignadas, $dudosas, $sin_resultado, $sin_cuota, $ya_tenian),
            ]);

            $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

            foreach ($dudosas as $dudosa) {
                $categoria = Category::where('user_id', $this->owner_id)->where('id', $dudosa['category_id'])->first();

                if (is_null($categoria)) {
                    continue;
                }

                PropuestaImagenCategoriaIaHelper::crear_desde_job(
                    $contexto,
                    $mensaje,
                    $categoria,
                    $dudosa['archivo'],
                    $dudosa['url'],
                    $dudosa['motivo'],
                    $dudosa['buscar_como']
                );
            }

            $conversation->last_message_at = Carbon::now();
            $conversation->save();
        } catch (\Throwable $e) {
            Log::error('[ImagenesCategorias] No se pudo escribir el cierre en la conversación: '.$e->getMessage(), [
                'ai_conversation_id' => $this->ai_conversation_id,
            ]);

            return;
        }

        // El aviso, aparte y protegido: un Pusher caído no voltea nada de lo de arriba.
        try {
            event(new ChatIaMensajeActualizado(
                (int) $conversation->auth_user_id,
                (int) $conversation->id,
                (int) $mensaje->id,
                'listo'
            ));
        } catch (\Throwable $e) {
            Log::warning('[ImagenesCategorias] No se pudo emitir ChatIaMensajeActualizado: '.$e->getMessage(), [
                'ai_message_id' => (int) $mensaje->id,
            ]);
        }
    }

    /**
     * El texto plano del cierre, determinístico y sin llamar a la IA. Cada renglón va solo si aplica.
     *
     * @param  array $asignadas
     * @param  array $dudosas
     * @param  array $sin_resultado
     * @param  array $sin_cuota
     * @return string
     */
    public function texto_de_cierre(array $asignadas, array $dudosas, array $sin_resultado, array $sin_cuota, array $ya_tenian = [])
    {
        $renglones = ['Terminé de buscar imágenes para las categorías.'];

        if (count($asignadas)) {
            $renglones[] = 'Asigné imagen a '.count($asignadas).': '.$this->lista_de_nombres($asignadas, ', ').'.';
        }

        if (count($dudosas)) {
            $nombres = [];

            foreach ($dudosas as $dudosa) {
                $nombres[] = $dudosa['nombre'];
            }

            $renglones[] = count($dudosas) === 1
                ? 'Para '.$nombres[0].' encontré una imagen pero no estoy seguro de que corresponda: mirá la tarjeta de abajo y, si te sirve, tocá Usar esta imagen.'
                : 'Para '.$this->lista_de_nombres($nombres).' encontré una imagen pero no estoy seguro de que corresponda: mirá las tarjetas de abajo y tocá Usar esta imagen en las que te sirvan.';
        }

        if (count($sin_resultado)) {
            $renglones[] = count($sin_resultado) === 1
                ? 'Para '.$sin_resultado[0].' no encontré ninguna imagen que sirva. Si querés, decime con qué otro nombre la busco.'
                : 'Para '.$this->lista_de_nombres($sin_resultado).' no encontré ninguna imagen que sirva. Si querés, decime con qué otro nombre las busco.';
        }

        if (count($sin_cuota)) {
            $renglones[] = count($sin_cuota) === 1
                ? 'Me quedé sin búsquedas por hoy para 1 categoría ('.$sin_cuota[0].'): pedímelo de nuevo mañana.'
                : 'Me quedé sin búsquedas por hoy para '.count($sin_cuota).' categorías ('.$this->lista_de_nombres($sin_cuota, ', ').'): pedímelo de nuevo mañana.';
        }

        if (count($ya_tenian)) {
            $renglones[] = count($ya_tenian) === 1
                ? $ya_tenian[0].' ya tenía imagen cuando llegué, así que la dejé como estaba.'
                : $this->lista_de_nombres($ya_tenian).' ya tenían imagen cuando llegué, así que las dejé como estaban.';
        }

        return implode("\n", $renglones);
    }

    /**
     * "A, B y C", hasta NOMBRES_EN_EL_MENSAJE nombres y "+N más" si hay más. Con separador ', '
     * fijo (sin la "y") para las listas después de dos puntos.
     *
     * @param  array       $nombres
     * @param  string|null $separador_final  null = " y " antes del último.
     * @return string
     */
    protected function lista_de_nombres(array $nombres, $separador_final = null)
    {
        $nombres  = array_values($nombres);
        $visibles = array_slice($nombres, 0, self::NOMBRES_EN_EL_MENSAJE);
        $restan   = count($nombres) - count($visibles);

        if (is_null($separador_final) && count($visibles) > 1 && $restan === 0) {
            $ultimo = array_pop($visibles);
            $texto  = implode(', ', $visibles).' y '.$ultimo;
        } else {
            $texto = implode(', ', $visibles);
        }

        return $restan > 0 ? $texto.' +'.$restan.' más' : $texto;
    }
}
