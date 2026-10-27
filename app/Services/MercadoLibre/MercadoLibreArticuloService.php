<?php

namespace App\Services\MercadoLibre;

use App\Models\Article;
use App\Models\MeliAttribute;
use App\Models\SyncToMeliArticle;

/**
 * Lo que el módulo de Mercado Libre lee de un artículo del ERP, en UN lugar (misión
 * mercado-libre-integracion, 27/10/2026): el precio para ML, las fotos publicables, los atributos
 * que se completan solos, la descripción, lo que le falta para poder publicarse y el estado que
 * ve el formulario.
 *
 * Todo estático y sin llamar a ML: lo usan la sincronización (`ProductService`), el estado del
 * formulario (`GET mercado-libre/articulo/{id}`) y el resolve de la categoría, con o sin cuenta
 * conectada.
 */
class MercadoLibreArticuloService
{
    /** Precio mínimo de una publicación en Mercado Libre Argentina ($3.500 en consumo masivo). */
    const PRECIO_MINIMO = 1000;

    /**
     * Con qué dato del artículo se completa solo un atributo de ML, si el usuario no lo cargó.
     *
     * @param string|null $meli_id Id del atributo en ML (`BRAND`, `GTIN`, `SELLER_SKU`, ...).
     * @return string|null `brand`, `bar_code`, `sku` o null.
     */
    public static function auto_de_atributo($meli_id)
    {
        $mapa = [
            'BRAND'      => 'brand',
            'GTIN'       => 'bar_code',
            'SELLER_SKU' => 'sku',
        ];

        $meli_id = (string) $meli_id;

        return isset($mapa[$meli_id]) ? $mapa[$meli_id] : null;
    }

    /**
     * Valor automático de un atributo para este artículo, o null si el artículo no tiene el dato.
     *
     * - `BRAND`: el nombre de la marca del artículo.
     * - `GTIN`: el código de barras, solo si son 8 a 14 dígitos (un código interno no es un GTIN y
     *   ML modera la publicación si se lo mandan como tal).
     * - `SELLER_SKU`: el SKU o, si no tiene, el código de proveedor.
     *
     * @param Article $article
     * @param string $meli_id
     * @return string|null
     */
    public static function valor_automatico(Article $article, $meli_id)
    {
        $auto = self::auto_de_atributo($meli_id);

        if ($auto === 'brand') {
            $brand = $article->brand;

            return ($brand && trim((string) $brand->name) !== '') ? trim((string) $brand->name) : null;
        }

        if ($auto === 'bar_code') {
            $bar_code = trim((string) $article->bar_code);

            return (ctype_digit($bar_code) && strlen($bar_code) >= 8 && strlen($bar_code) <= 14) ? $bar_code : null;
        }

        if ($auto === 'sku') {
            $sku = trim((string) $article->sku);
            if ($sku !== '') {
                return $sku;
            }

            $provider_code = trim((string) $article->provider_code);

            return $provider_code !== '' ? $provider_code : null;
        }

        return null;
    }

    /**
     * Precio para ML: el de la lista marcada "se usa en Mercado Libre" (`se_usa_en_ml`) y, si el
     * comercio no marcó ninguna (o el artículo no está en ella), el precio final del artículo,
     * igual que hace Tienda Nube. null si no hay ningún precio.
     *
     * @param Article $article
     * @return float|null
     */
    public static function precio_para_meli(Article $article)
    {
        $price_type = $article->price_types()->where('se_usa_en_ml', 1)->first();

        if ($price_type && isset($price_type->pivot->final_price) && (float) $price_type->pivot->final_price > 0) {
            return (float) $price_type->pivot->final_price;
        }

        if (!is_null($article->final_price) && (float) $article->final_price > 0) {
            return (float) $article->final_price;
        }

        return null;
    }

