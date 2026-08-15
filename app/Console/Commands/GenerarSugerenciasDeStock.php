<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\GenerateStockSuggestionChunksJob;
use App\Models\StockSuggestion;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Genera la sugerencia de stock automática del comercio de la instancia según
 * la periodicidad configurada en Configuración → general.
 *
 * La decisión de "hoy toca" se toma contra sugerencias_ultima_generacion_at
 * (cuántos días pasaron desde la última generación automática), NO contra el
 * día del calendario: una instancia apagada un día no se saltea el ciclo
 * entero, y el comando es seguro de correr a mano cuantas veces se quiera.
 *
 * Reusa el mismo GenerateStockSuggestionChunksJob del flujo manual: lo que se
 * prueba a mano es literalmente lo que corre de noche.
 */
class GenerarSugerenciasDeStock extends Command
{
    /**
     * Nombre y firma del comando Artisan. --force genera aunque según la
     * periodicidad hoy no toque (no saltea el gate por extensión).
     *
     * @var string
     */
    protected $signature = 'sugerencias:generar {--force : Genera aunque la periodicidad diga que hoy no toca}';

    /**
     * Descripción del comando para php artisan list.
     *
     * @var string
     */
    protected $description = 'Genera la sugerencia de stock automática del comercio según su periodicidad configurada';

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
     * Ejecuta el comando sobre el dueño de la instancia (config app.USER_ID).
     *
     * @return int Código de salida (0 = OK)
     */
    public function handle()
    {
        $user = $this->resolver_comercio();

        if (!$user) {
            $this->info('sugerencias:generar — no hay comercio configurado (app.USER_ID), no se genera nada.');
            return 0;
        }

        // Doble gate: el Kernel ya filtra por extensión, pero el comando puede
        // correrse a mano y la extensión es lo que se le vendió (o no) al
        // cliente. --force no saltea esto.
        if (!UserHelper::hasExtencion('sugerencias_inteligentes', $user)) {
            $this->info('sugerencias:generar — el comercio no tiene la extensión sugerencias_inteligentes, no se genera nada.');
            return 0;
        }

        $periodicidad = (string) $user->sugerencias_periodicidad;

        if (!$this->option('force')) {

            if ($periodicidad === 'nunca' || $periodicidad === '') {
                $this->info('sugerencias:generar — periodicidad "nunca", no se genera nada.');
                return 0;
            }

            if (!array_key_exists($periodicidad, self::DIAS_POR_PERIODICIDAD)) {
                $this->warn('sugerencias:generar — periodicidad desconocida "' . $periodicidad . '", no se genera nada.');
                return 0;
            }

            if (!$this->hoy_toca($user, self::DIAS_POR_PERIODICIDAD[$periodicidad])) {
                $this->info('sugerencias:generar — todavía no pasaron los días de la periodicidad "' . $periodicidad . '", no se genera nada.');
                return 0;
            }
        }

        $suggestion = StockSuggestion::create([
            'modo'              => $user->sugerencias_modo ? $user->sugerencias_modo : 'minimo',
            'origen'            => $user->sugerencias_origen ? $user->sugerencias_origen : 'absoluto',
            'limite_origen'     => $user->sugerencias_limite_origen ? $user->sugerencias_limite_origen : 'minimo',
            'status'            => 'pendiente',
            'origen_generacion' => 'automatica',
            'user_id'           => $user->id,
        ]);

        // La marca de última generación se registra al CREAR, no al terminar:
        // si el cálculo falla, queda en status error y no se reintenta en loop
        // hasta el próximo ciclo de la periodicidad.
        $user->sugerencias_ultima_generacion_at = now();
        $user->save();

        // Siempre por cola (a las 05:00 no hay request HTTP que proteger): el
        // camino de errores tries/failed del job queda activo completo.
        dispatch(new GenerateStockSuggestionChunksJob($suggestion->id));

        $this->info('sugerencias:generar — sugerencia automática #' . $suggestion->id . ' creada y despachada.');

        return 0;
    }

    /**
     * true si desde la última generación pasaron al menos $dias días
     * calendario. Se compara por fecha (startOfDay) y no por timestamp
     * exacto, para que la deriva de segundos del cron no saltee un día
     * (generada ayer 05:00:10, cron hoy 05:00:05 seguiría siendo "hoy toca").
     *
     * @param User $user
     * @param int $dias
     * @return bool
     */
    protected function hoy_toca($user, $dias)
    {
        $ultima = $user->sugerencias_ultima_generacion_at;

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
