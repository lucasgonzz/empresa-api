<?php

namespace App\Http\Controllers\Helpers\puntos;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\MovimientoPunto;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
 *  `anulado_at` y borrar su reverso. El PASIVO del programa sigue siendo `SUM(puntos)` sin
 *  ninguna condición extra (`PuntosSaldoHelper::saldo_del_libro()`), que es lo que hace que un
 *  lote anulado y su reverso sumen exactamente cero sin que nadie tenga que acordarse de
 *  excluirlos. (El SALDO DISPONIBLE que ve el cliente sí descuenta además los lotes ya
 *  vencidos: es otra pregunta y vive en `PuntosSaldoHelper::saldo()`.)
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
     * El `tipo` con el que el comando `puntos:vencer` escribe la fila NEGATIVA del vencimiento.
     *
     * Se usa para distinguir, en `movimiento_punto_consumos`, qué pedazo de un lote se lo comió
     * un vencimiento (que ya restó del saldo por su cuenta) y qué pedazo se lo comió un canje
     * (que no). Ver `revertir_lote()`.
     */
    const TIPO_VENCIDOS = 'vencidos';

    /**
     * El `status` con el que `CurrentAcountHelper::notaCredito()` escribe el haber de una nota
     * de crédito. Es lo que la distingue de un cobro de verdad ('pago_from_client') cuando se
     * mira quién saldó un débito.
     */
    const STATUS_NOTA_CREDITO = 'nota_credito';

    /**
     * Cuántas veces se pidió suspender el enganche automático que cuelga del final de
     * `CurrentAcountPagoHelper::init()`.
     *
     * 🔴 QUÉ PROBLEMA RESUELVE, ANTES DE QUE ALGUIEN LO BORRE POR "GLOBAL FEO".
     *
     * El enganche de la cuenta corriente vive en `init()` porque ése es el ÚNICO lugar por el
     * que pasan todos los caminos que dejan un débito en 'pagado' (ver el comentario de allá).
     * Pero `CurrentAcountHelper::checkPagos()` llama a `init()` UNA VEZ POR CADA PAGO de la
     * cuenta, y después reconcilia la cuenta entera él mismo, al final. Sin esto, una cuenta con
     * 30 pagos reconciliaría todas sus ventas 31 veces en vez de una: no duplicaría un punto
     * (el reconciliador compara contra lo escrito y no escribe si no cambió nada), pero le
     * multiplicaría por 31 el costo en consultas a los dos jobs de fondo que barren todas las
     * cuentas de todos los clientes.
     *
     * Es un CONTADOR y no una bandera para que un flujo suspendido que llame a otro flujo
     * suspendido no se destape solo al reanudar el de adentro.
     *
     * @var int
     */
    private static $suspensiones = 0;

    /**
     * Deja los movimientos 'ganados' de UNA venta en el estado que le corresponde hoy.
     *
     * Es el único punto de entrada por venta y es idempotente: si lo que ya está escrito
     * coincide con lo que corresponde, NO ESCRIBE NADA.
     *
     * @param  \App\Models\Sale  $sale
     * @param  array|null        $contexto  Datos ya leídos de a muchos por
     *                                      `reconciliar_cuenta_corriente()`, para que esta
     *                                      venta no vuelva a pedir uno por uno lo que ya está
     *                                      en memoria. `null` = camino de una sola venta, que
     *                                      lee todo por su cuenta. Ver `ventas_a_reconciliar()`.
     * @return void
     */
    static function reconciliar_venta($sale, $contexto = null) {

        if (is_null($sale) || is_null($sale->id)) {
            return;
        }

        /*
         * 🔴 LA SALIDA BARATA ES LA DEL PROGRAMA, Y ES LA ÚNICA. NO VUELVAS A PONER UN
         *    `if (is_null($sale->client_id)) return;` ACÁ ARRIBA.
         *
         * Hasta el 22/8/2026 esta salida existía y era el bug: una venta a la que le SACAN el
         * cliente (o se lo cambian) se iba del helper sin reconciliar nada, así que el lote
         * quedaba vivo y el cliente viejo se quedaba con los puntos de una venta que ya no es
         * suya. "Sin cliente no acumula" es una regla sobre lo que CORRESPONDE, no sobre si hay
         * que mirar: quien la aplica es `corresponde_acumular()`, que devuelve false y hace que
         * `aplicar_grupos()` revierta lo que hubiera de antes. Es la misma forma con la que ya
         * se resuelve "el programa filtró la lista" o "se devolvió todo": el que decide es el
         * reconciliador, no una puerta en la entrada.
         *
         * Y el ahorro de queries del comercio SIN la extensión se conserva entero igual, porque
         * lo que lo daba nunca fue el cliente: lo da PuntosConfigHelper, que memoiza por user_id
         * y sin extensión ni siquiera mira `sistemas_de_puntos`. La primera venta del request
         * paga las dos consultas del gate y todas las demás responden de memoria — es lo que
         * mide `11_Costo_cero_sin_extencion_Test`.
         *
         * Lo que sí cuesta esta decisión, dicho para que nadie lo descubra midiendo: en un
         * comercio CON el programa prendido, una venta sin cliente ahora paga UNA consulta a
         * `movimiento_puntos` (la de `mapa_de_movimientos()`) para poder contestar "no hay nada
         * que revertir". No hay forma de saltearla sin saber de quién ERA la venta antes, que
         * es justo el dato que este helper no tiene.
         */
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

        $corresponde = self::corresponde_acumular($sale, $contexto);

        /*
         * 🔴 `calcular_grupos()` devuelve SOLO los grupos con puntos > 0. Un array vacío
         * significa "esta venta no otorga nada", NO "no hacer nada": hay que revertir lo que
         * hubiera de antes. Por eso el array vacío entra igual a aplicar_grupos().
         */
        $grupos = [];

        if ($corresponde) {

            /*
             * Se recargan los artículos SIEMPRE, y es una query que se paga a propósito. La
             * venta llega acá desde cinco lugares distintos (alta, edición, checkPagos, nota de
             * crédito, borrado) y en algunos de ellos la relación ya viene cargada de ANTES de
             * que attachArticles() tocara los pivots: `returned_amount`, `price_sin_iva` y
             * `discount` estarían viejos y el monto base saldría mal. Confiar en lo que cada
             * llamador haya dejado cargado sería hacer que el mismo helper calcule distinto
             * según de dónde lo llamen, que es exactamente la clase de error que hay que no
             * escribir.
             *
             * Va adentro del `if` y no antes: si la venta no corresponde no hay ningún monto
             * base que calcular, y la única razón de existir de esta consulta es alimentar a
             * `calcular_grupos()`. Es lo que hace que la venta sin cliente —que desde el
             * 22/8/2026 llega hasta acá en vez de salirse arriba— no pague los artículos
             * además del mapa de movimientos.
             */
            /*
             * `discounts` y `surchages` van junto con `articles` y no aparte: desde el
             * 22/8/2026 el monto base aplica también las tres capas de descuento del
             * ENCABEZADO (ver PuntosBaseHelper::factor_descuentos_de_venta()), y sin
             * precargarlas cada venta pagaría dos lazy loads más. Son dos consultas nuevas por
             * venta en el camino de guardar; en el camino de la cuenta corriente no cuestan
             * nada porque reconciliar_cuenta_corriente() las trae de a muchas.
             */
            if (!self::articles_ya_frescos($contexto)) {
                $sale->load('articles', 'discounts', 'surchages');
            }

            $grupos = PuntosBaseHelper::calcular_grupos($sale, $sistema, self::factor_nc_del_contexto($sale, $contexto));
        }

        self::aplicar_grupos($sale, $sistema, $grupos, $contexto);
    }

    /**
     * ¿A esta venta le corresponde tener puntos hoy?
     *
     * @param  \App\Models\Sale  $sale
     * @param  array|null        $contexto  Ver reconciliar_venta().
     * @return bool
     */
    static function corresponde_acumular($sale, $contexto = null) {

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
            $debitos = self::debitos_del_contexto($sale, $contexto);

            if (!count($debitos)) {
                return false;
            }

            foreach ($debitos as $debito) {

                if ($debito->status != 'pagado') {
                    return false;
                }

                /*
                 * 🔴 "SUMA AL COBRAR". UNA NOTA DE CRÉDITO NO ES UN COBRO.
                 *
                 * `status = 'pagado'` NO significa "el cliente pagó": significa "el débito
                 * quedó saldado". Y hay un haber que lo salda sin que entre un peso: la nota
                 * de crédito. La de `POST api/devoluciones` viene atada a la venta (`sale_id`)
                 * y la tapa `PuntosBaseHelper::factor_nota_credito()`, pero la de monto libre
                 * de `CurrentAcountController@notaCredito` NACE SIN `sale_id` —el request no
                 * trae ninguno, es un ajuste a la cuenta del cliente y puede no corresponder a
                 * ninguna venta—, así que cae en la cola FIFO, salda el débito más viejo y
                 * llega hasta acá disfrazada de cobro. Medido: venta impaga + NC de monto
                 * libre por el total = puntos regalados a un cliente que no pagó nada.
                 *
                 * 🔴 EL ARREGLO NO ES INVENTARLE UN `sale_id` A LA NC. Es no confundir
                 * "saldado" con "cobrado", y la señal que los separa está en `pagado_por` —la
                 * tabla que dice QUÉ haberes saldaron este débito— cruzada con el `status` de
                 * cada haber: 'nota_credito' contra cualquier otro ('pago_from_client').
                 *
                 * La condición es "no entró NADA de plata", y no "tocó una nota de crédito",
                 * a propósito: una devolución PARCIAL de una venta de cuenta corriente sí
                 * mezcla plata y NC, y ahí los puntos tienen que bajar en proporción —de eso
                 * se encarga el factor de PuntosBaseHelper— y no desaparecer. Esta guarda
                 * contesta "¿se cobró?"; el factor contesta "¿sobre cuánto?". Son dos
                 * preguntas distintas y por eso viven en dos lugares distintos.
                 *
                 * Un débito sin ninguna fila en `pagado_por` pasa: no hay NC involucrada, así
                 * que no hay nada que esta regla tenga que decir sobre él.
                 */
                if (!self::hubo_cobro_real($debito)) {
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
     * ¿Entró PLATA a saldar este débito, o lo saldó únicamente una nota de crédito?
     *
     * Devuelve `false` solo cuando hay al menos una nota de crédito imputada y la suma de lo
     * que aportaron los haberes que NO son nota de crédito es cero. En cualquier otro caso
     * —cobro normal, mezcla de plata y NC, o un débito sin imputaciones— devuelve `true` y la
     * proporción la decide `PuntosBaseHelper::factor_nota_credito()`.
     *
     * El status del haber es la única señal disponible: 'nota_credito' lo escribe
     * `CurrentAcountHelper::notaCredito()` y 'pago_from_client' los cobros de verdad
     * (`CurrentAcountController@pago`, `ChequeController`, el saldo inicial).
     *
     * @param  \App\Models\CurrentAcount  $debito
     * @return bool
     */
    private static function hubo_cobro_real($debito) {

        $plata         = 0;
        $notas_credito = 0;

        foreach ($debito->pagado_por as $haber) {

            $pagado = (float) $haber->pivot->pagado;

            if ($haber->status == self::STATUS_NOTA_CREDITO) {
                $notas_credito += $pagado;
            } else {
                $plata += $pagado;
            }
        }

        if ($notas_credito <= self::DELTA) {
            return true;
        }

        return $plata > self::DELTA;
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

        /*
         * 🔴 Tampoco acá se sale por `client_id`, y por la misma razón que en reconciliar_venta():
         * los movimientos se buscan por `sale_id`, no por cliente. Una venta a la que le sacaron
         * el cliente y que después se borra tiene igual sus lotes escritos, y salirse antes los
         * dejaría vivos para siempre.
         */

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
     * Es el enganche del camino de cuenta corriente entero, y tiene DOS lugares de llamada a
     * propósito (ver el comentario de self::$suspensiones):
     *
     *   1. el final de `CurrentAcountPagoHelper::init()`, que es por donde pasan TODOS los
     *      caminos que dejan un débito en 'pagado' — incluido el cobro de todos los días
     *      (`CurrentAcountController@pago` con current_date = 1, el default de la SPA);
     *   2. el final de `CurrentAcountHelper::checkPagos()`, que suspende el primero mientras
     *      re-imputa la cuenta entera y reconcilia una sola vez acá — alta y baja de pagos,
     *      alta y baja de ventas, restaurar de la papelera y los dos jobs de fondo.
     *
     * @param  \App\Models\CreditAccount|int|null  $credit_account  El modelo si el llamador ya
     *                                                              lo tiene (checkPagos lo
     *                                                              tiene), o su id.
     * @return void
     */
    static function reconciliar_cuenta_corriente($credit_account) {

        /*
         * El llamador de afuera avisó que él reconcilia la cuenta entera una sola vez al final
         * (hoy el único es checkPagos(), que llama a init() una vez por pago). Ver el comentario
         * de self::$suspensiones.
         */
        if (self::$suspensiones > 0) {
            return;
        }

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
                                    ->pluck('sale_id')
                                    ->all();

        if (!count($sale_ids)) {
            return;
        }

        /*
         * TODOS los débitos de esas ventas, con sus imputaciones ya cargadas. Dos consultas
         * fijas, no dos por venta.
         *
         * 🔴 Se busca por `sale_id` y NO por `credit_account_id`: una venta puede tener débito
         * en más de una cuenta (el cliente con cuentas en dos monedas), y `corresponde_acumular()`
         * exige que estén TODOS saldados. Filtrar por la cuenta que disparó el cobro le
         * escondería la mitad y le haría decir que sí cuando la otra moneda sigue impaga.
         */
        $debitos = CurrentAcount::whereIn('sale_id', $sale_ids)
                                ->whereNull('haber')
                                ->with('pagado_por')
                                ->get();

        $debitos_por_venta = [];

        foreach ($debitos as $debito) {

            $sale_id = (int) $debito->sale_id;

            if (!array_key_exists($sale_id, $debitos_por_venta)) {
                $debitos_por_venta[$sale_id] = [];
            }

            $debitos_por_venta[$sale_id][] = $debito;
        }

        /*
         * El estado ESCRITO de las mismas ventas, en una sola consulta. Es la otra mitad de la
         * comparación que decide a quién hay que visitar.
         */
        $movimientos_por_venta = self::mapas_de_movimientos($sale_ids);

        $candidatas = self::ventas_a_reconciliar($sale_ids, $debitos_por_venta, $movimientos_por_venta);

        if (!count($candidatas)) {
            return;
        }

        /*
         * Recién acá se leen las ventas, y solo las candidatas. `articles`, `discounts` y
         * `surchages` van eager para que calcular_grupos() no pague un lazy load por venta:
         * son tres consultas fijas para todo el lote, no tres por venta.
         */
        $sales = Sale::whereIn('id', $candidatas)
                        ->with('articles', 'discounts', 'surchages')
                        ->get();

        $totales = [];

        foreach ($sales as $sale) {
            $totales[(int) $sale->id] = (float) $sale->total;
        }

        $factores_nc = PuntosBaseHelper::factores_nota_credito(array_keys($totales), $totales);

        foreach ($sales as $sale) {

            $sale_id = (int) $sale->id;

            self::reconciliar_venta($sale, [
                'articles_frescos' => true,
                'debitos'          => array_key_exists($sale_id, $debitos_por_venta) ? $debitos_por_venta[$sale_id] : [],
                'mapa'             => array_key_exists($sale_id, $movimientos_por_venta) ? $movimientos_por_venta[$sale_id] : self::mapa_vacio(),
                'factor_nc'        => array_key_exists($sale_id, $factores_nc) ? $factores_nc[$sale_id] : 1,
            ]);
        }
    }

    /**
     * De todas las ventas con débito en la cuenta, cuáles PUEDE haber tocado este pago.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 EL N+1 SIN TECHO QUE ESTO ARREGLA.
     *
     *  Hasta el 22/8/2026 acá se traían TODOS los `sale_id` con débito en la cuenta —sin filtro
     *  de fecha ni de estado— y se llamaba a `reconciliar_venta()` por cada uno. Cada visita
     *  pagaba `load('articles')`, la consulta de débitos, las dos de `factor_nota_credito()` y
     *  la de `mapa_de_movimientos()`. Medido con un cliente de 21 ventas de historia: 112
     *  consultas con la extensión contra 25 sin ella, o sea ~4,1 consultas EXTRA por venta
     *  histórica, y creciendo para siempre. Esto cuelga del final de
     *  `CurrentAcountPagoHelper::init()` —el cobro de todos los días— y de `checkPagos()`, que
     *  corre en `ProcessCheckSaldosChunk` y `ProcessRecalculateCurrentAcounts` sobre TODAS las
     *  cuentas de TODOS los clientes. Un mayorista con 2.000 ventas pagaba ~8.000 consultas por
     *  cobro. Lo que los tests medían hasta ese día era el costo del comercio SIN la extensión;
     *  el del que COMPRÓ el módulo no lo miraba nadie.
     *
     *  ⚠️ "Reconciliar sólo la venta del pago" es DEMASIADO ANGOSTO y por eso no se hizo: la
     *  imputación FIFO de `CurrentAcountPagoHelper` cascadea, así que un solo pago puede saldar
     *  tres ventas de una. Y `checkPagos()` borra las imputaciones de la cuenta entera y las
     *  vuelve a repartir, con lo que una venta vieja puede DESALDARSE por un pago de hoy.
     *
     *  EL CONJUNTO MÍNIMO Y CORRECTO. Como `reconciliar_venta()` es idempotente —no escribe si
     *  lo que corresponde coincide con lo escrito—, alcanza con visitar las ventas donde los dos
     *  pueden discrepar. Y las dos mitades se calculan de a muchas, con dos consultas fijas:
     *
     *   (1) EL ESTADO QUE CORRESPONDE contra EL ESCRITO. `deberia` = todos los débitos de la
     *       venta en 'pagado' y con plata de verdad adentro (la misma pregunta que hace
     *       `hubo_cobro_real()`, sobre las mismas filas de `pagado_por`). `tiene_lote_vivo` = hay
     *       una fila 'ganados' sin `anulado_at`. Si los dos coinciden, este pago no pudo cambiar
     *       nada: una venta saldada hace tres años, con su lote vivo escrito, sigue saldada y con
     *       el mismo lote. Si discrepan, hay algo que otorgar o algo que revertir.
     *
     *   (2) LAS VENTAS CON UNA NOTA DE CRÉDITO IMPUTADA. Son la única excepción donde el MONTO
     *       puede cambiar sin que se mueva ninguno de los dos booleanos: el monto base se achica
     *       en proporción a lo que las NC le imputaron a ese débito
     *       (`PuntosBaseHelper::factor_nota_credito()`), y `checkPagos()` puede repartir la misma
     *       NC distinto después de que le borren un pago al cliente. Se sacan de las mismas filas
     *       de `pagado_por` que ya se leyeron para (1), así que no cuestan una consulta más.
     *
     *  QUÉ QUEDA AFUERA, Y POR QUÉ ES CORRECTO: una venta cuyo lote ya está escrito y cuyo
     *  débito sigue pagado con plata. Su monto base sólo puede cambiar si cambian sus renglones
     *  o el encabezado —o sea, si alguien EDITA la venta—, y ese camino no pasa por acá:
     *  `SaleController@update` llama a `reconciliar_venta()` derecho sobre la venta editada.
     *
     *  ⚠️ LO QUE SÍ SIGUE ENTRANDO DE MÁS, dicho para que nadie lo descubra midiendo: una venta
     *  saldada que NO otorga puntos por una razón que este filtro no ve (el programa filtra por
     *  lista de precio y la venta es de otra, o se devolvió entera) tiene `deberia = true` y
     *  ningún lote, así que queda de candidata en cada cobro. No se puede evitar sin recalcular
     *  el monto base, que es justo lo caro. Pero ya no importa: las candidatas se leen TODAS
     *  JUNTAS, así que N candidatas cuestan las mismas ~6 consultas que una.
     * ─────────────────────────────────────────────────────────────────────────────
     *
     * @param  array  $sale_ids
     * @param  array  $debitos_por_venta      sale_id => lista de débitos con pagado_por cargado.
     * @param  array  $movimientos_por_venta  sale_id => el mapa de mapa_de_movimientos().
     * @return array  Los sale_id a visitar.
     */
    private static function ventas_a_reconciliar($sale_ids, $debitos_por_venta, $movimientos_por_venta) {

        $candidatas = [];

        foreach ($sale_ids as $sale_id) {

            $sale_id = (int) $sale_id;

            $debitos = array_key_exists($sale_id, $debitos_por_venta) ? $debitos_por_venta[$sale_id] : [];

            $deberia         = self::deberia_tener_lote_vivo($debitos);
            $tiene_lote_vivo = self::tiene_lote_vivo($sale_id, $movimientos_por_venta);

            if ($deberia != $tiene_lote_vivo || self::tiene_nota_credito_imputada($debitos)) {
                $candidatas[] = $sale_id;
            }
        }

        return $candidatas;
    }

    /**
     * La mitad barata de `corresponde_acumular()`: ¿los débitos dicen que esta venta tendría que
     * tener puntos vivos?
     *
     * A propósito NO mira `client_id`, `to_check`/`checked` ni el filtro por lista de precio: eso
     * exigiría leer la venta, que es justo lo que este filtro existe para no hacer. Al no mirarlos
     * se equivoca SIEMPRE PARA EL MISMO LADO —de más, nunca de menos—, que es el único error que
     * este filtro puede permitirse: una candidata de más cuesta cero consultas extra (se leen
     * todas juntas) y una de menos sería un punto que no se otorga.
     *
     * @param  array  $debitos
     * @return bool
     */
    private static function deberia_tener_lote_vivo($debitos) {

        if (!count($debitos)) {
            return false;
        }

        foreach ($debitos as $debito) {

            if ($debito->status != 'pagado') {
                return false;
            }

            if (!self::hubo_cobro_real($debito)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  int    $sale_id
     * @param  array  $movimientos_por_venta
     * @return bool
     */
    private static function tiene_lote_vivo($sale_id, $movimientos_por_venta) {

        if (!array_key_exists($sale_id, $movimientos_por_venta)) {
            return false;
        }

        foreach ($movimientos_por_venta[$sale_id]['lotes'] as $lote) {

            if (is_null($lote->anulado_at)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Alguna nota de crédito le imputó algo a alguno de los débitos de esta venta?
     *
     * Ver el punto (2) de `ventas_a_reconciliar()`: es el único caso donde el monto base puede
     * moverse sin que cambie el estado del débito.
     *
     * @param  array  $debitos
     * @return bool
     */
    private static function tiene_nota_credito_imputada($debitos) {

        foreach ($debitos as $debito) {

            foreach ($debito->pagado_por as $haber) {

                if ($haber->status == self::STATUS_NOTA_CREDITO) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Apaga el enganche automático de `CurrentAcountPagoHelper::init()` hasta el `reanudar()`.
     *
     * La usa UN solo llamador —`CurrentAcountHelper::checkPagos()`—, que corre `init()` una vez
     * por cada pago de la cuenta y después reconcilia la cuenta entera él mismo. Si aparece un
     * segundo llamador, que sea porque también reconcilia al final: suspender sin reconciliar
     * después es dejar el módulo apagado en ese camino.
     *
     * Se usa SIEMPRE con try/finally. Si se saltea el reanudar() por una excepción, el módulo
     * queda mudo para el resto del request.
     *
     * @return void
     */
    static function suspender() {
        self::$suspensiones++;
    }

    /**
     * Vuelve a prender el enganche automático.
     *
     * @return void
     */
    static function reanudar() {

        if (self::$suspensiones > 0) {
            self::$suspensiones--;
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
    private static function aplicar_grupos($sale, $sistema, $grupos, $contexto = null) {

        $mapa = self::mapa_del_contexto($sale, $contexto);

        /*
         * 🔴 VA PRIMERO, ANTES DE TOCAR NINGÚN PUNTO. El reverso que puede escribirse más abajo
         * copia el `client_id` del lote: si el titular se sincronizara después, el reverso
         * nacería con el cliente viejo y le dejaría el saldo en -100.
         */
        self::sincronizar_titular($sale, $mapa);

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

        $mapas = self::mapas_de_movimientos([$sale->id]);

        $sale_id = (int) $sale->id;

        return array_key_exists($sale_id, $mapas) ? $mapas[$sale_id] : self::mapa_vacio();
    }

    /**
     * Lo mismo que `mapa_de_movimientos()` pero para MUCHAS ventas y con UNA sola consulta.
     *
     * Es la forma que usa `reconciliar_cuenta_corriente()`, y la de una venta sola delega acá
     * para que la definición de "el mapa" exista una vez y no dos.
     *
     * @param  array  $sale_ids
     * @return array  sale_id => ['lotes' => [...], 'reversos' => [...]]
     */
    private static function mapas_de_movimientos($sale_ids) {

        $mapas = [];

        if (!count($sale_ids)) {
            return $mapas;
        }

        $movimientos = MovimientoPunto::whereIn('sale_id', $sale_ids)
                                        ->whereIn('tipo', [self::TIPO_GANADOS, self::TIPO_REVERTIDOS])
                                        ->get();

        foreach ($movimientos as $movimiento) {

            $sale_id = (int) $movimiento->sale_id;
            $lista   = (int) $movimiento->price_type_id;

            if (!array_key_exists($sale_id, $mapas)) {
                $mapas[$sale_id] = self::mapa_vacio();
            }

            if ($movimiento->tipo == self::TIPO_GANADOS) {
                $mapas[$sale_id]['lotes'][$lista] = $movimiento;
            } else {
                $mapas[$sale_id]['reversos'][$lista] = $movimiento;
            }
        }

        return $mapas;
    }

    /**
     * @return array
     */
    private static function mapa_vacio() {
        return ['lotes' => [], 'reversos' => []];
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  El contexto precargado
     *
     *  Cuatro lecturas que el camino de UNA venta hace por su cuenta y que el de la cuenta
     *  corriente ya resolvió de a muchas. Cada una tiene la misma forma: si el contexto la
     *  trae, se usa; si no, se lee como siempre. Así el camino de una sola venta
     *  (SaleController, la papelera, la nota de crédito) no cambia una línea.
     * ---------------------------------------------------------------------------------------
     */

    /**
     * ¿La venta llega con `articles`, `discounts` y `surchages` recién leídos de la base?
     *
     * @param  array|null  $contexto
     * @return bool
     */
    private static function articles_ya_frescos($contexto) {

        return is_array($contexto)
                && array_key_exists('articles_frescos', $contexto)
                && $contexto['articles_frescos'];
    }

    /**
     * Los débitos de la venta, con sus imputaciones.
     *
     * @param  \App\Models\Sale  $sale
     * @param  array|null        $contexto
     * @return array|\Illuminate\Support\Collection
     */
    private static function debitos_del_contexto($sale, $contexto) {

        if (is_array($contexto) && array_key_exists('debitos', $contexto) && !is_null($contexto['debitos'])) {
            return $contexto['debitos'];
        }

        return CurrentAcount::where('sale_id', $sale->id)
                            ->whereNull('haber')
                            ->with('pagado_por')
                            ->get();
    }

    /**
     * El mapa de movimientos escritos de la venta.
     *
     * @param  \App\Models\Sale  $sale
     * @param  array|null        $contexto
     * @return array
     */
    private static function mapa_del_contexto($sale, $contexto) {

        if (is_array($contexto) && array_key_exists('mapa', $contexto) && !is_null($contexto['mapa'])) {
            return $contexto['mapa'];
        }

        return self::mapa_de_movimientos($sale);
    }

    /**
     * El factor de nota de crédito de la venta, o null para que lo calcule PuntosBaseHelper.
     *
     * @param  \App\Models\Sale  $sale
     * @param  array|null        $contexto
     * @return float|null
     */
    private static function factor_nc_del_contexto($sale, $contexto) {

        if (is_array($contexto) && array_key_exists('factor_nc', $contexto)) {
            return $contexto['factor_nc'];
        }

        return null;
    }

    /**
     * El lote SIGUE al `client_id` de la venta.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 POR QUÉ EL LOTE TIENE QUE SEGUIR AL CLIENTE DE LA VENTA.
     *
     *  `movimiento_puntos.client_id` no es un dato del movimiento: es una COPIA del titular de
     *  la venta que lo originó, y el saldo del cliente se lee como SUM(puntos) filtrando por esa
     *  copia (PuntosSaldoHelper y la ficha del cliente leen así). O sea que si la venta cambia
     *  de cliente y la copia no, el saldo de los dos queda mal a la vez: el cliente viejo cobra
     *  puntos de una venta que ya no es suya y el nuevo no cobra los de la que sí lo es. Y no se
     *  arregla solo nunca, porque el reconciliador indexa por `sale_id` y encuentra el lote
     *  igual: lo mira, ve los mismos puntos y el mismo monto base, y no escribe nada.
     *
     *  Se mueven TAMBIÉN los reversos, y no solo los lotes vivos: un lote anulado y su reverso
     *  suman cero, así que dejar uno de cada lado le clavaría +100 a uno y -100 al otro.
     * ─────────────────────────────────────────────────────────────────────────────
     *
     * @param  \App\Models\Sale  $sale
     * @param  array             $mapa  El de mapa_de_movimientos().
     * @return void
     */
    private static function sincronizar_titular($sale, $mapa) {

        /*
         * 🔴 Venta que se quedó SIN cliente: el lote NO se pone en null, se deja con su dueño
         * viejo y se revierte (de eso se encarga aplicar_grupos(), porque corresponde_acumular()
         * devuelve false sin cliente). Ponerlo en null sería peor que no hacer nada: el reverso
         * de -100 quedaría con el cliente viejo y el lote de +100 sin dueño, y el saldo del que
         * ya no tiene la venta pasaría de 100 a -100 en vez de a 0.
         */
        if (is_null($sale->client_id) || !$sale->client_id) {
            return;
        }

        $client_id = (int) $sale->client_id;

        $movimientos = array_merge(array_values($mapa['lotes']), array_values($mapa['reversos']));

        foreach ($movimientos as $movimiento) {

            if ((int) $movimiento->client_id == $client_id) {
                continue;
            }

            $movimiento->client_id = $client_id;
            $movimiento->save();
        }
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
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 SE REVIERTE LO QUE EL LOTE TODAVÍA APORTA AL SALDO, NO SIEMPRE EL TOTAL OTORGADO.
     *
     *  Lo que hay que cancelar es exactamente lo que el lote sigue sumando en el libro. Y hay
     *  un consumo que YA salió del saldo por su cuenta: EL VENCIMIENTO. `puntos:vencer` escribe
     *  una fila 'vencidos' NEGATIVA, así que esos puntos ya están restados. Revertirlos otra vez
     *  los resta dos veces.
     *
     *  Medido contra la base antes del arreglo del 22/8/2026 (venta de 100 puntos):
     *
     *      saldo con la venta      : 100
     *      saldo despues de vencer :   0
     *      saldo despues de anular : -100   ← el mismo punto restado dos veces
     *         ganados    100.00
     *         vencidos  -100.00
     *         revertidos -100.00
     *
     *  Ese -100 le queda al cliente PARA SIEMPRE y además le bloquea el canje: nunca vuelve a
     *  llegar al mínimo del programa.
     *
     *  🔴 EL CANJE ES EL CASO CONTRARIO Y NO SE TOCA. Ahí revertir el total ES lo correcto y
     *  está decidido: el cliente ya se llevó el descuento en pesos, así que si después se le
     *  anula la venta que le dio esos puntos, el saldo tiene que quedar negativo. Perdonar la
     *  diferencia sería que el negocio pague dos veces. Un saldo negativo bloquea el canje solo
     *  y se ve en la ficha del cliente.
     *
     *  La señal que separa los dos casos es `movimiento_punto_consumos`, que dice QUÉ movimiento
     *  se comió cada pedazo del lote: si fue un 'vencidos', no se vuelve a restar; si fue un
     *  'canjeados' (o un 'ajuste' negativo), sí. Es la misma tabla que ya existía para deshacer
     *  un canje devolviéndole los puntos al lote del que salieron — no hace falta ninguna
     *  columna nueva.
     * ─────────────────────────────────────────────────────────────────────────────
     *
     * @param  \App\Models\Sale                  $sale
     * @param  \App\Models\MovimientoPunto       $lote
     * @param  \App\Models\MovimientoPunto|null  $reverso
     * @return void
     */
    private static function revertir_lote($sale, $lote, $reverso) {

        $puntos = self::puntos_a_revertir($lote);

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
     * Cuántos puntos de este lote hay que compensar con la fila 'revertidos'.
     *
     * Es lo que el lote TODAVÍA aporta al saldo: los puntos que otorgó menos lo que ya se
     * restó por vencimiento. Ver el docblock de `revertir_lote()` por qué el vencimiento se
     * descuenta y el canje no.
     *
     * 🔴 LA SALIDA BARATA ES LA REGLA, NO LA EXCEPCIÓN. Un lote con `consumido = 0` no puede
     * tener ni un consumo de vencimiento, así que se contesta sin ir a la base. Eso deja el
     * caso normal —anular una venta cuyos puntos nadie tocó— en cero consultas nuevas, que
     * importa porque `aplicar_grupos()` pasa por acá en cada reconciliación de una venta que
     * dejó de corresponder.
     *
     * @param  \App\Models\MovimientoPunto  $lote
     * @return float  Nunca negativo.
     */
    private static function puntos_a_revertir($lote) {

        $puntos = (float) $lote->puntos;

        if ((float) $lote->consumido <= self::DELTA) {
            return $puntos;
        }

        $a_revertir = $puntos - self::consumido_por_vencimiento($lote);

        if ($a_revertir <= 0) {
            return 0;
        }

        return round($a_revertir, 2);
    }

    /**
     * Cuánto de este lote se lo comió un VENCIMIENTO.
     *
     * `movimiento_punto_consumos` guarda el detalle por lote de cada consumo, y el `tipo` del
     * movimiento que consumió es lo que distingue un vencimiento de un canje. Se pregunta por
     * el tipo del CONSUMIDOR y no por el del lote, que es 'ganados' en los dos casos.
     *
     * @param  \App\Models\MovimientoPunto  $lote
     * @return float
     */
    private static function consumido_por_vencimiento($lote) {

        $total = DB::table('movimiento_punto_consumos')
                    ->join(
                        'movimiento_puntos',
                        'movimiento_puntos.id',
                        '=',
                        'movimiento_punto_consumos.movimiento_consumo_id'
                    )
                    ->where('movimiento_punto_consumos.movimiento_origen_id', $lote->id)
                    ->where('movimiento_puntos.tipo', self::TIPO_VENCIDOS)
                    ->sum('movimiento_punto_consumos.puntos');

        return round((float) $total, 2);
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
