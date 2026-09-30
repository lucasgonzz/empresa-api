<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\PdfColumnRemitoSetupHelper;
use Illuminate\Console\Command;

/**
 * Deja los perfiles de PDF "Remito" y "Sin Precios" de cada owner con todas sus columnas sumando
 * el ancho útil de la hoja (ancho imprimible menos los dos márgenes laterales), y crea el que falte.
 *
 * Solo toca los dos perfiles no fiscales con esos nombres: no cambia qué columnas hay, ni su orden,
 * ni el ancho de ninguna salvo "Nombre del artículo", que absorbe la diferencia. Es idempotente.
 *
 * Uso típico tras actualizar el sistema:
 * php artisan pdf-column-profiles:ajustar-remitos
 */
class ajustar_pdf_column_remitos extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pdf-column-profiles:ajustar-remitos
                            {--user-id= : Solo procesar este owner (users.id con owner_id null)}
                            {--dry-run : Simular sin guardar}';

    /**
     * @var string
     */
    protected $description = 'Crea los perfiles PDF Remito y Sin Precios que falten y ajusta sus columnas al ancho útil de la hoja';

    /**
     * @return int
     */
    public function handle()
    {
        $dry_run = (bool) $this->option('dry-run');
        $only_user_id = $this->option('user-id');

        if ($dry_run) {
            $this->warn('Modo dry-run: no se guardan cambios.');
        }

        $results = PdfColumnRemitoSetupHelper::apply_for_all_owners(
            $dry_run,
            $only_user_id ? (int) $only_user_id : null
        );

        if (! count($results)) {
            $this->error('No hay owners para procesar.');

            return 1;
        }

        $rows = [];
        $created = 0;
        $adjusted = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($results as $result) {
            if (! empty($result['skipped_reason'])) {
                $skipped++;
                $rows[] = [$result['user_id'], '-', '-', 'omitido', $result['skipped_reason']];
                continue;
            }

            foreach ($result['perfiles'] as $perfil) {
                if ($perfil['accion'] === 'creado') {
                    $created++;
                } elseif ($perfil['accion'] === 'ajustado') {
                    $adjusted++;
                } elseif ($perfil['accion'] === 'omitido') {
                    $skipped++;
                } else {
                    $unchanged++;
                }

                $rows[] = [
                    $result['user_id'],
                    $perfil['profile_id'] ?: '-',
                    $perfil['nombre'],
                    $perfil['accion'],
                    $perfil['antes'].' -> '.$perfil['despues'].' de '.$perfil['disponible'].' mm',
                ];
            }
        }

        $this->table(['owner_id', 'profile_id', 'perfil', 'accion', 'ancho (suma -> útil)'], $rows);

        $this->info(sprintf(
            '%s: %d owner(s). Perfiles creados: %d. Ajustados: %d. Sin cambios: %d. Omitidos: %d.',
            $dry_run ? 'Simulación' : 'Completado',
            count($results),
            $created,
            $adjusted,
            $unchanged,
            $skipped
        ));

        return 0;
    }
}
