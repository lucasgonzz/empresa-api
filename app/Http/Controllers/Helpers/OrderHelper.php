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
use App\Listerners\OrderConfirmedListene;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\Buyer;
use App\Models\Cart;
use App\Models\Color;
use App\Models\Sale;
use App\Models\Size;
use App\Models\Variant;
use App\Notifications\CreatedSale;
use App\Notifications\OrderConfirmed as OrderConfirmedNotification;
use App\Notifications\OrderFinished as OrderFinishedNotification;
use App\Notifications\PaymentError as PaymentErrorNotification;
use App\Notifications\PaymentSuccess as PaymentSuccessNotification;
use Illuminate\Support\Facades\Log;


class OrderHelper {

    static function setArticlesVariant($orders) {
        foreach ($orders as $order) {
            foreach ($order->articles as $article) {
                if (isset($article->pivot) && $article->pivot->variant_id) {
                    $article->variant = Variant::find($article->pivot->variant_id);
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
            MessageHelper::sendOrderDeliveredMessage($model);
        }
    }

    static function discountArticleStock($model) {
        if ($model->order_status->name == 'Sin confirmar') {
            foreach ($model->articles as $article) {
                $_article = Article::find($article->id);
                if (count($_article->article_variants) >= 1) {
                    $article_variant = ArticleVariant::find($article->pivot->variant_id);
                    if (!is_null($article_variant->stock)) {
                        $_article->timestamps = false;
                        $article_variant->stock -= $article->pivot->amount;
                        $article_variant->save();
                    }
                } else {
                    if (!is_null($_article->stock)) {
                        $_article->timestamps = false;
                        $_article->stock -= $article->pivot->amount;
                        $_article->save();
                    }
                }
            }
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

    static function saveSale($order, $instance) {
        if ($order->order_status->name == 'Entregado') {
            $client_id = null;
            if (!is_null($order->buyer->comercio_city_client)) {
                $client_id = $order->buyer->comercio_city_client_id;
            }
            $sale = Sale::create([
                'user_id'               => $instance->userId(),
                'buyer_id'              => $order->buyer_id,
                'client_id'             => $client_id,
                'num'                   => $instance->num('sales'),
                'save_current_acount'   => 1,
                'order_id'              => $order->id,
                'employee_id'           => SaleHelper::getEmployeeId(),
            ]);
            SaleHelper::attachArticlesFromOrder($sale, $order->articles);
            if (!is_null($order->buyer->comercio_city_client)) {
                SaleHelper::attachCurrentAcountsAndCommissions($sale, $order->buyer->comercio_city_client_id, [], []);
            }
            $instance->sendAddModelNotification('sale', $sale->id, false);
        }
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