<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\puntos\PuntosConfigHelper;
use App\Http\Controllers\Helpers\puntos\PuntosSaldoHelper;
use App\Models\MovimientoPunto;
use App\Models\SistemaDePuntos;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vence los puntos de los clientes cuyos lotes pasaron su fecha de vencimiento.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUÉ ES UN COMANDO Y NO UN CÁLCULO PEREZOSO AL LEER EL SALDO.
 *
 *  El saldo lo leen TRES lugares distintos —VENDER al elegir el cliente, la ficha del
 *  cliente y el reporte de pasivo—. Si "vencido" fuera una condición del SELECT, los tres
 *  tendrían que implementar la misma regla de exclusión y el primero que se la olvide
 *  muestra un número distinto: es la clase "el mismo invariante decidido con dos criterios
 *  distintos" (contexto/APRENDER_NO_PARCHEAR.md:997).
 *
 *  Con este comando el vencimiento es una FILA NEGATIVA del libro, y el saldo queda siendo
 *  `SUM(movimiento_puntos.puntos)`, una sola frase imposible de implementar mal (ver el
 *  docblock de PuntosSaldoHelper). De paso, el reporte de pasivo necesita "cuántos puntos
 *  vencieron" como un hecho con fecha, no como una derivación que se recalcula distinto
 *  cada vez que alguien cambia la configuración del programa.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 🔴 ES IDEMPOTENTE, y no por una marca aparte: lo que vence es el REMANENTE del lote
 * (`puntos - consumido`), y al vencerlo se le sube el `consumido` hasta el tope. La segunda
 * corrida ya no encuentra ese lote, porque `PuntosSaldoHelper::query_lotes_vivos()` pide
 * `(puntos - consumido) > 0`. O sea que la protección contra el doble vencimiento es la
 * misma columna que hace que un lote ya canjeado no se pueda vencer de nuevo: una sola
 * regla para las dos cosas.
 */
