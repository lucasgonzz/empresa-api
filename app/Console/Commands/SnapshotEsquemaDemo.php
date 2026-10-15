<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\EsquemaDemoSnapshotHelper;
use Illuminate\Console\Command;

/**
 * Genera el volcado del esquema que `DemoSetupHelper::run()` restaura en vez de correr
 * `migrate:fresh`.
 *
 * Cuándo se corre a mano: después de agregar migraciones, sobre una base recién migrada, si se
 * quiere que la PRIMERA corrida del demo setup de esa versión ya sea rápida. No hace falta correrlo
 * para que el mecanismo funcione -- el setup se auto-ceba (ver `EsquemaDemoSnapshotHelper`) --,
 * pero entonces el primer lead de cada versión paga el `migrate:fresh` entero.
 *
 * El camino típico en una instancia de demo:
 *
 *     php artisan migrate:fresh --force
 *     php artisan demo:snapshot-esquema
 *
 * 🔴 «Recién migrada» no es «vacía» (15/10/2026): hay migraciones que insertan filas de catálogo
 * (plataformas, estados de Mercado Libre, la plantilla online ComercioCity) y el volcado se las
 * lleva. Por eso la guarda no mira si hay tablas con filas sino si hay un usuario: una base con
 * `users` ya pasó por un setup o por seeders, y la guarda vive en `generar()` para que la tengan
 * también los otros que lo llamen. No hay opción para saltearla: volcar una base usada es
 * exactamente el error que la guarda existe para impedir.
 */
class SnapshotEsquemaDemo extends Command
{
    protected $signature = 'demo:snapshot-esquema
        {--salida= : Ruta del archivo a escribir. Por defecto storage/demo/esquema-<version>.sql}';

    protected $description = 'Vuelca el esquema recién migrado (y las filas que dejan las migraciones) a storage/demo/esquema-<version>.sql, para que el demo setup lo restaure en vez de correr migrate:fresh';

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

        try {
            $resultado = EsquemaDemoSnapshotHelper::generar($this->option('salida'));
        } catch (\Throwable $e) {
            $this->error('No se pudo generar el volcado: ' . $e->getMessage());
            $this->line('El volcado tiene que salir de una base vacía recién migrada: corré `php artisan migrate:fresh --force` antes.');

            return 1;
        }

        $this->info('Volcado escrito: ' . $resultado['ruta']);
        $this->line('  version     : ' . $resultado['version']);
        $this->line('  tablas      : ' . $resultado['tablas']);
        $this->line('  migraciones : ' . $resultado['migraciones']);
        $this->line('  con filas   : ' . json_encode($resultado['filas']));
        $this->line('  tamaño      : ' . number_format(filesize($resultado['ruta']) / 1024, 0, ',', '.') . ' KB');

        return 0;
    }
}
