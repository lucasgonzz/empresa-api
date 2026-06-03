<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\Seeders\PdfColumnProfileSeederHelper;
use App\Models\PdfColumnProfile;
use App\Models\SheetType;
use App\Models\User;
use App\Services\PdfColumnService;

/**
 * Sincroniza catálogo PDF de artículos y asegura el perfil tabular por defecto por owner.
 */
class PdfColumnArticleSetupHelper
{
    /**
     * Nombre del perfil PDF tabular de artículos creado/actualizado en despliegues.
     */
    const DEFAULT_PROFILE_NAME = 'Lista de artículos';

    /**
     * Columnas visibles predeterminadas (orden de impresión: imagen, nombre, precio).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function default_visible_columns_definition()
    {
        return [
            [
                'name' => 'Imágenes',
                'width' => 40,
                'wrap_content' => false,
            ],
            [
                'name' => 'Nombre del artículo',
                'width' => 120,
                'wrap_content' => true,
            ],
            [
                'name' => 'Precio final',
                'width' => 40,
                'wrap_content' => false,
            ],
        ];
    }

    /**
     * Atributos de hoja A4 para el perfil tabular de artículos.
     *
     * @return array<string, mixed>
     */
    public static function default_profile_attributes()
    {
        $a4_sheet_type = SheetType::where('name', 'A4')->first();

        return [
            'model_name' => 'article',
            'name' => self::DEFAULT_PROFILE_NAME,
            'paper_width_mm' => 210,
            'printable_width_mm' => 210,
            'margin_mm' => 5,
            'is_default' => true,
            'is_afip_ticket' => false,
            'show_totals_on_each_page' => false,
            'columns' => [],
            'sheet_type_id' => $a4_sheet_type ? $a4_sheet_type->id : null,
        ];
    }

    /**
     * Sincroniza opciones de catálogo sale y article desde código (PdfColumnService).
     *
     * @return void
     */
    public static function sync_catalog_options()
    {
        PdfColumnService::sync_catalog_options('sale');
        PdfColumnService::sync_catalog_options('article');
    }

    /**
     * Elimina perfiles PDF cuyo user_id pertenece a empleados (no owners).
     *
     * @param bool $dry_run
     * @return int Cantidad de filas eliminadas (o que se eliminarían).
     */
    public static function cleanup_employee_pdf_column_profiles($dry_run = false)
    {
        $employee_user_ids = User::query()
            ->whereNotNull('owner_id')
            ->pluck('id');

        if ($employee_user_ids->isEmpty()) {
            return 0;
        }

        $query = PdfColumnProfile::query()->whereIn('user_id', $employee_user_ids);
        $count = (int) $query->count();

        if (! $dry_run && $count > 0) {
            $query->delete();
        }

        return $count;
    }