class VencerPuntos extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * Sin `--user_id` barre TODOS los comercios con un programa de puntos activo, que es lo
     * que hace el scheduler. Con `--user_id` corre uno solo, que es lo que hace falta para
     * probarlo a mano en una base con varios comercios.
     *
     * 🔴 `--user_id` NO saltea el gate por extensión: la extensión es lo que se le vendió (o
     * no) al cliente, y eso no lo destraba una bandera de consola. Mismo criterio que el
     * `--force` de ofertas:generar.
     *
     * @var string
     */
    protected $signature = 'puntos:vencer {--user_id= : Vence un solo comercio, para probar a mano}';

    /** @var string Descripción del comando para php artisan list */
    protected $description = 'Vence los puntos de clientes cuyos lotes pasaron su fecha de vencimiento';

    /** Texto del movimiento de vencimiento, el que ve el usuario en la ficha del cliente. */
    const DETALLE = 'Vencimiento de puntos';

    /**
     * Ejecuta el barrido.
     *
     * @return int Código de salida (0 = OK)
     */
    public function handle()
    {
        /*
         * PuntosConfigHelper memoiza extensión y programa activo por lo que dura el proceso, y
         * eso está pensado para un request. Acá el proceso puede recorrer varios comercios y,
         * en los tests, correr dos veces seguidas con la configuración cambiada en el medio:
         * se arranca siempre leyendo la base.
         */
        PuntosConfigHelper::limpiar_memo();

        $user_ids = $this->resolver_comercios();

        if (count($user_ids) == 0) {
            $this->info('puntos:vencer — no hay ningún comercio con un programa de puntos activo, no se vence nada.');
            return 0;
        }

        foreach ($user_ids as $user_id) {
            $this->vencer_comercio($user_id);
        }

        return 0;
    }

    /**
     * Qué comercios se barren.
     *
     * Sin `--user_id` se sale de `sistemas_de_puntos` y no de `config('app.USER_ID')`: el
     * dueño de la instancia está incluido igual (si tiene programa, tiene fila), y de paso la
     * misma corrida sirve en una base con varios comercios sin que haya que acordarse de una
     * variable de entorno. Si no hay ni una fila, la lista viene vacía y el comando sale en
     * una línea sin tocar nada.
     *
     * @return array  Lista de user_id
     */
    protected function resolver_comercios()
    {
        $user_id = $this->option('user_id');

        if (!is_null($user_id) && $user_id !== '') {
            return [(int) $user_id];
        }

        $ids = SistemaDePuntos::where('activo', 1)
                                ->orderBy('user_id', 'ASC')
                                ->pluck('user_id')
                                ->unique()
                                ->values()
                                ->all();

        $limpios = [];

        foreach ($ids as $id) {
            $limpios[] = (int) $id;
        }

        return $limpios;
    }

    /**
     * Vence lo que corresponda de un comercio.
     *
     * @param  int  $user_id
     * @return void
     */
    protected function vencer_comercio($user_id)
    {
        /*
         * Doble gate: el Kernel ya filtra por extensión antes de agendar el comando, pero el
         * comando se puede correr a mano. Mismo patrón que ofertas:generar.
         */
        if (!PuntosConfigHelper::tiene_extencion($user_id)) {
            $this->info('puntos:vencer — comercio ' . $user_id . ': no tiene la extensión '
                . PuntosConfigHelper::SLUG_EXTENCION . ', no se toca nada.');
            return;
        }

        $programa = PuntosConfigHelper::programa_activo($user_id);

        if (is_null($programa)) {
            $this->info('puntos:vencer — comercio ' . $user_id . ': no tiene un programa de puntos activo, no se vence nada.');
            return;
        }

        /*
         * 🔴 `vencimiento_meses` en null significa "los puntos de este programa NO vencen", no
         * "vencen a los cero meses". Sin este corte, un comercio que apagó el vencimiento le
         * mataría de una todos los lotes que arrastran un `vence_at` de cuando sí vencían.
         */
        if (is_null($programa->vencimiento_meses)) {
            $this->info('puntos:vencer — comercio ' . $user_id . ': el programa no vence los puntos (vencimiento_meses en null), no se toca nada.');
            return;
        }

        /*
         * Los lotes vencidos con remanente vivo. La definición de "lote vivo" —no anulado y con
         * `puntos - consumido > 0`— la pone query_lotes_vivos() y no se reescribe acá: es la
         * misma que usa el canje, y por eso lo ya canjeado no se puede volver a vencer.
         *
         * No hace falta un `whereNotNull('vence_at')`: en SQL una comparación contra NULL nunca
         * da verdadera, así que `vence_at <= now()` ya deja afuera los lotes que no vencen.
         */
        $lotes = PuntosSaldoHelper::query_lotes_vivos($user_id)
                                    ->where('vence_at', '<=', Carbon::now())
                                    ->orderBy('client_id', 'ASC')
                                    ->orderBy('created_at', 'ASC')
                                    ->orderBy('id', 'ASC')
                                    ->get();

        if ($lotes->count() == 0) {
            $this->info('puntos:vencer — comercio ' . $user_id . ': no hay lotes vencidos con puntos sin usar.');
            return;
        }

        $clientes_tocados = 0;
        $puntos_vencidos  = 0;

        foreach ($lotes->groupBy('client_id') as $client_id => $lotes_del_cliente) {

            $vencidos = $this->vencer_cliente($programa, $user_id, (int) $client_id, $lotes_del_cliente);

            if ($vencidos > 0) {
                $clientes_tocados++;
                $puntos_vencidos += $vencidos;
            }
        }

        $puntos_vencidos = round($puntos_vencidos, 2);

        $resumen = 'puntos:vencer — comercio ' . $user_id . ': vencieron ' . $puntos_vencidos
            . ' puntos de ' . $clientes_tocados . ' cliente(s).';

        $this->info($resumen);

        Log::info($resumen);
    }

    /**
     * Vence, en una sola transacción, todos los lotes vencidos de UN cliente.
     *
     * 🔴 UN SOLO movimiento 'vencidos' por cliente y por corrida, no uno por lote. El detalle
     * de qué lote aportó cuánto va en `movimiento_punto_consumos`, que es exactamente para lo
     * que existe esa tabla (es el calco del `pagado_por` de la cuenta corriente). Con un
     * movimiento por lote, la ficha del cliente le mostraría catorce renglones de vencimiento
     * el mismo día y el reporte de pasivo tendría que agruparlos igual para ser legible.
     *
     * @param  \App\Models\SistemaDePuntos  $programa
     * @param  int                          $user_id
     * @param  int                          $client_id
     * @param  iterable                     $lotes      Los lotes vencidos del cliente, en orden FIFO.
     * @return float  Puntos que se vencieron de verdad.
     */
    protected function vencer_cliente($programa, $user_id, $client_id, $lotes)
    {
        /*
         * 🔴 Lo que vence es el REMANENTE (`puntos - consumido`), no los puntos que el lote
         * otorgó en su momento. Un lote de 100 puntos del que el cliente ya canjeó 80 solo
         * puede hacer vencer 20: los otros 80 ya salieron del saldo cuando se canjearon, y
         * vencerlos otra vez sería cobrárselos dos veces.
         */
        $total = 0;

        foreach ($lotes as $lote) {
            $total += PuntosSaldoHelper::remanente_de_lote($lote);
        }

        $total = round($total, 2);

        if ($total <= PuntosSaldoHelper::DELTA) {
            return 0;
        }

        $consumido = 0;

        DB::transaction(function () use ($programa, $user_id, $client_id, $lotes, $total, &$consumido) {

            $movimiento = MovimientoPunto::create([
                'user_id'              => $user_id,
                'client_id'            => $client_id,
                'sistema_de_puntos_id' => $programa->id,
                'tipo'                 => 'vencidos',
                'puntos'               => -$total,
                'sale_id'              => null,
                /*
                 * El centinela 0 = "sin lista". Un vencimiento no tiene lista de precio: junta
                 * lotes que pueden venir de listas distintas. El unique de la tabla es sobre
                 * (sale_id, tipo, price_type_id) y las filas con sale_id NULL no participan, así
                 * que dos vencimientos del mismo cliente en días distintos conviven bien.
                 */
                'price_type_id'        => 0,
                'monto_base'           => null,
                'detalle'              => self::DETALLE,
                'vence_at'             => null,
                'consumido'            => 0,
                'anulado_at'           => null,
                /* Lo hizo el cron, no una persona */
                'employee_id'          => null,
            ]);

            /*
             * null como tercer argumento = "llevate TODO el remanente de estos lotes", que es
             * justo el caso del vencimiento (el canje, en cambio, pide una cantidad exacta).
             * Esto es lo que sube el `consumido` de cada lote y lo que hace que la segunda
             * corrida no encuentre nada.
             */
            $consumido = PuntosSaldoHelper::consumir_lotes($movimiento, $lotes, null);

            /*
             * Red de seguridad, no una rama esperada: si por lo que sea se consumiera menos que
             * el total calculado, el movimiento tiene que decir lo que REALMENTE salió de los
             * lotes. Si no, el saldo (SUM(puntos)) y el remanente de los lotes empiezan a contar
             * cosas distintas, que es el bug más caro que puede tener este módulo.
             */
            if (abs($consumido - $total) > PuntosSaldoHelper::DELTA) {

                $movimiento->puntos = -$consumido;
                $movimiento->save();

                Log::warning('puntos:vencer — cliente ' . $client_id . ': se esperaba vencer ' . $total
                    . ' puntos y se consumieron ' . $consumido . '. El movimiento se ajustó a lo consumido.');
            }
        });

        return round($consumido, 2);
    }
}
