<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\agenda\AgendaTareaHelper;
use App\Http\Controllers\Helpers\ColumnFiltersHelper;
use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Provider;
use App\Models\SubCategory;

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
    ];

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
    ];

    /**
     * Traduce los filtros de la IA al filter_form de ColumnFiltersHelper.
     *
     * @param  array  $filtros  [{campo, operador, valor}] tal como los mandó la IA.
     * @param  int  $owner_id
     * @return array  ['filter_form' => [...], 'legibles' => ['Proveedor: Bulonera', ...],
     *                 'renglones' => [{etiqueta, valor}], 'imagen' => null|'en_blanco'|'no_en_blanco']
     *                 o la respuesta negativa (RespuestaDeCargaIa) si algo no se puede interpretar.
     */
    public static function traducir(array $filtros, $owner_id)
    {
        $filter_form = [];
        $legibles = [];
        $renglones = [];
        $imagen = null;

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
        ];
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

        // Los comodines del LIKE se escapan: un nombre con "%" o "_" tiene que buscarse literal.
        $candidatos = $modelo::where('user_id', $owner_id)
                                ->where('name', 'LIKE', '%' . addcslashes($nombre, '%_\\') . '%')
                                ->orderBy('name')
                                ->limit(20)
                                ->get();

        if (count($candidatos) === 0) {

            return RespuestaDeCargaIa::error(
                'No encontré ' . $definicion['articulo'] . ' que se llame "' . $nombre . '".'
            );
        }

        if (count($candidatos) === 1) {

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
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function query($owner_id, array $filter_form, $imagen = null)
    {
        $query = Article::where('user_id', (int) $owner_id)->where('status', 'active');

        $aplicado = ColumnFiltersHelper::apply($query, $filter_form, 'article', Article::class);

        $query = $aplicado['models'];

        if ($imagen === self::IMAGEN_EN_BLANCO) {

            $query = $query->whereDoesntHave('images');

        } elseif ($imagen === self::IMAGEN_NO_EN_BLANCO) {

            $query = $query->whereHas('images');
        }

        return $query;
    }

    /**
     * Cuántos artículos cumplen el filtro.
     *
     * @param  int  $owner_id
     * @param  array  $filter_form
     * @param  string|null  $imagen
     * @return int
     */
    public static function contar($owner_id, array $filter_form, $imagen = null)
    {
        return (int) self::query($owner_id, $filter_form, $imagen)->count();
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
     * @return array<int, string>
     */
    public static function muestra($owner_id, array $filter_form, $imagen = null, $orden = null)
    {
        $nombres = [];

        $articulos = self::ordenar(self::query($owner_id, $filter_form, $imagen), $orden)
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

        $traducido = self::traducir($parametros['filtros'], $owner_id);

        if (RespuestaDeCargaIa::es_negativa($traducido)) {

            return $traducido;
        }

        $imagen = self::imagen_efectiva($traducido['imagen'], $parametros['solo_sin_imagen']);

        $total = self::contar($owner_id, $traducido['filter_form'], $imagen);

        $a_procesar = $parametros['limite'] > 0 ? min($total, $parametros['limite']) : $total;

        $legibles = $traducido['legibles'];

        if ($parametros['solo_sin_imagen'] && is_null($traducido['imagen'])) {

            $legibles[] = 'Imagen: sin imagen';
        }

        return [
            'ok'               => true,
            'total'            => $total,
            'a_procesar'       => $a_procesar,
            'filtros_legibles' => $legibles,
            'muestra'          => self::muestra($owner_id, $traducido['filter_form'], $imagen, $parametros['orden']),
            'nota'             => 'total es cuántos cumplen el filtro; a_procesar aplica el límite pedido.',
        ];
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
