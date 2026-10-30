<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\agenda\AgendaTareaHelper;
use App\Http\Controllers\Helpers\ColumnFiltersHelper;
use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Provider;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El filtro de artículos que arma el asistente de IA en lenguaje natural (misión
 * asistente-masivas-imagenes-y-remito, 19/9/2026, plan §4.1). Lo comparten la actualización masiva y
 * la búsqueda de imágenes para artículos: la IA manda `filtros: [{campo, operador, valor}]` y acá se
 * traduce, en UN solo lugar, al `filter_form` que consume ColumnFiltersHelper — o sea, a los mismos
 * filtros de columna que la persona arma con la lupa de cada header del listado.
 *
 * 🔴 NO REIMPLEMENTA NINGÚN FILTRO. Cada campo de la IA se traduce a `[{key, type, igual_que |
 * que_contenga | menor_que | mayor_que | en_blanco | no_en_blanco | checkbox}]` y el SQL lo escribe
 * ColumnFiltersHelper::apply(), igual que para la pantalla. Lo único que no es un filtro de columna es
 * `imagen` (las imágenes viven en otra tabla) y por eso va aparte, con whereHas / whereDoesntHave.
 *
 * 🔴 CORRE SIN Auth. Las herramientas del asistente corren adentro de ResponderMensajeChatIaJob, sin
 * sesión, donde UserHelper::userId() devolvería el USER_ID de config (otro comercio). Por eso la
 * query arranca en `Article::where('user_id', $owner_id)` con el dueño explícito y NO pasa por
 * SearchController::search(), que resuelve el dueño por Auth. El status 'active' es el mismo corte
 * que hace search() para 'article'.
 *
 * Las fechas: la IA habla en `desde` / `hasta` (≥ / ≤), y ColumnFiltersHelper compara fechas con
 * whereDate y solo tiene <, = y >. Por eso `desde D` se traduce a `mayor_que D − 1 día` y `hasta D` a
 * `menor_que D + 1 día`: da exactamente el rango inclusivo pedido sin tocar el helper (que es de
 * la pantalla y no se toca en esta misión).
 */
class FiltroDeArticulosIaHelper
{
    const ORDEN_PRIMEROS_CREADOS = 'primeros_creados';

    const ORDEN_ULTIMOS_CREADOS = 'ultimos_creados';

    /** Los dos valores posibles del filtro especial `imagen` (mismos nombres que los operadores). */
    const IMAGEN_EN_BLANCO = 'en_blanco';

    const IMAGEN_NO_EN_BLANCO = 'no_en_blanco';

    /** Cuántos nombres se le muestran a la IA como muestra de lo que alcanza el filtro. */
    const TAMANO_DE_MUESTRA = 5;

    /**
     * Las relaciones que la IA nombra por su NOMBRE (nunca por id). `plural` es la clave de las
     * opciones que viajan en un `faltan`, para que la IA pregunte "¿cuál proveedor?" con la lista.
     *
     * @var array<string, array<string, string>>
     */
    const RELACIONES = [
        'proveedor'     => ['modelo' => Provider::class,    'etiqueta' => 'Proveedor',    'plural' => 'proveedores',    'articulo' => 'ningún proveedor'],
        'categoria'     => ['modelo' => Category::class,    'etiqueta' => 'Categoría',    'plural' => 'categorias',     'articulo' => 'ninguna categoría'],
        'sub_categoria' => ['modelo' => SubCategory::class, 'etiqueta' => 'Subcategoría', 'plural' => 'sub_categorias', 'articulo' => 'ninguna subcategoría'],
        'marca'         => ['modelo' => Brand::class,       'etiqueta' => 'Marca',        'plural' => 'marcas',         'articulo' => 'ninguna marca'],
    ];

