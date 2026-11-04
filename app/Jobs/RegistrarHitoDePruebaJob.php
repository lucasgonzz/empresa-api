<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\DemoEventosPushHelper;
use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Models\DemoEvento;
use App\Models\PruebaHito;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registra un hito de la prueba gratuita y lo empuja al admin (C2, misión prueba-experiencia-empresa).
 *
 * Qué hace, en orden:
 *  1. Si el dueño no está en modo prueba, nada.
 *  2. `prueba_hitos` (unique dueño + tipo): si el tipo ya estaba, termina. Es lo que garantiza «una vez cada
 *     uno». `prueba.ingreso` es la excepción: se registra la primera vez y se emite todas las veces.
 *  3. Con canal (`demo_tracking_config`), escribe la fila de `demo_eventos` con `nombre = tipo` y empuja con
 *     `DemoEventosPushHelper::flush()`: el mismo POST, el mismo header y la misma forma que los eventos de la
 *     demo, así el admin lo recibe por el canal que ya tiene. Si el push falla, el cron `demo:flush-eventos`
 *     lo reintenta.
 *
 * Corre en el worker. Nada de esto pasa en el request que produjo el hito.
 */
class RegistrarHitoDePruebaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $tries = 3;

    /** @var int */
    public $timeout = 60;

    /** @var int */
    public $owner_id;

    /** @var string */
    public $tipo;

    /** @var array */
    public $datos;

    /** @var \Carbon\Carbon */
    public $ocurrido_at;

    /**
     * @param int $owner_id
     * @param string $tipo
     * @param array $datos
     * @param \Carbon\Carbon $ocurrido_at
     */
    public function __construct($owner_id, $tipo, array $datos, Carbon $ocurrido_at)
    {
        $this->owner_id = (int) $owner_id;
        $this->tipo = (string) $tipo;
        $this->datos = $datos;
        $this->ocurrido_at = $ocurrido_at;
    }

    /**
     * @return void
     */
    public function handle()
    {
        PruebaHelper::olvidar_cache();

        if (!PruebaHelper::activa($this->owner_id)) {
            return;
        }

        $primera_vez = $this->registrar_primera_vez();

        if (!$primera_vez && $this->tipo !== 'prueba.ingreso') {
            return;
        }

        if (!DemoTrackingConfigHelper::hay_canal()) {
            return;
        }

        DemoEvento::create([
            'uuid'            => (string) Str::uuid(),
            'nombre'          => $this->tipo,
            'clip_id'         => null,
            'datos'           => $this->datos_acotados(),
            'ocurrido_at'     => $this->ocurrido_at,
            'sincronizado_at' => null,
            'intentos'        => 0,
            'ultimo_error'    => null,
        ]);

        try {
            DemoEventosPushHelper::flush();
        } catch (\Throwable $e) {
            // Queda pendiente en demo_eventos: el barrido del minuto lo vuelve a intentar.
            Log::warning('RegistrarHitoDePruebaJob: el push de "' . $this->tipo . '" quedó para el barrido: ' . $e->getMessage());
        }
    }

    /**
     * Deja el primer momento del hito en `prueba_hitos`. Devuelve true solo si ESTA llamada lo creó.
     *
     * Es la base la que decide (índice único), no una lectura previa que otro job puede invalidar entre
     * el select y el insert: dos ventas casi juntas no pueden emitir dos `prueba.venta`.
     *
     * @return bool
     */
    private function registrar_primera_vez()
    {
        try {
            $hito = PruebaHito::firstOrCreate(
                ['owner_id' => $this->owner_id, 'tipo' => $this->tipo],
                ['ocurrido_at' => $this->ocurrido_at, 'created_at' => Carbon::now()]
            );

            return (bool) $hito->wasRecentlyCreated;
        } catch (QueryException $e) {
            // Chocó contra el índice único: otro job lo registró un instante antes.
            return false;
        }
    }

    /**
     * Solo escalares cortos y hasta 20 claves: `datos` viaja al admin tal cual y se guarda en una columna
     * acotada (mismo criterio que DemoEventoEmitter::acotar_datos()).
     *
     * @return array
     */
    private function datos_acotados()
    {
        $acotados = [];

        foreach ($this->datos as $clave => $valor) {
            if (count($acotados) >= 20) {
                break;
            }

            if (is_null($valor) || is_bool($valor) || is_int($valor) || is_float($valor)) {
                $acotados[(string) $clave] = $valor;
            } elseif (is_string($valor)) {
                $acotados[(string) $clave] = mb_substr($valor, 0, 200);
            }
        }

        return $acotados;
    }
}
