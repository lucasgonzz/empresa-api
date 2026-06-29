<?php

namespace App\Services;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;

class PdfColumnService
{
    /**
     * Asegura que el catálogo en BD coincida con default_options() (altas/actualizaciones de columnas).
     *
     * @param string $model_name sale|article
     * @return void
     */
    public static function sync_catalog_options($model_name)
    {
        $defaults = self::default_options($model_name);
        if (! is_array($defaults) || ! count($defaults)) {
            return;
        }

        foreach ($defaults as $index => $item) {
            PdfColumnOption::updateOrCreate(
                [
                    'model_name' => $model_name,
                    'value_resolver' => $item['value_resolver'],
                ],
                [
                    'name' => $item['name'],
                    'label' => $item['label'],
                    'default_width' => $item['default_width'],
                    'allow_wrap_content' => $item['allow_wrap_content'],
                    'is_active' => true,
                    'order' => $index,
                ]
            );
        }

        self::remove_duplicate_catalog_options($model_name);
    }

    /**
     * Elimina filas duplicadas por value_resolver (legacy de upserts antiguos).
     *
     * @param string $model_name
     * @return void
     */
    protected static function remove_duplicate_catalog_options($model_name)
    {
        $resolvers_seen = [];
        $options = PdfColumnOption::where('model_name', $model_name)
            ->orderBy('id')
            ->get();

        foreach ($options as $option) {
            if (isset($resolvers_seen[$option->value_resolver])) {
                $option->delete();
                continue;
            }
            $resolvers_seen[$option->value_resolver] = true;
        }
    }

    /**
     * Obtiene opciones activas por model_name para selector y normalización.
     * Sincroniza el catálogo desde código antes de leer (no depende solo del seeder).
     */
    public static function get_options($model_name)
    {
        self::sync_catalog_options($model_name);

        return PdfColumnOption::where('model_name', $model_name)
            ->where('is_active', true)
            ->orderBy('order')
            ->get();
    }

