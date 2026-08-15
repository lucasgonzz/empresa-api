<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\GenerateOfferSuggestionChunksJob;
use App\Models\ClientOffer;
use App\Models\OfferSuggestion;
use App\Models\OfferSuggestionLine;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Genera la corrida automática del motor de ofertas del comercio de la instancia, según la
 * periodicidad configurada en Configuración → general. Molde literal de GenerarSugerenciasDeCompra.
 *
 * La decisión de "hoy toca" se toma contra users.ofertas_ultima_generacion_at (cuántos días pasaron
 * desde la última generación automática) y NO contra el día del calendario: una instancia apagada un
 * día no se saltea el ciclo entero, y el comando es seguro de correr a mano cuantas veces se quiera.
 *
 * Reusa el mismo GenerateOfferSuggestionChunksJob del flujo manual: lo que se prueba a mano es
 * literalmente lo que corre de noche.
 */
class GenerarOfertasSugeridas extends Command
{
    /**
     * Nombre y firma del comando Artisan. --force genera aunque según la periodicidad hoy no toque.
     * 🔴 --force NO saltea el gate por extensión: la extensión es lo que se le vendió (o no) al
     * cliente, y eso no lo destraba una bandera de consola.
     *
     * @var string
     */
    protected $signature = 'ofertas:generar {--force : Genera aunque la periodicidad diga que hoy no toca}';

    /** @var string Descripción del comando para php artisan list */
    protected $description = 'Genera la corrida automática de ofertas por cliente según la periodicidad configurada';

    /** Días que deben pasar desde la última generación para cada periodicidad. */
    const DIAS_POR_PERIODICIDAD = [
        'diaria'    => 1,
        'semanal'   => 7,
        'quincenal' => 15,
        'mensual'   => 30,
    ];

