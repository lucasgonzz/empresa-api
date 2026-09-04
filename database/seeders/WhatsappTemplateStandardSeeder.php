<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\WhatsappTemplateStandardHelper;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use Illuminate\Database\Seeder;

/**
 * Precarga las 6 plantillas estándar de WhatsApp (`cc_cli_*`) para cada empresa que ya
 * tiene el bot configurado (grupo 137, Prompt 04). Solo itera owners (regla del
 * CLAUDE.md: los recursos de configuración por negocio son por owner, no por cada fila
 * de `users`) que tengan un `WhatsappBotConfig` propio, ya que sin bot configurado no
 * tiene sentido cargarles plantillas.
 *
 * Idempotente (`WhatsappTemplateStandardHelper::apply_for_owner` matchea por
 * `meta_template_name` antes de crear): se llama desde el seeder general
 * (`common_seeders()` en `DatabaseSeeder`) y también puede correrse de forma standalone
 * en bases de producción existentes sin duplicar filas:
 *   php artisan db:seed --class=WhatsappTemplateStandardSeeder
 */
class WhatsappTemplateStandardSeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        // Owners (dueños) que tienen configurado el bot de WhatsApp de clientes.
        $owner_ids_with_config = WhatsappBotConfig::query()
            ->distinct()
            ->pluck('user_id');

        User::query()
            ->whereNull('owner_id')
            ->whereIn('id', $owner_ids_with_config)
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    WhatsappTemplateStandardHelper::apply_for_owner($user->id);
                }
            });
    }
}
