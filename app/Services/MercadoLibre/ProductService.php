<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Helpers\ArticlePlatformSyncNotificationHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Models\Article;
use App\Models\MeliAttribute;
use App\Models\SyncToMeliArticle;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincronización de artículos locales hacia publicaciones de Mercado Libre (items).
 *
 * Misión mercado-libre-integracion (27/10/2026), lo que cambió:
 * - El gate de `add_article_to_sync` es SOLO la extensión `usa_mercado_libre` del dueño, el mismo
 *   criterio que el Kernel para correr la cola y que el listado de pedidos. El `USA_MERCADO_LIBRE`
 *   viejo del .env ya no cuenta: encolar con él sin la extensión dejaba filas que nadie procesaba
 *   (y con config:cache el env() suelto daba false de todos modos).
 * - Un artículo publicado que se desmarca de "Disponible en Mercado Libre" se encola igual, para
 *   PAUSAR la publicación; al volver a marcarlo, la sincronización normal la reactiva si la había
 *   pausado el sistema. Una publicación cerrada (`meli_status = closed`) no se reintenta en cada
 *   cambio: "Sincronizar ahora" la publica de nuevo.
 * - Alta: modelo User Products (`family_name` sin `title`) si la cuenta lo usa; título recortado al
 *   largo de la categoría; marca, código de barras y SKU como atributos automáticos; precio con
 *   fallback al precio final y mínimo de $1.000; solo fotos http/https; `POST items/validate`
 *   antes del `POST items` (si ML no lo valida, NO se publica y queda el motivo legible).
 * - Actualización con la menor cantidad de llamadas: un `GET items/{id}` y, si hay algo que
 *   cambiar, un `PUT`. Fotos, atributos y descripción solo si cambiaron (hash en
 *   `meli_sync_state`). Stock según tipo y condición de la publicación (ver
 *   `cambios_de_stock()`).
 * - Si ML pide esperar (429/5xx agotados), la sincronización vuelve a `pendiente` sin notificar.
 */
class ProductService extends MercadoLibreService
{
    /** Largo de título por defecto si no se pudo consultar la categoría (el de la doc de ML). */
    const LARGO_TITULO_POR_DEFECTO = 60;

    /** Segundos que vale la respuesta cacheada de "¿el dueño tiene la extensión?". */
    const SEGUNDOS_CACHE_DEL_MODULO = 60;

    /**
     * Respuesta cacheada por dueño de `modulo_habilitado()`: `[user_id => [bool, timestamp]]`.
     * Cada venta y cada movimiento de stock pasan por `add_article_to_sync`; sin esto serían una
     * consulta de extensiones por renglón.
     *
     * @var array<int, array{0: bool, 1: int}>
     */
    protected static $modulo_por_dueno = [];

    /**
     * True si el módulo de Mercado Libre está prendido para ese dueño: la extensión
     * `usa_mercado_libre`. Es el MISMO criterio que usan el Kernel (para correr la cola y la
     * consulta de pedidos) y el listado de pedidos: el `USA_MERCADO_LIBRE` del .env no cuenta,
     * porque encolar con él sin la extensión dejaba filas que el scheduler nunca procesaba.
     *
     * La respuesta se recuerda 60 segundos por dueño y por proceso: un recálculo masivo de precios
     * o una importación pasan por acá con cada artículo y no pueden hacer una consulta por
     * renglón.
     *
     * @param int $user_id Dueño (owner) del artículo.
     * @return bool
     */
    public static function modulo_habilitado($user_id)
    {
        $user_id = (int) $user_id;
        $ahora = time();

        if (isset(self::$modulo_por_dueno[$user_id]) && ($ahora - self::$modulo_por_dueno[$user_id][1]) < self::SEGUNDOS_CACHE_DEL_MODULO) {
            return self::$modulo_por_dueno[$user_id][0];
        }

        $owner = User::with('extencions')->find($user_id);
        $habilitado = $owner ? UserHelper::hasExtencion('usa_mercado_libre', $owner) : false;

        self::$modulo_por_dueno[$user_id] = [$habilitado, $ahora];

        return $habilitado;
    }

    /**
     * Olvida la respuesta cacheada de `modulo_habilitado()` (tests y cambios de extensión en el
     * mismo proceso).
     *
     * @return void
     */
    public static function olvidar_cache_del_modulo()
    {
        self::$modulo_por_dueno = [];
    }

    /**
     * Si el artículo cumple requisitos mínimos, encola un registro SyncToMeliArticle pendiente.
     *
     * @param Article $article
     * @return void
     */
    public static function add_article_to_sync($article)
    {
        /*
         * Modo lote de precios (PreciosEnLote: el recálculo en segundo plano y la importación,
         * misión recalculo-precios-motor-rapido, 29/9/2026): ArticleHelper::setFinalPrice() pasa
         * por acá con CADA artículo que recalcula, y en un cliente con Mercado Libre prendido casi
         * ninguno es de Mercado Libre, así que cada recálculo escribía un Log::info y un
         * Log::error por artículo del catálogo (decenas de miles de renglones de "error" que no
         * son errores, en el mismo log donde se buscan los de verdad). En modo lote no se
         * loguea; los return y lo que se encola son exactamente los mismos. Fuera del modo lote
         * (la ficha de un artículo, la sincronización a mano), como siempre.
         */
        $en_lote = PreciosEnLote::esta_activo();

        if (!$en_lote) {
            Log::info('add_article_to_sync');
        }

        // Primero lo barato: una columna del artículo que ya está en memoria.
        if (!$article->mercado_libre) {
            // Sin publicación no hay nada que hacer en ML.
            if (empty($article->me_li_id)) {
                if (!$en_lote) {
                    Log::error("Artículo Mercado Libre: ID {$article->id}");
                }

                return;
            }

            // Publicado pero desmarcado: se encola para PAUSAR la publicación, salvo que ya la haya
            // pausado el sistema o esté cerrada (no hay nada más que hacer).
            if (self::ya_pausada_por_el_sistema($article) || (string) $article->meli_status === 'closed') {
                return;
            }

            if (!self::modulo_habilitado($article->user_id) || (method_exists($article, 'trashed') && $article->trashed())) {
                return;
            }

            self::encolar($article);

            return;
        }

        if (!self::modulo_habilitado($article->user_id)) {
            return;
        }

        // Un artículo de la papelera no se publica ni se actualiza.
        if (method_exists($article, 'trashed') && $article->trashed()) {
            return;
        }

        // Una publicación cerrada en ML no se puede reactivar: reintentarla en cada cambio de stock
        // o de precio solo gastaba llamadas y repetía el mismo error. "Sincronizar ahora" la
        // publica de nuevo (ver `MercadoLibreController::sincronizar`).
        if ((string) $article->meli_status === 'closed') {
            return;
        }

        if (!$article->meli_category_id) {
            if (!$en_lote) {
                Log::error("Artículo sin categoria de Mercado Libre: ID {$article->id}");
            }

            return;
        }

        if (is_null($article->stock)) {
            if (!$en_lote) {
                Log::error("Artículo sin stock para Mercado Libre: ID {$article->id}");
            }

            return;
        }

        if (count($article->images) == 0) {
            if (!$en_lote) {
                Log::error("Artículo sin imagenes para Mercado Libre: ID {$article->id}");
            }

            return;
        }

        self::encolar($article);
    }