    /**
     * El catálogo de campos filtrables: nombre para la IA → columna de `articles`, tipo del
     * filter_form (el `type` que lee ColumnFiltersHelper) y etiqueta para la tarjeta. Los tipos son
     * los mismos que declara src/models/article.js del SPA para cada propiedad (`cost` filtra como
     * number aunque se edite como text: `filter_type: 'number'`).
     *
     * @var array<string, array<string, string|null>>
     */
    const CAMPOS = [
        'proveedor'                   => ['columna' => 'provider_id',                    'tipo' => 'search',   'etiqueta' => 'Proveedor',                   'relacion' => 'proveedor'],
        'categoria'                   => ['columna' => 'category_id',                    'tipo' => 'search',   'etiqueta' => 'Categoría',                   'relacion' => 'categoria'],
        'sub_categoria'               => ['columna' => 'sub_category_id',                'tipo' => 'search',   'etiqueta' => 'Subcategoría',                'relacion' => 'sub_categoria'],
        'marca'                       => ['columna' => 'brand_id',                       'tipo' => 'search',   'etiqueta' => 'Marca',                       'relacion' => 'marca'],
        'nombre'                      => ['columna' => 'name',                           'tipo' => 'textarea', 'etiqueta' => 'Nombre'],
        'codigo_de_barras'            => ['columna' => 'bar_code',                       'tipo' => 'text',     'etiqueta' => 'Código de barras'],
        'codigo_de_proveedor'         => ['columna' => 'provider_code',                  'tipo' => 'text',     'etiqueta' => 'Código de proveedor'],
        'costo'                       => ['columna' => 'cost',                           'tipo' => 'number',   'etiqueta' => 'Costo'],
        'precio_final'                => ['columna' => 'final_price',                    'tipo' => 'number',   'etiqueta' => 'Precio final'],
        'precio_manual'               => ['columna' => 'price',                          'tipo' => 'number',   'etiqueta' => 'Precio manual'],
        'margen_de_ganancia'          => ['columna' => 'percentage_gain',                'tipo' => 'number',   'etiqueta' => 'Margen de ganancia'],
        'stock'                       => ['columna' => 'stock',                          'tipo' => 'number',   'etiqueta' => 'Stock'],
        'stock_minimo'                => ['columna' => 'stock_min',                      'tipo' => 'number',   'etiqueta' => 'Stock mínimo'],
        'precio_actualizado'          => ['columna' => 'final_price_updated_at',         'tipo' => 'date',     'etiqueta' => 'Precio actualizado'],
        'stock_actualizado'           => ['columna' => 'stock_updated_at',               'tipo' => 'date',     'etiqueta' => 'Stock actualizado'],
        'fecha_de_alta'               => ['columna' => 'created_at',                     'tipo' => 'date',     'etiqueta' => 'Fecha de alta'],
        'fecha_de_modificacion'       => ['columna' => 'updated_at',                     'tipo' => 'date',     'etiqueta' => 'Fecha de modificación'],
        'en_tienda'                   => ['columna' => 'online',                         'tipo' => 'checkbox', 'etiqueta' => 'En tienda'],
        'destacado'                   => ['columna' => 'featured',                       'tipo' => 'checkbox', 'etiqueta' => 'Destacado'],
        'en_oferta'                   => ['columna' => 'in_offer',                       'tipo' => 'checkbox', 'etiqueta' => 'En oferta'],
        'precio_pausado'              => ['columna' => 'precio_pausado',                 'tipo' => 'checkbox', 'etiqueta' => 'Precio pausado'],
        'es_insumo'                   => ['columna' => 'es_insumo',                      'tipo' => 'checkbox', 'etiqueta' => 'Es insumo'],
        'aplica_margen_del_proveedor' => ['columna' => 'apply_provider_percentage_gain', 'tipo' => 'checkbox', 'etiqueta' => 'Aplica margen del proveedor'],
        /*
         * El resto de las columnas por las que la lupa del listado filtra (src/models/article.js),
         * para que "todos los filtros que ya se pueden hacer desde la interfaz" sea literal: lo que
         * se edita como text/number/checkbox se filtra con el mismo tipo. Las relaciones por select
         * (IVA, unidad de medida, bodega, cepa) no entran: se filtran por id y a la IA le llegan por
         * nombre; si hacen falta, se suman como `relacion` con su modelo.
         */
        'sku'                         => ['columna' => 'sku',                            'tipo' => 'text',     'etiqueta' => 'SKU'],
        'plu'                         => ['columna' => 'plu',                            'tipo' => 'text',     'etiqueta' => 'PLU'],
        'descripcion'                 => ['columna' => 'descripcion',                    'tipo' => 'textarea', 'etiqueta' => 'Descripción'],
        'titulo_seo'                  => ['columna' => 'seo_title',                      'tipo' => 'text',     'etiqueta' => 'Título para SEO'],
        'precio_promocional'          => ['columna' => 'precio_promocional',             'tipo' => 'number',   'etiqueta' => 'Precio promocional'],
        'margen_de_ganancia_blanco'   => ['columna' => 'percentage_gain_blanco',         'tipo' => 'number',   'etiqueta' => 'Margen de ganancia blanco'],
        'precio_final_blanco'         => ['columna' => 'final_price_blanco',             'tipo' => 'number',   'etiqueta' => 'Precio final blanco'],
        'costo_real'                  => ['columna' => 'costo_real',                     'tipo' => 'number',   'etiqueta' => 'Costo real'],
        'medida'                      => ['columna' => 'medida',                         'tipo' => 'number',   'etiqueta' => 'Medida'],
        'unidades_individuales'       => ['columna' => 'unidades_individuales',          'tipo' => 'number',   'etiqueta' => 'Unidades individuales'],
        'aplicar_iva'                 => ['columna' => 'aplicar_iva',                    'tipo' => 'checkbox', 'etiqueta' => 'Aplicar IVA'],
        'costo_en_dolares'            => ['columna' => 'cost_in_dollars',                'tipo' => 'checkbox', 'etiqueta' => 'Costo en dólares'],
        'disponible_tienda_nube'      => ['columna' => 'disponible_tienda_nube',         'tipo' => 'checkbox', 'etiqueta' => 'Disponible en Tienda Nube'],
        'en_mercado_libre'            => ['columna' => 'mercado_libre',                  'tipo' => 'checkbox', 'etiqueta' => 'En Mercado Libre'],
        'omitir_en_lista_pdf'         => ['columna' => 'omitir_en_lista_pdf',            'tipo' => 'checkbox', 'etiqueta' => 'Omitir en lista PDF'],
        'imagen'                      => ['columna' => null,                             'tipo' => 'imagen',   'etiqueta' => 'Imagen'],
        /*
         * Misión agente-mejoras-uso-real (E2 punto 3, 29/10/2026), al final: el margen y el precio fijo
         * de CADA LISTA DE PRECIO, que viven en el pivote `article_price_type` y no en `articles`. Ver
         * traducir_por_lista(). Solo sirven para CONTAR (contar_articulos_por_filtro): la masiva y la
         * búsqueda de imágenes los rechazan (traducir() con $permitir_por_lista en false).
         */
        'margen_en_lista'             => ['columna' => null,                             'tipo' => 'margen_en_lista',      'etiqueta' => 'Margen en la lista'],
        'precio_fijo_en_lista'        => ['columna' => null,                             'tipo' => 'precio_fijo_en_lista', 'etiqueta' => 'Precio fijo en la lista'],
    ];

    /** Los dos tipos de filtro que van por lista de precio (ver traducir_por_lista()). */
    const TIPOS_POR_LISTA = ['margen_en_lista', 'precio_fijo_en_lista'];

    /**
     * Lo que dice la persona (o el modelo) para "en cualquier lista", ya normalizado. "La lista de
     * precios" a secas es la pregunta de Pablo (CONV 12): no nombra ninguna, así que son todas.
     */
    const CUALQUIER_LISTA = [
        '', 'cualquiera', 'cualquier lista', 'cualquier lista de precios', 'alguna', 'alguna lista', 'todas',
        'todas las listas', 'todas las listas de precios', 'la lista', 'la lista de precios', 'lista de precios',
        'las listas', 'las listas de precios', 'listas de precios',
    ];

    /** Cuántos artículos viajan en la muestra detallada de un filtro por lista. */
    const TAMANO_DE_MUESTRA_POR_LISTA = 10;

    /** Tolerancia de "margen igual a": la columna es decimal(12,2). */
    const TOLERANCIA_DE_MARGEN = 0.005;

    /**
     * Qué operadores acepta cada tipo. Los nombres son los de la IA (`contiene`, `mayor`, `desde`),
     * no los del filter_form: la traducción a `que_contenga` / `mayor_que` / etc. va en traducir().
     *
     * @var array<string, array<int, string>>
     */
    const OPERADORES = [
        'search'   => ['igual', 'en_blanco', 'no_en_blanco'],
        'text'     => ['contiene', 'igual', 'en_blanco', 'no_en_blanco'],
        'textarea' => ['contiene', 'igual', 'en_blanco', 'no_en_blanco'],
        'number'   => ['igual', 'mayor', 'menor', 'en_blanco', 'no_en_blanco'],
        'date'     => ['desde', 'hasta', 'igual', 'mayor', 'menor', 'en_blanco', 'no_en_blanco'],
        'checkbox' => ['igual'],
        'imagen'   => ['en_blanco', 'no_en_blanco'],
        // Misión agente-mejoras-uso-real (E2): los dos por lista de precio.
        'margen_en_lista'      => ['igual', 'mayor', 'menor'],
        'precio_fijo_en_lista' => ['igual'],
    ];