    /**
     * Opciones base fijas por model_name.
     * `name`: texto en ABM / búsquedas en el front; `label`: encabezado en el PDF.
     */
    public static function default_options($model_name)
    {
        if ($model_name === 'sale') {
            return [
                [
                    'name' => 'Índice de fila',
                    'label'                => '#',
                    'value_resolver'               => 'row_index',
                    'default_width'                => 8,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Número de artículo',
                    'label'                => 'Num',
                    'value_resolver'               => 'item_id',
                    'default_width'                => 15,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Código de barras',
                    'label'                => 'Cod. barras',
                    'value_resolver'               => 'item_bar_code',
                    'default_width'                => 30,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Código de proveedor',
                    'label'                => 'Cod. prov',
                    'value_resolver'               => 'item_provider_code',
                    'default_width'                => 30,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Nombre del artículo',
                    'label'                => 'Nombre',
                    'value_resolver'               => 'item_name',
                    'default_width'                => 72,
                    'allow_wrap_content'               => true
                ],

                [
                    'name'              => 'Cantidad',
                    'label'                => 'Cant',
                    'value_resolver'               => 'item_amount',
                    'default_width'                => 15,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Costo',
                    'label'                => 'Costo',
                    'value_resolver'               => 'item_cost',
                    'default_width'                => 15,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Precio sin IVA',
                    'label'                => 'Pre s/IVA',
                    'value_resolver'               => 'item_price_without_iva',
                    'default_width'                => 20,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Subtotal sin IVA',
                    'label'                => 'SubT s/IVA',
                    'value_resolver'               => 'item_subtotal_without_iva',
                    'default_width'                => 25,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Importe IVA',
                    'label'                => 'Imp IVA',
                    'value_resolver'               => 'item_iva_amount',
                    'default_width'                => 20,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Total con IVA',
                    'label'                => 'Total c/IVA',
                    'value_resolver'               => 'item_subtotal_with_iva',
                    'default_width'                => 20,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Precio unitario',
                    'label'                => 'Precio',
                    'value_resolver'               => 'item_price',
                    'default_width'                => 22,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Descuento porcentaje',
                    'label'                => 'Des',
                    'value_resolver'               => 'item_discount_percentage',
                    'default_width'                => 13,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Total descuento',
                    'label'                => 'T Des',
                    'value_resolver'               => 'item_discount_total',
                    'default_width'                => 15,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Subtotal línea',
                    'label'                => 'Sub total',
                    'value_resolver'               => 'item_subtotal',
                    'default_width'                => 25,
                    'allow_wrap_content'               => false
                ],

                [
                    'name'              => 'Bonificación porcentaje',
                    'label'                => '% Bonif',
                    'value_resolver'               => 'item_discount_percentage',
                    'default_width'                => 15,
                    'allow_wrap_content'               => false
                ],

            ];
        }

        if ($model_name === 'article') {
            return [
                [
                    'name' => 'Índice de fila',
                    'label' => '#',
                    'value_resolver' => 'row_index',
                    'default_width' => 8,
                    'allow_wrap_content' => false,
                ],
                [
                    'name' => 'Imágenes',
                    'label' => 'Imagen',
                    'value_resolver' => 'article_first_image',
                    'default_width' => 40,
                    'allow_wrap_content' => false,
                ],
                [
                    'name' => 'Nombre del artículo',
                    'label' => 'Nombre',
                    'value_resolver' => 'article_name',
                    'default_width' => 120,
                    'allow_wrap_content' => true,
                ],
                [
                    'name' => 'Precio final',
                    'label' => 'Precio',
                    'value_resolver' => 'article_final_price',
                    'default_width' => 40,
                    'allow_wrap_content' => false,
                ],
                [
                    'name' => 'Número de artículo',
                    'label' => 'Num',
                    'value_resolver' => 'article_id',
                    'default_width' => 15,
                    'allow_wrap_content' => false,
                ],
                [
                    'name' => 'Código de barras',
                    'label' => 'Cod. barras',
                    'value_resolver' => 'article_bar_code',
                    'default_width' => 30,
                    'allow_wrap_content' => false,
                ],
                [
                    'name' => 'Código de proveedor',
                    'label' => 'Cod. prov',
                    'value_resolver' => 'article_provider_code',
                    'default_width' => 30,
                    'allow_wrap_content' => false,
                ],
                [
                    'name' => 'Costo',
                    'label' => 'Costo',
                    'value_resolver' => 'article_cost',
                    'default_width' => 22,
                    'allow_wrap_content' => false,
                ],
                [
                    'name' => 'Stock',
                    'label' => 'Stock',
                    'value_resolver' => 'article_stock',
                    'default_width' => 18,
                    'allow_wrap_content' => false,
                ],
                [
                    'name' => 'Categoría',
                    'label' => 'Categoría',
                    'value_resolver' => 'article_category_name',
                    'default_width' => 35,
                    'allow_wrap_content' => false,
                ],
                [
                    'name' => 'Marca',
                    'label' => 'Marca',
                    'value_resolver' => 'article_brand_name',
                    'default_width' => 30,
                    'allow_wrap_content' => false,
                ],
                [
                    'name' => 'Proveedor',
                    'label' => 'Proveedor',
                    'value_resolver' => 'article_provider_name',
                    'default_width' => 35,
                    'allow_wrap_content' => false,
                ],
                [
                    'name' => 'IVA porcentaje',
                    'label' => 'IVA %',
                    'value_resolver' => 'article_iva_percentage',
                    'default_width' => 15,
                    'allow_wrap_content' => false,
                ],
                [
                    'name' => 'Unidad de medida',
                    'label' => 'Unidad',
                    'value_resolver' => 'article_unit_measure',
                    'default_width' => 22,
                    'allow_wrap_content' => false,
                ],
            ];
        }

        return [];
    }

    public static function visible_width($columns)
    {
        $sum = 0;
        foreach ((array) $columns as $column) {
            if (! empty($column['visible'])) {
                $sum += (int) ($column['width'] ?? 0);
            }
        }
        return $sum;
    }

    /**
     * Resuelve perfil PDF para impresión.
     *
     * @param int $user_id
     * @param string $model_name
     * @param int|null $profile_id Id solicitado por query string.
     * @param bool|null $only_afip_ticket_profile true = solo factura ARCA; false = solo remito/venta; null = sin filtro.
     * @return \App\Models\PdfColumnProfile|null
     */
    public static function get_profile_for_print($user_id, $model_name, $profile_id = null, $only_afip_ticket_profile = null)
    {
        $query = PdfColumnProfile::where('user_id', $user_id)
            ->where('model_name', $model_name);

        if ($only_afip_ticket_profile === true) {
            $query->where('is_afip_ticket', true);
        } elseif ($only_afip_ticket_profile === false) {
            $query->where('is_afip_ticket', false);
        }

        if ($profile_id) {
            $profile = (clone $query)->where('id', $profile_id)->first();
            if ($profile) {
                return $profile;
            }
        }

        $default = (clone $query)->where('is_default', true)->first();
        if ($default) {
            return $default;
        }

        return (clone $query)->orderBy('id', 'asc')->first();
    }