    /**
     * True si la publicación del artículo está pausada y la pausó el sistema (según lo último que
     * se guardó del ítem y de la sincronización).
     *
     * @param Article $article
     * @return bool
     */
    protected static function ya_pausada_por_el_sistema($article)
    {
        if ((string) $article->meli_status !== 'paused') {
            return false;
        }

        $estado = json_decode((string) $article->meli_sync_state, true);

        return is_array($estado) && !empty($estado['pausado_por_sistema']);
    }

    /**
     * Toma una sincronización pendiente para procesarla con un update ATÓMICO
     * (`pendiente` → `en_progreso` en la misma sentencia). Si actualizó 0 filas, otro proceso ya la
     * tomó (dos `sync_to_meli_articles` solapados, o el comando y un worker): se saltea, así el
     * mismo artículo no se publica dos veces.
     *
     * @param SyncToMeliArticle $sync
     * @return bool true si la tomó este proceso.
     */
    public static function tomar_para_procesar(SyncToMeliArticle $sync)
    {
        $ahora = now();

        $tomadas = SyncToMeliArticle::where('id', $sync->id)
            ->where('status', 'pendiente')
            ->update(['status' => 'en_progreso', 'attempted_at' => $ahora]);

        if ($tomadas === 0) {
            return false;
        }

        $sync->status = 'en_progreso';
        $sync->attempted_at = $ahora;
        $sync->syncOriginalAttributes(['status', 'attempted_at']);

        return true;
    }

    /**
     * Prepara una publicación CERRADA en ML para publicarse de nuevo: le saca el código de la
     * publicación vieja (y lo que cuelga de ella) y la encola como un alta. ML no deja reactivar
     * una cerrada; republicar es la única salida.
     *
     * @param Article $article
     * @return void
     */
    public static function republicar_cerrada($article)
    {
        DB::table('articles')->where('id', $article->id)->update([
            'me_li_id'             => null,
            'meli_user_product_id' => null,
            'meli_permalink'       => null,
            'meli_sync_state'      => null,
            'meli_status'          => null,
        ]);

        foreach (['me_li_id', 'meli_user_product_id', 'meli_permalink', 'meli_sync_state', 'meli_status'] as $columna) {
            $article->setAttribute($columna, null);
        }

        self::encolar($article);
    }

    /**
     * Deja una sincronización pendiente para el artículo, si no hay ya una (sin mirar ninguna otra
     * condición). La usa "Sincronizar ahora": el veredicto lo da la sincronización, con el motivo
     * legible si algo falta.
     *
     * @param Article $article
     * @return void
     */
    public static function encolar($article)
    {
        $already_exists = SyncToMeliArticle::where('article_id', $article->id)
            ->where('user_id', $article->user_id)
            ->where('status', 'pendiente')
            ->exists();

        if (!$already_exists) {
            SyncToMeliArticle::create([
                'article_id' => $article->id,
                'user_id'    => $article->user_id,
                'status'     => 'pendiente',
            ]);
        }
    }

    /**
     * Ejecuta POST (nueva publicación) o PUT (actualización) según corresponda, aplicando reglas ML.
     *
     * @param SyncToMeliArticle $sync
     * @return void
     */
    public function sync_article(SyncToMeliArticle $sync)
    {
        // Una sola notificación al fallar la sync (evita duplicar con make_request).
        $previous_notify_on_api_error = $this->notify_on_api_error;
        $this->notify_on_api_error = false;

        $sync->status = 'en_progreso';
        $sync->attempted_at = now();
        $sync->save();

        $article = null;

        try {
            $article = $sync->article;

            if (!$article) {
                throw new MercadoLibreArticuloInexistenteException('El artículo ya no existe en el sistema.');
            }

            $article->load([
                'meli_category',
                'meli_listing_type',
                'meli_buying_mode',
                'meli_item_condition',
                'images',
                'meli_attributes',
                'brand',
                'descriptions',
            ]);

            if (!$article->mercado_libre) {
                // Desmarcado de "Disponible en Mercado Libre": nunca se publica (aunque llegue por
                // "Sincronizar ahora") y, si ya estaba publicado, se pausa.
                if (empty($article->me_li_id)) {
                    throw new \Exception('El artículo no está marcado como «Disponible en Mercado Libre»: no se publica. Marcalo en el formulario del artículo para publicarlo.');
                }

                $avisos = $this->pausar_por_no_disponible($article);
            } elseif ($article->me_li_id) {
                $avisos = $this->actualizar_publicacion($article);
            } else {
                $avisos = $this->crear_publicacion($article);
            }

            $sync->status = 'exitosa';
            $sync->synced_at = now();
            $sync->error_message = count($avisos) > 0 ? 'Aviso: '.implode(' ', $avisos) : null;
            $sync->error_message_crudo = null;
            $sync->save();
        } catch (MercadoLibreLimiteException $e) {
            // No es un error del artículo: ML pidió esperar. Vuelve a la cola y la próxima corrida
            // (pasados 2 minutos) lo reintenta. Sin notificación.
            Log::warning('Sync ML #'.$sync->id.': '.$e->getMessage());

            $sync->status = 'pendiente';
            $sync->attempted_at = now();
            $sync->error_message = 'Mercado Libre pidió esperar; se reintenta solo.';
            $sync->error_message_crudo = $e->getMessage();
            $sync->save();
        } catch (MercadoLibreArticuloInexistenteException $e) {
            // Borrado o en la papelera: queda registrado, sin notificación (no hay nada que hacer).
            $sync->status = 'error';
            $sync->error_message = $e->getMessage();
            $sync->error_message_crudo = $e->getMessage();
            $sync->save();
        } catch (\Throwable $e) {
            Log::error('Error al sincronizar artículo con MercadoLibre: '.$e->getMessage());

            $error_message = $this->mensaje_legible($e->getMessage());

            $sync->status = 'error';
            $sync->error_message = $error_message;
            $sync->error_message_crudo = $e->getMessage();
            $sync->save();

            $article_label = $article ? $article->name : ('sync #'.$sync->id);
            ArticlePlatformSyncNotificationHelper::notify_mercado_libre_sync_failed(
                (int) $sync->user_id,
                $article_label,
                $error_message
            );
        } finally {
            $this->notify_on_api_error = $previous_notify_on_api_error;
        }
    }

    /**
     * Mensaje para el comercio a partir del de una excepción: si es un error crudo de la API
     * (`Mercado Libre API error: {json}`), las causas en castellano; si no, tal cual.
     *
     * @param string $error_message
     * @return string
     */
    protected function mensaje_legible($error_message)
    {
        if (strpos($error_message, 'Mercado Libre API error:') === false) {
            return $error_message;
        }

        $json_part = trim(str_replace('Mercado Libre API error:', '', $error_message));
        $parsed_error = json_decode($json_part, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed_error)) {
            return $error_message;
        }

