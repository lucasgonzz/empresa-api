<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\GeneratePurchaseSuggestionChunksJob;
use App\Models\PurchaseSuggestion;
use App\Models\PurchaseSuggestionArticle;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Genera la sugerencia de compra a proveedores automática del comercio de la
 * instancia según la periodicidad configurada en Configuración → general.
 * Molde literal de GenerarSugerenciasDeStock, adaptado al dominio de compras.
 *
 * La decisión de "hoy toca" se toma contra
 * sugerencias_compras_ultima_generacion_at (cuántos días pasaron desde la
 * última generación automática), NO contra el día del calendario: una
 * instancia apagada un día no se saltea el ciclo entero, y el comando es
 * seguro de correr a mano cuantas veces se quiera.
 *
 * Reusa el mismo GeneratePurchaseSuggestionChunksJob del flujo manual: lo que
 * se prueba a mano es literalmente lo que corre de noche.
 */
class GenerarSugerenciasDeCompra extends Command
{
    /**
     * Nombre y firma del comando Artisan. --force genera aunque según la
     * periodicidad hoy no toque (no saltea el gate por extensión).
     *
     * @var string
     */
    protected $signature = 'compras:generar {--force : Genera aunque la periodicidad diga que hoy no toca}';

    /**
     * Descripción del comando para php artisan list.
     *
     * @var string
     */
    protected $description = 'Genera la sugerencia de compra a proveedores automática del comercio según su periodicidad configurada';

    /**
     * Días que deben pasar desde la última generación para cada periodicidad.
     *
     * @var array
     */
    const DIAS_POR_PERIODICIDAD = [
        'diaria'    => 1,
        'semanal'   => 7,
        'quincenal' => 15,
        'mensual'   => 30,
    ];

    /**
     * Días que se conserva una sugerencia automática sin órdenes de compra asociadas.
     *
     * @var int
     */
    const DIAS_RETENCION_AUTOMATICAS = 30;

    /**
     * Ejecuta el comando sobre el dueño de la instancia (config app.USER_ID).
     *
     * @return int Código de salida (0 = OK)
     */
    public function handle()
    {
        $user = $this->resolver_comercio();

        if (!$user) {
            $this->info('compras:generar — no hay comercio configurado (app.USER_ID), no se genera nada.');
            return 0;
        }

        // Doble gate: el Kernel ya filtra por extensión, pero el comando
        // puede correrse a mano y la extensión es lo que se le vendió (o no)
        // al cliente. --force no saltea esto.
        if (!UserHelper::hasExtencion('sugerencias_compras', $user)) {
            $this->info('compras:generar — el comercio no tiene la extensión sugerencias_compras, no se genera nada.');
            return 0;
        }

        $periodicidad = (string) $user->sugerencias_compras_periodicidad;

        if (!$this->option('force')) {

            if ($periodicidad === 'nunca' || $periodicidad === '') {
                $this->info('compras:generar — periodicidad "nunca", no se genera nada.');
                return 0;
            }

            if (!array_key_exists($periodicidad, self::DIAS_POR_PERIODICIDAD)) {
                $this->warn('compras:generar — periodicidad desconocida "' . $periodicidad . '", no se genera nada.');
                return 0;
            }

            if (!$this->hoy_toca($user, self::DIAS_POR_PERIODICIDAD[$periodicidad])) {
                $this->info('compras:generar — todavía no pasaron los días de la periodicidad "' . $periodicidad . '", no se genera nada.');
                return 0;
            }
        }

        $borradas = $this->purgar_automaticas_viejas($user);

        if ($borradas > 0) {
            $this->info(
                'compras:generar — retención: se borraron ' . $borradas
                . ' sugerencias automáticas de más de ' . self::DIAS_RETENCION_AUTOMATICAS
                . ' días sin órdenes de compra asociadas.'
            );
        }

        $suggestion = PurchaseSuggestion::create([
            'status'            => 'pendiente',
            'origen_generacion' => 'automatica',
            'user_id'           => $user->id,
        ]);

        // La marca de última generación se registra al CREAR, no al terminar:
        // si el cálculo falla, queda en status error y no se reintenta en loop
        // hasta el próximo ciclo de la periodicidad.
        $user->sugerencias_compras_ultima_generacion_at = now();
        $user->save();

        // Siempre por cola (a las 05:30 no hay request HTTP que proteger): el
        // camino de errores tries/failed del job queda activo completo.
        dispatch(new GeneratePurchaseSuggestionChunksJob($suggestion->id));

        $this->info('compras:generar — sugerencia automática #' . $suggestion->id . ' creada y despachada.');

        return 0;
    }

    /**
     * Retención de las sugerencias automáticas: borra (cabecera + líneas, en
     * transacción) las del usuario con más de DIAS_RETENCION_AUTOMATICAS días
     * que no tengan una orden de compra generada desde el botón "Generar
     * orden de compra". La generación periódica sin retención acumula
     * millones de filas por año; 30 días conservan historial útil, y lo que
     * se usó (generó una orden real) queda para siempre porque es la
     * explicación de por qué esa orden existe. Las manuales no se tocan
     * jamás.
     *
     * @param User $user
     * @return int Cantidad de sugerencias borradas
     */
    protected function purgar_automaticas_viejas($user)
    {
        $viejas = PurchaseSuggestion::where('user_id', $user->id)
            ->where('origen_generacion', 'automatica')
            ->where('created_at', '<', now()->subDays(self::DIAS_RETENCION_AUTOMATICAS))
            ->whereDoesntHave('provider_orders')
            ->get();

        foreach ($viejas as $vieja) {
            DB::transaction(function () use ($vieja) {
                PurchaseSuggestionArticle::where('purchase_suggestion_id', $vieja->id)->delete();
                $vieja->delete();
            });
        }

        return $viejas->count();
    }

    /**
     * true si desde la última generación pasaron al menos $dias días
     * calendario. Se compara por fecha (startOfDay) y no por timestamp
     * exacto, para que la deriva de segundos del cron no saltee un día
     * (generada ayer 05:30:10, cron hoy 05:30:05 seguiría siendo "hoy toca").
     *
     * @param User $user
     * @param int $dias
     * @return bool
     */
    protected function hoy_toca($user, $dias)
    {
        $ultima = $user->sugerencias_compras_ultima_generacion_at;

        if (!$ultima) {
            return true;
        }

        $ultima_fecha = \Carbon\Carbon::parse($ultima)->startOfDay();

        return $ultima_fecha->diffInDays(now()->startOfDay()) >= $dias;
    }

    /**
     * Dueño de la instancia con sus extensiones cargadas (mismo criterio que
     * el resolve del Kernel).
     *
     * @return User|null
     */
    protected function resolver_comercio()
    {
        $user_id = config('app.USER_ID');

        if (empty($user_id)) {
            return null;
        }

        return User::with('extencions')->find($user_id);
    }
}