    public static function resolve_value($resolver, $context)
    {
        $item = $context['item'] ?? null;
        $article = $context['article'] ?? null;
        $sale = $context['sale'] ?? null;
        $afip_helper = $context['afip_helper'] ?? null;
        $index = $context['index'] ?? null;
        $numbers = $context['numbers'] ?? null;
        $general_helper = $context['general_helper'] ?? null;

        /**
         * Resolvers de listado de artículos (PDF tabla sin pivot de venta).
         */
        if ($article && strpos((string) $resolver, 'article_') === 0) {
            return self::resolve_article_value(
                $resolver,
                $article,
                $index,
                $numbers,
                $general_helper,
                $context['price_type_id'] ?? null,
                $context['user'] ?? null
            );
        }

        if ($article && $resolver === 'row_index') {
            return $index;
        }

        if (! $item) {
            return '';
        }

        /**
         * Contexto de moneda: USD en pivot; conversión a pesos salvo factura de exportación (letra E).
         */
        $moneda_id = $sale ? (int) $sale->moneda_id : null;
        $es_usd = $moneda_id === 2;
        $cbte_letra = isset($context['afip_ticket']) ? (string) $context['afip_ticket']->cbte_letra : null;
        $es_exportacion = $cbte_letra === 'E';
        $valor_dolar = ($sale && $sale->valor_dolar) ? (float) $sale->valor_dolar : 1;

        switch ($resolver) {
            case 'row_index':
                return $index;
            case 'item_id':
                return $item->id;
            case 'item_bar_code':
                return $item->bar_code ?? '';
            case 'item_provider_code':
                return $item->provider_code ?? '';
            case 'item_name':
                if ($general_helper) {
                    return $general_helper::article_name($item);
                }
                return $item->name ?? '';
            case 'item_amount':
                return isset($item->pivot->amount) ? $numbers::price($item->pivot->amount) : '';
            case 'item_cost':
                if (! isset($item->pivot->cost)) {
                    return '';
                }
                return self::format_sale_monetary_value(
                    (float) $item->pivot->cost,
                    $numbers,
                    $es_usd,
                    $es_exportacion,
                    $valor_dolar,
                    $moneda_id,
                    false
                );
            case 'item_discount_percentage':
                return isset($item->pivot->discount) ? $item->pivot->discount : '';
            case 'item_discount_total':
                if (! isset($item->pivot->discount) || ! isset($item->pivot->price) || ! isset($item->pivot->amount) || ! $item->pivot->discount) {
                    return '';
                }
                $descuento_unitario = (float) $item->pivot->price * (float) $item->pivot->discount / 100;
                $descuento_total = $descuento_unitario * (float) $item->pivot->amount;
                return self::format_sale_monetary_value(
                    $descuento_total,
                    $numbers,
                    $es_usd,
                    $es_exportacion,
                    $valor_dolar,
                    $moneda_id
                );
            case 'item_price':
                if (! isset($item->pivot->price)) {
                    return '';
                }
                return self::format_sale_monetary_value(
                    (float) $item->pivot->price,
                    $numbers,
                    $es_usd,
                    $es_exportacion,
                    $valor_dolar,
                    $moneda_id
                );
            case 'item_subtotal':
                if (! isset($item->pivot->price) || ! isset($item->pivot->amount)) {
                    return '';
                }
                $total = (float) $item->pivot->price * (float) $item->pivot->amount;
                if (! is_null($item->pivot->discount ?? null)) {
                    $total -= $total * ((float) $item->pivot->discount / 100);
                }
                return self::format_sale_monetary_value(
                    $total,
                    $numbers,
                    $es_usd,
                    $es_exportacion,
                    $valor_dolar,
                    $moneda_id
                );
            case 'item_price_without_iva':
                /**
                 * Prioriza valor persistido en pivot para evitar recálculo posterior.
                 */
                if (isset($item->pivot->price_sin_iva) && ! is_null($item->pivot->price_sin_iva)) {
                    return self::format_sale_monetary_value(
                        (float) $item->pivot->price_sin_iva,
                        $numbers,
                        $es_usd,
                        $es_exportacion,
                        $valor_dolar,
                        $moneda_id
                    );
                }
                if ($afip_helper && $sale) {
                    return self::format_sale_monetary_value(
                        (float) $afip_helper->getArticlePrice($sale, $item),
                        $numbers,
                        $es_usd,
                        $es_exportacion,
                        $valor_dolar,
                        $moneda_id
                    );
                }
                if (isset($item->pivot->price)) {
                    return self::format_sale_monetary_value(
                        (float) $item->pivot->price,
                        $numbers,
                        $es_usd,
                        $es_exportacion,
                        $valor_dolar,
                        $moneda_id
                    );
                }
                return '';
            case 'item_subtotal_without_iva':
                /**
                 * Si existe snapshot unitario sin IVA en pivot, se usa para subtotal.
                 */
                if (
                    isset($item->pivot->price_sin_iva)
                    && ! is_null($item->pivot->price_sin_iva)
                    && isset($item->pivot->amount)
                ) {
                    $subtotal_sin_iva = (float) $item->pivot->price_sin_iva * (float) $item->pivot->amount;
                    return self::format_sale_monetary_value(
                        $subtotal_sin_iva,
                        $numbers,
                        $es_usd,
                        $es_exportacion,
                        $valor_dolar,
                        $moneda_id
                    );
                }
                if ($afip_helper) {
                    return self::format_sale_monetary_value(
                        (float) $afip_helper->subTotal($item),
                        $numbers,
                        $es_usd,
                        $es_exportacion,
                        $valor_dolar,
                        $moneda_id
                    );
                }
                return '';
            case 'item_iva_amount':
                if ($afip_helper && isset($item->pivot->amount)) {
                    $afip_helper->article = $item;
                    $monto_iva = (float) $afip_helper->montoIvaDelPrecio() * (float) $item->pivot->amount;
                    return self::format_sale_monetary_value(
                        $monto_iva,
                        $numbers,
                        $es_usd,
                        $es_exportacion,
                        $valor_dolar,
                        $moneda_id
                    );
                }
                return '';
            case 'item_subtotal_with_iva':
                if ($afip_helper && isset($item->pivot->amount)) {
                    $afip_helper->article = $item;
                    $total = (float) $afip_helper->getArticlePriceWithDiscounts() * (float) $item->pivot->amount;
                    return self::format_sale_monetary_value(
                        $total,
                        $numbers,
                        $es_usd,
                        $es_exportacion,
                        $valor_dolar,
                        $moneda_id
                    );
                }
                return '';
            default:
                return '';
        }
    }

