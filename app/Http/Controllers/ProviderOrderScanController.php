<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\providerOrder\FacturaDeCompraHelper;
use App\Http\Controllers\Helpers\providerOrder\ModoFacturacionHelper;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Http\Controllers\Helpers\providerOrder\ProviderOrderScanAltaHelper;
use App\Models\Article;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderScanImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Escaneo de facturas de compra con IA (misión escaneo-factura-compra).
 *
 * El usuario saca una o varias fotos de la factura/remito del proveedor, el sistema las manda
 * juntas a Claude en una sola llamada (eso lo hace el job, no este controlador), y cuando el
 * resultado está listo el usuario lo revisa, lo corrige a mano y lo confirma. Recién en la
 * confirmación se toca la compra.
 *
 * 🔴 Toda consulta por uuid lleva ADEMÁS `where('user_id', $this->userId())`. Sin ese segundo
 * filtro, iterar UUIDs deja leer las facturas de otro comercio — con CUIT, razón social y precios
 * adentro. Vale igual para el endpoint que sirve el binario de la foto.
 *
 * 🔴 La confirmación corre en el request autenticado y NUNCA en un job: `NewProviderOrderHelper`
 * y `ModoFacturacionHelper` resuelven el usuario con `UserHelper::user()`, que devuelve null sin
 * sesión ni Auth y explota más adelante sin guarda (ver §4.6.1 del plan de la misión).
 */
class ProviderOrderScanController extends Controller
{
    /** Estados en los que un escaneo todavía está ocupando la cola para esa compra. */
    const ESTADOS_EN_CURSO = ['pendiente', 'procesando'];

    /**
     * Cuánto vale un escaneo "en curso" para bloquear la compra con un 409.
     *
     * 🔴 El bloqueo TIENE que vencer. El único desatascador de un escaneo trabado en
     * 'pendiente'/'procesando' es failed() del job, y ese método solo corre si el job llegó a
     * ejecutarse: con el worker caído al despachar, o con el job perdido (queue:restart, flush
     * de Redis), la fila queda en 'pendiente' para siempre. Y no hay salida por la interfaz —
     * 'descartar' solo se alcanza desde el modal de revisión, que solo se abre con el botón
     * rojo, que solo se enciende con estado = 'listo'. Sin vencimiento, el usuario ve "Esta
     * compra ya tiene un escaneo en curso" hasta el fin de los tiempos.
     *
     * 60 minutos: el job tiene $timeout = 600 (10 min), así que una corrida viva jamás llega a
     * esta antigüedad ni sumándole la espera en la cola. Un escaneo más viejo que esto no está
     * corriendo: está abandonado, y no puede seguir bloqueando la compra.
     */
    const MINUTOS_ESCANEO_EN_CURSO = 60;

    /**
     * Código interno con el que aplicar_confirmacion() avisa, desde adentro de la transacción,
     * que otro request ganó la carrera y el escaneo ya estaba gestionado. confirmar() lo
     * traduce a un 409. No es un código HTTP: es el `code` de la excepción.
     */
    const CODIGO_YA_GESTIONADO = 4090;

    /**
     * Extensiones de imagen aceptadas al subir las páginas de la factura.
     *
     * 🔴 `heic` NO está en la lista, y sacarlo fue deliberado. GD —la librería que usa
     * Intervention para redimensionar— no lo decodifica (verificalo con `gd_info()`: no hay
     * ninguna clave HEIC), así que aceptarlo solo servía para que el archivo pasara la
     * validación y muriera dos pasos después con un "no se pudo procesar, probá con otra
     * foto" que le iba a fallar igual las tres veces que lo intentara.
     *
     * Es el formato por default de la cámara del iPhone, o sea justo el aparato con el que se
     * van a sacar estas fotos. Lo que salva el caso normal es que Safari convierte a JPEG solo
     * cuando la foto se elige con un <input type="file">; el HEIC crudo llega únicamente si el
     * usuario lo sube a mano desde la app Archivos. Para ese caso, el mensaje de abajo le dice
     * qué hacer en vez de dejarlo probando.
     */
    const EXTENSIONES_PERMITIDAS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * POST /api/provider-order-scan
     *
     * Encola el escaneo: valida, redimensiona y guarda las fotos, crea la corrida en "pendiente"
     * y despacha el job. No espera el resultado (una factura de varias páginas contra Claude
     * tarda más que cualquier timeout de proxy razonable): devuelve 202 y el usuario sigue
     * trabajando.
     *
     * Request (multipart/form-data): provider_order_id (int) + imagenes[] (1..max_imagenes).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $owner_id = $this->userId();

        /* Máximos de config; los defaults repiten los de config/services.php por si la clave falta. */
        $max_imagenes = (int) config('services.escaneo_factura_compra.max_imagenes', 6);
        $max_imagenes = $max_imagenes > 0 ? $max_imagenes : 6;

        $max_mb = (int) config('services.escaneo_factura_compra.max_mb', 12);
        $max_mb = $max_mb > 0 ? $max_mb : 12;

        /*
         * Validaciones síncronas: rechazan ANTES de tocar storage o la cola. Son instantáneas y
         * cubren el caso más común de error de uso, igual que AiExcelImportController::analyze().
         */
        $imagenes = $request->file('imagenes');

        if (is_null($imagenes)) {
            $imagenes = [];
        }

        if (!is_array($imagenes)) {
            $imagenes = [$imagenes];
        }

        if (count($imagenes) === 0) {
            return response()->json(['message' => 'Subí al menos una foto de la factura.'], 422);
        }

        if (count($imagenes) > $max_imagenes) {
            return response()->json([
                'message' => 'Se pueden escanear hasta ' . $max_imagenes . ' páginas por vez.',
            ], 422);
        }

