<?php 

use Carbon\Carbon;

$model = [
            'client' => 'Angelo Pasteleria',
            'offer_validity'    => Carbon::now()->addDays(7),
            'hour_price'        => 3000,
            'delivery_time'     => '4 semanas, el tiempo de entrega puede variar dependiendo las revisiones solicitadas por el cliente.',
            'titles'             => [
                [
                    'text' => 'Presupuesto para la actualizacion de Aplicación Web con almacenamiento de datos en la Nube.'
                ],
                [
                    'text' => 'La tecnología en la Nube permite acceder la información desde cualquier dispositivo conectado a internet.'
                ],
                [
                    'text' => 'El desarrollo en esta arquitectura permite que se puedan ir haciendo mejoras en el sistema una que vez el cliente comienza a usarlo, estas mejoras a realizarse, las irá identificando el cliente conforme utilice el programa.'
                ],
            ],
            'features'          => [
                [
                    'title'             => 'Solo el usuario administrador podrá ver el total de las ventas',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'Solo el usuario administrador podrá eliminar pedidos',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'Indicar el método de pago para cada venta',
                    'description'       => 'El usuario administrador podrá dar de alta, editar y eliminar METODOS DE PAGO, para luego indicarlo en las ventas.',
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Indicar el tipo de venta',
                    'description'       => 'El usuario administrador podrá dar de alta, editar y eliminar TIPOS DE VENTA, y asignarle a cada una que cree un color, para luego indicarlo en las ventas. Por ejemplo:',
                    'items'             => [
                        'Tipo de venta: "Salon", color: "Rojo"',
                        'Tipo de venta: "Take away", color: "Naranja"',
                    ],
                    'development_time'  => 3,
                ],
                [
                    'title'             => 'Mostrar con un color amarillo los pedidos entregados',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'Indicar si una venta fue pagada',
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'Indicar si una venta fue actualizada',
                    'description'       => 'Los items agregados a un pedido aparecerán resaltados, el pedido aparecera con un aviso de que fue modificado y se actualizaran las demás computadoras con una notificación del pedido que fue modificado.',
                    'development_time'  => 2,
                ],
            ],
        ];