    /**
     * Aplica setup de artículos PDF para un owner.
     *
     * @param int $owner_id users.id con owner_id null.
     * @param bool $dry_run
     * @param bool $refresh_default_profile Si true, reaplica columnas al perfil predeterminado.
     * @return array<string, mixed>
     */
    public static function apply_for_owner($owner_id, $dry_run = false, $refresh_default_profile = true)
    {
        $result = [
            'user_id' => (int) $owner_id,
            'profile_id' => null,
            'profile_created' => false,
            'profile_updated' => false,
            'columns_applied' => false,
            'metrics_normalized' => false,
            'skipped_reason' => null,
        ];

        $owner = User::query()
            ->whereNull('owner_id')
            ->where('id', $owner_id)
            ->first();

        if (! $owner) {
            $result['skipped_reason'] = 'no_es_owner';
            return $result;
        }

        if ($dry_run) {
            $existing = PdfColumnProfile::query()
                ->where('user_id', $owner_id)
                ->where('model_name', 'article')
                ->where('name', self::DEFAULT_PROFILE_NAME)
                ->first();

            $result['profile_id'] = $existing ? $existing->id : null;
            $result['profile_created'] = $existing === null;
            $result['columns_applied'] = $refresh_default_profile || $existing === null;
            $result['metrics_normalized'] = $existing !== null;

            return $result;
        }

        $profile_attributes = self::default_profile_attributes();
        $search_keys = [
            'user_id' => $owner_id,
            'model_name' => $profile_attributes['model_name'],
            'name' => $profile_attributes['name'],
        ];

        $profile = PdfColumnProfile::query()
            ->where($search_keys)
            ->first();

        $profile_created = false;

        if (! $profile) {
            $profile = PdfColumnProfile::create(array_merge($profile_attributes, [
                'user_id' => $owner_id,
            ]));
            $profile_created = true;
            $result['profile_created'] = true;
        } else {
            $metrics_changed = self::normalize_article_profile_sheet_metrics($profile);
            $profile->fill([
                'is_default' => true,
                'paper_width_mm' => $profile_attributes['paper_width_mm'],
                'printable_width_mm' => $profile_attributes['printable_width_mm'],
                'margin_mm' => $profile_attributes['margin_mm'],
                'sheet_type_id' => $profile_attributes['sheet_type_id'],
                'is_afip_ticket' => false,
                'show_totals_on_each_page' => false,
            ]);
            if ($metrics_changed || $profile->isDirty()) {
                $profile->save();
                $result['metrics_normalized'] = true;
                $result['profile_updated'] = true;
            }
        }

        $should_apply_columns = $refresh_default_profile || $profile_created;

        if ($should_apply_columns) {
            PdfColumnProfile::query()
                ->where('user_id', $owner_id)
                ->where('model_name', 'article')
                ->where('id', '!=', $profile->id)
                ->update(['is_default' => false]);

            $profile->is_default = true;
            $profile->save();

            PdfColumnProfileSeederHelper::assign_profile_options(
                $profile,
                'article',
                self::default_visible_columns_definition()
            );

            $result['columns_applied'] = true;
        }

        $result['profile_id'] = $profile->id;

        return $result;
    }

    /**
     * Corrige valores legacy de ancho imprimible (200 mm netos guardados como imprimible).
     *
     * @param \App\Models\PdfColumnProfile $profile
     * @return bool true si hubo cambios pendientes de guardar en el modelo.
     */
    public static function normalize_article_profile_sheet_metrics(PdfColumnProfile $profile)
    {
        $paper_width_mm = (int) $profile->paper_width_mm;
        $printable_width_mm = (int) $profile->printable_width_mm;
        $margin_mm = (int) ($profile->margin_mm ?? 5);
        $changed = false;

        if ($paper_width_mm === 210 && $printable_width_mm === 200 && $margin_mm === 5) {
            $profile->printable_width_mm = 210;
            $changed = true;
        }

        if ($paper_width_mm <= 0) {
            $profile->paper_width_mm = 210;
            $changed = true;
        }

        if ((int) $profile->printable_width_mm <= 0) {
            $profile->printable_width_mm = 210;
            $changed = true;
        }

        if ($profile->margin_mm === null || $profile->margin_mm === '') {
            $profile->margin_mm = 5;
            $changed = true;
        }

        return $changed;
    }

    /**
     * Ejecuta setup para todos los owners.
     *
     * @param bool $dry_run
     * @param bool $refresh_default_profile
     * @param int|null $only_owner_id
     * @return array<int, array<string, mixed>>
     */
    public static function apply_for_all_owners($dry_run = false, $refresh_default_profile = true, $only_owner_id = null)
    {
        self::sync_catalog_options();

        $owners_query = User::query()
            ->whereNull('owner_id')
            ->select('id')
            ->orderBy('id');

        if ($only_owner_id) {
            $owners_query->where('id', (int) $only_owner_id);
        }

        $results = [];

        $owners_query->chunkById(200, function ($owners) use (&$results, $dry_run, $refresh_default_profile) {
            foreach ($owners as $owner) {
                $results[] = self::apply_for_owner(
                    $owner->id,
                    $dry_run,
                    $refresh_default_profile
                );
            }
        });

        return $results;
    }
}
