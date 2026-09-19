<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\asistente_ia\RespuestaDeCargaIa;
use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;
use App\Services\PdfColumnService;

/**
 * Las columnas de un diseño de PDF (pdf_column_profiles + pivot pdf_column_option_profile), fuera
 * del controller (misión asistente-masivas-imagenes-y-remito, 19/9/2026, plan §4.6).
 *
 * `ancho_disponible_mm()` es LA implementación de la regla del ancho útil: el controller del ABM
 * delega acá, así que el asistente y la pantalla no pueden discrepar sobre cuánto entra en la
 * hoja. `aplicar_cambios()` arma el pivot nuevo SIN persistir (para la tarjeta) y `persistir()`
 * lo escribe igual que el seeder helper (sync del pivot con los seis campos + `columns`).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class PdfColumnProfileHelper
{
    /** Ancho mínimo al que se achica una columna con ajuste de texto (plan §4.6). */
    const ANCHO_MINIMO_CON_AJUSTE_MM = 30;

    /** Posiciones válidas de un agregado. */
    const POSICIONES = ['al_final', 'al_principio', 'despues_de', 'antes_de'];

    /**
     * Ancho útil disponible para las columnas visibles, en mm: el imprimible menos los dos
     * márgenes laterales. A4 con 210 imprimibles y 5 de margen → 200.
     *
     * @param  mixed $printable_width_mm
     * @param  mixed $margin_mm
     * @return int
     */
    public static function ancho_disponible_mm($printable_width_mm, $margin_mm)
    {
        $disponible = (int) $printable_width_mm - ((int) $margin_mm * 2);

        return $disponible > 0 ? $disponible : 0;
    }

    /**
     * El catálogo de columnas de un tipo de diseño ('sale' | 'article'), sincronizado desde el
     * código (PdfColumnService::get_options ya lo hace).
     *
     * @param  string $model_name
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function catalogo($model_name)
    {
        return PdfColumnService::get_options($model_name);
    }

    /**
     * Las columnas VISIBLES del perfil, en el orden del PDF, con lo que la tarjeta y la
     * consulta necesitan mostrar.
     *
     * @param  \App\Models\PdfColumnProfile $profile
     * @return array  [['option_id', 'nombre', 'label', 'ancho_mm', 'ajusta_texto', 'default_width', 'allow_wrap_content', 'font_size', 'text_align'], ...]
     */
    public static function columnas_visibles(PdfColumnProfile $profile)
    {
        $profile->loadMissing('pdf_column_options');

        $filas = [];

        foreach ($profile->pdf_column_options as $option) {
            if (!self::pivot_visible($option->pivot)) {
                continue;
            }

            $filas[] = self::fila_desde_pivot($option);
        }

        usort($filas, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return array_values($filas);
    }

    /**
     * Suma de anchos de las columnas visibles.
     *
     * @param  \App\Models\PdfColumnProfile $profile
     * @return int
     */
    public static function suma_de_anchos_mm(PdfColumnProfile $profile)
    {
        $suma = 0;

        foreach (self::columnas_visibles($profile) as $fila) {
            $suma += (int) $fila['ancho_mm'];
        }

        return $suma;
    }

    /**
     * Resuelve una columna por nombre, sin distinguir mayúsculas ni acentos, contra el catálogo
     * del tipo de diseño. Se mira `name` (lo que ve el ABM) y también `label` (el encabezado
     * impreso, "Cod. barras").
     *
     * Tres escalones, y en cada uno gana la unicidad: (1) igualdad exacta normalizada; (2) el
     * término como PALABRA entera ("categoría" está entera en "Categoria del articulo" y NO en
     * "Subcategoria del articulo", así que no es ambigua); (3) contenido a secas. Si en el
     * escalón donde hubo coincidencias hay más de una → `faltan` con las opciones; ninguna en
     * ningún escalón → error.
     *
     * @param  string $model_name
     * @param  string $nombre
     * @return \App\Models\PdfColumnOption|array  La opción, o la respuesta negativa.
     */
    public static function resolver_columna($model_name, $nombre)
    {
        $termino = self::normalizar(trim((string) $nombre));

        if ($termino === '') {
            return RespuestaDeCargaIa::error('Decime el nombre de la columna.');
        }

        $catalogo = self::catalogo($model_name);

        $exactas = [];
        $por_palabra = [];
        $contenidas = [];

        foreach ($catalogo as $option) {
            $textos = [self::normalizar((string) $option->name), self::normalizar((string) $option->label)];

            foreach ($textos as $texto) {
                if ($texto === $termino) {
                    $exactas[$option->id] = $option;
                }

                if (preg_match('/(^|[^a-z0-9])'.preg_quote($termino, '/').'($|[^a-z0-9])/', $texto)) {
                    $por_palabra[$option->id] = $option;
                }

                if (strpos($texto, $termino) !== false) {
                    $contenidas[$option->id] = $option;
                }
            }
        }

        foreach ([$exactas, $por_palabra, $contenidas] as $escalon) {
            if (count($escalon) === 1) {
                return array_values($escalon)[0];
            }

            if (count($escalon) > 1) {
                $opciones = [];

                foreach ($escalon as $option) {
                    $opciones[] = ['id' => (int) $option->id, 'nombre' => (string) $option->name];
                }

                return RespuestaDeCargaIa::faltan(['a cuál columna te referís con "'.trim((string) $nombre).'"'], ['columnas' => $opciones]);
            }
        }

        return RespuestaDeCargaIa::error('No hay ninguna columna que se llame "'.trim((string) $nombre).'" para este tipo de diseño.');
    }

    /**
     * Arma el pivot nuevo del perfil SIN persistir: quita, agrega, fija anchos, renumera y,
     * si no entra en la hoja, achica lo que se puede (plan §4.6).
     *
     * Achique, en este orden y hasta cubrir el faltante:
     *   (1) las visibles con ajuste de texto, de mayor ancho a menor, hasta max(30, default_width);
     *   (2) las visibles cuyo ancho supera su default, hasta su default.
     * Las columnas cuyo ancho la persona fijó explícitamente en este pedido no se tocan. Si
     * después de eso sigue sobrando, `error` dice qué sacar.
     *
     * @param  \App\Models\PdfColumnProfile $profile
     * @param  array $agregar  [['option_id' => int, 'posicion' => string, 'referencia_option_id' => int|null, 'ancho_mm' => int|null], ...]
     * @param  array $quitar   [option_id, ...]
     * @param  array $anchos   [['option_id' => int, 'ancho_mm' => int], ...]
     * @return array {
     *     pivots:     array  option_id => [visible, order, width, wrap_content, font_size, text_align] (TODO el catálogo)
     *     visibles:   array  las visibles en orden, con nombre, label y ancho_mm
     *     ajustes:    array  [['columna' => nombre, 'de' => int, 'a' => int], ...]
     *     suma:       int
     *     disponible: int
     *     error:      string|null
     * }
     */
    public static function aplicar_cambios(PdfColumnProfile $profile, array $agregar, array $quitar, array $anchos)
    {
        $catalogo = self::catalogo($profile->model_name);

        /** Catálogo indexado por id, para leer default_width / allow_wrap_content. */
        $opciones = [];

        foreach ($catalogo as $option) {
            $opciones[(int) $option->id] = $option;
        }

        $visibles = self::columnas_visibles($profile);

        /** option_id => true de las que la persona fijó en este pedido: no se achican. */
        $fijadas = [];

        /* Quitar. */
        foreach ($quitar as $option_id) {
            $visibles = array_values(array_filter($visibles, function ($fila) use ($option_id) {
                return (int) $fila['option_id'] !== (int) $option_id;
            }));
        }

        /* Agregar (una ya visible se mueve a la posición pedida). */
        foreach ($agregar as $pedido) {
            $option_id = isset($pedido['option_id']) ? (int) $pedido['option_id'] : 0;

            if (!isset($opciones[$option_id])) {
                return self::resultado_con_error($visibles, [], $profile, 'Esa columna no existe para este tipo de diseño.');
            }

            $option = $opciones[$option_id];

            $visibles = array_values(array_filter($visibles, function ($fila) use ($option_id) {
                return (int) $fila['option_id'] !== $option_id;
            }));

            $ancho_pedido = isset($pedido['ancho_mm']) && (int) $pedido['ancho_mm'] > 0 ? (int) $pedido['ancho_mm'] : null;

            $nueva = [
                'option_id'          => $option_id,
                'nombre'             => (string) $option->name,
                'label'              => (string) $option->label,
                'ancho_mm'           => is_null($ancho_pedido) ? (int) $option->default_width : $ancho_pedido,
                'ajusta_texto'       => (bool) $option->allow_wrap_content,
                'default_width'      => (int) $option->default_width,
                'allow_wrap_content' => (bool) $option->allow_wrap_content,
                'font_size'          => null,
                'text_align'         => null,
                'order'              => 0,
            ];

            if (!is_null($ancho_pedido)) {
                $fijadas[$option_id] = true;
            }

            $posicion   = isset($pedido['posicion']) && in_array($pedido['posicion'], self::POSICIONES, true) ? $pedido['posicion'] : 'al_final';
            $referencia = isset($pedido['referencia_option_id']) ? (int) $pedido['referencia_option_id'] : 0;

            if ($posicion === 'al_principio') {
                array_unshift($visibles, $nueva);
            } elseif ($posicion === 'despues_de' || $posicion === 'antes_de') {
                $indice = self::indice_de($visibles, $referencia);

                if (is_null($indice)) {
                    $nombre_referencia = isset($opciones[$referencia]) ? (string) $opciones[$referencia]->name : 'esa columna';

                    return self::resultado_con_error($visibles, [], $profile, 'La columna '.$nombre_referencia.' no está en el diseño, así que no puedo poner '.$nueva['nombre'].' '.($posicion === 'despues_de' ? 'después' : 'antes').' de ella.');
                }

                array_splice($visibles, $posicion === 'despues_de' ? $indice + 1 : $indice, 0, [$nueva]);
            } else {
                $visibles[] = $nueva;
            }
        }

        /* Anchos fijados. */
        foreach ($anchos as $pedido) {
            $option_id = isset($pedido['option_id']) ? (int) $pedido['option_id'] : 0;
            $ancho     = isset($pedido['ancho_mm']) ? (int) $pedido['ancho_mm'] : 0;

            $indice = self::indice_de($visibles, $option_id);

            if (is_null($indice)) {
                $nombre = isset($opciones[$option_id]) ? (string) $opciones[$option_id]->name : 'Esa columna';

                return self::resultado_con_error($visibles, [], $profile, $nombre.' no está en el diseño: agregala antes de darle un ancho.');
            }

            if ($ancho <= 0) {
                return self::resultado_con_error($visibles, [], $profile, 'El ancho de '.$visibles[$indice]['nombre'].' tiene que ser un número de milímetros mayor a cero.');
            }

            $visibles[$indice]['ancho_mm'] = $ancho;
            $fijadas[$option_id] = true;
        }

        /* Achique si no entra. */
        $disponible = self::ancho_disponible_mm($profile->printable_width_mm, $profile->margin_mm);
        $ajustes    = [];
        $faltante   = self::suma($visibles) - $disponible;

        if ($faltante > 0) {
            /* (1) Con ajuste de texto, de mayor a menor ancho, hasta max(30, default). */
            $candidatas = [];

            foreach ($visibles as $indice => $fila) {
                if ($fila['ajusta_texto'] && !isset($fijadas[(int) $fila['option_id']])) {
                    $candidatas[] = $indice;
                }
            }

            usort($candidatas, function ($a, $b) use ($visibles) {
                return $visibles[$b]['ancho_mm'] <=> $visibles[$a]['ancho_mm'];
            });

            foreach ($candidatas as $indice) {
                if ($faltante <= 0) {
                    break;
                }

                $piso = max(self::ANCHO_MINIMO_CON_AJUSTE_MM, (int) $visibles[$indice]['default_width']);

                $faltante = self::achicar($visibles, $indice, $piso, $faltante, $ajustes);
            }

            /* (2) Las que superan su default, hasta su default. */
            if ($faltante > 0) {
                $candidatas = [];

                foreach ($visibles as $indice => $fila) {
                    if ((int) $fila['ancho_mm'] > (int) $fila['default_width'] && !isset($fijadas[(int) $fila['option_id']])) {
                        $candidatas[] = $indice;
                    }
                }

                usort($candidatas, function ($a, $b) use ($visibles) {
                    return $visibles[$b]['ancho_mm'] <=> $visibles[$a]['ancho_mm'];
                });

                foreach ($candidatas as $indice) {
                    if ($faltante <= 0) {
                        break;
                    }

                    $faltante = self::achicar($visibles, $indice, (int) $visibles[$indice]['default_width'], $faltante, $ajustes);
                }
            }

            if ($faltante > 0) {
                $mas_ancha = null;

                foreach ($visibles as $fila) {
                    if (is_null($mas_ancha) || (int) $fila['ancho_mm'] > (int) $mas_ancha['ancho_mm']) {
                        $mas_ancha = $fila;
                    }
                }

                return self::resultado_con_error(
                    $visibles,
                    $ajustes,
                    $profile,
                    'No entra: las columnas suman '.self::suma($visibles).' mm y la hoja tiene '.$disponible.' mm. Sacá una columna o pedime un ancho más chico para '.(is_null($mas_ancha) ? 'alguna' : $mas_ancha['nombre']).'.'
                );
            }
        }

        /* Renumerar: las visibles 0..n−1, las demás después, en el orden del catálogo (como el seeder). */
        $pivots = [];

        foreach ($visibles as $orden => $fila) {
            $visibles[$orden]['order'] = $orden;

            $pivots[(int) $fila['option_id']] = [
                'visible'      => true,
                'order'        => $orden,
                'width'        => (int) $fila['ancho_mm'],
                'wrap_content' => (bool) $fila['ajusta_texto'],
                'font_size'    => $fila['font_size'],
                'text_align'   => $fila['text_align'],
            ];
        }

        $cantidad_visibles = count($visibles);

        /** Anchos que las no visibles ya tenían guardados (se conservan por si vuelven). */
        $anchos_guardados = [];

        foreach ($profile->pdf_column_options as $option) {
            $anchos_guardados[(int) $option->id] = isset($option->pivot->width) ? (int) $option->pivot->width : (int) $option->default_width;
        }

        foreach ($catalogo as $indice_catalogo => $option) {
            $option_id = (int) $option->id;

            if (isset($pivots[$option_id])) {
                continue;
            }

            $pivots[$option_id] = [
                'visible'      => false,
                'order'        => $cantidad_visibles + (int) $indice_catalogo,
                'width'        => isset($anchos_guardados[$option_id]) ? $anchos_guardados[$option_id] : (int) $option->default_width,
                'wrap_content' => false,
                'font_size'    => null,
                'text_align'   => null,
            ];
        }

        return [
            'pivots'     => $pivots,
            'visibles'   => $visibles,
            'ajustes'    => $ajustes,
            'suma'       => self::suma($visibles),
            'disponible' => $disponible,
            'error'      => null,
        ];
    }

    /**
     * Escribe el pivot nuevo en el perfil, igual que PdfColumnProfileSeederHelper::assign_profile_options:
     * sync con los seis campos y `columns` con la misma foto. Y toca `updated_at` aunque `columns`
     * no cambie: es lo que la tarjeta compara para detectar un diseño editado en el medio.
     *
     * @param  \App\Models\PdfColumnProfile $profile
     * @param  array $pivots  Lo que devolvió aplicar_cambios()['pivots'].
     * @return void
     */
    public static function persistir(PdfColumnProfile $profile, array $pivots)
    {
        $catalogo = PdfColumnOption::where('model_name', $profile->model_name)
            ->whereIn('id', array_keys($pivots))
            ->orderBy('order')
            ->get();

        $columns = [];

        foreach ($catalogo as $option) {
            $pivot = $pivots[(int) $option->id];

            $columns[] = [
                'option_id'      => (int) $option->id,
                'name'           => $option->name,
                'label'          => $option->label,
                'value_resolver' => $option->value_resolver,
                'visible'        => (bool) $pivot['visible'],
                'order'          => (int) $pivot['order'],
                'width'          => (int) $pivot['width'],
                'wrap_content'   => (bool) $pivot['wrap_content'],
                'font_size'      => $pivot['font_size'],
                'text_align'     => $pivot['text_align'],
            ];
        }

        $profile->pdf_column_options()->sync($pivots);
        $profile->columns = $columns;
        $profile->save();
        $profile->touch();
        $profile->unsetRelation('pdf_column_options');
    }

    /**
     * "Índice · Num · Cod. barras · Nombre · Cant · Categoria — 200 de 200 mm", con los labels.
     *
     * @param  array $visibles
     * @param  int   $suma
     * @param  int   $disponible
     * @return string
     */
    public static function resumen_de_columnas(array $visibles, $suma, $disponible)
    {
        $labels = [];

        foreach ($visibles as $fila) {
            $labels[] = $fila['label'] !== '' ? $fila['label'] : $fila['nombre'];
        }

        return implode(' · ', $labels).' — '.(int) $suma.' de '.(int) $disponible.' mm';
    }

    /**
     * Minúsculas y sin acentos, para comparar nombres de columnas.
     *
     * @param  string $texto
     * @return string
     */
    public static function normalizar($texto)
    {
        $texto = mb_strtolower(trim((string) $texto), 'UTF-8');

        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', 'à', 'è', 'ì', 'ò', 'ù'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n', 'a', 'e', 'i', 'o', 'u'],
            $texto
        );
    }

    /* ----------------------------------------------------------------------------------------
     * Internos
     * -------------------------------------------------------------------------------------- */

    /**
     * Achica una columna hasta `$piso` como mucho, anota el ajuste y devuelve el faltante que queda.
     *
     * @param  array $visibles  Por referencia.
     * @param  int   $indice
     * @param  int   $piso
     * @param  int   $faltante
     * @param  array $ajustes   Por referencia.
     * @return int
     */
    protected static function achicar(array &$visibles, $indice, $piso, $faltante, array &$ajustes)
    {
        $actual = (int) $visibles[$indice]['ancho_mm'];
        $margen = $actual - (int) $piso;

        if ($margen <= 0) {
            return $faltante;
        }

        $recorte = min($margen, $faltante);
        $nuevo   = $actual - $recorte;

        $visibles[$indice]['ancho_mm'] = $nuevo;

        $ajustes[] = ['columna' => $visibles[$indice]['nombre'], 'de' => $actual, 'a' => $nuevo];

        return $faltante - $recorte;
    }

    /**
     * @param  array $visibles
     * @param  array $ajustes
     * @param  \App\Models\PdfColumnProfile $profile
     * @param  string $error
     * @return array
     */
    protected static function resultado_con_error(array $visibles, array $ajustes, PdfColumnProfile $profile, $error)
    {
        return [
            'pivots'     => [],
            'visibles'   => $visibles,
            'ajustes'    => $ajustes,
            'suma'       => self::suma($visibles),
            'disponible' => self::ancho_disponible_mm($profile->printable_width_mm, $profile->margin_mm),
            'error'      => (string) $error,
        ];
    }

    /**
     * @param  array $visibles
     * @return int
     */
    protected static function suma(array $visibles)
    {
        $suma = 0;

        foreach ($visibles as $fila) {
            $suma += (int) $fila['ancho_mm'];
        }

        return $suma;
    }

    /**
     * @param  array $visibles
     * @param  int   $option_id
     * @return int|null
     */
    protected static function indice_de(array $visibles, $option_id)
    {
        foreach ($visibles as $indice => $fila) {
            if ((int) $fila['option_id'] === (int) $option_id) {
                return $indice;
            }
        }

        return null;
    }

    /**
     * @param  \App\Models\PdfColumnOption $option  Con su pivot cargado.
     * @return array
     */
    protected static function fila_desde_pivot($option)
    {
        $pivot = $option->pivot;

        return [
            'option_id'          => (int) $option->id,
            'nombre'             => (string) $option->name,
            'label'              => (string) $option->label,
            'ancho_mm'           => isset($pivot->width) ? (int) $pivot->width : (int) $option->default_width,
            'ajusta_texto'       => isset($pivot->wrap_content) ? (bool) $pivot->wrap_content : false,
            'default_width'      => (int) $option->default_width,
            'allow_wrap_content' => (bool) $option->allow_wrap_content,
            'font_size'          => isset($pivot->font_size) ? $pivot->font_size : null,
            'text_align'         => isset($pivot->text_align) ? $pivot->text_align : null,
            'order'              => isset($pivot->order) ? (int) $pivot->order : 0,
        ];
    }

    /**
     * Mismo criterio que PdfColumnProfileController::is_attached_pivot_visible_for_width_sum:
     * 0 / "0" / false es no visible; sin dato, visible.
     *
     * @param  mixed $pivot
     * @return bool
     */
    protected static function pivot_visible($pivot)
    {
        if (!isset($pivot->visible)) {
            return true;
        }

        $visible = $pivot->visible;

        if ($visible === true || $visible === 1 || $visible === '1') {
            return true;
        }

        if ($visible === false || $visible === 0 || $visible === '0' || $visible === '') {
            return false;
        }

        return (bool) $visible;
    }
}
