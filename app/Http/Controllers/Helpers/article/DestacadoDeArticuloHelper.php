<?php

namespace App\Http\Controllers\Helpers\article;

use App\Models\Article;

/**
 * Lógica de «destacar» un artículo en la tienda (columna `articles.featured`).
 *
 * Misión asistente-diario-20261121 (M1): esta cuenta vivía adentro de `ArticleController::setFeatured()`
 * (el botón de la estrella del Listado). Se extrajo acá para que la usen las DOS puertas —la pantalla y la
 * herramienta del asistente `proponer_destacado_articulo`— y no se puedan desalinear.
 *
 * `featured` es un entero que hace de orden (1 = primero) o null cuando el artículo no está destacado.
 * Destacar lo pone al final de la lista: «cantidad de destacados del dueño + 1». Se conserva EXACTAMENTE esa
 * cuenta (aunque no compacte los huecos que deja quitar un destacado del medio) para no cambiar lo que ya
 * hace la pantalla.
 *
 * PHP 7.4: sin match, sin propiedades tipadas, sin str_contains.
 */
class DestacadoDeArticuloHelper
{
    /**
     * true si el artículo está destacado (tiene un orden cargado).
     *
     * @param  \App\Models\Article  $article
     * @return bool
     */
    public static function esta_destacado(Article $article): bool
    {
        return !is_null($article->featured);
    }

    /**
     * El orden que le toca a un artículo que se destaca ahora: al final de los destacados del dueño.
     *
     * @param  int  $user_id  El dueño de los artículos.
     * @return int
     */
    public static function siguiente_orden($user_id): int
    {
        return Article::where('user_id', $user_id)->whereNotNull('featured')->count() + 1;
    }

    /**
     * Deja el artículo destacado o no destacado y lo guarda. Si ya estaba en el estado pedido no hace nada.
     *
     * @param  \App\Models\Article  $article
     * @param  bool  $destacado  true para destacarlo, false para quitarle el destacado.
     * @param  int  $user_id  El dueño (para calcular el orden al destacar).
     * @return bool  true si cambió algo, false si ya estaba así.
     */
    public static function fijar(Article $article, bool $destacado, $user_id): bool
    {
        if (self::esta_destacado($article) === $destacado) {

            return false;
        }

        $article->featured = $destacado ? self::siguiente_orden($user_id) : null;

        $article->save();

        return true;
    }

    /**
     * Invierte el estado (lo que hace el botón de la estrella): destacado → no destacado y al revés.
     *
     * @param  \App\Models\Article  $article
     * @param  int  $user_id  El dueño.
     * @return void
     */
    public static function alternar(Article $article, $user_id): void
    {
        self::fijar($article, !self::esta_destacado($article), $user_id);
    }
}