    /** Días que se conserva una corrida automática de la que no salió ninguna oferta activada. */
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
            $this->info('ofertas:generar — no hay comercio configurado (app.USER_ID), no se genera nada.');
            return 0;
        }

        // Doble gate: el Kernel ya filtra por extensión, pero el comando puede correrse a mano.
        if (!UserHelper::hasExtencion('motor_de_ofertas', $user)) {
            $this->info('ofertas:generar — el comercio no tiene la extensión motor_de_ofertas, no se genera nada.');
            return 0;
        }

        /*
         * 🔴 EL BARRIDO DE HIGIENE VA ACÁ ARRIBA, ANTES DE LOS EARLY-RETURN DE LA PERIODICIDAD, Y NO
         * ES UN DETALLE DE ORDEN.
         *
         * Estaba abajo, después del bloque de periodicidad, y el default de la migración es
         * ofertas_periodicidad = 'nunca': con ese default el comando salía por el return de arriba y
         * el barrido NO CORRÍA NUNCA. La creación manual tampoco lo llama. Modo de falla medido: un
         * comercio que usa el módulo a mano activa 30 ofertas a 15 días; tres meses después la
         * pestaña "Vigentes" le sigue mostrando las 30 con su botón Cancelar y "Vencidas" está
         * siempre vacía. La tienda ya no las aplica (filtra por CURDATE(), §A.6), así que la única
         * pantalla donde el comerciante mira "qué le estoy ofreciendo hoy" es justo la que miente.
         *
         * Por qué arriba y no enganchado al camino manual: esto es HIGIENE DE DATOS, no generación.
         * No mira la periodicidad porque no genera nada, no manda mails y no toca client_offers que
         * sigan vigentes — solo le pone la etiqueta correcta a lo que la fecha ya venció. El Kernel
         * corre ofertas:generar todos los días a las 06:00 con la sola condición de la extensión
         * (Kernel.php:90-94), así que desde acá el barrido pasa a correr a diario en cualquier
         * comercio con el módulo, tenga la periodicidad que tenga. Engancharlo además a la
         * activación manual sería un segundo lugar que hace lo mismo y que hay que acordarse de
         * mantener; con un solo lugar diario alcanza y sobra.
         *
         * 🔴 Lo que SÍ queda abajo del gate de periodicidad es purgar_automaticas_viejas(): eso
         * BORRA corridas, y borrar es una consecuencia de haber generado. Un comercio en 'nunca' no
         * genera automáticas, así que no tiene nada que purgar.
         */
        $vencidas = $this->vencer_ofertas_pasadas_de_fecha($user);

        if ($vencidas > 0) {
            $this->info('ofertas:generar — higiene: ' . $vencidas . ' ofertas activas pasaron de fecha y quedaron marcadas como vencidas.');
        }

        $periodicidad = (string) $user->ofertas_periodicidad;

        if (!$this->option('force')) {

            if ($periodicidad === 'nunca' || $periodicidad === '') {
                $this->info('ofertas:generar — periodicidad "nunca", no se genera nada.');
                return 0;
            }

            if (!array_key_exists($periodicidad, self::DIAS_POR_PERIODICIDAD)) {
                $this->warn('ofertas:generar — periodicidad desconocida "' . $periodicidad . '", no se genera nada.');
                return 0;
            }

            if (!$this->hoy_toca($user, self::DIAS_POR_PERIODICIDAD[$periodicidad])) {
                $this->info('ofertas:generar — todavía no pasaron los días de la periodicidad "' . $periodicidad . '", no se genera nada.');
                return 0;
            }
        }

        $borradas = $this->purgar_automaticas_viejas($user);

        if ($borradas > 0) {
            $this->info(
                'ofertas:generar — retención: se borraron ' . $borradas . ' corridas automáticas de más de '
                . self::DIAS_RETENCION_AUTOMATICAS . ' días sin ninguna oferta activada.'
            );
        }

        $suggestion = OfferSuggestion::create([
            'status'            => 'pendiente',
            'origen_generacion' => 'automatica',
            'user_id'           => $user->id,
        ]);

        // 🔴 La marca de última generación se registra al CREAR y no al terminar: si el cálculo
        // falla, la corrida queda en 'error' y NO se reintenta en loop hasta el próximo ciclo de la
        // periodicidad. Con la marca al final, un comercio con una corrida que revienta se generaría
        // una corrida fallida por minuto.
        $user->ofertas_ultima_generacion_at = now();
        $user->save();

        // Siempre por cola (a las 06:00 no hay request HTTP que proteger): el camino de errores
        // tries/failed del job queda activo completo.
        dispatch(new GenerateOfferSuggestionChunksJob($suggestion->id));

        $this->info('ofertas:generar — corrida automática #' . $suggestion->id . ' creada y despachada.');

        return 0;
    }

    /**
     * Higiene de las ofertas activas: las que ya pasaron su fecha `hasta` quedan en 'vencida'.
     *
     * 🔴 Esto NO es lo que decide si una oferta se aplica: la verdad de la vigencia la dan las
     * fechas, y la query que corre la tienda ya filtra por desde/hasta contra CURDATE() (§A.6). Este
     * barrido existe para que el listado del ERP no muestre como "activa" una promoción que venció
     * hace tres meses. Si alguien lo borra, la tienda sigue andando bien igual.
     *
     * 🔴 Se llama ANTES del gate de periodicidad (ver el comentario largo de handle()): con el
     * default 'nunca' de la migración, desde abajo no corría jamás.
     *
     * @param  User $user
     * @return int Cantidad de ofertas marcadas
     */
    protected function vencer_ofertas_pasadas_de_fecha($user)
    {
        return ClientOffer::where('user_id', $user->id)
            ->where('estado', 'activa')
            ->whereDate('hasta', '<', now()->toDateString())
            ->update(['estado' => 'vencida']);
    }

    /**
     * Retención de las corridas automáticas: borra (cabecera + líneas, en transacción) las del
     * comercio con más de DIAS_RETENCION_AUTOMATICAS días de las que NO salió ninguna oferta
     * activada. La generación periódica sin retención acumula millones de filas por año; 30 días
     * conservan historial útil, y una corrida que sirvió para algo (dejó una promoción vigente) se
     * conserva para siempre porque es la explicación de por qué esa oferta existe.
     *
     * 🔴 Las manuales no se tocan jamás, y las client_offers tampoco: una promoción vigente es un
     * compromiso con el cliente y con la tienda, y borrar el borrador que la propuso no puede darla
     * de baja (mismo criterio que OfferSuggestionController::destroy).
     *
     * @param  User $user
     * @return int Cantidad de corridas borradas
     */
    protected function purgar_automaticas_viejas($user)
    {
        $viejas = OfferSuggestion::where('user_id', $user->id)
            ->where('origen_generacion', 'automatica')
            ->where('created_at', '<', now()->subDays(self::DIAS_RETENCION_AUTOMATICAS))
            ->whereDoesntHave('lines', function ($q) {
                $q->whereNotNull('client_offer_id');
            })
            ->get();

        foreach ($viejas as $vieja) {
            DB::transaction(function () use ($vieja) {
                OfferSuggestionLine::where('offer_suggestion_id', $vieja->id)->delete();
                $vieja->delete();
            });
        }

        return $viejas->count();
    }

    /**
     * true si desde la última generación pasaron al menos $dias días calendario. Se compara por
     * fecha (startOfDay) y no por timestamp exacto, para que la deriva de segundos del cron no
     * saltee un día (generada ayer 06:00:10, cron hoy 06:00:05 seguiría siendo "hoy toca").
     *
     * @param  User $user
     * @param  int  $dias
     * @return bool
     */
    protected function hoy_toca($user, $dias)
    {
        $ultima = $user->ofertas_ultima_generacion_at;

        if (!$ultima) {
            return true;
        }

        return \Carbon\Carbon::parse($ultima)->startOfDay()->diffInDays(now()->startOfDay()) >= $dias;
    }

    /**
     * Dueño de la instancia con sus extensiones cargadas (mismo criterio que el resolve del Kernel).
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
