<?php

namespace App\Http\Controllers\Helpers;

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
 * Velocidad (misión completar-slugs-rapido, 27/11/2026): antes se hacía un SELECT por cada candidato
 * (`ArticleHelper::slug`, con nombres repetidos miles de veces es O(n²)) y un UPDATE por artículo
 * (~1 artículo/seg en Servian). Ahora, por dueño, se cargan UNA vez los slugs tomados en un conjunto en
 * memoria, se calcula cada slug nuevo contra ese conjunto con la MISMA regla (base, base-1, base-2...,
 * cada candidato desde la base) y se escribe por tandas con un solo UPDATE ... CASE. El resultado es
 * idéntico al secuencial. Límites conocidos (aceptados): si otro proceso CREA artículos del dueño
 * mientras corre el barrido, el conjunto no los ve (no hay índice único: el ABM sigue su camino y el
 * backfill se corre con el sistema en mantenimiento); y si una escritura es rechazada porque otro
 * proceso ya puso slug, el candidato queda "gastado" en el conjunto (deja un hueco, nunca un duplicado).
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
    const TAMANO_TANDA_ARTICULOS = 1000;

    /**
     * Cantidad de slugs que se leen por consulta al cargar los slugs ya tomados de un dueño.
     *
     * @var int
     */
    const TAMANO_TANDA_SLUGS_TOMADOS = 20000;

    /**
     * Memoria mínima que se pide para sostener el conjunto de slugs tomados de un dueño grande
     * (cientos de miles de artículos). Sólo se sube el límite si el actual es menor y no es ilimitado.
     *
     * @var string
     */
    const MEMORIA_MINIMA = '1024M';

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
     * Calcula en memoria contra el conjunto de slugs ya tomados del dueño y escribe por tandas.
     * En modo reporte (`$aplicar` false) no escribe ni "gasta" slugs: igual que el secuencial de antes,
     * los ejemplos muestran el slug que le tocaría a cada artículo si fuera el único pendiente.
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

        self::asegurar_memoria();

        // Conjunto de slugs tomados por artículos vivos del dueño (clave normalizada => true).
        $tomados = self::cargar_slugs_tomados($owner_id);

        // Por cada base de slug, desde qué sufijo conviene seguir probando: todos los anteriores ya están tomados.
        $proximo_sufijo = [];

        // Artículos vivos del dueño sin slug (NULL o ''). `deleted_at IS NULL` equivale al SoftDeletes del modelo.
        $consulta = DB::table('articles')
                        ->select('id', 'name')
                        ->where('user_id', $owner_id)
                        ->whereNull('deleted_at')
                        ->where(function ($query) {
                            $query->whereNull('slug')->orWhere('slug', '');
                        });

        // chunkById pagina por id (id > último): escribir el slug de una fila no desplaza las siguientes.
        $consulta->chunkById(self::TAMANO_TANDA_ARTICULOS, function ($articulos) use (&$resultado, &$tomados, &$proximo_sufijo, $aplicar, $cupo_ejemplos) {
            // Pares id => slug a escribir en esta tanda.
            $a_escribir = [];

            foreach ($articulos as $articulo) {
                // Base del slug: la misma función que usa el ABM.
                $base = Str::slug((string) $articulo->name);

                // Sin ningún carácter aprovechable no hay slug posible: la tienda lo abre por `~id`.
                if ($base === '') {
                    $resultado['sin_nombre']++;
                    continue;
                }

                $resultado['pendientes']++;

                // En reporte sólo hace falta calcular el slug de los que van de ejemplo.
                $necesita_ejemplo = count($resultado['ejemplos']) < $cupo_ejemplos;

                if (!$aplicar && !$necesita_ejemplo) {
                    continue;
                }

                $slug = self::calcular_slug($base, $tomados, $proximo_sufijo, $aplicar);

                if ($necesita_ejemplo) {
                    $resultado['ejemplos'][] = [
                        'id'   => (int) $articulo->id,
                        'name' => (string) $articulo->name,
                        'slug' => $slug,
                    ];
                }

                if ($aplicar) {
                    $a_escribir[(int) $articulo->id] = $slug;
                }
            }

            if ($aplicar && !empty($a_escribir)) {
                $resultado['completados'] += self::escribir_slugs($a_escribir);
            }
        });

        return $resultado;
    }

    /**
     * Normaliza un slug para compararlo como lo hace la base (collation `_ci`: sin distinguir
     * mayúsculas y sin espacios finales). Los slugs generados ya son minúsculas sin espacios; esto
     * cubre slugs viejos cargados a mano.
     *
     * @param string $slug
     * @return string
     */
    protected static function clave_de_slug($slug)
    {
        return mb_strtolower(rtrim((string) $slug), 'UTF-8');
    }

    /**
     * Carga los slugs ya tomados por los artículos vivos de un dueño (slug no vacío, no borrado),
     * leyendo por id en tandas y sólo las columnas necesarias.
     *
     * @param int $owner_id
     * @return array Mapa clave_normalizada => true.
     */
    protected static function cargar_slugs_tomados($owner_id)
    {
        // Conjunto resultante.
        $tomados = [];

        // Último id leído (paginación por id: estable aunque la tabla sea enorme).
        $ultimo_id = 0;

        do {
            $filas = DB::table('articles')
                        ->select('id', 'slug')
                        ->where('user_id', $owner_id)
                        ->whereNull('deleted_at')
                        ->whereNotNull('slug')
                        ->where('slug', '!=', '')
                        ->where('id', '>', $ultimo_id)
                        ->orderBy('id')
                        ->limit(self::TAMANO_TANDA_SLUGS_TOMADOS)
                        ->get();

            foreach ($filas as $fila) {
                $tomados[self::clave_de_slug($fila->slug)] = true;
                $ultimo_id = (int) $fila->id;
            }
        } while (count($filas) === self::TAMANO_TANDA_SLUGS_TOMADOS);

        return $tomados;
    }

    /**
     * Calcula el slug libre para una base con la misma regla que `ArticleHelper::slug`: la base si está
     * libre; si no, base-1, base-2... (cada candidato se arma desde la base). Para no recorrer desde
     * `-1` cada vez con nombres muy repetidos, se recuerda desde qué sufijo seguir: los anteriores ya
     * están tomados y el conjunto sólo crece, así que el resultado es el mismo que el secuencial.
     *
     * @param string $base Slug base (no vacío).
     * @param array $tomados Conjunto de slugs tomados (por referencia; se le suma el elegido si `$reservar`).
     * @param array $proximo_sufijo Mapa base => próximo sufijo a probar (por referencia).
     * @param bool $reservar true marca el slug elegido como tomado; false sólo lo calcula (modo reporte).
     * @return string
     */
    protected static function calcular_slug($base, array &$tomados, array &$proximo_sufijo, $reservar)
    {
        $clave_base = self::clave_de_slug($base);
        $slug = $base;

        if (isset($tomados[$clave_base])) {
            // En reporte no se "gastan" slugs: se arranca siempre desde -1, como el secuencial sin escribir.
            $sufijo = ($reservar && isset($proximo_sufijo[$clave_base])) ? $proximo_sufijo[$clave_base] : 1;

            while (isset($tomados[self::clave_de_slug($base . '-' . $sufijo)])) {
                $sufijo++;
            }

            $slug = $base . '-' . $sufijo;

            if ($reservar) {
                $proximo_sufijo[$clave_base] = $sufijo + 1;
            }
        }

        if ($reservar) {
            $tomados[self::clave_de_slug($slug)] = true;
        }

        return $slug;
    }

    /**
     * Escribe una tanda de slugs con un solo UPDATE ... CASE dentro de una transacción.
     *
     * No pasa por Eloquent a propósito: no actualiza `updated_at` ni dispara observers. Re-condiciona
     * "slug vacío", así no pisa un slug que otro proceso puso en el medio.
     *
     * @param array $slugs_por_id Mapa article_id => slug.
     * @return int Cantidad de filas realmente actualizadas.
     */
    protected static function escribir_slugs(array $slugs_por_id)
    {
        // Fragmentos del CASE y sus valores, en el orden de los `?` del SQL.
        $casos = '';
        $valores_caso = [];
        $ids = [];

        foreach ($slugs_por_id as $article_id => $slug) {
            $casos .= ' WHEN ? THEN ?';
            $valores_caso[] = $article_id;
            $valores_caso[] = $slug;
            $ids[] = $article_id;
        }

        // Un `?` por id para el IN.
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        $sql = 'UPDATE articles SET slug = CASE id' . $casos . ' END'
             . ' WHERE id IN (' . $marcas . ") AND (slug IS NULL OR slug = '')";

        $filas = 0;

        DB::transaction(function () use ($sql, $valores_caso, $ids, &$filas) {
            $filas = DB::update($sql, array_merge($valores_caso, $ids));
        });

        return (int) $filas;
    }

    /**
     * Sube el límite de memoria si es menor al mínimo y no es ilimitado: el conjunto de slugs de un
     * dueño con cientos de miles de artículos no entra en los 128M por defecto de la CLI.
     *
     * @return void
     */
    protected static function asegurar_memoria()
    {
        $actual = trim((string) ini_get('memory_limit'));

        if ($actual === '-1' || $actual === '') {
            return;
        }

        // Bytes del límite actual (admite sufijos K, M, G).
        $numero = (int) $actual;
        $unidad = strtoupper(substr($actual, -1));
        $bytes = $numero * ($unidad === 'G' ? 1073741824 : ($unidad === 'M' ? 1048576 : ($unidad === 'K' ? 1024 : 1)));

        if ($bytes < 1073741824) {
            @ini_set('memory_limit', self::MEMORIA_MINIMA);
        }
    }
}