    /**
     * Traduce los filtros de la IA al filter_form de ColumnFiltersHelper.
     *
     * Misión agente-mejoras-uso-real (E2 punto 3, 29/10/2026):
     *  - `margen_en_lista` y `precio_fijo_en_lista` no son columnas: van en `por_lista` (ver
     *    traducir_por_lista()) y se aplican en query(). Solo con `$permitir_por_lista` (contar); la
     *    masiva y las imágenes, que no los pasan, reciben un error que lo explica.
     *  - `margen_de_ganancia` en un negocio donde la ficha esconde ese margen (listas de precio por
     *    artículo o por categoría, MargenesPorListaIaHelper::margen_suelto_prohibido()) es error: esa
     *    columna no arma ningún precio ahí (medido en Ferre Total: 0 artículos con margen suelto 0 y
     *    19 con margen 0 en LISTA GENERAL) y contar por ella da un número que no significa nada.
     *
     * @param  array  $filtros  [{campo, operador, valor, lista?}] tal como los mandó la IA.
     * @param  int  $owner_id
     * @param  bool  $permitir_por_lista  true solo para contar_articulos_por_filtro.
     * @return array  ['filter_form' => [...], 'legibles' => ['Proveedor: Bulonera', ...],
     *                 'renglones' => [{etiqueta, valor}], 'imagen' => null|'en_blanco'|'no_en_blanco',
     *                 'por_lista' => [...]]
     *                 o la respuesta negativa (RespuestaDeCargaIa) si algo no se puede interpretar.
     */
    public static function traducir(array $filtros, $owner_id, $permitir_por_lista = false)
    {
        $filter_form = [];
        $legibles = [];
        $renglones = [];
        $imagen = null;
        $por_lista = [];

        foreach ($filtros as $filtro) {

            if (!is_array($filtro)) {

                return RespuestaDeCargaIa::error('Cada filtro tiene que venir como {campo, operador, valor}.');
            }

            $campo = mb_strtolower(EntradaDeCargaIa::texto($filtro, 'campo'));
            $operador = mb_strtolower(EntradaDeCargaIa::texto($filtro, 'operador'));
            $valor = EntradaDeCargaIa::valor($filtro, 'valor');

            if (!isset(self::CAMPOS[$campo])) {

                return RespuestaDeCargaIa::error(
                    'No conozco el campo "' . $campo . '" para filtrar artículos. Los campos válidos son: '
                    . implode(', ', array_keys(self::CAMPOS)) . '.'
                );
            }

            $definicion = self::CAMPOS[$campo];
            $tipo = $definicion['tipo'];
            $etiqueta = $definicion['etiqueta'];

            if (!in_array($operador, self::OPERADORES[$tipo], true)) {

                return RespuestaDeCargaIa::error(
                    'El operador "' . $operador . '" no sirve para ' . $etiqueta . '. Para ese campo valen: '
                    . implode(', ', self::OPERADORES[$tipo]) . '.'
                );
            }

            // El margen suelto del artículo no arma el precio en un negocio con listas: se rechaza.
            if ($campo === 'margen_de_ganancia' && self::margen_suelto_prohibido($owner_id)) {

                return RespuestaDeCargaIa::error(
                    'Este negocio trabaja con listas de precio: el margen de ganancia del artículo (margen_de_ganancia) no es el que arma el precio, así que filtrar por él no dice nada. '
                    . 'Para contar por margen usá margen_en_lista en contar_articulos_por_filtro, con lista = el nombre de la lista o "cualquiera".'
                );
            }

            // El margen y el precio fijo de una lista de precio: van aparte (pivote article_price_type).
            if (in_array($tipo, self::TIPOS_POR_LISTA, true)) {

                if (!$permitir_por_lista) {

                    return RespuestaDeCargaIa::error(
                        'El filtro ' . $campo . ' sirve solo para contar artículos (contar_articulos_por_filtro): una actualización masiva o una búsqueda de imágenes no se puede filtrar por el margen o el precio fijo de una lista.'
                    );
                }

                $traducido = self::traducir_por_lista($tipo, $operador, $valor, EntradaDeCargaIa::texto($filtro, 'lista'), $owner_id);

                if (RespuestaDeCargaIa::es_negativa($traducido)) {

                    return $traducido;
                }

                $por_lista[] = $traducido['condicion'];
                $legibles[] = $etiqueta . ': ' . $traducido['texto'];
                $renglones[] = ['etiqueta' => $etiqueta, 'valor' => $traducido['texto']];

                continue;
            }

            // El filtro especial: no es una columna, se aplica en query() sobre la relación images.
            if ($tipo === 'imagen') {

                $imagen = $operador;
                $texto = $operador === self::IMAGEN_EN_BLANCO ? 'sin imagen' : 'con imagen';
                $legibles[] = $etiqueta . ': ' . $texto;
                $renglones[] = ['etiqueta' => $etiqueta, 'valor' => $texto];

                continue;
            }

            if ($operador === 'en_blanco' || $operador === 'no_en_blanco') {

                $filter_form[] = ['key' => $definicion['columna'], 'type' => $tipo, $operador => true];
                $texto = $operador === 'en_blanco' ? 'en blanco' : 'no en blanco';
                $legibles[] = $etiqueta . ': ' . $texto;
                $renglones[] = ['etiqueta' => $etiqueta, 'valor' => $texto];

                continue;
            }

            if (EntradaDeCargaIa::vacio($valor) || !is_scalar($valor)) {

                return RespuestaDeCargaIa::error('Falta el valor del filtro ' . $etiqueta . ' (' . $operador . ').');
            }

            $traducido = self::traducir_con_valor($definicion, $operador, $valor, $owner_id);

            if (RespuestaDeCargaIa::es_negativa($traducido)) {

                return $traducido;
            }

            $filter_form[] = $traducido['filtro'];
            $legibles[] = $etiqueta . ': ' . $traducido['texto'];
            $renglones[] = ['etiqueta' => $etiqueta, 'valor' => $traducido['texto']];
        }

        return [
            'filter_form' => $filter_form,
            'legibles'    => $legibles,
            'renglones'   => $renglones,
            'imagen'      => $imagen,
            'por_lista'   => $por_lista,
        ];
    }

