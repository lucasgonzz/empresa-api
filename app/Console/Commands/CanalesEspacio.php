<?php

namespace App\Console\Commands;

use App\Broadcasting\EspacioDeCanales;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CanalesEspacio — `php artisan canales:espacio` (misión pusher-canales-por-sistema, 19/10/2026).
 *
 * Imprime UNA línea JSON con el espacio de nombres de los canales de Pusher de esta instalación y de
 * dónde sale:
 *
 *     {"base":"u767360347_ferretotal","entorno":"production","espacio":"26d5013a6846","canales_viejos_de_tienda":true,"driver":"pusher"}
 *
 * Para qué: el espacio no se carga en ningún `.env`, se calcula (ver `EspacioDeCanales`). Después de
 * un despliegue, la única forma de saber que el ERP y la tienda de un mismo cliente se van a oír en
 * los canales que cruzan es comparar esta línea en empresa-api y en tienda-api: tienen que dar el
 * mismo `espacio`. 🔴 tienda-api tiene un comando con el MISMO nombre y la MISMA salida (mismas claves,
 * mismo orden): si se toca acá, se toca allá.
 *
 * 🔴 Nunca imprime el secret de Pusher, aunque sea una de las entradas del cálculo: esta línea se pega
 * en informes y en la consola de Lucas.
 *
 * Es de solo lectura y de corrida manual: no está en `Kernel::schedule()`.
 */
class CanalesEspacio extends Command
{
    /**
     * Nombre y firma del comando.
     *
     * @var string
     */
    protected $signature = 'canales:espacio';

    /**
     * Descripción del comando.
     *
     * @var string
     */
    protected $description = 'Muestra en una línea JSON el espacio de nombres de los canales de Pusher de esta instalación (sin el secret).';

    /**
     * Arma e imprime la línea.
     *
     * @return int 0 siempre: no escribe nada ni puede dejar nada a medias.
     */
    public function handle()
    {
        /**
         * Lo que se imprime, en el orden del contrato con tienda-api.
         *
         * - base / entorno: dos de las tres entradas del cálculo (la tercera, el secret, no se muestra).
         * - espacio: el que de verdad se usa (el override de `BROADCAST_ESPACIO` si hay, si no el
         *   calculado).
         * - canales_viejos_de_tienda: el modo de los canales que cruzan con la tienda.
         * - driver: el broadcaster por defecto; con `log` o `null` el espacio no se usa para nada.
         */
        $datos = [
            'base'                     => (string) DB::connection()->getDatabaseName(),
            'entorno'                  => (string) config('app.env'),
            'espacio'                  => EspacioDeCanales::espacio(),
            'canales_viejos_de_tienda' => EspacioDeCanales::canales_viejos_de_tienda(),
            'driver'                   => (string) config('broadcasting.default'),
        ];

        $this->line(json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
