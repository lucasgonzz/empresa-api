<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\providerOrder\ModoFacturacionHelper;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Jobs\RunProviderOrderScanJob;
use App\Models\Article;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderScanImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

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

    /** Extensiones de imagen aceptadas al subir las páginas de la factura. */
    const EXTENSIONES_PERMITIDAS = ['jpg', 'jpeg', 'png', 'webp', 'heic'];

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
         * Un solo escaneo en curso por compra: dos jobs escribiendo la misma compra al mismo
         * tiempo se pisarían, y el usuario no tendría forma de saber cuál ganó.
         */
        $hay_uno_en_curso = ProviderOrderScan::where('provider_order_id', $provider_order->id)
                                                ->where('user_id', $owner_id)
                                                ->whereIn('estado', self::ESTADOS_EN_CURSO)
                                                ->exists();

        if ($hay_uno_en_curso) {
            return response()->json([
                'message' => 'Esta compra ya tiene un escaneo en curso. Esperá a que termine.',
            ], 409);
        }

        /*
         * El uuid se genera acá porque forma parte de la ruta donde se guardan las fotos
         * (storage/app/provider_order_scans/{user_id}/{uuid}/{orden}.webp) y las fotos se guardan
         * antes de crear la fila.
         */
        $uuid      = Str::uuid()->toString();
        $carpeta   = 'provider_order_scans/' . $owner_id . '/' . $uuid;
        $guardadas = [];

        try {

            $orden = 1;

            foreach ($imagenes as $imagen) {

                $binario = $this->redimensionar_a_webp(file_get_contents($imagen->getRealPath()));

                if (is_null($binario)) {

                    /* Salimos por el 422 y no por el catch: hay que limpiar lo ya guardado igual. */
                    $this->borrar_carpeta_del_escaneo($carpeta);

                    return response()->json([
                        'message' => 'No se pudo procesar la imagen "' . $imagen->getClientOriginalName() .
                                     '". Probá con otra foto.',
                    ], 422);
                }

                $path = $carpeta . '/' . $orden . '.webp';

                /* Disco 'local' (privado): una factura es información fiscal, nunca va al disco público. */
                Storage::disk('local')->put($path, $binario);

                $guardadas[] = [
                    'orden'           => $orden,
                    'path'            => $path,
                    'mime'            => 'image/webp',
                    'bytes'           => strlen($binario),
                    'nombre_original' => (string) $imagen->getClientOriginalName(),
                ];

                $orden++;
            }

            $scan = ProviderOrderScan::create([
                'uuid'              => $uuid,
                'user_id'           => $owner_id,
                /*
                 * Quién disparó el escaneo. El canal global_notification.{owner_id} lo escuchan
                 * TODOS los empleados: sin este id, el aviso de "terminó" le llegaría también a
                 * los compañeros que no sacaron ninguna foto.
                 */
                'auth_user_id'      => optional(auth()->user())->id,
                'provider_order_id' => $provider_order->id,
                'estado'            => 'pendiente',
                'progreso'          => 0,
            ]);

            foreach ($guardadas as $datos) {

                ProviderOrderScanImage::create([
                    'provider_order_scan_id' => $scan->id,
                    /* Desnormalizados a propósito: ver §1.2 del plan. */
                    'provider_order_id'      => $provider_order->id,
                    'user_id'                => $owner_id,
                    'orden'                  => $datos['orden'],
                    'path'                   => $datos['path'],
                    'mime'                   => $datos['mime'],
                    'bytes'                  => $datos['bytes'],
                    'nombre_original'        => $datos['nombre_original'],
                ]);
            }

            /* Solo el id, no el modelo (mismo criterio que RunExcelAnalysisJob). */
            RunProviderOrderScanJob::dispatch($scan->id);

            return response()->json([
                'uuid'              => $scan->uuid,
                'estado'            => $scan->estado,
                'provider_order_id' => $provider_order->id,
                'cantidad_imagenes' => count($guardadas),
            ], 202);

        } catch (\Throwable $e) {

            /* Si algo falló a mitad, no dejamos fotos huérfanas ocupando disco. */
            $this->borrar_carpeta_del_escaneo($carpeta);

            Log::error('ProviderOrderScanController::store - error al encolar el escaneo', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Ocurrió un error inesperado al iniciar el escaneo: ' . $e->getMessage(),
            ], 500);
        }
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
     * Definición literal de "pendiente de revisar": estado = 'listo' Y gestionado_at IS NULL. No
     * es "no visto" ni "sin confirmar": es esas dos condiciones.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pendientes()
    {
        $scans = ProviderOrderScan::where('user_id', $this->userId())
                                    ->where('estado', 'listo')
                                    ->whereNull('gestionado_at')
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
     *   5. decisión sobre la factura según modo_facturacion, ANTES de tocar nada;
     *   6. resolución de cada artículo (crear solo si el usuario lo pidió explícitamente);
     *   7. attach_articles(false) + check_modo_facturacion() + procesar_pedido();
     *   8. recién ACÁ se guarda la factura;
     *   9. el escaneo queda gestionado, con el resultado corregido y el resumen de lo aplicado.
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

        /* Paso 2: no se aplica dos veces. Confirmar de nuevo duplicaría los artículos en la compra. */
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

        if ($pasar_a_manual) {

            /* Acá, ANTES del paso 7: si no, check_modo_facturacion() actuaría con el modo viejo. */
            $provider_order->modo_facturacion = 'manual';
            $provider_order->save();

            $modo_facturacion = 'manual';

        } elseif ($debe_guardar_factura && ($modo_facturacion === 'sin factura' || $modo_facturacion === 'automatico')) {

            $debe_guardar_factura = false;
            $factura_motivo       = 'La compra está configurada como «' . $modo_facturacion . '», así que los ' .
                                    'datos de la factura no se guardaron. Para guardarlos hay que pasar la compra ' .
                                    'a facturación manual.';
        }

        try {

            /*
             * Pasos 6 a 9 adentro de una transacción: si falla a mitad, la compra no queda con la
             * mitad de los artículos y el escaneo marcado como gestionado.
             */
            $resumen = DB::transaction(function () use (
                $scan,
                $provider_order,
                $owner_id,
                $articulos_request,
                $factura,
                $debe_guardar_factura,
                $factura_motivo,
                $modo_facturacion
            ) {
                return $this->aplicar_confirmacion(
                    $scan,
                    $provider_order,
                    $owner_id,
                    $articulos_request,
                    $factura,
                    $debe_guardar_factura,
                    $factura_motivo,
                    $modo_facturacion
                );
            });

        } catch (\Throwable $e) {

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

        return response()->file($ruta_completa);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers internos                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Cuerpo de la confirmación (pasos 6 a 9). Está aparte del método público para que la
     * transacción de confirmar() envuelva exactamente estos pasos y nada más.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  \App\Models\ProviderOrder      $provider_order
     * @param  int                            $owner_id
     * @param  array                          $articulos_request
     * @param  array                          $factura
     * @param  bool                           $debe_guardar_factura
     * @param  string|null                    $factura_motivo
     * @param  string                         $modo_facturacion  Modo YA resuelto en el paso 5.
     * @return array  Resumen de lo aplicado.
     */
    protected function aplicar_confirmacion(
        $scan,
        $provider_order,
        $owner_id,
        $articulos_request,
        $factura,
        $debe_guardar_factura,
        $factura_motivo,
        $modo_facturacion
    ) {
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

            $article = null;

            if (!is_null($article_id)) {

                $article = Article::where('user_id', $owner_id)->find($article_id);

                if (is_null($article)) {
                    /* Se borró entre el escaneo y la confirmación: se saltea y se cuenta. */
                    $articulos_omitidos++;
                    continue;
                }

            } elseif ($crear_en_catalogo) {

                /* Alta idéntica a la del import de Excel (ProviderOrderArticleImport::get_article). */
                $article = Article::create([
                    'bar_code'      => $this->texto_o_null(isset($item['bar_code']) ? $item['bar_code'] : null),
                    'provider_code' => $this->texto_o_null(isset($item['codigo_proveedor']) ? $item['codigo_proveedor'] : null),
                    'name'          => $this->texto_o_null(isset($item['nombre']) ? $item['nombre'] : null),
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
                    'notes'           => $this->texto_o_null(isset($item['notas']) ? $item['notas'] : null),
                    'price'           => $article->price,
                    'iva_id'          => $article->iva_id,
                    'cost_in_dollars' => $article->cost_in_dollars,
                    'update_provider' => 0,
                ],
            ];

            $articulos_agregados++;
        }

        /*
         * Paso 7 — copia exacta de ProviderOrderArticleImport::collection(). Los tres pasos van
         * juntos y en este orden: el helper deja los artículos en la compra, ModoFacturacionHelper
         * resuelve qué hacer con las facturas según el modo, y procesar_pedido() recalcula
         * totales, descuentos, recargos y cuenta corriente.
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

        $helper->procesar_pedido();

        /*
         * Paso 8 — la factura se guarda RECIÉN ACÁ, nunca antes del paso 7. Con
         * modo_facturacion = 'sin factura', check_modo_facturacion() hace
         * ProviderOrderAfipTicket::where('provider_order_id', ...)->delete(): guardarla antes la
         * borraría en el mismo request y en silencio.
         */
        $factura_guardada = 'no';

        if ($debe_guardar_factura) {

            $resultado_factura = $this->guardar_factura($provider_order, $owner_id, $factura, $modo_facturacion);

            $factura_guardada = $resultado_factura['estado'];

            if (!is_null($resultado_factura['motivo'])) {
                $factura_motivo = $resultado_factura['motivo'];
            }
        }

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

        $ticket = $this->buscar_ticket_principal($provider_order->id, $code);

        if (is_null($ticket)) {
            $ticket = new ProviderOrderAfipTicket();
        }

        /* Campos que se escriben SIEMPRE (ModoFacturacionHelper no los toca en ningún modo). */
        $ticket->provider_order_id   = $provider_order->id;
        $ticket->user_id             = $owner_id;
        $ticket->code                = $code;
        $ticket->issued_at           = $this->texto_o_null(isset($factura['issued_at']) ? $factura['issued_at'] : null);
        $ticket->emisor_cuit         = $this->texto_o_null(isset($factura['emisor_cuit']) ? $factura['emisor_cuit'] : null);
        $ticket->emisor_razon_social = $this->texto_o_null(isset($factura['emisor_razon_social']) ? $factura['emisor_razon_social'] : null);
        $ticket->percepcion_iibb     = $this->numero_o_null(isset($factura['percepcion_iibb']) ? $factura['percepcion_iibb'] : null);
        $ticket->percepcion_iva      = $this->numero_o_null(isset($factura['percepcion_iva']) ? $factura['percepcion_iva'] : null);
        $ticket->retencion_iibb      = $this->numero_o_null(isset($factura['retencion_iibb']) ? $factura['retencion_iibb'] : null);
        $ticket->retencion_iva       = $this->numero_o_null(isset($factura['retencion_iva']) ? $factura['retencion_iva'] : null);
        $ticket->retencion_ganancias = $this->numero_o_null(isset($factura['retencion_ganancias']) ? $factura['retencion_ganancias'] : null);

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
                            'emisor y las percepciones/retenciones, pero el total y el IVA los sigue ' .
                            'calculando el sistema desde los artículos.',
            ];
        }

        $ticket->total = $this->numero_o_null(isset($factura['total']) ? $factura['total'] : null);

        /* Hace falta el id para colgarle las filas de IVA. */
        $ticket->save();

        /* Se borran y se recrean: el usuario pudo sacar o cambiar alícuotas en el modal. */
        ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $ticket->id)->delete();

        $ivas = isset($factura['ivas']) && is_array($factura['ivas']) ? $factura['ivas'] : [];

        $total_iva = 0;

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

            $total_iva += (float) $iva_importe;
        }

        /* Mismo criterio que ProviderOrderAfipTicketController::set_total_iva(). */
        $ticket->total_iva = $total_iva;
        $ticket->save();

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
    protected function buscar_ticket_principal($provider_order_id, $code)
    {
        if (!is_null($code)) {

            $ticket = ProviderOrderAfipTicket::where('provider_order_id', $provider_order_id)
                                                ->whereNull('provider_order_extra_cost_id')
                                                ->where('code', $code)
                                                ->first();

            if (!is_null($ticket)) {
                return $ticket;
            }
        }

        return ProviderOrderAfipTicket::where('provider_order_id', $provider_order_id)
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

    /**
     * Redimensiona el binario de una foto al lado mayor configurado y lo re-encodea como webp.
     *
     * No se guarda el original: para el respaldo del comprobante alcanza una imagen legible, y
     * guardar los 8 MB que saca la cámara de un teléfono por cada página solo llena el disco.
     *
     * @param  string  $binario
     * @return string|null  Binario webp, o null si Intervention no pudo procesar la imagen.
     */
    protected function redimensionar_a_webp($binario)
    {
        /*
         * 1568 px, no 512 como la validación de imágenes de producto: para leer un código de
         * artículo de 8 caracteres impreso chico hace falta resolución. Es además el lado máximo
         * que Anthropic procesa sin downsamplear.
         */
        $max_side = (int) config('services.escaneo_factura_compra.max_side', 1568);
        $max_side = $max_side > 0 ? $max_side : 1568;

        try {

            $manager = new ImageManager();
            $img     = $manager->make($binario);

            $img->resize($max_side, $max_side, function ($constraint) {
                $constraint->aspectRatio();
                /* upsize evita agrandar una foto que ya es más chica: solo agregaría peso. */
                $constraint->upsize();
            });

            $encoded = (string) $img->encode('webp', 80);

        } catch (\Throwable $e) {

            Log::info('ProviderOrderScanController: Intervention no pudo procesar la imagen -- ' . $e->getMessage());

            return null;
        }

        if ($encoded === '') {
            return null;
        }

        return $encoded;
    }

    /**
     * Borra la carpeta de fotos de un escaneo que no llegó a crearse.
     *
     * @param  string  $carpeta
     * @return void
     */
    protected function borrar_carpeta_del_escaneo($carpeta)
    {
        try {
            Storage::disk('local')->deleteDirectory($carpeta);
        } catch (\Throwable $e) {
            Log::info('ProviderOrderScanController: no se pudo limpiar ' . $carpeta . ' -- ' . $e->getMessage());
        }
    }

    /**
     * Normaliza un valor de texto del request: null si vino vacío.
     *
     * @param  mixed  $valor
     * @return string|null
     */
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
