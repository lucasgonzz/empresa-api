<?php 

use Carbon\Carbon;

$model = [
            'client' => 'Fenix Distribuidora Mayorista',
            'offer_validity'    => Carbon::now()->addDays(7),
            'hour_price'        => 4000,
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
                    'title'             => 'Generar Documento PDF A4 con los articulos resultantes de una busqueda o de una seleccion multiple',
                    'items' => [
                        'En el PDF se mostrara el nombre del negocio, su logotipo y la fecha en la que se este generando el documento.',
                        'En el PDF se listaran los articulos con su NOMBRE, PRECIO e IMAGEN.',
                    ],
                    'development_time'  => 3,
                ],
            ],
        ];