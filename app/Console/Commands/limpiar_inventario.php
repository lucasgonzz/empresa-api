<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\database\LimpiarInventarioHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Borrado físico del inventario completo del usuario de la instancia (config app.USER_ID).
 */
class limpiar_inventario extends Command
{
    /**
     * limpiar_inventario {confirmar?}
     *
     * Sin confirmar: solo muestra relaciones y cantidades.
     * Con confirmar=1: ejecuta el borrado físico en transacción.
     */
    protected $signature = 'limpiar_inventario {confirmar?}';

    /**
     * Descripción del comando en artisan list.
     *
     * @var string
     */
    protected $description = 'Elimina físicamente todos los artículos del usuario y sus relaciones. Sin confirmar solo muestra el resumen.';

    /**
     * Ejecuta el comando: vista previa o borrado confirmado.
     *
     * @return int
     */
    public function handle()
    {
        // Si viene 1/true, se ejecuta el borrado; si no, solo vista previa.
        $confirmar = (bool) $this->argument('confirmar');

        // Usuario dueño de la instancia (misma convención que otros comandos).
        $user_id = config('app.USER_ID');

        if (empty($user_id)) {
            $this->error('No está definido app.USER_ID en la configuración.');
            return 1;
        }

        // IDs de artículos a limpiar (incluye soft-deleted).
        $article_ids = LimpiarInventarioHelper::get_article_ids($user_id);
        $articles_count = $article_ids->count();

        $this->warn('=== LIMPIAR INVENTARIO — VISTA PREVIA ===');
        $this->line("user_id: {$user_id}");
        $this->line("Artículos a eliminar (físico): {$articles_count}");
        $this->line('');
        $this->info('Relaciones que se limpiarán (tabla → registros):');
        $this->line('');

        // Catálogo ordenado de dependencias.
        $catalog = LimpiarInventarioHelper::get_relations_catalog();
        $total_related = 0;
        $rows_preview = [];

        foreach ($catalog as $relation) {
            // Cantidad de filas que se borrarían o actualizarían.
            $count = LimpiarInventarioHelper::count_relation($relation, $article_ids, $user_id);

            $rows_preview[] = [
                $relation['table'],
                $relation['label'],
                $count,
            ];

            if ($relation['type'] !== 'nullify_provider_article_id' && $relation['type'] !== 'articles_delete') {
                $total_related += $count;
            }
        }

        $this->table(['Tabla', 'Descripción', 'Registros'], $rows_preview);
        $this->line('');
        $this->comment("Total registros en tablas relacionadas (sin contar anulación provider_article_id): {$total_related}");

        if (!$confirmar) {
            $this->line('');
            $this->warn('Modo solo vista previa. No se borró nada.');
            $this->comment('Para ejecutar el borrado físico: php artisan limpiar_inventario 1');
            return 0;
        }

        if (!$this->confirm('¿Confirmás el BORRADO FÍSICO irreversible de todo el inventario?')) {
            $this->comment('Operación cancelada.');
            return 0;
        }

        $this->info('Iniciando borrado físico...');

        $deleted_summary = [];

        DB::beginTransaction();

        try {
            foreach ($catalog as $relation) {
                $affected = LimpiarInventarioHelper::delete_relation($relation, $article_ids, $user_id);

                if ($affected > 0) {
                    $deleted_summary[] = [
                        $relation['table'],
                        $affected,
                    ];
                    $this->comment("  ✓ {$relation['table']}: {$affected} filas");
                }
            }

            DB::commit();
            $this->info('Inventario limpiado correctamente.');
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->error('Error durante el borrado. Se revirtió la transacción.');
            $this->error($exception->getMessage());
            return 1;
        }

        return 0;
    }
}
