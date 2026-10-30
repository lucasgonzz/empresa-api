<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\DimensionSearchRunHelper;
use App\Models\DimensionSearchRun;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * El vigía de la búsqueda masiva de peso y medidas (misión medidas-vigia-de-corridas, 30/10/2026).
 *
 *     php artisan medidas:reanudar-trabadas             # reanuda las corridas en proceso sin señal de vida
 *     php artisan medidas:reanudar-trabadas --dry-run   # solo lista las que reanudaría
 *
 * El problema que resuelve: la base del VPS la mata el sistema operativo cada noche cerca de las 00:00 y tarda
 * unos minutos en volver. Si el corte es más largo que lo que el tramo aguanta esperando (ver
 * ProcessDimensionSearchRunJob), el tramo muere y la corrida queda `en_proceso` SIN ningún job en la cola:
 * nada la levanta y la pantalla solo avisa «Parece trabada» hasta que alguien apriete Reanudar. Este comando
 * hace ese clic solo.
 *
 * Qué toca y qué no:
 *   - SOLO las corridas `en_proceso` que `esta_trabada()` (15 minutos sin señal de vida). Una `detenida` o
 *     `fallida` es una decisión de una persona (o un corte con motivo explicado) y NO se reanuda sola; una
 *     `pendiente` espera a su worker y una `terminada` ya terminó.
 *   - Reanuda con DimensionSearchRunHelper::reanudar(), el mismo camino del botón de la pantalla.
 *
 * Tope: como mucho MAX_REANUDACIONES_POR_VENTANA reanudaciones automáticas por corrida cada 24 horas (el
 * contador vive en la caché, no hay columna nueva). Pasado el tope no reanuda y deja un error en el log: una
 * corrida que se traba una y otra vez necesita a una persona. La pantalla sigue mostrando «Parece trabada».
 *
 * Nunca rompe el scheduler: si la tabla no existe (cliente con una versión vieja) o la base falla, sale en
 * silencio con código 0.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class MedidasReanudarTrabadas extends Command
{
    /** Reanudaciones automáticas máximas de una misma corrida dentro de la ventana. */
    const MAX_REANUDACIONES_POR_VENTANA = 6;

    /** Horas que dura la ventana en la que se cuentan las reanudaciones automáticas de una corrida. */
    const HORAS_DE_LA_VENTANA = 24;

    /** Minutos que se recuerda que ya se avisó (log de error) del tope alcanzado, para no repetirlo cada 5 minutos. */
    const MINUTOS_ENTRE_AVISOS_DE_TOPE = 60;

    /**
     * @var string
     */
    protected $signature = 'medidas:reanudar-trabadas
                            {--dry-run : Solo lista las corridas que reanudaría: no escribe ni despacha nada}';

    /**
     * @var string
     */
    protected $description = 'Reanuda las búsquedas de peso y medidas que quedaron en proceso sin avanzar (con un tope de reanudaciones por día)';

    /**
     * Ejecuta el comando.
     *
     * @return int  Siempre 0: el vigía no debe romper el scheduler.
     */
    public function handle()
    {
        $dry_run = (bool) $this->option('dry-run');

        // Las corridas candidatas. Si la tabla no existe (versión vieja) o la base no responde, no hay nada que
        // vigilar: se sale en silencio.
        try {
            if (!Schema::hasTable('dimension_search_runs')) {
                return 0;
            }

            $trabadas = DimensionSearchRun::where('status', DimensionSearchRun::STATUS_EN_PROCESO)
                ->orderBy('id')
                ->get()
                ->filter(function ($run) {
                    return $run->esta_trabada();
                });
        } catch (\Throwable $e) {
            return 0;
        }

        foreach ($trabadas as $run) {
            try {
                $this->vigilar($run, $dry_run);
            } catch (\Throwable $e) {
                // Una corrida que no se pudo atender no frena a las demás, ni al scheduler.
                Log::warning('[MedidasMasivas] El vigía no pudo atender una corrida.', [
                    'run_id' => $run->id,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        return 0;
    }

    /**
     * Atiende una corrida trabada: respeta el tope, y reanuda (o, con --dry-run, solo lo dice).
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  bool $dry_run
     * @return void
     */
    protected function vigilar(DimensionSearchRun $run, $dry_run)
    {
        $ultima_senal = !is_null($run->last_progress_at) ? $run->last_progress_at : $run->started_at;
        $minutos      = (int) Carbon::parse($ultima_senal)->diffInMinutes(Carbon::now());
        $hechas       = $this->reanudaciones_hechas($run);

        if ($dry_run) {
            $this->line('Corrida #'.$run->id.' sin señal hace '.$minutos.' min: '
                .($hechas >= self::MAX_REANUDACIONES_POR_VENTANA
                    ? 'NO se reanudaría (ya se reanudó '.$hechas.' veces en '.self::HORAS_DE_LA_VENTANA.' h).'
                    : 'se reanudaría ('.$hechas.' reanudaciones automáticas en las últimas '.self::HORAS_DE_LA_VENTANA.' h).'));

            return;
        }

        // Pasado el tope, una persona tiene que mirar qué le pasa a esta corrida.
        if ($hechas >= self::MAX_REANUDACIONES_POR_VENTANA) {
            $this->avisar_tope($run, $hechas);

            return;
        }

        $resultado = DimensionSearchRunHelper::reanudar($run);

        if ($resultado['status'] !== 200) {
            // Por ejemplo, hay otra corrida de catálogo en curso o la demo se quedó sin búsquedas: no cuenta para el tope.
            Log::info('[MedidasMasivas] El vigía no pudo reanudar la corrida.', [
                'run_id' => $run->id,
                'motivo' => $resultado['message'],
            ]);

            return;
        }

        $this->sumar_reanudacion($run);

        Log::warning('[MedidasMasivas] Reanudada automáticamente', [
            'run_id'                => $run->id,
            'minutos_sin_senal'     => $minutos,
            'reanudaciones_en_24_h' => $hechas + 1,
        ]);

        $this->line('Corrida #'.$run->id.' reanudada (sin señal hace '.$minutos.' min).');
    }

    /**
     * La clave de la caché donde se cuentan las reanudaciones automáticas de una corrida.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return string
     */
    protected function clave_del_contador(DimensionSearchRun $run)
    {
        return 'medidas-masivas:reanudaciones-automaticas:'.$run->id;
    }

    /**
     * Cuántas veces el vigía reanudó esta corrida en la ventana. Si la caché falla se deja pasar (0): es
     * mejor reanudar una vez de más que dejar una corrida parada.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return int
     */
    protected function reanudaciones_hechas(DimensionSearchRun $run)
    {
        try {
            return (int) Cache::get($this->clave_del_contador($run), 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Suma una reanudación al contador. La ventana de 24 horas empieza con la primera y no se renueva con las
     * siguientes (`add` solo crea la clave si no existe; `increment` no cambia su vencimiento).
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return void
     */
    protected function sumar_reanudacion(DimensionSearchRun $run)
    {
        try {
            $clave = $this->clave_del_contador($run);

            Cache::add($clave, 0, Carbon::now()->addHours(self::HORAS_DE_LA_VENTANA));
            Cache::increment($clave);
        } catch (\Throwable $e) {
            // Sin caché no hay tope: se sigue reanudando.
        }
    }

    /**
     * Deja el error de «se necesita una persona» (a lo sumo uno por hora y por corrida, no uno cada 5 minutos).
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  int $hechas  Reanudaciones automáticas ya hechas en la ventana.
     * @return void
     */
    protected function avisar_tope(DimensionSearchRun $run, $hechas)
    {
        try {
            $primera_vez = Cache::add(
                'medidas-masivas:tope-avisado:'.$run->id,
                true,
                Carbon::now()->addMinutes(self::MINUTOS_ENTRE_AVISOS_DE_TOPE)
            );
        } catch (\Throwable $e) {
            // Sin caché no hay memoria del aviso: mejor repetirlo que callarlo.
            $primera_vez = true;
        }

        if (!$primera_vez) {
            return;
        }

        Log::error('[MedidasMasivas] La corrida #'.$run->id.' se reanudó '.$hechas.' veces en '.self::HORAS_DE_LA_VENTANA.' h y sigue trabándose: se necesita una persona.', [
            'run_id'     => $run->id,
            'reanudadas' => $hechas,
        ]);
    }
}
