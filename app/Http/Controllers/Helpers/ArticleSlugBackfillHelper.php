<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lógica del backfill de slugs de artículos (misión tienda-articulos-sin-slug, 26/11/2026).
 *
 * La tienda arma la URL de la ficha con `articles.slug`; un artículo con slug NULL o '' no se puede
 * abrir ("No pudimos cargar este producto"). Este helper completa los slugs que faltan con la MISMA
 * regla que el ABM (`ArticleHelper::slug`: único por dueño, sufijo `-1`, `-2`...). Lo usa el comando
 * `articles:completar-slugs`, que es delgado: valida opciones, imprime y delega acá.
 *
 * Reglas de seguridad del backfill:
 *   - sólo recorre artículos NO borrados (el modelo usa SoftDeletes);
 *   - la escritura es directa a la tabla (`DB::table`): no toca `updated_at` (el sync de los clientes
 *     no ve un cambio de contenido) y no dispara observers (embeddings, etc.);
 *   - la escritura re-condiciona "slug vacío", así no pisa un slug que otro proceso puso en el medio;
 *   - un nombre del que no sale ningún slug (vacío o sólo símbolos) se saltea y se cuenta aparte.
 *
 * IMPORTANTE: PHP 7.4 estricto, sin sintaxis de PHP 8 (ver .mision/RESTRICCIONES.md).
 */
class ArticleSlugBackfillHelper
{
    /**
     * Cantidad de dueños que se cargan por tanda al recorrer todos los dueños.
     *
     * @var int
     */
    const TAMANO_TANDA_DUENOS = 200;

    /**
     * Cantidad de artículos que se cargan por tanda dentro de un dueño.
     *
     * @var int
     */
    const TAMANO_TANDA_ARTICULOS = 500;

    /**
     * Cuántos ejemplos se devuelven por dueño para mostrar en el reporte.
     *
     * @var int
     */
    const CANTIDAD_EJEMPLOS = 5;

    /**
     * Procesa todos los dueños (SÓLO owners: `owner_id IS NULL`) o uno solo si se indica `$user_id`.
     *
     * @param bool $aplicar true escribe los slugs; false sólo cuenta (modo reporte).
     * @param int|null $user_id Id de un dueño para limitar el barrido; null = todos los dueños.
     * @return array [
     *     'pendientes'  => int  artículos a los que se les puede calcular slug (en modo aplicar, los que se intentaron),
     *     'completados' => int  artículos a los que se les escribió slug (siempre 0 en modo reporte),
     *     'sin_nombre'  => int  artículos sin slug posible (nombre vacío o sólo símbolos),
     *     'ejemplos'    => array lista de ['id' => int, 'name' => string, 'slug' => string] (hasta 5 en total),
     * ]
     */
    public static function procesar($aplicar, $user_id = null)
    {
        // Acumulado de todos los dueños recorridos.
        $resultado = [
            'pendientes'  => 0,
            'completados' => 0,
            'sin_nombre'  => 0,
            'ejemplos'    => [],
        ];

        // Dueños a recorrer: siempre y sólo owners (los empleados comparten los artículos del dueño).
        $duenos = User::query()->whereNull('owner_id');

        if (!is_null($user_id)) {
            $duenos->where('id', (int) $user_id);
        }

        $duenos->chunkById(self::TAMANO_TANDA_DUENOS, function ($tanda) use (&$resultado, $aplicar) {
            foreach ($tanda as $dueno) {
                $parcial = self::procesar_dueno((int) $dueno->id, $aplicar, self::CANTIDAD_EJEMPLOS - count($resultado['ejemplos']));

                $resultado['pendientes']  += $parcial['pendientes'];
                $resultado['completados'] += $parcial['completados'];
                $resultado['sin_nombre']  += $parcial['sin_nombre'];
                $resultado['ejemplos']     = array_merge($resultado['ejemplos'], $parcial['ejemplos']);
            }
        });

        return $resultado;
    }

    /**
     * Completa (o sólo cuenta) los slugs vacíos de los artículos de UN dueño.
     *
     * @param int $owner_id Id del dueño (el slug es único por dueño).
     * @param bool $aplicar true escribe; false sólo cuenta.
     * @param int $cupo_ejemplos Cuántos ejemplos como máximo puede devolver esta llamada.
     * @return array Mismas claves que `procesar()`.
     */
    public static function procesar_dueno($owner_id, $aplicar, $cupo_ejemplos = 0)
    {
        // Contadores y ejemplos de este dueño.
        $resultado = [
            'pendientes'  => 0,
            'completados' => 0,
            'sin_nombre'  => 0,
            'ejemplos'    => [],
        ];

        // Artículos del dueño sin slug (NULL o ''). El modelo excluye los borrados (SoftDeletes).
        $consulta = Article::query()
                            ->where('user_id', $owner_id)
                            ->where(function ($query) {
                                $query->whereNull('slug')->orWhere('slug', '');
                            })
                            ->orderBy('id');

        // chunkById pagina por id (id > último): escribir el slug de una fila no desplaza las siguientes.
        $consulta->chunkById(self::TAMANO_TANDA_ARTICULOS, function ($articulos) use (&$resultado, $aplicar, $owner_id, $cupo_ejemplos) {
            foreach ($articulos as $articulo) {
                // Sin ningún carácter aprovechable no hay slug posible: la tienda lo abre por `~id`.
                if (Str::slug((string) $articulo->name) === '') {
                    $resultado['sin_nombre']++;
                    continue;
                }

                $resultado['pendientes']++;

                // Misma regla que el ABM, contra la base: cada slug escrito cuenta para el siguiente.
                $slug = ArticleHelper::slug($articulo->name, $articulo->id, $owner_id);

                if (count($resultado['ejemplos']) < $cupo_ejemplos) {
                    $resultado['ejemplos'][] = [
                        'id'   => (int) $articulo->id,
                        'name' => (string) $articulo->name,
                        'slug' => $slug,
                    ];
                }

                if ($aplicar && self::escribir_slug((int) $articulo->id, $slug)) {
                    $resultado['completados']++;
                }
            }
        });

        return $resultado;
    }

    /**
     * Escribe el slug directo en la tabla, sólo si el artículo sigue sin slug.
     *
     * No pasa por Eloquent a propósito: no actualiza `updated_at` ni dispara observers.
     *
     * @param int $article_id
     * @param string $slug
     * @return bool true si se escribió; false si en el medio otro proceso ya le puso un slug.
     */
    protected static function escribir_slug($article_id, $slug)
    {
        $filas = DB::table('articles')
                    ->where('id', $article_id)
                    ->where(function ($query) {
                        $query->whereNull('slug')->orWhere('slug', '');
                    })
                    ->update(['slug' => $slug]);

        return $filas > 0;
    }
}