    /**
     * Formatea un importe monetario de línea de venta según moneda y letra del comprobante AFIP.
     *
     * @param float $valor_original Importe en la moneda del pivot (USD si moneda_id=2).
     * @param mixed $numbers Clase Numbers.
     * @param bool $es_usd Venta en dólares.
     * @param bool $es_exportacion Factura letra E.
     * @param float $valor_dolar Cotización de la venta.
     * @param int|null $moneda_id Moneda de la venta.
     * @param bool $con_signo Antepone símbolo $ cuando no hay moneda_id explícita.
     * @return string
     */
    protected static function format_sale_monetary_value(
        $valor_original,
        $numbers,
        $es_usd,
        $es_exportacion,
        $valor_dolar,
        $moneda_id,
        $con_signo = true
    ) {
        if ($es_usd && ! $es_exportacion) {
            $valor_mostrar = $valor_original * $valor_dolar;
            $moneda_mostrar = null;
        } else {
            $valor_mostrar = $valor_original;
            $moneda_mostrar = $moneda_id;
        }

        return $numbers::price($valor_mostrar, $con_signo, $moneda_mostrar);
    }

    /**
     * Resuelve el valor de una columna para un artículo del listado (sin pivot de venta).
     *
     * @param string $resolver
     * @param \App\Models\Article $article
     * @param int|null $index
     * @param mixed $numbers Clase Numbers
     * @param mixed $general_helper
     * @param string|int|null $price_type_id Lista de precios para pivot de precio final.
     * @param mixed $user Usuario autenticado (owner) para reglas de listas de precio.
     * @return mixed
     */
    protected static function resolve_article_value($resolver, $article, $index, $numbers, $general_helper, $price_type_id = null, $user = null)
    {
        switch ($resolver) {
            case 'article_id':
                return $article->id;
            case 'article_bar_code':
                return $article->bar_code ?? '';
            case 'article_provider_code':
                return $article->provider_code ?? '';
            case 'article_name':
                if ($general_helper) {
                    return $general_helper::article_name($article);
                }
                return $article->name ?? '';
            case 'article_final_price':
                $final_price = self::article_final_price_for_list($article, $price_type_id, $user);

                return '$' . $numbers::price($final_price);
            case 'article_cost':
                if (is_null($article->cost)) {
                    return '';
                }
                return '$' . $numbers::price($article->cost);
            case 'article_stock':
                return $article->stock ?? '';
            case 'article_category_name':
                return $article->category ? $article->category->name : '';
            case 'article_brand_name':
                return $article->brand ? $article->brand->name : '';
            case 'article_provider_name':
                if ($article->provider) {
                    return $article->provider->name;
                }
                return '';
            case 'article_iva_percentage':
                if ($article->iva) {
                    return $article->iva->percentage;
                }
                return '';
            case 'article_unit_measure':
                $unit_label = $article->unidad_medida ? $article->unidad_medida->name : '';
                $measure = $article->medida ?? null;
                if ($unit_label && $measure) {
                    return $unit_label . ' ' . $measure;
                }
                return $unit_label ?: ($measure ?? '');
            case 'article_first_image':
                /**
                 * El valor textual va vacío; la imagen se dibuja en ArticleTablePdf.
                 */
                return '';
            default:
                return '';
        }
    }

