<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Helpers\ArticlePlatformSyncNotificationHelper;
use App\Models\Article;
use App\Models\MeliAttribute;
use App\Models\SyncToMeliArticle;
use Illuminate\Support\Facades\Log;

/**
 * Sincronización de artículos locales hacia publicaciones de Mercado Libre (items).
 * Aplica reglas de listing_type, stock máximo en publicaciones gratuitas y payload mínimo para updates.
 */
class ProductService extends MercadoLibreService
{
    /**
     * Tipos de publicación que suelen exigir imágenes en actualizaciones (políticas ML 2025-2026).
     *
     * @var array<int, string>
     */
    protected $listing_types_that_require_pictures = [
        'gold_special',
        'gold_pro',
        'gold_premium',
        'silver',
        'gold',
    ];

    /**
     * Si el artículo cumple requisitos mínimos, encola un registro SyncToMeliArticle pendiente.
     *
     * @param Article $article
     * @return void
     */
    public static function add_article_to_sync($article)
    {
        if (!env('USA_MERCADO_LIBRE', false)) {
            return;
        }

        Log::info('add_article_to_sync');

        if (!$article->mercado_libre) {
            Log::error("Artículo Mercado Libre: ID {$article->id}");

            return;
        }

        if (!$article->meli_category_id) {
            Log::error("Artículo sin categoria de Mercado Libre: ID {$article->id}");

            return;
        }

        if (is_null($article->stock)) {
            Log::error("Artículo sin stock para Mercado Libre: ID {$article->id}");

            return;
        }

        if (count($article->images) == 0) {
            Log::error("Artículo sin imagenes para Mercado Libre: ID {$article->id}");

            return;
        }

        $already_exists = SyncToMeliArticle::where('article_id', $article->id)
            ->where('user_id', $article->user_id)
            ->where('status', 'pendiente')
            ->exists();

        if (!$already_exists) {
            SyncToMeliArticle::create([
                'article_id' => $article->id,
                'user_id' => $article->user_id,
                'status' => 'pendiente',
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

        try {
            $article = $sync->article;
            $article->load([
                'meli_category',
                'meli_listing_type',
                'meli_buying_mode',
                'meli_item_condition',
                'images',
                'meli_attributes',
            ]);

            $me_li_id = $article->me_li_id;

            if ($me_li_id) {
                $meli_item = $this->make_request('get', "items/{$me_li_id}");
                if ($meli_item === null || !is_array($meli_item)) {
                    throw new \Exception("No se pudo obtener el ítem {$me_li_id} en Mercado Libre (404 o respuesta inválida).");
                }

                // Stock en publicaciones User Product se actualiza en /user-products, no en PUT /items.
                if ($this->meli_item_quantity_modifiable_via_put_items($meli_item) === false) {
                    $this->sync_user_product_stock($article, $meli_item);
                }

                $meli_payload = $this->build_update_payload($article, $meli_item, $me_li_id);
                $this->put_meli_item_with_fallback($me_li_id, $meli_payload);
            } else {
                $meli_payload = $this->build_create_payload($article);
                $response = $this->make_request('post', 'items', $meli_payload);
                $article->me_li_id = $response['id'];
                $article->save();
            }

            $this->set_description($article);

            $sync->status = 'exitosa';
            $sync->synced_at = now();
            $sync->error_message = null;
            $sync->save();

            $this->notify_on_api_error = $previous_notify_on_api_error;
        } catch (\Exception $e) {
            Log::error('Error al sincronizar artículo con MercadoLibre: '.$e->getMessage());

            $article = $sync->article ?? null;
            $error_message = $e->getMessage();

            if (strpos($error_message, 'Mercado Libre API error:') !== false) {
                $json_part = trim(str_replace('Mercado Libre API error:', '', $error_message));

                $parsed_error = json_decode($json_part, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($parsed_error['cause']) && is_array($parsed_error['cause'])) {
                    $causes = [];
                    foreach ($parsed_error['cause'] as $c) {
                        if (is_array($c) && isset($c['message'], $c['code'])) {
                            $causes[] = "- {$c['message']} (Código: {$c['code']})";
                        }
                    }
                    if (count($causes) > 0) {
                        $error_message = "Errores al sincronizar con MercadoLibre:\n".implode("\n", $causes);
                    }
                }
            }

            $sync->status = 'error';
            $sync->error_message = $error_message;
            $sync->error_message_crudo = $e->getMessage();
            Log::info('Se marco como fallido');
            $sync->save();

            $article_label = $article ? $article->name : ('sync #'.$sync->id);
            ArticlePlatformSyncNotificationHelper::notify_mercado_libre_sync_failed(
                (int) $sync->user_id,
                $article_label,
                $error_message
            );

            $this->notify_on_api_error = $previous_notify_on_api_error;
        }
    }

    /**
     * Arma el cuerpo para PUT /items/{id} según listing_type, stock local y restricciones de ML.
     * Omite price, available_quantity o title cuando la API no los permite en ese ítem.
     *
     * @param Article $article
     * @param array<string, mixed> $meli_item Respuesta GET del ítem en ML
     * @param string $me_li_id ID de publicación en ML
     * @return array<string, mixed>
     */
    protected function build_update_payload(Article $article, array $meli_item, $me_li_id)
    {
        $listing_type_id = $meli_item['listing_type_id'] ?? null;
        $sold_quantity = (int) ($meli_item['sold_quantity'] ?? 0);
        $stock_local = (int) $article->stock;

        $meli_payload = [];

        // Precio: no enviar si hay automatización de precios o el ítem es User Product (ML usa otras APIs).
        if ($this->meli_item_price_modifiable_via_put_items($meli_item, $me_li_id)) {
            $meli_payload['price'] = (float) $this->get_price($article);
        } else {
            Log::info("Sync ML artículo {$article->id}: precio omitido en PUT /items (no modificable en ML).");
        }

        // Título: solo sin ventas y fuera del modelo User Product (ML genera el título en UP).
        if ($sold_quantity === 0 && !$this->meli_item_is_user_product_listing($meli_item)) {
            $meli_payload['title'] = $article->name;
        }

        // Stock/cantidad: en User Product o catálogo se gestiona por otro recurso, no en PUT /items.
        if ($this->meli_item_quantity_modifiable_via_put_items($meli_item)) {
            if ($listing_type_id === 'free') {
                $qty = min(max($stock_local, 0), 1);
                if ($qty > 0) {
                    $meli_payload['available_quantity'] = $qty;
                } else {
                    $meli_payload['status'] = 'paused';
                }
            } else {
                if ($stock_local <= 0) {
                    $meli_payload['status'] = 'paused';
                } else {
                    $meli_payload['available_quantity'] = $stock_local;
                }
            }
        } else {
            Log::info("Sync ML artículo {$article->id}: available_quantity omitido en PUT /items (stock vía user-products).");
        }

        if ($this->listing_type_requires_pictures_in_update($listing_type_id)) {
            $meli_payload['pictures'] = $this->build_pictures_payload($article);
        }

        if (count($article->meli_attributes) > 0) {
            $meli_payload = $this->add_attributes($meli_payload, $article);
        }

        return $meli_payload;
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
     * Publicación bajo el modelo User Products (stock y varios campos no van en PUT /items).
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
     * Publicación de catálogo: cantidad y otros datos suelen estar centralizados en el producto de catálogo.
     *
     * @param array<string, mixed> $meli_item
     * @return bool
     */
    protected function meli_item_is_catalog_listing(array $meli_item)
    {
        return !empty($meli_item['catalog_listing']);
    }

    /**
     * Consulta si el ítem tiene automatización de precios activa en Mercado Libre.
     *
     * @param string $me_li_id
     * @return bool
     */
    protected function meli_item_has_active_price_automation($me_li_id)
    {
        try {
            $automation = $this->make_request('get', "pricing-automation/items/{$me_li_id}/automation");
            if (!is_array($automation)) {
                return false;
            }

            $status = $automation['status'] ?? '';

            return strtoupper((string) $status) === 'ACTIVE';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Indica si el precio puede enviarse en PUT /items/{id} (bloqueado con automatización de precios ML).
     *
     * @param array<string, mixed> $meli_item
     * @param string $me_li_id
     * @return bool
     */
    protected function meli_item_price_modifiable_via_put_items(array $meli_item, $me_li_id)
    {
        if ($this->meli_item_has_active_price_automation($me_li_id)) {
            return false;
        }

        return true;
    }

    /**
     * Indica si available_quantity / status por stock puede enviarse en PUT /items/{id}.
     *
     * @param array<string, mixed> $meli_item
     * @return bool
     */
    protected function meli_item_quantity_modifiable_via_put_items(array $meli_item)
    {
        if ($this->meli_item_is_user_product_listing($meli_item)) {
            return false;
        }

        if ($this->meli_item_is_catalog_listing($meli_item)) {
            return false;
        }

        return true;
    }

    /**
     * Actualiza stock en Mercado Libre para publicaciones User Product (PUT user-products/.../stock).
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
        $stock_local = (int) $article->stock;
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
            ErrorHandler::send_notification($stock_response, $this->user_id);
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
            ErrorHandler::send_notification($put_response, $this->user_id);
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
     * Arma el cuerpo para POST /items (nueva publicación), validando listing_type disponible.
     *
     * @param Article $article
     * @return array<string, mixed>
     */
    protected function build_create_payload(Article $article)
    {
        $category = $article->meli_category;
        if (!$category || empty($category->meli_category_id)) {
            throw new \Exception('Artículo sin meli_category_id para publicar en Mercado Libre.');
        }

        $category_id = $category->meli_category_id;
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

        $stock_local = (int) $article->stock;
        $available_quantity = $listing_type_id === 'free' ? min(max($stock_local, 0), 1) : max($stock_local, 0);

        $meli_payload = [
            'site_id' => $site_id,
            'title' => $article->name,
            'category_id' => $category_id,
            'currency_id' => $currency_id,
            'price' => (float) $this->get_price($article),
            'available_quantity' => $available_quantity,
            'buying_mode' => $buying_mode,
            'listing_type_id' => $listing_type_id,
            'condition' => $condition,
            'pictures' => $this->build_pictures_payload($article),
        ];

        if (count($article->meli_attributes) > 0) {
            $meli_payload = $this->add_attributes($meli_payload, $article);
        }

        return $meli_payload;
    }

    /**
     * PUT /items con reintento omitiendo price o stock si ML responde que no son modificables.
     *
     * @param string $me_li_id
     * @param array<string, mixed> $meli_payload
     * @return void
     */
    protected function put_meli_item_with_fallback($me_li_id, array $meli_payload)
    {
        if (count($meli_payload) === 0) {
            return;
        }

        try {
            $this->make_request('put', "items/{$me_li_id}", $meli_payload);
        } catch (\Exception $e) {
            $reduced_payload = $this->remove_blocked_item_fields_from_payload($meli_payload, $e);
            if ($reduced_payload === null || count($reduced_payload) === 0) {
                throw $e;
            }

            Log::info("Reintento PUT /items/{$me_li_id} sin campos rechazados por Mercado Libre.");
            $this->make_request('put', "items/{$me_li_id}", $reduced_payload);
        }
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

        if (strpos($error_message, 'item.price.not_modifiable') !== false) {
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

    /**
     * Indica si conviene enviar pictures en el PUT (tipos con requires_picture en la práctica ML).
     *
     * @param string|null $listing_type_id
     * @return bool
     */
    protected function listing_type_requires_pictures_in_update($listing_type_id)
    {
        if ($listing_type_id === null || $listing_type_id === '') {
            return false;
        }

        return in_array($listing_type_id, $this->listing_types_that_require_pictures, true);
    }

    /**
     * Construye el array pictures para la API de items.
     *
     * @param Article $article
     * @return array<int, array<string, string>>
     */
    protected function build_pictures_payload(Article $article)
    {
        $pictures = [];
        foreach ($article->images as $image) {
            $row = $image instanceof \ArrayAccess || is_array($image) ? $image : $image->toArray();
            $url = $row['hosting_url'] ?? null;
            if ($url) {
                $pictures[] = ['source' => $url];
            }
        }

        return $pictures;
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

        $response = $this->make_request(
            'get',
            "users/{$meli_seller_id}/available_listing_types",
            ['category_id' => $category_id]
        );

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

    /**
     * Crea o actualiza la descripción en texto plano del ítem en ML.
     *
     * @param Article $article
     * @return void
     */
    public function set_description($article)
    {
        if (!$article->meli_descripcion || !$article->me_li_id) {
            return;
        }

        $meli_payload = [
            'plain_text' => $article->meli_descripcion,
        ];

        try {
            $existing = $this->make_request('get', "items/{$article->me_li_id}/description");
            if ($existing === null) {
                $this->make_request('post', "items/{$article->me_li_id}/description", $meli_payload);
            } else {
                $this->make_request('put', "items/{$article->me_li_id}/description", $meli_payload);
            }
        } catch (\Exception $e) {
            Log::error("Error al setear descripción para artículo ID {$article->id}: ".$e->getMessage());
            $this->notify_meli_exception(
                $e,
                'Error al actualizar descripción en Mercado Libre: '.$article->name
            );
        }
    }

    /**
     * Precio a usar en ML según lista de precios marcada para ML (se_usa_en_ml).
     *
     * @param Article $article
     * @return float
     */
    public function get_price($article)
    {
        $price_type = $article->price_types()->where('se_usa_en_ml', 1)->first();
        if (!$price_type || !isset($price_type->pivot->final_price)) {
            throw new \Exception('No hay lista de precios con se_usa_en_ml para el artículo ID '.$article->id);
        }

        return (float) $price_type->pivot->final_price;
    }

    /**
     * Agrega atributos de categoría ML al payload del ítem.
     *
     * @param array<string, mixed> $meli_payload
     * @param Article $article
     * @return array<string, mixed>
     */
    public function add_attributes($meli_payload, $article)
    {
        $meli_payload['attributes'] = [];

        foreach ($article->meli_attributes as $article_meli_attributes) {
            Log::info('meli_attribute_id: '.$article_meli_attributes->pivot->meli_attribute_id);

            $meli_attribute = MeliAttribute::find($article_meli_attributes->pivot->meli_attribute_id);
            if (!$meli_attribute) {
                continue;
            }

            $attribute = [
                'id' => $meli_attribute->meli_id,
            ];

            if (!empty($article_meli_attributes->pivot->value_id)) {
                $attribute['value_id'] = $article_meli_attributes->pivot->value_id;
            }

            if (!empty($article_meli_attributes->pivot->value_name)) {
                $attribute['value_name'] = $article_meli_attributes->pivot->value_name;
            }

            $meli_payload['attributes'][] = $attribute;
        }

        return $meli_payload;
    }
}