        foreach ($imagenes as $imagen) {

            if (is_null($imagen) || !$imagen->isValid()) {
                return response()->json([
                    'message' => 'Una de las fotos no se subió correctamente. Probá de nuevo.',
                ], 422);
            }

            $nombre_original = (string) $imagen->getClientOriginalName();
            $extension       = strtolower((string) $imagen->getClientOriginalExtension());

            if (!in_array($extension, self::EXTENSIONES_PERMITIDAS)) {

                /*
                 * El HEIC se nombra aparte porque es el default de la cámara del iPhone: decirle
                 * "se aceptan jpg, jpeg, png, webp" a alguien que subió una foto de su teléfono
                 * no le dice qué hacer. Sacarla otra vez con el botón de la cámara sí, porque
                 * ahí el navegador la entrega ya convertida a JPEG.
                 */
                if ($extension === 'heic' || $extension === 'heif') {
                    return response()->json([
                        'message' => 'El archivo "' . $nombre_original . '" está en formato HEIC, que es el que usa ' .
                                     'la cámara del iPhone por defecto y el sistema todavía no puede leer. ' .
                                     'Sacá la foto con el botón "Tomar foto" en vez de subirla desde Archivos, ' .
                                     'o convertila a JPG antes de subirla.',
                    ], 422);
                }

                return response()->json([
                    'message' => 'El archivo "' . $nombre_original . '" no es una imagen soportada. ' .
                                 'Se aceptan: ' . implode(', ', self::EXTENSIONES_PERMITIDAS) . '.',
                ], 422);
            }

            if ($imagen->getSize() > ($max_mb * 1024 * 1024)) {
                return response()->json([
                    'message' => 'El archivo "' . $nombre_original . '" pesa más de ' . $max_mb . ' MB.',
                ], 422);
            }
        }

        /* La compra tiene que existir y ser de este comercio. */
        $provider_order = ProviderOrder::where('id', $request->input('provider_order_id'))
                                        ->where('user_id', $owner_id)
                                        ->first();

        if (is_null($provider_order)) {
            return response()->json(['message' => 'No se encontro la compra'], 404);
        }

        /*
         * El cuerpo del alta vive en ProviderOrderScanAltaHelper (misión asistente-por-whatsapp,
         * 16/9/2026): el asistente de IA también crea escaneos —la foto de la factura que el
         * dueño manda por WhatsApp— y no tiene request del que leer las fotos. La extracción no
         * cambió nada observable: la misma guarda del escaneo en curso, el mismo redimensionado,
         * las mismas filas, el mismo despacho y los mismos códigos y textos (202, 409, 422, 500).
         *
         * Lo que se queda acá es la validación del lote de arriba: eso es validación del request,
         * y es quien tiene el UploadedFile con su nombre original.
         */
        $resultado = ProviderOrderScanAltaHelper::crear($provider_order, $imagenes, auth()->user());

