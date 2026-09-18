<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\sale\CostoDeVentaHelper;
use App\Http\Controllers\Helpers\sale\IvaDeVentaHelper;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de `sales.ganancia` con la fórmula vigente (misión saneo-ganancia-ventas, 17/9/2026).
 *
 *     sales.ganancia = total − costo neto − IVA efectivamente declarado por esa venta
 *
 * Tiene que dar EXACTAMENTE lo mismo que el guardado en vivo: las dos puntas comparten
 * `SaleHelper::calcular_ganancia()`, `IvaDeVentaHelper` y `CostoDeVentaHelper`, no hay una segunda
 * fórmula acá.
 *
 * 🔴 SE NIEGA A CORRER EN UNA CUENTA LEGACY CON `aplicar_iva_al_costo` PRENDIDA, y eso es lo más
 * importante de este archivo. Ver el PHPDoc de `verificar_que_el_historico_sea_medible()`: en esas
 * cuentas no hay forma de saber en qué base estaba guardado el costo de una venta VIEJA, y escribir
 * igual dejaría un número peor que el que había.
 *
 * 🔴 Corre sobre bases de producción con decenas de miles de ventas (53.155 en ferretotal, 27.368
 * en golonorte al 17/9/2026), así que el chunking y la pausa entre lotes no son decorativos.
 * `IvaDeVentaHelper::medir_ventas()` mide el lote entero en dos queries, no una por venta, y
 * `CostoDeVentaHelper::medir_ventas()` en una sola más (ninguna, si la cuenta tiene el costo neto).
 */
class set_sales_ganancia extends Command
{
    /**
     * Nombre del comando para ejecutar el backfill de ganancia.
     *
     * @var string
     */
    protected $signature = 'set_sales_ganancia {--chunk=1000} {--sleep=0} {--force}';

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

        /**
         * Contador de ventas que quedaron en null porque tienen un comprobante AUTORIZADO cuyo
         * `importe_iva` nunca se midió. Se informa aparte del contador de arriba porque tiene una
         * salida concreta: medir el IVA de esos comprobantes y volver a correr este comando.
         */
        $sin_iva_medido_sales = 0;

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

        /** Dueño de las ventas: de él salen las dos tildes que deciden si el costo es bruto. */
        $user = User::find($user_id);

        if (is_null($user)) {
            $this->error('No existe el usuario id '.$user_id.' que declara config(app.USER_ID).');
            return 1;
        }

        if (!$this->verificar_que_el_historico_sea_medible($user)) {
            return 1;
        }

        /**
         * Query base con columnas minimas para reducir transferencia y memoria.
         *
         * 🔴 `consolidacion_facturacion_id` NO es opcional en este select: es la columna con la que
         * `IvaDeVentaHelper` le prorratea a cada venta consolidada la parte que le toca del
         * comprobante único de su contenedora. Sin ella, todas las ventas consolidadas se medirían
         * con IVA 0 — o sea, facturadas contadas como si fueran en negro.
         */
        $sales_query = Sale::query()
            ->where('user_id', $user_id)
            ->select('id', 'num', 'total', 'total_cost', 'consolidacion_facturacion_id')
            ->orderBy('id', 'asc');

        /** Total esperado para informar progreso general del proceso. */
        $total_sales = (clone $sales_query)->count();

        $this->info('Iniciando set_sales_ganancia');
        $this->info('USER_ID: '.$user_id);
        $this->info('Formula: ganancia = total - costo neto - IVA declarado por la venta');
        $this->info('Ventas a procesar: '.$total_sales);
        $this->info('Chunk: '.$chunk_size.' | Sleep: '.$sleep_seconds.'s');

