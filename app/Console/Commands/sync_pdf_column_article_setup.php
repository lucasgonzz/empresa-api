<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\PdfColumnArticleSetupHelper;
use Illuminate\Console\Command;

/**
 * Sincroniza catálogo PDF (opciones de columnas) y perfil tabular de artículos por owner.
 *
 * Uso típico tras actualizar el sistema (versión 2.0.6+):
 * php artisan pdf-column-profiles:sync-article-setup
 */
class sync_pdf_column_article_setup extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pdf-column-profiles:sync-article-setup
                            {--user-id= : Solo procesar este owner (users.id con owner_id null)}
                            {--dry-run : Simular sin guardar}
                            {--no-refresh-default-profile : No reaplicar columnas si el perfil "Lista de artículos" ya existe}
                            {--cleanup-employee-rows : Elimina perfiles PDF asociados a empleados (user_id con owner_id)}';

    /**
     * @var string
     */
    protected $description = 'Sincroniza opciones PDF de artículos y crea/actualiza el perfil tabular predeterminado por owner';

    /**
     * @return int
     */
    public function handle()
    {
        $dry_run = (bool) $this->option('dry-run');
        $only_user_id = $this->option('user-id');
        $refresh_default_profile = ! (bool) $this->option('no-refresh-default-profile');
        $cleanup_employees = (bool) $this->option('cleanup-employee-rows');

        if ($dry_run) {
            $this->warn('Modo dry-run: no se guardan cambios.');
        }

        $this->info('Sincronizando catálogo global de opciones PDF (sale + article)...');
        if (! $dry_run) {
            PdfColumnArticleSetupHelper::sync_catalog_options();
        }

        if ($cleanup_employees) {
            $deleted_count = PdfColumnArticleSetupHelper::cleanup_employee_pdf_column_profiles($dry_run);
            $this->info(sprintf(
                '%s %d perfil(es) PDF de empleados.',
                $dry_run ? 'Se eliminarían' : 'Eliminados',
                $deleted_count
            ));
        }

        $results = PdfColumnArticleSetupHelper::apply_for_all_owners(
            $dry_run,
            $refresh_default_profile,
            $only_user_id ? (int) $only_user_id : null
        );

        if (! count($results)) {
            $this->error('No hay owners para procesar.');
            return 1;
        }

        $rows = [];
        $created = 0;
        $columns_applied = 0;
        $skipped = 0;

        foreach ($results as $result) {
            if (! empty($result['skipped_reason'])) {
                $skipped++;
                $rows[] = [
                    $result['user_id'],
                    '-',
                    'omitido',
                    $result['skipped_reason'],
                ];
                continue;
            }

            if (! empty($result['profile_created'])) {
                $created++;
            }
            if (! empty($result['columns_applied'])) {
                $columns_applied++;
            }

            $rows[] = [
                $result['user_id'],
                $result['profile_id'] ?: '-',
                ! empty($result['profile_created']) ? 'creado' : 'existente',
                ! empty($result['columns_applied']) ? 'si' : 'no',
            ];
        }

        $this->table(
            ['owner_id', 'profile_id', 'perfil', 'columnas_default'],
            $rows
        );

        $action_label = $dry_run ? 'Simulación' : 'Completado';
        $this->info(sprintf(
            '%s: %d owner(s). Perfiles nuevos: %d. Columnas predeterminadas aplicadas: %d. Omitidos: %d.',
            $action_label,
            count($results),
            $created,
            $columns_applied,
            $skipped
        ));

        return 0;
    }
}
