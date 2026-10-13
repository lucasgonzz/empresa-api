<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\EsquemaDemoSnapshotHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Genera el volcado del esquema que `DemoSetupHelper::run()` restaura en vez de correr
 * `migrate:fresh`.
 *
 * Cuándo se corre a mano: después de agregar migraciones, sobre una base recién migrada y vacía, si
 * se quiere que la PRIMERA corrida del demo setup de esa versión ya sea rápida. No hace falta
 * correrlo para que el mecanismo funcione -- el setup se auto-ceba (ver
 * `EsquemaDemoSnapshotHelper`) --, pero entonces el primer lead de cada versión paga el
 * `migrate:fresh` entero.
 *
 * El camino típico en una instancia de demo:
 *
 *     php artisan migrate:fresh --force
 *     php artisan demo:snapshot-esquema
 */
class SnapshotEsquemaDemo extends Command
{
    protected $signature = 'demo:snapshot-esquema
        {--salida= : Ruta del archivo a escribir. Por defecto storage/demo/esquema-<version>.sql}
        {--forzar : Escribe el volcado aunque la base tenga datos}';

    protected $description = 'Vuelca el esquema migrado y vacío a storage/demo/esquema-<version>.sql, para que el demo setup lo restaure en vez de correr migrate:fresh';

    /**
     * Tablas que pueden tener filas sin que la base deje de contar como "vacía".
     *
     * `migrations` es justamente lo que el volcado tiene que llevarse; las tres de infraestructura de
     * Laravel las puede escribir cualquier cosa que haya corrido en el medio (una cola, una sesión,
     * un lock de caché) y no son datos del negocio.
     */
    const TABLAS_QUE_NO_CUENTAN = [
        'migrations',
        'jobs',
        'failed_jobs',
        'cache',
        'cache_locks',
        'sessions',
        'password_resets',
        'personal_access_tokens',
    ];

    public function handle()
    {
        /*
            Misma guarda de entorno que `semilla:datos` y `DemoSetupHelper`, por el mismo motivo: en
            un cliente real este comando no tiene nada que hacer, y aunque solo LEE la estructura, un
            volcado del esquema de un cliente dejado en su `storage/` es una superficie que no hace
            falta crear.
        */
        if (config('app.env') !== 'local'
            && config('app.env') !== 'testing'
            && config('app.FOR_USER') !== 'demo'
        ) {
            $this->error('demo:snapshot-esquema solo corre en local, en la suite o en una instancia demo.');

            return 1;
        }

        $con_datos = $this->tablas_con_datos();

        if (! empty($con_datos) && ! $this->option('forzar')) {
            $this->error(
                'La base tiene datos y el volcado tiene que salir de una base VACÍA: '
                    . implode(', ', array_slice($con_datos, 0, 8))
                    . (count($con_datos) > 8 ? ' (y ' . (count($con_datos) - 8) . ' más)' : '')
            );
            $this->line('Corré `php artisan migrate:fresh --force` antes, o pasá --forzar si sabés lo que hacés.');

            return 1;
        }

        try {
            $resultado = EsquemaDemoSnapshotHelper::generar($this->option('salida'));
        } catch (\Throwable $e) {
            $this->error('No se pudo generar el volcado: ' . $e->getMessage());

            return 1;
        }

        $this->info('Volcado escrito: ' . $resultado['ruta']);
        $this->line('  version     : ' . $resultado['version']);
        $this->line('  tablas      : ' . $resultado['tablas']);
        $this->line('  migraciones : ' . $resultado['migraciones']);
        $this->line('  tamaño      : ' . number_format(filesize($resultado['ruta']) / 1024, 0, ',', '.') . ' KB');

        return 0;
    }

    /**
     * Tablas con al menos una fila, sin contar las de `TABLAS_QUE_NO_CUENTAN`.
     *
     * Se cuenta tabla por tabla con `COUNT(*)` y no con `information_schema.TABLE_ROWS`: en InnoDB
     * ese campo es una estimación del optimizador y puede decir 0 con filas adentro, o al revés.
     * Para 433 tablas vacías son 433 consultas que resuelven por índice: medido, menos de un
     * segundo.
     *
     * @return string[]
     */
    protected function tablas_con_datos()
    {
        $con_datos = [];

        foreach (EsquemaDemoSnapshotHelper::tablas_base() as $tabla) {
            if (in_array($tabla, self::TABLAS_QUE_NO_CUENTAN, true)) {
                continue;
            }

            if (DB::table($tabla)->limit(1)->count() > 0) {
                $con_datos[] = $tabla;
            }
        }

        return $con_datos;
    }
}
