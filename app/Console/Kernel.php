<?php

namespace App\Console;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\ExportHistory;
use App\Models\ImportHistory;
use App\Models\SyncToMeliArticle;
use App\Models\SyncToTNArticle;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * En producción debe existir un único cron cada minuto:
     *   * * * * * cd /ruta/empresa-api && php artisan schedule:run >> /dev/null 2>&1
     *
     * 🔴 Regla del schedule (misión actualizar-sin-el-vps, 9/9/2026): CERO procesos nuevos cuando
     * no hay nada que hacer. Cada comando que el scheduler dispara es un `php artisan` aparte, y
     * arrancar Laravel son 0,3-0,5 s de CPU; en el VPS, con 17 instancias, eran ~80 arranques por
     * minuto (entre medio núcleo y uno, permanente) para que casi todos salieran en su primera
     * línea. Por eso los comandos de cada minuto llevan un `->when()` que decide ADENTRO del
     * proceso de schedule:run (que ya está booteado) si hay trabajo, y recién ahí se paga el
     * arranque. Para quien sí tiene trabajo el comportamiento es exactamente el de antes.
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
        //
        // Solo en shared hosting. En el VPS la cola la maneja supervisor, con un queue:work de larga
        // vida por instancia; si el scheduler ademas programara el suyo, quedarian dos workers
        // compitiendo por los mismos jobs. La instancia se identifica por VPS=true en su .env, la
        // misma variable que ya usa config/filesystems.php para decidir el prefijo /public de los
        // archivos. El default false de env('VPS') deja intacto el comportamiento de toda instancia
        // que no la declare, que es el caso de todas las del shared.
        //
        // Y un segundo interruptor, independiente del anterior: QUEUE_SCHEDULER_WORKER (config
        // queue.scheduler_worker, misión actualizar-sin-el-vps). Apaga este worker en cualquier
        // instancia que ya tenga otro consumidor de la cola, sin depender de que VPS esté declarada
        // — es lo que se va a setear en false en las instancias del VPS. Default true: el shared
        // hosting, donde este worker es lo ÚNICO que procesa la cola, sigue igual.
        if (! config('app.VPS') && config('queue.scheduler_worker')) {
            $schedule->command('queue:work --stop-when-empty')
                ->everyMinute()
                ->withoutOverlapping(75);
        }

        // Usuario dueño de la instancia (config app.USER_ID) con extensiones cargadas.
        $company_owner = $this->resolve_company_owner_for_schedule();

        // Sincronización de artículos pendientes hacia Tienda Nube solo si tiene la extensión.
        //
        // El when() es la misma consulta con la que arranca el comando (sync_to_t_n_articles del
        // dueño en 'pendiente'), pero hecha acá, en el proceso de schedule:run: con la extensión
        // activa y nada pendiente, antes se arrancaba un artisan por minuto para imprimir
        // "0 sincronizaciones". Con algo pendiente el comando corre exactamente igual que antes.
        if ($company_owner && UserHelper::hasExtencion('usa_tienda_nube', $company_owner)) {
            $schedule->command('sync_articles_to_tienda_nube')
                ->everyMinute()
                ->withoutOverlapping(15)
                ->when(function () {
                    return $this->gate_de_datos(function () {
                        return SyncToTNArticle::where('user_id', config('app.USER_ID'))
                            ->where('status', 'pendiente')
                            ->exists();
                    });
                });
        }

        // Sincronización de artículos pendientes hacia Mercado Libre solo si tiene la extensión.
        // Mismo gate que Tienda Nube, sobre sync_to_meli_articles.
        if ($company_owner && UserHelper::hasExtencion('usa_mercado_libre', $company_owner)) {
            $schedule->command('sync_to_meli_articles')
                ->everyMinute()
                ->withoutOverlapping(15)
                ->when(function () {
                    return $this->gate_de_datos(function () {
                        return SyncToMeliArticle::where('user_id', config('app.USER_ID'))
                            ->where('status', 'pendiente')
                            ->exists();
                    });
                });
        }

        // Genera embeddings vectoriales de artículos nuevos o modificados,
        // solo si el usuario tiene la extensión whatsapp_ia activa.
        // withoutOverlapping(25) previene acumulación si un ciclo tarda más de lo esperado
        // (primer índice completo de catálogos grandes) con margen antes del próximo ciclo de 30 min.
        //
        // EMBEDDINGS_OMITIR_IMPORTACION apaga este scheduler entero (además del disparo post-import
        // de FinalizeArticleImport, ver ese archivo). Existe para las instancias de demo: un lead
        // que sube un Excel de prueba de miles de artículos no tiene que generarle embeddings a
        // ninguno — la creación manual de un artículo sigue embebiendo al toque igual, porque eso
        // vive en ArticleObserver/DescriptionObserver y no pasa por acá. Default false: ningún
        // cliente real nota que esta variable existe.
        if (
            $company_owner
            && UserHelper::hasExtencion('whatsapp_ia', $company_owner)
            && ! filter_var(env('EMBEDDINGS_OMITIR_IMPORTACION', false), FILTER_VALIDATE_BOOLEAN)
        ) {
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

        // Generación automática de sugerencias de compra a proveedores, solo
        // con la extensión sugerencias_compras. Mismo patrón que
        // sugerencias:generar de arriba: el comando decide adentro si según
        // la periodicidad configurada hoy toca (o sale en una línea).
        // 05:30 y no 05:00: no se pisa con sugerencias:generar (mismo
        // comercio, misma ventana horaria, dos comandos que recorren todo el
        // catálogo). withoutOverlapping(60) cubre catálogos grandes.
        if ($company_owner && UserHelper::hasExtencion('sugerencias_compras', $company_owner)) {
            $schedule->command('compras:generar')
                ->dailyAt('05:30')
                ->withoutOverlapping(60);
        }

        // Corrida automática del motor de ofertas por cliente, solo con la extensión
        // motor_de_ofertas. Mismo patrón que los dos de arriba: el comando decide adentro si hoy
        // toca según la periodicidad, y ese doble gate es a propósito (el de acá evita el SELECT;
        // el de adentro cubre la corrida a mano). 06:00 y no 05:00/05:30: no se pisa con
        // sugerencias:generar ni con compras:generar, que en la misma ventana recorren catálogo e
        // historial del mismo comercio. withoutOverlapping(60) cubre padrones de clientes grandes.
        if ($company_owner && UserHelper::hasExtencion('motor_de_ofertas', $company_owner)) {
            $schedule->command('ofertas:generar')
                ->dailyAt('06:00')
                ->withoutOverlapping(60);
        }

        // Rollup diario del tracking de comportamiento de compradores de la tienda
        // (misión tracking-buyers-tienda): colapsa el día de ayer de
        // buyer_tracking_events en buyer_tracking_daily. 03:30 porque el día de ayer
        // ya está cerrado y la tienda a esa hora no tiene tráfico; withoutOverlapping(30)
        // cubre una tienda con muchos grupos por día.
        //
        // Costo permanente en los ~40 clientes reales: CERO consultas a la base. El
        // gate por extensión de este if corta antes de que el comando arranque, y el
        // $company_owner que mira ya está resuelto arriba (:34) para el resto del
        // schedule — o sea que ni siquiera agrega el SELECT que sí paga
        // demo:flush-eventos acá abajo. Y si alguien lo corre a mano, el comando
        // repite el gate adentro.
        if ($company_owner && UserHelper::hasExtencion('tracking_buyers', $company_owner)) {
            $schedule->command('tracking:agregar-buyers')
                ->dailyAt('03:30')
                ->withoutOverlapping(30);
        }

        // Retención de los eventos crudos del tracking de compradores: borra por lotes
        // lo de más de 90 días. 04:00, media hora después del rollup, para que el
        // agregado del día ya esté escrito antes de que se toque nada; el agregado no
        // se purga nunca. withoutOverlapping(30) porque el borrado por lotes de una
        // tabla grande puede tardar.
        //
        // Mismo costo permanente que el de arriba en los ~40 clientes reales: cero,
        // por el mismo gate.
        if ($company_owner && UserHelper::hasExtencion('tracking_buyers', $company_owner)) {
            $schedule->command('tracking:purgar-buyers')
                ->dailyAt('04:00')
                ->withoutOverlapping(30);
        }

        // Vencimiento de puntos de clientes, solo con la extensión puntos_clientes. Mismo patrón
        // que ofertas:generar: el comando decide adentro si hay programa activo y si vence algo (o
        // sale en una línea), y ese doble gate es a propósito -- el de acá evita el SELECT, el de
        // adentro cubre la corrida a mano. 04:30 y no 03:30/04:00/05:00/05:30/06:00: no se pisa con
        // ninguno de los cinco comandos ya agendados del mismo comercio.
        //
        // Costo permanente en los ~40 clientes reales sin el módulo: CERO consultas a la base. El
        // gate de este if corta antes de que el comando arranque y el $company_owner ya está
        // resuelto arriba (:34) para el resto del schedule.
        if ($company_owner && UserHelper::hasExtencion('puntos_clientes', $company_owner)) {
            $schedule->command('puntos:vencer')
                ->dailyAt('04:30')
                ->withoutOverlapping(30);
        }

        // Reporte de inventario (stock mínimo, sin stock, valuación) de cada comercio, una vez por
        // noche (misión optimizacion-vps-fase1, 4.0.24). Hasta la 4.0.23 se regeneraba desde
        // InventoryPerformanceController::index() en cada entrada al sistema con un reporte de más
        // de 30 minutos, o sea todo el día: en servian (537k artículos) cada corrida son 18-20 min
        // de worker. Ahora index() sólo encola si no hay reporte o si tiene más de 7 días, y el
        // botón Actualizar lo dispara a pedido.
        //
        // 04:00: después del backup nocturno del VPS (03:15) y antes de sugerencias:generar (05:00)
        // y compras:generar (05:30), que recorren el mismo catálogo del mismo comercio. Comparte la
        // hora con tracking:purgar-buyers, que sólo corre con la extensión tracking_buyers y borra
        // otra tabla. withoutOverlapping(120) y no el default de 1440: el job tiene timeout de
        // 60 min, y si un día se cuelga, el comando no queda mudo un día entero.
        //
        // Sin gate por extensión (el reporte es de todos los comercios) y sin ->when(): corre una
        // vez por día, no por minuto, y adentro el candado atómico de Cache::add evita que se pise
        // con una generación pedida a mano. El comando resuelve el dueño por app.USER_ID igual que
        // el resto del schedule; sin USER_ID (dev/testing) recorre los dueños con actividad.
        $schedule->command('inventario:generar')
            ->dailyAt('04:00')
            ->withoutOverlapping(120);

        // Cierre mensual del rendimiento del comercio (company_performances / article_performances,
        // misión optimizacion-vps-fase1, 4.0.24): el día 1 borra lo que se fue calculando durante
        // el mes anterior y lo recrea completo, así que es idempotente y una segunda corrida el
        // mismo día da el mismo resultado. En el shared convive con el cron manual que algunas
        // instancias ya tenían (dos corridas el día 1); en el VPS nunca corrió — supervisor sólo
        // tiene queue y schedule — y con esto empieza a correr.
        //
        // 06:30: después de la ventana 03:30-06:00 de los otros comandos nocturnos del mismo
        // comercio. withoutOverlapping(120): recorre un mes entero de ventas.
        //
        // 🔴 El ->when() con app.USER_ID no es cosmética: sin USER_ID el comando cae a su lista
        // hardcodeada de ids [121, 228, 2] (Colman, HiperMax, Fenix), que en cualquier otra base
        // son otros comercios o no existen. En una instancia de cliente USER_ID siempre está.
        $schedule->command('set_company_performances')
            ->monthlyOn(1, '06:30')
            ->withoutOverlapping(120)
            ->when(function () {
                return ! empty(config('app.USER_ID'));
            });

        // check_stocks NO se agenda, a propósito (decisión de la misión optimizacion-vps-fase1).
        // No es una función del cliente: recorre TODO el catálogo con ->get() (en servian son 537k
        // modelos Eloquent en memoria, un OOM seguro), hace una consulta a stock_movements por
        // artículo (N+1) y le manda un mail a Lucas con los que no coinciden con su último
        // movimiento. Queda como corrida manual hasta que se reescriba como una consulta agregada.

        // Reintenta cada 5 minutos los mensajes de soporte no sincronizados a admin-api.
        $schedule->command('support:retry-pending-syncs')->everyFiveMinutes();

        // Barrido de los eventos de la demo que quedaron sin empujar al admin (misión 50).
        // Cada minuto porque el panel del lead lo poléa cada 10 segundos esperando ver el
        // movimiento en vivo; el push inmediato va por afterResponse y esto es la red de abajo.
        //
        // En una instancia que no es de demo el comando sale en su primera línea — pero ojo:
        // sale DESPUÉS de un SELECT a demo_tracking_config. Lo que la misión 50 exige en cero es
        // el camino del request del usuario, y ahí sí es cero; esto es el cron, y no hay forma
        // de saber si hay canal sin preguntárselo a la base.
        //
        // Ese SELECT ahora se hace acá, en el when(), con el mismo hay_canal() que el comando
        // evalúa primero: en los ~40 clientes reales el costo permanente pasa de "un artisan por
        // minuto que sale en su primera línea" a un SELECT sobre una tabla vacía en el proceso
        // que ya está corriendo. En demo, demo2 y demo3 hay canal y el comando corre cada minuto
        // como siempre. Si la tabla todavía no existe, hay_canal() ya devuelve false (y loguea).
        $schedule->command('demo:flush-eventos')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->when(function () {
                return DemoTrackingConfigHelper::hay_canal();
            });

        // Captura el snapshot de deuda diario (clientes y proveedores) a las 23:59.
        // Registra los saldos actuales de credit_accounts para análisis histórico.
        $schedule->command('debt:snapshot')->dailyAt('23:59');

        // Watchdog de importaciones colgadas: desbloquea imports que murieron sin dejar traza.
        // withoutOverlapping(10) permite reintentos si el comando muere; por default (1440 min = 24 horas)
        // el comando quedaría "trabado" un día entero sin poder correr de nuevo.
        // Corre cada 5 min, 10 min de margen es suficiente sin dejarlo mudo por 24 horas.
        //
        // El when() mira solo si hay alguna importación en estado activo (los mismos dos estados
        // que busca el comando, sin el umbral de minutos: eso lo sigue decidiendo el comando).
        // Sin ninguna en curso no hay nada que pueda estar colgado, y ese es el estado normal de
        // una instancia el 99 % del tiempo. Con una en curso el comando corre igual que antes.
        $schedule->command('imports:detectar-colgadas')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->when(function () {
                return $this->gate_de_datos(function () {
                    return ImportHistory::whereIn('status', ['en_preparacion', 'en_proceso'])->exists();
                });
            });

        // Watchdog de historiales colgados: desbloquea exportaciones (y a futuro imports) que murieron sin traza.
        // withoutOverlapping(10) permite reintentos si el comando muere; por default (1440 min = 24 horas)
        // el comando quedaría "trabado" un día entero sin poder correr de nuevo.
        // Corre cada 5 min, 10 min de margen es suficiente sin dejarlo mudo por 24 horas.
        //
        // Mismo gate que el de importaciones, sobre export_histories en 'pending'/'processing'
        // (los dos estados que busca DetectarHistorialesColgados::tipos()). Si algún día se suma
        // otro tipo de historial a ese comando, hay que sumarlo también acá.
        $schedule->command('historiales:detectar-colgados')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->when(function () {
                return $this->gate_de_datos(function () {
                    return ExportHistory::whereIn('status', ['pending', 'processing'])->exists();
                });
            });

        // Renueva los access_token de Mercado Pago próximos a vencer (grupo 170, prompt 598).
        // Corre para TODOS los comercios con conector de Mercado Pago (no depende de
        // company_owner / extensiones), porque el vínculo OAuth es por conector, no por
        // instancia. Desde la misión de ABM -> Integraciones esos tokens viven en
        // `platform_connectors` y no más en `online_configurations.mp_*`.
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
     * Evalúa un gate de datos de un `->when()` sin que una excepción tumbe schedule:run entero.
     *
     * Los filtros del schedule corren adentro del proceso de schedule:run y
     * `Event::filtersPass()` no atrapa nada: si la consulta tira (la tabla todavía no existe en
     * la ventana entre copiar el código y correr las migraciones de un upgrade, la base no
     * responde, etc.), la excepción cortaría el schedule:run de ESE minuto para todos los
     * comandos que vienen después. El gate es un ahorro, no una guarda: si no se puede decidir,
     * se corre el comando como hasta hoy, y se deja una línea en el log.
     *
     * @param  \Closure  $gate  Devuelve true si hay trabajo para el comando.
     * @return bool
     */
    protected function gate_de_datos(\Closure $gate)
    {
        try {
            return (bool) $gate();
        } catch (\Throwable $e) {
            Log::warning('Kernel: no se pudo evaluar el gate de un comando del schedule, se corre igual: ' . $e->getMessage());

            return true;
        }
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
