<?php

namespace App\Http\Controllers\Helpers;
use Illuminate\Support\Facades\Log;
use MercadoPago\Customer;
use MercadoPago\SDK;

class PaymentCardHelper {

    function __construct($commerce, $payment_method) {
        $this->commerce = $commerce;
        $this->payment_method = $payment_method;
    }

    function setPrices($cupon, $delivery_zone, $articles) {
        $index = 0;
        if (!is_null($cupon)) {
            if (!is_null($cupon['amount'])) {
                $total_amount = $cupon['amount'];
                foreach ($articles as $article) {
                    $price = $this->getArticlePrice($article) * $article['pivot']['amount'];
                    if ($price > $total_amount) {
                        Log::info('Descontando '.$total_amount.' a '.$article['name'].' price: '.$price);
                        $price -= $total_amount;
                        $total_amount = 0;
                        $price = $price / $article['pivot']['amount'];
                        $new_article = [
                            'name'      => $article['name'],
                            'amount'    => $article['pivot']['amount'],
                            'final_price'     => $price,
                        ];
                        $articles[$index] = $new_article;
                    } else {
                        $price -= 1;
                        $total_amount -= $price;
                        Log::info('No alcanzo. Descontando '.$price.' a total_amount: '.$total_amount);
                        $total_amount -= $price;
                        Log::info('total_amount: '.$total_amount);
                        $price = 1 / $article['pivot']['amount'];
                        $new_article = [
                            'name'      => $article['name'],
                            'amount'    => $article['pivot']['amount'],
                            'final_price'     => $price,
                        ];
                        $articles[$index] = $new_article;
                    }
                    $index++;
                }
            } else if (!is_null($cupon['percentage'])) {
                foreach ($articles as $article) {
                    $price = $this->getArticlePrice($article);
                    Log::info($article['name'].' $'.$price.' x '.$article['pivot']['amount']);
                    $new_article = [
                        'name'      => $article['name'],
                        'amount'    => $article['pivot']['amount'],
                    ];
                    Log::info('Descontando '.$cupon['percentage'].'% a '.$price);
                    $new_article['final_price'] = $price - floatval($price) * floatval($cupon['percentage']) / 100;
                    $articles[$index] = $new_article;
                    Log::info('Quedo en $'.$articles[$index]['final_price']);
                    $index++;
                }
            }
        } else {
            foreach ($articles as $article) {
                $new_article = [
                    'name'          => $article['name'],
                    'final_price'   => $this->getArticlePrice($article),
                    'amount'        => $article['pivot']['amount'],
                ];
                $articles[$index] = $new_article;
                $index++;
            }
        }
        if (!is_null($delivery_zone)) {
            $articles[] = [
                'name'          => 'Envio',
                'amount'        => 1,
                'final_price'   => $delivery_zone['price'],
            ];
        }
        return $articles;
    }

    function getArticlePrice($article) {
        $price = $article['pivot']['price'];
        if (!is_null($this->commerce->online_configuration->online_price_surchage)) {
            $price += $price * $this->commerce->online_configuration->online_price_surchage / 100;
            Log::info('Sumando recargo del comercio del '.$this->commerce->online_configuration->online_price_surchage);
        }
        if (!is_null($this->payment_method->surchage)) {
            $price += $price * $this->payment_method->surchage / 100;
            Log::info('Sumando recargo del '.$this->payment_method->surchage);
        }
        if (!is_null($this->payment_method->discount)) {
            $price -= $price * $this->payment_method->discount / 100;
            Log::info('Restado descuento del '.$this->payment_method->surchage);
        }
        return $price;
    }
    
}