    /**
     * Un filtro por lista de precio (misión agente-mejoras-uso-real, E2 punto 3).
     *
     * 🔴 EL CASO REAL. Pablo (Ferre Total, CONV 12) preguntó «¿hay algún producto con margen 0 en la
     * lista de precios?» y el agente le contestó «uno solo»: miraba `articles.percentage_gain`, que con
     * listas por artículo no mueve ningún precio. Medido en su base: 19 artículos activos con margen 0
     * en LISTA GENERAL (12 con stock) y 3.206 en MAYORISTA.
     *
     * El margen de un artículo EN UNA LISTA es el del pivote `article_price_type.percentage` y, si el
     * pivote no lo tiene (o el artículo no tiene fila para esa lista), el de la lista
     * (`price_types.percentage`), y si ninguno, 0: es exactamente lo que hace
     * ArticlePricesHelper::aplicar_precios_segun_listas_de_precios() al calcular el precio. El precio
     * fijo es `article_price_type.setear_precio_final = 1` (el precio de esa lista lo fijó una persona a
     * mano y el margen no lo mueve).
     *
     * `lista` va por nombre (MargenesPorListaIaHelper::lista_por_nombre()) o "cualquiera" (vacío,
     * "todas", "la lista de precios"): con "cualquiera" el artículo entra si cumple en ALGUNA lista.
     *
     * @param  string  $tipo  margen_en_lista | precio_fijo_en_lista
     * @param  string  $operador
     * @param  mixed  $valor
     * @param  string  $lista  El nombre de la lista, o "cualquiera".
     * @param  int  $owner_id
     * @return array  ['condicion' => [...], 'texto' => string] o la respuesta negativa.
     */
    protected static function traducir_por_lista($tipo, $operador, $valor, $lista, $owner_id)
    {
        $todas = MargenesPorListaIaHelper::listas_del_dueno($owner_id);

        if (!count($todas)) {

            return RespuestaDeCargaIa::error('Este negocio no trabaja con listas de precio: el margen es el del artículo (margen_de_ganancia).');
        }

        $buscada = self::normalizar_lista($lista);

        if (in_array($buscada, self::CUALQUIER_LISTA, true)) {

            $listas = $todas->all();
            $donde = 'cualquier lista';

        } else {

            $encontrada = MargenesPorListaIaHelper::lista_por_nombre($owner_id, $lista);

            if (RespuestaDeCargaIa::es_negativa($encontrada)) {

                return $encontrada;
            }

            $listas = [$encontrada];
            $donde = (string) $encontrada->name;
        }

        $resueltas = [];

        foreach ($listas as $una) {

            $resueltas[] = [
                'price_type_id' => (int) $una->id,
                'nombre'        => (string) $una->name,
                'margen'        => is_null($una->percentage) ? null : (float) $una->percentage,
            ];
        }

        if ($tipo === 'precio_fijo_en_lista') {

            $activo = self::si_o_no($valor);

            if (is_null($activo)) {

                return RespuestaDeCargaIa::error('El valor de Precio fijo en la lista tiene que ser si o no.');
            }

            return [
                'condicion' => ['tipo' => 'precio_fijo', 'listas' => $resueltas, 'operador' => 'igual', 'valor' => $activo],
                'texto'     => $donde . ': ' . ($activo ? 'sí' : 'no'),
            ];
        }

        if (!is_numeric($valor)) {

            return RespuestaDeCargaIa::error('El valor de Margen en la lista tiene que ser un número (el porcentaje: 30 es 30 %).');
        }

        $numero = (float) $valor;
        $textos = ['igual' => '', 'mayor' => 'más de ', 'menor' => 'menos de '];

        return [
            'condicion' => ['tipo' => 'margen', 'listas' => $resueltas, 'operador' => $operador, 'valor' => $numero],
            'texto'     => $donde . ': ' . $textos[$operador] . self::numero_legible($numero) . ' %',
        ];
    }

    /**
     * Aplica UNA condición por lista a la query de artículos (ver traducir_por_lista()).
     *
     * Margen: el artículo entra si en ALGUNA de las listas de la condición tiene una fila del pivote
     * cuyo margen efectivo (`COALESCE(apt.percentage, margen de la lista, 0)`) cumple, o no tiene
     * ninguna fila para esa lista y el margen de la lista (o 0) cumple — ese caso se decide en PHP, que
     * ya sabe el margen de la lista. Precio fijo sí: alguna fila con `setear_precio_final = 1` en esas
     * listas; no: ninguna.
     *
     * Todo correlacionado con `articles.id` por EXISTS: los pivotes pueden tener filas repetidas y un
     * JOIN multiplicaría los artículos del conteo.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array  $condicion
     * @return void
     */
    protected static function aplicar_por_lista($query, array $condicion)
    {
        $ids = array_column($condicion['listas'], 'price_type_id');

        if ($condicion['tipo'] === 'precio_fijo') {

            $fija = function ($sub) use ($ids) {
                $sub->selectRaw('1')
                    ->from('article_price_type as apt_fija')
                    ->whereColumn('apt_fija.article_id', 'articles.id')
                    ->whereIn('apt_fija.price_type_id', $ids)
                    ->where('apt_fija.setear_precio_final', 1);
            };

            if ($condicion['valor']) {

                $query->whereExists($fija);

            } else {

                $query->whereNotExists($fija);
            }

            return;
        }

        $operador = $condicion['operador'];
        $valor = (float) $condicion['valor'];

        $query->where(function ($alguna) use ($condicion, $operador, $valor) {

            foreach ($condicion['listas'] as $lista) {

                $alguna->orWhere(function ($en_esta) use ($lista, $operador, $valor) {

                    $id = (int) $lista['price_type_id'];
                    $defecto = $lista['margen'];

                    if ($operador === 'igual') {

                        $comparacion = 'ABS(COALESCE(apt_margen.percentage, ?, 0) - ?) < ' . self::TOLERANCIA_DE_MARGEN;

                    } elseif ($operador === 'mayor') {

                        $comparacion = 'COALESCE(apt_margen.percentage, ?, 0) > ?';

                    } else {

                        $comparacion = 'COALESCE(apt_margen.percentage, ?, 0) < ?';
                    }

                    $en_esta->whereExists(function ($sub) use ($id, $comparacion, $defecto, $valor) {
                        $sub->selectRaw('1')
                            ->from('article_price_type as apt_margen')
                            ->whereColumn('apt_margen.article_id', 'articles.id')
                            ->where('apt_margen.price_type_id', $id)
                            ->whereRaw($comparacion, [$defecto, $valor]);
                    });

                    // Sin fila para esa lista, manda el margen de la lista: si cumple, también entran.
                    if (self::cumple(is_null($defecto) ? 0.0 : (float) $defecto, $operador, $valor)) {

                        $en_esta->orWhereNotExists(function ($sub) use ($id) {
                            $sub->selectRaw('1')
                                ->from('article_price_type as apt_sin')
                                ->whereColumn('apt_sin.article_id', 'articles.id')
                                ->where('apt_sin.price_type_id', $id);
                        });
                    }
                });
            }
        });
    }

