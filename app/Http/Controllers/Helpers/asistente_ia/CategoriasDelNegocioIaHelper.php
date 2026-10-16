<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use Illuminate\Support\Facades\DB;

/**
 * Las categorías y subcategorías del negocio para el asistente (misión
 * asistente-altas-y-conversacion, 16/10/2026, clase D5).
 *
 * De dónde sale: la prueba real por WhatsApp contra demo3 (`_video/agente-personal/registro.md`,
 * S10 y S11). La cinta 3M y el martillo Tramontina quedaron dados de alta SIN categoría ni
 * subcategoría, aunque "Electricidad e iluminación / Electricidad" y "Herramientas de mano / Herramientas
 * Manuales" eran obvias, y cuando el dueño pidió "ponele la categoría que corresponda" el martillo
 * quedó con la categoría pero sin la subcategoría. Tres causas, y este helper es la pieza común de
 * las tres:
 *
 *   1. Ninguna herramienta le mostraba al modelo el árbol de categorías del negocio: no podía elegir
 *      lo que no veía. arbol() es ese árbol, compacto (solo nombres) y con tope, y viaja en
 *      que_puedo_cargar de `article`, en el resultado de buscar_producto_por_codigo_de_barras y en el
 *      error de un alta de artículo sin categoría.
 *   2. Un alta de artículo sin `category_id` se armaba igual (el prompt dice "un opcional que no se
 *      dijo no se manda"). PropuestaGenericaIaHelper::proponer_alta() ahora corta con un error que
 *      empieza con "No se armó ninguna tarjeta" y trae el árbol: es para el modelo, que elige y
 *      vuelve a llamar en el mismo mensaje.
 *   3. La subcategoría se resolvía por nombre contra TODAS las del negocio, sin mirar de qué
 *      categoría colgaba. resolver_subcategoria() la busca DENTRO de la categoría elegida y, si vino
 *      sola, deduce la categoría.
 *
 * 🔴 "Sin categoría" sigue siendo una respuesta válida: es_sin_categoria() reconoce el vacío explícito
 * ("", null mandado a propósito, "sin categoría", "ninguna", 0) y el alta se arma sin categoría, como
 * antes. Lo único que cambia es que el modelo ya no puede OLVIDARSE de la categoría.
 *
 * Todo filtra por el dueño (`user_id`) y saca lo borrado (`deleted_at`): las dos tablas usan
 * SoftDeletes.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class CategoriasDelNegocioIaHelper
{
    /** Cuántas categorías entran en el árbol (las que más artículos tienen, si hay más). */
    const TOPE_DE_CATEGORIAS = 60;

    /** Cuántas subcategorías de cada categoría entran en el árbol. */
    const TOPE_DE_SUBCATEGORIAS = 40;

    /** El comienzo de todo error de este helper: el prompt lo trata como "es para vos, elegí y volvé a llamar". */
    const NO_SE_ARMO = 'No se armó ninguna tarjeta: ';

    /**
     * Lo que el modelo manda para decir "sin categoría" (normalizado: minúsculas, sin acentos).
     */
    const VALORES_SIN_CATEGORIA = [
        'sin categoria', 'sin subcategoria', 'sin rubro', 'ninguna', 'ninguno', 'ningun', 'ninguna categoria',
        'ninguna subcategoria', 'no', 'no tiene', 'nada', 'vacio', 'vacia', 'null', 'none', '-', '0',
    ];

    // -------------------------------------------------------------------------------------------
    // El árbol
    // -------------------------------------------------------------------------------------------

    /**
     * true si el negocio tiene al menos una categoría viva.
     *
     * @param  int  $owner_id
     * @return bool
     */
    public static function tiene_categorias($owner_id): bool
    {
        return DB::table('categories')
                    ->where('user_id', (int) $owner_id)
                    ->whereNull('deleted_at')
                    ->exists();
    }

    /**
     * El árbol compacto del negocio: cada categoría con los nombres de sus subcategorías, ordenado
     * por nombre. Con más de TOPE_DE_CATEGORIAS categorías entran las que más artículos tienen, y
     * `cortado` lo dice (con `aviso` para el modelo): un árbol recortado en silencio haría que el
     * modelo eligiera "la que más se parece" entre las que vio.
     *
     * @param  int  $owner_id
     * @return array{categorias: array<int, array{categoria: string, subcategorias: array<int, string>}>, cortado: bool, aviso: string|null}
     */
    public static function arbol($owner_id): array
    {
        $owner_id = (int) $owner_id;

        $categorias = DB::table('categories')
                        ->where('user_id', $owner_id)
                        ->whereNull('deleted_at')
                        ->orderBy('name')
                        ->get(['id', 'name']);

        $total = count($categorias);
        $cortado = false;

        if ($total > self::TOPE_DE_CATEGORIAS) {

            $cortado = true;

            // Las que más artículos tienen: son las que más probablemente le toquen a un alta.
            $cuantos = DB::table('articles')
                            ->where('user_id', $owner_id)
                            ->whereNull('deleted_at')
                            ->whereNotNull('category_id')
                            ->groupBy('category_id')
                            ->select('category_id', DB::raw('COUNT(*) as cantidad'))
                            ->pluck('cantidad', 'category_id')
                            ->all();

            $ordenadas = $categorias->all();

            usort($ordenadas, function ($a, $b) use ($cuantos) {

                $ca = isset($cuantos[$a->id]) ? (int) $cuantos[$a->id] : 0;
                $cb = isset($cuantos[$b->id]) ? (int) $cuantos[$b->id] : 0;

                if ($ca === $cb) {

                    return strcmp(mb_strtolower((string) $a->name), mb_strtolower((string) $b->name));
                }

                return $cb - $ca;
            });

            $elegidas = array_slice($ordenadas, 0, self::TOPE_DE_CATEGORIAS);

            usort($elegidas, function ($a, $b) {
                return strcmp(mb_strtolower((string) $a->name), mb_strtolower((string) $b->name));
            });

            $categorias = collect($elegidas);
        }

        $ids = [];

        foreach ($categorias as $categoria) {

            $ids[] = (int) $categoria->id;
        }

        $subs_por_categoria = [];

        if (count($ids)) {

            foreach (DB::table('sub_categories')
                        ->where('user_id', $owner_id)
                        ->whereNull('deleted_at')
                        ->whereIn('category_id', $ids)
                        ->orderBy('name')
                        ->get(['category_id', 'name']) as $sub) {

                $subs_por_categoria[(int) $sub->category_id][] = (string) $sub->name;
            }
        }

        $arbol = [];
        $subs_cortadas = false;

        foreach ($categorias as $categoria) {

            $subs = isset($subs_por_categoria[(int) $categoria->id]) ? $subs_por_categoria[(int) $categoria->id] : [];

            if (count($subs) > self::TOPE_DE_SUBCATEGORIAS) {

                $subs = array_slice($subs, 0, self::TOPE_DE_SUBCATEGORIAS);
                $subs_cortadas = true;
            }

            $arbol[] = ['categoria' => (string) $categoria->name, 'subcategorias' => $subs];
        }

        $aviso = null;

        if ($cortado) {

            $aviso = 'El negocio tiene ' . $total . ' categorías y acá van las ' . self::TOPE_DE_CATEGORIAS . ' con más artículos. Si ninguna encaja, podés mandar el nombre de otra igual: se busca entre todas.';
        }

        if ($subs_cortadas) {

            $aviso = (is_null($aviso) ? '' : $aviso . ' ') . 'Algunas categorías tienen más de ' . self::TOPE_DE_SUBCATEGORIAS . ' subcategorías y van recortadas.';
        }

        return ['categorias' => $arbol, 'cortado' => $cortado || $subs_cortadas, 'aviso' => $aviso];
    }

    /**
     * El árbol como viaja en una respuesta al modelo, o null si el negocio no tiene categorías (así
     * la clave ni aparece y nada cambia para un negocio sin categorías).
     *
     * @param  int  $owner_id
     * @return array|null
     */
    public static function para_respuesta($owner_id)
    {
        $arbol = self::arbol($owner_id);

        if (!count($arbol['categorias'])) {

            return null;
        }

        $respuesta = [
            'categorias' => $arbol['categorias'],
            'como_se_usa' => 'El alta de un artículo lleva category_id con el nombre de una de estas categorías y sub_category_id con una subcategoría DE ESA categoría (si alguna encaja). Elegí vos la que corresponde al producto; si la persona pidió que quede sin categoría, mandá category_id vacío.',
        ];

        if (!is_null($arbol['aviso'])) {

            $respuesta['aviso'] = $arbol['aviso'];
        }

        return $respuesta;
    }

    // -------------------------------------------------------------------------------------------
    // "Sin categoría"
    // -------------------------------------------------------------------------------------------

    /**
     * true si el valor dice "sin categoría" a propósito: null, "", 0, "sin categoría", "ninguna"...
     *
     * @param  mixed  $valor
     * @return bool
     */
    public static function es_sin_categoria($valor): bool
    {
        if (is_null($valor)) {

            return true;
        }

        if (is_bool($valor)) {

            return $valor === false;
        }

        if (is_int($valor) || is_float($valor)) {

            return (float) $valor <= 0;
        }

        if (!is_string($valor)) {

            return false;
        }

        $normalizado = self::normalizar($valor);

        return $normalizado === '' || in_array($normalizado, self::VALORES_SIN_CATEGORIA, true);
    }

    // -------------------------------------------------------------------------------------------
    // La subcategoría
    // -------------------------------------------------------------------------------------------

    /**
     * Resuelve una subcategoría pedida por nombre (o por id) con su categoría.
     *
     *  - Con `$category_id`: se busca SOLO entre las subcategorías de esa categoría. Si no está ahí:
     *    con `$buscar_en_otras` (la edición de un artículo al que le nombran solo la subcategoría) se
     *    deduce entre todas; sin él, error con las subcategorías de esa categoría.
     *  - Sin `$category_id` (vino solo la subcategoría): se busca entre todas las del negocio; una
     *    sola → esa y su categoría; varias en distintas categorías → error con las opciones
     *    ("Subcategoría (de Categoría)"); ninguna → error con el árbol.
     *
     * @param  int  $owner_id
     * @param  int|null  $category_id
     * @param  mixed  $valor
     * @param  bool  $buscar_en_otras
     * @return array  ['sub_category_id', 'subcategoria', 'category_id', 'categoria'] o la respuesta negativa.
     */
    public static function resolver_subcategoria($owner_id, $category_id, $valor, $buscar_en_otras = false)
    {
        $owner_id = (int) $owner_id;
        $category_id = is_null($category_id) || (int) $category_id <= 0 ? null : (int) $category_id;

        $texto = is_string($valor) ? trim($valor) : $valor;

        $categoria = is_null($category_id) ? null : self::categoria($owner_id, $category_id);

        // Un número es un id que devolvió otra herramienta.
        if (is_numeric($texto)) {

            $sub = DB::table('sub_categories')
                        ->where('user_id', $owner_id)
                        ->whereNull('deleted_at')
                        ->where('id', (int) $texto)
                        ->first(['id', 'name', 'category_id']);

            if (!is_null($sub)) {

                if (is_null($category_id) || (int) $sub->category_id === $category_id || $buscar_en_otras) {

                    return self::resuelta($owner_id, $sub);
                }

                return self::no_es_de_la_categoria($owner_id, (string) $sub->name, $categoria);
            }
        }

        $texto = (string) $texto;

        if (!is_null($categoria)) {

            $de_la_categoria = DB::table('sub_categories')
                                    ->where('user_id', $owner_id)
                                    ->whereNull('deleted_at')
                                    ->where('category_id', (int) $categoria->id)
                                    ->orderBy('name')
                                    ->get(['id', 'name', 'category_id']);

            $encontradas = self::por_nombre($de_la_categoria, $texto);

            if (count($encontradas) === 1) {

                return self::resuelta($owner_id, $encontradas[0]);
            }

            if (count($encontradas) > 1) {

                return RespuestaDeCargaIa::error(
                    self::NO_SE_ARMO . 'en la categoría "' . $categoria->name . '" hay ' . count($encontradas) . ' subcategorías que encajan con "' . $texto . '". Elegí una de las opciones y volvé a llamar en este mismo mensaje.',
                    ['sub_category_id' => self::nombres($encontradas)]
                );
            }

            if (!$buscar_en_otras) {

                return self::no_es_de_la_categoria($owner_id, $texto, $categoria);
            }
        }

        $todas = DB::table('sub_categories')
                    ->where('user_id', $owner_id)
                    ->whereNull('deleted_at')
                    ->orderBy('name')
                    ->get(['id', 'name', 'category_id']);

        $encontradas = self::por_nombre($todas, $texto);

        if (count($encontradas) === 1) {

            return self::resuelta($owner_id, $encontradas[0]);
        }

        if (count($encontradas) > 1) {

            $opciones = [];

            foreach ($encontradas as $sub) {

                $de = self::categoria($owner_id, (int) $sub->category_id);

                $opciones[] = (string) $sub->name . ' (de ' . (is_null($de) ? '?' : $de->name) . ')';
            }

            return RespuestaDeCargaIa::error(
                self::NO_SE_ARMO . 'hay ' . count($encontradas) . ' subcategorías "' . $texto . '" en distintas categorías. Elegí la que corresponde al producto y volvé a llamar en este mismo mensaje con category_id y sub_category_id.',
                ['sub_category_id' => array_slice($opciones, 0, PropuestaGenericaIaHelper::MAX_OPCIONES)]
            );
        }

        $arbol = self::arbol($owner_id);

        return RespuestaDeCargaIa::error(
            self::NO_SE_ARMO . 'no hay ninguna subcategoría que se llame como "' . $texto . '". Elegí del árbol la categoría y la subcategoría que corresponden (o mandá sub_category_id vacío) y volvé a llamar en este mismo mensaje.',
            ['categorias' => $arbol['categorias']]
        );
    }

    /**
     * true si la subcategoría cuelga de esa categoría.
     *
     * @param  int  $sub_category_id
     * @param  int|null  $category_id
     * @return bool
     */
    public static function es_de_la_categoria($sub_category_id, $category_id): bool
    {
        if (is_null($category_id) || (int) $category_id <= 0) {

            return false;
        }

        return DB::table('sub_categories')
                    ->where('id', (int) $sub_category_id)
                    ->where('category_id', (int) $category_id)
                    ->exists();
    }

    /**
     * El nombre de una subcategoría por id (aunque esté borrada: es para decir qué se saca), o null.
     *
     * @param  int  $sub_category_id
     * @return string|null
     */
    public static function nombre_de_subcategoria($sub_category_id)
    {
        $nombre = DB::table('sub_categories')->where('id', (int) $sub_category_id)->value('name');

        return is_null($nombre) ? null : (string) $nombre;
    }

    /**
     * La categoría de una subcategoría del dueño: ['id', 'nombre'] o null.
     *
     * @param  int  $owner_id
     * @param  int  $sub_category_id
     * @return array|null
     */
    public static function categoria_de_la_subcategoria($owner_id, $sub_category_id)
    {
        $sub = DB::table('sub_categories')
                    ->where('user_id', (int) $owner_id)
                    ->where('id', (int) $sub_category_id)
                    ->first(['category_id']);

        if (is_null($sub)) {

            return null;
        }

        $categoria = self::categoria((int) $owner_id, (int) $sub->category_id);

        return is_null($categoria) ? null : ['id' => (int) $categoria->id, 'nombre' => (string) $categoria->name];
    }

    // -------------------------------------------------------------------------------------------
    // Internos
    // -------------------------------------------------------------------------------------------

    /**
     * La categoría viva del dueño, o null.
     *
     * @param  int  $owner_id
     * @param  int  $category_id
     * @return object|null
     */
    protected static function categoria($owner_id, $category_id)
    {
        return DB::table('categories')
                    ->where('user_id', (int) $owner_id)
                    ->whereNull('deleted_at')
                    ->where('id', (int) $category_id)
                    ->first(['id', 'name']);
    }

    /**
     * La subcategoría resuelta con su categoría.
     *
     * @param  int  $owner_id
     * @param  object  $sub
     * @return array
     */
    protected static function resuelta($owner_id, $sub): array
    {
        $categoria = self::categoria($owner_id, (int) $sub->category_id);

        return [
            'sub_category_id' => (int) $sub->id,
            'subcategoria'    => (string) $sub->name,
            'category_id'     => is_null($categoria) ? null : (int) $categoria->id,
            'categoria'       => is_null($categoria) ? null : (string) $categoria->name,
        ];
    }

    /**
     * El error de una subcategoría que no es de la categoría elegida, con las que sí lo son.
     *
     * @param  int  $owner_id
     * @param  string  $pedida
     * @param  object  $categoria
     * @return array
     */
    protected static function no_es_de_la_categoria($owner_id, $pedida, $categoria): array
    {
        $de_la_categoria = DB::table('sub_categories')
                                ->where('user_id', (int) $owner_id)
                                ->whereNull('deleted_at')
                                ->where('category_id', (int) $categoria->id)
                                ->orderBy('name')
                                ->limit(self::TOPE_DE_SUBCATEGORIAS)
                                ->pluck('name')
                                ->all();

        if (!count($de_la_categoria)) {

            return RespuestaDeCargaIa::error(
                self::NO_SE_ARMO . 'la subcategoría "' . $pedida . '" no es de la categoría "' . $categoria->name . '", y esa categoría no tiene subcategorías. Mandá sub_category_id vacío (o elegí otra categoría) y volvé a llamar en este mismo mensaje.'
            );
        }

        return RespuestaDeCargaIa::error(
            self::NO_SE_ARMO . 'la subcategoría "' . $pedida . '" no es de la categoría "' . $categoria->name . '". Las subcategorías de "' . $categoria->name . '" son las de las opciones: elegí una (o mandá sub_category_id vacío) y volvé a llamar en este mismo mensaje.',
            ['sub_category_id' => array_map('strval', $de_la_categoria)]
        );
    }

    /**
     * Las filas cuyo nombre es el pedido (sin mayúsculas ni acentos); si ninguna es exacta, las que lo
     * contienen o están contenidas en él.
     *
     * @param  iterable  $filas
     * @param  string  $texto
     * @return array<int, object>
     */
    protected static function por_nombre($filas, $texto): array
    {
        $buscado = self::normalizar($texto);

        if ($buscado === '') {

            return [];
        }

        $exactas = [];
        $parciales = [];

        foreach ($filas as $fila) {

            $nombre = self::normalizar((string) $fila->name);

            if ($nombre === '') {

                continue;
            }

            if ($nombre === $buscado) {

                $exactas[] = $fila;

                continue;
            }

            if (mb_strpos($nombre, $buscado) !== false || mb_strpos($buscado, $nombre) !== false) {

                $parciales[] = $fila;
            }
        }

        return count($exactas) ? $exactas : $parciales;
    }

    /**
     * @param  array<int, object>  $filas
     * @return array<int, string>
     */
    protected static function nombres(array $filas): array
    {
        $nombres = [];

        foreach ($filas as $fila) {

            $nombres[] = (string) $fila->name;
        }

        return $nombres;
    }

    /**
     * Minúsculas, sin acentos y con los espacios colapsados.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar($texto): string
    {
        $texto = mb_strtolower(trim((string) $texto));

        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }
}
