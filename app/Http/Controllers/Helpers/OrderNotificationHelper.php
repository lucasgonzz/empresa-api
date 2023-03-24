<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\Payment;
use Carbon\Carbon;

class OrderNotificationHelper {

    static function getCreatedMessage($order) {
        $message = 'Ya recibimos tu pedido. Te vamos a avisar cuando lo aprobemos.';
        if ($order->payment_method == 'tarjeta') {
            $message .= ' Y una vez aprobado procesamos el pago.';
        }
        return $message;
    }

    static function getConfirmedMessage($order) {
        $message = $order->buyer->name.', tu pedido fue aprobado 👌. ';
        if ($order->deliver) {
            $message .= 'Te avisamos cuando lo enviemos.';
        } else {
            $message .= 'Te avisamos cuando puedas retirarlo.';
        }
        return $message;
    }

    static function getCanceledMessage($description) {
        return 'Tuvimos que cancelar tu pedido por la siguiente razon: '.$description;
    }

    static function getFinishedMessage($order) {
        $message = '¡'.$order->buyer->name.'! Tu pedido ya esta listo 😁. ';
        if ($order->deliver) {
            $message .= '¡El repartidor va en camino!🛵';
        } else {
            $message .= '¡Podes retirarlo cuando quieras!';
        }
        return $message;
    }

    static function getDeliveredMessage($order) {
        if ($order->deliver) {
            $message = "Recibiste tu pedido";
        } else {
            $message = "Buscaste tu pedido";
        }
        $message .= ". ¡Muchas gracias por tu compra!👍👍";
        return $message;
    }

    static function checkPaymentStatus($order) {
        $checkPaymentMethod = Self::checkPaymentMethod($order);
        if  (!$checkPaymentMethod['is_with_card']) {
            return true;
        }
        if ($checkPaymentMethod['is_with_card'] && !$checkPaymentMethod['with_error']) {
            return true;
        }
        return false;
    }

	static function checkPaymentMethod($order) {
        if ($order->payment_method == 'tarjeta') {
            $payment = Payment::where('order_id', $order->id)
                                ->first();
            $message = '';
            $with_error = false;
            switch ($payment->status) {
                case 'approved':
                    $message = "Se acreditó tu pago. En tu resumen verás el cargo de {$payment->transaction_amount} pesos como: {$payment->description}";
                    break;
                case 'in_process':
                    $with_error = true;
                    $message = 'Hubo un error al procesar el pago. ';
                    if ($payment->status_detail == 'pending_contingency') {
                        $message .= "No te preocupes, en menos de 2 días hábiles te avisaremos por e-mail si se acreditó. Estamos procesando tu pago.";
                    } else if ($payment->status_detail == 'pending_review_manual') {
                        $message .= "No te preocupes, en menos de 2 días hábiles te avisaremos por e-mail si se acreditó o si necesitamos más información.";
                    }
                case 'rejected':
                    $message = 'Hubo un error al procesar el pago. ';
                    $with_error = true;
                    switch ($payment->status_detail) {
                        case 'cc_rejected_bad_filled_card_number':
                            $message .= "Revisa el número de tarjeta.";
                            break;
                        
                        case 'cc_rejected_bad_filled_date':
                            $message .= "Revisa la fecha de vencimiento.";
                            break;
                        case 'cc_rejected_bad_filled_other':
                            $message .= "Revisa los datos.";
                            break;
                        case 'cc_rejected_bad_filled_security_code':
                            $message .= "Revisa el código de seguridad de la tarjeta.";
                            break;
                        case 'cc_rejected_blacklist':
                            $message .= "No pudimos procesar tu pago.";
                            break;
                        case 'cc_rejected_call_for_authorize':
                            $message .= "Debes autorizar ante {$payment->payment_method_id} el pago de {$payment->transaction_amount}.";
                            break;
                        case 'cc_rejected_card_disabled':
                            $message .= "Llama a {$payment->payment_method_id} para activar tu tarjeta o usa otro medio de pago. El teléfono está al dorso de tu tarjeta.";
                            break;
                        case 'cc_rejected_card_error':
                            $message .= "No pudimos procesar tu pago.";
                            break;
                        case 'cc_rejected_duplicated_payment':
                            $message .= "Ya hiciste un pago por el valor de {$payment->transaction_amount}. Si necesitas volver a pagar usa otra tarjeta u otro medio de pago. Tu pago fue rechazado.";
                            break;
                        case 'cc_rejected_insufficient_amount':
                            $message .= "Tu {$payment->payment_method_id} no tiene fondos suficientes.";
                            break;
                        case 'cc_rejected_invalid_installments':
                            $message .= "{$payment->payment_method_id} no procesa pagos en {$payment->installments} cuotas.";
                            break;
                        case 'cc_rejected_max_attempts':
                            $message .= "Llegaste al límite de intentos permitidos. Elige otra tarjeta u otro medio de pago.";
                            break;
                        case 'cc_rejected_other_reason':
                            $message .= "{$payment->payment_method_id} no procesó el pago.";
                            break;
                        default:
                            $message .= "";
                            break;

                    }
            }
            $message .= ' ';
            return ['is_with_card' => true, 'with_error' => $with_error, 'message' => $message];
        } else {
            return ['is_with_card' => false];
        }
    }

}