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
        // withoutOverlapping(75) previene que el scheduler arranque un segundo worker en paralelo
        // mientras uno anterior todavía está procesando jobs pesados (timeout = 60 min = 3600 seg).
        // El margen de 75 min asegura que el anterior haya terminado antes de que se permita uno nuevo.
        $schedule->command('queue:work --stop-when-empty')
            ->everyMinute()
            ->withoutOverlapping(75);

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

        // Generación automática de sugerencias de stock (v2), solo con la
        // extensión sugerencias_inteligentes. El comando decide adentro si
        // según la periodicidad configurada hoy toca (o sale en una línea).
        // 05:00: lejos de debt:snapshot (23:59) y antes de que abra el
        // comercio; withoutOverlapping(60) cubre catálogos grandes.
        if ($company_owner && UserHelper::hasExtencion('sugerencias_inteligentes', $company_owner)) {
            $schedule->command('sugerencias:generar')
                ->dailyAt('05:00')
                ->withoutOverlapping(60);
        }

        // Reintenta cada 5 minutos los mensajes de soporte no sincronizados a admin-api.
        $schedule->command('support:retry-pending-syncs')->everyFiveMinutes();

        // Barrido de los eventos de la demo que quedaron sin empujar al admin (misión 50).
        // Cada minuto porque el panel del lead lo poléa cada 10 segundos esperando ver el
        // movimiento en vivo; el push inmediato va por afterResponse y esto es la red de abajo.
        //
        // En una instancia que no es de demo el comando sale en su primera línea — pero ojo:
        // sale DESPUÉS de un SELECT a demo_tracking_config. O sea que este es el único costo
        // permanente de la misión 50 en los ~40 clientes reales: un SELECT sobre una tabla
        // vacía por minuto. Lo que la misión exige en cero es el camino del request del
        // usuario, y ahí sí es cero; esto es el cron, y no hay forma de saber si hay canal
        // sin preguntárselo a la base.
        $schedule->command('demo:flush-eventos')
            ->everyMinute()
            ->withoutOverlapping(5);

        // Captura el snapshot de deuda diario (clientes y proveedores) a las 23:59.
        // Registra los saldos actuales de credit_accounts para análisis histórico.
        $schedule->command('debt:snapshot')->dailyAt('23:59');

        // Watchdog de importaciones colgadas: desbloquea imports que murieron sin dejar traza.
        // withoutOverlapping(10) permite reintentos si el comando muere; por default (1440 min = 24 horas)
        // el comando quedaría "trabado" un día entero sin poder correr de nuevo.
        // Corre cada 5 min, 10 min de margen es suficiente sin dejarlo mudo por 24 horas.
        $schedule->command('imports:detectar-colgadas')
            ->everyFiveMinutes()
            ->withoutOverlapping(10);

        // Watchdog de historiales colgados: desbloquea exportaciones (y a futuro imports) que murieron sin traza.
        // withoutOverlapping(10) permite reintentos si el comando muere; por default (1440 min = 24 horas)
        // el comando quedaría "trabado" un día entero sin poder correr de nuevo.
        // Corre cada 5 min, 10 min de margen es suficiente sin dejarlo mudo por 24 horas.
        $schedule->command('historiales:detectar-colgados')
            ->everyFiveMinutes()
            ->withoutOverlapping(10);

        // Renueva los access_token de Mercado Pago próximos a vencer (grupo 170, prompt 598).
        // Corre para TODOS los comercios con mp_enabled=true (no depende de company_owner /
        // extensiones), porque el vínculo OAuth es por online_configuration, no por instancia.
        $schedule->command('mercadopago:refresh-tokens')
            ->daily()
            ->withoutOverlapping();

        // Renueva los access_token de Zippin próximos a vencer (grupo 171, prompt 599).
        // Corre para TODOS los comercios con zippin_enabled=true (no depende de company_owner /
        // extensiones), porque el vínculo OAuth es por online_configuration, no por instancia.
        $schedule->command('zippin:refresh-tokens')
            ->daily()
            ->withoutOverlapping();
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
        // $this->load() escanea todas las clases Command dentro de app/Console/Commands
        // y las registra automáticamente (no hace falta listarlas una por una acá).
        // El comando `reconciliar_stock_variantes` (prompt 521, reconciliación defensiva
        // de stock de variantes) queda disponible por este mismo mecanismo; a propósito
        // no se le agrega entrada en $schedule() porque es de corrida manual, no periódica.
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
