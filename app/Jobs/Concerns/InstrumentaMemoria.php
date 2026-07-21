<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Helpers de memoria compartidos por los jobs de exportación pesada. Permiten: subir el memory_limit a un
 * piso razonable (best effort), cortar temprano con un mensaje accionable si ya arrancamos cerca del techo,
 * y leer el pico de uso para instrumentar.
 */
trait InstrumentaMemoria
{
    /**
     * Sube el memory_limit a un piso razonable si está por debajo (nunca lo baja). Best effort: en hosting
     * compartido puede estar capado y el ini_set no tener efecto real.
     *
     * @return void
     */
    protected function asegurar_memoria_minima()
    {
        $piso_bytes = 512 * 1024 * 1024; // 512 MB
        $actual = $this->memory_limit_bytes();

        // -1 => sin límite: no tocar. 0 => no parseable: no tocar.
        if ($actual > 0 && $actual < $piso_bytes) {
            @ini_set('memory_limit', '512M');
        }
    }

    /**
     * Si ya estamos usando más del 85% del memory_limit al arrancar, corta con un mensaje accionable. Mejor
     * un fallo claro inmediato (que el catch del handle marca 'failed' y notifica) que un OOM crudo sin log.
     *
     * @param  string  $etiqueta  Contexto para el mensaje (ej: 'exportación de artículos').
     * @return void
     */
    protected function verificar_memoria_disponible($etiqueta)
    {
        $limite = $this->memory_limit_bytes();

        if ($limite <= 0) {
            return; // sin límite o no parseable: nada que verificar
        }

        $en_uso = memory_get_usage(true);
        $umbral = (int) ($limite * 0.85);

        if ($en_uso > $umbral) {
            $usados_mb = number_format($en_uso / 1048576, 0);
            $limite_mb = number_format($limite / 1048576, 0);

            throw new \RuntimeException(
                'Memoria insuficiente al iniciar la ' . $etiqueta . ': '
                . $usados_mb . ' MB usados de ' . $limite_mb . ' MB. '
                . 'Reducí la selección, o subí el memory_limit del worker / el plan de hosting.'
            );
        }
    }

    /**
     * Devuelve el memory_limit vigente en bytes. -1 (sin límite) => -1. Sin poder parsear => 0.
     *
     * @return int
     */
    protected function memory_limit_bytes()
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $unidad = strtoupper(substr($raw, -1));
        $numero = (int) $raw;

        switch ($unidad) {
            case 'G':
                return $numero * 1024 * 1024 * 1024;
            case 'M':
                return $numero * 1024 * 1024;
            case 'K':
                return $numero * 1024;
            default:
                if (is_numeric($raw)) {
                    return (int) $raw;
                }
                return 0;
        }
    }

    /**
     * Pico de memoria del proceso hasta ahora, en MB (2 decimales).
     *
     * @return float
     */
    protected function peak_memory_mb()
    {
        return round(memory_get_peak_usage(true) / 1048576, 2);
    }

    /**
     * memory_limit en MB (entero) o null si no aplica / no parseable.
     *
     * @return int|null
     */
    protected function memory_limit_mb()
    {
        $bytes = $this->memory_limit_bytes();
        if ($bytes <= 0) {
            return null;
        }
        return (int) round($bytes / 1048576);
    }
}
