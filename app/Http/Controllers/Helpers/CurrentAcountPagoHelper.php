<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\ChequeHelper;
use App\Http\Controllers\Helpers\SellerCommissionHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Http\Controllers\Helpers\puntos\PuntosAcumulacionHelper;
use App\Models\Check;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CurrentAcountPagoHelper {

    public $model_name;
    public $model_id;
    public $pago;
    public $fondos;
    public $fondos_iniciales;
    public $sin_pagar;
    public $sin_pagar_index;

    /** Instancia de CreditAccount asociada a este procesamiento */
    public $credit_account;

    /** Monto pendiente del débito actual (debe - pagandose) */
    public $debe;

    /**
     * Colección de débitos pendientes cargada en memoria al inicio.
     * Se consume con shift() para evitar un query por iteración en setSinPagar().
     *
     * @var \Illuminate\Support\Collection
     */
    public $debitos_pendientes;

    /**
     * Constructor del helper de imputación de pagos.
     *
     * @param int $credit_account_id  ID de la cuenta corriente
     * @param string $model_name      Tipo de modelo: 'client' o 'provider'
     * @param int $model_id           ID del cliente o proveedor
     * @param CurrentAcount $pago     Movimiento de tipo haber (pago) recién creado
     * @param CreditAccount|null $credit_account  Instancia ya cargada para evitar query extra
     */
    function __construct($credit_account_id, $model_name, $model_id, $pago, $credit_account = null) {
        Log::info('-------------');
        Log::info('procesando '.$pago->detalle);
        $this->model_name = $model_name;
        $this->model_id = $model_id;

        // Reusar la instancia ya cargada si se pasó como parámetro (evita query extra en checkPagos)
        $this->credit_account = $credit_account ?? CreditAccount::find($credit_account_id);

        $this->pago = $pago;
        $this->fondos = $pago->haber;
        $this->sin_pagar = null;
        $this->sin_pagar_index = 0;

        // Cargar todos los débitos pendientes de una sola vez en memoria
        // para evitar hacer 1 query por cada débito procesado en setSinPagar()
        $this->debitos_pendientes = CurrentAcount::where('credit_account_id', $this->credit_account->id)
                                                 ->whereIn('status', ['sin_pagar', 'pagandose'])
                                                 ->orderBy('created_at', 'ASC')
                                                 ->get();

        $this->setSinPagar();
    }

    /**
     * Determina el próximo débito a procesar.
     * Si el pago tiene to_pay_id y es la primera llamada, usa ese débito específico.
     * En los demás casos consume el primer débito pendiente de la colección en memoria.
     */
    function setSinPagar() {

        $this->sin_pagar_index++;

        if (!is_null($this->pago->to_pay_id) && is_null($this->sin_pagar)) {

            // Buscar el débito dirigido dentro de la colección en memoria
            $found = $this->debitos_pendientes->firstWhere('id', $this->pago->to_pay_id);

            if ($found) {
                // Removerlo de la colección para no procesarlo de nuevo en las iteraciones siguientes
                $this->debitos_pendientes = $this->debitos_pendientes
                                                ->reject(function ($d) { return $d->id == $this->pago->to_pay_id; })
                                                ->values();
                $this->sin_pagar = $found;
            } else {
                // No estaba en los pendientes (puede ser un débito ya pagado o de otro estado)
                $this->sin_pagar = CurrentAcount::find($this->pago->to_pay_id);
            }

            Log::info('con to_pay_id '.$this->pago->to_pay_id);
        } else {

            // Tomar el siguiente débito pendiente de la colección en memoria (FIFO)
            $this->sin_pagar = $this->debitos_pendientes->shift();

        }

        if (!is_null($this->sin_pagar)) {
            Log::info('se puso '.$this->sin_pagar->detalle.' para sin pagar');
        }

    }

    function init() {
        while (!is_null($this->sin_pagar) && $this->fondos > 0) {
            $this->fondos_iniciales = $this->fondos;
            $this->procesarPago();
            $this->setSinPagar();
        }
        Log::info('Ya no entro en procesar pago');
        $this->setModelPagosCheckeados();

        /*
         * ─────────────────────────────────────────────────────────────────────────────
         *  🔴 PUNTOS PARA CLIENTES. EL ENGANCHE VA ACÁ Y NO PUEDE VIVIR SOLO EN checkPagos().
         * ─────────────────────────────────────────────────────────────────────────────
         *
         *  Hasta el 22/8/2026 el único enganche de la cuenta corriente estaba al final de
         *  `CurrentAcountHelper::checkPagos()`. El problema es que checkPagos() NO es el único
         *  camino por el que un débito llega a 'pagado': es uno de varios. Todos los demás
         *  arman este helper a mano y llaman a init() derecho, y el más importante de todos es
         *  EL COBRO DE TODOS LOS DÍAS — `CurrentAcountController@pago` se bifurca por
         *  `current_date`, y con `current_date = 1`, que es lo que la SPA manda por default,
         *  toma la rama que NO pasa por checkPagos(). O sea que el enganche de allá cubría la
         *  excepción (el pago con fecha pasada) y se perdía la regla: un comercio con cuenta
         *  corriente casi no acumulaba nada, y los puntos aparecían de rebote cuando cualquier
         *  otra cosa disparaba checkPagos() más tarde.
         *
         *  La familia entera que llega a 'pagado' es la de los llamadores de `init()`:
         *    - CurrentAcountController@pago       (cobro con current_date = 1)  ← el bug
         *    - CurrentAcountHelper::checkPagos()  (una vez por pago; ver más abajo)
         *    - CurrentAcountHelper::notaCredito() (la NC imputada al débito de la venta)
         *    - ChequeController                   (cheque de proveedor)
         *    - los helpers de baja (DeleteSale, DeleteNotaDebito), CheckSaldosHelper y las
         *      semillas.
         *
         *  Copiar la llamada en cada uno de esos lugares sería arreglar las instancias que el
         *  test nombró y no la familia: el próximo camino que se escriba nace roto y nada lo
         *  denuncia. `init()` es el punto por donde pasan TODOS —es el único método que corre
         *  procesarPago(), que es la línea que escribe `status = 'pagado'`—, así que puesto acá
         *  el enganche queda cubierto POR CONSTRUCCIÓN.
         *
         *  🔴 Y NO por eso se saca el del final de checkPagos(): checkPagos() re-imputa la
         *  cuenta entera y llama a init() UNA VEZ POR PAGO, así que reconciliar desde acá le
         *  costaría N pasadas por las mismas ventas. Por eso checkPagos() suspende este
         *  enganche mientras corre su loop y reconcilia una sola vez al final. No duplica
         *  puntos ni con suspensión ni sin ella (el reconciliador compara contra lo escrito),
         *  lo que se cuida es el costo en consultas de los dos jobs de fondo.
         *
         *  Costo para el comercio SIN la extensión: CERO consultas. `credit_account` ya está en
         *  memoria acá (lo cargó el constructor) y reconciliar_cuenta_corriente() corta con la
         *  respuesta memoizada de PuntosConfigHelper antes de leer una sola fila. Para una
         *  cuenta de PROVEEDOR corta antes todavía, por `model_name`.
         */
        PuntosAcumulacionHelper::reconciliar_cuenta_corriente($this->credit_account);
    }

    function setModelPagosCheckeados() {
        $model = GeneralHelper::getModelName($this->model_name)::find($this->model_id);
        if ($model) {
            $model->pagos_checkeados = 0;
            $model->save();
        }
    }

    function procesarPago() {
        Log::info('procesarPago');
        $this->debe = $this->sin_pagar->debe - $this->sin_pagar->pagandose;

        // $fondos = (float)$this->fondos;
        // $debe = (float)$this->debe;

        Log::info('Los fondos son '.Numbers::price($this->fondos).' y se debe '.Numbers::price($this->debe));
        Log::info('Los fondos son mayor o iguales: ');

        $delta = 0.00001;
        Log::info($this->fondos > $this->debe || abs($this->fondos - $this->debe) < $delta);

        if ($this->fondos > $this->debe || abs($this->fondos - $this->debe) < $delta) {
            $this->sin_pagar->pagandose += $this->debe;
            $this->sin_pagar->status = 'pagado';
            // Redondeamos a 2 decimales para evitar residuos de punto flotante
            // (ej: 249013.22 - 141481.34 - 107531.88 puede dar ~1e-10 en lugar de 0.00,
            //  lo que haría que el while vuelva a entrar y cree un pagado_por con monto 0)
            $this->fondos = Numbers::redondear($this->fondos - $this->debe);
            $this->savePagadoPor($this->debe);
            SellerCommissionHelper::checkCommissionStatus($this->sin_pagar, $this->pago);
            Log::info('Se puso en pagado');
        } else {
            $this->sin_pagar->pagandose += $this->fondos;
            $this->sin_pagar->status = 'pagandose';
            $pagado = $this->fondos;
            $this->fondos = 0;
            $this->savePagadoPor($pagado);
            Log::info('Se puso sin pagar');
        }
        $this->sin_pagar->save();
    }

    function savePagadoPor($pagado) {
        $this->sin_pagar->pagado_por()->attach($this->pago->id, [
            'pagado'            => $pagado,
            'total_pago'        => $this->pago->haber,
            'a_cubrir'          => $this->debe,
            'fondos_iniciales'  => $this->fondos_iniciales,
            'nuevos_fondos'     => $this->fondos,
            'remantente'        => $this->debe - $pagado,
            'created_at'        => $this->pago->created_at->addSeconds($this->sin_pagar_index),
        ]);
    }

    static function attachPaymentMethods($pago, $payment_methods, $model_name = null) {
        
        PaymentMethodHelper::attach_payment_methods($pago, $payment_methods);

        $pago->load('current_acount_payment_methods');
        
        foreach ($pago->current_acount_payment_methods as $payment_method) {

            if ($payment_method->pivot->caja_id) {

                // Grupo 223 · Prompt 02: se pasa el id del método de pago (ya disponible acá en el
                // foreach) para que guardar_pago() pueda resolver la cascada de liquidación/comisión.
                CurrentAcountCajaHelper::guardar_pago($payment_method->pivot->amount, $payment_method->pivot->caja_id, $model_name, $pago, null, $payment_method->id);

            } else if (Self::deberia_haber_impactado_caja($payment_method)) {

                /*
                 * Un metodo de pago con monto pero SIN caja destino no impacta en ninguna caja, y
                 * hasta el 21/8/2026 eso pasaba sin dejar ningun rastro: la respuesta era 201, el
                 * pago aparecia cargado y la plata no estaba en ninguna caja. Es exactamente el
                 * modo de falla que reporto el usuario del pago en dos monedas.
                 *
                 * NO se lanza excepcion: el metodo igual queda guardado y el pago es valido; lo
                 * unico que falta es el movimiento de caja, y eso se arregla desde Tesoreria.
                 */
                Log::warning('attachPaymentMethods: el metodo de pago '.$payment_method->id.' del pago '.$pago->id.' tiene monto '.$payment_method->pivot->amount.' pero no tiene caja destino, asi que no impacta en ninguna caja.');

            // $amount = $payment_method['amount'];
            // $amount_cotizado = isset($payment_method['amount_cotizado']) ? $payment_method['amount_cotizado'] : null;
            // $cotizacion = isset($payment_method['cotizacion']) ? $payment_method['cotizacion'] : null;
            // $moneda_id = isset($payment_method['moneda_id']) ? $payment_method['moneda_id'] : null;

            // $haber = 0;

            // if (
            //     !is_null($amount_cotizado)
            //     && $amount_cotizado != ''
            //     && (float)$amount_cotizado > 0
            // ) {
            //     $haber = $amount_cotizado;
            // } else {

            //     $haber = $amount;
            // }
            
            // if ($amount == '' || is_null($amount)) {
            //     $amount = $pago->haber;
            // }
            
            // // Si es cheque
            // if ($payment_method['current_acount_payment_method_id'] == 1) {
            //     ChequeHelper::crear_cheque($pago, $payment_method);
            // }

            // $pago->current_acount_payment_methods()->attach($payment_method['current_acount_payment_method_id'], [
            //         'amount'    => $haber,
            //         'amount_cotizado'    => $amount_cotizado,
            //         'cotizacion'    => $cotizacion,
            //         'moneda_id'    => $moneda_id,
            //         'user_id'   => UserHelper::userId(),
            // ]);

            // if (
            //     $payment_method['current_acount_payment_method_id'] != 1
            //     && isset($payment_method['caja_id'])
            //     && $payment_method['caja_id'] != 0
            //     && !is_null($model_name)
            // ) {

            //     CurrentAcountCajaHelper::guardar_pago($amount, $payment_method['caja_id'], $model_name, $pago);
            // }
            }


        }
    }

    /**
     * Si un metodo de pago que quedo sin caja destino TENIA que haber impactado en una.
     *
     * Un cheque no toca caja al cargarse: entra por ChequeHelper y recien mueve plata cuando se
     * cobra (ChequeController, que ahi si manda su caja). Avisar por cada cheque seria un warning
     * por cobro, o sea ruido que tapa justo el caso que interesa ver.
     *
     * @param \App\Models\CurrentAcountPaymentMethod $payment_method Metodo con su pivot cargado.
     * @return bool
     */
    static function deberia_haber_impactado_caja($payment_method) {

        if (is_null($payment_method->pivot->amount) || (float)$payment_method->pivot->amount <= 0) {

            return false;
        }

        if (!is_null($payment_method->type) && $payment_method->type->slug == 'cheque') {

            return false;
        }

        return true;
    }

    static function get_check_status($payment_method) {

        // if ($this->estadoManual === 'cobrado') return 'Cobrado';
        // if ($this->estadoManual === 'rechazado') return 'Rechazado';

        return 1;

        $hoy = Carbon::today();
        $fecha_pago = Carbon::parse($payment_method['fecha_pago']);
        $vencimiento = $fecha_pago->copy()->addDays(30);

        if ($hoy->lt($fecha_pago)) {
            return 'Pendiente';
        }

        if ($hoy->between($fecha_pago, $vencimiento->copy()->subDays(3))) {
            return 'Disponible para cobrar';
        }

        if ($hoy->between($vencimiento->copy()->subDays(2), $vencimiento)) {
            return 'Pronto a vencerse';
        }

        if ($hoy->gt($vencimiento)) {
            return 'Vencido';
        }
    }

}