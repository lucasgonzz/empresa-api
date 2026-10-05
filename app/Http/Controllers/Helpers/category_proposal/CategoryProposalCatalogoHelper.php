<?php

namespace App\Http\Controllers\Helpers\category_proposal;

use App\Models\CategoryProposalRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lo que la skill /categorizar lee del catálogo de un cliente por `admin-sync/catalogo/*` (misión
 * categorizacion-tres-modelos, 5/10/2026): el resumen con los hechos para diseñar los sistemas y el
 * inventario de artículos por cursor de id. Contrato A del plan, §5.1 y §5.2. SOLO LEE.
 *
 * También es el dueño de la definición del "universo de artículos" que usan la ingesta, la lectura
 * para la SPA y el aplicar: un solo lugar para decidir qué artículo cuenta.
 *
 * 🔴 EL UNIVERSO: artículos del dueño con `status = 'active'` y sin borrar. Es el mismo criterio con
 * el que el sistema entero habla de "los artículos" (listado, búsqueda, embeddings, catálogo del
 * asistente: `status='active'` y no borrado). Los `inactive` son los fantasma que crean las compras
 * (un renglón de un pedido a proveedor con un artículo que no estaba cargado): no se ven en el
 * listado, así que clasificarlos gastaría tokens de la skill en cosas que el dueño no ve y haría que
 * `articulos_total` no coincida con lo que él cuenta.
 *
 * 🔴 TODO por dueño: cada consulta lleva `user_id` del dueño, y cada JOIN a categorías,
 * subcategorías, marcas y proveedores también compara `user_id` con el del artículo. En la base
 * compartida vieja hay decenas de comercios: un artículo con un `category_id` que apunta a la
 * categoría de otro comercio (datos sucios) no tiene que filtrar el nombre de esa categoría.
 *
 * "Sin categoría" en el sistema es NULL, 0 o una categoría borrada. La definición robusta es el
 * LEFT JOIN a una categoría VIVA del mismo dueño: sin fila del otro lado, no tiene categoría.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class CategoryProposalCatalogoHelper
{
    /** Estado de `articles.status` de un artículo que el dueño ve en su listado. */
    const ESTADO_DE_ARTICULO_VISIBLE = 'active';

    /** Cuántos artículos devuelve `articulos` por página si la skill no pide una cantidad. */
    const LIMITE_POR_DEFECTO = 500;

    /**
     * Las consultas del universo de artículos de un dueño: activos y sin borrar. Devuelve el query
     * builder (no Eloquent: el catálogo grande no se hidrata modelo por modelo) listo para encadenarle
     * joins y condiciones.
     *
     * 🔴 `select` explícito en quien la use: `articles` tiene la columna `embedding` (~29 KB por fila)
     * y un `select *` la leería entera.
     *
     * @param  int    $owner_id  El dueño (users.id con owner_id NULL).
     * @param  string $alias     Alias de la tabla `articles` en la consulta.
     * @return \Illuminate\Database\Query\Builder
     */
    public static function articulos_del_dueno($owner_id, $alias = 'a')
    {
        return DB::table('articles as '.$alias)
            ->where($alias.'.user_id', (int) $owner_id)
            ->where($alias.'.status', self::ESTADO_DE_ARTICULO_VISIBLE)
            ->whereNull($alias.'.deleted_at');
    }

    /**
     * Cuántos artículos tiene el dueño en el universo.
     *
     * @param  int $owner_id
     * @return int
     */
    public static function contar_articulos($owner_id)
    {
        return (int) self::articulos_del_dueno($owner_id)->count();
    }

    /**
     * Le suma a una consulta del universo el LEFT JOIN a la categoría VIVA del artículo (alias `c`).
     * Si `c.id` queda NULL, el artículo no tiene categoría (NULL, 0, borrada o de otro comercio).
     *
     * @param  \Illuminate\Database\Query\Builder $consulta
     * @param  string $alias_articulo
     * @return \Illuminate\Database\Query\Builder
     */
    public static function unir_categoria_viva($consulta, $alias_articulo = 'a')
    {
        return $consulta->leftJoin('categories as c', function ($join) use ($alias_articulo) {
            $join->on('c.id', '=', $alias_articulo.'.category_id')
                ->on('c.user_id', '=', $alias_articulo.'.user_id')
                ->whereNull('c.deleted_at');
        });
    }

    /**
     * Le suma el LEFT JOIN a la subcategoría viva del artículo (alias `s`).
     *
     * @param  \Illuminate\Database\Query\Builder $consulta
     * @param  string $alias_articulo
     * @return \Illuminate\Database\Query\Builder
     */
    public static function unir_subcategoria_viva($consulta, $alias_articulo = 'a')
    {
        return $consulta->leftJoin('sub_categories as s', function ($join) use ($alias_articulo) {
            $join->on('s.id', '=', $alias_articulo.'.sub_category_id')
                ->on('s.user_id', '=', $alias_articulo.'.user_id')
                ->whereNull('s.deleted_at');
        });
    }

    /**
     * Le suma el LEFT JOIN a la marca del artículo (alias `b`). `brands` no tiene borrado lógico: al
     * borrar una marca el artículo conserva su `brand_id`, así que acá "sin fila" cubre a la marca
     * borrada.
     *
     * @param  \Illuminate\Database\Query\Builder $consulta
     * @param  string $alias_articulo
     * @return \Illuminate\Database\Query\Builder
     */
    public static function unir_marca($consulta, $alias_articulo = 'a')
    {
        return $consulta->leftJoin('brands as b', function ($join) use ($alias_articulo) {
            $join->on('b.id', '=', $alias_articulo.'.brand_id')
                ->on('b.user_id', '=', $alias_articulo.'.user_id');
        });
    }

    /**
     * Le suma el LEFT JOIN al proveedor vivo del artículo (alias `p`).
     *
     * @param  \Illuminate\Database\Query\Builder $consulta
     * @param  string $alias_articulo
     * @return \Illuminate\Database\Query\Builder
     */
    public static function unir_proveedor($consulta, $alias_articulo = 'a')
    {
        return $consulta->leftJoin('providers as p', function ($join) use ($alias_articulo) {
            $join->on('p.id', '=', $alias_articulo.'.provider_id')
                ->on('p.user_id', '=', $alias_articulo.'.user_id')
                ->whereNull('p.deleted_at');
        });
    }

    /**
     * Un texto de la base listo para el JSON: sin espacios en los bordes y NULL si queda vacío (un
     * código de barras de espacios no es un código).
     *
     * @param  mixed $valor
     * @return string|null
     */
    public static function texto_o_null($valor)
    {
        if (is_null($valor)) {

            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * GET admin-sync/catalogo/resumen — los hechos del cliente para que la skill diseñe los sistemas
     * (contrato A, §5.1): los totales del catálogo, las categorías que ya tiene con su cantidad de
     * artículos, si puede o no elegir un sistema nuevo y la propuesta vigente.
     *
     * Cantidad de consultas constante: una agregada para los totales, dos listados (categorías y
     * subcategorías) y dos agrupadas para las cantidades por categoría y por subcategoría.
     *
     * @param  \App\Models\User $dueno
     * @return array
     */
    public static function resumen(User $dueno)
    {
        // El id del dueño: lo llevan todas las consultas de abajo.
        $owner_id = (int) $dueno->id;

        // Totales de artículos en UNA pasada: los tres LEFT JOIN son por clave primaria, así que no
        // multiplican filas. SUM sobre cero filas da NULL: de ahí el (int).
        $consulta = self::articulos_del_dueno($owner_id);
        self::unir_categoria_viva($consulta);
        self::unir_subcategoria_viva($consulta);
        self::unir_marca($consulta);

        // Los totales del catálogo en una sola fila.
        $totales = $consulta->selectRaw(
            'COUNT(*) AS total, '
            .'SUM(CASE WHEN c.id IS NULL THEN 1 ELSE 0 END) AS sin_categoria, '
            .'SUM(CASE WHEN s.id IS NOT NULL THEN 1 ELSE 0 END) AS con_subcategoria, '
            .'SUM(CASE WHEN b.id IS NOT NULL THEN 1 ELSE 0 END) AS con_marca, '
            ."SUM(CASE WHEN a.bar_code IS NOT NULL AND TRIM(a.bar_code) <> '' THEN 1 ELSE 0 END) AS con_codigo_de_barras, "
            ."SUM(CASE WHEN a.provider_code IS NOT NULL AND TRIM(a.provider_code) <> '' THEN 1 ELSE 0 END) AS con_codigo_de_proveedor"
        )->first();

        // Cuántos artículos hay y cuántos de ellos no tienen categoría viva (el resto sí la tiene).
        $total         = (int) $totales->total;
        $sin_categoria = (int) $totales->sin_categoria;

        // Las categorías y subcategorías vivas del dueño, por nombre (como las ordena la tienda).
        $categorias = DB::table('categories')
            ->where('user_id', $owner_id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        $subcategorias = DB::table('sub_categories')
            ->where('user_id', $owner_id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'category_id']);

        // Cuántos artículos del universo tiene cada categoría y cada subcategoría (agrupado en SQL).
        $por_categoria = self::articulos_del_dueno($owner_id)
            ->whereNotNull('a.category_id')
            ->groupBy('a.category_id')
            ->selectRaw('a.category_id AS id, COUNT(*) AS n')
            ->pluck('n', 'id');

        $por_subcategoria = self::articulos_del_dueno($owner_id)
            ->whereNotNull('a.sub_category_id')
            ->groupBy('a.sub_category_id')
            ->selectRaw('a.sub_category_id AS id, COUNT(*) AS n')
            ->pluck('n', 'id');

        // Las subcategorías agrupadas por su categoría padre.
        $subs_de = [];
        foreach ($subcategorias as $subcategoria) {
            $subs_de[(int) $subcategoria->category_id][] = [
                'id'        => (int) $subcategoria->id,
                'nombre'    => (string) $subcategoria->name,
                'articulos' => (int) (isset($por_subcategoria[$subcategoria->id]) ? $por_subcategoria[$subcategoria->id] : 0),
            ];
        }

        // El árbol que ya tiene el dueño: cada categoría con su cantidad de artículos y sus subcategorías.
        $arbol_existente = [];
        foreach ($categorias as $categoria) {
            $arbol_existente[] = [
                'id'            => (int) $categoria->id,
                'nombre'        => (string) $categoria->name,
                'articulos'     => (int) (isset($por_categoria[$categoria->id]) ? $por_categoria[$categoria->id] : 0),
                'subcategorias' => isset($subs_de[(int) $categoria->id]) ? $subs_de[(int) $categoria->id] : [],
            ];
        }

        // El nombre del comercio: `company_name` es el que ve el equipo; `name` es el de la persona.
        $nombre = self::texto_o_null($dueno->company_name);

        return [
            'dueno' => [
                'id'     => $owner_id,
                'nombre' => is_null($nombre) ? (string) $dueno->name : $nombre,
            ],
            'articulos' => [
                'total'                   => $total,
                'con_categoria'           => $total - $sin_categoria,
                'sin_categoria'           => $sin_categoria,
                'con_subcategoria'        => (int) $totales->con_subcategoria,
                'con_marca'               => (int) $totales->con_marca,
                'con_codigo_de_barras'    => (int) $totales->con_codigo_de_barras,
                'con_codigo_de_proveedor' => (int) $totales->con_codigo_de_proveedor,
            ],
            'categorias'    => $arbol_existente,
            'marcas_total'  => (int) DB::table('brands')->where('user_id', $owner_id)->count(),
            // Lo decide el helper de las reglas de plata (API-2); la skill solo lo lee y, si está
            // bloqueado, no arma sistemas nuevos.
            'bloqueo'       => CategoryMargenesHelper::bloqueo_para($owner_id),
            'advertencias'  => array_values(CategoryMargenesHelper::advertencias_para($owner_id)),
            'tope_articulos' => (int) config('catalogo_ia.tope_articulos'),
            'propuesta_actual' => self::propuesta_actual($owner_id),
        ];
    }

    /**
     * La última corrida no descartada del dueño, en la forma corta del resumen: `{run_id, estado}` o
     * null si no hay ninguna.
     *
     * @param  int $owner_id
     * @return array|null
     */
    public static function propuesta_actual($owner_id)
    {
        // La última corrida no descartada del dueño.
        $run = CategoryProposalRun::where('user_id', (int) $owner_id)
            ->vigentes()
            ->orderBy('id', 'desc')
            ->first(['id', 'estado']);

        if (is_null($run)) {

            return null;
        }

        return ['run_id' => (int) $run->id, 'estado' => (string) $run->estado];
    }

    /**
     * GET admin-sync/catalogo/articulos?despues_de=&limite=&solo_sin_categoria= — el inventario del
     * dueño por cursor de id (contrato A, §5.2).
     *
     * Va por `id` ascendente y NO por `updated_at`: con un cursor por fecha, un artículo que se edita
     * mientras la skill recorre reaparecería en una página posterior. Con el id, cada artículo sale
     * una sola vez. Se piden `limite + 1` filas para saber si hay más sin hacer un COUNT por página.
     *
     * `total` es la cantidad de artículos que cumplen el filtro en TODO el catálogo (no solo en esta
     * página ni después del cursor): es lo que la skill muestra como avance.
     *
     * @param  int  $owner_id
     * @param  int  $despues_de          Devuelve los de id MAYOR a éste (0 = desde el principio).
     * @param  int  $limite              Se acota a 1..`articulos_por_pagina_maximo`.
     * @param  bool $solo_sin_categoria  Solo los que no tienen categoría viva (el flujo "mantener").
     * @return array  ['articulos' => [...], 'siguiente' => int|null, 'total' => int]
     */
    public static function articulos($owner_id, $despues_de, $limite, $solo_sin_categoria)
    {
        // El cursor: un valor que no es número o es negativo es "desde el principio".
        $despues_de = max(0, (int) $despues_de);

        // El tope sale de config (1000): una página más grande es un pedido que la skill no necesita
        // y un JSON que ningún proxy del hosting tiene por qué aguantar.
        $maximo = max(1, (int) config('catalogo_ia.articulos_por_pagina_maximo'));

        // El tamaño de la página que pidió la skill (en cero, negativo o sin número: el de por defecto).
        $limite = (int) $limite;

        if ($limite < 1) {

            $limite = self::LIMITE_POR_DEFECTO;
        }

        $limite = min($limite, $maximo);

        // El total del filtro, sin los joins que no hacen falta para contar.
        $para_contar = self::articulos_del_dueno($owner_id);

        if ($solo_sin_categoria) {

            self::unir_categoria_viva($para_contar);
            $para_contar->whereNull('c.id');
        }

        // Cuántos artículos cumplen el filtro en todo el catálogo (el avance que muestra la skill).
        $total = (int) $para_contar->count();

        // La página: con los nombres de categoría, subcategoría, marca y proveedor ya resueltos
        // (cuatro LEFT JOIN por clave primaria, una sola consulta, sin N+1).
        $consulta = self::articulos_del_dueno($owner_id);
        self::unir_categoria_viva($consulta);
        self::unir_subcategoria_viva($consulta);
        self::unir_marca($consulta);
        self::unir_proveedor($consulta);

        if ($solo_sin_categoria) {

            $consulta->whereNull('c.id');
        }

        // Las filas de la página, más una (si hay otra página después de ésta).
        $filas = $consulta
            ->where('a.id', '>', $despues_de)
            ->orderBy('a.id')
            ->limit($limite + 1)
            ->get([
                'a.id',
                'a.name as nombre',
                'a.bar_code as codigo_de_barras',
                'a.provider_code as codigo_de_proveedor',
                'p.name as proveedor',
                'b.name as marca',
                'c.id as categoria_id',
                'c.name as categoria',
                's.id as subcategoria_id',
                's.name as subcategoria',
            ]);

        // La fila de más (si vino) solo dice que hay otra página: no se devuelve.
        $hay_mas = count($filas) > $limite;

        if ($hay_mas) {

            $filas = $filas->slice(0, $limite)->values();
        }

        // Los artículos de la página con la forma del contrato (los textos vacíos salen como null).
        $articulos = [];

        foreach ($filas as $fila) {
            $articulos[] = [
                'id'                  => (int) $fila->id,
                'nombre'              => is_null($fila->nombre) ? '' : (string) $fila->nombre,
                'codigo_de_barras'    => self::texto_o_null($fila->codigo_de_barras),
                'codigo_de_proveedor' => self::texto_o_null($fila->codigo_de_proveedor),
                'proveedor'           => self::texto_o_null($fila->proveedor),
                'marca'               => self::texto_o_null($fila->marca),
                'categoria_id'        => is_null($fila->categoria_id) ? null : (int) $fila->categoria_id,
                'categoria'           => self::texto_o_null($fila->categoria),
                'subcategoria_id'     => is_null($fila->subcategoria_id) ? null : (int) $fila->subcategoria_id,
                'subcategoria'        => self::texto_o_null($fila->subcategoria),
            ];
        }

        // `siguiente` es el último id devuelto (el cursor para el próximo pedido) o null si no hay más.
        $ultimo = count($articulos) > 0 ? $articulos[count($articulos) - 1]['id'] : null;

        return [
            'articulos' => $articulos,
            'siguiente' => $hay_mas ? $ultimo : null,
            'total'     => $total,
        ];
    }
}
