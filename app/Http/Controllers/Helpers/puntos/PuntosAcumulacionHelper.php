<?php

namespace App\Http\Controllers\Helpers\puntos;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\MovimientoPunto;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * El reconciliador de la acumulación de puntos.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUÉ ESTO ES UN RECONCILIADOR Y NO UN "AL COBRAR, SUMÁ".
 *
 *  El pedido es: los puntos aparecen cuando la venta queda cobrada. El mecanismo obvio sería
 *  enganchar en CurrentAcountPagoHelper.php:146, que es la línea donde un débito pasa a
 *  `status = 'pagado'`. Ese enganche REGALA PUNTOS INFINITOS.
 *
 *  `CurrentAcountHelper::checkPagos()` (CurrentAcountHelper.php:579) hace, en este orden:
 *    1. borra TODAS las imputaciones de `pagado_por` de los débitos de la cuenta,
 *    2. resetea TODOS los débitos a `pagandose = 0, status = 'sin_pagar'`,
 *    3. vuelve a correr CurrentAcountPagoHelper por CADA pago de la cuenta.
 *
 *  O sea que la transición "el débito llegó a pagado" NO ES UN EVENTO: se dispara N veces por
 *  UN solo hecho económico, y todas las ventas ya saldadas del cliente vuelven a pasar por esa
 *  línea. Y checkPagos() se llama desde once lugares: alta y baja de pagos, alta y baja de
 *  ventas, restaurar de la papelera, dos jobs de fondo (ProcessCheckSaldosChunk,
 *  ProcessRecalculateCurrentAcounts) y dos comandos. A un cliente al que le borran un pago
 *  viejo se le volverían a otorgar los puntos de TODAS sus ventas saldadas.
 *
 *  Por eso el efecto pedido se consigue con otro mecanismo: este helper COMPARA el estado que
 *  corresponde contra el que ya está escrito, y escribe únicamente la diferencia. Correrlo mil
 *  veces sobre la misma venta deja exactamente el mismo resultado que correrlo una.
 *
 *  El unique `(sale_id, tipo, price_type_id)` de `movimiento_puntos` es la RED, no el diseño:
 *  si el código se apoyara en que el unique explote, tiraríamos excepciones en el camino
 *  caliente de guardar una venta.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL LOTE SE ACTUALIZA EN EL LUGAR. NO SE ANULA Y SE CREA OTRO.
 *
 *  El plan de la misión decía "revertir y recrear" cuando una venta editada cambia de monto.
 *  ESO NO SE PUEDE HACER con el esquema que quedó: el unique es
 *  (sale_id, tipo, price_type_id) y NO incluye `anulado_at`, así que una segunda fila
 *  'ganados' para la misma venta y la misma lista viola el índice aunque la primera esté
 *  anulada. Verificado contra `empresa_testing_s7` (SHOW INDEX FROM movimiento_puntos).
 *
 *  Entonces el invariante es: para cada (venta, lista) hay A LO SUMO UNA fila 'ganados' y A LO
 *  SUMO UNA fila 'revertidos', y la de 'revertidos' existe SOLO mientras la de 'ganados' está
 *  anulada. Recalcular = escribir el número nuevo en la fila que ya está; revivir = sacarle el
 *  `anulado_at` y borrar su reverso. El saldo sigue siendo SUM(puntos) sin ninguna condición
 *  extra, que es lo único que hace que los tres lugares que lo leen no puedan discrepar.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class PuntosAcumulacionHelper {

    /**
     * Tolerancia de un centavo, el mismo $delta de CurrentAcountPagoHelper:141. Sin esto, una
     * diferencia de 0.0000001 entre el decimal de MySQL y el float de PHP haría que el
     * reconciliador reescriba la misma fila en cada una de las N llamadas de checkPagos(),
     * que es justo lo que este helper existe para no hacer.
     */
    const DELTA = 0.01;

    const TIPO_GANADOS    = 'ganados';
    const TIPO_REVERTIDOS = 'revertidos';

    /**
     * Deja los movimientos 'ganados' de UNA venta en el estado que le corresponde hoy.
     *
     * Es el único punto de entrada por venta y es idempotente: si lo que ya está escrito
     * coincide con lo que corresponde, NO ESCRIBE NADA.
     *
     * @param  \App\Models\Sale  $sale
     * @return void
     */
    static function reconciliar_venta($sale) {

        if (is_null($sale) || is_null($sale->id)) {
            return;
        }

        /*
         * Salidas baratas, en orden de costo creciente. Este método corre adentro del camino
         * de guardar una venta y adentro de dos jobs que barren todas las cuentas de todos los
         * clientes: un comercio SIN la extensión tiene que pagar CERO consultas por venta.
         *
         * 1. Sin cliente no hay a quién darle puntos. Cero consultas.
         * 2. El programa activo lo resuelve PuntosConfigHelper, que memoiza por user_id: la
         *    primera venta del request paga las dos queries y todas las demás responden de
         *    memoria. Sin extensión ni siquiera mira `sistemas_de_puntos`.
         */
        if (is_null($sale->client_id) || !$sale->client_id) {
            return;
        }

        $sistema = PuntosConfigHelper::programa_activo($sale->user_id);

        if (is_null($sistema)) {
            /*
             * 🔴 A propósito NO se revierte nada cuando el programa está apagado. Apagar el
             * programa es dejar de EMITIR puntos, no confiscarle al cliente los que ya ganó:
             * si acá revirtiéramos, el primer job de fondo que corriera después de que alguien
             * desmarca el checkbox le vaciaría el saldo a todos los clientes del comercio, y
             * volver a prenderlo no lo devolvería.
             */
            return;
        }

        /*
         * Se recargan los artículos SIEMPRE, y es una query que se paga a propósito. La venta
         * llega acá desde cinco lugares distintos (alta, edición, checkPagos, nota de crédito,
         * borrado) y en algunos de ellos la relación ya viene cargada de ANTES de que
         * attachArticles() tocara los pivots: `returned_amount`, `price_sin_iva` y `discount`
         * estarían viejos y el monto base saldría mal. Confiar en lo que cada llamador haya
         * dejado cargado sería hacer que el mismo helper calcule distinto según de dónde lo
         * llamen, que es exactamente la clase de error que hay que no escribir.
         * Solo la paga el comercio que tiene el programa prendido: la salida barata de arriba
         * ya filtró a todos los demás.
         */
        $sale->load('articles');

        $corresponde = self::corresponde_acumular($sale);

        /*
         * 🔴 `calcular_grupos()` devuelve SOLO los grupos con puntos > 0. Un array vacío
         * significa "esta venta no otorga nada", NO "no hacer nada": hay que revertir lo que
         * hubiera de antes. Por eso el array vacío entra igual a aplicar_grupos().
         */
        $grupos = $corresponde ? PuntosBaseHelper::calcular_grupos($sale, $sistema) : [];

        self::aplicar_grupos($sale, $sistema, $grupos);
    }

    /**
     * ¿A esta venta le corresponde tener puntos hoy?
     *
     * @param  \App\Models\Sale  $sale
     * @return bool
     */
    static function corresponde_acumular($sale) {

        if (is_null($sale->client_id) || !$sale->client_id) {
            return false;
        }

        /*
         * Venta de depósito todavía sin confirmar: no se otorga nada hasta que alguien la
         * confirme. Es el mismo par de banderas con el que SaleHelper::attachProperies()
         * decide si la venta entra a la cuenta corriente y a la caja.
         */
        if ($sale->to_check || $sale->checked) {
            return false;
        }

        if (SaleHelper::va_a_volver_a_la_cuenta_corriente($sale)) {

            /*
             * Venta de cuenta corriente: corresponde recién cuando el débito de la venta está
             * saldado. TODO O NADA: 'pagandose' (pago parcial) no otorga ni un punto.
             *
             * Se piden TODOS los débitos de la venta y se exige que estén todos en 'pagado'.
             * Normalmente hay uno solo, pero un cliente con cuentas en dos monedas puede tener
             * más de uno, y "saldada" tiene que significar saldada entera.
             */
            $debitos = CurrentAcount::where('sale_id', $sale->id)
                                    ->whereNull('haber')
                                    ->get();

            if (!count($debitos)) {
                return false;
            }

            foreach ($debitos as $debito) {
                if ($debito->status != 'pagado') {
                    return false;
                }
            }

            return true;
        }

        /*
         * Venta de mostrador (save_current_acount = 0 u omitir_en_cuenta_corriente = 1): se
         * cobró en el acto, así que alcanza con que esté confirmada. `terminada` es la bandera
         * que SaleHelper::get_terminada() apaga para las ventas con fecha de entrega y para
         * las de la extensión check_sales: una venta que todavía no se entregó no cobra
         * puntos, igual que una de cuenta corriente que todavía no se pagó.
         */
        return (bool) $sale->terminada;
    }

    /**
     * Revierte TODO lo que esta venta haya otorgado, sin preguntar si corresponde.
     *
     * La usa SaleController@destroy antes del delete: una venta que se borra no otorga nada,
     * y esperar a que el reconciliador se dé cuenta por su cuenta dependería de que el débito
     * de la cuenta corriente ya se haya borrado, que es un orden que no controlamos.
     *
     * @param  \App\Models\Sale  $sale
     * @return void
     */
    static function revertir_venta($sale) {

        if (is_null($sale) || is_null($sale->id)) {
            return;
        }

        if (is_null($sale->client_id) || !$sale->client_id) {
            return;
        }

        /*
         * Gate por extensión y no por programa activo: acá hay que revertir aunque el programa
         * se haya apagado después de otorgar los puntos (una venta borrada no puede dejar
         * puntos vivos). Sin la extensión no puede haber un solo movimiento, así que la
         * pregunta memoizada alcanza y no cuesta una query.
         */
        if (!PuntosConfigHelper::tiene_extencion($sale->user_id)) {
            return;
        }

        $mapa = self::mapa_de_movimientos($sale);

        foreach ($mapa['lotes'] as $lista => $lote) {

            $reverso = array_key_exists($lista, $mapa['reversos']) ? $mapa['reversos'][$lista] : null;

            self::revertir_lote($sale, $lote, $reverso);
        }
    }

    /**
     * Reconcilia todas las ventas con débito en una cuenta corriente.
     *
     * Es el enganche del final de `CurrentAcountHelper::checkPagos()`, o sea el que cubre el
     * camino de cuenta corriente entero: alta y baja de pagos, alta y baja de ventas,
     * restaurar de la papelera y los dos jobs de fondo.
     *
     * @param  \App\Models\CreditAccount|int|null  $credit_account  El modelo si el llamador ya
     *                                                              lo tiene (checkPagos lo
     *                                                              tiene), o su id.
     * @return void
     */
    static function reconciliar_cuenta_corriente($credit_account) {

        if (is_null($credit_account)) {
            return;
        }

        if (!is_object($credit_account)) {

            $credit_account = CreditAccount::find($credit_account);

            if (is_null($credit_account)) {
                return;
            }
        }

        /*
         * checkPagos() también corre para las cuentas de PROVEEDORES, donde no hay cliente al
         * que darle puntos ni venta que mirar. Sin esta guarda barreríamos las compras.
         */
        if ($credit_account->model_name != 'client') {
            return;
        }

        /*
         * Salida barata memoizada: el comercio sin extensión (o sin programa activo) no lee ni
         * una fila de `current_acounts` acá, que es lo que hace que los jobs de fondo no se
         * pongan más lentos por existir este módulo.
         */
        if (!PuntosConfigHelper::activo_para($credit_account->user_id)) {
            return;
        }

        $sale_ids = CurrentAcount::where('credit_account_id', $credit_account->id)
                                    ->whereNotNull('debe')
                                    ->whereNotNull('sale_id')
                                    ->distinct()
                                    ->pluck('sale_id');

        if (!count($sale_ids)) {
            return;
        }

        $sales = Sale::whereIn('id', $sale_ids)->get();

        foreach ($sales as $sale) {
            self::reconciliar_venta($sale);
        }
    }

    /**
     * Reconcilia una venta de la que solo se tiene el id, sin pagar la lectura si el comercio
     * no tiene el módulo.
     *
     * La usa `CurrentAcountHelper::notaCredito()`, que llama a CurrentAcountPagoHelper derecho
     * y NUNCA pasa por checkPagos(): sin este enganche, una nota de crédito dejaría los puntos
     * de la venta devuelta tal como estaban.
     *
     * @param  int       $sale_id
     * @param  int|null  $user_id  El comercio, si el llamador lo tiene a mano gratis. Con null
     *                             se resuelve leyendo la venta.
     * @return void
     */
    static function reconciliar_venta_por_id($sale_id, $user_id = null) {

        if (is_null($sale_id) || !$sale_id) {
            return;
        }

        if (!is_null($user_id) && !PuntosConfigHelper::activo_para($user_id)) {
            return;
        }

        $sale = Sale::find($sale_id);

        if (is_null($sale)) {
            return;
        }

        self::reconciliar_venta($sale);
    }

    /**
     * Compara los grupos que corresponden contra los movimientos que ya están escritos, y
     * escribe SOLO la diferencia.
     *
     * @param  \App\Models\Sale                 $sale
     * @param  \App\Models\SistemaDePuntos      $sistema
     * @param  array                            $grupos
     * @return void
     */
    private static function aplicar_grupos($sale, $sistema, $grupos) {

        $mapa = self::mapa_de_movimientos($sale);

        $listas_vigentes = [];

        foreach ($grupos as $grupo) {

            $lista = (int) $grupo['price_type_id'];

            $listas_vigentes[$lista] = true;

            $lote    = array_key_exists($lista, $mapa['lotes'])    ? $mapa['lotes'][$lista]    : null;
            $reverso = array_key_exists($lista, $mapa['reversos']) ? $mapa['reversos'][$lista] : null;

            self::sincronizar_lote($sale, $sistema, $grupo, $lote, $reverso);
        }

        /*
         * Lo que está escrito y ya no corresponde se revierte. Cubre los dos casos de una sola
         * forma: la venta entera dejó de corresponder (array de grupos vacío) y la venta
         * editada perdió los renglones de una de sus listas (venta mixta).
         */
        foreach ($mapa['lotes'] as $lista => $lote) {

            if (array_key_exists($lista, $listas_vigentes)) {
                continue;
            }

            $reverso = array_key_exists($lista, $mapa['reversos']) ? $mapa['reversos'][$lista] : null;

            self::revertir_lote($sale, $lote, $reverso);
        }
    }

    /**
     * Los movimientos 'ganados' y 'revertidos' de la venta, indexados por lista de precio.
     *
     * Una sola query para los dos tipos: el reconciliador necesita las dos mitades juntas
     * porque revivir un lote implica borrar su reverso, y volver a preguntar sería una segunda
     * query en el camino caliente.
     *
     * @param  \App\Models\Sale  $sale
     * @return array  ['lotes' => [price_type_id => MovimientoPunto], 'reversos' => idem]
     */
    private static function mapa_de_movimientos($sale) {

        $movimientos = MovimientoPunto::where('sale_id', $sale->id)
                                        ->whereIn('tipo', [self::TIPO_GANADOS, self::TIPO_REVERTIDOS])
                                        ->get();

        $lotes    = [];
        $reversos = [];

        foreach ($movimientos as $movimiento) {

            $lista = (int) $movimiento->price_type_id;

            if ($movimiento->tipo == self::TIPO_GANADOS) {
                $lotes[$lista] = $movimiento;
            } else {
                $reversos[$lista] = $movimiento;
            }
        }

        return ['lotes' => $lotes, 'reversos' => $reversos];
    }

    /**
     * Deja el lote de UNA lista de precio con los puntos que le corresponden.
     *
     * 🔴 El camino del 99% de las llamadas es el que NO ESCRIBE NADA: el lote ya existe, está
     * vivo y tiene los mismos puntos. Es lo que hace que las N corridas de checkPagos() sobre
     * la misma cuenta no cuesten N escrituras ni dupliquen un solo punto.
     *
     * @param  \App\Models\Sale                      $sale
     * @param  \App\Models\SistemaDePuntos           $sistema
     * @param  array                                 $grupo
     * @param  \App\Models\MovimientoPunto|null      $lote
     * @param  \App\Models\MovimientoPunto|null      $reverso
     * @return void
     */
    private static function sincronizar_lote($sale, $sistema, $grupo, $lote, $reverso) {

        $puntos = (float) $grupo['puntos'];
        $base   = (float) $grupo['base'];

        if (is_null($lote)) {

            /*
             * No debería haber un reverso sin su lote, pero si lo hubiera (una fila 'ganados'
             * borrada a mano) el reverso quedaría restando para siempre. Se limpia antes de
             * crear, que además es lo que deja libre el lugar del unique.
             */
            if (!is_null($reverso)) {
                $reverso->delete();
            }

            MovimientoPunto::create([
                'user_id'              => $sale->user_id,
                'client_id'            => $sale->client_id,
                'sistema_de_puntos_id' => $sistema->id,
                'tipo'                 => self::TIPO_GANADOS,
                'puntos'               => $puntos,
                'sale_id'              => $sale->id,
                'price_type_id'        => (int) $grupo['price_type_id'],
                'monto_base'           => $base,
                'detalle'              => 'Venta N° '.$sale->num,
                'vence_at'             => self::calcular_vence_at($sistema),
                'consumido'            => 0,
                'employee_id'          => $sale->employee_id,
            ]);

            return;
        }

        $hay_cambios = false;

        if (!is_null($lote->anulado_at)) {

            /*
             * El lote estaba revertido y ahora vuelve a corresponder (la venta se editó, o se
             * volvió a saldar después de que le borraran un pago). Se REVIVE la misma fila y se
             * borra su reverso: crear una segunda fila 'ganados' violaría el unique
             * (sale_id, tipo, price_type_id), y dejar el reverso vivo restaría dos veces.
             */
            $lote->anulado_at = null;
            $hay_cambios      = true;

            if (!is_null($reverso)) {
                $reverso->delete();
            }
        }

        if (abs((float) $lote->puntos - $puntos) > self::DELTA) {
            /*
             * Se escribe el número nuevo sin tocar `consumido`. Si la venta se editó para
             * abajo y el cliente ya había canjeado parte de este lote, el remanente queda en 0
             * (PuntosSaldoHelper::remanente_de_lote() tiene piso) y el saldo del cliente puede
             * quedar negativo. Es la decisión declarada: perdonar la diferencia sería pagar dos
             * veces el mismo descuento.
             */
            $lote->puntos = $puntos;
            $hay_cambios  = true;
        }

        if (abs((float) $lote->monto_base - $base) > self::DELTA) {
            $lote->monto_base = $base;
            $hay_cambios      = true;
        }

        if (!$hay_cambios) {
            return;
        }

        $lote->save();
    }

    /**
     * Anula un lote y deja su movimiento compensatorio.
     *
     * 🔴 Se revierte EL TOTAL OTORGADO, no el remanente, aunque el cliente ya haya canjeado
     * esos puntos y el saldo quede negativo. La alternativa —perdonar la diferencia— es un
     * agujero: se le anula la venta al que ya se llevó el descuento y el negocio paga dos
     * veces. Un saldo negativo bloquea el canje solo (nunca llega al mínimo) y se ve en la
     * ficha del cliente.
     *
     * @param  \App\Models\Sale                  $sale
     * @param  \App\Models\MovimientoPunto       $lote
     * @param  \App\Models\MovimientoPunto|null  $reverso
     * @return void
     */
    private static function revertir_lote($sale, $lote, $reverso) {

        $puntos = (float) $lote->puntos;

        /*
         * Ya estaba revertido: no se vuelve a escribir. Es la mitad de la idempotencia que
         * importa, porque checkPagos() puede pasar por acá N veces con una venta que dejó de
         * corresponder hace rato.
         */
        if (!is_null($lote->anulado_at)) {

            if (!is_null($reverso) && abs((float) $reverso->puntos + $puntos) > self::DELTA) {
                $reverso->puntos = -$puntos;
                $reverso->save();
            }

            return;
        }

        $lote->anulado_at = Carbon::now();
        $lote->save();

        if (is_null($reverso)) {

            MovimientoPunto::create([
                'user_id'              => $lote->user_id,
                'client_id'            => $lote->client_id,
                'sistema_de_puntos_id' => $lote->sistema_de_puntos_id,
                'tipo'                 => self::TIPO_REVERTIDOS,
                'puntos'               => -$puntos,
                'sale_id'              => $sale->id,
                'price_type_id'        => (int) $lote->price_type_id,
                'monto_base'           => null,
                'detalle'              => 'Reversión de la venta N° '.$sale->num,
                'employee_id'          => $sale->employee_id,
            ]);

        } else {

            $reverso->puntos = -$puntos;
            $reverso->save();
        }

        Log::info(
            'Puntos revertidos. Venta id '.$sale->id.' (N° '.$sale->num.'), cliente '.$lote->client_id.
            ', lista '.$lote->price_type_id.', puntos '.$puntos
        );
    }

    /**
     * Cuándo vence este lote.
     *
     * Se calcula UNA sola vez, al crear el lote, y no se recalcula cuando la venta se edita:
     * el vencimiento cuenta desde que el cliente ganó los puntos, no desde la última vez que
     * alguien tocó la venta. Si se recalculara, editar una venta vieja le renovaría el
     * vencimiento a puntos que ya tenían que morir.
     *
     * @param  \App\Models\SistemaDePuntos  $sistema
     * @return \Carbon\Carbon|null  null = el programa no vence puntos.
     */
    private static function calcular_vence_at($sistema) {

        if (is_null($sistema->vencimiento_meses) || !$sistema->vencimiento_meses) {
            return null;
        }

        return Carbon::now()->addMonths((int) $sistema->vencimiento_meses);
    }
}
