<?php

namespace App\Http\Controllers\Helpers\expense;

use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\PaymentMethodHelper;
use App\Models\Expense;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alta de un gasto, extraída de ExpenseController::store() en la misión agenda-tareas-calendario
 * (14/9/2026) para que la Agenda pueda registrar el gasto de una tarea sin pasar por el
 * controller.
 *
 * Recibe un ARRAY y no un Request, a propósito. El helper viejo de la agenda
 * (PendingCompletedHelper::check_expense_concept) armaba un `Illuminate\Http\Request` a mano con
 * un subconjunto de las claves que el controller leía —sin `payment_methods`— y lo mandaba a
 * `store()`: el `foreach (null)` de attach_payment_methods() tiraba un 500 después de haber
 * marcado la tarea como hecha. Es la clase "el store() que lee un subconjunto del request y el
 * llamador que le manda el resto" de APRENDER_NO_PARCHEAR.md (5/9/2026). Con un array de claves
 * explícitas, lo que el alta necesita está a la vista en la firma y cada llamador arma
 * exactamente eso; con un Request, lo que el alta lee queda escondido adentro y el llamador
 * adivina.
 */
class ExpenseHelper {

    /**
     * moneda_id=0 llega cuando el formulario no envía moneda (sin extensión ventas_en_dolares).
     * Se normaliza a pesos (1) para reportes y listados.
     *
     * @param  mixed  $moneda_id
     * @return int
     */
    static function normalizar_moneda_id($moneda_id) {

        if (is_null($moneda_id) || (int) $moneda_id === 0) {

            return 1;
        }

        return (int) $moneda_id;
    }

    /**
     * Crea el gasto con su desglose de métodos de pago y sus movimientos de caja, todo adentro de
     * una transacción. Devuelve el Expense recién creado (sin relaciones extra cargadas: el
     * llamador decide qué necesita, ej. `fullModel('Expense', $id)`).
     *
     * 🔴 La prevalidación de cajas sin apertura (CurrentAcountCajaHelper::cajas_sin_apertura_en_payload)
     * NO vive acá: la hace cada llamador ANTES, para poder responder 422 sin haber escrito nada.
     * Acá se asume que el payload ya pasó por ella.
     *
     * @param  array  $data  Claves: `expense_concept_id`, `amount`, `moneda_id` (ya normalizada con
     *                       normalizar_moneda_id()), `importe_iva`, `observations`, `created_at`,
     *                       `payment_methods` (array con la forma que produce MultiPaymentMethods en
     *                       la SPA; puede ser `[]`).
     * @param  int  $user_id  Dueño de la cuenta (Controller::userId()).
     * @param  int|callable  $num  Correlativo del gasto. Puede venir resuelto (int) o como callable
     *                             que se ejecuta ADENTRO de la transacción: `Controller::num()` toma
     *                             `lockForUpdate` sobre la fila de users y el máximo de expenses, y
     *                             si ya hay una transacción abierta no abre otra, así que el candado
     *                             se sostiene hasta el commit. Resolverlo antes de entrar lo
     *                             liberaría al toque y dos altas concurrentes podrían llevarse el
     *                             mismo número. Por eso los dos llamadores pasan un closure.
     * @return \App\Models\Expense
     */
    static function crear(array $data, $user_id, $num) {

        /*
         * Una clave que no vino vale null, igual que `$request->clave` en el controller de origen:
         * `Request::only()` omite las claves ausentes del array, y un "Undefined index" acá sería
         * un 500 nuevo donde antes había un gasto con ese campo en null.
         */
        $valor = function ($clave) use ($data) {
            return array_key_exists($clave, $data) ? $data[$clave] : null;
        };

        /*
         * `payment_methods` se garantiza array: attach_payment_methods() hace `foreach` directo y
         * con `null` revienta. Un gasto sin métodos de pago es válido (no genera movimiento de caja;
         * así lo arma, por ejemplo, el trait EscenariosDePlata de los tests).
         */
        $payment_methods = is_array($valor('payment_methods')) ? $valor('payment_methods') : [];

        /*
         * Todo lo que escribe va adentro de una transacción. La prevalidación de cajas del
         * llamador cubre el caso conocido, pero si algo revienta igual —por ejemplo una caja que
         * se cierra entre la validación y el guardado— no puede quedar un gasto a medias con solo
         * una parte de los movimientos de caja hechos.
         */
        return DB::transaction(function () use ($valor, $user_id, $num, $payment_methods) {

            $model = Expense::create([
                'num'                                   => is_callable($num) ? $num() : $num,
                'expense_concept_id'                    => $valor('expense_concept_id'),
                'amount'                                => $valor('amount'),
                'moneda_id'                             => $valor('moneda_id'),
                'importe_iva'                           => $valor('importe_iva'),
                'observations'                          => $valor('observations'),
                'created_at'                            => $valor('created_at'),
                'user_id'                               => $user_id,
                'caja_id'                               => 0,
            ]);

            PaymentMethodHelper::attach_payment_methods($model, $payment_methods);

            // `.type` va explícito para no lazy-loadear la relación una vez por método de pago
            // adentro de la transacción: deberia_haber_impactado_caja() la lee en cada vuelta para
            // dejar los cheques afuera del warning.
            $model->load('current_acount_payment_methods.type');

            foreach ($model->current_acount_payment_methods as $payment_method) {

                /*
                 * Sin guard de cheque, a propósito. Hasta el 29/8/2026 acá decía
                 * `$payment_method->type != 'cheque'`: `type` es una relación belongsTo a
                 * CAPaymentMethodType, no una columna, así que eso comparaba un modelo de Eloquent
                 * contra un string y daba SIEMPRE true. Nunca excluyó un cheque, o sea que el
                 * comportamiento real y vigente en producción es el de esta condición pelada. Se
                 * deja igual, pero escrito sin la comparación rota para que el código diga lo que
                 * de verdad hace.
                 *
                 * 🔴 Que el cheque mueva o no la caja al cargar el gasto es una decisión aparte, y
                 * NO entra acá (Lucas, 29/8/2026): en el pago de cuenta corriente el cheque con caja
                 * también genera movimiento (CurrentAcountPagoHelper), así que cambiarlo de un solo
                 * lado dejaría dos criterios distintos para la misma decisión.
                 */
                if ($payment_method->pivot->caja_id) {

                    $movimiento = [
                        'amount'    => $payment_method->pivot->amount,
                        'caja_id'   => $payment_method->pivot->caja_id,
                    ];

                    ExpenseCajaHelper::guardar_movimiento_caja($model, $movimiento);

                } else if (CurrentAcountPagoHelper::deberia_haber_impactado_caja($payment_method)) {

                    /*
                     * Un método de pago con monto pero SIN caja destino no impacta en ninguna caja,
                     * y hasta el 29/8/2026 eso pasaba sin dejar rastro: la respuesta era 201, el
                     * gasto aparecía cargado y la plata no estaba en ninguna caja. Es el mismo
                     * agujero que se tapó en el pago de cuenta corriente el 21/8/2026.
                     *
                     * NO se lanza excepción: el método igual queda guardado y el gasto es válido; lo
                     * único que falta es el movimiento, y eso se arregla desde Tesorería.
                     */
                    Log::warning('ExpenseHelper::crear: el metodo de pago '.$payment_method->id.' del gasto '.$model->id.' tiene monto '.$payment_method->pivot->amount.' pero no tiene caja destino, asi que no impacta en ninguna caja.');
                }
            }

            $model->save();

            return $model;
        });
    }
}
