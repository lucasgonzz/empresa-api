<?php

namespace App\Console;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * En producción debe existir un único cron cada minuto:
     *   * * * * * cd /ruta/empresa-api && php artisan schedule:run >> /dev/null 2>&1
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Procesa la cola de jobs hasta vaciarla (reemplaza cron directo de queue:work).
        // Nota: no usar ['--stop-when-empty' => true]; Laravel lo serializa como --stop-when-empty="1"
        // y Symfony rechaza ese flag (no acepta valor), el worker falla sin procesar jobs.
        $schedule->command('queue:work --stop-when-empty')
            ->everyMinute()
            ->withoutOverlapping(15);

        // Usuario dueño de la instancia (config app.USER_ID) con extensiones cargadas.
        $company_owner = $this->resolve_company_owner_for_schedule();

        // Sincronización de artículos pendientes hacia Tienda Nube solo si tiene la extensión.
        if ($company_owner && UserHelper::hasExtencion('usa_tienda_nube', $company_owner)) {
            $schedule->command('sync_articles_to_tienda_nube')
                ->everyMinute()
                ->withoutOverlapping(15);
        }

        // Sincronización de artículos pendientes hacia Mercado Libre solo si tiene la extensión.
        if ($company_owner && UserHelper::hasExtencion('usa_mercado_libre', $company_owner)) {
            $schedule->command('sync_to_meli_articles')
                ->everyMinute()
                ->withoutOverlapping(15);
        }

        // Genera embeddings vectoriales de artículos nuevos o modificados,
        // solo si el usuario tiene la extensión whatsapp_ia activa.
        // withoutOverlapping(25) previene acumulación si un ciclo tarda más de lo esperado
        // (primer índice completo de catálogos grandes) con margen antes del próximo ciclo de 30 min.
        if ($company_owner && UserHelper::hasExtencion('whatsapp_ia', $company_owner)) {
            $schedule->command('articles:generate-embeddings')
                ->everyThirtyMinutes()
                ->withoutOverlapping(25);
        }

        // Reintenta cada 5 minutos los mensajes de soporte no sincronizados a admin-api.
        $schedule->command('support:retry-pending-syncs')->everyFiveMinutes();

        // Captura el snapshot de deuda diario (clientes y proveedores) a las 23:59.
        // Registra los saldos actuales de credit_accounts para análisis histórico.
        $schedule->command('debt:snapshot')->dailyAt('23:59');
    }

    /**
     * Resuelve el usuario dueño de la instancia para evaluar extensiones en el schedule.
     *
     * @return User|null
     */
    protected function resolve_company_owner_for_schedule()
    {
        // ID del dueño configurado en .env (una instancia = un cliente).
        $user_id = config('app.USER_ID');

        if (empty($user_id)) {
            return null;
        }

        return User::with('extencions')->find($user_id);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