    /**
     * La comparación de un margen contra el valor pedido, en PHP (con la misma tolerancia que el SQL).
     *
     * @param  float  $margen
     * @param  string  $operador  igual | mayor | menor
     * @param  float  $valor
     * @return bool
     */
    protected static function cumple($margen, $operador, $valor)
    {
        if ($operador === 'igual') {

            return abs($margen - $valor) < self::TOLERANCIA_DE_MARGEN;
        }

        return $operador === 'mayor' ? $margen > $valor : $margen < $valor;
    }

    /**
     * true si en este negocio el margen suelto del artículo no arma el precio (ver
     * MargenesPorListaIaHelper::margen_suelto_prohibido()). Una consulta, solo cuando hay un filtro
     * por margen_de_ganancia.
     *
     * @param  int  $owner_id
     * @return bool
     */
    protected static function margen_suelto_prohibido($owner_id)
    {
        return MargenesPorListaIaHelper::margen_suelto_prohibido(User::find((int) $owner_id));
    }

    /**
     * Minúsculas, sin tildes, sin signos de pregunta y con los espacios colapsados, para comparar lo
     * que se dijo de la lista contra CUALQUIER_LISTA.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar_lista($texto)
    {
        $texto = strtr(mb_strtolower(trim((string) $texto)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '?' => '', '¿' => '', '"' => '']);

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /**
     * "30", "12,5".
     *
     * @param  float  $numero
     * @return string
     */
    protected static function numero_legible($numero)
    {
        $redondeado = round((float) $numero, 2);

        if ((float) (int) $redondeado === $redondeado) {

            return (string) (int) $redondeado;
        }

        return str_replace('.', ',', rtrim(rtrim(number_format($redondeado, 2, '.', ''), '0'), '.'));
    }

    /**
     * Un filtro con valor (todos menos en_blanco / no_en_blanco), ya validado el operador.
     *
     * @param  array  $definicion  Entrada de CAMPOS.
     * @param  string  $operador
     * @param  mixed  $valor
     * @param  int  $owner_id
     * @return array  ['filtro' => entrada del filter_form, 'texto' => valor legible] o negativa.
     */
    protected static function traducir_con_valor(array $definicion, $operador, $valor, $owner_id)
    {
        $columna = $definicion['columna'];
        $tipo = $definicion['tipo'];
        $etiqueta = $definicion['etiqueta'];

        switch ($tipo) {

            case 'search':

                $modelo = self::resolver_relacion($owner_id, $definicion['relacion'], (string) $valor);

                if (RespuestaDeCargaIa::es_negativa($modelo)) {

                    return $modelo;
                }

                return [
                    'filtro' => ['key' => $columna, 'type' => 'search', 'igual_que' => (int) $modelo->id],
                    'texto'  => (string) $modelo->name,
                ];

            case 'text':
            case 'textarea':

                $texto = trim((string) $valor);

                if ($operador === 'contiene') {

                    return [
                        'filtro' => ['key' => $columna, 'type' => $tipo, 'que_contenga' => $texto],
                        'texto'  => 'contiene "' . $texto . '"',
                    ];
                }

                return [
                    'filtro' => ['key' => $columna, 'type' => $tipo, 'igual_que' => $texto],
                    'texto'  => $texto,
                ];

            case 'number':

                if (!is_numeric($valor)) {

                    return RespuestaDeCargaIa::error('El valor de ' . $etiqueta . ' tiene que ser un número.');
                }

                /*
                 * Va como STRING, igual que lo manda la pantalla, y no como número. El helper filtra
                 * con `$filter['igual_que'] != ''`, y en PHP 7 un 0 numérico contra '' da IGUAL
                 * (los dos se leen como 0): "stock igual 0" con un int se saltearía en silencio.
                 * Con "0" la comparación es entre strings y el filtro se aplica. El `+ 0` previo
                 * normaliza "100.0" → 100 y "10,5" no entra (is_numeric ya lo rechazó).
                 */
                $numero = (string) ($valor + 0);

                $operadores = ['igual' => 'igual_que', 'mayor' => 'mayor_que', 'menor' => 'menor_que'];
                $textos = ['igual' => (string) $numero, 'mayor' => 'mayor que ' . $numero, 'menor' => 'menor que ' . $numero];

                return [
                    'filtro' => ['key' => $columna, 'type' => 'number', $operadores[$operador] => $numero],
                    'texto'  => $textos[$operador],
                ];

            case 'date':

                $fecha = AgendaTareaHelper::parsear_fecha(is_string($valor) ? trim($valor) : $valor);

                if (is_null($fecha)) {

                    return RespuestaDeCargaIa::error('La fecha de ' . $etiqueta . ' tiene que venir como AAAA-MM-DD.');
                }

                $legible = $fecha->format('d/m/Y');

                /*
                 * desde / hasta son inclusivos y ColumnFiltersHelper solo compara con <, = y > por
                 * whereDate: se corre un día para cada lado (ver el docblock de la clase).
                 */
                switch ($operador) {

                    case 'desde':
                        return [
                            'filtro' => ['key' => $columna, 'type' => 'date', 'mayor_que' => $fecha->copy()->subDay()->format('Y-m-d')],
                            'texto'  => 'desde el ' . $legible,
                        ];

                    case 'hasta':
                        return [
                            'filtro' => ['key' => $columna, 'type' => 'date', 'menor_que' => $fecha->copy()->addDay()->format('Y-m-d')],
                            'texto'  => 'hasta el ' . $legible,
                        ];

                    case 'mayor':
                        return [
                            'filtro' => ['key' => $columna, 'type' => 'date', 'mayor_que' => $fecha->format('Y-m-d')],
                            'texto'  => 'después del ' . $legible,
                        ];

                    case 'menor':
                        return [
                            'filtro' => ['key' => $columna, 'type' => 'date', 'menor_que' => $fecha->format('Y-m-d')],
                            'texto'  => 'antes del ' . $legible,
                        ];
                }

                return [
                    'filtro' => ['key' => $columna, 'type' => 'date', 'igual_que' => $fecha->format('Y-m-d')],
                    'texto'  => 'el ' . $legible,
                ];

            case 'checkbox':

                $activo = self::si_o_no($valor);

                if (is_null($activo)) {

                    return RespuestaDeCargaIa::error('El valor de ' . $etiqueta . ' tiene que ser si o no.');
                }

                return [
                    'filtro' => ['key' => $columna, 'type' => 'checkbox', 'checkbox' => $activo ? 1 : 0],
                    'texto'  => $activo ? 'sí' : 'no',
                ];
        }

        return RespuestaDeCargaIa::error('No sé filtrar por ' . $etiqueta . '.');
    }

