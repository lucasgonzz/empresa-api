<?php

namespace App\Http\Controllers\Helpers;

use App\Events\OrderConfirmed as OrderConfirmedEvent;
use App\Events\OrderFinished as OrderFinishedEvent;
use App\Events\PaymentError as PaymentErrorEvent;
use App\Events\PaymentSuccess as PaymentSuccessEvent;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\OrderNotificationHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaywayController;
use App\Http\Controllers\Stock\StockMovementController;
use App\Listerners\OrderConfirmedListene;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\Buyer;
use App\Models\Cart;
use App\Models\Color;
use App\Models\Sale;
use App\Models\Size;
use App\Notifications\CreatedSale;
use App\Notifications\OrderConfirmed as OrderConfirmedNotification;
use App\Notifications\OrderFinished as OrderFinishedNotification;
use App\Notifications\PaymentError as PaymentErrorNotification;
use App\Notifications\PaymentSuccess as PaymentSuccessNotification;
use Illuminate\Support\Facades\Log;


class OrderHelper {

    static function get_total($order) {
        $total = 0;
        foreach ($order->articles as $article) {
            $total_article = (float)$article->pivot->price * (float)$article->pivot->amount;
            $total += $total_article;
        }
        return $total;
    }

    static function setArticlesVariant($orders) {
        foreach ($orders as $order) {
            foreach ($order->articles as $article) {
                if (isset($article->pivot) && $article->pivot->variant_id) {
                    $article->variant = ArticleVariant::find($article->pivot->variant_id)->variant_description;
                } 
            }
        }
        return $orders;
    }

    static function setArticlesColor($orders) {
        $colors = Color::all();
        foreach ($orders as $order) {
            foreach ($order->articles as $article) {
                if (isset($article->pivot) && $article->pivot->color_id) {
                    foreach ($colors as $color) {
                        if ($color->id == $article->pivot->color_id) {
                            $article->color = $color;
                        }
                    }
                } 
            }
        }
        return $orders;
    }

    static function setArticlesSize($orders) {
        $sizes = Size::all();
        foreach ($orders as $order) {
            foreach ($order->articles as $article) {
                if (isset($article->pivot) && $article->pivot->size_id) {
                    foreach ($sizes as $size) {
                        if ($size->id == $article->pivot->size_id) {
                            $article->size = $size;
                        }
                    }
                } 
            }
        }
        return $orders;
    }

    static function attachArticles($model, $articles) {
        $model->articles()->detach();
        foreach ($articles as $article) {
            $model->articles()->attach($article['id'], [
                'price' => $article['pivot']['price'],
                'amount' => $article['pivot']['amount'],
            ]);
        }
    }

    static function deleteCartOrder($order) {
        $cart = Cart::where('order_id', $order->id)
                    ->first();
        if ($cart) {
            $cart->articles()->detach();
            $cart->delete();
        }
    }

    static function updateCuponsStatus($order) {
        foreach ($order->cupons as $cupon) {
            $cupon->valid = 1;
            $cupon->order_id = null;
            $cupon->cart_id = null;
            $cupon->save();
        }
    }

    static function sendMail($model) {
        if ($model->order_status->name == 'Confirmado') {
            MessageHelper::sendOrderConfirmedMessage($model);
        } else if ($model->order_status->name == 'Terminado') {
            MessageHelper::sendOrderFinishedMessage($model);
        } else if ($model->order_status->name == 'Entregado') {
            Log::info('Por mandar mail---------------');
            // MessageHelper::sendOrderDeliveredMessage($model);
            Log::info('Se mando mail-----------');
        }
    }

    // static function discountArticleStock($model, $instance) {
    //     if ($model->order_status->name == 'Sin confirmar') {
    //         if (Self::saveSaleAfterFinishOrder() && !UserHelper::hasExtencion('check_sales')) {
    //             foreach ($model->articles as $article) {

    //                 $ct_stock_movement = new StockMovementController();

    //                 $data = [];
    //                 $data['model_id'] = $article->id;
    //                 $data['order_id'] = $model->id;

    //                 if (!is_null($model->address_id) && $model->address_id != 0) {
    //                     $data['from_address_id'] = $model->address_id;
    //                 } 

    //                 $data['amount'] = -$article->pivot->amount;
    //                 $data['concepto_stock_movement_name'] = 'Pedido Online';
    //                 $ct_stock_movement->crear($data);
    //             }
    //         }
    //         Self::deleteOrderCart($model);

    //         // Extencion Colman
    //         Self::checkDepositos($model, $instance);
    //     }
    // }

    static function checkDepositos($order, $instance) {
        if (UserHelper::hasExtencion('check_sales')) {
            Self::createSale($order, $instance, true);
        }
    }

    static function deleteOrderCart($order) {
        if (!is_null($order->cart)) {
            $order->cart->articles()->detach();
            $order->cart->delete();
        }
    }


    // Si tiene payment_card_info se ejecuta el pago con tarjeta
    static function checkPaymentCardInfo($model) {
        if ($model->order_status->name == 'Sin confirmar' && !is_null($model->payment_method) && !is_null($model->payment_method->payment_method_type) && $model->payment_method->payment_method_type->name == 'Payway') {
            $ct = new PaywayController();
            $ct->executePayment($model);
        }
    }

    static function restartArticleStock($model) {
        foreach ($model->articles as $article) {
            $_article = Article::find($article->id);
            if (!is_null($_article->stock)) {
                $_article->stock += $article->pivot->amount;
                $_article->timestamps = false;
                $_article->save();
            }
        }
    }

    static function saveSaleAfterFinishOrder() {
        $user = UserHelper::getFullModel();
        return $user->online_configuration->save_sale_after_finish_order;
    }

    static function sendOrderConfirmedNotification($order) {
        $buyer = Buyer::find($order->buyer_id);
        $buyer->notify(new OrderConfirmedNotification($order));
        event(new OrderConfirmedEvent($order));
        Self::checkPaymentMethodError($order, $buyer);
    }

    static function sendOrderFinishedNotification($order) {
        $buyer = Buyer::find($order->buyer_id);
        $buyer->notify(new OrderFinishedNotification($order));
        event(new OrderFinishedEvent($order));
    }

    static function checkPaymentMethodError($order) {
        if ($order->payment_method == 'tarjeta' && $order->payment->status != '') {
            $check_payment_status = OrderNotificationHelper::checkPaymentStatus($order);
            if ($check_payment_status) {
                MessageHelper::sendPaymentSuccessMessage($order);
                // $order->buyer->notify(new PaymentSuccessNotification($order));
                // event(new PaymentSuccessEvent($order));
            } else {
                MessageHelper::sendPaymentErrorMessage($order);
                // $order->buyer->notify(new PaymentErrorNotification($order));
                // event(new PaymentErrorEvent($order));
            }
        }
    }

    static function getCanceledDescription($articulos_faltantes, $order) {
        if (count($articulos_faltantes) >= 1) {
            $count = 1;
            $message = 'No hay stock disponible para ';
            foreach ($articulos_faltantes as $article) {
                if ($count > 1) {
                    $message .= ', ni para ';
                }
                $count++;
            }
            $message .= '.';
            return $message;
        } else {
            return StringHelper::onlyFirstWordUpperCase($order['cancel_description']);
        }
    }
}