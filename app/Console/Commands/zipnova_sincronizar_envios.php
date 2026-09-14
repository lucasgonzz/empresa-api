<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ZipnovaCredentialsHelper;
use App\Models\Envio;
use App\Services\Zipnova\ZipnovaEnvioService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza contra Zipnova el estado de los envíos que todavía están en curso (misión
 * zipnova-envios, 14/9/2026). Corre cada 30 minutos (ver `app/Console/Kernel.php`).
 *
 * Es la red de abajo del webhook: Zipnova avisa cada cambio de estado con un POST a
 * `/api/zipnova/webhook`, pero el WAF del shared hosting ya frenó webhooks en ráfaga (pasó con
 * Kapso, memoria del pool) y un comercio puede haber conectado sin que el webhook se registrara.
 * Con esto, el estado que ve el operador nunca queda más de media hora atrás aunque no llegue
 * ningún aviso.
 *
 * Qué toca: envíos de `zipnova` con id en Zipnova, en un estado NO final (ver
 * `Envio::ESTADOS_FINALES`) y que no sean uno de los propios sin seguimiento (`error`,
 * `not_found`, `generando`), y que no se hayan sincronizado en los últimos 30 minutos —el
 * webhook o el botón "Actualizar estado" pueden haberlo hecho recién—. Un 404 de Zipnova deja
 * la fila en `not_found` (lo hace `ZipnovaEnvioService::sincronizar_con()`), así que ese envío
 * no se vuelve a consultar cada media hora para siempre.
 *
 * Se agrupa por comercio y el `ZipnovaClient` se resuelve UNA vez por comercio (descifrar el
 * token cuesta, y un comercio con 50 envíos en curso no tiene por qué pagarlo 50 veces); cada
 * envío va en su propio try/catch para que uno que falla no corte el resto.
 *
 * Recorre TODOS los comercios de la base (no solo `app.USER_ID`): en las bases compartidas
 * viejas conviven varios, y el conector es por comercio.
 */
class zipnova_sincronizar_envios extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zipnova:sincronizar-envios {--minutos=30 : No volver a consultar un envío sincronizado hace menos de estos minutos}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Trae desde Zipnova el estado de los envíos en curso (red de seguridad del webhook).';

    /**
     * Ejecuta el comando.
     *
     * @return int
     */
    public function handle()
    {
        $minutos = (int) $this->option('minutos');
        if ($minutos < 0) {
            $minutos = 0;
        }
        $limite = Carbon::now()->subMinutes($minutos);

        $envios = Envio::where('proveedor', Envio::PROVEEDOR_ZIPNOVA)
            ->whereNotNull('proveedor_envio_id')
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereNotIn('status', array_merge(Envio::ESTADOS_FINALES, Envio::ESTADOS_SIN_SEGUIMIENTO));
            })
            ->where(function ($q) use ($limite) {
                $q->whereNull('ultima_sincronizacion')
                    ->orWhere('ultima_sincronizacion', '<', $limite);
            })
            ->orderBy('user_id')
            ->orderBy('id')
            ->get();

        $this->info('zipnova:sincronizar-envios: ' . $envios->count() . ' envío(s) en curso para consultar.');

        if ($envios->count() === 0) {
            return 0;
        }

        $service = new ZipnovaEnvioService();
        $sincronizados = 0;
        $fallidos = 0;

        foreach ($envios->groupBy('user_id') as $user_id => $envios_del_comercio) {
            // Un comercio que se desconectó (o cuyo token no se puede descifrar) no tiene con qué
            // consultar: se anota una vez y se saltean sus envíos sin llamar a Zipnova.
            $client = ZipnovaCredentialsHelper::client((int) $user_id);

            if (is_null($client)) {
                $fallidos += $envios_del_comercio->count();
                Log::warning('zipnova:sincronizar-envios: comercio ' . $user_id . ' sin Zipnova conectado, se saltean sus ' . $envios_del_comercio->count() . ' envío(s) en curso.');

                continue;
            }

            foreach ($envios_del_comercio as $envio) {
                try {
                    $service->sincronizar_con($client, $envio);
                    $sincronizados++;
                } catch (\Throwable $e) {
                    $fallidos++;
                    Log::warning('zipnova:sincronizar-envios: no se pudo sincronizar el envío ' . $envio->id . ' (Zipnova ' . $envio->proveedor_envio_id . ', user_id ' . $user_id . '): ' . $e->getMessage());
                }
            }
        }

        $this->info('zipnova:sincronizar-envios: ' . $sincronizados . ' sincronizado(s), ' . $fallidos . ' con error.');

        return 0;
    }
}
