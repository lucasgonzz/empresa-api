<?php

namespace App\Console\Commands;

use App\Models\Envio;
use App\Services\Zipnova\EnvioNoGenerableException;
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
 * `Envio::ESTADOS_FINALES`) y que no sean el `error` propio (nunca existieron allá), y que no
 * se hayan sincronizado en los últimos 30 minutos —el webhook o el botón "Actualizar estado"
 * pueden haberlo hecho recién—. Se agrupa por comercio para resolver sus credenciales una sola
 * vez; cada envío va en su propio try/catch para que uno que Zipnova ya no encuentra no corte
 * el resto.
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
                    ->orWhereNotIn('status', array_merge(Envio::ESTADOS_FINALES, [Envio::STATUS_ERROR]));
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
        $sin_conector = [];

        foreach ($envios->groupBy('user_id') as $user_id => $envios_del_comercio) {
            foreach ($envios_del_comercio as $envio) {
                // Un comercio que se desconectó no tiene con qué consultar: se anota una vez y
                // se saltean sus envíos sin llamar a Zipnova por cada uno.
                if (in_array((int) $user_id, $sin_conector, true)) {
                    continue;
                }

                try {
                    $service->sincronizar($envio);
                    $sincronizados++;
                } catch (EnvioNoGenerableException $e) {
                    $sin_conector[] = (int) $user_id;
                    $fallidos++;
                    Log::warning('zipnova:sincronizar-envios: comercio ' . $user_id . ' sin Zipnova conectado, se saltean sus envíos: ' . $e->getMessage());
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
