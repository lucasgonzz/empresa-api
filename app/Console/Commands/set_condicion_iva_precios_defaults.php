<?php

namespace App\Console\Commands;

use App\Models\UserConfiguration;
use Illuminate\Console\Command;

/**
 * Seeder standalone para bases de producción ya existentes: completa
 * `condicion_iva_precios` con el default 'RRII' en las UserConfiguration
 * que quedaron vacías/nulas tras la migración que agregó la columna.
 *
 * Es idempotente: correrlo dos veces no cambia nada la segunda vez, y
 * nunca pisa un valor que el usuario ya haya elegido ('RRII' o 'MT').
 */
class set_condicion_iva_precios_defaults extends Command
{
    /**
     * @var string
     */
    protected $signature = 'user-configurations:set-condicion-iva-precios-defaults
                            {--dry-run : Mostrar cuántos registros se actualizarían sin guardar}';

    /**
     * @var string
     */
    protected $description = 'Setea condicion_iva_precios = RRII donde esté vacío/nulo, sin pisar valores ya elegidos';

    /**
     * Recorre las UserConfiguration existentes y completa el default donde falte.
     *
     * @return int
     */
    public function handle()
    {
        // Modo simulación: informa cuántos registros tocaría, sin persistir cambios.
        $dry_run = (bool) $this->option('dry-run');

        if ($dry_run) {
            $this->warn('Modo dry-run: no se guardan cambios.');
        }

        // Solo registros donde el campo esté vacío o nulo: nunca se pisa un valor ya elegido.
        $query = UserConfiguration::query()
            ->where(function ($q) {
                $q->whereNull('condicion_iva_precios')
                  ->orWhere('condicion_iva_precios', '');
            });

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No hay UserConfiguration con condicion_iva_precios vacío/nulo. Nada para hacer.');
            return 0;
        }

        if ($dry_run) {
            $this->info($total.' registro(s) quedarían en condicion_iva_precios = RRII.');
            return 0;
        }

        // chunkById para no cargar todos los registros en memoria de una vez en bases grandes.
        $updated = 0;
        $query->chunkById(200, function ($configurations) use (&$updated) {
            foreach ($configurations as $configuration) {
                $configuration->condicion_iva_precios = UserConfiguration::CONDICION_RRII;
                $configuration->save();
                $updated++;
            }
        });

        $this->info($updated.' UserConfiguration actualizada(s) a condicion_iva_precios = RRII.');

        return 0;
    }
}
