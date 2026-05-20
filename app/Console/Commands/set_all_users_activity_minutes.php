<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Comando de mantenimiento: asigna activity_minutes a todos los usuarios de la instancia.
 */
class set_all_users_activity_minutes extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'set_all_users_activity_minutes {minutes=10 : Minutos de inactividad antes de cerrar sesión}';

    /**
     * Descripción mostrada en php artisan list.
     *
     * @var string
     */
    protected $description = 'Setea activity_minutes de todos los usuarios al valor indicado (por defecto 10)';

    /**
     * Ejecuta la actualización masiva de activity_minutes.
     *
     * @return int
     */
    public function handle()
    {
        // Minutos de sesión a aplicar (argumento opcional, default 10).
        $activity_minutes = (int) $this->argument('minutes');

        if ($activity_minutes < 1) {
            $this->error('Los minutos deben ser al menos 1.');
            return 1;
        }

        // Actualización directa sin tocar updated_at ni disparar eventos por registro.
        $updated_count = User::query()->update(['activity_minutes' => $activity_minutes]);

        $this->info("Listo: {$updated_count} usuario(s) con activity_minutes = {$activity_minutes}.");

        return 0;
    }
}
