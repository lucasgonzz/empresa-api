<?php

namespace App\Console\Commands;

use App\Models\Sale;
use Illuminate\Database\Connection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class set_sales_ganancia extends Command
{
    /**
     * Nombre del comando para ejecutar el backfill de ganancia.
     *
     * @var string
     */
    protected $signature = 'set_sales_ganancia {--chunk=1000} {--sleep=0}';

    /**
     * Descripcion breve del comando.
     *
     * @var string
     */
    protected $description = 'Calcula y persiste ganancia en ventas existentes del usuario configurado';

    /**
     * Ejecuta el comando y procesa ventas por lotes grandes.
     *
     * @return int
     */
    public function handle()
    {
        /** Permite ejecuciones largas sin timeout de PHP CLI. */
        set_time_limit(0);

        /** Limite de memoria elevado para evitar cortes en procesos extensos. */
        ini_set('memory_limit', '1024M');

        /** Conexion actual tipada para poder desactivar query log en procesos masivos. */
        $connection = DB::connection();

        /** Se desactiva el query log para evitar crecimiento de memoria con cientos de miles de consultas. */
        if ($connection instanceof Connection) {
            $connection->disableQueryLog();
        }

        /** Usuario dueno de las ventas a recalcular, definido por configuracion del proyecto. */
        $user_id = config('app.USER_ID');

        /** Tamanio de lote para procesar ventas por bloques y mantener memoria estable. */
        $chunk_size = (int) $this->option('chunk');

        /** Pausa opcional entre lotes para bajar carga en base de datos durante produccion. */
        $sleep_seconds = (int) $this->option('sleep');

        /** Contador total de ventas procesadas correctamente. */
        $processed_sales = 0;

        /** Contador de ventas con ganancia nula por falta de total o costo. */
        $null_ganancia_sales = 0;

        if (is_null($user_id)) {
            $this->error('No se encontro config(app.USER_ID).');
            return 1;
        }

        if ($chunk_size <= 0) {
            $this->error('El valor de --chunk debe ser mayor a 0.');
            return 1;
        }

        if ($sleep_seconds < 0) {
            $this->error('El valor de --sleep no puede ser negativo.');
            return 1;
        }

        /** Query base con columnas minimas para reducir transferencia y memoria. */
        $sales_query = Sale::query()
            ->where('user_id', $user_id)
            ->select('id', 'num', 'total', 'total_cost')
            ->orderBy('id', 'asc');

        /** Total esperado para informar progreso general del proceso. */
        $total_sales = (clone $sales_query)->count();

        $this->info('Iniciando set_sales_ganancia');
        $this->info('USER_ID: '.$user_id);
        $this->info('Ventas a procesar: '.$total_sales);
        $this->info('Chunk: '.$chunk_size.' | Sleep: '.$sleep_seconds.'s');

        /**
         * Proceso incremental por id para soportar volumen alto sin cortar memoria ni ejecucion.
         */
        $sales_query->chunkById($chunk_size, function ($sales_chunk) use (&$processed_sales, &$null_ganancia_sales, $total_sales, $sleep_seconds) {
            /** Recorre cada venta del lote actual y persiste la ganancia. */
            foreach ($sales_chunk as $sale) {
                /** Total de venta convertido a numero para el calculo. */
                $sale_total = is_null($sale->total) ? null : (float) $sale->total;

                /** Costo total convertido a numero para el calculo. */
                $sale_total_cost = is_null($sale->total_cost) ? null : (float) $sale->total_cost;

                /**
                 * Ganancia final a guardar.
                 * Si falta total o costo, se persiste null para mantener consistencia con backend.
                 */
                $sale_ganancia = is_null($sale_total) || is_null($sale_total_cost)
                    ? null
                    : $sale_total - $sale_total_cost;

                if (is_null($sale_ganancia)) {
                    $null_ganancia_sales++;
                }

                /** Se actualiza solo la columna necesaria para reducir tiempo de escritura. */
                Sale::where('id', $sale->id)->update([
                    'ganancia' => $sale_ganancia,
                ]);

                $processed_sales++;
            }

            /** Se informa progreso acumulado al finalizar cada lote. */
            $this->info('Procesadas: '.$processed_sales.' / '.$total_sales);

            /** Pausa opcional para evitar picos continuos de carga en produccion. */
            if ($sleep_seconds > 0) {
                sleep($sleep_seconds);
            }
        }, 'id');

        $this->info('Proceso finalizado');
        $this->info('Total procesadas: '.$processed_sales);
        $this->info('Ganancia null: '.$null_ganancia_sales);

        return 0;
    }
}
