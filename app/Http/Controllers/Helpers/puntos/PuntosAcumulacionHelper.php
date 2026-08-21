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
     * @return void
     */
    static function reconciliar_venta($sale) {

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

        $corresponde = self::corresponde_acumular($sale);

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
            $sale->load('articles');

            $grupos = PuntosBaseHelper::calcular_grupos($sale, $sistema);
        }

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
    private static function aplicar_grupos($sale, $sistema, $grupos) {

        $mapa = self::mapa_de_movimientos($sale);

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
