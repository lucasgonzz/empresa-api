<?php 

use Carbon\Carbon;

$model = [
			'client'            => '',
            'offer_validity'    => Carbon::now()->addDays(7),
            'hour_price'        => 3500,
            'delivery_time'     => '4 semanas, el tiempo de entrega puede variar dependiendo las revisiones solicitadas por el cliente.',
            'features' => [
                [
                    'title'             => '',
                    'items' => [
                        '',
                    ],
                    'development_time'  => ,
                ],
            ],
        ];