    /**
     * URLs de las fotos que ML puede bajar: solo `hosting_url` con http/https (una ruta local o un
     * `data:` no le sirve y ML rechaza el ítem entero).
     *
     * @param Article $article
     * @return array<int, string>
     */
    public static function fotos_publicables(Article $article)
    {
        $urls = [];

        foreach ($article->images as $image) {
            $url = trim((string) $image->hosting_url);

            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Texto de la descripción para ML: la descripción específica de Mercado Libre del artículo
     * (`meli_descripcion`) y, si está vacía, las descripciones generales del artículo en texto
     * plano. null si no hay ninguna.
     *
     * @param Article $article
     * @return string|null
     */
    public static function texto_de_descripcion(Article $article)
    {
        $texto = trim((string) $article->meli_descripcion);

        if ($texto !== '') {
            return $texto;
        }

        $partes = [];
        foreach ($article->descriptions as $description) {
            $titulo = trim(strip_tags((string) $description->title));
            $contenido = trim(html_entity_decode(strip_tags((string) $description->content), ENT_QUOTES, 'UTF-8'));

            if ($titulo !== '' && $contenido !== '') {
                $partes[] = $titulo."\n".$contenido;
            } elseif ($contenido !== '') {
                $partes[] = $contenido;
            }
        }

        $texto = trim(implode("\n\n", $partes));

        return $texto !== '' ? $texto : null;
    }

    /**
     * Lo que le falta al artículo para poder publicarse, en el idioma del comercio.
     *
     * Cuenta: "Disponible en Mercado Libre" sin marcar, la categoría, las fotos, el precio mínimo,
     * el stock para la primera publicación y los atributos obligatorios de la categoría sin valor
     * (contando los que se completan solos con la marca, el código de barras o el SKU).
     *
     * @param Article $article
     * @return array<int, string>
     */
    public static function faltantes(Article $article)
    {
        $faltantes = [];

        // Una publicación cerrada no se puede reactivar: la sincronización automática ya no la
        // toca y "Sincronizar ahora" la publica de nuevo (revisión de la misión, 27/10/2026).
        if ((string) $article->meli_status === 'closed') {
            $faltantes[] = 'La publicación está finalizada en Mercado Libre: «Sincronizar ahora» la publica de nuevo.';
        }

        if (!$article->mercado_libre) {
            $faltantes[] = 'Marcar «Disponible en Mercado Libre».';
        }

        $categoria = $article->meli_category;

        if (!$categoria) {
            $faltantes[] = 'Elegir la categoría de Mercado Libre.';
        }

        if (count(self::fotos_publicables($article)) === 0) {
            $faltantes[] = 'Cargar al menos una foto.';
        }

        $precio = self::precio_para_meli($article);
        if (is_null($precio)) {
            $faltantes[] = 'Cargar el precio del artículo.';
        } elseif ($precio < self::PRECIO_MINIMO) {
            $faltantes[] = 'El precio para Mercado Libre ($'.self::formatear_precio($precio).') está debajo del mínimo de $'.self::formatear_precio(self::PRECIO_MINIMO).' que exige Mercado Libre.';
        }

        if (empty($article->me_li_id) && (is_null($article->stock) || (float) $article->stock <= 0)) {
            $faltantes[] = 'Cargar stock: Mercado Libre no deja publicar sin unidades disponibles.';
        }

        if ($categoria) {
            foreach (self::atributos_obligatorios_sin_valor($article, (int) $categoria->id) as $nombre) {
                $faltantes[] = 'Completar el atributo obligatorio «'.$nombre.'».';
            }
        }

        return $faltantes;
    }

    /**
     * Nombres de los atributos obligatorios de la categoría que el artículo no tiene ni puede
     * completar solo.
     *
     * @param Article $article
     * @param int $meli_category_local_id `meli_categories.id`.
     * @return array<int, string>
     */
    public static function atributos_obligatorios_sin_valor(Article $article, $meli_category_local_id)
    {
        $cargados = [];
        foreach ($article->meli_attributes as $meli_attribute) {
            $value_id = trim((string) $meli_attribute->pivot->value_id);
            $value_name = trim((string) $meli_attribute->pivot->value_name);

            if ($value_id !== '' || $value_name !== '') {
                $cargados[(int) $meli_attribute->pivot->meli_attribute_id] = true;
            }
        }

        $atributos = MeliAttribute::where('meli_category_id', $meli_category_local_id)
            ->with('meli_attributes_tags')
            ->orderBy('id')
            ->get();

        $sin_valor = [];

        foreach ($atributos as $atributo) {
            $tags = CategoryService::tags_activos($atributo);

            if (!CategoryService::es_requerido($tags) || !CategoryService::va_en_el_formulario($atributo->meli_id, $tags)) {
                continue;
            }

            if (isset($cargados[(int) $atributo->id])) {
                continue;
            }

            if (!is_null(self::valor_automatico($article, $atributo->meli_id))) {
                continue;
            }

            $sin_valor[] = (string) ($atributo->name ?: $atributo->meli_id);
        }

        return $sin_valor;
    }

    /**
     * Estado de la publicación para el formulario del artículo.
     *
     * Forma: `{me_li_id, permalink, meli_status, ultima_sync: {status, error_message, synced_at,
     * attempted_at}|null, faltantes: [texto...]}`.
     *
     * @param Article $article
     * @return array<string, mixed>
     */
    public static function estado(Article $article)
    {
        $article->loadMissing(['meli_category', 'images', 'meli_attributes', 'brand']);

        $ultima = SyncToMeliArticle::where('article_id', $article->id)
            ->where('user_id', $article->user_id)
            ->orderBy('id', 'DESC')
            ->first();

        return [
            'me_li_id'    => $article->me_li_id,
            'permalink'   => $article->meli_permalink,
            'meli_status' => $article->meli_status,
            'ultima_sync' => $ultima ? [
                'status'        => $ultima->status,
                'error_message' => $ultima->error_message,
                'synced_at'     => $ultima->synced_at,
                'attempted_at'  => $ultima->attempted_at,
            ] : null,
            'faltantes'   => self::faltantes($article),
        ];
    }

    /**
     * Precio con separador de miles argentino y sin decimales si es entero (`1.000`, `850,50`).
     *
     * @param float $precio
     * @return string
     */
    public static function formatear_precio($precio)
    {
        $precio = (float) $precio;
        $decimales = (abs($precio - round($precio)) < 0.005) ? 0 : 2;

        return number_format($precio, $decimales, ',', '.');
    }
}