    /**
     * Resuelve por NOMBRE un proveedor, una categoría, una subcategoría o una marca del dueño.
     *
     * Búsqueda por `name LIKE %nombre%`: si encaja uno solo, es ese; si encajan varios y uno coincide
     * exacto (sin distinguir mayúsculas), es ese; si encajan varios sin coincidencia exacta, vuelve
     * `faltan` con las opciones para que la IA pregunte cuál; si no encaja ninguno, `error`. Nunca se
     * adivina: elegir "Bulonera Norte" cuando la persona dijo "Bulonera" y también existe "Bulonera
     * Sur" sería filtrar (y actualizar en lote) los artículos equivocados.
     *
     * La usa también el constructor B para resolver categorías por nombre (contrato §6).
     *
     * @param  int  $owner_id
     * @param  string  $relacion  proveedor | categoria | sub_categoria | marca
     * @param  string  $nombre
     * @return \Illuminate\Database\Eloquent\Model|array  El modelo, o la respuesta negativa.
     */
    public static function resolver_relacion($owner_id, $relacion, $nombre)
    {
        $relacion = (string) $relacion;

        if (!isset(self::RELACIONES[$relacion])) {

            return RespuestaDeCargaIa::error(
                'No conozco la relación "' . $relacion . '". Valen: ' . implode(', ', array_keys(self::RELACIONES)) . '.'
            );
        }

        $definicion = self::RELACIONES[$relacion];
        $etiqueta_en_minuscula = mb_strtolower($definicion['etiqueta']);
        $nombre = trim((string) $nombre);

        if ($nombre === '') {

            return RespuestaDeCargaIa::faltan(['el nombre de ' . $etiqueta_en_minuscula]);
        }

        $modelo = $definicion['modelo'];

        /*
         * Por palabras sueltas, con la frase entera primero (misión asistente-altas-y-conversacion,
         * 16/10/2026): "ferretería sur" encuentra "Ferretería del Sur". Los comodines los escapa
         * BusquedaPorPalabrasIaHelper. Lo que encaja solo por palabras se toma sin preguntar únicamente
         * si es el único y es seguro: una masiva sobre el proveedor equivocado toca cientos de precios.
         */
        $query = $modelo::where('user_id', $owner_id);

        BusquedaPorPalabrasIaHelper::filtrar($query, $nombre, ['name']);
        BusquedaPorPalabrasIaHelper::ordenar_por_frase($query, $nombre, ['name']);

        $candidatos = $query->orderBy('name')
                                ->limit(20)
                                ->get();

        if (count($candidatos) === 0) {

            return RespuestaDeCargaIa::error(
                'No encontré ' . $definicion['articulo'] . ' que se llame "' . $nombre . '".'
            );
        }

        $con_la_frase = BusquedaPorPalabrasIaHelper::por_frase($candidatos, $nombre, ['name']);

        if (count($con_la_frase)) {

            $candidatos = collect($con_la_frase);
        }

        if (count($candidatos) === 1
            && (count($con_la_frase) || BusquedaPorPalabrasIaHelper::es_seguro((string) $candidatos[0]->name, $nombre))) {

            return $candidatos[0];
        }

        foreach ($candidatos as $candidato) {

            if (mb_strtolower(trim((string) $candidato->name)) === mb_strtolower($nombre)) {

                return $candidato;
            }
        }

        $opciones = [];

        foreach ($candidatos as $candidato) {

            $opciones[] = ['id' => (int) $candidato->id, 'nombre' => (string) $candidato->name];
        }

        return RespuestaDeCargaIa::faltan(
            ['cuál ' . $etiqueta_en_minuscula . ': hay varias que encajan con "' . $nombre . '"'],
            [$definicion['plural'] => $opciones]
        );
    }

    /**
     * La query de artículos activos del dueño con los filtros aplicados. Sin Auth (ver la clase).
     *
     * @param  int  $owner_id
     * @param  array  $filter_form  Lo que devolvió traducir().
     * @param  string|null  $imagen  null | 'en_blanco' | 'no_en_blanco'
     * @param  array  $por_lista  Las condiciones por lista de precio de traducir() (E2). Opcional.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function query($owner_id, array $filter_form, $imagen = null, array $por_lista = [])
    {
        $query = Article::where('user_id', (int) $owner_id)->where('status', 'active');

        $aplicado = ColumnFiltersHelper::apply($query, $filter_form, 'article', Article::class);

        $query = $aplicado['models'];

        if ($imagen === self::IMAGEN_EN_BLANCO) {

            $query = $query->whereDoesntHave('images');

        } elseif ($imagen === self::IMAGEN_NO_EN_BLANCO) {

            $query = $query->whereHas('images');
        }

        // El margen y el precio fijo por lista de precio (misión agente-mejoras-uso-real, E2).
        foreach ($por_lista as $condicion) {

            self::aplicar_por_lista($query, $condicion);
        }

        return $query;
    }

    /**
     * Cuántos artículos cumplen el filtro.
     *
     * @param  int  $owner_id
     * @param  array  $filter_form
     * @param  string|null  $imagen
     * @param  array  $por_lista  Ver query(). Opcional.
     * @return int
     */
    public static function contar($owner_id, array $filter_form, $imagen = null, array $por_lista = [])
    {
        return (int) self::query($owner_id, $filter_form, $imagen, $por_lista)->count();
    }

