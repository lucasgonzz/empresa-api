<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\OrderNotificationHelper;
use App\Http\Controllers\Helpers\QuestionNotificationHelper;
// use App\Http\Controllers\Helpers\TwilioHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Message;
use App\Notifications\MessageSend;

class MessageHelper {

    static function sendCartArticleAmountUpdatedMessage($text, $buyer) {
        $message = Message::create([
            'user_id'   => UserHelper::userId(),
            'buyer_id'  => $buyer->id,
            'text'      => $text,
            'type'      => 'cart_amount_updated',
        ]);

        $title = 'Actualizacion de Carrito';
        // Message Broadcast Mail
        $buyer->notify(new MessageSend($message, false, $title, null, false));
        UserHelper::getFullModel()->notify(new MessageSend($message, true, null, null, false));
    }

    static function sendOrderConfirmedMessage($order) {
        $confirmation_message = OrderNotificationHelper::getConfirmedMessage($order);
        $message = Message::create([
            'user_id' => UserHelper::userId(),
            'buyer_id' => $order->buyer_id,
            'text' => $confirmation_message,
            'type' => 'order_confirmed',
        ]);

        $title = 'Confirmamos tu pedido';
        // Message Broadcast Mail
        $order->buyer->notify(new MessageSend($message, false, $title));
        $order->user->notify(new MessageSend($message, true));
        // Push Notification
        // TwilioHelper::sendNotification($order->buyer_id, $title, $confirmation_message);
    }

    static function sendOrderCanceledMessage($description, $order) {
        $description = 'Tuvimos que cancelar tu pedido por la siguiente razon: '.$description;
        $message = Message::create([
            'user_id' => UserHelper::userId(),
            'buyer_id' => $order->buyer_id,
            'text' => $description,
            'type' => 'order_canceled',
            'order_id' => $order->id,
        ]);
        // Message Broadcast
        $title = 'Cancelamos tu pedido';
        $order->buyer->notify(new MessageSend($message, false, $title));
        $order->user->notify(new MessageSend($message, true));
    }

    static function sendOrderFinishedMessage($order) {
        $finish_message = OrderNotificationHelper::getFinishedMessage($order);
        $message = Message::create([
            'user_id' => UserHelper::userId(),
            'buyer_id' => $order->buyer_id,
            'text' => $finish_message,
            'type' => 'order_finished',
        ]);
        // Broadcast
        $title = 'Tu pedido esta listo';
        $order->buyer->notify(new MessageSend($message, false, $title));
        $order->user->notify(new MessageSend($message, true));
        // Notification
        // TwilioHelper::sendNotification($order->buyer_id, $title, $finish_message);
    }

    static function sendOrderDeliveredMessage($order) {
        $delivered_message = OrderNotificationHelper::getDeliveredMessage($order);
        $message = Message::create([
            'user_id' => UserHelper::userId(),
            'buyer_id' => $order->buyer_id,
            'text' => $delivered_message,
            'type' => 'order_delivered',
        ]);
        // Broadcast
        $title = '¡Muchas gracias por tu compra!';
        $order->buyer->notify(new MessageSend($message, false, $title));
        $order->user->notify(new MessageSend($message, true));
        // Notification
        // TwilioHelper::sendNotification($order->buyer_id, $title, $delivered_message);
    }

    static function sendPaymentSuccessMessage($order) {
        $payment_message = OrderNotificationHelper::checkPaymentMethod($order)['message'];
        $message = Message::create([
            'user_id' => UserHelper::userId(),
            'buyer_id' => $order->buyer_id,
            'text' => $payment_message,
            'type' => 'payment_success',
        ]);
        // Broadcast
        $title = 'Se acredito tu pago';
        $order->buyer->notify(new MessageSend($message, false, $title));
        $order->user->notify(new MessageSend($message, true));
        // Notification
        // TwilioHelper::sendNotification($order->buyer_id, $title, $payment_message);
    }

    static function sendPaymentErrorMessage($order) {
        $payment_message = OrderNotificationHelper::checkPaymentMethod($order)['message'];
        $message = Message::create([
            'user_id' => UserHelper::userId(),
            'buyer_id' => $order->buyer_id,
            'text' => $payment_message,
            'type' => 'payment_error',
        ]);
        // Broadcast
        $title = 'Hubo un error con tu pago';
        $order->buyer->notify(new MessageSend($message, false, $title));
        $order->user->notify(new MessageSend($message, true));
        // Notification
        // TwilioHelper::sendNotification($order->buyer_id, $title, $payment_message);
    }

    static function sendQuestionAnsweredMessage($question) {
        $question_message = QuestionNotificationHelper::getQuestionAnsweredMessage($question);
        $message = Message::create([
            'user_id' => UserHelper::userId(),
            'buyer_id' => $question->buyer_id,
            'text' => $question_message,
            'type' => 'question_answered',
            'article_id' => $question->article->id,
        ]);
        $message = Self::getFullMessage($message->id);
        // Broadcast
        $title = 'Respondimos a tu pregunta';
        $question->buyer->notify(new MessageSend($message, false, $title));
        $question->user->notify(new MessageSend($message, true));
        // Notification
        // TwilioHelper::sendNotification($question->buyer_id, $title, $question_message);
    }

    static function sendArticleAdviseMessage($advise) {
        // $advise_message = "Hola! Queriamos avisarte que ya ingreso el artículo: {$advise->article->name}";
        // $message = Message::create([
        //     'user_id' => UserHelper::userId(),
        //     'buyer_id' => $advise->buyer_id,
        //     'text' => $advise_message,
        //     'type' => 'article_advise',
        //     'article_id' => $advise->article->id,
        // ]);
        // $message = Self::getFullMessage($message->id);
        // Broadcast
        $title = "Hola! Queriamos avisarte que ya ingreso nuevo stock para el artículo: {$advise->article->name}";
        $url = $advise->article->user->online.'/articulos/'.$advise->article->slug;
        // $advise->buyer->notify(new MessageSend($message, false, $title, $url));
        // $advise->buyer->user->notify(new MessageSend($message, true));
        // Notification
        // TwilioHelper::sendNotification($advise->buyer_id, $title, $advise_message);
    }

    static function getFullMessage($id) {
        return Message::where('id', $id)
                            ->with('article.images')
                            ->with('article.sizes')
                            ->with('article.colors')
                            ->with('article.variants')
                            ->with(['article.questions' => function($query) {
                                $query->whereHas('answer')->with('answer');
                            }])
                            ->first();
    }

}