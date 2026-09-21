<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Address;
use App\Models\Article;

/**
 * La ficha de un artículo para la tarjeta que abre el hover sobre una mención del chat (misión
 * agente-ia-mano-derecha, §2 del contrato, 16/9/2026).
 *
 * 🔴 TODO EN UN REQUEST, A PROPÓSITO. La tarjeta aparece al pasar el mouse y tiene que estar
 * dibujada antes de que la persona termine de leer el renglón: nombre, foto, código, precio,
 * proveedor, stock total, stock por depósito y listas de precios. Encadenar dos o tres pedidos para
 * eso sería mirar una tarjeta a medio llenar cada vez.
 *
 * 🔴 Y NO ES UNA PANTALLA NUEVA: ES LA MISMA DATA QUE YA MUESTRA EL LISTADO, con los mismos gates.
 * Por eso el recorte por `article.stock_only_sucursal` se hace ACÁ y no del lado de la SPA —que
 * dibuja lo que llega y nada más—: si el API no filtra, filtra data. Es la misma regla de
 * PermisosIaHelper, espejo literal de la pantalla.
 */
class FichaArticuloIaHelper
{
    /**
     * El permiso que deja a un empleado ver SOLO el stock de su propia sucursal.
     *
     * Espeja `puede_ver_address()` de empresa-spa
     * (src/common-vue/helpers/article_dynamic_table_columns.js:98-103), que es el mismo criterio
     * que usa el buscador de artículos (common/buscador-articulos/Index.vue:187-195) y el select de
     * sucursales del listado. Si la SPA cambia su criterio, este archivo cambia en el mismo diff.
     *
     * @var string
     */
    const SOLO_MI_SUCURSAL = 'article.stock_only_sucursal';

    /**
     * La ficha, o null si el artículo no existe o no es del dueño (el controller contesta 404).
     *
     * @param  int  $article_id
     * @param  int  $owner_id  Dueño de la cuenta (articles.user_id).
     * @param  \App\Models\User|null  $persona  Quien está mirando: puede ser un empleado con menos
     *                                          permisos que el dueño.
     * @return array<string, mixed>|null
     */
    public static function ficha($article_id, $owner_id, $persona)
    {
        /*
         * sinEmbedding(): la columna `embedding` son 1536 floats (~29 KB por fila) que además
         * $hidden no ahorra —se leen igual de la base y se hidratan en memoria—. Una tarjeta de
         * hover no puede pagar eso.
         */
        $article = Article::sinEmbedding()
            ->where('user_id', (int) $owner_id)
            ->where('id', (int) $article_id)
            ->with(['images', 'provider', 'price_types', 'addresses'])
            ->first();

        if (is_null($article)) {

            return null;
        }

        $tiene_depositos = self::tiene_depositos((int) $owner_id);

        return [
            'id'                => (int) $article->id,
            'nombre'            => (string) $article->name,
            // primera_imagen_publica() y no getFirstImage(): la vieja devuelve una URL rota en
            // produccion (ver su docblock y el de ApiUrlHelper::url_publica_de_imagen()), y esta
            // tarjeta de hover la pinta un <img> de la SPA.
            'imagen_url'        => ArticleHelper::primera_imagen_publica($article),
            'codigo'            => self::codigo($article),
            'precio'            => self::precio($article),
            'proveedor'         => is_null($article->provider) ? null : (string) $article->provider->name,
            'stock_total'       => is_null($article->stock) ? 0.0 : (float) $article->stock,
            'tiene_depositos'   => $tiene_depositos,
            // Sin depósitos la sección no se dibuja: repetir el stock total abajo del stock total
            // sería el mismo número dos veces.
            'depositos'         => $tiene_depositos ? self::depositos($article, $persona) : [],
            'listas_de_precios' => self::listas_de_precios($article),
        ];
    }

    /**
     * true si el comercio trabaja con depósitos.
     *
     * 🔴 NO HAY EXTENSIÓN DE DEPÓSITOS: el criterio es contar filas de `addresses`, exactamente
     * como lo mide RecolectorStock::recolectar() (:52-56) antes de sugerir un traslado. Con una
     * sola sucursal no hay reparto que mostrar.
     *
     * @param  int  $owner_id
     * @return bool
     */
    public static function tiene_depositos($owner_id): bool
    {
        return Address::where('user_id', (int) $owner_id)->count() >= 2;
    }

    /**
     * El stock por depósito, del pivot `address_article`.
     *
     * 🔴 Acá vive el recorte por permiso: un empleado con `article.stock_only_sucursal` que no sea
     * admin ve SOLO la suya. Sin `address_id` asignado no ve ninguna, que es lo mismo que le pasa
     * en la pantalla (`!!(user && user.address_id == address.id)` con address_id nulo da false).
     *
     * @param  Article  $article
     * @param  \App\Models\User|null  $persona
     * @return array<int, array<string, mixed>>
     */
    protected static function depositos($article, $persona): array
    {
        $solo_la_suya = !PermisosIaHelper::es_admin($persona) && PermisosIaHelper::puede($persona, self::SOLO_MI_SUCURSAL);

        $mi_address_id = (!is_null($persona) && !is_null($persona->address_id)) ? (int) $persona->address_id : null;

        $depositos = [];

        foreach ($article->addresses as $address) {
            if ($solo_la_suya && (is_null($mi_address_id) || $mi_address_id !== (int) $address->id)) {
                continue;
            }

            $depositos[] = [
                'nombre' => (string) $address->street,
                'stock'  => is_null($address->pivot->amount) ? 0.0 : (float) $address->pivot->amount,
            ];
        }

        return $depositos;
    }

    /**
     * Las listas de precios donde el artículo tiene un precio cargado, del pivot
     * `article_price_type` con `pivot.final_price`.
     *
     * Las listas sin precio final quedan afuera: "Lista mayorista $0" no es un dato, es un renglón
     * que confunde.
     *
     * @param  Article  $article
     * @return array<int, array<string, mixed>>
     */
    protected static function listas_de_precios($article): array
    {
        $listas = [];

        foreach ($article->price_types as $price_type) {
            if (is_null($price_type->pivot->final_price)) {
                continue;
            }

            $listas[] = [
                'nombre' => (string) $price_type->name,
                'precio' => (float) $price_type->pivot->final_price,
            ];
        }

        return $listas;
    }

    /**
     * El código que identifica al artículo: el de barras y, si no tiene, el del proveedor. null si
     * no tiene ninguno (la tarjeta simplemente no dibuja el renglón).
     *
     * @param  Article  $article
     * @return string|null
     */
    protected static function codigo($article)
    {
        $bar_code = trim((string) $article->bar_code);

        if ($bar_code !== '') {

            return $bar_code;
        }

        $provider_code = trim((string) $article->provider_code);

        return $provider_code === '' ? null : $provider_code;
    }

    /**
     * El precio de venta actual: el final y, si no está cargado, el de lista. Es el mismo criterio
     * que ConsultasSistemaIaHelper::stock_de_articulos(), que es de donde salió el id de la
     * mención — la tarjeta no puede decir un precio distinto del que el asistente acaba de nombrar.
     *
     * @param  Article  $article
     * @return float
     */
    protected static function precio($article): float
    {
        if (!is_null($article->final_price)) {

            return (float) $article->final_price;
        }

        return is_null($article->price) ? 0.0 : (float) $article->price;
    }
}
