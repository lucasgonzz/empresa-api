<?php 

use Carbon\Carbon;

$model = [
			'client'            => 'La Barraca',
            'offer_validity'    => Carbon::now()->addDays(7),
            'hour_price'        => 3500,
            'delivery_time'     => '4 semanas, el tiempo de entrega puede variar dependiendo las revisiones solicitadas por el cliente.',
            'features' => [
                [
                    'title'             => 'Stock detallado',
                    'description'       => 'Poder indicar el deposito, la nave, la columna y la altura.
                                            Indicarías el stock total del producto, con 3 columnas auxiliares, para que coloques en cada columna el numero que representa, por un lado a la nave, por otro la columna y por ultimo la altura.',
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Listas de precios para proveedores',
                    'description'       => '' ,
                    // 'description'       => 'Crear listas de precios para los proveedores, cada una con un nombre y margen de ganancia, para luego aplicar ese marguen de ganancia a los artículos que pertenezcan a esa lista.' ,
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Indicar las columas antes de importar excel',
                    'description'       => '' ,
                    // 'description'       => 'Luego de cargar el archivo excel con los datos a importar, indicas a que propiedad representa cada letra de las columnas.' ,
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Adjuntar a un pedido de un proveedor una o mas facturas',
                    'description'       => '' ,
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Acopios',
                    'description'       => '' ,
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Crear medios de pago para cada tarjeta',
                    'description'       => 'Crear un medio de pago que represente una tarjeta, con sus diferentes planes según la cantidades de cuotas, y los porcentajes de recargo para cada plan.' ,
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Varios metodos de pago para una sola venta',
                    'description'       => '' ,
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'Venta sujeta a actualizaciones de precios',
                    'description'       => 'Poder marcar una venta para que se actualicen los precios de los artículos conforme se vayan actualizando los precios en el sistema hasta que el cliente pague esa venta.' ,
                    'development_time'  => 2,
                ],
                [
                    'title'             => 'Poder imprimir los remitos sin el precio de los articulos',
                    'description'       => '' ,
                    // 'description'       => 'Indicar antes de imprimir el remito si imprimir con o sin los precios de los articulos' ,
                    'development_time'  => 1,
                ],
                [
                    'title'             => 'Indicar que se va a hacer la factura antes de guardar la venta',
                    'description'       => '' ,
                    'development_time'  => 1,
                ],
            ],
        ];