    /**
     * Los ids de los artículos que cumplen el filtro, en el orden pedido y hasta el límite.
     *
     * "Los primeros N del inventario" son los N MÁS VIEJOS por fecha de alta: `created_at ASC` con
     * desempate por `id ASC` (los catálogos importados en lote comparten created_at, y sin el
     * desempate el LIMIT podría devolver artículos distintos en dos llamadas). Es lo contrario del
     * listado, que muestra los más nuevos primero: por eso el orden es explícito y no se hereda.
     *
     * @param  int  $owner_id
     * @param  array  $filter_form
     * @param  string|null  $imagen
     * @param  string|null  $orden  primeros_creados (default) | ultimos_creados
     * @param  int  $limite  0 = sin límite.
     * @return array<int, int>
     */
    public static function ids($owner_id, array $filter_form, $imagen = null, $orden = null, $limite = 0)
    {
        $query = self::ordenar(self::query($owner_id, $filter_form, $imagen), $orden);

        $limite = (int) $limite;

        if ($limite > 0) {

            $query = $query->limit($limite);
        }

        $ids = [];

        foreach ($query->pluck('articles.id') as $id) {

            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * Los primeros nombres que alcanza el filtro, en el orden pedido, para que la IA le muestre a la
     * persona de qué artículos está hablando.
     *
     * @param  int  $owner_id
     * @param  array  $filter_form
     * @param  string|null  $imagen
     * @param  string|null  $orden
     * @param  array  $por_lista  Ver query(). Opcional.
     * @return array<int, string>
     */
    public static function muestra($owner_id, array $filter_form, $imagen = null, $orden = null, array $por_lista = [])
    {
        $nombres = [];

        $articulos = self::ordenar(self::query($owner_id, $filter_form, $imagen, $por_lista), $orden)
                            ->limit(self::TAMANO_DE_MUESTRA)
                            ->get(['articles.id', 'articles.name']);

        foreach ($articulos as $articulo) {

            $nombres[] = (string) $articulo->name;
        }

        return $nombres;
    }

    /**
     * true si el orden es uno de los dos válidos.
     *
     * @param  mixed  $orden
     * @return bool
     */
    public static function orden_valido($orden)
    {
        return in_array((string) $orden, [self::ORDEN_PRIMEROS_CREADOS, self::ORDEN_ULTIMOS_CREADOS], true);
    }

    /**
     * Herramienta contar_articulos_por_filtro (contrato §1.3): cuenta, aplica el límite pedido y
     * arma la respuesta que lee la IA.
     *
     * @param  int  $owner_id
     * @param  array  $input  filtros, solo_sin_imagen, orden, limite
     * @return array
     */
    public static function contar_para_la_ia($owner_id, array $input)
    {
        $parametros = self::parametros_de_seleccion($input, false);

        if (RespuestaDeCargaIa::es_negativa($parametros)) {

            return $parametros;
        }

        // Contar es lo único que acepta los filtros por lista de precio (E2).
        $traducido = self::traducir($parametros['filtros'], $owner_id, true);

        if (RespuestaDeCargaIa::es_negativa($traducido)) {

            return $traducido;
        }

        $imagen = self::imagen_efectiva($traducido['imagen'], $parametros['solo_sin_imagen']);

        $total = self::contar($owner_id, $traducido['filter_form'], $imagen, $traducido['por_lista']);

        $a_procesar = $parametros['limite'] > 0 ? min($total, $parametros['limite']) : $total;

        $legibles = $traducido['legibles'];

        if ($parametros['solo_sin_imagen'] && is_null($traducido['imagen'])) {

            $legibles[] = 'Imagen: sin imagen';
        }

        $respuesta = [
            'ok'               => true,
            'total'            => $total,
            'a_procesar'       => $a_procesar,
            'filtros_legibles' => $legibles,
            'muestra'          => self::muestra($owner_id, $traducido['filter_form'], $imagen, $parametros['orden'], $traducido['por_lista']),
            'nota'             => 'total es cuántos cumplen el filtro; a_procesar aplica el límite pedido.',
        ];

        /*
         * Con un filtro por lista de precio, el conteo POR LISTA y una muestra con el detalle de cada
         * artículo en cada lista (misión agente-mejoras-uso-real, E2): es lo que el agente necesita
         * para decir «19 en LISTA GENERAL (12 con stock)» en vez de nombrar uno de la muestra.
         */
        if (count($traducido['por_lista'])) {

            $respuesta['por_lista'] = self::conteo_por_lista($owner_id, $traducido, $imagen);
            $respuesta['muestra_por_lista'] = self::muestra_por_lista($owner_id, $traducido, $imagen);
            $respuesta['nota_por_lista'] = 'por_lista dice, para cada lista, cuántos artículos cumplen el filtro en ESA lista, cuántos tienen stock (con_stock) y en cuántos el precio de esa lista está fijado a mano (con_precio_fijo: el margen no lo mueve). Contestá con esos números, por lista: nunca con uno solo de la muestra. muestra_por_lista son los primeros ' . self::TAMANO_DE_MUESTRA_POR_LISTA . ' (los que tienen stock primero), con su margen, su precio y si es fijo en cada lista.';
        }

        return $respuesta;
    }

    /**
     * El conteo por lista de un filtro por lista de precio: para cada lista que nombra alguna
     * condición (con "cualquiera", todas las del negocio), cuántos artículos cumplen el filtro EN ESA
     * LISTA, cuántos tienen stock y en cuántos el precio de esa lista está fijado a mano. Tres
     * consultas por lista; un negocio tiene dos o tres.
     *
     * Para la fila de una lista, las condiciones que la incluyen se acotan a ella ("margen 0 en
     * cualquier lista" pasa a "margen 0 en ESTA lista"); las que nombran otra lista quedan como están.
     *
     * @param  int  $owner_id
     * @param  array  $traducido  traducir().
     * @param  string|null  $imagen
     * @return array<int, array{lista: string, articulos: int, con_stock: int, con_precio_fijo: int}>
     */
    protected static function conteo_por_lista($owner_id, array $traducido, $imagen)
    {
        $filas = [];

        foreach (self::listas_de_las_condiciones($traducido['por_lista']) as $lista) {

            $acotadas = self::acotar_a_la_lista($traducido['por_lista'], $lista['price_type_id']);

            $base = self::query($owner_id, $traducido['filter_form'], $imagen, $acotadas);

            $id = (int) $lista['price_type_id'];

            $filas[] = [
                'lista'           => $lista['nombre'],
                'articulos'       => (int) (clone $base)->count(),
                'con_stock'       => (int) (clone $base)->where('articles.stock', '>', 0)->count(),
                'con_precio_fijo' => (int) (clone $base)->whereExists(function ($sub) use ($id) {
                    $sub->selectRaw('1')
                        ->from('article_price_type as apt_conteo')
                        ->whereColumn('apt_conteo.article_id', 'articles.id')
                        ->where('apt_conteo.price_type_id', $id)
                        ->where('apt_conteo.setear_precio_final', 1);
                })->count(),
            ];
        }

        return $filas;
    }

    /**
     * Hasta TAMANO_DE_MUESTRA_POR_LISTA artículos que cumplen el filtro (los que tienen stock
     * primero), con su `article_id`, su stock y, para cada lista de las condiciones, su margen
     * efectivo, su precio y si es fijo. Dos consultas.
     *
     * @param  int  $owner_id
     * @param  array  $traducido
     * @param  string|null  $imagen
     * @return array<int, array<string, mixed>>
     */
    protected static function muestra_por_lista($owner_id, array $traducido, $imagen)
    {
        $articulos = self::query($owner_id, $traducido['filter_form'], $imagen, $traducido['por_lista'])
                        ->orderByRaw('CASE WHEN articles.stock > 0 THEN 0 ELSE 1 END')
                        ->orderBy('articles.name')
                        ->orderBy('articles.id')
                        ->limit(self::TAMANO_DE_MUESTRA_POR_LISTA)
                        ->get(['articles.id', 'articles.name', 'articles.stock']);

        if (!count($articulos)) {

            return [];
        }

        $listas = self::listas_de_las_condiciones($traducido['por_lista']);

        // Las filas del pivote de esos artículos en esas listas, en UNA consulta.
        $pivotes = [];

        $filas = DB::table('article_price_type')
                    ->whereIn('article_id', $articulos->pluck('id')->all())
                    ->whereIn('price_type_id', array_column($listas, 'price_type_id'))
                    ->orderBy('id')
                    ->get(['article_id', 'price_type_id', 'percentage', 'final_price', 'setear_precio_final']);

        foreach ($filas as $fila) {

            $clave = (int) $fila->article_id . '-' . (int) $fila->price_type_id;

            // Con filas repetidas manda la primera, como find() en el cálculo del precio.
            if (!isset($pivotes[$clave])) {

                $pivotes[$clave] = $fila;
            }
        }

        $muestra = [];

        foreach ($articulos as $articulo) {

            $en_listas = [];

            foreach ($listas as $lista) {

                $clave = (int) $articulo->id . '-' . (int) $lista['price_type_id'];
                $fila = isset($pivotes[$clave]) ? $pivotes[$clave] : null;

                $margen = !is_null($fila) && !is_null($fila->percentage)
                    ? (float) $fila->percentage
                    : (is_null($lista['margen']) ? 0.0 : (float) $lista['margen']);

                $en_listas[] = [
                    'lista'       => $lista['nombre'],
                    'margen'      => round($margen, 2),
                    'precio'      => !is_null($fila) && !is_null($fila->final_price) ? round((float) $fila->final_price, 2) : null,
                    'precio_fijo' => !is_null($fila) && (int) $fila->setear_precio_final === 1,
                ];
            }

            $muestra[] = [
                'article_id' => (int) $articulo->id,
                'nombre'     => (string) $articulo->name,
                'stock'      => is_null($articulo->stock) ? null : (float) $articulo->stock,
                'listas'     => $en_listas,
            ];
        }

        return $muestra;
    }

    /**
     * Las listas (sin repetir, en el orden en que aparecen) que nombran las condiciones por lista.
     *
     * @param  array  $por_lista
     * @return array<int, array{price_type_id: int, nombre: string, margen: float|null}>
     */
    protected static function listas_de_las_condiciones(array $por_lista)
    {
        $listas = [];

        foreach ($por_lista as $condicion) {

            foreach ($condicion['listas'] as $lista) {

                $listas[(int) $lista['price_type_id']] = $lista;
            }
        }

        return array_values($listas);
    }

    /**
     * Las condiciones por lista acotadas a UNA lista: las que la incluyen pasan a mirar solo esa; las
     * que no la incluyen (nombran otra) quedan como están.
     *
     * @param  array  $por_lista
     * @param  int  $price_type_id
     * @return array
     */
    protected static function acotar_a_la_lista(array $por_lista, $price_type_id)
    {
        $acotadas = [];

        foreach ($por_lista as $condicion) {

            $solo_esta = [];

            foreach ($condicion['listas'] as $lista) {

                if ((int) $lista['price_type_id'] === (int) $price_type_id) {

                    $solo_esta[] = $lista;
                }
            }

            if (count($solo_esta)) {

                $condicion['listas'] = $solo_esta;
            }

            $acotadas[] = $condicion;
        }

        return $acotadas;
    }

    /**
     * Lee de una sola forma los parámetros de selección que comparten contar_articulos_por_filtro y
     * proponer_imagenes_para_articulos: filtros, solo_sin_imagen, orden y limite.
     *
     * @param  array  $input
     * @param  bool  $solo_sin_imagen_por_defecto  true en las imágenes (se saltean los que ya tienen).
     * @return array  ['filtros' => array, 'solo_sin_imagen' => bool, 'orden' => string, 'limite' => int] o negativa.
     */
    public static function parametros_de_seleccion(array $input, $solo_sin_imagen_por_defecto)
    {
        $filtros = EntradaDeCargaIa::valor($input, 'filtros');

        if (!is_null($filtros) && !is_array($filtros)) {

            return RespuestaDeCargaIa::error('filtros tiene que ser una lista de {campo, operador, valor}.');
        }

        $solo_sin_imagen = EntradaDeCargaIa::valor($input, 'solo_sin_imagen');

        if (is_null($solo_sin_imagen)) {

            $solo_sin_imagen = (bool) $solo_sin_imagen_por_defecto;

        } else {

            $solo_sin_imagen = filter_var($solo_sin_imagen, FILTER_VALIDATE_BOOLEAN);
        }

        $orden = EntradaDeCargaIa::texto($input, 'orden');

        if ($orden === '') {

            $orden = self::ORDEN_PRIMEROS_CREADOS;
        }

        if (!self::orden_valido($orden)) {

            return RespuestaDeCargaIa::error(
                'El orden tiene que ser ' . self::ORDEN_PRIMEROS_CREADOS . ' o ' . self::ORDEN_ULTIMOS_CREADOS . '.'
            );
        }

        $limite = EntradaDeCargaIa::valor($input, 'limite');

        if (!EntradaDeCargaIa::vacio($limite) && (!is_numeric($limite) || (int) $limite < 0)) {

            return RespuestaDeCargaIa::error('El límite tiene que ser un número entero mayor o igual a 0.');
        }

        return [
            'filtros'         => is_array($filtros) ? array_values($filtros) : [],
            'solo_sin_imagen' => $solo_sin_imagen,
            'orden'           => $orden,
            'limite'          => EntradaDeCargaIa::vacio($limite) ? 0 : (int) $limite,
        ];
    }

    /**
     * El filtro de imagen que efectivamente se aplica: el que la IA puso explícito en `filtros`
     * manda; si no puso ninguno y pidió solo_sin_imagen, se saltean los que ya tienen imagen.
     *
     * @param  string|null  $imagen_del_filtro
     * @param  bool  $solo_sin_imagen
     * @return string|null
     */
    public static function imagen_efectiva($imagen_del_filtro, $solo_sin_imagen)
    {
        if (!is_null($imagen_del_filtro)) {

            return $imagen_del_filtro;
        }

        return $solo_sin_imagen ? self::IMAGEN_EN_BLANCO : null;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string|null  $orden
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function ordenar($query, $orden)
    {
        $direccion = (string) $orden === self::ORDEN_ULTIMOS_CREADOS ? 'DESC' : 'ASC';

        return $query->orderBy('articles.created_at', $direccion)->orderBy('articles.id', $direccion);
    }

    /**
     * "si" / "no" en las formas en que lo puede escribir la IA. null si no es ninguna.
     *
     * @param  mixed  $valor
     * @return bool|null
     */
    protected static function si_o_no($valor)
    {
        if (is_bool($valor)) {

            return $valor;
        }

        $texto = strtr(mb_strtolower(trim((string) $valor)), ['í' => 'i']);

        if (in_array($texto, ['si', 'true', '1', 'activado', 'activo'], true)) {

            return true;
        }

        if (in_array($texto, ['no', 'false', '0', 'desactivado', 'inactivo'], true)) {

            return false;
        }

        return null;
    }
}