    /**
     * Precio final de un artículo en PDF tabla: pivot de price_types si hay lista, si no columna general.
     *
     * @param \App\Models\Article $article
     * @param string|int|null   $price_type_id
     * @param mixed             $user
     * @return float
     */
    protected static function article_final_price_for_list($article, $price_type_id, $user)
    {
        if (UserHelper::uses_listas_de_precio($user)) {
            if (! is_null($price_type_id) && $price_type_id !== '') {
                $price_type = $article->relationLoaded('price_types')
                    ? $article->price_types->firstWhere('id', (int) $price_type_id)
                    : null;

                if (is_null($price_type)) {
                    $price_type = $article->price_types()
                        ->where('price_type_id', $price_type_id)
                        ->first();
                }

                if (! is_null($price_type) && isset($price_type->pivot->final_price)) {
                    return (float) $price_type->pivot->final_price;
                }
            }
        }

        return (float) $article->final_price;
    }

    /**
     * Indica si la columna del perfil corresponde a la primera imagen del artículo.
     *
     * @param string $resolver
     * @return bool
     */
    public static function is_article_image_column($resolver)
    {
        return $resolver === 'article_first_image';
    }

    /**
     * Ruta o URL usable por FPDF para la primera imagen del artículo (null si no hay).
     *
     * @param \App\Models\Article $article
     * @return string|null
     */
    public static function article_first_image_path($article)
    {
        if (! $article) {
            return null;
        }

        if (! $article->relationLoaded('images')) {
            $article->load(['images' => function ($query) {
                $query->orderBy('id', 'asc');
            }]);
        }

        $images = $article->images;
        if (! $images || $images->count() < 1) {
            return null;
        }

        $first_image = $images->first();
        $url_prop = env('IMAGE_URL_PROP_NAME', 'image_url');
        $img_url = $first_image->{$url_prop} ?? null;

        if (empty($img_url) && isset($first_image->hosting_url)) {
            $img_url = $first_image->hosting_url;
        }
        if (empty($img_url) && isset($first_image->image_url)) {
            $img_url = $first_image->image_url;
        }

        if (config('app.APP_ENV') == 'local' && empty($img_url)) {
            $img_url = 'https://api-colman-prueba.comerciocity.com/public/storage/171699179550596.webp';
        }

        if (empty($img_url)) {
            return null;
        }

        $general_helper = \App\Http\Controllers\Helpers\GeneralHelper::class;

        return $general_helper::pdf_image_path($img_url);
    }
}