        return response()->json($resultado['body'], $resultado['status']);
    }

    /**
     * GET /api/provider-order-scan/{uuid}
     *
     * Estado y, si ya terminó, el resultado completo del escaneo.
     *
     * @param  string  $uuid
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($uuid)
    {
        $scan = $this->escaneo_del_owner($uuid);

        if (is_null($scan)) {
            return response()->json(['message' => 'No se encontro el escaneo'], 404);
        }

        $imagenes = [];

        foreach ($this->imagenes_del_escaneo($scan) as $image) {
            $imagenes[] = [
                'orden'           => (int) $image->orden,
                'nombre_original' => $image->nombre_original,
            ];
        }

        return response()->json([
            'uuid'              => $scan->uuid,
            'provider_order_id' => $scan->provider_order_id,
            'estado'            => $scan->estado,
            'progreso'          => $scan->progreso,
            'paso'              => $scan->paso,
            'error'             => $scan->error,
            'gestionado_at'     => $scan->gestionado_at,
            'resultado_gestion' => $scan->resultado_gestion,
            /*
             * ¿Este escaneo todavía se puede revisar? Sale del modelo, que es donde vive el
             * criterio (ProviderOrderScan::esta_pendiente_de_revisar(), hermano del scope que
             * usa pendientes()). Así el frontend no lo tiene que rederivar de estado +
             * gestionado_at, que es exactamente la forma en que dos lugares se desincronizan.
             */
            'pendiente_de_revisar' => $scan->esta_pendiente_de_revisar(),
            'imagenes'          => $imagenes,
            /*
             * El resultado completo va SOLO cuando el escaneo terminó bien; en cualquier otro
             * estado va null (mismo criterio que AiExcelImportController::analysisStatus).
             */
            'resultado'         => $scan->estado === 'listo' ? $scan->resultado : null,
        ], 200);
    }

    /**
     * GET /api/provider-order-scan/pendientes
     *
     * Lo que enciende los botones rojos del listado de compras. Liviano a propósito: NO devuelve
     * el resultado (una factura de 80 renglones con confianzas por campo pesa, y el listado no
     * lo usa).
     *
     * 🔴 El criterio de "pendiente de revisar" NO se escribe acá inline: sale del scope
     * ProviderOrderScan::scopePendientesDeRevisar(), que es donde vive junto a su hermano en
     * memoria (esta_pendiente_de_revisar()). Reimplementarlo acá es justo lo que hace que
     * cambiarlo en el modelo no cambie nada y el botón rojo siga con el criterio viejo.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pendientes()
    {
        $scans = ProviderOrderScan::where('user_id', $this->userId())
                                    ->pendientesDeRevisar()
                                    ->orderBy('id', 'DESC')
                                    ->get();

        $models = [];

        foreach ($scans as $scan) {

            $models[] = [
                'provider_order_id'  => $scan->provider_order_id,
                'uuid'               => $scan->uuid,
                'cantidad_articulos' => count($this->articulos_del_resultado($scan->resultado)),
                'created_at'         => $scan->created_at,
            ];
        }

        return response()->json(['models' => $models], 200);
    }

    /**
     * GET /api/provider-order-scan/en-curso
     *
     * Recupera el hilo después de cerrar la pestaña: el aviso de "terminó" viaja por broadcast, y
     * un broadcast solo llega a quien está conectado en ese momento. La SPA llama a este endpoint
     * al arrancar y recupera lo que se haya perdido mientras el usuario no estaba.
     *
     * Se limita al propio auth_user (no al owner): la foto la sacó una persona y es a esa persona
     * a la que le interesa el resultado.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function en_curso()
    {
        $auth_user_id = optional(auth()->user())->id;

        if (is_null($auth_user_id)) {
            return response()->json(['run' => null], 200);
        }

        /*
         * La ventana de 48 h es SOLO para decidir qué escaneo ofrecer al arrancar la SPA: acá no
         * se borra nada. Un escaneo es parte del expediente de la compra y vive mientras viva la
         * compra (a diferencia de excel_analysis_runs, que sí se limpia por antigüedad).
         */
        $scan = ProviderOrderScan::where('user_id', $this->userId())
                                    ->where('auth_user_id', $auth_user_id)
                                    ->where('created_at', '>=', now()->subDays(2))
                                    ->whereNull('visto_at')
                                    ->whereNull('gestionado_at')
                                    ->orderBy('id', 'DESC')
                                    ->first();

        if (is_null($scan)) {
            return response()->json(['run' => null], 200);
        }

        return response()->json([
            'run' => [
                'uuid'              => $scan->uuid,
                'provider_order_id' => $scan->provider_order_id,
                'estado'            => $scan->estado,
                'progreso'          => $scan->progreso,
                'paso'              => $scan->paso,
                'error'             => $scan->error,
                'contexto'          => $scan->contexto_para_frontend(),
            ],
        ], 200);
    }

    /**
     * POST /api/provider-order-scan/{uuid}/visto
     *
     * Marca el AVISO como visto, para que la próxima carga de la SPA no le vuelva a tirar el
     * mismo modal de "terminó". No apaga el botón rojo: eso lo hace gestionado_at.
     *
     * @param  string  $uuid
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function marcar_visto($uuid)
    {
        $scan = $this->escaneo_del_owner($uuid);

        if (is_null($scan)) {
            return response()->json(['message' => 'No se encontro el escaneo'], 404);
        }

        $scan->update(['visto_at' => now()]);

        return response(null, 200);
    }

    /**
     * POST /api/provider-order-scan/{uuid}/confirmar
     *
     * El endpoint más delicado de la misión: asienta en la compra los artículos que el usuario
     * revisó y, si corresponde, guarda la factura.
     *
     * Orden obligatorio (§4.6 del plan), y cada paso está donde está por un motivo:
     *   5. decisión sobre la factura según modo_facturacion;
     *   6. resolución de cada artículo (crear solo si el usuario lo pidió explícitamente);
     *   7. attach_articles(false) + check_modo_facturacion() + guardar_factura() +
     *      procesar_pedido() — en ESE orden, ver el comentario del paso 7 en
     *      aplicar_confirmacion();
     *   9. el escaneo queda gestionado, con el resultado corregido y el resumen de lo aplicado.
     *
     * 🔴 Los pasos 5 a 9 corren TODOS adentro de la misma transacción, incluido el cambio de
     * modo_facturacion del paso 5. Si ese cambio quedara afuera (como estaba), un rollback
     * desharía los artículos pero dejaría la compra pasada a 'manual' para siempre.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string                    $uuid
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirmar(Request $request, $uuid)
    {
        $owner_id = $this->userId();

        /* Paso 1: el escaneo tiene que ser de este comercio. */
        $scan = $this->escaneo_del_owner($uuid);

        if (is_null($scan)) {
            return response()->json(['message' => 'No se encontro el escaneo'], 404);
        }

        /*
         * Paso 2: no se aplica dos veces. Confirmar de nuevo duplicaría los artículos en la
         * compra, los altas de catálogo y el comprobante.
         *
         * ⚠️ Este chequeo es solo el camino rápido, para no abrir una transacción al pedo
         * cuando el escaneo ya está claramente cerrado. NO alcanza como garantía: entre este
         * SELECT y el UPDATE del paso 9 hay toda la confirmación de por medio (movimientos de
         * stock de N artículos), y dos requests concurrentes —dos pestañas, o un reintento
         * después de un timeout— pasan los dos por acá. La garantía de verdad es la relectura
         * con lockForUpdate() adentro de la transacción, en aplicar_confirmacion().
         */
        if (!is_null($scan->gestionado_at)) {
            return response()->json(['message' => 'Este escaneo ya fue gestionado.'], 409);
        }

        /* Paso 3: sin resultado no hay nada que confirmar. */
        if ($scan->estado !== 'listo') {
            return response()->json([
                'message' => 'El escaneo todavía no terminó. Esperá a que esté listo para confirmarlo.',
            ], 409);
        }

        /* Paso 4: la compra tiene que existir y ser de este comercio. */
        $provider_order = ProviderOrder::where('id', $scan->provider_order_id)
                                        ->where('user_id', $owner_id)
                                        ->first();

        if (is_null($provider_order)) {
            return response()->json(['message' => 'No se encontro la compra'], 404);
        }

        $articulos_request = $request->input('articulos');

        if (!is_array($articulos_request)) {
            $articulos_request = [];
        }

        $factura = $request->input('factura');

        if (!is_array($factura)) {
            $factura = [];
        }

        /*
         * boolean() y no (bool): "(bool) 'false'" en PHP da TRUE, así que un cast crudo prendería
         * el guardado justo cuando el usuario no lo pidió. boolean() tolera 1/0, "1"/"0",
         * true/false y "true"/"false", y soporta la notación con punto.
         */
        $quiere_guardar_factura = $request->boolean('factura.guardar');
        $pasar_a_manual         = $request->boolean('factura.pasar_a_manual');

        try {

            /*
             * Pasos 5 a 9 adentro de una transacción: si falla a mitad, la compra no queda con la
             * mitad de los artículos, ni pasada a facturación manual, ni el escaneo marcado como
             * gestionado.
             */
            $resumen = DB::transaction(function () use (
                $uuid,
                $provider_order,
                $owner_id,
                $articulos_request,
                $factura,
                $quiere_guardar_factura,
                $pasar_a_manual
            ) {
                return $this->aplicar_confirmacion(
                    $uuid,
                    $provider_order,
                    $owner_id,
                    $articulos_request,
                    $factura,
                    $quiere_guardar_factura,
                    $pasar_a_manual
                );
            });

        } catch (\Throwable $e) {

            /*
             * Perdimos la carrera contra otro request que confirmó el mismo escaneo: la
             * transacción ya se revirtió sola y no quedó nada aplicado. Es un 409, igual que el
             * chequeo rápido del paso 2, y no un 500: no hay nada roto, hay algo que ya estaba
             * hecho.
             */
            if ($e->getCode() === self::CODIGO_YA_GESTIONADO) {
                return response()->json(['message' => 'Este escaneo ya fue gestionado.'], 409);
            }

            Log::error('ProviderOrderScanController::confirmar - error al asentar el escaneo', [
                'uuid'    => $uuid,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Ocurrió un error al cargar el escaneo en la compra: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            /* Para que la SPA refresque la fila del listado sin volver a pedir todo. */
            'model'   => $this->fullModel('ProviderOrder', $provider_order->id),
            'resumen' => $resumen,
        ], 200);
    }

    /**
     * POST /api/provider-order-scan/{uuid}/descartar
     *
     * Apaga el botón rojo sin asentar nada. Existe porque, si no, un escaneo que salió mal (foto
     * borrosa, documento equivocado) dejaría el botón rojo prendido para siempre.
     *
     * @param  string  $uuid
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function descartar($uuid)
    {
        $scan = $this->escaneo_del_owner($uuid);

        if (is_null($scan)) {
            return response()->json(['message' => 'No se encontro el escaneo'], 404);
        }

        if (!is_null($scan->gestionado_at)) {
            return response()->json(['message' => 'Este escaneo ya fue gestionado.'], 409);
        }

        $scan->update([
            'gestionado_at'     => now(),
            'resultado_gestion' => 'descartado',
        ]);

        return response(null, 200);
    }

    /**
     * GET /api/provider-order-scan/{uuid}/imagen/{orden}
     *
     * Sirve el binario de una página escaneada, para poder cotejar con el original mientras se
     * corrige la tabla. Chequea tenencia DOS veces (el escaneo por user_id y la imagen por su
     * propio user_id desnormalizado): una factura tiene CUIT, razón social y precios, y no puede
     * salir por una URL adivinable.
     *
     * @param  string      $uuid
     * @param  int|string  $orden
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    public function imagen($uuid, $orden)
    {
        $owner_id = $this->userId();

        $scan = $this->escaneo_del_owner($uuid);

        if (is_null($scan)) {
            return response()->json(['message' => 'No se encontro el escaneo'], 404);
        }

        $image = ProviderOrderScanImage::where('provider_order_scan_id', $scan->id)
                                        ->where('user_id', $owner_id)
                                        ->where('orden', (int) $orden)
                                        ->first();

        if (is_null($image)) {
            return response()->json(['message' => 'No se encontro la imagen'], 404);
        }

        $ruta_completa = storage_path('app/' . $image->path);

        if (!file_exists($ruta_completa)) {
            return response()->json(['message' => 'No se encontro la imagen'], 404);
        }

        $respuesta = response()->file($ruta_completa);

        /*
         * 🔴 setPrivate() SOBRE LA RESPUESTA YA ARMADA, no un header `Cache-Control` en el array
         * de response()->file(). BinaryFileResponse nace con `$public = true` y su constructor
         * llama a setPublic() DESPUES de cargar los headers que se le pasaron, asi que un
         * `private` puesto ahi sale como `max-age=300, public` — lo contrario de lo que se quiso.
         * Medido en el endpoint gemelo de AiConversationController el 22/9/2026.
         *
         * Aca importa tanto como alla: esto sirve la FACTURA ESCANEADA de un proveedor, con su
         * CUIT, su razon social y los precios de compra. Un proxy o una CDN intermedia
         * guardandola y sirviendosela a otro comercio es el peor final posible para este archivo.
         */
        $respuesta->setPrivate();
        $respuesta->setMaxAge(300);

        return $respuesta;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers internos                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Cuerpo de la confirmación (pasos 5 a 9). Está aparte del método público para que la
     * transacción de confirmar() envuelva exactamente estos pasos y nada más.
     *
     * Recibe el `uuid` y no el modelo: el escaneo se vuelve a leer acá adentro, con
     * lockForUpdate(), porque el que se leyó afuera no sirve como garantía de unicidad.
     *
     * @param  string                     $uuid
     * @param  \App\Models\ProviderOrder  $provider_order
     * @param  int                        $owner_id
     * @param  array                      $articulos_request
     * @param  array                      $factura
     * @param  bool                       $quiere_guardar_factura  factura.guardar del request.
     * @param  bool                       $pasar_a_manual          factura.pasar_a_manual del request.
     * @return array  Resumen de lo aplicado.
     *
     * @throws \RuntimeException  Con código self::CODIGO_YA_GESTIONADO si otro request ganó la
     *                            carrera y el escaneo ya estaba gestionado.
     */
    protected function aplicar_confirmacion(
        $uuid,
        $provider_order,
        $owner_id,
        $articulos_request,
        $factura,
        $quiere_guardar_factura,
        $pasar_a_manual
    ) {
        /*
         * 🔴 Paso 0 — la relectura que hace que confirmar sea de verdad una sola vez.
         *
         * El chequeo de gestionado_at de confirmar() corre FUERA de la transacción y sin lock:
         * dos requests concurrentes (dos pestañas, o un reintento después de un timeout — la
         * confirmación hace movimientos de stock de N artículos y puede tardar) pasan los dos.
         * El resultado sería catastrófico y silencioso: los artículos con crear_en_catalogo se
         * crean DOS veces en el catálogo, y se crean DOS comprobantes con el mismo code; con
         * total_from_provider_order_afip_tickets = 1, la deuda del proveedor queda al doble.
         *
         * Con lockForUpdate() adentro de la transacción, el segundo request se queda esperando
         * en el SELECT hasta que el primero commitea, y recién ahí lee la fila YA gestionada y
         * sale por acá sin tocar nada.
         */
        $scan = ProviderOrderScan::where('uuid', $uuid)
                                    ->where('user_id', $owner_id)
                                    ->lockForUpdate()
                                    ->first();

        if (is_null($scan) || !is_null($scan->gestionado_at)) {
            throw new \RuntimeException('Este escaneo ya fue gestionado.', self::CODIGO_YA_GESTIONADO);
        }

        /*
         * Paso 5 — LA TRAMPA DEL modo_facturacion (§4.6.2). ModoFacturacionHelper, que corre en
         * el paso 7:
         *   - 'sin factura'  → BORRA todas las facturas de la compra;
         *   - 'automatico'   → recalcula total, total_iva y el desglose de IVA desde los artículos.
         * En los dos casos, guardar la factura sin permiso explícito del usuario sería trabajo
         * tirado (o peor: silenciosamente borrado en el mismo request). Cambiarle la configuración
         * de facturación a la compra por nuestra cuenta sería peor todavía que no guardar la
         * factura, así que se pide la casilla `pasar_a_manual` con todas las letras.
         */
        $modo_facturacion = (string) $provider_order->modo_facturacion;

        $debe_guardar_factura = $quiere_guardar_factura;
        $factura_motivo       = null;

        if ($debe_guardar_factura && $pasar_a_manual) {

            /*
             * 🔴 El cambio de modo_facturacion pide DOS condiciones, no una: que el usuario haya
             * tildado la casilla Y que efectivamente haya una factura para guardar. Sin el
             * segundo requisito, escanear un remito (es_factura_afip = false, así que la SPA no
             * manda `guardar`) con la casilla tildada dejaba la compra en 'manual' para siempre:
             * no se guardaba ninguna factura, y la factura automática que el sistema venía
             * recalculando quedaba congelada. Cambiar la configuración de facturación de una
             * compra es un efecto permanente, y no se paga por nada.
             *
             * Va acá adentro de la transacción (antes vivía en confirmar(), afuera): si la
             * confirmación falla más abajo, el rollback tiene que deshacer TAMBIÉN esto.
             *
             * Y va antes del paso 7 porque check_modo_facturacion() lee el modo de la compra:
             * con el modo viejo borraría la factura que estamos por guardar.
             */
            $provider_order->modo_facturacion = 'manual';
            $provider_order->save();

            $modo_facturacion = 'manual';

        } elseif ($debe_guardar_factura && ($modo_facturacion === 'sin factura' || $modo_facturacion === 'automatico')) {

            $debe_guardar_factura = false;
            $factura_motivo       = 'La compra está configurada como «' . $modo_facturacion . '», así que los ' .
                                    'datos de la factura no se guardaron. Para guardarlos hay que pasar la compra ' .
                                    'a facturación manual.';
        }

        /* Paso 6: resolver cada artículo. */
        $articles            = [];
        $articulos_agregados = 0;
        $articulos_creados   = 0;
        $articulos_omitidos  = 0;

        foreach ($articulos_request as $item) {

            if (!is_array($item)) {
                $articulos_omitidos++;
                continue;
            }

            $article_id = isset($item['article_id']) ? $item['article_id'] : null;

            if ($article_id === '' || $article_id === 0 || $article_id === '0') {
                $article_id = null;
            }

            $crear_en_catalogo = isset($item['crear_en_catalogo'])
                                    ? filter_var($item['crear_en_catalogo'], FILTER_VALIDATE_BOOLEAN)
                                    : false;

            /*
             * Alícuota de ESTE renglón, tal como quedó en el modal de revisión (la IA la lee de
             * la columna de IVA y el servicio la resuelve contra la tabla `ivas`; el usuario la
             * puede corregir). Null cuando el comprobante no discrimina por línea.
             */
            $iva_id_del_item = $this->entero_o_null(isset($item['iva_id']) ? $item['iva_id'] : null);

            $article = null;

            if (!is_null($article_id)) {

                $article = Article::where('user_id', $owner_id)->find($article_id);

                if (is_null($article)) {
                    /* Se borró entre el escaneo y la confirmación: se saltea y se cuenta. */
                    $articulos_omitidos++;
                    continue;
                }

            } elseif ($crear_en_catalogo) {

                /*
                 * Alta idéntica a la del import de Excel (ProviderOrderArticleImport::get_article),
                 * más el `iva_id` de la línea.
                 *
                 * 🔴 El iva_id NO es opcional acá. Un artículo nuevo sin alícuota queda con
                 * iva_id null, y entonces get_total_article() no le calcula IVA a esa línea
                 * (NewProviderOrderHelper: el cálculo pide iva_id no nulo y distinto de 0) y
                 * ModoFacturacionHelper::get_ivas() la saltea entera: en modo automático el
                 * renglón aporta $0 de IVA aunque el comprobante diga 21%. Si la línea no trajo
                 * alícuota queda null igual que antes, pero cuando la trajo se usa.
                 */
                $article = Article::create([
                    'bar_code'      => $this->texto_o_null(isset($item['bar_code']) ? $item['bar_code'] : null),
                    'provider_code' => $this->texto_o_null(isset($item['codigo_proveedor']) ? $item['codigo_proveedor'] : null),
                    'name'          => $this->texto_o_null(isset($item['nombre']) ? $item['nombre'] : null),
                    'iva_id'        => $iva_id_del_item,
                    'provider_id'   => $provider_order->provider_id,
                    'status'        => 'inactive',
                    'user_id'       => $owner_id,
                ]);

                $articulos_creados++;

            } else {

                /*
                 * 🔴 Decisión 3 de Lucas: un artículo sin article_id y sin crear_en_catalogo se
                 * saltea EN SILENCIO. Nunca se crea solo. Un código mal leído por el OCR que crea
                 * un artículo fantasma en el catálogo del cliente es el peor resultado posible: no
                 * se nota, y queda para siempre. La creación la decide el usuario, tildando la
                 * casilla en el modal de revisión.
                 */
                $articulos_omitidos++;
                continue;
            }

            /* La forma del array es la que espera NewProviderOrderHelper, no una propia. */
            $articles[] = [
                'id'            => $article->id,
                'status'        => $article->status,
                'bar_code'      => $article->bar_code,
                'provider_code' => $article->provider_code,
                'pivot' => [
                    'amount'          => $this->numero_o_null(isset($item['cantidad']) ? $item['cantidad'] : null),
                    'cost'            => $this->numero_o_null(isset($item['costo_unitario']) ? $item['costo_unitario'] : null),
                    /*
                     * 🔴 La bonificación por renglón ("Bonif. 10%", "Dto. 15%") tiene que llegar
                     * al pivot: `discount` es la columna que el sistema usa de verdad, en
                     * NewProviderOrderHelper::get_total_article() —
                     * `$total_article -= $total_article * $article->pivot->discount / 100`.
                     * Leerla en la foto, mostrarla en el modal y no mandarla acá era cargar el
                     * renglón por su precio de lista: 12 u. a $2.450,50 con 10% entraban como
                     * $29.406 en vez de $26.465,40, y esos $2.940,60 de más iban derecho a la
                     * cuenta corriente del proveedor (y, con update_prices, a los precios de
                     * venta vía el costo inflado).
                     *
                     * Null cuando el renglón no tenía bonificación: attach_articles() solo
                     * escribe las claves NO nulas, así que un null no pisa lo que ya hubiera en
                     * el pivot de una carga anterior. Eso es a propósito.
                     */
                    'discount'        => $this->numero_o_null(isset($item['descuento_porcentaje']) ? $item['descuento_porcentaje'] : null),
                    'notes'           => $this->texto_o_null(isset($item['notas']) ? $item['notas'] : null),
                    'price'           => $article->price,
                    /*
                     * La alícuota del renglón manda sobre la del catálogo: la factura es el dato
                     * fiscal de esta compra. Si la línea no la trajo, se cae a la del artículo,
                     * que es el comportamiento de siempre.
                     */
                    'iva_id'          => is_null($iva_id_del_item) ? $article->iva_id : $iva_id_del_item,
                    'cost_in_dollars' => $article->cost_in_dollars,
                    'update_provider' => 0,
                ],
            ];

            $articulos_agregados++;
        }

        /*
         * Paso 7 — la secuencia de ProviderOrderArticleImport::collection(), con el guardado de
         * la factura intercalado. Los cuatro pasos van juntos y en este orden: el helper deja los
         * artículos en la compra, ModoFacturacionHelper resuelve qué hacer con las facturas según
         * el modo, se guarda la factura escaneada, y procesar_pedido() recalcula totales,
         * descuentos, recargos y cuenta corriente TENIENDO YA la factura a la vista.
         *
         * El porqué de que el guardado vaya justo en el medio está escrito abajo, en el paso 8.
         *
         * Corre SIEMPRE, aunque no haya artículos: confirmar un escaneo es un guardado de la
         * compra, y saltear la secuencia dejaría los totales sin recalcular.
         *
         * 🔴 attach_articles(false) — el false NO es configurable y no sale del request. Con true
         * el helper hace sync() y BORRA de la compra todos los artículos que no vinieran en esta
         * foto: con una factura de varias páginas, confirmar la página 2 borraría la página 1.
         * Si alguna vez alguien quiere "parametrizarlo", este comentario es el motivo por el que
         * no se hace.
         */
        $ya_se_actualizo_stock = $provider_order->update_stock;

        $helper = new NewProviderOrderHelper($provider_order, $articles, $ya_se_actualizo_stock);

        $helper->attach_articles(false);

        ModoFacturacionHelper::check_modo_facturacion($provider_order, $helper);

        /*
         * 🔴 PASO 8 — LA FACTURA SE GUARDA EXACTAMENTE ACÁ: en el medio, después de
         * check_modo_facturacion() y antes de procesar_pedido(). No es cosmético y no se
         * reordena; las dos vecindades están puestas por un motivo distinto:
         *
         *   · DESPUÉS de check_modo_facturacion() — porque con modo_facturacion = 'sin factura'
         *     ese helper hace ProviderOrderAfipTicket::where('provider_order_id', ...)->delete()
         *     y borra TODAS las facturas de la compra. Guardarla antes la borraría en el mismo
         *     request y en silencio.
         *
         *   · ANTES de procesar_pedido() — porque procesar_pedido() → set_totales() es lo que
         *     arma los totales de la compra A PARTIR de las facturas: total_iva sale de sumar
         *     provider_order_afip_tickets.total_iva, y con
         *     total_from_provider_order_afip_tickets = 1 el `total` sale de sumar
         *     afip_ticket->total. Y después set_current_acount() escribe
         *     current_acount.debe = provider_order.total. Si la factura nace DESPUÉS de todo
         *     eso, se calcula todo con una factura que todavía no existe: la compra queda con
         *     total_iva = 0 y el proveedor con la deuda de menos (medido: $3.000 en vez de
         *     $24.000 con una factura de $21.000 de IVA), o directamente con total = 0 si el
         *     total sale de las facturas.
         *
         * Es el mismo orden que ProviderOrderController::store(), que graba los `childrens`
         * (las facturas entre ellos) antes de la secuencia del helper.
         *
         * set_totales() hace su propio ->load('provider_order_afip_tickets'), así que ve el
         * comprobante recién creado sin que haya que refrescar nada a mano.
         */
        $factura_guardada = 'no';

        if ($debe_guardar_factura) {

            $resultado_factura = $this->guardar_factura($provider_order, $owner_id, $factura, $modo_facturacion);

            $factura_guardada = $resultado_factura['estado'];

            if (!is_null($resultado_factura['motivo'])) {
                $factura_motivo = $resultado_factura['motivo'];
            }
        }

        $helper->procesar_pedido();

        $resumen = [
            'articulos_agregados' => $articulos_agregados,
            'articulos_creados'   => $articulos_creados,
            'articulos_omitidos'  => $articulos_omitidos,
            'factura_guardada'    => $factura_guardada,
            'factura_motivo'      => $factura_motivo,
        ];

        /*
         * Paso 9. El resultado se pisa con lo que el usuario dejó, no con lo que leyó la IA: el
         * registro del escaneo tiene que decir qué se asentó de verdad. Las claves que el usuario
         * no toca (columnas_detectadas, avisos, tokens, version) se conservan.
         */
        $resultado_corregido = is_array($scan->resultado) ? $scan->resultado : [];

        $resultado_corregido['articulos'] = $articulos_request;
        $resultado_corregido['factura']   = $factura;

        $scan->update([
            'gestionado_at'     => now(),
            'resultado_gestion' => 'confirmado',
            'resultado'         => $resultado_corregido,
            'aplicado'          => $resumen,
        ]);

        return $resumen;
    }

    /**
     * Paso 8 de la confirmación: crea o actualiza el comprobante principal de la compra con lo
     * que el usuario dejó en el modal de revisión.
     *
     * @param  \App\Models\ProviderOrder  $provider_order
     * @param  int                        $owner_id
     * @param  array                      $factura
     * @param  string                     $modo_facturacion
     * @return array  ['estado' => 'completa'|'parcial', 'motivo' => string|null]
     */
    protected function guardar_factura($provider_order, $owner_id, $factura, $modo_facturacion)
    {
        $code = $this->texto_o_null(isset($factura['code']) ? $factura['code'] : null);

        $ticket = $this->buscar_ticket_principal($provider_order->id, $owner_id, $code);

        if (is_null($ticket)) {
            $ticket = new ProviderOrderAfipTicket();
        }

        /* Campos que se escriben SIEMPRE (ModoFacturacionHelper no los toca en ningún modo). */
        $ticket->provider_order_id   = $provider_order->id;
        $ticket->user_id             = $owner_id;
        $ticket->code                = $code;
        $ticket->issued_at           = $this->fecha_o_null(isset($factura['issued_at']) ? $factura['issued_at'] : null);
        $ticket->emisor_cuit         = $this->texto_o_null(isset($factura['emisor_cuit']) ? $factura['emisor_cuit'] : null);
        $ticket->emisor_razon_social = $this->texto_o_null(isset($factura['emisor_razon_social']) ? $factura['emisor_razon_social'] : null);
        $ticket->percepcion_iibb     = $this->numero_o_null(isset($factura['percepcion_iibb']) ? $factura['percepcion_iibb'] : null);
        $ticket->percepcion_iva      = $this->numero_o_null(isset($factura['percepcion_iva']) ? $factura['percepcion_iva'] : null);

        /*
         * 🔴 Las retenciones NO se escriben más acá (misión `compras-factura-manual-alicuotas`,
         * 17/9/2026). Una factura de compra no las tiene —retiene tu cliente cuando te paga, no el
         * proveedor cuando te factura—, así que el escaneo dejó de pedírselas a la IA
         * (`EscaneoFacturaCompraService::CAMPOS_NUMERICOS_FACTURA`) y se cargan al registrar un
         * cobro en la cuenta corriente de un cliente.
         *
         * No alcanzaba con que la IA dejara de mandarlas: este método REUSA el comprobante que ya
         * existe (ver `buscar_ticket_principal()`), así que las tres líneas de asignación le
         * escribían `null` encima a lo que hubiera guardado. Re-escanear una factura vieja le
         * borraba las retenciones que todavía están en esas columnas, que son justo las que se
         * migran a `retenciones_sufridas`.
         */

        if ($modo_facturacion === 'automatico') {

            /*
             * En modo automático, total, total_iva y el desglose de IVA los calcula
             * ModoFacturacionHelper desde los artículos: pisarlos acá duraría hasta el próximo
             * guardado de la compra, así que no se tocan y el resumen lo dice.
             *
             * Con la regla del paso 5 esta rama hoy no se alcanza (en 'automatico' la factura no
             * se guarda sin pasar_a_manual, y con pasar_a_manual el modo ya es 'manual'). Queda
             * escrita igual porque es la que sostiene la garantía: si mañana alguien afloja el
             * paso 5, los importes calculados no se pisan por accidente.
             */
            $ticket->save();

            return [
                'estado' => 'parcial',
                'motivo' => 'La compra factura en modo automático: se guardaron el número, la fecha, el ' .
                            'emisor y las percepciones, pero el total y el IVA los sigue ' .
                            'calculando el sistema desde los artículos.',
            ];
        }

        /*
         * 🔴 `total` NO se guarda tal como viene (misión `compras-factura-manual-alicuotas`,
         * 17/9/2026). Cuando el comprobante tiene desglose de IVA, el total es una cuenta y lo
         * calcula el servidor abajo, con `FacturaDeCompraHelper::guardar_totales()`, igual que en
         * `ProviderOrderAfipTicketController`. Ésta era la otra puerta por la que el total entraba
         * tal como lo mandaba el cliente: dejar el campo de solo lectura en pantalla y seguir
         * aceptándolo por acá es la misma promesa a medias.
         *
         * Pero un comprobante SIN desglose —una Factura C, que no discrimina IVA, o uno donde la
         * IA no llegó a leer el detalle— no tiene total derivable, y ahí el único dato que existe
         * es el número impreso en el papel, que el usuario ya revisó en el modal. Ese número es el
         * que se pasa como base, neto de las percepciones (el TOTAL de una factura real las trae
         * adentro, así que sumarlas de nuevo sería contarlas dos veces).
         *
         * Si el desglose está pero no cuadra con el total impreso, el aviso ya salió mucho antes:
         * `EscaneoFacturaCompraService` marca `total` en `campos_dudosos` y agrega un aviso, para
         * que la discrepancia se vea en el modal de revisión y no adentro de la deuda con el
         * proveedor.
         */
        $total_impreso = $this->numero_o_null(isset($factura['total']) ? $factura['total'] : null);

        /* Hace falta el id para colgarle las filas de IVA. */
        $ticket->save();

        /* Se borran y se recrean: el usuario pudo sacar o cambiar alícuotas en el modal. */
        ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $ticket->id)->delete();

        $ivas = isset($factura['ivas']) && is_array($factura['ivas']) ? $factura['ivas'] : [];

        foreach ($ivas as $fila) {

            if (!is_array($fila)) {
                continue;
            }

            $iva_id = isset($fila['iva_id']) ? $fila['iva_id'] : null;

            if (is_null($iva_id) || $iva_id === '') {
                /* Sin alícuota resuelta no se puede armar la fila del desglose. */
                continue;
            }

            $iva_importe = $this->numero_o_null(isset($fila['iva_importe']) ? $fila['iva_importe'] : null);

            ProviderOrderAfipTicketIva::create([
                'provider_order_afip_ticket_id' => $ticket->id,
                'iva_id'                        => (int) $iva_id,
                'neto'                          => $this->numero_o_null(isset($fila['neto']) ? $fila['neto'] : null),
                'iva_importe'                   => $iva_importe,
            ]);
        }

        /*
         * Los totales del comprobante, en un solo lugar (el mismo que usa la factura cargada a
         * mano):
         *
         *   · Con desglose: total_iva = Σ(iva_importe) y total = Σ(neto + iva_importe) +
         *     percepciones. El total impreso que se pasa como base queda ignorado, que es el punto.
         *   · Sin desglose: total = total impreso sin percepciones + percepciones (o sea, el total
         *     impreso), y `total_iva` no se toca.
         *
         * No hace falta recalcular la compra acá: este método corre en el medio del confirmar, y
         * `procesar_pedido()` —que es el que arma el total de la compra a partir de sus facturas—
         * viene justo después (ver el comentario del PASO 8).
         */
        FacturaDeCompraHelper::guardar_totales(
            $ticket,
            (float) $total_impreso - FacturaDeCompraHelper::percepciones($ticket)
        );

        return ['estado' => 'completa', 'motivo' => null];
    }

    /**
     * Busca el comprobante principal de la compra que hay que actualizar (nunca uno "aparte" de
     * un costo extra: esos tienen su propio ciclo de vida idempotente en ModoFacturacionHelper).
     *
     * Primero por code exacto; si no aparece y hay code, se reusa uno sin code (el que crea el
     * modo automático vacío para que el usuario lo complete). Null si hay que crear uno nuevo.
     *
     * @param  int          $provider_order_id
     * @param  string|null  $code
     * @return \App\Models\ProviderOrderAfipTicket|null
     */
    protected function buscar_ticket_principal($provider_order_id, $owner_id, $code)
    {
        /*
         * El filtro por user_id es redundante hoy —el provider_order_id que llega acá ya
         * fue validado contra el owner en confirmar()—, y va igual. Era la unica consulta
         * de todo el flujo sin el filtro de tenencia, y una consulta que depende de que
         * "el de arriba ya validó" se rompe sola el dia que alguien la llama desde otro
         * lado. El costo es una condicion mas en un WHERE; el de olvidarla es devolver el
         * comprobante de otro comercio.
         */
        if (!is_null($code)) {

            $ticket = ProviderOrderAfipTicket::where('provider_order_id', $provider_order_id)
                                                ->where('user_id', $owner_id)
                                                ->whereNull('provider_order_extra_cost_id')
                                                ->where('code', $code)
                                                ->first();

            if (!is_null($ticket)) {
                return $ticket;
            }
        }

        return ProviderOrderAfipTicket::where('provider_order_id', $provider_order_id)
                                        ->where('user_id', $owner_id)
                                        ->whereNull('provider_order_extra_cost_id')
                                        ->whereNull('code')
                                        ->first();
    }

    /**
     * Escaneo del comercio autenticado, o null. Único punto de entrada de todos los lookups por
     * uuid: el filtro por user_id no se puede olvidar si está en un solo lugar.
     *
     * @param  string  $uuid
     * @return \App\Models\ProviderOrderScan|null
     */
    protected function escaneo_del_owner($uuid)
    {
        return ProviderOrderScan::where('uuid', $uuid)
                                ->where('user_id', $this->userId())
                                ->first();
    }

    /**
     * Imágenes de un escaneo, en orden de página.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return \Illuminate\Support\Collection
     */
    protected function imagenes_del_escaneo($scan)
    {
        return ProviderOrderScanImage::where('provider_order_scan_id', $scan->id)
                                        ->orderBy('orden', 'ASC')
                                        ->get();
    }

    /**
     * Lista de artículos de un resultado, tolerando cualquier forma inesperada.
     *
     * @param  mixed  $resultado
     * @return array
     */
    protected function articulos_del_resultado($resultado)
    {
        if (!is_array($resultado)) {
            return [];
        }

        if (!isset($resultado['articulos']) || !is_array($resultado['articulos'])) {
            return [];
        }

        return $resultado['articulos'];
    }

    /*
     * redimensionar_a_webp() y borrar_carpeta_del_escaneo() se fueron a
     * ProviderOrderScanAltaHelper con el resto del alta (misión asistente-por-whatsapp,
     * 16/9/2026). Acá quedaban sin ningún llamador, y dos copias del mismo redimensionado son
     * dos criterios que se separan solos: el día que cambie el lado máximo, la mitad de las
     * fotos quedaría en el viejo.
     */

    /**
     * Normaliza un valor de texto del request: null si vino vacío.
     *
     * @param  mixed  $valor
     * @return string|null
     */
    /**
     * Fecha valida en formato AAAA-MM-DD, o null.
     *
     * El servicio ya valida con checkdate lo que devuelve la IA, pero este valor NO viene de
     * ahi: viene del request de confirmacion, o sea de un campo que el usuario pudo editar a
     * mano en el modal de revision. Sin este parseo, un "14/08/2026" o un "ayer" se guardaban
     * como string crudo y MySQL los convertia en 0000-00-00 sin quejarse, dejando la factura
     * con una fecha de emision que no es la del papel.
     *
     * Se aceptan las dos formas con las que puede llegar: AAAA-MM-DD (el input date de la SPA)
     * y DD/MM/AAAA (lo que escribe alguien acostumbrado al formato argentino). Cualquier otra
     * cosa es null: mejor una factura sin fecha, que el usuario ve vacia y completa, que una
     * con una fecha inventada que nadie mira.
     *
     * @param  mixed  $valor
     * @return string|null  Fecha en AAAA-MM-DD, o null
     */
    protected function fecha_o_null($valor)
    {
        $texto = $this->texto_o_null($valor);

        if (is_null($texto)) {
            return null;
        }

        $formatos = ['Y-m-d', 'd/m/Y'];

        foreach ($formatos as $formato) {

            $fecha = \DateTime::createFromFormat($formato, $texto);

            /*
             * createFromFormat acepta desbordes ("2026-02-31" se vuelve el 3 de marzo), asi que
             * no alcanza con que devuelva un objeto: se compara el resultado formateado contra
             * la entrada. Si no coinciden, la fecha no era esa.
             */
            if ($fecha !== false && $fecha->format($formato) === $texto) {
                return $fecha->format('Y-m-d');
            }
        }

        Log::warning('[EscaneoFactura] issued_at con formato no reconocido, se guarda null.', [
            'valor' => $texto,
        ]);

        return null;
    }

    protected function texto_o_null($valor)
    {
        if (is_null($valor)) {
            return null;
        }

        if (is_array($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * Normaliza un id del request: null si vino vacío, 0 o no numérico.
     *
     * El 0 se trata como null a propósito: un `<select>` vacío del frontend manda 0 o '', y un
     * iva_id = 0 en el pivot es lo mismo que sin alícuota para NewProviderOrderHelper (chequea
     * `!= 0` explícitamente), pero como foreign key sería basura.
     *
     * @param  mixed  $valor
     * @return int|null
     */
    protected function entero_o_null($valor)
    {
        if (is_null($valor) || $valor === '' || is_array($valor) || is_bool($valor)) {
            return null;
        }

        if (!is_numeric($valor)) {
            return null;
        }

        $entero = (int) $valor;

        return $entero === 0 ? null : $entero;
    }

    /**
     * Normaliza un valor numérico del request: null si vino vacío o no es numérico.
     *
     * @param  mixed  $valor
     * @return float|null
     */
    protected function numero_o_null($valor)
    {
        if (is_null($valor) || $valor === '' || is_array($valor)) {
            return null;
        }

        if (!is_numeric($valor)) {
            return null;
        }

        return (float) $valor;
    }
}
