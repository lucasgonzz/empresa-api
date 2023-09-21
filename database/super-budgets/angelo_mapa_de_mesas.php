<?php 

use Carbon\Carbon;

$model = [
            'client' => 'Angelo Pasteleria',
            'offer_validity'    => Carbon::now()->addDays(7),
            'hour_price'        => 3000,
            'delivery_time'     => '3 semanas, el tiempo de entrega puede variar dependiendo las revisiones solicitadas por el cliente.',
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
                [
                    'text' => 'Esta metodologia de desarrollo le permite al cliente poder modificar su SALON o agregar nuevos SALONES sin la necesidad de pagar un nuevo desarrollo.'
                ],
            ],
            'features'          => [
                [
                    'title'             => 'Dar de alta, modificar y eliminar SALONES.',
                    'description'       => 'Cada salon representa un mapa, ese mapa estara formado por FILAS y COLUMNAS, y en esa cuadricula resultante se van a ubicar las mesas.',
                    'development_time'  => 4,
                ],
                [
                    'title'             => 'Dar de alta, modificar y eliminar MESAS',
                    'description'       => 'Una mesa estara reprecentada por uno o mas cuadrados de la cuadricula del SALON. Una mesa tendra los datos del nombre y horientacion.',
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Asignar mesas a un pedido',
                    'description'       => 'Cuando se cree o edite un Pedido, se le podra asignar una Mesa, escogida desde el Mapa del salon.',
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Ver el Mapa de mesas con sus estados',
                    'description'       => 'Se podra ver en tiempo real el mapa del salon con el estado de sus mesas, asignando a cada una el color correspondiente a su estado.',
                    'development_time'  => 3,
                ],
                [
                    'title'             => 'Actualizar un pedido desde el Mapa de mesas',
                    'description'       => 'Desde el mapa en tiempo real se podra seleccionar una mesa y actualizar los datos del pedido de esa mesa, como cambiar su estado.',
                    'development_time'  => 2,
                ],
            ],
        ];