        /**
         * Proceso incremental por id para soportar volumen alto sin cortar memoria ni ejecucion.
         */
        $sales_query->chunkById($chunk_size, function ($sales_chunk) use (&$processed_sales, &$null_ganancia_sales, &$sin_iva_medido_sales, $total_sales, $sleep_seconds, $user) {

            /**
             * IVA declarado de TODO el lote, en dos queries (no una por venta): el mismo criterio
             * del comprobante que usa el guardado en vivo.
             */
            $medicion_por_venta = IvaDeVentaHelper::medir_ventas($sales_chunk);

            /**
             * Credito fiscal contenido en el costo de TODO el lote, en una sola query mas (ninguna
             * si la cuenta tiene el costo neto, que es el caso normal). El `$user` se pasa resuelto
             * porque el select de arriba no trae `user_id`.
             */
            $credito_por_venta = CostoDeVentaHelper::medir_ventas($sales_chunk, $user);

            /** Recorre cada venta del lote actual y persiste la ganancia. */
            foreach ($sales_chunk as $sale) {
                $medicion_iva = isset($medicion_por_venta[$sale->id])
                    ? $medicion_por_venta[$sale->id]
                    : ['iva' => 0.0, 'sin_medir' => 0];

                $credito_fiscal = isset($credito_por_venta[$sale->id]) ? $credito_por_venta[$sale->id] : 0.0;

                /**
                 * Ganancia final a guardar, con la MISMA funcion que usa el guardado en vivo.
                 * Si falta total o costo, o si hay un comprobante autorizado sin IVA medido, se
                 * persiste null: null ya significa "no se puede calcular" en esta columna, y es la
                 * unica respuesta que no miente.
                 */
                $sale_ganancia = SaleHelper::calcular_ganancia($sale->total, $sale->total_cost, $medicion_iva, $credito_fiscal);

                if (is_null($sale_ganancia)) {

                    if ((int) $medicion_iva['sin_medir'] > 0) {
                        $sin_iva_medido_sales++;
                    } else {
                        $null_ganancia_sales++;
                    }
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
        $this->info('Ganancia null por falta de total o costo: '.$null_ganancia_sales);
        $this->info('Ganancia null por comprobante sin IVA medido: '.$sin_iva_medido_sales);

        /**
         * 🔴 El aviso va como warning: una venta facturada cuyo comprobante no tiene `importe_iva`
         * no se salda sola. Medido el 17/9/2026: 53 comprobantes asi en ferretotal y 8 en golonorte.
         *
         * ⚠️ A proposito NO nombra un comando: el unico que existe para eso
         * (`php artisan set_iva_debito`) esta roto en develop y no corre. Ver el PHPDoc de
         * `IvaDeVentaHelper` para el error exacto y para el molde con el que rehacerlo.
         */
        if ($sin_iva_medido_sales > 0) {
            $this->warn(
                $sin_iva_medido_sales.' venta(s) quedaron con ganancia en NULL porque tienen un comprobante '.
                'autorizado sin importe_iva medido. No se las cuenta como IVA 0 a proposito: seria contar una '.
                'venta facturada como si hubiera sido en negro. Hay que medir el IVA de esos comprobantes y '.
                'volver a correr este comando.'
            );
        }

        return 0;
    }

    /**
     * 🔴 Guarda de "el historico de esta cuenta NO es medible": frena el backfill entero cuando la
     * cuenta es **legacy con `aplicar_iva_al_costo` prendida**.
     *
     * Por que existe. En esas cuentas `article_sale.cost` viene BRUTO, y para calcular bien la
     * ganancia hay que devolverle al costo el IVA de compra (`CostoDeVentaHelper`). El guardado en
     * vivo puede hacerlo porque sabe que la tilde esta prendida AHORA. El backfill no: una venta de
     * hace dos años puede tener el costo guardado en la otra base, si la tilde se prendio o se
     * apago en el medio.
     *
     * Y eso no se puede averiguar: **el cambio de esa tilde no queda registrado en ninguna tabla**.
     * `UserController::update()` dispara un recalculo de precios y un broadcast, pero no persiste ni
     * la fecha ni el valor anterior; lo unico que queda es un `Log::info` en el `laravel.log`, que
     * rota. No hay historial, no hay columna, no hay auditoria.
     *
     * Entonces el backfill tendria que elegir entre dos numeros y los dos pueden estar mal: netear
     * todo (infla la ganancia de las ventas que se guardaron con el costo ya neto) o no netear nada
     * (que es el error de -52,5 % que esta mision vino a arreglar, y que con margen <= 21 % informa
     * ganancia NEGATIVA a un negocio que gana plata). Escribir cualquiera de los dos deja un numero
     * peor que el que habia, y sin nada que lo avise. Por eso no escribe: para.
     *
     * Que hacer con una cuenta asi, en orden:
     *
     *   1. Migrarla (`usar_condicion_fiscal_en_costeo = 1`), que ademas recalcula el catalogo y deja
     *      los costos netos de ahi en adelante.
     *   2. Dejar que el guardado en vivo actualice cada venta cuando se la toca.
     *   3. Y si se necesita el historico igual, correr con `--force` habiendo entendido que a las
     *      ventas viejas se les va a aplicar el criterio de HOY.
     *
     * ⚠️ La condicion se pregunta con `ArticlePricesHelper::iva_va_al_costo()` y no leyendo las dos
     * columnas a mano: ese metodo ya devuelve true SOLO para la cuenta legacy con la tilde
     * prendida (para cualquier cuenta migrada devuelve false siempre), que es exactamente el caso
     * ambiguo. El Monotributista migrado tambien tiene el costo bruto pero NO entra acá: para el no
     * hay nada que netear —no recupera el IVA— asi que su ganancia da igual con cualquier criterio
     * y su historico si es medible.
     *
     * @param  \App\Models\User $user Dueño de las ventas.
     * @return bool false si hay que abortar el comando.
     */
    private function verificar_que_el_historico_sea_medible($user)
    {
        if (!ArticlePricesHelper::iva_va_al_costo($user)) {
            return true;
        }

        if ($this->option('force')) {

            $this->warn(
                'CUENTA LEGACY CON aplicar_iva_al_costo PRENDIDA, y se corrio con --force. A TODAS las '.
                'ventas, incluidas las mas viejas, se les va a aplicar el criterio de HOY: el costo se '.
                'toma como BRUTO y se le devuelve el IVA de compra. Si la tilde se cambio en algun '.
                'momento, las ventas anteriores a ese cambio van a quedar con la ganancia inflada en '.
                'ese IVA. No hay forma de saber cuando cambio: el sistema no lo registra.'
            );

            return true;
        }

        $this->error(
            'FRENADO: esta cuenta es LEGACY (usar_condicion_fiscal_en_costeo apagado) y tiene '.
            'aplicar_iva_al_costo PRENDIDA, asi que su article_sale.cost esta guardado BRUTO (con el '.
            'IVA de compra adentro).'
        );

        $this->error(
            'Para una venta VIEJA no se puede saber en que base estaba su costo: el cambio de esa tilde '.
            'no se persiste en ninguna tabla (dispara un recalculo y un broadcast, y nada mas). '.
            'Backfillear igual escribiria un numero PEOR que el que ya hay: o infla la ganancia de las '.
            'ventas guardadas en neto, o la hunde un 21% del costo en las guardadas en bruto —que con '.
            'margen <= 21% informa ganancia NEGATIVA a un negocio que gana plata.'
        );

        $this->error(
            'El guardado en vivo SI la calcula bien (sabe que la tilde esta prendida ahora). Camino '.
            'recomendado: migrar la cuenta a usar_condicion_fiscal_en_costeo = 1. Si aun asi se quiere '.
            'backfillear el historico con el criterio de hoy, hay que pasar --force explicitamente.'
        );

        return false;
    }
}
