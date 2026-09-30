<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\Seeders\PdfColumnProfileSeederHelper;
use App\Models\PdfColumnProfile;
use App\Models\SheetType;
use App\Models\User;
use App\Services\PdfColumnService;
use Illuminate\Support\Facades\DB;

/**
 * Asegura que cada owner tenga los perfiles de PDF "Remito" y "Sin Precios", y que sus columnas
 * ocupen TODO el ancho útil de la hoja (ancho imprimible menos los dos márgenes laterales).
 *
 * Por qué existe (Quino2, 30/9/2026): el remito sin precios de un cliente salía con la tabla a dos
 * tercios del ancho, contra un encabezado que sí llegaba al borde: # 8 + Num 15 + Cod. barras 30 +
 * Nombre 72 + Cant 15 = 140 mm de 200. Los anchos salían del catálogo (`pdf_column_options.default_width`)
 * y no de una definición pensada para cerrar en 200. Además "Sin Precios" no lo sembraba nadie en un
 * cliente nuevo (su seeder existía pero no lo llamaba ningún alta).
 *
 * Esta clase es la ÚNICA definición de las columnas de esos dos perfiles: los seeders la usan para
 * los clientes nuevos y el comando `pdf-column-profiles:ajustar-remitos` para los que ya existen.
 * La columna "Nombre del artículo" es la elástica: absorbe lo que le falte o le sobre al resto.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class PdfColumnRemitoSetupHelper
{
    /** Nombre del perfil de remito con precios. */
    const REMITO = 'Remito';

    /** Nombre del perfil de remito sin precios. */
    const SIN_PRECIOS = 'Sin Precios';

    /** Nombre (en el catálogo) de la única columna que absorbe la diferencia de ancho. */
    const COLUMNA_ELASTICA = 'Nombre del artículo';

    /** Ancho mínimo al que se achica la columna elástica antes de repartir entre todas. */
    const ANCHO_MINIMO_ELASTICA_MM = 30;

    /** Hoja A4 con margen de 5 mm: los valores con que nacen los dos perfiles. */
    const PAPEL_MM = 210;
    const IMPRIMIBLE_MM = 210;
    const MARGEN_MM = 5;

    /**
     * Los dos perfiles que se exigen, en el orden en que se procesan.
     *
     * @return array<int, string>
     */
    public static function nombres_de_perfiles()
    {
        return [self::REMITO, self::SIN_PRECIOS];
    }

    /**
     * Ancho útil de una hoja recién creada (A4, margen 5): 200 mm.
     *
     * @return int
     */
    public static function ancho_util_de_hoja_nueva()
    {
        return PdfColumnProfileHelper::ancho_disponible_mm(self::IMPRIMIBLE_MM, self::MARGEN_MM);
    }

    /**
     * Columnas visibles de un perfil, en el formato que recibe
     * PdfColumnProfileSeederHelper::assign_profile_options(): el ancho de cada una sale del
     * catálogo salvo los fijos de acá, y "Nombre del artículo" se lleva TODO lo que sobra para
     * que la suma cierre en el ancho útil.
     *
     * @param  string $profile_name  self::REMITO | self::SIN_PRECIOS
     * @param  int|null $ancho_util  Por defecto, el de una hoja A4 con margen 5.
     * @return array<int, array<string, mixed>>
     */
    public static function visible_columns_definition($profile_name, $ancho_util = null)
    {
        $ancho_util = $ancho_util === null ? self::ancho_util_de_hoja_nueva() : (int) $ancho_util;

        $nombres = $profile_name === self::REMITO
            ? [
                'Índice de fila',
                'Número de artículo',
                'Código de barras',
                self::COLUMNA_ELASTICA,
                'Cantidad',
                'Precio unitario',
                'Subtotal línea',
            ]
            : [
                'Índice de fila',
                'Número de artículo',
                'Código de barras',
                self::COLUMNA_ELASTICA,
                'Cantidad',
            ];

        /**
         * El remito NO lleva columna de descuento a propósito: en el catálogo la opción con el
         * resolver `item_discount_percentage` se llama 'Bonificación porcentaje' (comparte resolver
         * con 'Descuento porcentaje' y la sincronización deja el nombre de la última), así que el
         * 'Descuento porcentaje' que declaraba el seeder no coincidía con ninguna opción y quedaba
         * fuera sin avisar. Se deja como estaba de hecho en los clientes: 7 columnas que suman 200.
         *
         * Fijos por columna. "Precio unitario" y "Subtotal línea" llevan más ancho que el catálogo
         * (22 y 25) para que entren importes de siete cifras con decimales sin cortarse.
         */
        $fijos = [
            'Precio unitario' => 28,
            'Subtotal línea' => 32,
        ];

        $catalogo = self::anchos_de_catalogo();

        $anchos = [];
        $suma_sin_elastica = 0;
        foreach ($nombres as $nombre) {
            if ($nombre === self::COLUMNA_ELASTICA) {
                continue;
            }
            $anchos[$nombre] = isset($fijos[$nombre]) ? $fijos[$nombre] : (int) $catalogo[$nombre];
            $suma_sin_elastica += $anchos[$nombre];
        }
        $anchos[self::COLUMNA_ELASTICA] = max(self::ANCHO_MINIMO_ELASTICA_MM, $ancho_util - $suma_sin_elastica);

        $definicion = [];
        foreach ($nombres as $nombre) {
            $definicion[] = ['name' => $nombre, 'width' => $anchos[$nombre]];
        }

        return $definicion;
    }

    /**
     * Atributos con que nace cada uno de los dos perfiles (A4, margen 5, no fiscal).
     *
     * @param  string $profile_name
     * @return array<string, mixed>
     */
    public static function default_profile_attributes($profile_name)
    {
        $a4 = SheetType::where('name', 'A4')->first();

        return [
            'model_name' => 'sale',
            'name' => $profile_name,
            'is_default' => false,
            'paper_width_mm' => self::PAPEL_MM,
            'printable_width_mm' => self::IMPRIMIBLE_MM,
            'margin_mm' => self::MARGEN_MM,
            'sheet_type_id' => $a4 ? $a4->id : null,
            'is_afip_ticket' => false,
            'show_totals_on_each_page' => false,
            'columns' => [],
        ];
    }

    /**
     * Reparte el ancho útil entre las columnas visibles de un perfil que YA existe.
     *
     * 1) Si la columna "Nombre del artículo" está visible y, absorbiendo la diferencia, queda en
     *    30 mm o más, solo ella cambia: el resto de las columnas quedan exactamente como las dejó
     *    el cliente.
     * 2) Si no (no hay Nombre, o quedaría muy angosto), se escalan TODAS las visibles en
     *    proporción y el resto del redondeo va a la elástica (o a la más ancha).
     *
     * @param  array $visibles     Filas de PdfColumnProfileHelper::columnas_visibles().
     * @param  int   $disponible   Ancho útil en mm.
     * @return array<int, int>     option_id => ancho nuevo, solo de las columnas que cambian.
     */
    public static function repartir_ancho(array $visibles, $disponible)
    {
        $disponible = (int) $disponible;
        $suma = 0;
        foreach ($visibles as $fila) {
            $suma += (int) $fila['ancho_mm'];
        }

        if ($disponible <= 0 || $suma <= 0 || $suma === $disponible) {
            return [];
        }

        $diferencia = $disponible - $suma;
        $indice_elastica = self::indice_de_elastica($visibles);

        if ($indice_elastica !== null) {
            $nuevo = (int) $visibles[$indice_elastica]['ancho_mm'] + $diferencia;
            if ($nuevo >= self::ANCHO_MINIMO_ELASTICA_MM) {
                return [(int) $visibles[$indice_elastica]['option_id'] => $nuevo];
            }
        }

        /** Escala proporcional. */
        $razon = $disponible / $suma;
        $nuevos = [];
        $suma_nuevos = 0;
        foreach ($visibles as $i => $fila) {
            $nuevos[$i] = max(1, (int) floor((int) $fila['ancho_mm'] * $razon));
            $suma_nuevos += $nuevos[$i];
        }

        $destino = $indice_elastica !== null ? $indice_elastica : self::indice_de_la_mas_ancha($visibles);
        $nuevos[$destino] = max(1, $nuevos[$destino] + ($disponible - $suma_nuevos));

        $cambios = [];
        foreach ($visibles as $i => $fila) {
            if ($nuevos[$i] !== (int) $fila['ancho_mm']) {
                $cambios[(int) $fila['option_id']] = $nuevos[$i];
            }
        }

        return $cambios;
    }

    /**
     * Revisa los dos perfiles de un owner: crea el que falta y ajusta el que no suma el ancho útil.
     *
     * @param  int  $owner_id  users.id con owner_id null.
     * @param  bool $dry_run   Si es true no escribe nada: reporta lo que haría.
     * @return array<string, mixed>
     */
    public static function apply_for_owner($owner_id, $dry_run = false)
    {
        $resultado = [
            'user_id' => (int) $owner_id,
            'skipped_reason' => null,
            'perfiles' => [],
        ];

        $owner = User::query()->whereNull('owner_id')->where('id', $owner_id)->first();
        if (! $owner) {
            $resultado['skipped_reason'] = 'no_es_owner';

            return $resultado;
        }

        $perfiles_de_venta = PdfColumnProfile::query()
            ->where('user_id', $owner_id)
            ->where('model_name', 'sale')
            ->where(function ($q) {
                $q->where('is_afip_ticket', false)->orWhereNull('is_afip_ticket');
            })
            ->orderBy('id')
            ->get();

        DB::beginTransaction();
        try {
            foreach (self::nombres_de_perfiles() as $nombre) {
                $existentes = $perfiles_de_venta->filter(function ($perfil) use ($nombre) {
                    return PdfColumnProfileHelper::normalizar($perfil->name) === PdfColumnProfileHelper::normalizar($nombre);
                });

                if ($existentes->isEmpty()) {
                    $resultado['perfiles'][] = self::crear_perfil($owner_id, $nombre, $dry_run);
                    continue;
                }

                foreach ($existentes as $perfil) {
                    $resultado['perfiles'][] = self::ajustar_perfil($perfil, $dry_run);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $resultado;
    }

    /**
     * Corre apply_for_owner() con todos los owners (o con uno solo).
     *
     * @param  bool     $dry_run
     * @param  int|null $only_owner_id
     * @return array<int, array<string, mixed>>
     */
    public static function apply_for_all_owners($dry_run = false, $only_owner_id = null)
    {
        /** El catálogo tiene que estar completo antes de crear o ajustar nada. */
        if (! $dry_run) {
            PdfColumnService::sync_catalog_options('sale');
        }

        $owners = User::query()->whereNull('owner_id')->select('id')->orderBy('id');
        if ($only_owner_id) {
            $owners->where('id', (int) $only_owner_id);
        }

        $resultados = [];
        $owners->chunkById(200, function ($lote) use (&$resultados, $dry_run) {
            foreach ($lote as $owner) {
                $resultados[] = self::apply_for_owner($owner->id, $dry_run);
            }
        });

        return $resultados;
    }

    /* ----------------------------------------------------------------------------------------
     * Internos
     * -------------------------------------------------------------------------------------- */

    /**
     * Crea el perfil que falta, con la definición de este helper.
     *
     * @param  int    $owner_id
     * @param  string $nombre
     * @param  bool   $dry_run
     * @return array<string, mixed>
     */
    protected static function crear_perfil($owner_id, $nombre, $dry_run)
    {
        $fila = [
            'nombre' => $nombre,
            'profile_id' => null,
            'accion' => 'creado',
            'antes' => 0,
            'despues' => self::ancho_util_de_hoja_nueva(),
            'disponible' => self::ancho_util_de_hoja_nueva(),
        ];

        if ($dry_run) {
            return $fila;
        }

        $perfil = PdfColumnProfile::create(array_merge(
            self::default_profile_attributes($nombre),
            ['user_id' => $owner_id]
        ));

        PdfColumnProfileSeederHelper::assign_profile_options($perfil, 'sale', self::visible_columns_definition($nombre));

        $fila['profile_id'] = $perfil->id;

        return $fila;
    }

    /**
     * Ajusta la suma de anchos de un perfil existente al ancho útil.
     *
     * @param  \App\Models\PdfColumnProfile $perfil
     * @param  bool $dry_run
     * @return array<string, mixed>
     */
    protected static function ajustar_perfil(PdfColumnProfile $perfil, $dry_run)
    {
        $margen = ($perfil->margin_mm === null || $perfil->margin_mm === '') ? self::MARGEN_MM : (int) $perfil->margin_mm;
        $disponible = PdfColumnProfileHelper::ancho_disponible_mm($perfil->printable_width_mm, $margen);
        $visibles = PdfColumnProfileHelper::columnas_visibles($perfil);
        $suma = PdfColumnProfileHelper::suma_de_anchos_mm($perfil);

        $fila = [
            'nombre' => $perfil->name,
            'profile_id' => $perfil->id,
            'accion' => 'sin_cambios',
            'antes' => $suma,
            'despues' => $suma,
            'disponible' => $disponible,
        ];

        if ($disponible <= 0 || ! count($visibles)) {
            $fila['accion'] = 'omitido';

            return $fila;
        }

        $cambios = self::repartir_ancho($visibles, $disponible);
        if (! count($cambios)) {
            return $fila;
        }

        $fila['accion'] = 'ajustado';
        $fila['despues'] = $disponible;

        if ($dry_run) {
            return $fila;
        }

        foreach ($cambios as $option_id => $ancho) {
            $perfil->pdf_column_options()->updateExistingPivot($option_id, ['width' => $ancho]);
        }

        self::reescribir_foto_de_columnas($perfil);

        return $fila;
    }

    /**
     * Reescribe `columns` (la foto JSON del pivot) y toca `updated_at`, igual que
     * PdfColumnProfileHelper::persistir() y PdfColumnProfileSeederHelper::assign_profile_options().
     *
     * @param  \App\Models\PdfColumnProfile $perfil
     * @return void
     */
    protected static function reescribir_foto_de_columnas(PdfColumnProfile $perfil)
    {
        $perfil->unsetRelation('pdf_column_options');
        $perfil->load('pdf_column_options');

        $columns = [];
        foreach ($perfil->pdf_column_options as $option) {
            $pivot = $option->pivot;
            $columns[] = [
                'option_id' => (int) $option->id,
                'name' => $option->name,
                'label' => $option->label,
                'value_resolver' => $option->value_resolver,
                'visible' => (bool) $pivot->visible,
                'order' => (int) $pivot->order,
                'width' => (int) $pivot->width,
                'wrap_content' => (bool) $pivot->wrap_content,
                'font_size' => $pivot->font_size,
                'text_align' => $pivot->text_align,
            ];
        }

        $perfil->columns = $columns;
        $perfil->save();
        $perfil->touch();
        $perfil->unsetRelation('pdf_column_options');
    }

    /**
     * Ancho por defecto de cada columna de venta, por nombre, tomado del código (no de la base):
     * así los seeders funcionan aunque el catálogo todavía no esté sincronizado.
     *
     * @return array<string, int>
     */
    protected static function anchos_de_catalogo()
    {
        $anchos = [];
        foreach (PdfColumnService::default_options('sale') as $opcion) {
            $anchos[$opcion['name']] = (int) $opcion['default_width'];
        }

        return $anchos;
    }

    /**
     * @param  array $visibles
     * @return int|null
     */
    protected static function indice_de_elastica(array $visibles)
    {
        foreach ($visibles as $i => $fila) {
            if ($fila['nombre'] === self::COLUMNA_ELASTICA) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array $visibles
     * @return int
     */
    protected static function indice_de_la_mas_ancha(array $visibles)
    {
        $mejor = 0;
        foreach ($visibles as $i => $fila) {
            if ((int) $fila['ancho_mm'] > (int) $visibles[$mejor]['ancho_mm']) {
                $mejor = $i;
            }
        }

        return $mejor;
    }
}