        return "Errores al sincronizar con MercadoLibre:\n".$this->causas_legibles($parsed_error);
    }

    /**
     * Renglones legibles con las causas de un error de ML (`cause[]`), primero los errores y, si
     * no hay ninguno, las advertencias. Sin causas, el `message` general.
     *
     * @param array<string, mixed>|null $body
     * @return string
     */
    protected function causas_legibles($body)
    {
        if (!is_array($body)) {
            return '- Mercado Libre no explicó el motivo.';
        }

        $errores = [];
        $advertencias = [];

        if (isset($body['cause']) && is_array($body['cause'])) {
            foreach ($body['cause'] as $causa) {
                if (!is_array($causa) || empty($causa['message'])) {
                    continue;
                }

                $renglon = '- '.ErrorHandler::translate_meli_message((string) $causa['message']);
                if (!empty($causa['code'])) {
                    $renglon .= ' (Código: '.$causa['code'].')';
                }

                if (isset($causa['type']) && $causa['type'] === 'warning') {
                    $advertencias[] = $renglon;
                } else {
                    $errores[] = $renglon;
                }
            }
        }

        if (count($errores) > 0) {
            return implode("\n", $errores);
        }

        if (count($advertencias) > 0) {
            return implode("\n", $advertencias);
        }

        if (!empty($body['message'])) {
            return '- '.ErrorHandler::translate_meli_message((string) $body['message']);
        }

        if (!empty($body['error'])) {
            return '- '.(string) $body['error'];
        }

        return '- Mercado Libre no explicó el motivo.';
    }

    // ------------------------------------------------------------------------------------------
    // Alta
    // ------------------------------------------------------------------------------------------

    /**
     * Publica el artículo: arma el payload, lo valida con ML y recién ahí lo crea.
     *
     * @param Article $article
     * @return array<int, string> Avisos para el registro de la sincronización.
     */
    protected function crear_publicacion(Article $article)
    {
        $avisos = [];

        $meli_payload = $this->build_create_payload($article);

        $this->validar_con_mercado_libre($meli_payload);

        // Sin reintento ante un 5xx ni ante un timeout: un POST que falló o se cortó del lado de ML
        // puede haber creado el ítem igual, y reintentar a ciegas publicaría dos veces el mismo
        // artículo.
        try {
            $response = $this->meli_authenticated_request('post', 'items', $meli_payload, [], false);
        } catch (ConnectionException $e) {
            Log::warning('Sync ML artículo '.$article->id.': el POST items se cortó: '.$e->getMessage());

            throw new \Exception(
                'Se cortó la conexión con Mercado Libre mientras se creaba la publicación. Antes de reintentar, '.
                'fijate en tu cuenta de Mercado Libre si la publicación quedó creada, para no duplicarla.'
            );
        }

        if ($response->status() >= 500) {
            throw new \Exception(
                'Mercado Libre falló al crear la publicación (HTTP '.$response->status().'). Antes de reintentar, '.
                'fijate en tu cuenta de Mercado Libre si la publicación quedó creada, para no duplicarla.'
            );
        }

        if (!$response->successful()) {
            throw new \Exception('Mercado Libre API error: '.$response->body());
        }

        $item = $response->json();

        if (!is_array($item) || empty($item['id'])) {
            throw new \Exception('Mercado Libre no devolvió el código de la publicación creada.');
        }

        $estado = [
            'fotos'     => $this->hash_de($meli_payload['pictures']),
            'atributos' => isset($meli_payload['attributes']) ? $this->hash_de($meli_payload['attributes']) : null,
        ];

        $columnas = [
            'me_li_id'             => (string) $item['id'],
            'meli_permalink'       => isset($item['permalink']) ? (string) $item['permalink'] : null,
            'meli_status'          => isset($item['status']) ? (string) $item['status'] : null,
            'meli_user_product_id' => isset($item['user_product_id']) ? (string) $item['user_product_id'] : null,
        ];

        $this->guardar_columnas($article, $columnas, $estado);

        // La descripción va aparte: ML pide crear el ítem sin descripción y agregarla después.
        $texto = MercadoLibreArticuloService::texto_de_descripcion($article);
        if (!is_null($texto)) {
            try {
                $this->subir_descripcion((string) $item['id'], $texto, true);
                $estado['descripcion'] = $this->hash_de($texto);
                $this->guardar_columnas($article, [], $estado);
            } catch (MercadoLibreLimiteException $e) {
                $avisos[] = 'La publicación se creó pero la descripción no se pudo subir ahora; se sube en la próxima sincronización.';
            } catch (\Throwable $e) {
                Log::warning('Sync ML artículo '.$article->id.': descripción no subida: '.$e->getMessage());
                $avisos[] = 'La publicación se creó pero Mercado Libre no aceptó la descripción.';
            }
        }

        return $avisos;
    }

    /**
     * Arma el cuerpo para POST /items (nueva publicación).
     *
     * @param Article $article
     * @return array<string, mixed>
     */
    protected function build_create_payload(Article $article)
    {
        $category = $article->meli_category;
        if (!$category || empty($category->meli_category_id)) {
            throw new \Exception('El artículo no tiene categoría de Mercado Libre. Elegila en el formulario del artículo.');
        }

        $category_id = (string) $category->meli_category_id;

        $precio = $this->precio_validado($article);

        $fotos = MercadoLibreArticuloService::fotos_publicables($article);
        if (count($fotos) === 0) {
            throw new \Exception('El artículo no tiene fotos que Mercado Libre pueda bajar. Cargale al menos una foto.');
        }

        $stock_local = $this->stock_entero($article);
        if ($stock_local <= 0) {
            throw new \Exception('El artículo no tiene stock: Mercado Libre no deja publicar sin unidades disponibles. Cuando le cargues stock se publica solo.');
        }

        $site_id = $this->resolve_site_id_from_category_id($category_id);
        $currency_id = $this->resolve_currency_id_from_site_id($site_id);

        $desired_listing_type = $article->meli_listing_type && $article->meli_listing_type->meli_id
            ? $article->meli_listing_type->meli_id
            : 'gold_special';

        $listing_type_id = $this->resolve_available_listing_type_id($desired_listing_type, $category_id);

        $buying_mode = $article->meli_buying_mode && $article->meli_buying_mode->meli_id
            ? $article->meli_buying_mode->meli_id
            : 'buy_it_now';

        $condition = $article->meli_item_condition && $article->meli_item_condition->meli_id
            ? $article->meli_item_condition->meli_id
            : 'new';

        // La publicación gratuita admite stock 1 nada más.
        $available_quantity = $listing_type_id === 'free' ? 1 : $stock_local;

        $nombre = $this->nombre_recortado($article, $category_id);

        $meli_payload = [
            'site_id'            => $site_id,
            'category_id'        => $category_id,
            'currency_id'        => $currency_id,
            'price'              => $precio,
            'available_quantity' => $available_quantity,
            'buying_mode'        => $buying_mode,
            'listing_type_id'    => $listing_type_id,
            'condition'          => $condition,
            'pictures'           => $this->pictures_desde($fotos),
        ];

        // Modelo User Products: ML genera el título desde `family_name` y rechaza `title` (400).
        if ($this->es_vendedor_user_products()) {
            $meli_payload['family_name'] = $nombre;
        } else {
            $meli_payload['title'] = $nombre;
        }

        $atributos = $this->armar_atributos($article);
        if (count($atributos) > 0) {
            $meli_payload['attributes'] = $atributos;
        }

        return $meli_payload;
    }

    /**
     * `POST items/validate` con el payload del alta: ML responde 204 si lo publicaría. Si no, se
     * corta acá con las causas legibles y NO se publica (no hay sandbox en ML, y una cantidad
     * alta de 400 en `POST items` puede hacer que bloqueen la app).
     *
     * @param array<string, mixed> $meli_payload
     * @return void
     */
    protected function validar_con_mercado_libre(array $meli_payload)
    {
        $response = $this->meli_authenticated_request('post', 'items/validate', $meli_payload);

        if ($response->successful()) {
            return;
        }

        throw new \Exception(
            "Mercado Libre no aceptó la publicación (no se publicó nada):\n".$this->causas_legibles($response->json())
        );
    }

    // ------------------------------------------------------------------------------------------
    // Actualización
    // ------------------------------------------------------------------------------------------

    /**
     * Actualiza una publicación existente con la menor cantidad de llamadas: `GET items/{id}` y un
     * `PUT` solo con lo que cambió (más la descripción si cambió).
     *
     * @param Article $article
     * @return array<int, string> Avisos para el registro de la sincronización.
     */
    protected function actualizar_publicacion(Article $article)
    {
        $me_li_id = (string) $article->me_li_id;
        $avisos = [];

        $meli_item = $this->make_request('get', "items/{$me_li_id}");
        if (!is_array($meli_item)) {
            throw new \Exception("La publicación {$me_li_id} no existe en Mercado Libre (puede haber sido eliminada).");
        }

        $status = isset($meli_item['status']) ? (string) $meli_item['status'] : null;
        $estado = $this->estado_de_sync($article);

        $columnas = [
            'meli_status'          => $status,
            'meli_permalink'       => isset($meli_item['permalink']) ? (string) $meli_item['permalink'] : $article->meli_permalink,
            'meli_user_product_id' => !empty($meli_item['user_product_id']) ? (string) $meli_item['user_product_id'] : $article->meli_user_product_id,
        ];

        if ($status === 'closed') {
            $this->guardar_columnas($article, $columnas, $estado);

            throw new \Exception('La publicación está finalizada en Mercado Libre (cerrada) y no se puede reactivar. Para volver a venderlo hay que publicarlo de nuevo.');
        }

        if ($status === 'under_review') {
            $this->guardar_columnas($article, $columnas, $estado);
            $motivo = $this->motivo_de_moderacion($me_li_id);

            throw new \Exception(
                'Mercado Libre está revisando la publicación'.
                (!is_null($motivo) ? ': '.$motivo : '. Entrá a Mercado Libre para ver qué hay que corregir.')
            );
        }

        $es_catalogo = $this->meli_item_is_catalog_listing($meli_item);
        $es_user_product = $this->meli_item_is_user_product_listing($meli_item) || !empty($meli_item['family_name']);

        $meli_payload = [];

        // Errores que NO frenan el resto (sobre todo el stock: no mandarlo es vender lo que no
        // hay). Se informan al final, con la sync en error, después de haber mandado lo demás.
        $errores_parciales = [];

        // Precio. Solo si cambió (si ML ya no lo devuelve en /items, se manda igual). Si no valida
        // (mínimo de $1.000, sin precio), no se manda, pero el stock y el estado viajan igual.
        try {
            $precio = $this->precio_validado($article);
            if (!isset($meli_item['price']) || abs((float) $meli_item['price'] - $precio) >= 0.01) {
                $meli_payload['price'] = $precio;
            }
        } catch (MercadoLibreLimiteException $e) {
            throw $e;
        } catch (\Exception $e) {
            $errores_parciales[] = $e->getMessage();
        }

        // Título / family_name: solo sin ventas, fuera de catálogo, y si cambió.
        if (!$es_catalogo && (int) ($meli_item['sold_quantity'] ?? 0) === 0) {
            $categoria_meli = $article->meli_category ? (string) $article->meli_category->meli_category_id : (string) ($meli_item['category_id'] ?? '');
            $nombre = $this->nombre_recortado($article, $categoria_meli);
            $campo = $es_user_product ? 'family_name' : 'title';
            $actual = isset($meli_item[$campo]) ? (string) $meli_item[$campo] : '';

            if (mb_strtolower(trim($nombre)) !== mb_strtolower(trim($actual))) {
                $meli_payload[$campo] = $nombre;
            }
        }

        // Stock y pausa por stock.
        list($cambios_de_stock, $estado, $aviso_de_stock) = $this->cambios_de_stock($article, $meli_item, $estado, $es_catalogo, $es_user_product);
        $meli_payload = array_merge($meli_payload, $cambios_de_stock);
        if (!is_null($aviso_de_stock)) {
            $avisos[] = $aviso_de_stock;
        }

        $estado_nuevo = $estado;

        // Fotos y atributos: solo si cambiaron respecto de lo último que se mandó. En catálogo
        // los pone ML.
        if (!$es_catalogo) {
            $fotos = MercadoLibreArticuloService::fotos_publicables($article);
            if (count($fotos) > 0) {
                $pictures = $this->pictures_desde($fotos);
                $hash_fotos = $this->hash_de($pictures);
                if (!isset($estado['fotos']) || $estado['fotos'] !== $hash_fotos) {
                    $meli_payload['pictures'] = $pictures;
                    $estado_nuevo['fotos'] = $hash_fotos;
                }
            }

            $atributos = $this->armar_atributos($article);
            if (count($atributos) > 0) {
                $hash_atributos = $this->hash_de($atributos);
                if (!isset($estado['atributos']) || $estado['atributos'] !== $hash_atributos) {
                    $meli_payload['attributes'] = $atributos;
                    $estado_nuevo['atributos'] = $hash_atributos;
                }
            }
        }

        // Si en lo que hay que mandar va stock o estado (para el mensaje final).
        $habia_stock_para_mandar = isset($meli_payload['available_quantity']) || isset($meli_payload['status']);

        if (count($meli_payload) > 0) {
            $resultado = $this->put_meli_item_with_fallback($me_li_id, $meli_payload);
            $avisos = array_merge($avisos, $resultado['avisos']);

            if (is_array($resultado['item']) && isset($resultado['item']['status'])) {
                $columnas['meli_status'] = (string) $resultado['item']['status'];
            }

            // ML rechazó el PUT combinado y solo pasó el stock/estado: lo demás (fotos, atributos,
            // título, precio) NO quedó en ML, así que tampoco se da por mandado.
            if (!is_null($resultado['error_parcial'])) {
                $errores_parciales[] = $resultado['error_parcial'];
                foreach (['fotos', 'atributos'] as $clave) {
                    if (isset($estado[$clave])) {
                        $estado_nuevo[$clave] = $estado[$clave];
                    } else {
                        unset($estado_nuevo[$clave]);
                    }
                }
            }

            // ML rechazó `available_quantity` en /items: la publicación es User Product, se usa
            // el recurso de stock del UP.
            if (in_array('available_quantity', $resultado['quitados'], true) && !empty($meli_item['user_product_id'])) {
                try {
                    $this->sync_user_product_stock($article, $meli_item);
                } catch (MercadoLibreLimiteException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    Log::warning('Sync ML artículo '.$article->id.': stock por user product falló: '.$e->getMessage());
                    $avisos[] = 'Mercado Libre no dejó actualizar el stock de esta publicación.';
                }
            }
        }

        // Descripción: solo si cambió.
        $texto = MercadoLibreArticuloService::texto_de_descripcion($article);
        if (!is_null($texto) && !$es_catalogo) {
            $hash_texto = $this->hash_de($texto);
            if (!isset($estado['descripcion']) || $estado['descripcion'] !== $hash_texto) {
                try {
                    $this->subir_descripcion($me_li_id, $texto, !isset($estado['descripcion']));
                    $estado_nuevo['descripcion'] = $hash_texto;
                } catch (MercadoLibreLimiteException $e) {
                    $avisos[] = 'La descripción no se pudo actualizar ahora; se reintenta en la próxima sincronización.';
                } catch (\Throwable $e) {
                    Log::warning('Sync ML artículo '.$article->id.': descripción no actualizada: '.$e->getMessage());
                    $avisos[] = 'Mercado Libre no aceptó la descripción.';
                }
            }
        }

        $this->guardar_columnas($article, $columnas, $estado_nuevo);

        if (count($errores_parciales) > 0) {
            $cierre = $habia_stock_para_mandar
                ? 'El stock y el estado de la publicación sí se actualizaron en Mercado Libre.'
                : 'El stock en Mercado Libre ya estaba al día.';

            throw new \Exception(implode("\n", $errores_parciales)."\n".$cierre);
        }

        return $avisos;
    }

    /**
     * El artículo se desmarcó de "Disponible en Mercado Libre" pero tiene publicación: se PAUSA
     * (`status=paused`) y se anota `pausado_por_sistema`, para que al volver a marcarlo la
     * sincronización normal la reactive. Si ya estaba pausada (por el vendedor o por falta de
     * stock) o cerrada, no se toca: una pausa que no hizo el sistema no se reactiva sola después.
     *
     * @param Article $article
     * @return array<int, string> Avisos para el registro de la sincronización.
     */
    protected function pausar_por_no_disponible(Article $article)
    {
        $me_li_id = (string) $article->me_li_id;

        $meli_item = $this->make_request('get', "items/{$me_li_id}");
        if (!is_array($meli_item)) {
            throw new \Exception("La publicación {$me_li_id} no existe en Mercado Libre (puede haber sido eliminada).");
        }

        $status = isset($meli_item['status']) ? (string) $meli_item['status'] : null;
        $estado = $this->estado_de_sync($article);

        $columnas = [
            'meli_status'    => $status,
            'meli_permalink' => isset($meli_item['permalink']) ? (string) $meli_item['permalink'] : $article->meli_permalink,
        ];

        if ($status === 'closed') {
            $this->guardar_columnas($article, $columnas, $estado);

            return ['El artículo no está marcado como «Disponible en Mercado Libre» y la publicación ya estaba finalizada.'];
        }

        if ($status === 'paused') {
            $this->guardar_columnas($article, $columnas, $estado);

            return ['El artículo no está marcado como «Disponible en Mercado Libre» y la publicación ya estaba pausada.'];
        }

        $resultado = $this->put_meli_item_with_fallback($me_li_id, ['status' => 'paused']);

        $columnas['meli_status'] = (is_array($resultado['item']) && isset($resultado['item']['status']))
            ? (string) $resultado['item']['status']
            : 'paused';
        $estado['pausado_por_sistema'] = true;

        $this->guardar_columnas($article, $columnas, $estado);

        return ['El artículo no está marcado como «Disponible en Mercado Libre»: la publicación se pausó en Mercado Libre. Se reactiva sola cuando lo vuelvas a marcar.'];
    }

    /**
     * Cambios de stock / estado para el PUT, según tipo y condición de la publicación:
     *
     * - Catálogo: no se toca (lo sincroniza la publicación de marketplace asociada).
     * - User Product con multiorigen: no se toca (va por depósito en ML) y se avisa.
     * - Nueva y no gratuita: `available_quantity = max(stock, 0)`. Con 0 ML la pausa sola como
     *   `out_of_stock` y la reactiva sola al volver el stock.
     * - Gratuita o usada: con stock <= 0 se pausa (`status=paused`) y se anota
     *   `pausado_por_sistema` en `meli_sync_state`; al volver el stock se reactiva SOLO si la había
     *   pausado el sistema (una pausa del vendedor desde ML se respeta).
     *
     * @param Article $article
     * @param array<string, mixed> $meli_item
     * @param array<string, mixed> $estado `meli_sync_state` actual.
     * @param bool $es_catalogo
     * @param bool $es_user_product
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string|null}
     */
    protected function cambios_de_stock(Article $article, array $meli_item, array $estado, $es_catalogo, $es_user_product)
    {
        $cambios = [];

        // En catálogo y en multiorigen el stock no se toca desde acá; lo único que sí se hace es
        // reactivar una publicación que pausó el sistema (por ejemplo, porque el artículo se
        // desmarcó de "Disponible en Mercado Libre" y ahora se volvió a marcar).
        if ($es_catalogo || ($es_user_product && $this->es_multiorigen())) {
            if (isset($meli_item['status']) && $meli_item['status'] === 'paused' && !empty($estado['pausado_por_sistema'])) {
                $cambios['status'] = 'active';
                $estado['pausado_por_sistema'] = false;
            }

            $aviso = $es_catalogo
                ? null
                : 'La cuenta usa multiorigen: el stock se maneja por depósito en Mercado Libre y no se actualiza desde el sistema.';

            return [$cambios, $estado, $aviso];
        }

        $stock = max($this->stock_entero($article), 0);
        $listing_type_id = isset($meli_item['listing_type_id']) ? (string) $meli_item['listing_type_id'] : null;
        $condition = isset($meli_item['condition']) ? (string) $meli_item['condition'] : 'new';
        $status = isset($meli_item['status']) ? (string) $meli_item['status'] : null;
        $cantidad_en_ml = isset($meli_item['available_quantity']) ? (int) $meli_item['available_quantity'] : null;
        $pausado_por_sistema = !empty($estado['pausado_por_sistema']);

        $pausa_por_falta_de_stock = $listing_type_id === 'free' || $condition === 'used';

        if ($pausa_por_falta_de_stock) {
            if ($stock <= 0) {
                if ($status !== 'paused') {
                    $cambios['status'] = 'paused';
                    $estado['pausado_por_sistema'] = true;
                }

                return [$cambios, $estado, null];
            }

            $cantidad = $listing_type_id === 'free' ? 1 : $stock;
            if ($cantidad_en_ml !== $cantidad) {
                $cambios['available_quantity'] = $cantidad;
            }
        } else {
            if ($cantidad_en_ml !== $stock) {
                $cambios['available_quantity'] = $stock;
            }
        }

        if ($stock > 0 && $status === 'paused' && $pausado_por_sistema) {
            $cambios['status'] = 'active';
        }

        if ($stock > 0) {
            $estado['pausado_por_sistema'] = false;
        }

        return [$cambios, $estado, null];
    }

    /**
     * Motivo de la última moderación de la publicación, o null si no se pudo consultar.
     *
     * @param string $me_li_id
     * @return string|null
     */
    protected function motivo_de_moderacion($me_li_id)
    {
        try {
            $moderacion = $this->make_request('get', "moderations/last_moderation/{$me_li_id}-ITM");
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($moderacion)) {
            return null;
        }

        $partes = [];
        foreach (['reason', 'remedy', 'description', 'message'] as $clave) {
            if (!empty($moderacion[$clave]) && is_string($moderacion[$clave])) {
                $partes[] = trim($moderacion[$clave]);
            }
        }

        if (count($partes) === 0 && isset($moderacion['reasons']) && is_array($moderacion['reasons'])) {
            foreach ($moderacion['reasons'] as $razon) {
                if (is_array($razon) && !empty($razon['description'])) {
                    $partes[] = trim((string) $razon['description']);
                } elseif (is_string($razon)) {
                    $partes[] = trim($razon);
                }
            }
        }

        return count($partes) > 0 ? implode(' — ', array_unique($partes)) : null;
    }

    /**
     * Indica si el ítem tiene un tag de ML en su array tags.
     *
     * @param array<string, mixed> $meli_item
     * @param string $tag
     * @return bool
     */
    protected function meli_item_has_tag(array $meli_item, $tag)
    {
        $tags = $meli_item['tags'] ?? [];
        if (!is_array($tags)) {
            return false;
        }

        return in_array($tag, $tags, true);
    }

    /**
     * Publicación bajo el modelo User Products.
     *
     * @param array<string, mixed> $meli_item
     * @return bool
     */
    protected function meli_item_is_user_product_listing(array $meli_item)
    {
        if ($this->meli_item_has_tag($meli_item, 'user_product_listing')) {
            return true;
        }

        $user_product_id = $meli_item['user_product_id'] ?? null;

        return $user_product_id !== null && $user_product_id !== '';
    }

    /**
     * Publicación de catálogo: título, fotos y ficha los pone ML; precio y stock se sincronizan
     * desde la publicación de marketplace asociada.
     *
     * @param array<string, mixed> $meli_item
     * @return bool
     */
    protected function meli_item_is_catalog_listing(array $meli_item)
    {
        return !empty($meli_item['catalog_listing']);
    }

    /**
     * FALLBACK: actualiza el stock de una publicación User Product por
     * `PUT user-products/{id}/stock/type/{tipo}` (con `x-version`). Se usa solo si ML rechazó
     * `available_quantity` en `PUT items`.
     *
     * @param Article $article
     * @param array<string, mixed> $meli_item
     * @return void
     */
    protected function sync_user_product_stock(Article $article, array $meli_item)
    {
        $user_product_id = $meli_item['user_product_id'] ?? null;
        if ($user_product_id === null || $user_product_id === '') {
            Log::warning("Sync ML artículo {$article->id}: ítem User Product sin user_product_id; no se actualiza stock.");

            return;
        }

        $listing_type_id = $meli_item['listing_type_id'] ?? null;
        $stock_local = $this->stock_entero($article);
        if ($listing_type_id === 'free') {
            $target_quantity = min(max($stock_local, 0), 1);
        } else {
            $target_quantity = max($stock_local, 0);
        }

        $stock_response = $this->meli_authenticated_request('get', "user-products/{$user_product_id}/stock");
        if ($stock_response->status() === 404) {
            throw new \Exception("No se pudo obtener stock del user_product {$user_product_id} en Mercado Libre.");
        }
        if (!$stock_response->successful()) {
            throw new \Exception('Mercado Libre API error: '.$stock_response->body());
        }

        $x_version = $stock_response->header('x-version');
        if ($x_version === null || $x_version === '') {
            throw new \Exception("Mercado Libre no devolvió x-version para user_product {$user_product_id}.");
        }

        $stock_body = $stock_response->json();
        $location_type = $this->resolve_user_product_stock_location_type($stock_body);

        $put_response = $this->meli_authenticated_request(
            'put',
            "user-products/{$user_product_id}/stock/type/{$location_type}",
            ['quantity' => $target_quantity],
            ['x-version' => $x_version]
        );

        if (!$put_response->successful()) {
            throw new \Exception('Mercado Libre API error al actualizar stock user_product: '.$put_response->body());
        }

        Log::info("Sync ML artículo {$article->id}: stock user_product {$user_product_id} → {$target_quantity} ({$location_type}).");
    }

    /**
     * Elige la ubicación de stock a actualizar según lo que devuelve GET user-products/{id}/stock.
     *
     * @param array<string, mixed>|null $stock_body
     * @return string selling_address|meli_facility
     */
    protected function resolve_user_product_stock_location_type($stock_body)
    {
        $locations = [];
        if (is_array($stock_body) && isset($stock_body['locations']) && is_array($stock_body['locations'])) {
            $locations = $stock_body['locations'];
        }

        $types = [];
        foreach ($locations as $location) {
            if (is_array($location) && !empty($location['type'])) {
                $types[] = $location['type'];
            }
        }

        if (in_array('selling_address', $types, true)) {
            return 'selling_address';
        }

        if (in_array('meli_facility', $types, true)) {
            return 'meli_facility';
        }

        return 'selling_address';
    }

    /**
     * PUT /items con reintento omitiendo los campos que ML rechace como no modificables.
     *
     * Si lo único que había para mandar era el precio y ML no lo deja cambiar (automatización de
     * precios activa), no es un error: se avisa y la sincronización sigue.
     *
     * Último recurso (revisión de la misión, 27/10/2026): si ML rechaza el PUT por otro motivo
     * (una foto, un atributo) y en el payload iba stock o estado, se reintenta con SOLO
     * `available_quantity` / `status`. Un error en una foto no puede dejar la publicación vendiendo
     * unidades que ya no hay. Si eso pasa, el motivo del rechazo vuelve en `error_parcial` para que
     * la sincronización quede en error y lo muestre.
     *
     * @param string $me_li_id
     * @param array<string, mixed> $meli_payload
     * @return array{item: array<string, mixed>|null, quitados: array<int, string>, avisos: array<int, string>, error_parcial: string|null}
     */
    protected function put_meli_item_with_fallback($me_li_id, array $meli_payload)
    {
        $resultado = ['item' => null, 'quitados' => [], 'avisos' => [], 'error_parcial' => null];

        if (count($meli_payload) === 0) {
            return $resultado;
        }

        $original = $meli_payload;

        try {
            $resultado['item'] = $this->make_request('put', "items/{$me_li_id}", $meli_payload);

            return $resultado;
        } catch (MercadoLibreLimiteException $e) {
            throw $e;
        } catch (\Exception $e) {
            $error = $e;
        }

        $reduced_payload = $this->remove_blocked_item_fields_from_payload($meli_payload, $error);

        if ($reduced_payload !== null) {
            $resultado['quitados'] = array_values(array_diff(array_keys($meli_payload), array_keys($reduced_payload)));

            if (in_array('price', $resultado['quitados'], true)) {
                $resultado['avisos'][] = 'Mercado Libre no deja cambiar el precio de esta publicación (tiene automatización de precios activa).';
            }

            if (count($reduced_payload) === 0) {
                return $resultado;
            }

            Log::info("Reintento PUT /items/{$me_li_id} sin campos rechazados por Mercado Libre.");

            try {
                $resultado['item'] = $this->make_request('put', "items/{$me_li_id}", $reduced_payload);

                return $resultado;
            } catch (MercadoLibreLimiteException $e) {
                throw $e;
            } catch (\Exception $e) {
                $error = $e;
                $meli_payload = $reduced_payload;
            }
        }

        // Último recurso: solo stock y estado.
        $solo_stock = array_intersect_key($meli_payload, ['available_quantity' => true, 'status' => true]);

        if (count($solo_stock) === 0 || count($solo_stock) === count($meli_payload)) {
            throw $error;
        }

        Log::info("Reintento PUT /items/{$me_li_id} solo con stock y estado (Mercado Libre rechazó el resto).");

        $resultado['item'] = $this->make_request('put', "items/{$me_li_id}", $solo_stock);
        $resultado['quitados'] = array_values(array_diff(array_keys($original), array_keys($solo_stock)));
        $resultado['error_parcial'] = $this->mensaje_legible($error->getMessage());

        return $resultado;
    }

    /**
     * Quita del payload campos que ML indicó como no modificables en el error de validación.
     *
     * @param array<string, mixed> $meli_payload
     * @param \Exception $e
     * @return array<string, mixed>|null null si no hay nada que quitar o el payload no cambia
     */
    protected function remove_blocked_item_fields_from_payload(array $meli_payload, \Exception $e)
    {
        $error_message = $e->getMessage();
        $reduced_payload = $meli_payload;
        $changed = false;

        if (strpos($error_message, 'item.price.not_modifiable') !== false || strpos($error_message, 'price_automation') !== false) {
            if (array_key_exists('price', $reduced_payload)) {
                unset($reduced_payload['price']);
                $changed = true;
            }
        }

        if (
            strpos($error_message, 'field_not_updatable') !== false
            || strpos($error_message, 'available_quantity') !== false
        ) {
            if (array_key_exists('available_quantity', $reduced_payload)) {
                unset($reduced_payload['available_quantity']);
                $changed = true;
            }
            if (array_key_exists('status', $reduced_payload)) {
                unset($reduced_payload['status']);
                $changed = true;
            }
        }

        if (!$changed) {
            return null;
        }

        return $reduced_payload;
    }

    // ------------------------------------------------------------------------------------------
    // Piezas comunes
    // ------------------------------------------------------------------------------------------

    /**
     * Precio para ML ya validado: con fallback al precio final y no menos de $1.000.
     *
     * @param Article $article
     * @return float
     */
    protected function precio_validado(Article $article)
    {
        $precio = MercadoLibreArticuloService::precio_para_meli($article);

        if (is_null($precio)) {
            throw new \Exception('El artículo no tiene precio para Mercado Libre. Cargale un precio (o marcá una lista de precios para Mercado Libre).');
        }

        if ($precio < MercadoLibreArticuloService::PRECIO_MINIMO) {
            throw new \Exception(
                'El precio para Mercado Libre ($'.MercadoLibreArticuloService::formatear_precio($precio).') está debajo del mínimo de $'.
                MercadoLibreArticuloService::formatear_precio(MercadoLibreArticuloService::PRECIO_MINIMO).' que exige Mercado Libre.'
            );
        }

        return round($precio, 2);
    }

    /**
     * Precio a usar en ML (compatibilidad con llamadores viejos): ver `precio_validado()`.
     *
     * @param Article $article
     * @return float
     */
    public function get_price($article)
    {
        return $this->precio_validado($article);
    }

    /**
     * Stock del artículo en unidades enteras (ML no admite fracciones; 2,5 son 2).
     *
     * @param Article $article
     * @return int
     */
    protected function stock_entero(Article $article)
    {
        return (int) floor((float) $article->stock);
    }

    /**
     * Nombre del artículo recortado al largo máximo de título de la categoría.
     *
     * @param Article $article
     * @param string $meli_category_id
     * @return string
     */
    protected function nombre_recortado(Article $article, $meli_category_id)
    {
        $maximo = self::LARGO_TITULO_POR_DEFECTO;

        if ($meli_category_id !== '') {
            try {
                $categoria = (new CatalogoPublicoService($this->user_id))->categoria($meli_category_id);
                if (is_array($categoria) && isset($categoria['settings']['max_title_length']) && (int) $categoria['settings']['max_title_length'] > 0) {
                    $maximo = (int) $categoria['settings']['max_title_length'];
                }
            } catch (\Throwable $e) {
                // Sin la categoría se usa el largo por defecto.
            }
        }

        $nombre = trim(preg_replace('/\s+/u', ' ', (string) $article->name));

        if (mb_strlen($nombre) > $maximo) {
            $nombre = rtrim(mb_substr($nombre, 0, $maximo));
        }

        return $nombre;
    }

    /**
     * `pictures` del payload a partir de las URLs.
     *
     * @param array<int, string> $fotos
     * @return array<int, array<string, string>>
     */
    protected function pictures_desde(array $fotos)
    {
        $pictures = [];
        foreach ($fotos as $url) {
            $pictures[] = ['source' => $url];
        }

        return $pictures;
    }

    /**
     * Construye el array pictures para la API de items (compatibilidad).
     *
     * @param Article $article
     * @return array<int, array<string, string>>
     */
    protected function build_pictures_payload(Article $article)
    {
        return $this->pictures_desde(MercadoLibreArticuloService::fotos_publicables($article));
    }

    /**
     * Atributos del ítem: los que cargó el usuario y, para los que no cargó, los automáticos
     * (marca, código de barras, SKU) si la categoría los tiene.
     *
     * @param Article $article
     * @return array<int, array<string, string>>
     */
    protected function armar_atributos(Article $article)
    {
        $por_id = [];

        foreach ($article->meli_attributes as $meli_attribute) {
            $meli_id = (string) $meli_attribute->meli_id;
            if ($meli_id === '') {
                continue;
            }

            $attribute = ['id' => $meli_id];

            $value_id = trim((string) $meli_attribute->pivot->value_id);
            $value_name = trim((string) $meli_attribute->pivot->value_name);

            if ($value_id !== '') {
                $attribute['value_id'] = $value_id;
            }

            if ($value_name !== '') {
                $attribute['value_name'] = $value_name;
            }

            if (count($attribute) > 1) {
                $por_id[$meli_id] = $attribute;
            }
        }

        $category = $article->meli_category;

        if ($category) {
            $automaticos = MeliAttribute::where('meli_category_id', $category->id)
                ->whereIn('meli_id', ['BRAND', 'GTIN', 'SELLER_SKU'])
                ->pluck('meli_id')
                ->all();

            foreach ($automaticos as $meli_id) {
                if (isset($por_id[$meli_id])) {
                    continue;
                }

                $valor = MercadoLibreArticuloService::valor_automatico($article, $meli_id);

                if (!is_null($valor)) {
                    $por_id[$meli_id] = ['id' => (string) $meli_id, 'value_name' => $valor];
                }
            }
        }

        return array_values($por_id);
    }

    /**
     * Agrega atributos de categoría ML al payload del ítem (compatibilidad).
     *
     * @param array<string, mixed> $meli_payload
     * @param Article $article
     * @return array<string, mixed>
     */
    public function add_attributes($meli_payload, $article)
    {
        $meli_payload['attributes'] = $this->armar_atributos($article);

        return $meli_payload;
    }

    /**
     * Sube la descripción en texto plano. `$es_nueva`: primero `POST` y, si ML dice que ya hay una,
     * `PUT`. Si no: `PUT` y, si no había ninguna (404), `POST`.
     *
     * @param string $me_li_id
     * @param string $texto
     * @param bool $es_nueva
     * @return void
     */
    protected function subir_descripcion($me_li_id, $texto, $es_nueva)
    {
        $payload = ['plain_text' => $texto];
        $endpoint = "items/{$me_li_id}/description";

        $primero = $es_nueva ? 'post' : 'put';
        $segundo = $es_nueva ? 'put' : 'post';

        $response = $this->meli_authenticated_request($primero, $endpoint, $payload);

        if ($response->successful()) {
            return;
        }

        $response = $this->meli_authenticated_request($segundo, $endpoint, $payload);

        if (!$response->successful()) {
            throw new \Exception('Mercado Libre API error: '.$response->body());
        }
    }

    /**
     * Crea o actualiza la descripción en texto plano del ítem en ML (compatibilidad con
     * llamadores viejos).
     *
     * @param Article $article
     * @return void
     */
    public function set_description($article)
    {
        $texto = MercadoLibreArticuloService::texto_de_descripcion($article);

        if (is_null($texto) || !$article->me_li_id) {
            return;
        }

        try {
            $estado = $this->estado_de_sync($article);
            $this->subir_descripcion((string) $article->me_li_id, $texto, !isset($estado['descripcion']));
            $estado['descripcion'] = $this->hash_de($texto);
            $this->guardar_columnas($article, [], $estado);
        } catch (\Exception $e) {
            Log::error("Error al setear descripción para artículo ID {$article->id}: ".$e->getMessage());
            $this->notify_meli_exception(
                $e,
                'Error al actualizar descripción en Mercado Libre: '.$article->name
            );
        }
    }

    /**
     * `meli_sync_state` del artículo decodificado.
     *
     * @param Article $article
     * @return array<string, mixed>
     */
    protected function estado_de_sync(Article $article)
    {
        $crudo = $article->meli_sync_state;

        if (is_array($crudo)) {
            return $crudo;
        }

        $estado = json_decode((string) $crudo, true);

        return is_array($estado) ? $estado : [];
    }

    /**
     * Persiste columnas de ML del artículo y su `meli_sync_state` con el query builder (sin
     * eventos del modelo ni `updated_at`): es un dato de la integración, no una edición del
     * artículo, y no tiene por qué disparar el observer (embeddings, etc.) ni cambiar la fecha de
     * modificación que mira la SPA.
     *
     * @param Article $article
     * @param array<string, mixed> $columnas
     * @param array<string, mixed>|null $estado
     * @return void
     */
    protected function guardar_columnas(Article $article, array $columnas, $estado)
    {
        if (is_array($estado)) {
            $columnas['meli_sync_state'] = json_encode($estado);
        }

        if (count($columnas) === 0) {
            return;
        }

        DB::table('articles')->where('id', $article->id)->update($columnas);

        foreach ($columnas as $columna => $valor) {
            $article->setAttribute($columna, $valor);
        }
        $article->syncOriginalAttributes(array_keys($columnas));
    }

    /**
     * Hash estable de un valor (para saber si algo cambió desde la última sincronización).
     *
     * @param mixed $valor
     * @return string
     */
    protected function hash_de($valor)
    {
        return sha1(is_string($valor) ? $valor : json_encode($valor));
    }

    /**
     * Obtiene site_id (MLA, MLB, …) a partir del prefijo del category_id de ML.
     *
     * @param string $category_id
     * @return string
     */
    protected function resolve_site_id_from_category_id($category_id)
    {
        if (strlen($category_id) >= 3) {
            return strtoupper(substr($category_id, 0, 3));
        }

        return 'MLA';
    }

    /**
     * Mapea site_id a currency_id por convención ML (extensible si se agregan sites).
     *
     * @param string $site_id
     * @return string
     */
    protected function resolve_currency_id_from_site_id($site_id)
    {
        $map = [
            'MLA' => 'ARS',
            'MLB' => 'BRL',
            'MLM' => 'MXN',
            'MLC' => 'CLP',
            'MCO' => 'COP',
            'MLU' => 'UYU',
            'MPE' => 'PEN',
            'MLV' => 'VES',
            'MEC' => 'USD',
        ];

        return $map[$site_id] ?? 'ARS';
    }

    /**
     * Verifica listing types disponibles para el vendedor y categoría; si el deseado no está, elige uno válido.
     *
     * @param string $desired_listing_type_id
     * @param string $category_id
     * @return string
     */
    protected function resolve_available_listing_type_id($desired_listing_type_id, $category_id)
    {
        $meli_seller_id = $this->meli_seller_id();
        if ($meli_seller_id === '') {
            return $desired_listing_type_id;
        }

        try {
            $response = $this->make_request(
                'get',
                "users/{$meli_seller_id}/available_listing_types",
                ['category_id' => $category_id]
            );
        } catch (MercadoLibreLimiteException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Si no se pudo consultar, se intenta con el deseado: el validate dirá si no sirve.
            return $desired_listing_type_id;
        }

        $available_ids = [];
        if (isset($response['available']) && is_array($response['available'])) {
            foreach ($response['available'] as $row) {
                if (!empty($row['id'])) {
                    $available_ids[] = $row['id'];
                }
            }
        }

        if (count($available_ids) === 0) {
            return $desired_listing_type_id;
        }

        if (in_array($desired_listing_type_id, $available_ids, true)) {
            return $desired_listing_type_id;
        }

        if (in_array('gold_special', $available_ids, true)) {
            return 'gold_special';
        }

        if (in_array('gold_pro', $available_ids, true)) {
            return 'gold_pro';
        }

        return $available_ids[0];
    }
}
